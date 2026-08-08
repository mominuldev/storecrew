<?php
/**
 * Table name registry.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database;

defined( 'ABSPATH' ) || exit;

/**
 * The single place that knows how a StoreCrew table is named.
 *
 * Every query goes through here rather than interpolating a prefix inline. That
 * is what makes the `storecrew.noGlobalWpdb` rule checkable: a raw
 * "{$wpdb->prefix}scr_" anywhere else in the codebase is a defect by definition.
 *
 * @see docs/04-database-schema.md § 1
 */
final class Tables {

	public const PREFIX = 'scr_';

	public const CONVERSATIONS     = 'conversations';
	public const MESSAGES          = 'messages';
	public const AGENT_RUNS        = 'agent_runs';
	public const TOOL_CALLS        = 'tool_calls';
	public const KNOWLEDGE_SOURCES = 'knowledge_sources';
	public const KNOWLEDGE_CHUNKS  = 'knowledge_chunks';
	public const USAGE_EVENTS      = 'usage_events';
	public const USAGE_COUNTERS    = 'usage_counters';
	public const INDEX_RUNS        = 'index_runs';
	public const AUDIT_LOG         = 'audit_log';
	public const AGENT_CONFIGS     = 'agent_configs';
	public const ATTRIBUTIONS      = 'attributions';

	/**
	 * Fully qualified table name, including the site's table prefix.
	 */
	public static function name( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . self::PREFIX . $table;
	}

	/**
	 * Every table this plugin owns, unqualified.
	 *
	 * Premium tables are deliberately absent: each plugin drops only what it
	 * created (FR-DIST-06).
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::CONVERSATIONS,
			self::MESSAGES,
			self::AGENT_RUNS,
			self::TOOL_CALLS,
			self::KNOWLEDGE_SOURCES,
			self::KNOWLEDGE_CHUNKS,
			self::USAGE_EVENTS,
			self::USAGE_COUNTERS,
			self::INDEX_RUNS,
			self::AUDIT_LOG,
			self::AGENT_CONFIGS,
			self::ATTRIBUTIONS,
		);
	}

	/**
	 * Every table this plugin owns, fully qualified.
	 *
	 * @return list<string>
	 */
	public static function all_qualified(): array {
		return array_map( array( self::class, 'name' ), self::all() );
	}

	/**
	 * Whether a table exists in the database.
	 */
	public static function exists( string $table ): bool {
		global $wpdb;

		$qualified = self::name( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $qualified ) )
		);

		return $qualified === $found;
	}
}
