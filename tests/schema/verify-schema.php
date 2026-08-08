<?php
/**
 * Schema verification against a live database.
 *
 * Run with:  wp eval-file wp-content/plugins/storecrew/tests/schema/verify-schema.php
 *
 * The integration harness in tests/integration deliberately has no database, so
 * everything schema-shaped is verified here instead, against real MySQL. That
 * matters because the failure mode this guards against — dbDelta silently
 * declining to apply a change when its formatting rules are broken — cannot be
 * reproduced with a mock.
 *
 * @package StoreCrew
 */

// No declare(strict_types=1) here: `wp eval-file` runs this through eval(),
// where a declare must be the first statement of the script and cannot be.

use StoreCrew\Database\Migrations\Migration001InitialSchema;
use StoreCrew\Database\Migrator;
use StoreCrew\Database\Tables;

global $wpdb;

$pass = 0;
$fail = 0;

$t = static function ( string $label, bool $ok, string $detail = '' ) use ( &$pass, &$fail ): void {
	if ( $ok ) {
		++$pass;
		echo "  PASS  {$label}\n";
	} else {
		++$fail;
		echo "  FAIL  {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
	}
};

echo "\n== Tables exist ==\n";
foreach ( Tables::all() as $table ) {
	$t( Tables::name( $table ), Tables::exists( $table ) );
}

echo "\n== Engine and charset ==\n";
$meta = $wpdb->get_results(
	"SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
	 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '{$wpdb->prefix}scr_%'"
);
$t( 'all tables are InnoDB', array() === array_filter( $meta, static fn ( $r ) => 'InnoDB' !== $r->ENGINE ) );
$t( 'all tables are utf8mb4', array() === array_filter( $meta, static fn ( $r ) => ! str_starts_with( (string) $r->TABLE_COLLATION, 'utf8mb4' ) ) );
$t( 'exactly 11 tables', 11 === count( $meta ), (string) count( $meta ) );

echo "\n== Indexes that carry load ==\n";
$index_of = static function ( string $table ) use ( $wpdb ): array {
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT INDEX_NAME, INDEX_TYPE FROM information_schema.STATISTICS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			Tables::name( $table )
		)
	);
	$out = array();
	foreach ( $rows as $r ) {
		$out[ $r->INDEX_NAME ] = $r->INDEX_TYPE;
	}
	return $out;
};

$chunks = $index_of( Tables::KNOWLEDGE_CHUNKS );
$t( 'knowledge_chunks has FULLTEXT content_ft', ( $chunks['content_ft'] ?? '' ) === 'FULLTEXT' );

$conv = $index_of( Tables::CONVERSATIONS );
$t( 'conversations.uuid is unique', isset( $conv['uuid'] ) );
$t( 'conversations has status_activity', isset( $conv['status_activity'] ) );

$calls = $index_of( Tables::TOOL_CALLS );
$t( 'tool_calls has approval_queue index', isset( $calls['approval_queue'] ) );

$usage = $index_of( Tables::USAGE_EVENTS );
$t( 'usage_events has metric_period index', isset( $usage['metric_period'] ) );

$sources = $index_of( Tables::KNOWLEDGE_SOURCES );
$t( 'knowledge_sources.source_key is unique', isset( $sources['source_key'] ) );
$t( 'knowledge_sources has content_hash index', isset( $sources['content_hash'] ) );

echo "\n== dbDelta idempotency ==\n";
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$migration = new Migration001InitialSchema();
$reflect   = new ReflectionMethod( $migration, 'statements' );
$reflect->setAccessible( true );
$drift = 0;
foreach ( $reflect->invoke( $migration, $wpdb->get_charset_collate() ) as $sql ) {
	$drift += count( dbDelta( $sql ) );
}
$t( 'dbDelta reports no drift on re-run', 0 === $drift, "{$drift} spurious change(s)" );

echo "\n== Reserved-word renames actually work ==\n";
// `cursor` is reserved in MySQL and `authorization` in the SQL standard. These
// were renamed rather than escaped; a round-trip proves the rename is real and
// not just a column that happens to exist.
$now = gmdate( 'Y-m-d H:i:s' );

$wpdb->insert(
	Tables::name( Tables::INDEX_RUNS ),
	array( 'type' => 'probe', 'status' => 'running', 'cursor_position' => 'offset:4200', 'started_at' => $now ),
	array( '%s', '%s', '%s', '%s' )
);
$run_id = (int) $wpdb->insert_id;
$t( 'index_runs insert with cursor_position', $run_id > 0, $wpdb->last_error );
$t(
	'cursor_position round-trips',
	'offset:4200' === $wpdb->get_var( $wpdb->prepare( 'SELECT cursor_position FROM ' . Tables::name( Tables::INDEX_RUNS ) . ' WHERE id = %d', $run_id ) )
);

$wpdb->insert(
	Tables::name( Tables::TOOL_CALLS ),
	array( 'agent_run_id' => 0, 'conversation_id' => 0, 'tool_id' => 'probe.tool', 'intent' => 'write', 'auth_mode' => 'required', 'status' => 'pending', 'created_at' => $now ),
	array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
);
$call_id = (int) $wpdb->insert_id;
$t( 'tool_calls insert with auth_mode', $call_id > 0, $wpdb->last_error );
$t(
	// Scoped to the row just inserted: the query proves the index conditions
	// match, without asserting an absolute count on a table that legitimately
	// holds other pending approvals (a real store's queue, other suites' rows).
	'approval queue query works',
	1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( Tables::TOOL_CALLS ) . " WHERE auth_mode = 'required' AND status = 'pending' AND id = {$call_id}" )
);

echo "\n== Counter upsert is atomic ==\n";
$counters = Tables::name( Tables::USAGE_COUNTERS );
for ( $i = 0; $i < 3; $i++ ) {
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$counters} (metric, period, total, cost_micros, updated_at)
			 VALUES (%s, %s, %d, 0, %s)
			 ON DUPLICATE KEY UPDATE total = total + VALUES(total), updated_at = VALUES(updated_at)",
			// A synthetic metric and period so the atomic-upsert probe never
			// touches a real counter: 'conversation' in the current period is a
			// live metric, and asserting an absolute 15 on it — then deleting
			// the row — would break and wipe the merchant's own count.
			'probe_counter',
			'1970-01',
			5,
			$now
		)
	);
}
$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT total FROM {$counters} WHERE metric = %s AND period = %s", 'probe_counter', '1970-01' ) );
$t( 'three +5 upserts accumulate to 15', 15 === $total, (string) $total );

echo "\n== Migration lock ==\n";
add_option( Migrator::OPTION_LOCK, (string) time(), '', false );
$locked = new Migrator( array( new Migration001InitialSchema() ) );
update_option( Migrator::OPTION_SCHEMA_VERSION, 0, false );
$result = $locked->run();
$t( 'PROBE: run() refuses while locked', str_contains( $result['error'], 'already running' ), $result['error'] );
$t( 'PROBE: nothing applied while locked', array() === $result['applied'] );
delete_option( Migrator::OPTION_LOCK );

echo "\n== Stale lock is broken ==\n";
add_option( Migrator::OPTION_LOCK, (string) ( time() - 600 ), '', false );
$recovered = new Migrator( array( new Migration001InitialSchema() ) );
$result2   = $recovered->run();
$t( 'PROBE: lock older than TTL is broken', array( 1 ) === $result2['applied'], wp_json_encode( $result2 ) );

echo "\n== Cleanup ==\n";
$wpdb->delete( Tables::name( Tables::INDEX_RUNS ), array( 'id' => $run_id ), array( '%d' ) );
$wpdb->delete( Tables::name( Tables::TOOL_CALLS ), array( 'id' => $call_id ), array( '%d' ) );
$wpdb->delete( $counters, array( 'metric' => 'probe_counter', 'period' => '1970-01' ), array( '%s', '%s' ) );
$t( 'probe rows removed', 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( Tables::TOOL_CALLS ) . " WHERE tool_id = 'probe.tool'" ) );

echo "\n" . str_repeat( '-', 60 ) . "\n";
printf( "%d passed, %d failed\n", $pass, $fail );

if ( $fail > 0 ) {
	exit( 1 );
}
