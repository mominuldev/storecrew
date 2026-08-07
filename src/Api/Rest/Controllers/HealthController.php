<?php
/**
 * System health.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Rest\Controllers;

use StoreCrew\Ai\SpendGuard;
use StoreCrew\Api\Rest\RestController;
use StoreCrew\Core\Capabilities\Capabilities;
use StoreCrew\Core\Queue\Scheduler;
use StoreCrew\Database\Repositories\IndexRunRepository;
use StoreCrew\Knowledge\Indexer;
use StoreCrew\Licensing\FeatureGate;
use StoreCrew\Security\SecretStore;

defined( 'ABSPATH' ) || exit;

/**
 * What is working, what is not, and what stopped without saying so.
 *
 * FR-ADMIN-08 requires job and index health on the screen an operator actually
 * opens, because the failure mode merchants hit is not a crash — it is a job
 * that died hours ago while the dashboard still reports it as running. Every
 * "running" state here is judged by heartbeat rather than by stored status.
 */
final class HealthController extends RestController {

	public function __construct(
		FeatureGate $features,
		private readonly Scheduler $scheduler,
		private readonly Indexer $indexer,
		private readonly IndexRunRepository $runs,
		private readonly SpendGuard $spend,
		private readonly SecretStore $secrets,
	) {
		parent::__construct( $features );
	}

	public function register_routes(): void {
		$this->route(
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_health' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
			)
		);
	}

	public function get_health(): \WP_REST_Response {
		$active = $this->runs->active();

		return $this->ok(
			array(
				'environment' => array(
					'php'         => PHP_VERSION,
					'wordpress'   => get_bloginfo( 'version' ),
					'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
					'hpos'        => $this->hpos_enabled(),
				),
				'queue'       => $this->scheduler->health(),
				'index'       => $this->indexer->health(),
				'indexRun'    => null === $active ? null : array(
					'id'        => (int) $active->id,
					'status'    => (string) $active->status,
					'total'     => (int) $active->total,
					'processed' => (int) $active->processed,
					'failed'    => (int) $active->failed,
					// Status alone would say "running" for a process the host
					// killed an hour ago. This is the honest answer.
					'alive'     => $this->runs->is_alive( $active ),
					'startedAt' => (string) $active->started_at,
				),
				'spend'       => $this->spend->status(),
				'encryption'  => $this->secrets->master_key_source(),
			)
		);
	}

	/**
	 * Whether WooCommerce is using High-Performance Order Storage.
	 *
	 * Reported because it changes how orders must be read, and a support
	 * conversation that starts by asking is a wasted round trip.
	 */
	private function hpos_enabled(): ?bool {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return null;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
