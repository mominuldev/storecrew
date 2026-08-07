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

	/**
	 * Statuses a customer may still talk into.
	 *
	 * Escalated belongs here: a conversation waiting for a human is not over,
	 * and telling the customer to start again — losing everything they already
	 * explained — is the opposite of what escalation is for.
	 */
	public const LIVE_STATUSES = array( self::STATUS_OPEN, self::STATUS_ESCALATED );

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
	 * The open conversation belonging to a session, if there is one.
	 *
	 * The token stored here is a **hash** of the one the visitor holds, so a
	 * dump of this table hands an attacker nothing they can present. Callers
	 * pass the hash; the raw token never reaches a repository.
	 */
	public function find_open_for_session( string $session_token_hash ): ?object {
		if ( '' === $session_token_hash ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table_name()
					. ' WHERE session_token = %s AND status IN (' . $this->live_placeholders() . ') ORDER BY id DESC LIMIT 1',
				array_merge( array( $session_token_hash ), self::LIVE_STATUSES )
			)
		);

		return $row ?: null;
	}

	/**
	 * The most recent open conversation for a signed-in customer.
	 *
	 * FR-CHAT-05 requires a conversation to survive across sessions for an
	 * identified customer — a new browser, or a cleared cookie, must not lose
	 * the thread. Anonymous visitors have no such handle, which is why this is
	 * keyed on the WordPress user id rather than on the session token.
	 */
	public function find_open_for_customer( int $customer_id ): ?object {
		if ( $customer_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table_name()
					. ' WHERE customer_id = %d AND status IN (' . $this->live_placeholders() . ') ORDER BY id DESC LIMIT 1',
				array_merge( array( $customer_id ), self::LIVE_STATUSES )
			)
		);

		return $row ?: null;
	}

	/**
	 * Placeholder list for LIVE_STATUSES, so the constant stays the single
	 * definition of what "still live" means.
	 */
	private function live_placeholders(): string {
		return implode( ', ', array_fill( 0, count( self::LIVE_STATUSES ), '%s' ) );
	}

	/**
	 * Re-point a conversation at the session now holding it.
	 *
	 * A customer resuming on a second device presents a different token for the
	 * same conversation. Rotating rather than accepting both is what keeps the
	 * token a single-holder credential.
	 */
	public function rebind_session( int $id, string $session_token_hash ): bool {
		return $this->update_by_id( $id, array( 'session_token' => $session_token_hash ), array( '%s' ) );
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
	 * Flag a conversation for a human without ending it (FR-SUPPORT-07).
	 *
	 * Distinct from `close( STATUS_ESCALATED )`, which stamps `closed_at` and
	 * takes the conversation out of the customer's hands. Escalation is a
	 * request for help *during* a conversation — the customer keeps typing, and
	 * a merchant who opens it finds the whole thread rather than a transcript
	 * that stops at the moment it got difficult.
	 *
	 * Already-escalated conversations are left alone so the first escalation
	 * timestamp survives; that is the one the response-time figure is measured
	 * against.
	 */
	public function escalate( int $id ): bool {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return false !== $this->db->query(
			$this->db->prepare(
				"UPDATE {$table} SET status = %s, escalated_at = %s WHERE id = %d AND status = %s",
				self::STATUS_ESCALATED,
				$this->now(),
				$id,
				self::STATUS_OPEN
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
