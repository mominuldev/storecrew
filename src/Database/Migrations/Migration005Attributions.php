<?php
/**
 * The conversation-to-order link FR-ANALYTICS-03 rests on.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Migrations;

use StoreCrew\Database\MigrationInterface;
use StoreCrew\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Creates `scr_attributions`.
 *
 * There has never been a link between a conversation and an order.
 * `conversations.verified_order_id` looks like one and is not: it records that
 * a customer *proved who they were* against an order they already had, which
 * is identity verification pointing backwards. Attribution points forwards —
 * this shopper talked to the crew and then bought — and nothing recorded it,
 * so the honest answer to "what did StoreCrew earn me" was silence.
 *
 * Three properties of this table are deliberate:
 *
 * - **It holds no money.** There is no revenue column, no currency, no
 *   captured total. The row records the *link*; the amount is read live from
 *   the order at report time, which is the same discipline FR-KB-08 applies to
 *   price and stock. A stored total would keep counting a refunded order
 *   forever, and a merchant reading revenue that WooCommerce no longer agrees
 *   with cannot tell which number is wrong.
 * - **`order_id` is unique.** One order is attributed to at most one
 *   conversation, so the model is last-touch by construction rather than by
 *   convention, and the checkout hooks — which can fire more than once for one
 *   order, and fire twice over on a store running both classic and Blocks
 *   checkout — are idempotent for free.
 * - **`basis` is stored per row**, so the methodology is auditable against the
 *   data rather than only described in prose. A store whose links are all
 *   `customer` has cookie trouble, and that is worth being able to see.
 *
 * Attribution history is bounded by conversation retention (04 § 11): these
 * rows are pruned with the conversation they explain, because a link to a
 * conversation nobody can read is a figure nobody can check.
 *
 * @see docs/04-database-schema.md § 3.3
 */
final class Migration005Attributions implements MigrationInterface {

	public function version(): int {
		return 5;
	}

	public function description(): string {
		return 'Record which conversations preceded which orders';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		// dbDelta's formatting rules apply exactly as they do in Migration001:
		// one field per line, two spaces after PRIMARY KEY, KEY not INDEX,
		// every key named, lowercase types.
		$sql = '
			CREATE TABLE ' . Tables::name( Tables::ATTRIBUTIONS ) . " (
				id bigint(20) unsigned NOT NULL auto_increment,
				order_id bigint(20) unsigned NOT NULL,
				conversation_id bigint(20) unsigned NOT NULL,
				basis varchar(20) NOT NULL default '',
				agent_id varchar(64) NOT NULL default '',
				minutes_elapsed int(10) unsigned NOT NULL default 0,
				recorded_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY order_id (order_id),
				KEY conversation_id (conversation_id),
				KEY recorded_at (recorded_at)
			) {$collate};";

		dbDelta( $sql );

		if ( ! Tables::exists( Tables::ATTRIBUTIONS ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'Table %s was not created.', Tables::name( Tables::ATTRIBUTIONS ) ) )
			);
		}
	}
}
