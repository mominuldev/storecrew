<?php
/**
 * Uninstall routine.
 *
 * @package StoreCrew
 */

/**
 * IMPORTANT — This file must stay parseable by PHP 5.6.
 *
 * WordPress loads uninstall.php in isolation, with no guarantee that the
 * plugin's own bootstrap or its PHP version guard ever ran.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Destruction is opt-in.
 *
 * A merchant removing the plugin to troubleshoot a conflict should not lose a
 * year of conversation history to do it. Data is kept unless they explicitly
 * asked for it to be removed (FR-CORE-08).
 */
if ( '1' !== get_option( 'storecrew_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

$storecrew_tables = array(
	'conversations',
	'messages',
	'agent_runs',
	'tool_calls',
	'knowledge_sources',
	'knowledge_chunks',
	'usage_events',
	'usage_counters',
	'index_runs',
	'audit_log',
	'agent_configs',
);

// Only tables this plugin created. Premium owns its own and removes them in its
// own uninstall (FR-DIST-06).
foreach ( $storecrew_tables as $storecrew_table ) {
	$storecrew_name = $wpdb->prefix . 'scr_' . $storecrew_table;

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $storecrew_name );
}

$storecrew_options = array(
	'storecrew_version',
	'storecrew_activated_at',
	'storecrew_needs_upgrade',
	'storecrew_setup_redirect',
	'storecrew_schema_version',
	'storecrew_migration_lock',
	'storecrew_migration_log',
	'storecrew_delete_data_on_uninstall',
);

foreach ( $storecrew_options as $storecrew_option ) {
	delete_option( $storecrew_option );
}

// Capabilities are revoked here rather than on deactivation, so a merchant
// toggling the plugin off does not have to rebuild their role configuration.
if ( function_exists( 'wp_roles' ) ) {
	$storecrew_caps = array(
		'storecrew_manage',
		'storecrew_view_analytics',
		'storecrew_manage_agents',
		'storecrew_converse',
	);

	$storecrew_roles = wp_roles();

	foreach ( array_keys( $storecrew_roles->roles ) as $storecrew_role_name ) {
		$storecrew_role = get_role( $storecrew_role_name );

		if ( null === $storecrew_role ) {
			continue;
		}

		foreach ( $storecrew_caps as $storecrew_cap ) {
			$storecrew_role->remove_cap( $storecrew_cap );
		}
	}
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'storecrew' );
}
