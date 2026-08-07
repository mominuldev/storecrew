<?php
/**
 * Usage metering.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Repositories;

use StoreCrew\Database\Repository;
use StoreCrew\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Records what was consumed, and answers whether a limit has been hit.
 *
 * Every metric is recorded, not just the one the free tier happens to meter.
 * PRD open question 1 — conversations or indexed documents — is still open, and
 * this design means answering it later is an options change rather than a
 * migration. Both figures are already being collected, so the decision can be
 * made from a real installation's own data.
 *
 * @see docs/04-database-schema.md § 7
 */
final class UsageRepository extends Repository {

	public const METRIC_CONVERSATION = 'conversation';
	public const METRIC_MESSAGE      = 'message';
	public const METRIC_AGENT_RUN    = 'agent_run';
	public const METRIC_DOCUMENT     = 'document_indexed';
	public const METRIC_CHUNK        = 'chunk_embedded';
	public const METRIC_TOKENS_IN    = 'tokens_in';
	public const METRIC_TOKENS_OUT   = 'tokens_out';

	protected function table(): string {
		return Tables::USAGE_EVENTS;
	}

	private function counters_table(): string {
		return Tables::name( Tables::USAGE_COUNTERS );
	}

	/**
	 * Current billing period, YYYY-MM in UTC.
	 */
	public static function period(): string {
		return gmdate( 'Y-m' );
	}

	/**
	 * Record consumption.
	 *
	 * Writes the event and bumps the rollup counter. The counter is updated
	 * with INSERT … ON DUPLICATE KEY UPDATE, which is atomic and needs no
	 * read-modify-write — two concurrent conversations cannot lose a count
	 * between them.
	 */
	public function record(
		string $metric,
		int $quantity = 1,
		int $conversation_id = 0,
		string $agent_id = '',
		string $provider = '',
		string $model = '',
		int $cost_micros = 0
	): int {
		$period = self::period();
		$now    = $this->now();

		$id = $this->insert_row(
			array(
				'metric'          => $metric,
				'quantity'        => $quantity,
				'period'          => $period,
				'conversation_id' => $conversation_id,
				'agent_id'        => $agent_id,
				'provider'        => $provider,
				'model'           => $model,
				'cost_micros'     => $cost_micros,
				'recorded_at'     => $now,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		$counters = $this->counters_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$this->db->query(
			$this->db->prepare(
				"INSERT INTO {$counters} (metric, period, total, cost_micros, updated_at)
				 VALUES (%s, %s, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE
				   total = total + VALUES(total),
				   cost_micros = cost_micros + VALUES(cost_micros),
				   updated_at = VALUES(updated_at)",
				$metric,
				$period,
				$quantity,
				$cost_micros,
				$now
			)
		);

		return $id;
	}

	/**
	 * Total for a metric in a period.
	 *
	 * Reads the rollup, not the event log. The free-tier check runs on every
	 * conversation start, and a COUNT(*) there would degrade precisely as a
	 * store gets busier.
	 */
	public function total( string $metric, string $period = '' ): int {
		$period = '' !== $period ? $period : self::period();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT total FROM ' . $this->counters_table() . ' WHERE metric = %s AND period = %s',
				$metric,
				$period
			)
		);
	}

	/**
	 * Accumulated cost for a period, in micros.
	 */
	public function cost_micros( string $period = '' ): int {
		$period = '' !== $period ? $period : self::period();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT SUM(cost_micros) FROM ' . $this->counters_table() . ' WHERE period = %s',
				$period
			)
		);
	}

	/**
	 * Whether a metric is within its ceiling.
	 *
	 * A ceiling of 0 or below means unlimited.
	 */
	public function within_limit( string $metric, int $ceiling, string $period = '' ): bool {
		if ( $ceiling <= 0 ) {
			return true;
		}

		return $this->total( $metric, $period ) < $ceiling;
	}

	/**
	 * Every counter for a period, for the dashboard.
	 *
	 * @return array<string, array{total: int, cost_micros: int}>
	 */
	public function summary( string $period = '' ): array {
		$period = '' !== $period ? $period : self::period();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT metric, total, cost_micros FROM ' . $this->counters_table() . ' WHERE period = %s',
				$period
			)
		);

		$out = array();

		foreach ( $rows as $row ) {
			$out[ (string) $row->metric ] = array(
				'total'       => (int) $row->total,
				'cost_micros' => (int) $row->cost_micros,
			);
		}

		return $out;
	}

	/**
	 * Rebuild counters from the event log.
	 *
	 * Events are the source of truth; counters are a cache. This exists so a
	 * cache that drifts — a partial write, a restored backup — can be made
	 * correct without losing history.
	 */
	public function rebuild_counters( string $period = '' ): int {
		$period   = '' !== $period ? $period : self::period();
		$events   = $this->table_name();
		$counters = $this->counters_table();

		$this->db->delete( $counters, array( 'period' => $period ), array( '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->db->query(
			$this->db->prepare(
				"INSERT INTO {$counters} (metric, period, total, cost_micros, updated_at)
				 SELECT metric, period, SUM(quantity), SUM(cost_micros), %s
				 FROM {$events}
				 WHERE period = %s
				 GROUP BY metric, period",
				$this->now(),
				$period
			)
		);

		return false === $affected ? 0 : (int) $affected;
	}

	/**
	 * Delete usage events past the retention window (04 § 11), batched.
	 *
	 * Events only. The counters table is untouched — aggregates hold no
	 * personal data, and deleting them would corrupt billing history, which
	 * is the reason 04 § 11 retains them indefinitely.
	 */
	public function prune( int $older_than_days, int $batch = 500 ): int {
		$table  = $this->table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->db->query(
			$this->db->prepare( "DELETE FROM {$table} WHERE recorded_at < %s LIMIT %d", $cutoff, $batch )
		);

		return false === $affected ? 0 : (int) $affected;
	}
}
