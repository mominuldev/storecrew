<?php
/**
 * Registry of gateable features.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Registry;

use StoreCrew\Api\Feature;

defined( 'ABSPATH' ) || exit;

/**
 * Holds every feature slug the installation knows about.
 *
 * Populated via the `storecrew_register_features` filter. The free plugin
 * registers its own features; add-ons register theirs. Entitlement is resolved
 * separately by FeatureGate — this registry only answers "what exists".
 *
 * @extends Registry<Feature>
 */
final class FeatureRegistry extends Registry {

	protected function name(): string {
		return 'feature';
	}

	/**
	 * @throws \InvalidArgumentException When the item is not a Feature.
	 */
	protected function validate( mixed $item ): void {
		if ( ! $item instanceof Feature ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Expected %s, got %s.',
					Feature::class,
					get_debug_type( $item )
				)
			);
		}
	}

	/**
	 * Register a feature, keyed by its own slug.
	 *
	 * @return static
	 */
	public function register( Feature $feature, string $owner = 'storecrew' ): self {
		return $this->add( $feature->slug, $feature, $owner );
	}

	/**
	 * Features required by at least the given tier.
	 *
	 * @return array<string, Feature>
	 */
	public function for_tier( string $tier ): array {
		return array_filter(
			$this->items,
			static fn ( Feature $feature ): bool => $feature->satisfied_by( $tier )
		);
	}
}
