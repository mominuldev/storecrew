<?php
/**
 * Audit trail.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Repositories;

use StoreCrew\Database\Repository;
use StoreCrew\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only record of consequential actions.
 *
 * Every agent write, every approval, every licence transition, every settings
 * change. There is no update method by design — an audit log that can be edited
 * is not an audit log.
 *
 * @see docs/04-database-schema.md § 8.2
 */
final class AuditLogRepository extends Repository {

	public const ACTOR_USER   = 'user';
	public const ACTOR_AGENT  = 'agent';
	public const ACTOR_SYSTEM = 'system';

	protected function table(): string {
		return Tables::AUDIT_LOG;
	}

	/**
	 * Record an action.
	 *
	 * @param array<string, mixed> $data Contextual detail.
	 */
	public function record(
		string $action,
		string $actor_type = self::ACTOR_SYSTEM,
		string $actor_id = '',
		string $object_type = '',
		int $object_id = 0,
		array $data = array(),
		string $ip = ''
	): int {
		return $this->insert_row(
			array(
				'actor_type'  => $actor_type,
				'actor_id'    => $actor_id,
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'ip_hash'     => '' !== $ip ? self::hash_ip( $ip ) : '',
				'data'        => $this->encode_json( $data ),
				'created_at'  => $this->now(),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Salted hash of an IP address.
	 *
	 * Rate limiting and abuse detection need to recognise a repeat visitor, not
	 * identify one. Hashing with the site's own salt does that while keeping
	 * the column out of GDPR scope as personal data — and means a leaked table
	 * cannot be reversed into a visitor list.
	 */
	public static function hash_ip( string $ip ): string {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'storecrew';

		return hash( 'sha256', $salt . '|' . $ip );
	}

	/**
	 * Recent entries, newest first.
	 *
	 * @return list<object>
	 */
	public function recent( int $limit = 50, string $action = '' ): array {
		$table = $this->table_name();

		if ( '' !== $action ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$table} WHERE action = %s ORDER BY id DESC LIMIT %d",
					$action,
					$limit
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->get_results(
			$this->db->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit )
		);
	}

	/**
	 * Entries about one object.
	 *
	 * @return list<object>
	 */
	public function for_object( string $object_type, int $object_id, int $limit = 50 ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT %d",
				$object_type,
				$object_id,
				$limit
			)
		);
	}

	/**
	 * Prune entries older than a retention window.
	 *
	 * Batched rather than a single mass DELETE, which would lock the table and
	 * time out on a busy store.
	 */
	public function prune( int $older_than_days, int $batch = 500 ): int {
		$table  = $this->table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->db->query(
			$this->db->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT %d", $cutoff, $batch )
		);

		return false === $affected ? 0 : (int) $affected;
	}
}
