<?php
/**
 * Incremental re-indexing of single objects.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Knowledge\Jobs;

use StoreCrew\Core\Queue\Scheduler;
use StoreCrew\Knowledge\Indexer;

defined( 'ABSPATH' ) || exit;

/**
 * Re-indexes one object after it changes.
 *
 * FR-KB-07: a single product edit must never force a full rebuild. The work is
 * queued rather than done inline because embedding on `save_post` would make
 * the merchant's editor wait on a provider round trip — and a bulk edit would
 * make it wait on hundreds.
 *
 * Deduplication matters more here than anywhere else: saving 500 products fires
 * 500 hooks in one request, and without it each would queue a job for work the
 * pending one already covers.
 */
final class ReindexJob {

	public const HOOK = 'storecrew_reindex_object';

	/**
	 * Small delay before the job runs.
	 *
	 * A product save often fires several times in quick succession as
	 * WooCommerce writes meta. Waiting briefly lets those collapse into one
	 * queued job rather than one per write.
	 */
	private const SETTLE_SECONDS = 30;

	public function __construct(
		private readonly Indexer $indexer,
		private readonly Scheduler $scheduler,
	) {}

	/**
	 * Queue an object for re-indexing.
	 */
	public function queue( string $source_type, int $object_id ): void {
		if ( $object_id < 1 || '' === $source_type ) {
			return;
		}

		$this->scheduler->schedule_in(
			self::SETTLE_SECONDS,
			self::HOOK,
			array( $source_type, $object_id )
		);
	}

	/**
	 * Re-index one object, then top up embeddings.
	 */
	public function run( string $source_type, int $object_id ): void {
		try {
			$result = $this->indexer->index_object( $source_type, $object_id );
		} catch ( \Throwable $e ) {
			do_action( 'storecrew_index_object_failed', $source_type, $object_id, $e->getMessage() );

			return;
		}

		// Only chase embeddings when there is actually something new to embed.
		// An unchanged hash means no chunks were rewritten, so queuing an embed
		// pass would just wake the provider up for nothing.
		if ( 'chunked' === $result['status'] && $result['chunks'] > 0 ) {
			$this->scheduler->enqueue( EmbedJob::HOOK );
		}
	}
}
