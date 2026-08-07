<?php
/**
 * The storefront chat endpoints.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Rest\Controllers;

use StoreCrew\Agent\Orchestrator;
use StoreCrew\Ai\ModelPolicy;
use StoreCrew\Api\Rest\RestController;
use StoreCrew\Chat\ChatService;
use StoreCrew\Chat\ChatSettings;
use StoreCrew\Chat\RateLimiter;
use StoreCrew\Chat\Session;
use StoreCrew\Licensing\FeatureGate;

defined( 'ABSPATH' ) || exit;

/**
 * The only routes in this plugin an anonymous visitor may call.
 *
 * Everything else requires a capability. These four do not, because a shopper
 * asking a question is not a WordPress user — so the access decision moves to
 * three things that are checked on every request instead:
 *
 * 1. **The feature is on.** A store that has not enabled chat has no chat API.
 * 2. **The session token owns the conversation.** The uuid in the URL addresses
 *    a conversation; the token in the cookie is what permits touching it. A uuid
 *    on its own gets a 404, and it gets the *same* 404 whether the conversation
 *    exists or not.
 * 3. **The caller is within its rate limit.** Per session and per address
 *    (FR-CHAT-06).
 *
 * The nonce is deliberately not a gate. WordPress treats a cookie-authenticated
 * REST request with no nonce as anonymous, so a nonce here would be the
 * difference between a signed-in customer being recognised and being treated as
 * a guest — useful, and supplied by the widget, but a stale one from a cached
 * page must degrade to "guest", never to "refused".
 *
 * @see docs/01-prd.md FR-CHAT-01 … FR-CHAT-07
 */
final class ChatController extends RestController {

	public const FEATURE = 'chat.widget';

	public function __construct(
		FeatureGate $features,
		private readonly ChatService $chat,
		private readonly Orchestrator $orchestrator,
		private readonly ModelPolicy $policy,
	) {
		parent::__construct( $features );
	}

	public function register_routes(): void {
		// "Never cached" has to be enforced, not asserted: core only sends
		// nocache headers on REST responses for logged-in users, and the chat
		// caller is anonymous by design. On a host whose CDN caches REST GETs,
		// an unmarked /chat/boot would serve one visitor's nonce — and a
		// resumed visitor's transcript — to the next. Every chat response is
		// per-visitor, so the whole surface is marked no-store, errors included.
		add_filter( 'rest_post_dispatch', array( $this, 'nocache' ), 10, 3 );

		$this->route(
			'/chat/boot',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'boot' ),
				'permission_callback' => $this->public_access(),
			)
		);

		$this->route(
			'/chat/session',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'session' ),
				'permission_callback' => $this->public_access(),
			)
		);

		$this->route(
			'/chat/(?P<uuid>[a-f0-9-]{36})/messages',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'history' ),
					'permission_callback' => $this->public_access(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'send' ),
					'permission_callback' => $this->public_access(),
					'args'                => array(
						'message' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		$this->route(
			'/chat/(?P<uuid>[a-f0-9-]{36})/close',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'close' ),
				'permission_callback' => $this->public_access(),
			)
		);
	}

	/**
	 * Mark every chat response uncacheable.
	 *
	 * Runs on `rest_post_dispatch` so it covers all five routes and their
	 * error responses from one place — a new chat route cannot forget it.
	 */
	public function nocache(
		\WP_HTTP_Response $response,
		\WP_REST_Server $server,
		\WP_REST_Request $request
	): \WP_HTTP_Response {
		unset( $server );

		if ( str_starts_with( $request->get_route(), '/' . self::NAMESPACE . '/chat' ) ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
		}

		return $response;
	}

	/**
	 * Everything the widget needs before it draws anything.
	 *
	 * Served from a request of its own rather than printed into the page,
	 * because the page may be cached for hours and this payload carries a nonce
	 * and a resumed conversation — both of which are wrong the moment they are
	 * cached. What *is* printed into the page is only the REST root, which never
	 * changes.
	 */
	public function boot( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = ChatSettings::all();

		$ready = $this->is_ready();

		$payload = array(
			'enabled'      => (bool) $settings['enabled'] && $this->features->enabled( self::FEATURE ),
			'ready'        => $ready,
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'maxChars'     => ChatService::MAX_MESSAGE_CHARS,
			'appearance'   => array(
				'position'    => (string) $settings['position'],
				'accent'      => (string) $settings['accent'],
				'title'       => (string) $settings['title'],
				'launcher'    => (string) $settings['launcher'],
				'greeting'    => (string) $settings['greeting'],
				'placeholder' => (string) $settings['placeholder'],
				'offline'     => (string) $settings['offlineNotice'],
			),
			'conversation' => null,
		);

		$conversation = $this->chat->resume( Session::from_request( $request ), get_current_user_id() );

		if ( null !== $conversation ) {
			$payload['conversation'] = array(
				'uuid'     => (string) $conversation->uuid,
				'messages' => $this->chat->public_history( (int) $conversation->id ),
			);
		}

		return $this->ok( $payload );
	}

	/**
	 * Open a conversation, or hand back the one already in progress.
	 */
	public function session( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$gate = $this->guard();

		if ( $gate instanceof \WP_Error ) {
			return $gate;
		}

		$token = Session::from_request( $request );
		$fresh = '';

		if ( '' === $token ) {
			$token = Session::issue();
			$fresh = $token;
			Session::send_cookie( $token );
		}

		$conversation = $this->chat->resume( $token, get_current_user_id() );

		if ( null === $conversation ) {
			$conversation = $this->chat->start(
				$token,
				get_current_user_id(),
				'widget',
				(string) get_locale()
			);
		}

		if ( null === $conversation ) {
			return $this->error( 'session_failed', __( 'Chat is unavailable right now.', 'storecrew' ), 503 );
		}

		return $this->ok(
			array(
				'uuid'     => (string) $conversation->uuid,
				// Returned only on the request that minted it, so the widget can
				// carry it in a header where the cookie did not survive the host's
				// page cache. Never returned for a token the caller already holds.
				'token'    => $fresh,
				'messages' => $this->chat->public_history( (int) $conversation->id ),
			)
		);
	}

	/**
	 * The transcript of one conversation.
	 */
	public function history( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$conversation = $this->authorised_conversation( $request );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		return $this->ok(
			array(
				'uuid'      => (string) $conversation->uuid,
				'status'    => (string) $conversation->status,
				'escalated' => '' !== (string) ( $conversation->escalated_at ?? '' ),
				'messages'  => $this->chat->public_history( (int) $conversation->id ),
			)
		);
	}

	/**
	 * One customer turn.
	 */
	public function send( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$gate = $this->guard();

		if ( $gate instanceof \WP_Error ) {
			return $gate;
		}

		$conversation = $this->authorised_conversation( $request );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		if ( ! $this->chat->is_live( $conversation ) ) {
			return $this->error(
				'conversation_closed',
				__( 'This conversation has ended. Start a new one to carry on.', 'storecrew' ),
				409
			);
		}

		$message = trim( (string) $request->get_param( 'message' ) );

		if ( '' === $message ) {
			return $this->error( 'empty_message', __( 'Type a message first.', 'storecrew' ), 400 );
		}

		if ( mb_strlen( $message ) > ChatService::MAX_MESSAGE_CHARS ) {
			return $this->error(
				'message_too_long',
				sprintf(
					/* translators: %d: maximum number of characters */
					__( 'Messages are limited to %d characters.', 'storecrew' ),
					ChatService::MAX_MESSAGE_CHARS
				),
				413
			);
		}

		// Consumed here rather than in `guard()` so that reading a transcript,
		// or booting the widget, never eats a customer's allowance to speak.
		$wait = RateLimiter::configured()->consume(
			Session::from_request( $request ),
			Session::client_ip()
		);

		if ( null !== $wait ) {
			// Built directly rather than through `error()` so the wait travels
			// with the refusal. A widget that knows how long to wait can say so;
			// one that does not will retry immediately and be refused again.
			return new \WP_Error(
				'storecrew_rate_limited',
				__( 'You are sending messages faster than I can answer. Please wait a moment.', 'storecrew' ),
				array(
					'status'     => 429,
					'retryAfter' => $wait,
				)
			);
		}

		$turn = $this->chat->send( $conversation, $message );

		return $this->ok(
			array(
				'uuid'      => (string) $conversation->uuid,
				'reply'     => array(
					'role'    => 'assistant',
					'content' => $turn->text,
					'agentId' => $turn->agent_id,
				),
				'outcome'   => $turn->outcome,
				'escalated' => $turn->needs_escalation(),
			)
		);
	}

	/**
	 * End a conversation at the customer's request.
	 */
	public function close( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$conversation = $this->authorised_conversation( $request );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		$this->chat->close( (int) $conversation->id );

		return $this->ok(
			array(
				'uuid'   => (string) $conversation->uuid,
				'status' => 'closed',
			)
		);
	}

	/**
	 * Resolve the addressed conversation, or refuse.
	 *
	 * A uuid that does not exist and a uuid the caller does not own return the
	 * **same** 404. Distinguishing them would confirm that a given conversation
	 * exists, which is exactly what someone holding a leaked uuid wants to know.
	 */
	private function authorised_conversation( \WP_REST_Request $request ): \stdClass|\WP_Error {
		$not_found = $this->error(
			'no_conversation',
			__( 'That conversation is not available.', 'storecrew' ),
			404
		);

		$token = Session::from_request( $request );

		if ( '' === $token ) {
			return $not_found;
		}

		$conversation = $this->chat->authorise(
			(string) $request->get_param( 'uuid' ),
			$token,
			get_current_user_id()
		);

		return $conversation ?? $not_found;
	}

	/**
	 * Whether chat may run at all right now.
	 */
	private function guard(): ?\WP_Error {
		$settings = ChatSettings::all();

		if ( ! $settings['enabled'] || ! $this->features->enabled( self::FEATURE ) ) {
			return $this->error( 'chat_disabled', __( 'Chat is not available on this store.', 'storecrew' ), 403 );
		}

		if ( ! $this->is_ready() ) {
			return $this->error( 'chat_unconfigured', __( 'Chat is not available right now.', 'storecrew' ), 503 );
		}

		return null;
	}

	/**
	 * Whether a turn could actually be answered.
	 *
	 * Checked before a conversation is opened rather than after a customer has
	 * typed into it. A widget that accepts a question and then reports a
	 * configuration error reads as a broken store; one that never appears reads
	 * as a store without chat.
	 */
	private function is_ready(): bool {
		if ( null === $this->policy->resolve( ModelPolicy::TASK_CHAT ) ) {
			return false;
		}

		return array() !== $this->orchestrator->available_agents();
	}
}
