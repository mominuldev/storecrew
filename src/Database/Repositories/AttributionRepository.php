<?php
/**
 * Which conversations preceded which orders.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Repositories;

use StoreCrew\Database\Repository;
use StoreCrew\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * The only consumer of `scr_attributions` (rule 5).
 *
 * **This repository stores no money and no personal data.** It holds a pair of
 * ids and the circumstances under which they were joined. Revenue is read from
 * the order at report time, so a refund stops counting on its own; the customer
 * behind the order is WooCommerce's record, not ours.
 *
 * Everything here is written from a checkout request and read from an admin
 * report, which is why `record()` is cheap and the reads are the interesting
 * half.
 *
 * @see docs/04-database-schema.md § 3.3
 */
final class AttributionRepository extends Repository {

	/**
	 * The shopper's browser still held the conversation's session at checkout.
	 * The strong basis: it is the same person on the same device.
	 */
	public const BASIS_SESSION = 'session';

	/**
	 * No session cookie, but the order belongs to an account that had a
	 * conversation inside the window. Weaker — the same account may be a
	 * household — and stored distinctly so a report can say which it counted.
	 */
	public const BASIS_CUSTOMER = 'customer';

	/**
	 * Most rows one read will return.
	 *
	 * A report over a busy year could otherwise load tens of thousands of rows
	 * into a PHP request. When it bites the caller is told, because a truncated
	 * total presented as a total is the defect this whole feature exists to
	 * avoid producing.
	 */
	public const MAX_ROWS = 5000;

	protected function table(): string {
		return Tables::ATTRIBUTIONS;
	}

	/**
	 * Record that a conversation preceded an order.
	 *
	 * **Never overwrites.** `order_id` is unique, so the first recorded link
	 * wins and every later attempt is a no-op — which is what makes the
	 * duplicate checkout hooks safe, and what makes the model last-touch by
	 * construction. A store running both classic and Blocks checkout fires two
	 * hooks for one order; the second must not re-point the attribution at a
	 * different conversation, because by then "the conversation this shopper
	 * was in" may have moved on.
	 *
	 * @param int    $order_id        WooCommerce order.
	 * @param int    $conversation_id The conversation being credited.
	 * @param string $basis           One of the BASIS_* constants.
	 * @param string $agent_id        Agent that answered last, for reporting.
	 * @param int    $minutes_elapsed Minutes between last activity and the order.
	 *
	 * @return bool True when this call created the link.
	 */
	public function record(
		int $order_id,
		int $conversation_id,
		string $basis,
		string $agent_id = '',
		int $minutes_elapsed = 0
	): bool {
		if ( $order_id < 1 || $conversation_id < 1 ) {
			return false;
		}

		if ( ! in_array( $basis, array( self::BASIS_SESSION, self::BASIS_CUSTOMER ), true ) ) {
			return false;
		}

		if ( $this->has( $order_id ) ) {
			return false;
		}

		$id = $this->insert_row(
			array(
				'order_id'        => $order_id,
				'conversation_id' => $conversation_id,
				'basis'           => $basis,
				'agent_id'        => substr( $agent_id, 0, 64 ),
				'minutes_elapsed' => max( 0, $minutes_elapsed ),
				'recorded_at'     => $this->now(),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s' )
		);

		// A losing race on the unique key is an insert failure, not an error:
		// the link exists, which is all the caller wanted.
		return $id > 0;
	}

	/**
	 * Whether this order is already attributed.
	 */
	public function has( int $order_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$found = $this->db->get_var(
			$this->db->prepare(
				'SELECT id FROM ' . $this->table_name() . ' WHERE order_id = %d LIMIT 1',
				$order_id
			)
		);

		return null !== $found;
	}

	/**
	 * Links recorded in a window, oldest first.
	 *
	 * Returns one row per order. The caller reads the order itself for its
	 * total and status — this table deliberately holds neither.
	 *
	 * @param string $from_gmt Inclusive lower bound, `Y-m-d H:i:s` UTC.
	 * @param string $to_gmt   Exclusive upper bound.
	 * @param int    $limit    Row ceiling; clamped to MAX_ROWS.
	 *
	 * @return list<object>
	 */
	public function between( string $from_gmt, string $to_gmt, int $limit = self::MAX_ROWS ): array {
		$limit = max( 1, min( $limit, self::MAX_ROWS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table_name()
					. ' WHERE recorded_at >= %s AND recorded_at < %s ORDER BY id ASC LIMIT %d',
				$from_gmt,
				$to_gmt,
				$limit
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many links a window holds, ignoring the row ceiling.
	 *
	 * Read alongside {@see self::between()} so a caller can tell a complete
	 * answer from a truncated one rather than discovering it by counting.
	 */
	public function count_between( string $from_gmt, string $to_gmt ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT COUNT(*) FROM ' . $this->table_name()
					. ' WHERE recorded_at >= %s AND recorded_at < %s',
				$from_gmt,
				$to_gmt
			)
		);
	}

	/**
	 * Drop the links belonging to a set of conversations.
	 *
	 * The cascade half of retention (04 § 11) and of GDPR erasure. A link whose
	 * conversation is gone cannot be checked by anyone, so keeping it would
	 * leave a revenue figure nobody could explain — the shape of defect this
	 * codebase treats as worse than a missing figure.
	 *
	 * @param list<int> $conversation_ids Conversations being removed or anonymised.
	 */
	public function delete_for_conversations( array $conversation_ids ): int {
		$ids = array_values( array_filter( array_map( 'intval', $conversation_ids ) ) );

		if ( array() === $ids ) {
			return 0;
		}

		$holes = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->db->query(
			$this->db->prepare(
				'DELETE FROM ' . $this->table_name() . " WHERE conversation_id IN ({$holes})",
				$ids
			)
		);

		return false === $affected ? 0 : (int) $affected;
	}
}
