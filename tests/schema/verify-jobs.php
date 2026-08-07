<?php
/**
 * Background job runner verification.
 *
 * Run with:  wp eval-file wp-content/plugins/storecrew/tests/schema/verify-jobs.php
 *
 * Uses the site's real Action Scheduler. Jobs are invoked directly rather than
 * waiting for the queue to tick, so the suite finishes in seconds while still
 * exercising the real scheduling, dedup, and cursor behaviour.
 *
 * No declare(strict_types=1): wp eval-file runs this through eval().
 *
 * @package StoreCrew
 */

use StoreCrew\Core\Queue\Deadline;
use StoreCrew\Core\Queue\MaintenanceJob;
use StoreCrew\Core\Queue\Scheduler;
use StoreCrew\Database\Repositories\IndexRunRepository;
use StoreCrew\Database\Repositories\KnowledgeChunkRepository;
use StoreCrew\Database\Repositories\KnowledgeSourceRepository;
use StoreCrew\Database\Tables;
use StoreCrew\Knowledge\Jobs\EmbedJob;
use StoreCrew\Knowledge\Jobs\IndexJob;
use StoreCrew\Knowledge\Jobs\ReindexJob;

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

$c         = StoreCrew\Plugin::instance()->container();
$scheduler = $c->get( Scheduler::class );
$runs      = $c->get( IndexRunRepository::class );
$sources   = $c->get( KnowledgeSourceRepository::class );
$chunks    = $c->get( KnowledgeChunkRepository::class );

// Start clean; a previous run's queue would make dedup assertions meaningless.
$scheduler->cancel();

echo "\n== Deadline ==\n";
$budget = Deadline::detect_budget();
$t( 'derives a budget from the host limit', $budget >= 5 && $budget <= 60, (string) $budget );

$deadline = new Deadline( 2 );
$t( 'fresh deadline is not exceeded', ! $deadline->exceeded() );
$t( 'has room for small work', $deadline->has_room_for( 0.1 ) );
$t(
	'PROBE: refuses work it cannot finish, rather than starting it',
	! $deadline->has_room_for( 10.0 )
);
usleep( 2_100_000 );
$t( 'PROBE: reports exceeded once the budget is spent', $deadline->exceeded() );

echo "\n== Scheduler ==\n";
$t( 'Action Scheduler is available', $scheduler->is_available() );

$id = $scheduler->enqueue( 'storecrew_probe_hook', array( 'a' ) );
$t( 'enqueues an action', $id > 0 );
$t( 'reports it as pending', $scheduler->is_pending( 'storecrew_probe_hook', array( 'a' ) ) );

$dupe = $scheduler->enqueue( 'storecrew_probe_hook', array( 'a' ) );
$t( 'PROBE: identical action is deduplicated', 0 === $dupe );

$other = $scheduler->enqueue( 'storecrew_probe_hook', array( 'b' ) );
$t( 'different arguments are not deduplicated', $other > 0 );

$forced = $scheduler->enqueue( 'storecrew_probe_hook', array( 'a' ), false );
$t( 'dedup can be bypassed when a job chains itself', $forced > 0 );

$health = $scheduler->health();
$t( 'health reports availability', true === $health['available'] );
$t( 'health counts pending work', $health['pending'] >= 3, wp_json_encode( $health ) );

$scheduler->cancel( 'storecrew_probe_hook' );
$t( 'PROBE: cancel clears the hook', ! $scheduler->is_pending( 'storecrew_probe_hook', array( 'a' ) ) );

echo "\n== Job handlers are registered ==\n";
$t( 'index batch handler registered', has_action( IndexJob::HOOK ) !== false );
$t( 'embed batch handler registered', has_action( EmbedJob::HOOK ) !== false );
$t( 'reindex handler registered', has_action( ReindexJob::HOOK ) !== false );
$t( 'maintenance handler registered', has_action( MaintenanceJob::HOOK ) !== false );
$t(
	'PROBE: the kernel\'s reindex signal is consumed',
	has_action( 'storecrew_queue_reindex' ) !== false
);

echo "\n== Full index walk ==\n";
$page_ids = array();

for ( $i = 1; $i <= 5; $i++ ) {
	$page_ids[] = (int) wp_insert_post(
		array(
			'post_title'   => "StoreCrew Job Probe {$i}",
			'post_content' => "Probe document number {$i}. " . str_repeat( 'Shipping and returns information. ', 10 ),
			'post_status'  => 'publish',
			'post_type'    => 'page',
		)
	);
}

$index_job = $c->get( IndexJob::class );

$run_id = $index_job->start();
$t( 'starting a run returns a run id', $run_id > 0 );
$t( 'the run is queued', $scheduler->is_pending( IndexJob::HOOK, array( $run_id ) ) );

$second_start = $index_job->start();
$t( 'PROBE: a second concurrent run is refused', 0 === $second_start );

// Drive the batches directly rather than waiting for the queue to tick.
$iterations = 0;

do {
	$index_job->run( $run_id );
	$run = $runs->find( $run_id );
	++$iterations;
} while ( IndexRunRepository::STATUS_RUNNING === (string) $run->status && $iterations < 50 );

$t( 'the walk terminates', $iterations < 50, "iterations={$iterations}" );
$t( 'the run completes', IndexRunRepository::STATUS_COMPLETE === (string) $run->status, (string) $run->status );
$t( 'it processed the probe pages', (int) $run->processed >= 5, (string) $run->processed );
$t( 'progress is recorded', (int) $run->processed > 0 );
$t( 'heartbeat was written', null !== $run->heartbeat_at );

// The walk must reach the *end* of the set, not just the first batch. These
// pages have the highest ids on the site, so they are only indexed if the
// cursor genuinely advances past the first page of results.
$missing = array();

foreach ( $page_ids as $page_id ) {
	if ( null === $sources->find_by_key( KnowledgeSourceRepository::key( 'post', $page_id ) ) ) {
		$missing[] = $page_id;
	}
}

$t(
	'PROBE: the walk reaches objects beyond the first batch',
	array() === $missing,
	'missing: ' . implode( ',', $missing )
);
$t(
	'processed count covers the whole set, not one batch',
	(int) $run->processed >= count( $page_ids ),
	(string) $run->processed
);

$t( 'completing the walk queues embedding', $scheduler->is_pending( EmbedJob::HOOK ) );

echo "\n== Resume after a killed batch (R-TECH-03) ==\n";
$scheduler->cancel();

$resume_run = $runs->start( 'full', 5 );

// One batch, then simulate the host killing the process: the run stays
// "running" with a cursor and nothing else happens.
$index_job->run( $resume_run );
$after_first = $runs->find( $resume_run );
$cursor      = (string) $after_first->cursor_position;

$t( 'PROBE: a cursor is written after a batch', '' !== $cursor, $cursor );
$t( 'cursor names both extractor and position', str_contains( $cursor, ':' ), $cursor );

$processed_before = (int) $after_first->processed;

// Resuming picks up from the cursor rather than starting over.
$index_job->run( $resume_run );
$after_second = $runs->find( $resume_run );

$t(
	'PROBE: resuming advances the cursor rather than restarting',
	(string) $after_second->cursor_position !== $cursor
		|| IndexRunRepository::STATUS_COMPLETE === (string) $after_second->status,
	(string) $after_second->cursor_position
);
$t(
	'PROBE: resumed work is not re-counted from zero',
	(int) $after_second->processed >= $processed_before
);

// A run whose heartbeat has died must not be reported as in-flight.
$GLOBALS['wpdb']->update(
	Tables::name( Tables::INDEX_RUNS ),
	array( 'status' => 'running', 'heartbeat_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ),
	array( 'id' => $resume_run ),
	array( '%s', '%s' ),
	array( '%d' )
);
$t( 'PROBE: a heartbeat-dead run reads as not alive', ! $runs->is_alive( $runs->find( $resume_run ) ) );
$t( 'PROBE: start() reaps the dead run and begins a new one', $index_job->start() > 0 );

echo "\n== Incremental reindex ==\n";
$scheduler->cancel();

$reindex = $c->get( ReindexJob::class );

do_action( 'storecrew_queue_reindex', 'post', $page_ids[0] );
$t(
	'PROBE: the kernel signal queues a reindex job',
	$scheduler->is_pending( ReindexJob::HOOK, array( 'post', $page_ids[0] ) )
);

// A bulk edit fires the same hook many times in one request.
for ( $i = 0; $i < 20; $i++ ) {
	do_action( 'storecrew_queue_reindex', 'post', $page_ids[0] );
}
$pending = $scheduler->health()['pending'];
$t( 'PROBE: 20 repeat signals collapse to one queued job', $pending <= 2, (string) $pending );

$reindex->queue( '', 0 );
$reindex->queue( 'post', 0 );
$t( 'invalid queue requests are ignored', $scheduler->health()['pending'] <= 2 );

// Re-running against unchanged content must not chase embeddings.
$scheduler->cancel();
$reindex->run( 'post', $page_ids[0] );
$t(
	'PROBE: unchanged content does not queue an embedding pass',
	! $scheduler->is_pending( EmbedJob::HOOK )
);

wp_update_post(
	array(
		'ID'           => $page_ids[0],
		'post_content' => 'Rewritten. ' . str_repeat( 'Updated shipping and returns information. ', 10 ),
	)
);
$reindex->run( 'post', $page_ids[0] );
$t( 'changed content DOES queue an embedding pass', $scheduler->is_pending( EmbedJob::HOOK ) );

echo "\n== Embed job without a provider ==\n";
$scheduler->cancel();
$embed = $c->get( EmbedJob::class );

$blocked_reason = '';
add_action(
	'storecrew_embedding_blocked',
	static function ( $reason ) use ( &$blocked_reason ): void {
		$blocked_reason = (string) $reason;
	}
);

$embed->run();
$t(
	'PROBE: blocked embedding is announced, not swallowed',
	'' !== $blocked_reason,
	$blocked_reason
);
$t(
	'PROBE: blocked embedding does not reschedule itself into a loop',
	! $scheduler->is_pending( EmbedJob::HOOK )
);

echo "\n== Maintenance ==\n";
$maintenance = $c->get( MaintenanceJob::class );

$stale_run = $runs->start( 'full', 1 );
$GLOBALS['wpdb']->update(
	Tables::name( Tables::INDEX_RUNS ),
	array( 'heartbeat_at' => gmdate( 'Y-m-d H:i:s', time() - 900 ) ),
	array( 'id' => $stale_run ),
	array( '%s' ),
	array( '%d' )
);

$swept = $maintenance->run();
$t( 'sweep reports what it reaped', isset( $swept['indexRuns'] ), wp_json_encode( $swept ) );
$t( 'PROBE: it reaped the stalled run', $swept['indexRuns'] >= 1 );
$t(
	'stalled run is marked stalled, not left running',
	IndexRunRepository::STATUS_STALLED === (string) $runs->find( $stale_run )->status
);

// A one-day retention must not actually delete a two-day-old audit row.
$audit = $c->get( StoreCrew\Database\Repositories\AuditLogRepository::class );
$audit_id = $audit->record( 'probe.retention' );
$GLOBALS['wpdb']->update(
	Tables::name( Tables::AUDIT_LOG ),
	array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) ) ),
	array( 'id' => $audit_id ),
	array( '%s' ),
	array( '%d' )
);

update_option( MaintenanceJob::OPTION_AUDIT_RETENTION_DAYS, 1 );
$maintenance->run();
$survivors = $audit->recent( 5, 'probe.retention' );
$t(
	'PROBE: a 1-day retention setting cannot delete recent audit history',
	1 === count( $survivors ),
	(string) count( $survivors )
);
delete_option( MaintenanceJob::OPTION_AUDIT_RETENTION_DAYS );
$GLOBALS['wpdb']->delete( Tables::name( Tables::AUDIT_LOG ), array( 'action' => 'probe.retention' ), array( '%s' ) );

$maintenance->ensure_scheduled();
$t( 'recurring sweep is scheduled', $scheduler->is_pending( MaintenanceJob::HOOK ) );
$before_pending = $scheduler->health()['pending'];
$maintenance->ensure_scheduled();
$t(
	'PROBE: scheduling twice does not duplicate it',
	$scheduler->health()['pending'] === $before_pending,
	(string) $scheduler->health()['pending']
);

echo "\n== Cleanup ==\n";
$scheduler->cancel();

foreach ( $page_ids as $page_id ) {
	$source = $sources->find_by_key( KnowledgeSourceRepository::key( 'post', $page_id ) );

	if ( null !== $source ) {
		$chunks->delete_for_source( (int) $source->id );
		$sources->delete( (int) $source->id );
	}

	wp_delete_post( $page_id, true );
}

$GLOBALS['wpdb']->query(
	'DELETE FROM ' . Tables::name( Tables::INDEX_RUNS ) . " WHERE type = 'full'"
);

$t( 'queue drained', 0 === $scheduler->health()['pending'] );
$t( 'probe pages removed', null === get_post( $page_ids[0] ) );

echo "\n" . str_repeat( '-', 60 ) . "\n";
printf( "%d passed, %d failed\n", $pass, $fail );

if ( $fail > 0 ) {
	exit( 1 );
}
