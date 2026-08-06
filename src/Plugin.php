<?php
/**
 * Plugin kernel.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew;

use StoreCrew\Api\ExtensionApi;
use StoreCrew\Api\Feature;
use StoreCrew\Api\Registry\AdminRouteRegistry;
use StoreCrew\Api\Registry\FeatureRegistry;
use StoreCrew\Core\Container\Container;
use StoreCrew\Licensing\FeatureGate;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the container, opens the extension API, and closes it again.
 *
 * The kernel does very little on purpose. Its whole job is to establish a
 * deterministic registration window — open at plugins_loaded 10, frozen at 20 —
 * so that everything downstream reads a stable set of contributions.
 *
 * @see docs/15-free-premium-split.md § 3.1
 */
final class Plugin {

	private static ?self $instance = null;

	private Container $container;

	private ExtensionApi $api;

	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * The kernel instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * The service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * The extension API.
	 */
	public function api(): ExtensionApi {
		return $this->api;
	}

	/**
	 * Boot the plugin. Called on plugins_loaded at priority 5.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->register_services();
		$this->register_core_features();

		$this->api = $this->container->get( ExtensionApi::class );

		// Registration window. Add-ons hook storecrew_api_ready at priority 10;
		// adding these from inside plugins_loaded:5 is safe because WordPress
		// re-reads the callback list as it walks the remaining priorities.
		add_action( 'plugins_loaded', array( $this->api, 'open' ), 10 );
		add_action( 'plugins_loaded', array( $this->api, 'freeze' ), 20 );

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Register core service definitions.
	 */
	private function register_services(): void {
		$this->container->set(
			FeatureRegistry::class,
			static fn (): FeatureRegistry => new FeatureRegistry()
		);

		$this->container->set(
			AdminRouteRegistry::class,
			static fn (): AdminRouteRegistry => new AdminRouteRegistry()
		);

		$this->container->set(
			FeatureGate::class,
			static fn ( Container $c ): FeatureGate => new FeatureGate(
				$c->get( FeatureRegistry::class ),
				$c->get( AdminRouteRegistry::class )
			)
		);

		$this->container->set(
			ExtensionApi::class,
			static fn ( Container $c ): ExtensionApi => new ExtensionApi(
				$c,
				$c->get( FeatureRegistry::class ),
				$c->get( AdminRouteRegistry::class )
			)
		);
	}

	/**
	 * Register the features this plugin owns.
	 *
	 * Only free-tier features are declared here. Premium and Agency features are
	 * registered by the plugins that implement them — the free plugin does not
	 * enumerate capabilities it cannot deliver. Upgrade messaging is handled by
	 * a dedicated upsell screen rather than by seeding the registry with slugs
	 * that resolve to nothing.
	 *
	 * @see docs/15-free-premium-split.md § 7 (storecrew.noProReferenceInFree)
	 */
	private function register_core_features(): void {
		$features = $this->container->get( FeatureRegistry::class );

		$features->register(
			new Feature(
				slug: 'agent.sales',
				label: __( 'Sales agent', 'storecrew' ),
				tier: Feature::TIER_FREE,
				description: __( 'Finds products, answers pre-purchase questions, and suggests alternatives.', 'storecrew' ),
			)
		);

		$features->register(
			new Feature(
				slug: 'agent.support',
				label: __( 'Support agent', 'storecrew' ),
				tier: Feature::TIER_FREE,
				description: __( 'Resolves order, delivery, and returns enquiries.', 'storecrew' ),
			)
		);

		$features->register(
			new Feature(
				slug: 'knowledge.base',
				label: __( 'Knowledge base', 'storecrew' ),
				tier: Feature::TIER_FREE,
				description: __( 'Indexes products, pages, and policies for grounded answers.', 'storecrew' ),
			)
		);

		$features->register(
			new Feature(
				slug: 'chat.widget',
				label: __( 'Storefront chat', 'storecrew' ),
				tier: Feature::TIER_FREE,
				description: __( 'The customer-facing chat widget.', 'storecrew' ),
			)
		);
	}

	/**
	 * Load translations.
	 *
	 * On `init` rather than `plugins_loaded`: WordPress 6.7 warns when a text
	 * domain is loaded before the point translations are actually available.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'storecrew',
			false,
			dirname( STORECREW_BASENAME ) . '/languages'
		);
	}
}
