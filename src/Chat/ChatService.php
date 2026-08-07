<?php
/**
 * One customer turn, end to end.
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
 * The path from a typed message to a persisted answer.
 *
 * This is the only place a storefront request reaches the agent framework, and
 * it is written defensively because of where it runs: a fatal here is a fatal
 * on a product page, and the fourth principle in the PRD — *degrade, never
 * break* — makes that unacceptable regardless of what went wrong upstream. Every
 * path out of `send()` returns something the widget can render, including the
 * paths where the provider is down, the key is missing, or an add-on's filter
 * threw.
 *
 * Two things are load-bearing:
 *
 * - **History is rebuilt from the database, never from the client.** The widget
 *   posts one message; it does not post the transcript. A client that could
 *   supply its own history could fabricate an assistant turn saying identity was
 *   verified, or that a discount had been agreed, and the model would read it as
 *   something it had itself said.
 * - **Identity is re-read from the conversation row on every turn.** It is never
 *   carried in the request, and never inferred from model output.
 *
 * @see docs/01-prd.md FR-CHAT-05, FR-SUPPORT-02, R-SEC-01
 */
final class ChatService {

	/**
	 * How many prior turns are replayed into the prompt.
	 *
	 * A window rather than the whole transcript: a long conversation would
	 * otherwise grow the input token count on every message until a single turn
	 * costs more than the answer is worth.
	 */
	private const HISTORY_TURNS = 20;

	/**
	 * Longest message accepted, in characters.
	 *
	 * The ceiling is abuse protection, not a UX preference — a megabyte of text
	 * pasted into the box is a bill, not a question.
	 */
	public const MAX_MESSAGE_CHARS = 2000;

	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly Orchestrator $orchestrator,
	) {}

	/**
	 * Find the conversation this visitor should continue, if any.
	 *
	 * Order matters. The session token is checked first because it is the
	 * credential; the customer lookup is the FR-CHAT-05 cross-device path and
	 * re-binds the found conversation to the presented token, so a customer on a
	 * new laptop picks up where they left off without the old token becoming a
	 * second valid key to the same thread.
	 */
	public function resume( string $token, int $customer_id ): ?object {
		$digest = Session::digest( $token );

		if ( '' !== $digest ) {
			$found = $this->conversations->find_open_for_session( $digest );

			if ( null !== $found ) {
				// A visitor who signed in mid-conversation. Assigning revokes any
				// verification if the identity changed, which is the shared-device
				// case.
				if ( $customer_id > 0 && (int) $found->customer_id !== $customer_id ) {
					$this->conversations->assign_customer( (int) $found->id, $customer_id );

					return $this->conversations->find( (int) $found->id );
				}

				return $found;
			}
		}

		if ( $customer_id <= 0 ) {
			return null;
		}

		$found = $this->conversations->find_open_for_customer( $customer_id );

		if ( null === $found ) {
			return null;
		}

		// Re-binding needs a token to bind *to*. A signed-in customer whose first
		// request carries no token — the boot call, before any cookie exists —
		// still gets their transcript back; the binding happens on the request
		// that mints the token.
		if ( '' === $digest ) {
			return $found;
		}

		$this->conversations->rebind_session( (int) $found->id, $digest );

		return $this->conversations->find( (int) $found->id );
	}

	/**
	 * Open a conversation for this session.
	 */
	public function start( string $token, int $customer_id, string $channel = 'widget', string $locale = '' ): ?object {
		$uuid = $this->conversations->start(
			Session::digest( $token ),
			$customer_id,
			$channel,
			$locale
		);

		if ( null === $uuid ) {
			return null;
		}

		return $this->conversations->find_by_uuid( $uuid );
	}

	/**
	 * Whether a presented token owns a conversation.
	 *
	 * Compared as digests with a timing-safe comparison. The uuid identifies the
	 * conversation; this decides whether the caller may touch it.
	 */
	public function owns( object $conversation, string $token ): bool {
		$presented = Session::digest( $token );
		$stored    = (string) $conversation->session_token;

		if ( '' === $presented || '' === $stored ) {
			return false;
		}

		return hash_equals( $stored, $presented );
	}

	/**
	 * Resolve an addressed conversation for a caller, or refuse.
	 *
	 * The uuid says *which* conversation; the token says whether this caller may
	 * touch it. Resolution is deliberately by uuid rather than by "whatever
	 * conversation this session currently has", so a conversation the customer
	 * has closed is found and reported as closed rather than reported as
	 * missing — the difference between "that has ended, start a new one" and a
	 * widget that looks broken.
	 */
	public function authorise( string $uuid, string $token, int $customer_id ): ?object {
		$conversation = $this->conversations->find_by_uuid( $uuid );

		if ( null === $conversation ) {
			return null;
		}

		if ( $this->owns( $conversation, $token ) ) {
			return $conversation;
		}

		// The FR-CHAT-05 cross-device path: a signed-in customer arriving with a
		// new token may reclaim their own conversation. Authenticated by
		// WordPress, so this is not a weaker check than the token — it is a
		// stronger one.
		$digest = Session::digest( $token );

		if ( $customer_id > 0 && '' !== $digest && (int) $conversation->customer_id === $customer_id ) {
			$this->conversations->rebind_session( (int) $conversation->id, $digest );

			return $this->conversations->find( (int) $conversation->id );
		}

		return null;
	}

	/**
	 * Run one turn.
	 *
	 * @param object $conversation The conversation row.
	 * @param string $message      What the customer typed. Already length-checked.
	 */
	public function send( object $conversation, string $message ): AgentTurn {
		$conversation_id = (int) $conversation->id;

		// The window is read *before* the new message is stored, because the
		// orchestrator appends it itself. Reading after would send it twice.
		$history = $this->history_for_prompt( $conversation_id );

		$this->messages->append( $conversation_id, MessageRepository::ROLE_USER, $message );
		$this->conversations->touch( $conversation_id );

		$context = $this->context_for( $conversation );

		$turn = $this->run( $message, $history, $context );

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

		if ( $turn->needs_escalation() ) {
			$this->escalate( $conversation_id, $turn );
		}

		return $turn;
	}

	/**
	 * Invoke the orchestrator with the identity listener attached.
	 *
	 * The listener is the link between a tool proving identity mid-turn and the
	 * *next* tool call in the same turn seeing it. Verification is written to the
	 * conversation row by the tool — that is the source of truth — and mirrored
	 * into the turn's context here, so the model asking "verify me, then find my
	 * order" works in one turn instead of requiring the customer to ask twice.
	 *
	 * It is a listener rather than a return value from the tool because
	 * authorisation state must not travel through anything a model can shape.
	 *
	 * @param list<Message> $history Prior turns.
	 */
	private function run( string $message, array $history, SharedContext $context ): AgentTurn {
		$listener = static function ( int $conversation_id, int $order_id, int $customer_id ) use ( $context ): void {
			if ( $conversation_id !== $context->conversation_id ) {
				return;
			}

			$context->remember( 'identity_verified', true );
			$context->remember( 'verified_order_id', $order_id );

			unset( $customer_id );
		};

		add_action( 'storecrew_identity_verified', $listener, 10, 3 );

		// A handoff request surfaces the same way identity does: the tool
		// fires an action, a conversation-scoped listener captures it, and the
		// handoff itself runs *after* the current run completes — never
		// mid-run, and never through anything model-shaped.
		$handoff          = array();
		$handoff_listener = static function ( string $to, string $note, int $conversation_id ) use ( &$handoff, $context ): void {
			if ( $conversation_id !== $context->conversation_id ) {
				return;
			}

			$handoff = array(
				'to'   => $to,
				'note' => $note,
			);
		};

		add_action( 'storecrew_handoff_requested', $handoff_listener, 10, 3 );

		try {
			$turn = $this->orchestrator->handle( $message, $history, $context );

			// One hop per customer turn: the receiving agent's answer is the
			// reply, and a second hop would need the customer to speak again —
			// so two agents deciding to hand off to each other costs one extra
			// run, not a loop.
			if ( array() !== $handoff && $turn->succeeded() ) {
				// The note is recorded for the inspector under its own role,
				// which both the prompt window and the public transcript
				// already exclude.
				$this->messages->append(
					$context->conversation_id,
					MessageRepository::ROLE_HANDOFF,
					$handoff['note'],
					$turn->agent_id
				);

				$history[] = Message::user( $message );

				$turn = $this->orchestrator->handoff( $handoff['to'], $handoff['note'], $history, $context );
			}

			return $turn;
		} catch ( \Throwable $e ) {
			// Nothing below this line may reach the storefront as a fatal. An
			// add-on's filter throwing, a provider library changing shape, an
			// out-of-memory during a long tool result — the customer still gets
			// a sentence, and the merchant still gets the failure in the log.
			do_action( 'storecrew_chat_failed', $e, $context->conversation_id );

			return AgentTurn::failed( '', 'chat_exception', $e->getMessage() );
		} finally {
			remove_action( 'storecrew_identity_verified', $listener, 10 );
			remove_action( 'storecrew_handoff_requested', $handoff_listener, 10 );
		}
	}

	/**
	 * Record why a human is needed, then flag the conversation.
	 *
	 * The note is stored as a system-role message rather than only as a status
	 * change: FR-SUPPORT-07 asks for a structured summary, and a merchant opening
	 * the inbox needs to see *why* the agent stopped without reconstructing it
	 * from the run records.
	 */
	private function escalate( int $conversation_id, AgentTurn $turn ): void {
		$summary = sprintf(
			'Escalated: %s. Agent: %s. %s',
			$turn->outcome,
			'' !== $turn->agent_id ? $turn->agent_id : 'unrouted',
			'' !== $turn->error_message ? $turn->error_message : 'No further detail.'
		);

		$this->messages->append(
			$conversation_id,
			MessageRepository::ROLE_SYSTEM,
			$summary,
			$turn->agent_id,
			0,
			0,
			'text'
		);

		$this->conversations->escalate( $conversation_id );

		/**
		 * Fires when a conversation needs a human.
		 *
		 * @param int       $conversation_id Conversation.
		 * @param AgentTurn $turn            The turn that triggered it.
		 */
		do_action( 'storecrew_conversation_escalated', $conversation_id, $turn );
	}

	/**
	 * Context seeded from the conversation row.
	 */
	private function context_for( object $conversation ): SharedContext {
		$context = new SharedContext(
			(int) $conversation->id,
			(int) $conversation->customer_id
		);

		$context->remember( 'identity_verified', '1' === (string) $conversation->identity_verified );
		$context->remember( 'verified_order_id', (int) $conversation->verified_order_id );

		return $context;
	}

	/**
	 * The prompt window, oldest first.
	 *
	 * @return list<Message>
	 */
	private function history_for_prompt( int $conversation_id ): array {
		$window = $this->messages->recent_for_conversation( $conversation_id, self::HISTORY_TURNS );

		$out = array();

		foreach ( $window as $row ) {
			$role    = (string) $row->role;
			$content = trim( (string) $row->content );

			// Only the two roles a provider takes as conversation. System rows
			// are our own escalation notes and handoff rows are internal — both
			// would be either rejected outright or read by the model as
			// something it said.
			if ( '' === $content || ! in_array( $role, array( MessageRepository::ROLE_USER, MessageRepository::ROLE_ASSISTANT ), true ) ) {
				continue;
			}

			$message = MessageRepository::ROLE_USER === $role
				? Message::user( $content )
				: Message::assistant( $content );

			// Merge consecutive same-role turns. A turn that failed before its
			// answer was stored leaves two customer messages in a row, and
			// Anthropic rejects a non-alternating transcript outright — so the
			// customer's *next* message would fail for a reason that has nothing
			// to do with it.
			$previous = end( $out );

			if ( false !== $previous && $previous->role === $message->role ) {
				array_pop( $out );

				$message = MessageRepository::ROLE_USER === $role
					? Message::user( $previous->content . "\n\n" . $content )
					: Message::assistant( $previous->content . "\n\n" . $content );
			}

			$out[] = $message;
		}

		// A transcript must open with the customer. If the window happens to
		// start on an assistant turn — a greeting, or a trimmed window — drop it
		// rather than send a request every Anthropic model refuses.
		while ( array() !== $out && Message::ROLE_ASSISTANT === $out[0]->role ) {
			array_shift( $out );
		}

		return array_values( $out );
	}

	/**
	 * The transcript as the widget should see it.
	 *
	 * System rows are excluded: they are operator notes about the conversation,
	 * not part of it, and showing a customer "Escalated: failed. Agent: support"
	 * would be both confusing and a small information leak.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function public_history( int $conversation_id, int $limit = 100 ): array {
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
	 * Whether a conversation still accepts messages.
	 */
	public function is_live( object $conversation ): bool {
		return in_array( (string) $conversation->status, ConversationRepository::LIVE_STATUSES, true );
	}

	public function close( int $conversation_id ): void {
		$this->conversations->close( $conversation_id );
	}
}
