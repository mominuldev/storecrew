<?php
/**
 * Who is asking, and what they have proven.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent\Tool;

defined( 'ABSPATH' ) || exit;

/**
 * The session facts a tool is authorised against.
 *
 * Constructed from the conversation record and the current WordPress user —
 * **never from anything the model produced**. That is the whole point: if a
 * tool could read its authorisation out of its own arguments, a prompt
 * injection would be able to claim `identity_verified: true` and read another
 * customer's orders.
 *
 * @see docs/01-prd.md R-SEC-01, FR-SUPPORT-02
 */
final readonly class ToolContext {

	public function __construct(
		public int $conversation_id,
		public int $customer_id = 0,
		public bool $identity_verified = false,
		public int $verified_order_id = 0,
		public string $agent_id = '',
		/** True on the storefront, where there is no logged-in administrator. */
		public bool $is_storefront = true,
	) {}

	/**
	 * Whether the session holds a capability.
	 *
	 * Storefront visitors hold none of ours, which is why order tools depend on
	 * proven identity rather than on capabilities.
	 */
	public function can( string $capability ): bool {
		if ( '' === $capability ) {
			return true;
		}

		return current_user_can( $capability );
	}
}
