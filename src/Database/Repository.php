<?php
/**
 * Repository base.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Base for every repository.
 *
 * All database access in this plugin goes through a subclass of this. Nothing
 * else touches $wpdb — that is what the `storecrew.noGlobalWpdb` static-analysis
 * rule checks, and it is what keeps the vector storage format swappable if the
 * measurement in FR-KB-09 forces an external vector store.
 *
 * $wpdb is injected rather than reached for, so repositories are testable
 * against a double.
 *
 * @see docs/04-database-schema.md § 1
 */
abstract class Repository {

	/**
	 * Payload columns are capped before write. A tool returning a 5 MB result is
	 * a bug, and the log should not amplify it into a disk problem.
	 */
	protected const MAX_JSON_BYTES = 65535;

	protected wpdb $db;

	public function __construct( ?wpdb $db = null ) {
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}

		$this->db = $db;
	}

	/**
	 * Unqualified table name, from Tables::*.
	 */
	abstract protected function table(): string;

	/**
	 * Table name including the site prefix.
	 */
	protected function table_name(): string {
		return Tables::name( $this->table() );
	}

	/**
	 * Current UTC timestamp in MySQL DATETIME format.
	 */
	protected function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Insert a row and return its id, or 0 on failure.
	 *
	 * @param array<string, mixed> $data    Column => value.
	 * @param array<int, string>   $formats Matching wpdb format specifiers.
	 */
	protected function insert_row( array $data, array $formats ): int {
		$ok = $this->db->insert( $this->table_name(), $data, $formats );

		return false === $ok ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Update a row by primary key.
	 *
	 * @param array<string, mixed> $data    Column => value.
	 * @param array<int, string>   $formats Matching wpdb format specifiers.
	 */
	protected function update_by_id( int $id, array $data, array $formats ): bool {
		return false !== $this->db->update(
			$this->table_name(),
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Fetch one row by primary key.
	 */
	protected function find_row( int $id ): ?object {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE id = %d', $id )
		);

		return $row ?: null;
	}

	/**
	 * Delete a row by primary key.
	 */
	public function delete( int $id ): bool {
		return false !== $this->db->delete( $this->table_name(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Encode a value as JSON for a LONGTEXT column, truncating if oversized.
	 *
	 * Truncation replaces the payload with a marker rather than storing invalid
	 * JSON — a half-written object that fails to decode later is worse than an
	 * honest "this was too big".
	 */
	protected function encode_json( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$json = wp_json_encode( $value );

		if ( false === $json ) {
			return wp_json_encode( array( '_error' => 'not encodable' ) );
		}

		if ( strlen( $json ) > static::MAX_JSON_BYTES ) {
			return wp_json_encode(
				array(
					'_truncated' => true,
					'_bytes'     => strlen( $json ),
				)
			);
		}

		return $json;
	}

	/**
	 * Decode a JSON column.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function decode_json( ?string $json ): ?array {
		if ( null === $json || '' === $json ) {
			return null;
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
