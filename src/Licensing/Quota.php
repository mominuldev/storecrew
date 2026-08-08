<?php
/**
 * Tier quota resolution.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "how many is this installation allowed", where FeatureGate answers
 * "is this feature on".
 *
 * The two are deliberately separate readers. FeatureGate maps slugs to
 * booleans by tier and nothing else — mixing numbers into it is how the first
 * draft of 10 § 2 went wrong. A quota is a number, `null` means unlimited,
 * and the free plugin knows only the free tier's shape; premium loosens it
 * through the filter below once a licence is validated.
 *
 * The filter is loosen-only, for the same one-direction reason as
 * `storecrew_feature_enabled` (grant-only) and `storecrew_tool_authorized`
 * (deny-only): a lapsed licence degrades **to** free, never below it
 * (FR-LIC-06), so no add-on may tighten a quota under the free tier's own
 * number. A filter that returns less than the free default is clamped back
 * up — the failure mode this closes is a buggy add-on silently switching a
 * merchant's storefront chat off. Merchants who want to spend less have the
 * spend cap; this number is a tier shape, not a cost control.
 *
 * @see docs/10-saas-subscription.md § 2.2, § 5
 */
final class Quota {

	/**
	 * Conversations answered per calendar month (UTC), free tier.
	 *
	 * The key matches the entitlement-snapshot key exactly (10 § 2.2), for the
	 * same reason feature slugs are copied rather than paraphrased: a snapshot
	 * that spells it differently would silently grant nothing.
	 */
	public const CONVERSATIONS_MONTHLY = 'conversations.monthly';

	/**
	 * The free tier's shape (D1, 02 § 9).
	 *
	 * Every free-tier quota is a finite number by construction — a quota the
	 * free tier did not bound would not be here at all, it would be an
	 * unknown key, which resolves to unlimited below.
	 *
	 * @var array<string, int>
	 */
	private const FREE_TIER = array(
		self::CONVERSATIONS_MONTHLY => 100,
	);

	/**
	 * The effective limit for a quota key. Null means unlimited.
	 *
	 * An unknown key resolves to unlimited, loudly under WP_DEBUG — the
	 * opposite default from FeatureGate, deliberately. An unregistered feature
	 * slug hides a feature; an invented quota key, treated as a limit, would
	 * cap a storefront against a number nobody chose. Never fabricate
	 * protection (R-COST-01) applies to limits too.
	 */
	public function limit( string $key ): ?int {
		if ( ! array_key_exists( $key, self::FREE_TIER ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- deliberate WP_DEBUG-only developer warning, matching FeatureGate's unknown-slug path.
				trigger_error(
					sprintf( 'StoreCrew: read unknown quota "%s". Unknown quotas are unlimited, not zero.', esc_html( $key ) ),
					E_USER_WARNING
				);
			}

			return null;
		}

		$free = self::FREE_TIER[ $key ];

		/**
		 * The effective limit for one quota key.
		 *
		 * Loosen-only: premium raises a quota (null = unlimited) once a
		 * licence is validated. A value below the free tier's own number is
		 * clamped back to it.
		 *
		 * @param int|null $limit The free tier's limit; null means unlimited.
		 * @param string   $key   Quota key, e.g. `conversations.monthly`.
		 */
		$filtered = apply_filters( 'storecrew_quota', $free, $key );

		if ( null === $filtered ) {
			return null;
		}

		return max( (int) $filtered, $free );
	}
}
