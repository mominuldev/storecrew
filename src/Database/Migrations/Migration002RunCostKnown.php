<?php
/**
 * Record whether a run's cost figure is trustworthy.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Migrations;

use StoreCrew\Database\MigrationInterface;
use StoreCrew\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Adds `cost_known` to agent runs.
 *
 * `Pricing::estimate()` reports unknown as `{micros: 0, known: false}`, and
 * the runner has always carried the flag — but without a column it died at
 * the row boundary, so the inspector showed an unpriced model as a *free*
 * one. That is exactly the fabricated-zero the pricing rule exists to
 * prevent: a merchant reading costs that silently under-count.
 *
 * Existing rows default to 1 (known): they were written when only priced
 * models were in use, and flagging every historical run as suspect would
 * manufacture doubt the data does not support. Every new row sets the flag
 * explicitly.
 *
 * A plain ALTER rather than dbDelta: dbDelta wants the full CREATE statement
 * and diffing one column through it invites its silent-failure modes for no
 * benefit. The existence check makes the migration idempotent, which the
 * forward-only contract requires of a re-run after a mid-series failure.
 */
final class Migration002RunCostKnown implements MigrationInterface {

	public function version(): int {
		return 2;
	}

	public function description(): string {
		return 'Record whether a run cost is known';
	}

	public function up(): void {
		global $wpdb;

		$table = Tables::name( Tables::AGENT_RUNS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier from Tables::name(), not user input; identifiers cannot be prepared.
		$exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'cost_known'" );

		if ( array() === $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL on a Tables::name() identifier.
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN cost_known tinyint(1) unsigned NOT NULL DEFAULT 1 AFTER cost_micros" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier from Tables::name(), not user input; identifiers cannot be prepared.
		$exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'cost_known'" );

		if ( array() === $exists ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'Column cost_known was not added to %s.', $table ) )
			);
		}
	}
}
