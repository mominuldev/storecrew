<?php
/**
 * SPA bootstrap payload.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Rest\Controllers;

use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Api\Rest\RestController;
use StoreCrew\Core\Capabilities\Capabilities;
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
		private readonly ProviderRegistry $providers,
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
		$manifest = $this->features->manifest();

		return $this->ok(
			array(
				'version'    => STORECREW_VERSION,
				'apiVersion' => STORECREW_API_VERSION,
				'features'   => $manifest['features'],
				'catalog'    => $manifest['catalog'],
				'routes'     => $manifest['routes'],
				'onboarding' => $this->onboarding_state(),
				'user'       => array(
					'canManage'    => current_user_can( Capabilities::MANAGE ),
					'canViewStats' => current_user_can( Capabilities::VIEW_ANALYTICS ),
					'canEditAgents' => current_user_can( Capabilities::MANAGE_AGENTS ),
				),
			)
		);
	}

	/**
	 * What still needs doing before the product works.
	 *
	 * Surfaced as structured state rather than prose so the SPA can route a new
	 * merchant to the specific step that is blocking them. The
	 * `canEmbed` flag is the one that catches people out: an Anthropic-only
	 * install has working chat and cannot index anything, and discovering that
	 * when indexing silently produces nothing is a bad first hour.
	 *
	 * @return array{
	 *     hasProvider: bool,
	 *     canEmbed: bool,
	 *     configuredProviders: list<string>,
	 *     complete: bool
	 * }
	 */
	private function onboarding_state(): array {
		$configured = array_keys( $this->providers->configured() );

		return array(
			'hasProvider'         => array() !== $configured,
			'canEmbed'            => $this->providers->can_embed(),
			'configuredProviders' => array_map( 'strval', $configured ),
			'complete'            => array() !== $configured && $this->providers->can_embed(),
		);
	}
}
