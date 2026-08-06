<?php
/**
 * Server-authoritative feature entitlement.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Licensing;

use StoreCrew\Api\Feature;
use StoreCrew\Api\Registry\AdminRouteRegistry;
use StoreCrew\Api\Registry\FeatureRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a feature is available on this installation.
 *
 * The free plugin computes free-tier truth and nothing more. It has no licence
 * client and makes no network calls — that lives entirely in the premium
 * plugin, which grants higher tiers by filtering `storecrew_feature_enabled`.
 * This is what keeps the free plugin compliant with the WordPress.org rule
 * against calling home (FR-DIST-11), and why the free plugin genuinely does not
 * know premium exists.
 *
 * An unregistered slug is denied rather than allowed. A typo in a feature check
 * should hide a feature, never expose one.
 *
 * @see docs/15-free-premium-split.md § 4.2, § 5
 */
final class FeatureGate {

	/**
	 * Memoised per-request decisions.
	 *
	 * @var array<string, bool>
	 */
	private array $cache = array();

	public function __construct(
		private readonly FeatureRegistry $features,
		private readonly AdminRouteRegistry $routes,
	) {}

	/**
	 * Whether a feature is enabled for this installation.
	 */
	public function enabled( string $slug ): bool {
		if ( isset( $this->cache[ $slug ] ) ) {
			return $this->cache[ $slug ];
		}

		$feature = $this->features->get( $slug );

		// Unknown feature: deny, and say so loudly in development.
		if ( ! $feature instanceof Feature ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				trigger_error(
					sprintf(
						'StoreCrew: checked unregistered feature "%s". Register it via storecrew_register_features.',
						esc_html( $slug )
					),
					E_USER_WARNING
				);
			}

			return $this->cache[ $slug ] = false;
		}

		$enabled = $feature->satisfied_by( Feature::TIER_FREE );

		/**
		 * Final authority on whether a feature is active.
		 *
		 * The premium plugin filters this to grant Pro and Agency features once
		 * it has validated a licence. Third-party add-ons may do the same for
		 * features they own.
		 *
		 * @param bool    $enabled Whether the free plugin considers it enabled.
		 * @param string  $slug    Feature slug.
		 * @param Feature $feature The feature definition.
		 */
		$enabled = (bool) apply_filters( 'storecrew_feature_enabled', $enabled, $slug, $feature );

		return $this->cache[ $slug ] = $enabled;
	}

	/**
	 * Capability manifest serialised to the admin SPA.
	 *
	 * This is a rendering hint only. Every REST controller behind a gated screen
	 * re-checks entitlement itself, so editing this payload in the browser
	 * yields an empty panel and a 403 — not access.
	 *
	 * @return array{
	 *     features: array<string, bool>,
	 *     catalog: list<array{slug: string, label: string, tier: string, description: string}>,
	 *     routes: list<array<string, mixed>>
	 * }
	 */
	public function manifest(): array {
		$features = array();
		$catalog  = array();

		foreach ( $this->features->all() as $slug => $feature ) {
			$features[ $slug ] = $this->enabled( $slug );
			$catalog[]         = $feature->to_array();
		}

		$routes = array();

		foreach ( $this->routes->sorted() as $route ) {
			if ( ! current_user_can( $route->capability ) ) {
				continue;
			}

			if ( null !== $route->feature && ! $this->enabled( $route->feature ) ) {
				// Still advertised, so the SPA can render an upgrade prompt in
				// place of the screen rather than a dead link.
				$routes[] = $route->to_array() + array( 'locked' => true );

				continue;
			}

			$routes[] = $route->to_array() + array( 'locked' => false );
		}

		$manifest = array(
			'features' => $features,
			'catalog'  => $catalog,
			'routes'   => $routes,
		);

		/**
		 * The entitlement payload handed to the admin application.
		 *
		 * @param array $manifest Manifest as described above.
		 */
		return apply_filters( 'storecrew_capability_manifest', $manifest );
	}
}
