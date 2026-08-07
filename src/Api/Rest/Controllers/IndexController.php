<?php
/**
 * Knowledge-base indexing control.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Rest\Controllers;

use StoreCrew\Api\Registry\ExtractorRegistry;
use StoreCrew\Api\Rest\RestController;
use StoreCrew\Core\Capabilities\Capabilities;
use StoreCrew\Core\Queue\Scheduler;
use StoreCrew\Database\Repositories\IndexRunRepository;
use StoreCrew\Knowledge\Indexer;
use StoreCrew\Knowledge\Jobs\EmbedJob;
use StoreCrew\Knowledge\Jobs\IndexJob;
use StoreCrew\Knowledge\SourceSelection;
use StoreCrew\Licensing\FeatureGate;

defined( 'ABSPATH' ) || exit;

/**
 * Starts, stops, and reports on indexing.
 *
 * `/index/estimate` exists so a merchant sees what a run will cost *before* it
 * starts. R-COST-01 rates a surprise provider bill as high impact, and someone
 * with 50,000 products deserves the number in advance — including an honest
 * "we don't have a rate for that model" when the figure is not knowable.
 */
final class IndexController extends RestController {

	public function __construct(
		FeatureGate $features,
		private readonly Indexer $indexer,
		private readonly IndexJob $index_job,
		private readonly EmbedJob $embed_job,
		private readonly IndexRunRepository $runs,
		private readonly ExtractorRegistry $extractors,
		private readonly Scheduler $scheduler,
		private readonly SourceSelection $selection,
	) {
		parent::__construct( $features );
	}

	public function register_routes(): void {
		$this->route(
			'/index',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
			)
		);

		$this->route(
			'/index/estimate',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'estimate' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
			)
		);

		$this->route(
			'/index/sources',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_sources' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
			)
		);

		$this->route(
			'/index/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
			)
		);

		$this->route(
			'/index/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
			)
		);

		$this->route(
			'/index/embed',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'embed' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
			)
		);
	}

	public function status(): \WP_REST_Response {
		$active = $this->runs->active();

		$recent = array();

		foreach ( $this->runs->recent( 10 ) as $run ) {
			$recent[] = $this->present_run( $run );
		}

		return $this->ok(
			array(
				'health'     => $this->indexer->health(),
				'sources'    => $this->extractors->counts(),
				// What is available, what the merchant chose, and whether they
				// have chosen at all — the second onboarding step's whole state
				// (FR-ADMIN-02).
				'selection'  => array(
					'chosen'    => $this->selection->chosen(),
					'available' => $this->selection->describe(),
				),
				'queue'      => $this->scheduler->health(),
				'active'     => null === $active ? null : $this->present_run( $active ),
				'recentRuns' => $recent,
			)
		);
	}

	/**
	 * Record which sources the crew may read.
	 *
	 * Deselecting purges what was already indexed, in the same request. The
	 * alternative — store the flag, leave the rows — means the agent keeps
	 * quoting content the merchant has just excluded, with the console showing
	 * the exclusion as done. The response says how much was removed rather than
	 * doing it quietly.
	 */
	public function save_sources( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) || ! isset( $body['sources'] ) || ! is_array( $body['sources'] ) ) {
			return $this->error( 'invalid_body', __( 'Expected a "sources" array.', 'storecrew' ) );
		}

		$result = $this->selection->save( array_map( 'strval', $body['sources'] ) );

		$removed_sources = 0;
		$removed_chunks  = 0;

		foreach ( $result['removed'] as $type ) {
			$purged = $this->indexer->forget_type( $type );

			$removed_sources += $purged['sources'];
			$removed_chunks  += $purged['chunks'];
		}

		return $this->ok(
			array(
				'selected' => $result['selected'],
				'removed'  => $result['removed'],
				'purged'   => array(
					'sources' => $removed_sources,
					'chunks'  => $removed_chunks,
				),
			)
		);
	}

	public function estimate(): \WP_REST_Response {
		return $this->ok( $this->indexer->estimate() );
	}

	public function start(): \WP_REST_Response|\WP_Error {
		if ( ! $this->scheduler->is_available() ) {
			return $this->error(
				'queue_unavailable',
				__( 'Background processing is unavailable. WooCommerce provides the queue StoreCrew uses.', 'storecrew' ),
				503
			);
		}

		$run_id = $this->index_job->start();

		if ( 0 === $run_id ) {
			return $this->error(
				'already_running',
				__( 'An index run is already in progress.', 'storecrew' ),
				409
			);
		}

		return $this->ok( array( 'runId' => $run_id ), 202 );
	}

	public function cancel(): \WP_REST_Response|\WP_Error {
		$active = $this->runs->active();

		if ( null === $active ) {
			return $this->error( 'nothing_running', __( 'No index run is in progress.', 'storecrew' ), 409 );
		}

		$this->index_job->cancel( (int) $active->id );

		return $this->ok(
			array(
				'runId'     => (int) $active->id,
				'cancelled' => true,
			)
		);
	}

	/**
	 * Kick the embedding drain manually.
	 *
	 * Useful after fixing a key or raising a spend cap: the automatic pass has
	 * already stopped and there is nothing scheduled to notice the problem is
	 * gone.
	 */
	public function embed(): \WP_REST_Response {
		$this->embed_job->start();

		return $this->ok( array( 'queued' => true ), 202 );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present_run( object $run ): array {
		return array(
			'id'         => (int) $run->id,
			'type'       => (string) $run->type,
			'status'     => (string) $run->status,
			'total'      => (int) $run->total,
			'processed'  => (int) $run->processed,
			'failed'     => (int) $run->failed,
			// Derived from the heartbeat, not the stored status — a killed
			// process leaves `running` behind forever.
			'alive'      => $this->runs->is_alive( $run ),
			'costMicros' => (int) $run->cost_micros,
			'startedAt'  => (string) $run->started_at,
			'finishedAt' => null === $run->finished_at ? null : (string) $run->finished_at,
			'lastError'  => null === $run->last_error ? null : (string) $run->last_error,
		);
	}
}
