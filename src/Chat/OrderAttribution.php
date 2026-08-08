<?php
/**
 * Records that a conversation preceded an order.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Chat;

use StoreCrew\Api\Attribution;
use StoreCrew\Database\Repositories\AgentRunRepository;
use StoreCrew\Database\Repositories\AttributionRepository;
use StoreCrew\Database\Repositories\ConversationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The recording half of FR-ANALYTICS-03.
 *
 * Attribution has to be **recorded at the moment of the order**, not derived
 * later, and this class exists because of what is only true at that moment: the
 * shopper's browser is presenting the chat session cookie. An hour afterwards
 * there is no way to tell which of a store's conversations belonged to the
 * person who checked out — which is why the honest answer to "what did the crew
 * earn me" has been silence rather than an estimate.
 *
 * **The methodology is last-touch, session-scoped, and it measures influence,
 * not cause.** An order is credited to a conversation when all of these hold:
 *
 * 1. The checkout request carried the session token of a conversation, or —
 *    failing that — the order belongs to an account that had one.
 * 2. That conversation is on a **storefront** channel. A merchant's console
 *    thread is never a customer's shopping conversation (rule 7b), and a shop
 *    manager placing a test order after asking their marketing agent a
 *    question must not turn into attributed revenue.
 * 3. The crew actually **answered** in it. A conversation nobody replied in
 *    cannot have influenced anything.
 * 4. It happened inside the window — 7 days by default.
 *
 * What it cannot see is stated as plainly as what it can, because the figure is
 * a **floor and never a total**: a shopper who chats on their phone and buys on
 * a laptop, who clears cookies, or who checks out as a guest from a different
 * device is not counted. Reporting that as "revenue StoreCrew generated" would
 * be the fabricated-figure rule pointed at the merchant's own success metric.
 *
 * Nothing here decides what an order is worth. The row is a link; the money is
 * read live from WooCommerce when a report asks, so a refund stops counting
 * without anything having to notice.
 *
 * It implements {@see Attribution} because the reader of these links must get
 * the methodology from the writer of them. Publishing the reads from a class
 * that does not record would let the description and the mechanism drift.
 */
final class OrderAttribution implements Attribution {

	/**
	 * Default look-back, in days.
	 *
	 * Seven days is long enough to cover the ordinary "asked on Sunday, bought
	 * on Wednesday" shape and short enough that a conversation about socks is
	 * not still claiming credit for a sofa a month later.
	 */
	public const DEFAULT_WINDOW_DAYS = 7;

	/**
	 * Longest window the filter may set, in days.
	 *
	 * A window of a year would attribute nearly every repeat customer's order
	 * to whatever they once asked about, which is how attribution stops being
	 * a measurement and becomes a flattering number.
	 */
	private const MAX_WINDOW_DAYS = 90;

	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly AttributionRepository $attributions,
		private readonly AgentRunRepository $runs,
	) {}

	/**
	 * The checkout hooks this listens on.
	 *
	 * Both classic and Blocks checkout, because a store can serve either and
	 * declaring Blocks compatibility (FR-CORE-02) means meaning it. Firing
	 * twice is harmless — `record()` refuses to overwrite an existing link —
	 * and that is a property the double hook relies on rather than an accident
	 * it survives.
	 *
	 * Deliberately *not* `woocommerce_new_order`: that fires for orders an
	 * administrator creates in wp-admin, where the "session cookie" belongs to
	 * whoever is staffing the shop.
	 *
	 * The kernel attaches these behind a lazy resolver rather than the class
	 * registering itself, because constructing it constructs three
	 * repositories and most page loads are not checkouts.
	 *
	 * @var list<string>
	 */
	public const HOOKS = array(
		'woocommerce_checkout_order_created',
		'woocommerce_store_api_checkout_order_processed',
	);

	/**
	 * Attribute one order, if anything earns it.
	 *
	 * @param mixed $order A WC_Order, as both checkout hooks supply.
	 */
	public function from_order( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		$order_id = (int) $order->get_id();

		if ( $order_id < 1 || $this->attributions->has( $order_id ) ) {
			return;
		}

		$minutes = $this->window_minutes();

		$conversation = $this->conversations->find_recent_for_session(
			Session::digest( Session::from_cookie() ),
			$minutes
		);

		$basis = AttributionRepository::BASIS_SESSION;

		if ( null === $conversation ) {
			$customer_id = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;

			$conversation = $this->conversations->find_recent_for_customer( $customer_id, $minutes );
			$basis        = AttributionRepository::BASIS_CUSTOMER;
		}

		if ( null === $conversation ) {
			return;
		}

		$this->attributions->record(
			$order_id,
			(int) $conversation->id,
			$basis,
			$this->runs->last_agent_for( (int) $conversation->id ),
			$this->minutes_since( (string) $conversation->last_activity_at )
		);

		/**
		 * Fires after an order has been credited to a conversation.
		 *
		 * @param int    $order_id        The order.
		 * @param int    $conversation_id The conversation credited.
		 * @param string $basis           How the link was made.
		 */
		do_action( 'storecrew_order_attributed', $order_id, (int) $conversation->id, $basis );
	}

	/**
	 * Links recorded in a window, oldest first.
	 *
	 * @return list<object>
	 */
	public function between( string $from_gmt, string $to_gmt, int $limit = AttributionRepository::MAX_ROWS ): array {
		return $this->attributions->between( $from_gmt, $to_gmt, $limit );
	}

	/**
	 * How many links the window holds, ignoring the row ceiling.
	 */
	public function count_between( string $from_gmt, string $to_gmt ): int {
		return $this->attributions->count_between( $from_gmt, $to_gmt );
	}

	/**
	 * The methodology, in the words a report must repeat.
	 *
	 * **Published from here rather than restated by the reader.** FR-ANALYTICS-03
	 * requires the methodology to be stated with the figure, and a statement
	 * kept anywhere other than beside the mechanism drifts away from it — at
	 * which point the merchant is being told something untrue about a number
	 * that is otherwise correct.
	 *
	 * @return array<string, mixed>
	 */
	public function methodology(): array {
		return array(
			'model'       => 'last-touch',
			'windowDays'  => (int) round( $this->window_minutes() / ( 24 * 60 ) ),
			'bases'       => array(
				AttributionRepository::BASIS_SESSION  => 'The checkout request carried the conversation\'s own session token.',
				AttributionRepository::BASIS_CUSTOMER => 'No session token reached checkout, but the order\'s account held a conversation in the window.',
			),
			'requires'    => array(
				'A storefront conversation — merchant console threads are never counted.',
				'At least one answer from the crew in that conversation.',
				'One order credits at most one conversation, and the first link recorded wins.',
			),
			'undercounts' => array(
				'A shopper who chats on one device and buys on another.',
				'A shopper who clears cookies, or checks out as a guest from elsewhere.',
				'Orders whose conversation has since passed the retention window.',
			),
			'statement'   => 'Revenue from orders placed within the window by a shopper who had an answered '
				. 'storefront conversation. This measures influence, not cause, and it is a floor: '
				. 'conversations that led to a purchase on another device cannot be seen and are not counted.',
		);
	}

	/**
	 * The window, in minutes, after the filter and the clamp.
	 */
	private function window_minutes(): int {
		/**
		 * How long after a conversation an order may still be credited to it.
		 *
		 * Clamped to 1–90 days. A window measured in years does not measure
		 * attribution; it measures having ever had a conversation.
		 *
		 * @param int $days Look-back in days.
		 */
		$days = (int) apply_filters( 'storecrew_attribution_window_days', self::DEFAULT_WINDOW_DAYS );

		return max( 1, min( $days, self::MAX_WINDOW_DAYS ) ) * 24 * 60;
	}

	/**
	 * Minutes between a conversation's last activity and now.
	 */
	private function minutes_since( string $last_activity_gmt ): int {
		$then = strtotime( $last_activity_gmt . ' UTC' );

		if ( false === $then ) {
			return 0;
		}

		return max( 0, (int) round( ( time() - $then ) / 60 ) );
	}
}
