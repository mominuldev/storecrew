<?php
/**
 * Onboarding step events.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core;

use StoreCrew\Core\Activation\Activator;
use StoreCrew\Database\Repositories\UsageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Records the first time each setup step was seen complete.
 *
 * The leading indicator behind "onboarding completion >= 65%" is *drop-off per
 * step* (02 § 7): knowing 40% never finish is a number, knowing they all stop at
 * the provider key is a decision. Nothing recorded that, so the only observable
 * was the binary end state.
 *
 * This does not decide anything. `Onboarding` derives completion from the thing
 * itself — a provider that resolves, vectors in the table, the widget switched
 * on — and that stays the only answer to "is this step done". What is stored
 * here is *when we first observed* a step already done, which is a different
 * fact and never feeds back into the derivation. Reintroducing a stored
 * "step 3 done" flag is the failure `Onboarding` exists to make impossible.
 *
 * Local only. Events land in the merchant's own usage table and are never
 * transmitted — FR-DIST-11 gates *telemetry*, and there is none here. The
 * report is `tools/beta-metrics.php`, which reads the same rows the merchant
 * can read.
 *
 * @see docs/02-product-strategy.md § 7
 * @see docs/14-milestone-plan.md § M2
 */
final class SetupProgress {

	/**
	 * Which steps have already been emitted. Not autoloaded: it is read on
	 * `/bootstrap` and nowhere else, and an admin-only fact has no business
	 * loading on storefront requests.
	 */
	public const OPTION = 'storecrew_setup_progress';

	/**
	 * Written into the ledger when the flow was already finished the first time
	 * this instrument ran.
	 */
	public const BACKFILLED = 'backfilled';

	public function __construct( private readonly UsageRepository $usage ) {}

	/**
	 * Stamp any step now seen complete for the first time.
	 *
	 * @param array{steps: list<array{id: string, done: bool}>, complete: bool} $state Derived state.
	 */
	public function observe( array $state ): void {
		$ledger = $this->ledger();

		if ( in_array( self::BACKFILLED, $ledger, true ) ) {
			return;
		}

		// An install that was already finished before this shipped has step
		// times we do not know. Stamping them all at the first observation would
		// report a five-second onboarding and pull every fleet average down with
		// a figure nobody lived through — the fabricated-zero defect wearing a
		// different hat. Unknown is recorded as unknown, and the install is
		// excluded rather than guessed at.
		if ( array() === $ledger && ( $state['complete'] ?? false ) ) {
			update_option( self::OPTION, array( self::BACKFILLED ), false );

			return;
		}

		$emitted = false;

		foreach ( $state['steps'] as $step ) {
			if ( ! $step['done'] || in_array( $step['id'], $ledger, true ) ) {
				continue;
			}

			$this->usage->record( self::metric( $step['id'] ) );

			$ledger[] = $step['id'];
			$emitted  = true;
		}

		if ( $emitted ) {
			update_option( self::OPTION, $ledger, false );
		}
	}

	/**
	 * Metric name for a step.
	 *
	 * Namespaced rather than one metric with the step in a column: `metric` is
	 * indexed with `period` and the columns beside it mean specific things —
	 * putting a step id in `agent_id` would make the next reader of that column
	 * wrong. The longest name is 18 characters against a `varchar(32)`.
	 */
	public static function metric( string $step ): string {
		return UsageRepository::METRIC_SETUP_STEP . '.' . $step;
	}

	/**
	 * Steps already emitted, or `[BACKFILLED]`.
	 *
	 * @return list<string>
	 */
	public function ledger(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_values( array_filter( $stored, 'is_string' ) );
	}

	/**
	 * When the clock started: first activation, as a unix timestamp.
	 *
	 * The measurement protocol in 14 § M1 starts timing at the Activate click,
	 * not at the first admin page load, because the redirect into setup is part
	 * of what is being measured.
	 */
	public static function started_at(): int {
		return (int) get_option( Activator::OPTION_ACTIVATED_AT, 0 );
	}
}
