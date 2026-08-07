<?php
/**
 * Agent run persistence.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Repositories;

use StoreCrew\Database\Repository;
use StoreCrew\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Every agent turn, recorded.
 *
 * FR-AGENT-07. This is the only way to answer "why did the agent say that"
 * after the fact, and it is what the conversation inspector reads.
 *
 * @see docs/04-database-schema.md § 4.1
 */
final class AgentRunRepository extends Repository {

	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETE  = 'completed';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_BUDGET    = 'budget_exceeded';
	public const STATUS_TIMEOUT   = 'timeout';
	public const STATUS_CANCELLED = 'cancelled';

	protected function table(): string {
		return Tables::AGENT_RUNS;
	}

	/**
	 * Open a run. Returns its id.
	 */
	public function start(
		int $conversation_id,
		string $agent_id,
		string $provider = '',
		string $model = '',
		string $prompt_hash = ''
	): int {
		return $this->insert_row(
			array(
				'conversation_id' => $conversation_id,
				'agent_id'        => $agent_id,
				'provider'        => $provider,
				'model'           => $model,
				'prompt_hash'     => $prompt_hash,
				'status'          => self::STATUS_RUNNING,
				'started_at'      => $this->now(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Close a run.
	 *
	 * @param list<array{id: int, score: float}> $retrieved Chunk ids and scores.
	 */
	public function finish(
		int $id,
		string $status,
		int $message_id = 0,
		int $tokens_in = 0,
		int $tokens_out = 0,
		int $cost_micros = 0,
		int $latency_ms = 0,
		int $tool_call_count = 0,
		array $retrieved = array(),
		bool $cost_known = true
	): bool {
		return $this->update_by_id(
			$id,
			array(
				'status'          => $status,
				'message_id'      => $message_id,
				'tokens_in'       => $tokens_in,
				'tokens_out'      => $tokens_out,
				'cost_micros'     => $cost_micros,
				// An unpriced model must not display as a free one — cost that
				// is unknown reports unknown, never zero (the pricing rule).
				'cost_known'      => $cost_known ? 1 : 0,
				'latency_ms'      => $latency_ms,
				'tool_call_count' => $tool_call_count,
				// Chunk ids and scores, never chunk text — that would duplicate
				// the corpus into every run row (FR-KB-10).
				'retrieved'       => $this->encode_json( $retrieved ),
				'finished_at'     => $this->now(),
			),
			array( '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Close a run as failed.
	 */
	public function fail( int $id, string $code, string $message, int $latency_ms = 0 ): bool {
		return $this->update_by_id(
			$id,
			array(
				'status'        => self::STATUS_FAILED,
				'error_code'    => mb_substr( $code, 0, 64 ),
				'error_message' => mb_substr( $message, 0, 1000 ),
				'latency_ms'    => $latency_ms,
				'finished_at'   => $this->now(),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
	}

	public function find( int $id ): ?object {
		return $this->find_row( $id );
	}

	/**
	 * Runs belonging to a conversation, in order.
	 *
	 * @return list<object>
	 */
	public function for_conversation( int $conversation_id ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY id ASC",
				$conversation_id
			)
		);
	}

	/**
	 * Decoded retrieval trace for a run.
	 *
	 * @return array<string, mixed>|null
	 */
	public function retrieved( int $id ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$json = $this->db->get_var(
			$this->db->prepare( 'SELECT retrieved FROM ' . $this->table_name() . ' WHERE id = %d', $id )
		);

		return $this->decode_json( null === $json ? null : (string) $json );
	}

	/**
	 * Runs left marked running past a cutoff — killed mid-flight.
	 *
	 * Budget hosts terminate PHP without warning (R-TECH-03), so a run stuck in
	 * `running` is expected, not exceptional. Sweeping them keeps the dashboard
	 * honest about what is actually in progress.
	 */
	public function reap_stalled( int $older_than_seconds = 300 ): int {
		$table  = $this->table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $older_than_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$table} SET status = %s, error_code = %s, finished_at = %s
				 WHERE status = %s AND started_at < %s",
				self::STATUS_TIMEOUT,
				'stalled',
				$this->now(),
				self::STATUS_RUNNING,
				$cutoff
			)
		);

		return false === $affected ? 0 : (int) $affected;
	}

	/**
	 * Delete runs past the retention window (04 § 11), batched.
	 */
	public function prune( int $older_than_days, int $batch = 500 ): int {
		$table  = $this->table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->db->query(
			$this->db->prepare( "DELETE FROM {$table} WHERE started_at < %s LIMIT %d", $cutoff, $batch )
		);

		return false === $affected ? 0 : (int) $affected;
	}

	/**
	 * Delete runs belonging to pruned conversations, whatever their age — a
	 * run without its conversation is unexplainable, which defeats the reason
	 * runs are kept at all.
	 *
	 * @param list<int> $conversation_ids Conversations being pruned.
	 */
	public function delete_for_conversations( array $conversation_ids ): int {
		$conversation_ids = array_values( array_filter( array_map( 'intval', $conversation_ids ) ) );

		if ( array() === $conversation_ids ) {
			return 0;
		}

		$holes = implode( ', ', array_fill( 0, count( $conversation_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->db->query(
			$this->db->prepare( 'DELETE FROM ' . $this->table_name() . " WHERE conversation_id IN ({$holes})", $conversation_ids )
		);

		return false === $affected ? 0 : (int) $affected;
	}
}
