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

	/**
	 * The storefront widget — a customer talking to the store.
	 */
	public const CHANNEL_WIDGET = 'widget';

	/**
	 * The merchant console in wp-admin — the store talking to its own agents.
	 *
	 * Kept apart from the widget everywhere a conversation is *found*, not
	 * merely labelled. A shop manager is both a merchant and, on the same
	 * WordPress user id, a potential customer: without the channel in the
	 * lookups, opening the widget while signed in would resume their own
	 * console thread and show campaign planning in the storefront chat.
	 */
	public const CHANNEL_CONSOLE = 'console';

	protected function table(): string {
		return Tables::CONVERSATIONS;
	}

	/**
	 * Open a conversation. Returns its public uuid.
	 */
	public function start( string $session_token, int $customer_id = 0, string $channel = self::CHANNEL_WIDGET, string $locale = '' ): ?string {
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
	 *
	 * Scoped to one channel, defaulting to the widget: resuming is a
	 * per-surface question, and a lookup that could cross channels is how a
	 * console thread ends up in the storefront.
	 */
	public function find_open_for_session( string $session_token_hash, string $channel = self::CHANNEL_WIDGET ): ?object {
		if ( '' === $session_token_hash ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table_name()
					. ' WHERE session_token = %s AND channel = %s AND status IN (' . $this->live_placeholders() . ') ORDER BY id DESC LIMIT 1',
				array_merge( array( $session_token_hash, $channel ), self::LIVE_STATUSES )
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
	 *
	 * The channel is part of the key for the same reason, and here it is load
	 * bearing rather than tidy: a shop manager holds one WordPress user id
	 * across both surfaces, so an unscoped lookup would hand the storefront
	 * widget the merchant's own console conversation.
	 */
	public function find_open_for_customer( int $customer_id, string $channel = self::CHANNEL_WIDGET ): ?object {
		if ( $customer_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table_name()
					. ' WHERE customer_id = %d AND channel = %s AND status IN (' . $this->live_placeholders() . ') ORDER BY id DESC LIMIT 1',
				array_merge( array( $customer_id, $channel ), self::LIVE_STATUSES )
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
		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$table} SET status = %s, escalated_at = %s WHERE id = %d AND status = %s",
				self::STATUS_ESCALATED,
				$this->now(),
				$id,
				self::STATUS_OPEN
			)
		);

		// True only when a row actually transitioned. An already-escalated
		// conversation returns false, which is what lets the notifier email
		// once per escalation rather than once per failed turn.
		return false !== $affected && (int) $affected > 0;
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
	 * Defaults to the widget channel, because the inbox is a list of *customer*
	 * conversations: the merchant's own console threads are their side of the
	 * desk, and mixing them in would have the merchant reading their own
	 * questions as if a shopper had asked them. Pass an empty channel for
	 * every conversation regardless of surface.
	 *
	 * @return list<object>
	 */
	public function recent( int $limit = 25, int $offset = 0, string $status = '', string $channel = self::CHANNEL_WIDGET ): array {
		$table  = $this->table_name();
		$where  = array();
		$params = array();

		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( '' !== $channel ) {
			$where[]  = 'channel = %s';
			$params[] = $channel;
		}

		$clause = array() === $where ? '' : ' WHERE ' . implode( ' AND ', $where );

		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table}{$clause} ORDER BY last_activity_at DESC LIMIT %d OFFSET %d",
				$params
			)
		);
	}

	/**
	 * Conversation ids whose last activity predates the cutoff.
	 *
	 * @return list<int>
	 */
	public function ids_older_than( int $days, int $limit = 200 ): array {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $this->db->get_col(
			$this->db->prepare(
				'SELECT id FROM ' . $this->table_name() . ' WHERE last_activity_at < %s ORDER BY id ASC LIMIT %d',
				$cutoff,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Delete a set of conversations. Rows only — the caller owns the cascade,
	 * because messages, runs, and tool calls live in other repositories and a
	 * delete that silently reached into their tables would break the
	 * one-repository-per-table rule the whole data layer stands on.
	 *
	 * @param list<int> $ids Conversation ids.
	 */
	public function delete_ids( array $ids ): int {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( array() === $ids ) {
			return 0;
		}

		$holes = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->db->query(
			$this->db->prepare( 'DELETE FROM ' . $this->table_name() . " WHERE id IN ({$holes})", $ids )
		);

		return false === $affected ? 0 : (int) $affected;
	}

	/**
	 * Conversations belonging to a customer, oldest first. The privacy
	 * exporter's paging source.
	 *
	 * @return list<object>
	 */
	public function for_customer( int $customer_id, int $limit = 50, int $offset = 0 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table_name() . ' WHERE customer_id = %d ORDER BY id ASC LIMIT %d OFFSET %d',
				$customer_id,
				$limit,
				$offset
			)
		);
	}

	/**
	 * Strip a customer's identity from their conversations (GDPR erasure).
	 *
	 * The rows survive — counts and analytics are not personal data — but
	 * nothing ties them to a person afterwards: no customer id, no proven
	 * order, and no session token, so a cookie still on some device cannot
	 * resume a conversation its owner asked to be forgotten.
	 */
	public function anonymise_customer( int $customer_id ): int {
		if ( $customer_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->db->query(
			$this->db->prepare(
				'UPDATE ' . $this->table_name()
					. " SET customer_id = 0, identity_verified = 0, verified_order_id = 0, verified_at = NULL, session_token = ''"
					. ' WHERE customer_id = %d',
				$customer_id
			)
		);

		return false === $affected ? 0 : (int) $affected;
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
