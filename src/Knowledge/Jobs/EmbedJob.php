<?php
/**
 * Drains pending embeddings.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Knowledge\Jobs;

use StoreCrew\Core\Queue\Deadline;
use StoreCrew\Core\Queue\Scheduler;
use StoreCrew\Knowledge\Indexer;

defined( 'ABSPATH' ) || exit;

/**
 * Embeds chunks that have text but no vector.
 *
 * Separate from IndexJob because the two fail differently. Chunking fails on
 * bad content and is free to retry; embedding fails on a provider outage, an
 * expired key, or an exhausted spend cap — and retrying those in a tight loop
 * burns the merchant's rate limit to fail identically. When embedding stops,
 * this backs off rather than rescheduling immediately, and stops entirely when
 * the reason will not resolve on its own.
 */
final class EmbedJob {

	public const HOOK = 'storecrew_embed_batch';

	private const BATCH_SIZE = 25;

	/**
	 * How long to wait before retrying after a recoverable failure.
	 */
	private const BACKOFF_SECONDS = 300;

	public function __construct(
		private readonly Indexer $indexer,
		private readonly Scheduler $scheduler,
	) {}

	/**
	 * Queue a drain, if one is not already pending.
	 */
	public function start(): int {
		return $this->scheduler->enqueue( self::HOOK );
	}

	/**
	 * Embed one or more batches, then reschedule if work remains.
	 */
	public function run(): void {
		$deadline = new Deadline();

		$embedded = 0;

		while ( $deadline->has_room_for( 5.0 ) ) {
			$result = $this->indexer->embed_pending( self::BATCH_SIZE );

			if ( $result['blocked'] ) {
				/**
				 * Fires when embedding cannot proceed.
				 *
				 * Surfaced rather than swallowed: a merchant whose key expired
				 * needs to know indexing stopped, not discover it when answers
				 * quietly get worse.
				 *
				 * @param string $reason   Why it stopped.
				 * @param int    $embedded How many succeeded before stopping.
				 */
				do_action( 'storecrew_embedding_blocked', $result['reason'], $embedded );

				return;
			}

			if ( 0 === $result['embedded'] && 0 === $result['failed'] ) {
				// Nothing left to do.
				return;
			}

			$embedded += $result['embedded'];

			if ( $result['failed'] > 0 && 0 === $result['embedded'] ) {
				// Transient provider trouble. Back off rather than hammering it.
				$this->scheduler->schedule_in( self::BACKOFF_SECONDS, self::HOOK );

				return;
			}
		}

		// Ran out of time with work remaining.
		$this->scheduler->enqueue( self::HOOK, array(), false );
	}
}
