<?php
/**
 * Which orders followed a conversation, as much as an add-on should see.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Read the conversation-to-order links the platform records (FR-ANALYTICS-03).
 *
 * The free plugin is the only thing that *can* record these: it owns the
 * conversation and the storefront session cookie, and the link is only visible
 * during the checkout request that carries both. Reporting on them is a premium
 * concern. This interface is that seam.
 *
 * **The methodology travels with the data, and that is the point of publishing
 * it here rather than letting a reader describe what it thinks the links mean.**
 * FR-ANALYTICS-03 requires the methodology stated alongside the figure; a
 * statement kept in the reporting plugin would drift from the mechanism in the
 * recording one, and the merchant would then be told something false about a
 * number that is otherwise correct. {@see self::methodology()} is written by the
 * code that does the recording.
 *
 * **No money crosses this interface, deliberately.** A link is an order id and a
 * conversation id. What the order was worth is read from WooCommerce by the
 * caller, live, so a refunded order stops counting without anything here having
 * to know it happened — the same discipline FR-KB-08 applies to price and stock.
 *
 * Resolve it from the published container:
 *
 * ```php
 * $attribution = $api->container()->get( \StoreCrew\Api\Attribution::class );
 * $links = $attribution->between( '2026-07-01 00:00:00', '2026-08-01 00:00:00' );
 * ```
 *
 * @see docs/15-free-premium-split.md § 4
 *
 * @api
 */
interface Attribution {

	/**
	 * Links recorded in a window, oldest first.
	 *
	 * Each row carries `order_id`, `conversation_id`, `basis`, `agent_id`,
	 * `minutes_elapsed`, and `recorded_at`. Read the order for its total and
	 * status; this table holds neither.
	 *
	 * Bounded — see {@see self::count_between()} for whether the answer is
	 * complete.
	 *
	 * @param string $from_gmt Inclusive lower bound, `Y-m-d H:i:s` UTC.
	 * @param string $to_gmt   Exclusive upper bound, `Y-m-d H:i:s` UTC.
	 * @param int    $limit    Row ceiling.
	 *
	 * @return list<object>
	 */
	public function between( string $from_gmt, string $to_gmt, int $limit = 5000 ): array;

	/**
	 * How many links the window holds, ignoring the row ceiling.
	 *
	 * Exists so a caller can tell a complete answer from a truncated one before
	 * presenting a total. A report that silently summed the first N links and
	 * called it revenue would be under-counting in the direction that looks
	 * like a working feature.
	 */
	public function count_between( string $from_gmt, string $to_gmt ): int;

	/**
	 * How attribution is decided, in the words a report must repeat.
	 *
	 * Keys: `model`, `windowDays`, `bases`, `requires`, `undercounts`,
	 * `statement`. `undercounts` is not decoration — the figure is a floor,
	 * because a shopper who chats on a phone and buys on a laptop cannot be
	 * seen, and presenting a floor as a total is the fabricated-figure defect
	 * pointed at the merchant's own success metric.
	 *
	 * @return array<string, mixed>
	 */
	public function methodology(): array;
}
