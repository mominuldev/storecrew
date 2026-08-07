<?php
/**
 * Resumable full-index walker.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Knowledge\Jobs;

use StoreCrew\Api\Registry\ExtractorRegistry;
use StoreCrew\Core\Queue\Deadline;
use StoreCrew\Core\Queue\Scheduler;
use StoreCrew\Database\Repositories\IndexRunRepository;
use StoreCrew\Knowledge\Indexer;

defined( 'ABSPATH' ) || exit;

/**
 * Walks every extractor's objects, a bounded batch at a time.
 *
 * The cursor is the whole point. A full index of 50,000 products cannot finish
 * inside one PHP request on any host worth designing for, so the job does a
 * batch, writes where it got to, and schedules its successor. A run killed
 * mid-way resumes from the recorded position instead of starting again — which
 * matters because starting again means re-embedding everything already paid
 * for (R-TECH-03, R-COST-01).
 *
 * The cursor encodes both which extractor and which id, because "resume from
 * product 4,210" is meaningless once the walk has moved on to pages.
 *
 * @see docs/04-database-schema.md § 8.1
 */
final class IndexJob {

	public const HOOK = 'storecrew_index_batch';

	private const BATCH_SIZE = 20;

	public function __construct(
		private readonly ExtractorRegistry $extractors,
		private readonly Indexer $indexer,
		private readonly IndexRunRepository $runs,
		private readonly Scheduler $scheduler,
	) {}

	/**
	 * Begin a full index.
	 *
	 * Returns the run id, or 0 when one is already in flight — starting a
	 * second concurrent walk would double-bill the merchant for the same work.
	 */
	public function start(): int {
		$active = $this->runs->active();

		if ( null !== $active && $this->runs->is_alive( $active ) ) {
			return 0;
		}

		// A run marked running but heartbeat-dead was killed by the host. Close
		// it out honestly before starting a replacement.
		if ( null !== $active ) {
			$this->runs->reap_stalled();
		}

		$total = array_sum( $this->extractors->counts() );

		$run_id = $this->runs->start( 'full', $total );

		$this->scheduler->enqueue( self::HOOK, array( $run_id ) );

		return $run_id;
	}

	/**
	 * Process one batch and schedule the next.
	 */
	public function run( int $run_id ): void {
		$run = $this->runs->find( $run_id );

		if ( null === $run || IndexRunRepository::STATUS_RUNNING !== (string) $run->status ) {
			return;
		}

		$deadline = new Deadline();
		$cursor   = $this->parse_cursor( (string) $run->cursor_position );
		$types    = array_keys( $this->extractors->available() );

		if ( array() === $types ) {
			$this->runs->finish( $run_id, IndexRunRepository::STATUS_COMPLETE );

			return;
		}

		$type_index = array_search( $cursor['type'], $types, true );

		if ( false === $type_index ) {
			$type_index = 0;
			$cursor     = array( 'type' => $types[0], 'after' => 0 );
		}

		$processed = 0;
		$failed    = 0;

		while ( $type_index < count( $types ) ) {
			$type      = $types[ $type_index ];
			$extractor = $this->extractors->get( $type );

			if ( null === $extractor ) {
				++$type_index;

				continue;
			}

			$ids = $extractor->ids( $cursor['after'], self::BATCH_SIZE );

			if ( array() === $ids ) {
				// This extractor is exhausted; move to the next and reset the id
				// cursor so the next type starts from the beginning.
				++$type_index;
				$cursor = array(
					'type'  => $types[ $type_index ] ?? '',
					'after' => 0,
				);

				continue;
			}

			foreach ( $ids as $object_id ) {
				// Stop before starting work we cannot finish, not after.
				if ( ! $deadline->has_room_for( 1.5 ) ) {
					break 2;
				}

				try {
					$result = $this->indexer->index_object( $type, $object_id );

					if ( 'unavailable' === $result['status'] ) {
						++$failed;
					} else {
						++$processed;
					}
				} catch ( \Throwable $e ) {
					// One bad object must not end the run. Record it and continue;
					// a single malformed product should not stop a catalogue.
					++$failed;

					do_action( 'storecrew_index_object_failed', $type, $object_id, $e->getMessage() );
				}

				$cursor = array( 'type' => $type, 'after' => $object_id );
			}
		}

		$this->runs->progress( $run_id, $processed, $failed, $this->format_cursor( $cursor ) );

		// Exhausted every extractor.
		if ( $type_index >= count( $types ) ) {
			$this->runs->finish( $run_id, IndexRunRepository::STATUS_COMPLETE );

			// Chunks exist but have no vectors yet; hand off to the embedding job.
			$this->scheduler->enqueue( EmbedJob::HOOK );

			return;
		}

		$this->scheduler->enqueue( self::HOOK, array( $run_id ), false );
	}

	/**
	 * Cancel an in-flight run.
	 */
	public function cancel( int $run_id ): bool {
		$this->scheduler->cancel( self::HOOK, array( $run_id ) );

		return $this->runs->finish( $run_id, IndexRunRepository::STATUS_FAILED, 'Cancelled by an administrator.' );
	}

	/**
	 * @return array{type: string, after: int}
	 */
	private function parse_cursor( string $cursor ): array {
		if ( '' === $cursor || ! str_contains( $cursor, ':' ) ) {
			return array( 'type' => '', 'after' => 0 );
		}

		[ $type, $after ] = explode( ':', $cursor, 2 );

		return array( 'type' => $type, 'after' => (int) $after );
	}

	/**
	 * @param array{type: string, after: int} $cursor Cursor.
	 */
	private function format_cursor( array $cursor ): string {
		return $cursor['type'] . ':' . $cursor['after'];
	}
}
