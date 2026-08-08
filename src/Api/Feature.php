<?php
/**
 * A gateable feature.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Declares a unit of functionality that entitlement can switch on or off.
 *
 * Features are the only currency of gating in StoreCrew. Nothing checks a tier
 * name directly; code asks whether a feature slug is enabled. That keeps tier
 * definitions in one place and makes re-packaging (moving a capability between
 * Free, Pro, and Agency) a configuration change rather than a code change —
 * which matters because PRD open questions 1 and 3 are still unresolved.
 *
 * @see docs/15-free-premium-split.md § 4.2
 *
 * @api
 */
final readonly class Feature {

	public const TIER_FREE   = 'free';
	public const TIER_PRO    = 'pro';
	public const TIER_AGENCY = 'agency';

	/**
	 * @param string $slug        Stable identifier, e.g. "agent.marketing".
	 * @param string $label       Human-readable name for the admin UI.
	 * @param string $tier        Minimum tier required. One of the TIER_* constants.
	 * @param string $description Short explanation, shown in upgrade prompts.
	 */
	public function __construct(
		public string $slug,
		public string $label,
		public string $tier = self::TIER_FREE,
		public string $description = '',
	) {
		if ( '' === trim( $slug ) ) {
			throw new \InvalidArgumentException( 'Feature slug cannot be empty.' );
		}

		if ( ! in_array( $tier, self::tiers(), true ) ) {
			throw new \InvalidArgumentException(
				esc_html( sprintf( 'Unknown tier "%s" for feature "%s".', $tier, $slug ) )
			);
		}
	}

	/**
	 * Valid tiers, lowest to highest.
	 *
	 * @return list<string>
	 */
	public static function tiers(): array {
		return array( self::TIER_FREE, self::TIER_PRO, self::TIER_AGENCY );
	}

	/**
	 * Whether this feature is satisfied by a given tier.
	 *
	 * Tiers are cumulative: agency satisfies pro, pro satisfies free.
	 */
	public function satisfied_by( string $tier ): bool {
		$order   = self::tiers();
		$have    = array_search( $tier, $order, true );
		$require = array_search( $this->tier, $order, true );

		if ( false === $have || false === $require ) {
			return false;
		}

		return $have >= $require;
	}

	/**
	 * Serialisable shape for the SPA capability manifest.
	 *
	 * @return array{slug: string, label: string, tier: string, description: string}
	 */
	public function to_array(): array {
		return array(
			'slug'        => $this->slug,
			'label'       => $this->label,
			'tier'        => $this->tier,
			'description' => $this->description,
		);
	}
}
