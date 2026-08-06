<?php
/**
 * Message persistence.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Repositories;

use StoreCrew\Database\Repository;
use StoreCrew\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Conversation turns.
 *
 * @see docs/04-database-schema.md § 3.2
 */
final class MessageRepository extends Repository {

	public const ROLE_USER      = 'user';
	public const ROLE_ASSISTANT = 'assistant';
	public const ROLE_SYSTEM    = 'system';
	public const ROLE_TOOL      = 'tool';
	public const ROLE_HANDOFF   = 'handoff';

	protected function table(): string {
		return Tables::MESSAGES;
	}

	/**
	 * Append a turn.
	 */
	public function append(
		int $conversation_id,
		string $role,
		string $content,
		string $agent_id = '',
		int $tokens_in = 0,
		int $tokens_out = 0,
		string $format = 'markdown'
	): int {
		return $this->insert_row(
			array(
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'agent_id'        => $agent_id,
				'content'         => $content,
				'content_format'  => $format,
				'tokens_in'       => $tokens_in,
				'tokens_out'      => $tokens_out,
				'created_at'      => $this->now(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Full transcript in order.
	 *
	 * Ordered by id, not created_at: id is monotonic and unique, so two
	 * messages landing in the same second still order correctly.
	 *
	 * @return list<object>
	 */
	public function for_conversation( int $conversation_id, int $limit = 200 ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY id ASC LIMIT %d",
				$conversation_id,
				$limit
			)
		);
	}

	/**
	 * The most recent turns, oldest-first, for building a prompt window.
	 *
	 * @return list<object>
	 */
	public function recent_for_conversation( int $conversation_id, int $limit = 20 ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY id DESC LIMIT %d",
				$conversation_id,
				$limit
			)
		);

		return array_reverse( $rows );
	}

	/**
	 * Delete every message for a conversation. Used by retention pruning and
	 * GDPR erasure.
	 */
	public function delete_for_conversation( int $conversation_id ): int {
		$deleted = $this->db->delete(
			$this->table_name(),
			array( 'conversation_id' => $conversation_id ),
			array( '%d' )
		);

		return false === $deleted ? 0 : (int) $deleted;
	}
}
