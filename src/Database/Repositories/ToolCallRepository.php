<?php
/**
 * Tool call persistence and the approval queue.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Repositories;

use StoreCrew\Database\Repository;
use StoreCrew\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Tool calls, including writes waiting for a human.
 *
 * The approval queue is this table rather than a separate one (FR-ADMIN-06). A
 * pending write *is* a tool call that has not executed yet; modelling it
 * separately would mean two rows describing one act and an opportunity for them
 * to disagree about whether it ran.
 *
 * @see docs/04-database-schema.md § 4.2
 */
final class ToolCallRepository extends Repository {

	public const INTENT_READ  = 'read';
	public const INTENT_WRITE = 'write';

	public const AUTH_AUTO     = 'auto';
	public const AUTH_REQUIRED = 'required';
	public const AUTH_APPROVED = 'approved';
	public const AUTH_DENIED   = 'denied';

	public const STATUS_PENDING   = 'pending';
	public const STATUS_SUCCEEDED = 'succeeded';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_SKIPPED   = 'skipped';

	protected function table(): string {
		return Tables::TOOL_CALLS;
	}

	/**
	 * Record a call before it executes.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 */
	public function record(
		int $agent_run_id,
		int $conversation_id,
		string $tool_id,
		array $arguments,
		string $intent = self::INTENT_READ,
		string $auth_mode = self::AUTH_AUTO
	): int {
		return $this->insert_row(
			array(
				'agent_run_id'    => $agent_run_id,
				'conversation_id' => $conversation_id,
				'tool_id'         => $tool_id,
				'intent'          => $intent,
				'auth_mode'       => $auth_mode,
				'arguments'       => $this->encode_json( $arguments ),
				'status'          => self::STATUS_PENDING,
				'created_at'      => $this->now(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Record a successful execution.
	 */
	public function succeed( int $id, mixed $result, int $duration_ms = 0 ): bool {
		return $this->update_by_id(
			$id,
			array(
				'status'      => self::STATUS_SUCCEEDED,
				'result'      => $this->encode_json( $result ),
				'duration_ms' => $duration_ms,
			),
			array( '%s', '%s', '%d' )
		);
	}

	/**
	 * Record a failure.
	 */
	public function fail( int $id, string $error, int $duration_ms = 0 ): bool {
		return $this->update_by_id(
			$id,
			array(
				'status'        => self::STATUS_FAILED,
				'error_message' => mb_substr( $error, 0, 1000 ),
				'duration_ms'   => $duration_ms,
			),
			array( '%s', '%s', '%d' )
		);
	}

	/**
	 * Writes awaiting human approval, oldest first.
	 *
	 * @return list<object>
	 */
	public function approval_queue( int $limit = 50 ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE auth_mode = %s AND status = %s ORDER BY created_at ASC LIMIT %d",
				self::AUTH_REQUIRED,
				self::STATUS_PENDING,
				$limit
			)
		);
	}

	public function pending_count(): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE auth_mode = %s AND status = %s',
				self::AUTH_REQUIRED,
				self::STATUS_PENDING
			)
		);
	}

	/**
	 * Approve a pending write.
	 *
	 * Only transitions a row that is genuinely pending approval — approving an
	 * already-executed call would be a lie in the audit trail. Returns false if
	 * the row was not in that state.
	 */
	public function approve( int $id, int $user_id ): bool {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$table} SET auth_mode = %s, approved_by = %d, approved_at = %s
				 WHERE id = %d AND auth_mode = %s AND status = %s",
				self::AUTH_APPROVED,
				$user_id,
				$this->now(),
				$id,
				self::AUTH_REQUIRED,
				self::STATUS_PENDING
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Deny a pending write.
	 */
	public function deny( int $id, int $user_id ): bool {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$table} SET auth_mode = %s, status = %s, approved_by = %d, approved_at = %s
				 WHERE id = %d AND auth_mode = %s AND status = %s",
				self::AUTH_DENIED,
				self::STATUS_SKIPPED,
				$user_id,
				$this->now(),
				$id,
				self::AUTH_REQUIRED,
				self::STATUS_PENDING
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Calls belonging to a run, in order.
	 *
	 * @return list<object>
	 */
	public function for_run( int $agent_run_id ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->get_results(
			$this->db->prepare( "SELECT * FROM {$table} WHERE agent_run_id = %d ORDER BY id ASC", $agent_run_id )
		);
	}

	public function find( int $id ): ?object {
		return $this->find_row( $id );
	}
}
