<?php
/**
 * SPA bootstrap payload.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Rest\Controllers;

use StoreCrew\Api\Rest\RestController;
use StoreCrew\Core\Capabilities\Capabilities;
use StoreCrew\Core\Onboarding;
use StoreCrew\Core\SetupProgress;
use StoreCrew\Licensing\FeatureGate;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the admin application needs on first paint.
 *
 * One request rather than five, because the SPA cannot render its navigation
 * until it knows which routes exist and which are entitled, and four sequential
 * round trips to find that out is a visible delay on every admin page load.
 *
 * The capability manifest here is a **rendering hint**. Every gated controller
 * re-checks entitlement itself, so editing this payload in the browser yields
 * an empty panel and a 403, not access (FR-DIST-09).
 */
final class BootstrapController extends RestController {

	public function __construct(
		FeatureGate $features,
		private readonly Onboarding $onboarding,
		private readonly SetupProgress $progress,
	) {
		parent::__construct( $features );
	}

	public function register_routes(): void {
		$this->route(
			'/bootstrap',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_bootstrap' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
			)
		);
	}

	public function get_bootstrap(): \WP_REST_Response {
		$manifest   = $this->features->manifest();
		$onboarding = $this->onboarding->state();

		// The observation point, and the reason the resolution is good enough to
		// measure with: the setup screen invalidates `bootstrap` after every step
		// action, so a step is seen complete within a request of the click that
		// completed it. The index step is the exception — it finishes when a
		// background job produces the first vector, and is caught by that
		// screen's 3-second poll while embedding is pending.
		$this->progress->observe( $onboarding );

		return $this->ok(
			array(
				'version'    => STORECREW_VERSION,
				'apiVersion' => STORECREW_API_VERSION,
				'features'   => $manifest['features'],
				'catalog'    => $manifest['catalog'],
				'routes'     => $manifest['routes'],
				'onboarding' => $onboarding,
				'user'       => array(
					'canManage'     => current_user_can( Capabilities::MANAGE ),
					'canViewStats'  => current_user_can( Capabilities::VIEW_ANALYTICS ),
					'canEditAgents' => current_user_can( Capabilities::MANAGE_AGENTS ),
				),
			)
		);
	}
}
