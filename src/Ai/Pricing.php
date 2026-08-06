<?php
/**
 * Cost estimation.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Turns token usage into money.
 *
 * **Rates for models we do not have published figures for are absent, and an
 * absent rate reports cost as unknown rather than zero.** Inventing a plausible
 * number would produce a spend dashboard that looks authoritative and is wrong,
 * and a spend cap that never trips — which is worse than no cap at all, because
 * the merchant believes they are protected. `estimate()` returns a `known` flag
 * so callers can render "not tracked" instead of "$0.00".
 *
 * Rates are dollars per million tokens, which is numerically identical to
 * micros per token — $5/MTok is 5 micros/token — so no conversion is needed.
 *
 * Merchants and add-ons override or extend the table via
 * `storecrew_model_pricing`.
 *
 * @see docs/01-prd.md FR-AI-04, R-COST-01
 */
final class Pricing {

	/**
	 * When the built-in figures were last checked against published rates.
	 * Surfaced in the admin UI so a stale table is visible rather than assumed.
	 */
	public const RATES_VERIFIED = '2026-06-24';

	/**
	 * Cache reads bill at roughly a tenth of base input.
	 */
	private const CACHE_READ_MULTIPLIER = 0.1;

	/**
	 * Cache writes bill at roughly 1.25x base input (5-minute TTL).
	 */
	private const CACHE_WRITE_MULTIPLIER = 1.25;

	/**
	 * Published rates, keyed "provider:model" => [input, output] per MTok.
	 *
	 * Only Anthropic is seeded, because those are the figures this build was
	 * written against and could verify. Everything else is deliberately unknown
	 * until a rate is supplied — see the class docblock.
	 *
	 * @return array<string, array{0: float, 1: float}>
	 */
	private static function table(): array {
		$rates = array(
			// Anthropic, verified 2026-06-24.
			'anthropic:claude-opus-5'     => array( 5.0, 25.0 ),
			'anthropic:claude-opus-4-8'   => array( 5.0, 25.0 ),
			'anthropic:claude-opus-4-7'   => array( 5.0, 25.0 ),
			// Sonnet 5 carries a lower introductory rate through 2026-08-31. The
			// standard rate is used here: over-estimating spend is the safe
			// direction for a cap, and the intro rate expiring silently would
			// otherwise under-report cost right when it doubled.
			'anthropic:claude-sonnet-5'   => array( 3.0, 15.0 ),
			'anthropic:claude-sonnet-4-6' => array( 3.0, 15.0 ),
			'anthropic:claude-haiku-4-5'  => array( 1.0, 5.0 ),
			'anthropic:claude-fable-5'    => array( 10.0, 50.0 ),
		);

		/**
		 * Filter the model pricing table.
		 *
		 * Keys are "provider:model"; values are [inputPerMTok, outputPerMTok] in
		 * dollars. Supply rates for providers this build has none for.
		 *
		 * @param array<string, array{0: float, 1: float}> $rates Rate table.
		 */
		$filtered = apply_filters( 'storecrew_model_pricing', $rates );

		return is_array( $filtered ) ? $filtered : $rates;
	}

	/**
	 * Estimate the cost of one call.
	 *
	 * @return array{micros: int, known: bool}
	 */
	public static function estimate( string $provider, string $model, TokenUsage $usage ): array {
		$rates = self::table();
		$key   = $provider . ':' . $model;

		if ( ! isset( $rates[ $key ] ) ) {
			return array(
				'micros' => 0,
				'known'  => false,
			);
		}

		[ $input_rate, $output_rate ] = $rates[ $key ];

		$micros =
			( $usage->input * $input_rate )
			+ ( $usage->cache_read * $input_rate * self::CACHE_READ_MULTIPLIER )
			+ ( $usage->cache_write * $input_rate * self::CACHE_WRITE_MULTIPLIER )
			+ ( $usage->output * $output_rate );

		return array(
			'micros' => (int) round( $micros ),
			'known'  => true,
		);
	}

	/**
	 * Whether a rate exists for a model.
	 */
	public static function is_known( string $provider, string $model ): bool {
		return isset( self::table()[ $provider . ':' . $model ] );
	}

	/**
	 * Format micros as a display string.
	 */
	public static function format( int $micros ): string {
		return '$' . number_format( $micros / 1_000_000, 2 );
	}
}
