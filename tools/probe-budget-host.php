<?php
/**
 * Budget-host validation probe (R-TECH-03).
 *
 * Run with:
 *   wp eval-file wp-content/plugins/storecrew/tools/probe-budget-host.php
 *
 * The exit criterion for this row (14 § M1) is "a full index plus a day's
 * simulated chat on a $5/mo shared host, and a capability report that matches
 * reality." Two of those three clauses can only be settled *on the real host*;
 * this tool is what you run there. On any host it does two things:
 *
 *   1. Prints a capability report — the R-TECH-03 dimensions a budget host
 *      actually differs on (the kill window it imposes, cron reliability,
 *      CLI-vs-web PHP, loopback). On the real host you read it and check that
 *      each line matches what the host actually does. That is the "capability
 *      report matches reality" clause, made checkable rather than asserted.
 *
 *   2. Runs a *full index under a deliberately tight kill window* against a
 *      synthetic catalogue, driving the real IndexJob the way Action Scheduler
 *      would and killing it after roughly one object per batch. It asserts the
 *      index still finishes, every object is indexed exactly once, the cursor
 *      only ever advances, and nothing is re-counted — the resume machinery
 *      R-TECH-03 rests on, observed surviving dozens of kills rather than
 *      assumed to. Then it reports the measured throughput so the real-host run
 *      has a number to compare against.
 *
 * Why synthetic: a full index walks the merchant's live catalogue and writes to
 * the real chunk store. Running that as a probe is the exact shape of bug this
 * project keeps re-finding (a test touching the merchant's index). So the walk
 * runs over a synthetic source type whose only rows at risk are the ones the
 * probe created, and every table it touches is snapshot-and-restored — the
 * option, the usage counters, the scheduler queue, and the run row.
 *
 * Keyless-safe: nothing here embeds. Cost is *estimated* from the configured
 * embedding model's published rate (or reported unknown), never spent — the
 * one billable half is the real-host run's, not this instrument's.
 *
 * Configuration (environment, so it survives `wp eval-file`):
 *   STORECREW_BUDGET_OBJECTS   synthetic catalogue size      (default 150)
 *   STORECREW_BUDGET_SECONDS   forced per-batch kill window   (default 1)
 *
 * No declare(strict_types=1): wp eval-file runs this through eval().
 *
 * @package StoreCrew
 */

use StoreCrew\Ai\ModelPolicy;
use StoreCrew\Ai\Pricing;
use StoreCrew\Ai\SpendGuard;
use StoreCrew\Api\Registry\ExtractorRegistry;
use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Core\Queue\Deadline;
use StoreCrew\Core\Queue\Scheduler;
use StoreCrew\Database\Repositories\KnowledgeChunkRepository;
use StoreCrew\Database\Repositories\KnowledgeSourceRepository;
use StoreCrew\Database\Repositories\IndexRunRepository;
use StoreCrew\Database\Repositories\UsageRepository;
use StoreCrew\Database\Tables;
use StoreCrew\Knowledge\Chunker;
use StoreCrew\Knowledge\ExtractedDocument;
use StoreCrew\Knowledge\ExtractorInterface;
use StoreCrew\Knowledge\Indexer;
use StoreCrew\Knowledge\Jobs\EmbedJob;
use StoreCrew\Knowledge\Jobs\IndexJob;
use StoreCrew\Knowledge\SourceSelection;

if ( ! class_exists( '\StoreCrew\Plugin' ) ) {
	echo "StoreCrew is not active.\n";

	return;
}

$pass = 0;
$fail = 0;

$t = static function ( $label, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	if ( $ok ) {
		++$pass;
		echo "  PASS  {$label}\n";
	} else {
		++$fail;
		echo "  FAIL  {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
	}
};

$line = static function ( $label, $value ) {
	echo '  ' . str_pad( $label, 34 ) . $value . "\n";
};

$c = StoreCrew\Plugin::instance()->container();

$objects = max( 20, (int) ( getenv( 'STORECREW_BUDGET_OBJECTS' ) ?: 150 ) );
$seconds = max( 1, (int) ( getenv( 'STORECREW_BUDGET_SECONDS' ) ?: 1 ) );

$source_type = 'budget_probe';

/*
 * ---------------------------------------------------------------------------
 * 1. Capability report — read on the real host and check against reality.
 * ---------------------------------------------------------------------------
 */
echo "\n== Host capability report (R-TECH-03) ==\n";

$max_exec  = (int) ini_get( 'max_execution_time' );
$budget    = Deadline::detect_budget();
$scheduler = $c->get( Scheduler::class );
$sched     = $scheduler->health();

$sapi = PHP_SAPI;
$line( 'PHP (this process)', PHP_VERSION . "  (SAPI: {$sapi})" );
$line(
	'max_execution_time',
	( 0 === $max_exec ? '0 (unlimited — CLI or no limit)' : $max_exec . 's' )
);
$line( 'derived batch budget', $budget . 's  (Deadline::detect_budget)' );
$line( 'memory_limit', (string) ini_get( 'memory_limit' ) );
$line(
	'WP-Cron',
	( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
		? 'DISABLED (real cron expected)'
		: 'enabled (loopback-driven)'
);
$line(
	'Action Scheduler',
	$sched['available']
		? "available, {$sched['pending']} pending"
		: 'UNAVAILABLE — indexing cannot run'
);
$line( 'WooCommerce', defined( 'WC_VERSION' ) ? WC_VERSION : 'not active' );
$line(
	'HPOS',
	class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		? 'enabled' : 'off / n/a'
);

// Cron reliability, reported from configuration rather than probed. WP-Cron
// and Action Scheduler both lean on the site reaching itself, but we do *not*
// fire a loopback here: on a live install that request spawns WP-Cron, which
// starts the Action Scheduler runner, which would then race the index walk
// below in another process. To measure the walk honestly the probe must not be
// the thing that perturbs it. Check the loopback separately with `wp cron test`.
$last_cron = (int) get_option( 'action_scheduler_last_processed', 0 );
if ( $last_cron > 0 ) {
	$line( 'Action Scheduler last ran', human_time_diff( $last_cron ) . ' ago' );
}
$line(
	'loopback (check separately)',
	'`wp cron test` — a blocked loopback means the index only advances on real cron'
);

echo "\n  Note: run under `wp eval-file` this reports the *CLI* PHP and an\n";
echo "  unlimited execution time. Compare PHP against the admin Health screen's\n";
echo "  web PHP (they differ on some budget hosts — R-TECH-03), and read the\n";
echo "  real per-batch kill window off a web/cron run, not this line.\n";

/*
 * ---------------------------------------------------------------------------
 * 2. Real-catalogue cost estimate — the figure the real-host run confirms.
 * ---------------------------------------------------------------------------
 */
echo "\n== Full-index cost estimate (real catalogue) ==\n";

$real_indexer  = $c->get( Indexer::class );
$real_estimate = $real_indexer->estimate();

$line( 'selected objects', (string) $real_estimate['total'] );
$line( 'estimated chunks', (string) $real_estimate['estimatedChunks'] );
if ( $real_estimate['costKnown'] ) {
	$line( 'estimated embed cost', '$' . number_format( $real_estimate['costMicros'] / 1_000_000, 4 ) );
} else {
	// Pricing that is unknown reports unknown, never a confident zero.
	$line( 'estimated embed cost', 'unknown (no published rate for the embedding model)' );
}

/*
 * ---------------------------------------------------------------------------
 * 3. A full index under a forced-tight kill window (the R-TECH-03 core).
 * ---------------------------------------------------------------------------
 */
echo "\n== Full index under a {$seconds}s kill window, {$objects} objects ==\n";

// A synthetic extractor: one short document per object, real enough to chunk,
// isolated under its own source type so cleanup cannot reach a real row.
$extracted = array();

$extractor = new class( $source_type, $objects, $extracted ) implements ExtractorInterface {
	/** @var array<int,int> */
	public $extracted;

	public function __construct(
		private $type,
		private $count,
		&$extracted
	) {
		$this->extracted = &$extracted;
	}

	public function source_type(): string {
		return $this->type;
	}

	public function label(): string {
		return 'Budget-host probe';
	}

	public function is_available(): bool {
		return true;
	}

	public function count(): int {
		return $this->count;
	}

	public function ids( int $after_id = 0, int $limit = 50 ): array {
		$ids = array();

		for ( $id = $after_id + 1; $id <= $this->count && count( $ids ) < $limit; $id++ ) {
			$ids[] = $id;
		}

		return $ids;
	}

	public function extract( int $object_id ): ?ExtractedDocument {
		if ( $object_id < 1 || $object_id > $this->count ) {
			return null;
		}

		// Count every extraction so double-work would show up as a value > 1.
		$this->extracted[ $object_id ] = ( $this->extracted[ $object_id ] ?? 0 ) + 1;

		return new ExtractedDocument(
			$this->type,
			$object_id,
			"Budget probe object {$object_id}",
			"Shipping and returns information for probe object number {$object_id}. "
				. 'This store ships worldwide and accepts returns within thirty days of delivery.',
			'',
			"budget-probe-{$object_id}"
		);
	}
};

// A fresh registry + selection scoped to the synthetic type. The registry is
// unfrozen (it is not the container's), so registration is legal here.
$probe_extractors = new ExtractorRegistry();
$probe_extractors->register( $extractor );

$probe_selection = new SourceSelection( $probe_extractors );

$runs_repo     = $c->get( IndexRunRepository::class );
$sources_repo  = $c->get( KnowledgeSourceRepository::class );
$chunks_repo   = $c->get( KnowledgeChunkRepository::class );
$usage_repo    = $c->get( UsageRepository::class );
$wpdb          = $GLOBALS['wpdb'];

$usage_events   = Tables::name( Tables::USAGE_EVENTS );
$runs_table     = Tables::name( Tables::INDEX_RUNS );
$max_usage_id   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$usage_events}" ); // phpcs:ignore WordPress.DB
$docs_before    = $usage_repo->total( UsageRepository::METRIC_DOCUMENT );
$embed_pending0 = $scheduler->is_pending( EmbedJob::HOOK );

// Force the selection to the synthetic type through the read filter rather
// than writing the merchant's real option. This never touches their stored
// choice, and — unlike a DB write — it cannot be undone mid-walk by a
// concurrent request or a cron tick flipping the option back (which was making
// enabled() momentarily empty and aborting the walk early).
$force_sources = static function () use ( $source_type ) {
	return array( $source_type );
};
add_filter( 'option_' . SourceSelection::OPTION, $force_sources );

// Force the tight kill window regardless of what this host's real limit is,
// so the resume machinery is stress-tested even on an unlimited CLI.
$force_budget = static function () use ( $seconds ) {
	return $seconds;
};
add_filter( 'storecrew_index_batch_seconds', $force_budget );

$probe_indexer = new Indexer(
	$probe_extractors,
	$c->get( ProviderRegistry::class ),
	$c->get( ModelPolicy::class ),
	$c->get( Chunker::class ),
	$sources_repo,
	$chunks_repo,
	$usage_repo,
	$c->get( SpendGuard::class ),
	$probe_selection
);

$probe_job = new IndexJob(
	$probe_extractors,
	$probe_indexer,
	$runs_repo,
	$scheduler,
	$probe_selection
);

// Drive the batches ourselves, and *only* ourselves. Each run() enqueues its
// successor on the site's real Action Scheduler; if a cron tick fires the
// runner in another process (the loopback above can trigger exactly that), that
// process would pick up our run id and execute it through the container's real
// IndexJob — real catalogue, real selection, real budget — racing our loop and
// corrupting the very accounting we are measuring (it was flipping ~1 run in 6
// to a partial index). Detaching the handlers for the probe's lifetime makes a
// stray tick a no-op; our direct run() calls do not go through the hook and are
// unaffected. Handlers re-register from the container on the next boot.
remove_all_actions( IndexJob::HOOK );
remove_all_actions( EmbedJob::HOOK );

// Start from a clean slate so the run is deterministic: a synthetic source or
// run row left by an interrupted earlier probe would make the accounting lie.
$probe_indexer->forget_type( $source_type );
$runs_repo->reap_stalled();

$run_id = $runs_repo->start( 'full', $objects );

$iterations   = 0;
$prev_after   = -1;
$monotonic    = true;
$max_batch    = 0;
$started      = microtime( true );

do {
	$before = $runs_repo->find( $run_id );
	$probe_job->run( $run_id );
	// Each run() just enqueued its successor on the shared scheduler. Pull it
	// straight back out before a cron-triggered runner in another process can
	// claim it and race our loop — we are the only thing allowed to advance
	// this run. (Detaching the handler above only covers *our* process.)
	$scheduler->cancel( IndexJob::HOOK, array( $run_id ) );
	$run = $runs_repo->find( $run_id );
	++$iterations;

	// The cursor is 'budget_probe:<after_id>'. It must only ever move forward;
	// a resume that restarted would drop it back to a lower id and re-index.
	$after = 0;
	if ( false !== strpos( (string) $run->cursor_position, ':' ) ) {
		$parts = explode( ':', (string) $run->cursor_position, 2 );
		$after = (int) $parts[1];
	}

	// Only while the walk is mid-flight: on completion the cursor legitimately
	// resets to an empty ':0' as it steps past the last source type.
	if ( IndexRunRepository::STATUS_RUNNING === (string) $run->status ) {
		if ( $after < $prev_after ) {
			$monotonic = false;
		}
		$prev_after = $after;
	}

	// How much one batch got through before the kill window stopped it.
	$batch_did = (int) $run->processed - (int) $before->processed;
	$max_batch = max( $max_batch, $batch_did );
} while (
	IndexRunRepository::STATUS_RUNNING === (string) $run->status
	&& $iterations < ( $objects + 50 )
);

$elapsed = microtime( true ) - $started;

$t( 'the index completes despite the kill window', IndexRunRepository::STATUS_COMPLETE === (string) $run->status, (string) $run->status );
$t( 'it took many batches, not one', $iterations >= 10, "iterations={$iterations}" );
$t( 'no batch ran away past the kill window', $max_batch <= 5, "largest batch={$max_batch} objects" );
$t( 'the cursor only ever advanced', $monotonic, "last after={$prev_after}" );
$t( 'processed count equals the catalogue', (int) $run->processed === $objects, (string) $run->processed );

// Exact accounting: every object extracted exactly once. A resume that
// restarted, or a batch boundary that double-fetched, shows up as a 2 here.
$missing   = array();
$duplicate = array();
for ( $id = 1; $id <= $objects; $id++ ) {
	$n = $extracted[ $id ] ?? 0;
	if ( 0 === $n ) {
		$missing[] = $id;
	} elseif ( $n > 1 ) {
		$duplicate[] = $id;
	}
}
$t( 'every object was indexed', array() === $missing, count( $missing ) . ' missing' );
$t( 'no object was indexed twice (no re-billing)', array() === $duplicate, count( $duplicate ) . ' duplicated' );

// A source row per object, and chunks behind them.
$probe_sources = $sources_repo->ids_of_type( $source_type );
$t( 'one source row per object', count( $probe_sources ) === $objects, count( $probe_sources ) . ' rows' );

// Liveness was written as the walk progressed, so a killed run is
// distinguishable from a live one (FR-ADMIN-08).
$t( 'a heartbeat was written during the walk', null !== $run->heartbeat_at );
$t( 'the completed run reads as not still running', IndexRunRepository::STATUS_RUNNING !== (string) $run->status );

echo "\n";
$line( 'batches (kills survived)', (string) $iterations );
$line( 'largest single batch', $max_batch . ' object(s)' );
$rate = $elapsed > 0 ? $objects / $elapsed : 0;
$line( 'walk wall-clock', number_format( $elapsed, 2 ) . 's' );
$line( 'throughput', number_format( $rate, 1 ) . ' objects/sec' );
if ( $rate > 0 && $real_estimate['total'] > 0 ) {
	$projected = $real_estimate['total'] / $rate;
	$line(
		'projected for real catalogue',
		'~' . number_format( $projected, 1 ) . "s to walk {$real_estimate['total']} objects (chunking only; embedding is separate)"
	);
}

/*
 * ---------------------------------------------------------------------------
 * 4. A host kill mid-run leaves a run that reaps cleanly, not a zombie.
 * ---------------------------------------------------------------------------
 */
echo "\n== A killed run is reaped, not left running forever ==\n";

$zombie = $runs_repo->start( 'full', $objects );
$probe_job->run( $zombie ); // one batch, then "the host kills PHP".

// Backdate the heartbeat to simulate a process that died and never wrote again.
$wpdb->update( // phpcs:ignore WordPress.DB
	$runs_table,
	array(
		'status'       => IndexRunRepository::STATUS_RUNNING,
		'heartbeat_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ),
	),
	array( 'id' => $zombie ),
	array( '%s', '%s' ),
	array( '%d' )
);

$t( 'a heartbeat-dead run reads as not alive', ! $runs_repo->is_alive( $runs_repo->find( $zombie ) ) );
$t( 'reap_stalled closes it out', $runs_repo->reap_stalled() >= 1 );
$t( 'the reaped run is no longer active', null === $runs_repo->active() || (int) $runs_repo->active()->id !== $zombie );

/*
 * ---------------------------------------------------------------------------
 * 5. Cleanup — the probe touched real tables; it restores every one.
 * ---------------------------------------------------------------------------
 */
echo "\n== Cleanup ==\n";

// Synthetic sources and their chunks.
$forgotten = $probe_indexer->forget_type( $source_type );
$t( 'synthetic sources and chunks removed', 0 === count( $sources_repo->ids_of_type( $source_type ) ), $forgotten['sources'] . ' sources / ' . $forgotten['chunks'] . ' chunks' );

// Usage events the walk wrote (single-threaded, so everything past the
// snapshot id is the probe's), then rebuild the period's counters from the
// cleaned event log so the merchant's document metric is exactly as it was.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$usage_events} WHERE id > %d", $max_usage_id ) ); // phpcs:ignore WordPress.DB
$usage_repo->rebuild_counters();
$docs_after = $usage_repo->total( UsageRepository::METRIC_DOCUMENT );
$t( 'the document metric is restored', $docs_after === $docs_before, "before={$docs_before} after={$docs_after}" );

// The scheduler queue: reschedules for our run id, and the completion's embed
// job (only ours if none was pending before — otherwise it deduped into theirs).
$scheduler->cancel( IndexJob::HOOK, array( $run_id ) );
$scheduler->cancel( IndexJob::HOOK, array( $zombie ) );
if ( ! $embed_pending0 && $scheduler->is_pending( EmbedJob::HOOK ) ) {
	$scheduler->cancel( EmbedJob::HOOK );
}
$t( 'no probe index batch left pending', ! $scheduler->is_pending( IndexJob::HOOK, array( $run_id ) ) );

// The synthetic run rows — off the dashboard's recent list.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$runs_table} WHERE id IN (%d, %d)", $run_id, $zombie ) ); // phpcs:ignore WordPress.DB
$t( 'synthetic run rows removed', null === $runs_repo->find( $run_id ) && null === $runs_repo->find( $zombie ) );

// The forced budget and the forced selection — filters only, no stored state
// to restore. The merchant's real `storecrew_sources` option was never touched
// (forced through the option_ read filter, never update_option), so there is
// nothing to snapshot and nothing that a fatal could have left behind.
remove_filter( 'storecrew_index_batch_seconds', $force_budget );
remove_filter( 'option_' . SourceSelection::OPTION, $force_sources );
echo "  (the merchant's source selection was forced by read filter, never written)\n";

echo "\n";
echo "  ----------------------------------------\n";
echo "  PASS: {$pass}   FAIL: {$fail}\n";
echo "  ----------------------------------------\n\n";

echo "  Still open — and only settleable on the real host:\n";
echo "    • a full index of the merchant's real catalogue on a \$5/mo host,\n";
echo "      timed and costed against the estimate above;\n";
echo "    • a day's simulated chat sustained on that host;\n";
echo "    • the capability report above eyeballed against the host's reality.\n";
echo "  This tool is what you run there; see docs/14 for the protocol.\n\n";

if ( $fail > 0 ) {
	echo "  RESULT: FAIL\n";
} else {
	echo "  RESULT: PASS — the resume machinery survived {$iterations} kills and\n";
	echo "  the capability report is ready to check against a real budget host.\n";
}
