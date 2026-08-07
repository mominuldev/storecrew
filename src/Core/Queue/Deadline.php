<?php
/**
 * Soft time budget for a batch.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Tells a job when to stop and reschedule.
 *
 * R-TECH-03: budget hosts kill PHP without warning, and a job killed mid-batch
 * loses whatever it had not committed. Rather than racing the host's limit, a
 * batch stops well short of it and schedules its own continuation — a job that
 * chooses to stop leaves a clean cursor, a job that gets killed does not.
 *
 * The budget is derived from the host's own `max_execution_time` where it
 * reports one, because a fixed number would be reckless on a 30-second host
 * and wasteful on an unlimited one.
 */
final class Deadline {

	/**
	 * Fraction of the host's limit we are willing to use.
	 */
	private const SAFETY_FACTOR = 0.6;

	/**
	 * Used when the host reports no limit (CLI, or `max_execution_time = 0`).
	 */
	private const UNLIMITED_BUDGET = 45;

	private const MIN_BUDGET = 5;
	private const MAX_BUDGET = 60;

	private float $started;

	private int $budget;

	public function __construct( ?int $budget_seconds = null ) {
		$this->started = microtime( true );
		$this->budget  = $budget_seconds ?? self::detect_budget();
	}

	/**
	 * Seconds this batch may run for.
	 */
	public static function detect_budget(): int {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( $limit <= 0 ) {
			return self::UNLIMITED_BUDGET;
		}

		return (int) max(
			self::MIN_BUDGET,
			min( self::MAX_BUDGET, floor( $limit * self::SAFETY_FACTOR ) )
		);
	}

	public function budget(): int {
		return $this->budget;
	}

	public function elapsed(): float {
		return microtime( true ) - $this->started;
	}

	/**
	 * Whether the batch should stop now.
	 */
	public function exceeded(): bool {
		return $this->elapsed() >= $this->budget;
	}

	/**
	 * Whether there is room for another unit of work.
	 *
	 * Takes an estimate of how long that unit takes, so a job stops *before*
	 * starting work it cannot finish rather than after.
	 */
	public function has_room_for( float $estimated_seconds = 1.0 ): bool {
		return ( $this->elapsed() + $estimated_seconds ) < $this->budget;
	}
}
