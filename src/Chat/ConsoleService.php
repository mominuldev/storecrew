<?php
/**
 * One merchant turn, end to end.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Chat;

use StoreCrew\Agent\AgentTurn;
use StoreCrew\Agent\Orchestrator;
use StoreCrew\Agent\SharedContext;
use StoreCrew\Ai\Message;
use StoreCrew\Database\Repositories\ConversationRepository;
use StoreCrew\Database\Repositories\MessageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The path from a merchant's question to a persisted answer.
 *
 * `ChatService`'s counterpart on the other side of the desk. It exists as its
 * own class rather than a flag on that one because almost every decision
 * inverts, and each inversion is a thing that would be wrong if the two shared
 * a path:
 *
 * - **No routing.** The merchant chose who they are talking to by opening a
 *   screen. Paying a classifier to re-derive that is waste.
 * - **No identity verification.** The caller is an authenticated WordPress user
 *   holding `storecrew_manage`; there is nothing to prove by email.
 * - **No escalation.** Escalation summons a human. The human is typing.
 * - **No conversation quota.** The free-tier unit is a *customer* conversation
 *   (FR-LIC-02). Charging a merchant for asking their own agent a question
 *   would be the fabricated-figure defect running in the merchant's disfavour.
 *   Tokens and spend are still metered by the runner, because those are real.
 *
 * Authorisation is the caller's job and is a capability check, not a token:
 * every route reaching this class is behind `storecrew_manage`, and a thread is
 * additionally bound to the WordPress user who opened it, so one shop manager
 * cannot read another's.
 *
 * @see docs/08-agent-framework.md § 6, docs/15-free-premium-split.md § 4
 *
 * @api
 */
final class ConsoleService {

	/**
	 * How many prior turns are replayed into the prompt.
	 */
	private const HISTORY_TURNS = 20;

	/**
	 * Longest message accepted, in characters.
	 *
	 * Larger than the storefront's ceiling on purpose: a merchant pasting a
	 * campaign brief is the intended use, where a shopper pasting a novel is
	 * not. It is still a ceiling, because an unbounded box is a bill.
	 */
	public const MAX_MESSAGE_CHARS = 8000;

	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly Orchestrator $orchestrator,
	) {}

	/**
	 * The merchant's live thread with one agent, opened if there is none.
	 *
	 * One thread per (user, agent) pair, which is what a merchant expects from
	 * a screen they close and come back to. The pair is carried in the
	 * `session_token` column as a digest rather than in the channel string:
	 * the channel is 32 characters and an add-on agent id long enough to
	 * overflow it would collide with another agent's thread *silently*, which
	 * is exactly the class of failure this file is trying not to have. Nothing
	 * here is a credential — the digest is derivable by anyone who knows the
	 * user id, and it is never presented as proof of anything. Authority is the
	 * capability check upstream and the `customer_id` comparison below.
	 */
	public function open( int $user_id, string $agent_id ): ?object {
		if ( $user_id <= 0 || '' === $agent_id ) {
			return null;
		}

		$handle = $this->handle( $user_id, $agent_id );

		$found = $this->conversations->find_open_for_session(
			$handle,
			ConversationRepository::CHANNEL_CONSOLE
		);

		if ( null !== $found ) {
			return $found;
		}

		$uuid = $this->conversations->start(
			$handle,
			$user_id,
			ConversationRepository::CHANNEL_CONSOLE,
			get_user_locale( $user_id )
		);

		return null === $uuid ? null : $this->conversations->find_by_uuid( $uuid );
	}

	/**
	 * Run one turn.
	 *
	 * @param object $conversation The console conversation row.
	 * @param string $agent_id     Who the merchant is addressing.
	 * @param string $message      What they typed. Already length-checked.
	 */
	public function send( object $conversation, string $agent_id, string $message ): AgentTurn {
		$conversation_id = (int) $conversation->id;

		// Read before storing, for the same reason as the storefront: the
		// orchestrator appends the new message itself.
		$history = $this->history_for_prompt( $conversation_id );

		$this->messages->append( $conversation_id, MessageRepository::ROLE_USER, $message );
		$this->conversations->touch( $conversation_id );

		$context = new SharedContext( $conversation_id, (int) $conversation->customer_id );

		try {
			$turn = $this->orchestrator->converse( $agent_id, $message, $history, $context );
		} catch ( \Throwable $e ) {
			// The console is inside wp-admin rather than on a product page, so
			// a fatal here is less catastrophic than one in ChatService — but
			// it would still be a white screen over a paid feature, and the
			// merchant would have no idea which of their own settings caused
			// it. Same contract: something renderable, always.
			do_action( 'storecrew_console_failed', $e, $conversation_id );

			return AgentTurn::failed( $agent_id, 'console_exception', $e->getMessage() );
		}

		$this->messages->append(
			$conversation_id,
			MessageRepository::ROLE_ASSISTANT,
			$turn->text,
			$turn->agent_id,
			null !== $turn->usage ? $turn->usage->total_input() : 0,
			null !== $turn->usage ? $turn->usage->output : 0
		);

		$this->conversations->touch( $conversation_id );
		$this->conversations->record_run( $conversation_id );

		return $turn;
	}

	/**
	 * Resolve an addressed console thread for a caller, or refuse.
	 *
	 * The uuid says which thread; the WordPress user id says whether this
	 * caller owns it. A thread on any other channel is refused outright rather
	 * than falling through to a comparison that might pass — the console must
	 * not become a second way to read a customer's conversation.
	 */
	public function authorise( string $uuid, int $user_id ): ?object {
		if ( $user_id <= 0 ) {
			return null;
		}

		$conversation = $this->conversations->find_by_uuid( $uuid );

		if ( null === $conversation ) {
			return null;
		}

		if ( ConversationRepository::CHANNEL_CONSOLE !== (string) $conversation->channel ) {
			return null;
		}

		return (int) $conversation->customer_id === $user_id ? $conversation : null;
	}

	/**
	 * Close the thread, so the next `open()` starts a clean one.
	 *
	 * The transcript is not deleted: a merchant clearing the box wants a fresh
	 * context window, not their own history destroyed.
	 */
	public function reset( int $conversation_id ): void {
		$this->conversations->close( $conversation_id );
	}

	/**
	 * The transcript as the console should see it.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function history( int $conversation_id, int $limit = 100 ): array {
		$out = array();

		foreach ( $this->messages->for_conversation( $conversation_id, $limit ) as $row ) {
			if ( ! in_array( (string) $row->role, array( MessageRepository::ROLE_USER, MessageRepository::ROLE_ASSISTANT ), true ) ) {
				continue;
			}

			$out[] = array(
				'role'    => (string) $row->role,
				'content' => (string) $row->content,
				'agentId' => (string) $row->agent_id,
				'at'      => (string) $row->created_at,
			);
		}

		return $out;
	}

	/**
	 * Whether a thread still accepts messages.
	 */
	public function is_live( object $conversation ): bool {
		return in_array( (string) $conversation->status, ConversationRepository::LIVE_STATUSES, true );
	}

	/**
	 * The stable handle for one merchant's thread with one agent.
	 */
	private function handle( int $user_id, string $agent_id ): string {
		return hash( 'sha256', 'console:' . $user_id . ':' . $agent_id );
	}

	/**
	 * The prompt window, oldest first.
	 *
	 * Same shape as the storefront's, and same two reasons: consecutive
	 * same-role turns are merged and a leading assistant turn is dropped,
	 * because Anthropic rejects a non-alternating transcript outright — so a
	 * turn that failed before its answer was stored would otherwise break the
	 * merchant's *next* message for a reason unrelated to it.
	 *
	 * @return list<Message>
	 */
	private function history_for_prompt( int $conversation_id ): array {
		$window = $this->messages->recent_for_conversation( $conversation_id, self::HISTORY_TURNS );

		$out = array();

		foreach ( $window as $row ) {
			$role    = (string) $row->role;
			$content = trim( (string) $row->content );

			if ( '' === $content || ! in_array( $role, array( MessageRepository::ROLE_USER, MessageRepository::ROLE_ASSISTANT ), true ) ) {
				continue;
			}

			$message = MessageRepository::ROLE_USER === $role
				? Message::user( $content )
				: Message::assistant( $content );

			$previous = end( $out );

			if ( false !== $previous && $previous->role === $message->role ) {
				array_pop( $out );

				$message = MessageRepository::ROLE_USER === $role
					? Message::user( $previous->content . "\n\n" . $content )
					: Message::assistant( $previous->content . "\n\n" . $content );
			}

			$out[] = $message;
		}

		while ( array() !== $out && Message::ROLE_ASSISTANT === $out[0]->role ) {
			array_shift( $out );
		}

		return array_values( $out );
	}
}
