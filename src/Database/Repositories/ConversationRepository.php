<?php
/**
 * Conversation persistence.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Repositories;

use StoreCrew\Database\Repository;
use StoreCrew\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Conversations, and the identity verification that guards order data.
 *
 * @see docs/04-database-schema.md § 3.1
 */
final class ConversationRepository extends Repository {

	public const STATUS_OPEN      = 'open';
	public const STATUS_CLOSED    = 'closed';
	public const STATUS_ESCALATED = 'escalated';
	public const STATUS_ABANDONED = 'abandoned';

	protected function table(): string {
		return Tables::CONVERSATIONS;
	}

	/**
	 * Open a conversation. Returns its public uuid.
	 */
	public function start( string $session_token, int $customer_id = 0, string $channel = 'widget', string $locale = '' ): ?string {
		$uuid = wp_generate_uuid4();
		$now  = $this->now();

		$id = $this->insert_row(
			array(
				'uuid'             => $uuid,
				'session_token'    => $session_token,
				'customer_id'      => $customer_id,
				'status'           => self::STATUS_OPEN,
				'channel'          => $channel,
				'locale'           => $locale,
				'started_at'       => $now,
				'last_activity_at' => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $id > 0 ? $uuid : null;
	}

	/**
	 * Find by public uuid. Internal ids are never exposed, so this is the
	 * lookup every REST route uses.
	 */
	public function find_by_uuid( string $uuid ): ?object {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE uuid = %s', $uuid )
		);

		return $row ?: null;
	}

	public function find( int $id ): ?object {
		return $this->find_row( $id );
	}

	/**
	 * Record activity and bump the message counter.
	 */
	public function touch( int $id, bool $counts_as_message = true ): bool {
		$table = $this->table_name();
		$now   = $this->now();

		$sql = $counts_as_message
			? "UPDATE {$table} SET last_activity_at = %s, message_count = message_count + 1 WHERE id = %d"
			: "UPDATE {$table} SET last_activity_at = %s WHERE id = %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return false !== $this->db->query( $this->db->prepare( $sql, $now, $id ) );
	}

	/**
	 * Increment the agent run counter.
	 */
	public function record_run( int $id ): bool {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return false !== $this->db->query(
			$this->db->prepare( "UPDATE {$table} SET run_count = run_count + 1 WHERE id = %d", $id )
		);
	}

	/**
	 * Mark identity as proven for this conversation.
	 *
	 * FR-SUPPORT-01. Called only by the verification tool, after either a
	 * logged-in session or an order number matched against its billing email.
	 * No email is stored — only which order was proven.
	 */
	public function mark_verified( int $id, int $order_id, int $customer_id = 0 ): bool {
		$data    = array(
			'identity_verified' => 1,
			'verified_order_id' => $order_id,
			'verified_at'       => $this->now(),
		);
		$formats = array( '%d', '%d', '%s' );

		if ( $customer_id > 0 ) {
			$data['customer_id'] = $customer_id;
			$formats[]           = '%d';
		}

		return $this->update_by_id( $id, $data, $formats );
	}

	/**
	 * Attach a customer to the conversation, revoking verification if the
	 * identity changed.
	 *
	 * This is the shared-device case: a visitor logs out and another logs in on
	 * the same session. Carrying the previous verification forward would leak
	 * the first customer's orders to the second, so any change in identity
	 * resets it to unproven.
	 */
	public function assign_customer( int $id, int $customer_id ): bool {
		$existing = $this->find_row( $id );

		if ( null === $existing ) {
			return false;
		}

		$changed = (int) $existing->customer_id !== $customer_id;

		$data    = array( 'customer_id' => $customer_id );
		$formats = array( '%d' );

		if ( $changed ) {
			$data['identity_verified'] = 0;
			$data['verified_order_id'] = 0;
			$data['verified_at']       = null;
			$formats                   = array( '%d', '%d', '%d', '%s' );
		}

		return $this->update_by_id( $id, $data, $formats );
	}

	/**
	 * Whether this conversation may read order or customer data.
	 */
	public function is_verified( int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return '1' === (string) $this->db->get_var(
			$this->db->prepare(
				'SELECT identity_verified FROM ' . $this->table_name() . ' WHERE id = %d',
				$id
			)
		);
	}

	/**
	 * Close a conversation.
	 */
	public function close( int $id, string $status = self::STATUS_CLOSED ): bool {
		$data = array(
			'status'    => $status,
			'closed_at' => $this->now(),
		);

		if ( self::STATUS_ESCALATED === $status ) {
			$data['escalated_at'] = $this->now();

			return $this->update_by_id( $id, $data, array( '%s', '%s', '%s' ) );
		}

		return $this->update_by_id( $id, $data, array( '%s', '%s' ) );
	}

	/**
	 * Recent conversations, newest first.
	 *
	 * @return list<object>
	 */
	public function recent( int $limit = 25, int $offset = 0, string $status = '' ): array {
		$table = $this->table_name();

		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY last_activity_at DESC LIMIT %d OFFSET %d",
					$status,
					$limit,
					$offset
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table} ORDER BY last_activity_at DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	/**
	 * Mark long-idle open conversations as abandoned.
	 *
	 * Returns the number affected.
	 */
	public function abandon_stale( int $idle_minutes = 60 ): int {
		$table  = $this->table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $idle_minutes * 60 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$table} SET status = %s, closed_at = %s WHERE status = %s AND last_activity_at < %s",
				self::STATUS_ABANDONED,
				$this->now(),
				self::STATUS_OPEN,
				$cutoff
			)
		);

		return false === $affected ? 0 : (int) $affected;
	}
}
