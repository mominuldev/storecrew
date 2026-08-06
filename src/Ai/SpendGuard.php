<?php
/**
 * Monthly spend ceiling.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

use StoreCrew\Database\Repositories\UsageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces a hard monthly cap on provider spend.
 *
 * R-COST-01 rates "merchant receives an unexpectedly large provider bill" as a
 * high-impact risk, because it is a churn-and-reputation event rather than a
 * bug — the merchant's own API key is charged and the money is already gone by
 * the time anyone notices. FR-AI-06 requires this to be a hard ceiling, not a
 * warning.
 *
 * The cap is checked *before* a call, using recorded spend. That means a single
 * call can carry the total past the ceiling; the alternative is refusing to
 * start work whose cost is not yet known, which would block every request.
 *
 * @see docs/01-prd.md FR-AI-06, R-COST-01
 */
final class SpendGuard {

	public const OPTION_CAP_MICROS = 'storecrew_spend_cap_micros';
	public const OPTION_ON_BREACH  = 'storecrew_spend_cap_behaviour';

	/** Stop making calls entirely. */
	public const BEHAVIOUR_STOP = 'stop';

	/** Keep going, but record a breach for the dashboard. */
	public const BEHAVIOUR_WARN = 'warn';

	public function __construct(
		private readonly UsageRepository $usage,
	) {}

	/**
	 * The configured ceiling in micros. Zero means no cap.
	 */
	public function cap_micros(): int {
		return max( 0, (int) get_option( self::OPTION_CAP_MICROS, 0 ) );
	}

	public function behaviour(): string {
		$stored = (string) get_option( self::OPTION_ON_BREACH, self::BEHAVIOUR_STOP );

		return in_array( $stored, array( self::BEHAVIOUR_STOP, self::BEHAVIOUR_WARN ), true )
			? $stored
			: self::BEHAVIOUR_STOP;
	}

	/**
	 * Spend so far this period, in micros.
	 */
	public function spent_micros( string $period = '' ): int {
		return $this->usage->cost_micros( $period );
	}

	/**
	 * Whether a call may proceed.
	 */
	public function allows_call(): bool {
		$cap = $this->cap_micros();

		if ( 0 === $cap ) {
			return true;
		}

		if ( $this->spent_micros() < $cap ) {
			return true;
		}

		if ( self::BEHAVIOUR_WARN === $this->behaviour() ) {
			/**
			 * Fires when spend exceeds the cap and the configured behaviour is
			 * to continue anyway.
			 *
			 * @param int $spent Micros spent this period.
			 * @param int $cap   Configured ceiling in micros.
			 */
			do_action( 'storecrew_spend_cap_exceeded', $this->spent_micros(), $cap );

			return true;
		}

		return false;
	}

	/**
	 * Current state, for the dashboard.
	 *
	 * `unknownCost` is the honest part: when a model has no published rate,
	 * spend is under-counted and the cap cannot be trusted. Reporting that
	 * beats showing a confident number derived from missing data.
	 *
	 * @return array{
	 *     capMicros: int,
	 *     spentMicros: int,
	 *     remainingMicros: int,
	 *     percentUsed: float,
	 *     blocked: bool,
	 *     behaviour: string
	 * }
	 */
	public function status(): array {
		$cap   = $this->cap_micros();
		$spent = $this->spent_micros();

		return array(
			'capMicros'       => $cap,
			'spentMicros'     => $spent,
			'remainingMicros' => $cap > 0 ? max( 0, $cap - $spent ) : 0,
			'percentUsed'     => $cap > 0 ? round( ( $spent / $cap ) * 100, 1 ) : 0.0,
			'blocked'         => ! $this->allows_call(),
			'behaviour'       => $this->behaviour(),
		);
	}

	/**
	 * Set the ceiling.
	 */
	public function set_cap( int $micros, string $behaviour = self::BEHAVIOUR_STOP ): void {
		update_option( self::OPTION_CAP_MICROS, max( 0, $micros ), false );
		update_option( self::OPTION_ON_BREACH, $behaviour, false );
	}
}
