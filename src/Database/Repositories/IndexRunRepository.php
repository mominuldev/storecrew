<?php
/**
 * Index run persistence.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Repositories;

use StoreCrew\Database\Repository;
use StoreCrew\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Progress and health of indexing jobs.
 *
 * @see docs/04-database-schema.md § 8.1
 */
final class IndexRunRepository extends Repository {

	public const STATUS_QUEUED   = 'queued';
	public const STATUS_RUNNING  = 'running';
	public const STATUS_COMPLETE = 'completed';
	public const STATUS_FAILED   = 'failed';
	public const STATUS_STALLED  = 'stalled';

	/**
	 * A run whose heartbeat is older than this is presumed dead.
	 */
	public const HEARTBEAT_TIMEOUT = 300;

	protected function table(): string {
		return Tables::INDEX_RUNS;
	}

	public function start( string $type = 'full', int $total = 0 ): int {
		$now = $this->now();

		return $this->insert_row(
			array(
				'type'         => $type,
				'status'       => self::STATUS_RUNNING,
				'total'        => $total,
				'started_at'   => $now,
				'heartbeat_at' => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Record progress and refresh the heartbeat.
	 *
	 * `cursor_position` is what makes a killed run resumable rather than
	 * restarting from zero — and re-billing the merchant for embeddings they
	 * already paid for (R-TECH-03).
	 */
	public function progress( int $id, int $processed, int $failed = 0, string $cursor = '', int $cost_micros = 0 ): bool {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return false !== $this->db->query(
			$this->db->prepare(
				"UPDATE {$table}
				 SET processed = processed + %d,
				     failed = failed + %d,
				     cursor_position = %s,
				     cost_micros = cost_micros + %d,
				     heartbeat_at = %s
				 WHERE id = %d",
				$processed,
				$failed,
				$cursor,
				$cost_micros,
				$this->now(),
				$id
			)
		);
	}

	public function heartbeat( int $id ): bool {
		return $this->update_by_id( $id, array( 'heartbeat_at' => $this->now() ), array( '%s' ) );
	}

	public function finish( int $id, string $status = self::STATUS_COMPLETE, string $error = '' ): bool {
		return $this->update_by_id(
			$id,
			array(
				'status'      => $status,
				'last_error'  => '' !== $error ? mb_substr( $error, 0, 1000 ) : null,
				'finished_at' => $this->now(),
			),
			array( '%s', '%s', '%s' )
		);
	}

	public function find( int $id ): ?object {
		return $this->find_row( $id );
	}

	/**
	 * The run currently in progress, if any.
	 */
	public function active(): ?object {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE status IN (%s, %s) ORDER BY started_at DESC LIMIT 1",
				self::STATUS_RUNNING,
				self::STATUS_QUEUED
			)
		);

		return $row ?: null;
	}

	/**
	 * Whether a run is alive, judged by heartbeat rather than status.
	 *
	 * A job killed mid-flight leaves `status = running` forever. Reporting that
	 * as in-progress is the failure mode merchants actually hit, so health is
	 * derived from the heartbeat instead (FR-ADMIN-08).
	 */
	public function is_alive( object $run ): bool {
		if ( self::STATUS_RUNNING !== (string) $run->status ) {
			return false;
		}

		if ( null === $run->heartbeat_at ) {
			return false;
		}

		return ( time() - strtotime( (string) $run->heartbeat_at . ' UTC' ) ) < self::HEARTBEAT_TIMEOUT;
	}

	/**
	 * Mark heartbeat-dead runs as stalled. Returns the number affected.
	 */
	public function reap_stalled(): int {
		$table  = $this->table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::HEARTBEAT_TIMEOUT );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$table} SET status = %s, last_error = %s, finished_at = %s
				 WHERE status = %s AND (heartbeat_at IS NULL OR heartbeat_at < %s)",
				self::STATUS_STALLED,
				'No heartbeat; the process was terminated.',
				$this->now(),
				self::STATUS_RUNNING,
				$cutoff
			)
		);

		return false === $affected ? 0 : (int) $affected;
	}

	/**
	 * Recent runs, newest first.
	 *
	 * @return list<object>
	 */
	public function recent( int $limit = 10 ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->get_results(
			$this->db->prepare( "SELECT * FROM {$table} ORDER BY started_at DESC LIMIT %d", $limit )
		);
	}
}
