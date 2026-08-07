<?php
/**
 * The published extension surface.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api;

use StoreCrew\Api\Registry\AdminRouteRegistry;
use StoreCrew\Api\Registry\ControllerRegistry;
use StoreCrew\Api\Registry\ExtractorRegistry;
use StoreCrew\Api\Registry\FeatureRegistry;
use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Core\Container\Container;

defined( 'ABSPATH' ) || exit;

/**
 * The object add-ons receive on `storecrew_api_ready`.
 *
 * This is the entire contract between StoreCrew and anything that extends it,
 * premium included. If the premium plugin needs something that is not reachable
 * from here, the correct fix is to widen this class — never to reach into free
 * plugin internals. That rule is what keeps the API honest rather than
 * theoretical: premium is simply the first consumer of a surface anyone can use.
 *
 * Registration happens in one window. `open()` runs at plugins_loaded priority
 * 10; `freeze()` runs at priority 20. After that the contributed set is fixed,
 * which is what lets REST routes and the capability manifest be computed from a
 * stable input.
 *
 * @see docs/15-free-premium-split.md § 3.1, § 4
 */
final class ExtensionApi {

	private bool $opened = false;

	public function __construct(
		private readonly Container $container,
		private readonly FeatureRegistry $features,
		private readonly AdminRouteRegistry $admin_routes,
		private readonly ProviderRegistry $providers,
		private readonly ExtractorRegistry $extractors,
		private readonly ControllerRegistry $controllers,
	) {}

	/**
	 * The extension API contract version.
	 *
	 * Add-ons compare against this rather than the product version.
	 */
	public function version(): string {
		return STORECREW_API_VERSION;
	}

	/**
	 * The service container. Add-ons register their own services here.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Registry of gateable features.
	 */
	public function features(): FeatureRegistry {
		return $this->features;
	}

	/**
	 * Registry of admin SPA routes.
	 */
	public function admin_routes(): AdminRouteRegistry {
		return $this->admin_routes;
	}

	/**
	 * Registry of AI providers.
	 */
	public function providers(): ProviderRegistry {
		return $this->providers;
	}

	/**
	 * Registry of knowledge-base extractors.
	 */
	public function extractors(): ExtractorRegistry {
		return $this->extractors;
	}

	/**
	 * Registry of REST controllers.
	 */
	public function controllers(): ControllerRegistry {
		return $this->controllers;
	}

	/**
	 * Open the registration window.
	 *
	 * Fires the entry-point action, then applies each registry filter. Both
	 * styles are supported deliberately: an add-on can take the API object from
	 * the action and register imperatively, or hook a single registry filter if
	 * that is all it needs.
	 */
	public function open(): void {
		if ( $this->opened ) {
			return;
		}

		$this->opened = true;

		/**
		 * Fires once the container is built and every registry is open.
		 *
		 * This is the entry point for all extensions. Registering earlier is
		 * unsupported; registering later is rejected by the frozen registries.
		 *
		 * @param ExtensionApi $api The extension API.
		 */
		do_action( 'storecrew_api_ready', $this );

		/**
		 * Contribute service definitions to the container.
		 *
		 * @param Container $container The service container.
		 */
		apply_filters( 'storecrew_register_container', $this->container );

		/**
		 * Contribute gateable features.
		 *
		 * @param FeatureRegistry $features The feature registry.
		 */
		apply_filters( 'storecrew_register_features', $this->features );

		/**
		 * Contribute admin SPA routes.
		 *
		 * @param AdminRouteRegistry $admin_routes The admin route registry.
		 */
		apply_filters( 'storecrew_register_admin_routes', $this->admin_routes );

		/**
		 * Contribute AI providers.
		 *
		 * @param ProviderRegistry $providers The provider registry.
		 */
		apply_filters( 'storecrew_register_providers', $this->providers );

		/**
		 * Contribute knowledge-base extractors.
		 *
		 * @param ExtractorRegistry $extractors The extractor registry.
		 */
		apply_filters( 'storecrew_register_extractors', $this->extractors );

		/**
		 * Contribute REST controllers to the storecrew/v1 namespace.
		 *
		 * @param ControllerRegistry $controllers The controller registry.
		 */
		apply_filters( 'storecrew_register_rest_controllers', $this->controllers );
	}

	/**
	 * Close every registry to further writes.
	 */
	public function freeze(): void {
		$this->features->freeze();
		$this->admin_routes->freeze();
		$this->providers->freeze();
		$this->extractors->freeze();
		$this->controllers->freeze();

		/**
		 * Fires after every registry has been frozen.
		 *
		 * Safe point to read the final contributed set — for example to build
		 * REST routes or compute a capability manifest.
		 *
		 * @param ExtensionApi $api The extension API.
		 */
		do_action( 'storecrew_api_frozen', $this );
	}

	/**
	 * Diagnostic snapshot of what was contributed and by whom.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function contributions(): array {
		$snapshot = array();

		$registries = array(
			'features'     => $this->features,
			'admin_routes' => $this->admin_routes,
			'providers'    => $this->providers,
			'extractors'   => $this->extractors,
			'controllers'  => $this->controllers,
		);

		foreach ( $registries as $key => $registry ) {
			$owners = array();

			foreach ( array_keys( $registry->all() ) as $id ) {
				$owners[ $id ] = (string) $registry->owner( $id );
			}

			$snapshot[ $key ] = $owners;
		}

		return $snapshot;
	}
}
