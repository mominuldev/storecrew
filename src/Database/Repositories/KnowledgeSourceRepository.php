<?php
/**
 * Knowledge source persistence.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Repositories;

use StoreCrew\Database\Repository;
use StoreCrew\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * The registry of indexed sources.
 *
 * @see docs/04-database-schema.md § 5.1
 */
final class KnowledgeSourceRepository extends Repository {

	public const STATUS_PENDING = 'pending';
	public const STATUS_INDEXED = 'indexed';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_STALE   = 'stale';

	protected function table(): string {
		return Tables::KNOWLEDGE_SOURCES;
	}

	/**
	 * Stable identity hash for a source.
	 *
	 * Replaces a composite unique on (source_type, object_id, external_ref):
	 * dbDelta handles prefixed index columns unreliably, and the composite
	 * risked InnoDB's key-length ceiling under utf8mb4.
	 */
	public static function key( string $source_type, int $object_id, string $external_ref = '' ): string {
		return hash( 'sha256', $source_type . '|' . $object_id . '|' . $external_ref );
	}

	/**
	 * Register or refresh a source.
	 *
	 * Returns the row id and whether the content actually changed. **An
	 * unchanged content_hash short-circuits re-embedding**, which is the single
	 * most important cost control in the pipeline: a merchant bulk-editing
	 * stock levels would otherwise trigger a full re-embed and a provider bill
	 * for content that did not move.
	 *
	 * @return array{id: int, changed: bool}
	 */
	public function upsert(
		string $source_type,
		int $object_id,
		string $content_hash,
		string $title = '',
		string $url = '',
		string $external_ref = ''
	): array {
		$key      = self::key( $source_type, $object_id, $external_ref );
		$existing = $this->find_by_key( $key );
		$now      = $this->now();

		if ( null !== $existing ) {
			$changed = (string) $existing->content_hash !== $content_hash;

			$this->update_by_id(
				(int) $existing->id,
				array(
					'title'        => $title,
					'url'          => $url,
					'content_hash' => $content_hash,
					'status'       => $changed ? self::STATUS_STALE : $existing->status,
					'updated_at'   => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);

			return array(
				'id'      => (int) $existing->id,
				'changed' => $changed,
			);
		}

		$id = $this->insert_row(
			array(
				'source_key'   => $key,
				'source_type'  => $source_type,
				'object_id'    => $object_id,
				'external_ref' => $external_ref,
				'title'        => $title,
				'url'          => $url,
				'content_hash' => $content_hash,
				'status'       => self::STATUS_PENDING,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return array(
			'id'      => $id,
			'changed' => true,
		);
	}

	public function find_by_key( string $key ): ?object {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE source_key = %s', $key )
		);

		return $row ?: null;
	}

	public function find( int $id ): ?object {
		return $this->find_row( $id );
	}

	public function mark_indexed( int $id, int $chunk_count ): bool {
		return $this->update_by_id(
			$id,
			array(
				'status'        => self::STATUS_INDEXED,
				'chunk_count'   => $chunk_count,
				'error_message' => null,
				'indexed_at'    => $this->now(),
				'updated_at'    => $this->now(),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);
	}

	public function mark_failed( int $id, string $error ): bool {
		return $this->update_by_id(
			$id,
			array(
				'status'        => self::STATUS_FAILED,
				'error_message' => mb_substr( $error, 0, 1000 ),
				'updated_at'    => $this->now(),
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Sources awaiting indexing, oldest first.
	 *
	 * @return list<object>
	 */
	public function needing_index( int $limit = 50 ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE status IN (%s, %s) ORDER BY updated_at ASC LIMIT %d",
				self::STATUS_PENDING,
				self::STATUS_STALE,
				$limit
			)
		);
	}

	/**
	 * Counts by status, for the index-health panel.
	 *
	 * @return array<string, int>
	 */
	public function status_counts(): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" );

		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ (string) $row->status ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Remove a source by its identity tuple. Used when a product is deleted.
	 */
	public function forget( string $source_type, int $object_id, string $external_ref = '' ): bool {
		return false !== $this->db->delete(
			$this->table_name(),
			array( 'source_key' => self::key( $source_type, $object_id, $external_ref ) ),
			array( '%s' )
		);
	}
}
