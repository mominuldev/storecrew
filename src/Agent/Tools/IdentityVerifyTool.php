<?php
/**
 * Proving who the customer is.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent\Tools;

use StoreCrew\Agent\Tool\ToolContext;
use StoreCrew\Agent\Tool\ToolInterface;
use StoreCrew\Agent\Tool\ToolResult;
use StoreCrew\Ai\ToolDefinition;
use StoreCrew\Database\Repositories\AuditLogRepository;
use StoreCrew\Database\Repositories\ConversationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Order number plus the email on that order (FR-SUPPORT-01).
 *
 * This is the gate every order tool sits behind. `ToolExecutor` already refuses
 * `order.lookup` to an unverified conversation and tells the customer to give
 * their order number and email — this is what receives them. Without it that
 * instruction leads nowhere, which is the state the plugin shipped in until the
 * storefront gained a chat surface and it became reachable.
 *
 * Three properties matter more than the happy path:
 *
 * 1. **Failure is indistinguishable.** "No such order" and "that email does not
 *    match" return the same sentence. Distinguishing them turns this into an
 *    oracle that confirms which order numbers exist.
 * 2. **Attempts are capped per conversation.** Order numbers are sequential and
 *    email addresses leak; unlimited attempts make guessing a matter of
 *    patience. The cap is on the conversation rather than the session, so
 *    discarding a cookie starts a conversation with no history to guess against.
 * 3. **Verification proves one order.** The row records *which* order was
 *    proven, and `order.lookup` refuses any other. Identity here is not a
 *    passport to the order table.
 *
 * Declared as a read even though it writes to the conversations table. Intent
 * governs the approval queue (FR-AGENT-05), which exists for changes to
 * *merchant* data; queueing identity checks for human approval would make the
 * support agent unusable and train merchants to approve without reading.
 *
 * @see docs/01-prd.md FR-SUPPORT-01, FR-SUPPORT-02, R-SEC-02
 */
final class IdentityVerifyTool implements ToolInterface {

	public const ID = 'identity.verify';

	/**
	 * Attempts allowed in one conversation.
	 */
	private const MAX_ATTEMPTS = 5;

	private const ATTEMPT_PREFIX = 'storecrew_idv_';

	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly AuditLogRepository $audit,
	) {}

	public function id(): string {
		return self::ID;
	}

	public function definition(): ToolDefinition {
		return new ToolDefinition(
			self::ID,
			'Confirm who the customer is, using their order number and the email address on that order. '
			. 'Use this as soon as they mention an order, before looking anything up — and use it again '
			. 'if they ask about a different order. Ask for both details in one message rather than one at a time.',
			array(
				'type'       => 'object',
				'properties' => array(
					'order_id' => array(
						'type'        => 'integer',
						'description' => 'The order number the customer gave, digits only.',
					),
					'email'    => array(
						'type'        => 'string',
						'description' => 'The email address the customer gave for that order.',
					),
				),
				'required'   => array( 'order_id' ),
			)
		);
	}

	public function intent(): string {
		return self::INTENT_READ;
	}

	public function required_capability(): string {
		// Storefront visitors hold no capability by definition — this tool is
		// how they earn access, so requiring one would make it unreachable.
		return '';
	}

	public function requires_identity(): bool {
		// The one tool that must not, or nothing could ever become verified.
		return false;
	}

	public function execute( ToolContext $context, array $input ): ToolResult {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return ToolResult::error( 'Order lookup is unavailable.' );
		}

		$order_id = (int) ( $input['order_id'] ?? 0 );
		$email    = strtolower( trim( (string) ( $input['email'] ?? '' ) ) );

		if ( $order_id <= 0 ) {
			return ToolResult::error( 'I need the order number to check that.' );
		}

		if ( ! $this->take_attempt( $context->conversation_id ) ) {
			$this->record( $context, $order_id, 'attempts_exhausted' );

			return ToolResult::denied(
				'I have tried to confirm those details too many times in this conversation. '
				. 'Please contact the store team directly and they will help.'
			);
		}

		$order = wc_get_order( $order_id );

		// wc_get_order returns refunds and other order types too; only a real
		// customer order carries a billing email worth matching against.
		$verified = $order instanceof \WC_Order && $this->matches( $order, $email, $context->customer_id );

		if ( ! $verified ) {
			$this->record( $context, $order_id, 'failed' );

			// Identical for every kind of miss. See the class comment.
			return ToolResult::error(
				'Those details do not match an order I can find. Please check the order number and '
				. 'the email address used when ordering.'
			);
		}

		$this->conversations->mark_verified(
			$context->conversation_id,
			$order_id,
			$context->customer_id
		);

		$this->record( $context, $order_id, 'verified' );

		/**
		 * Fires when a conversation proves an identity.
		 *
		 * Carries the conversation rather than the customer because that is what
		 * was proven: this order, in this conversation. Anything wider would be
		 * a claim the check did not make.
		 *
		 * @param int $conversation_id Conversation.
		 * @param int $order_id        The order that was proven.
		 * @param int $customer_id     Customer, when signed in.
		 */
		do_action( 'storecrew_identity_verified', $context->conversation_id, $order_id, $context->customer_id );

		return ToolResult::ok(
			sprintf( 'Identity confirmed for order #%d. You can now look up this order.', $order_id ),
			array( 'order_id' => $order_id )
		);
	}

	/**
	 * Whether the presented details prove this order.
	 *
	 * A signed-in customer who owns the order is verified without an email,
	 * because WordPress already authenticated them — asking again would be
	 * theatre. Everyone else must match the billing address on the order.
	 */
	private function matches( \WC_Order $order, string $email, int $customer_id ): bool {
		if ( $customer_id > 0 && (int) $order->get_customer_id() === $customer_id ) {
			return true;
		}

		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$billing = strtolower( trim( (string) $order->get_billing_email() ) );

		if ( '' === $billing ) {
			return false;
		}

		// Timing-safe: a plain comparison leaks the length of the shared prefix,
		// which over enough attempts is a way to recover the address.
		return hash_equals( $billing, $email );
	}

	/**
	 * Consume one attempt. Returns false once the conversation is out.
	 *
	 * A transient, deliberately. Losing the counter to a cache flush costs a
	 * handful of extra guesses; a row per attempt would put a write on the path
	 * of every failed identity check, which is exactly the path an abuser
	 * hammers.
	 */
	private function take_attempt( int $conversation_id ): bool {
		$key  = self::ATTEMPT_PREFIX . $conversation_id;
		$used = (int) get_transient( $key );

		if ( $used >= self::MAX_ATTEMPTS ) {
			return false;
		}

		set_transient( $key, $used + 1, HOUR_IN_SECONDS );

		return true;
	}

	private function record( ToolContext $context, int $order_id, string $outcome ): void {
		$this->audit->record(
			'identity.' . $outcome,
			AuditLogRepository::ACTOR_AGENT,
			$context->agent_id,
			'conversation',
			$context->conversation_id,
			array( 'order_id' => $order_id ),
			// Failed verification attempts are the signal a merchant needs when
			// someone is walking the order table, and an address is what ties
			// separate conversations together as one actor.
			isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : ''
		);
	}
}
