<?php
/**
 * Background job scheduling.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over Action Scheduler.
 *
 * Action Scheduler ships with WooCommerce, so it is present wherever this
 * plugin can run at all — but every call is still guarded. A site that
 * deactivates WooCommerce mid-request must degrade to "background work is
 * unavailable" rather than fatal on an undefined function, and the admin needs
 * to be able to say which of those it is.
 *
 * Everything is scheduled into one group so a deactivation can cancel all of
 * it without touching another plugin's queue.
 *
 * @see docs/01-prd.md FR-CORE-06
 */
final class Scheduler {

	public const GROUP = 'storecrew';

	/**
	 * Whether background work can run at all.
	 */
	public function is_available(): bool {
		return function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Run a hook as soon as the queue gets to it.
	 *
	 * @param array<int, mixed> $args   Hook arguments.
	 * @param bool              $unique Skip if an identical action is already pending.
	 */
	public function enqueue( string $hook, array $args = array(), bool $unique = true ): int {
		if ( ! $this->is_available() ) {
			return 0;
		}

		if ( $unique && $this->is_pending( $hook, $args ) ) {
			return 0;
		}

		return (int) as_enqueue_async_action( $hook, $args, self::GROUP );
	}

	/**
	 * Run a hook after a delay.
	 *
	 * @param array<int, mixed> $args Hook arguments.
	 */
	public function schedule_in( int $seconds, string $hook, array $args = array(), bool $unique = true ): int {
		if ( ! $this->is_available() ) {
			return 0;
		}

		if ( $unique && $this->is_pending( $hook, $args ) ) {
			return 0;
		}

		return (int) as_schedule_single_action( time() + max( 0, $seconds ), $hook, $args, self::GROUP );
	}

	/**
	 * Ensure a recurring action exists.
	 *
	 * @param array<int, mixed> $args Hook arguments.
	 */
	public function ensure_recurring( int $interval, string $hook, array $args = array() ): int {
		if ( ! $this->is_available() || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return 0;
		}

		if ( $this->is_pending( $hook, $args ) ) {
			return 0;
		}

		return (int) as_schedule_recurring_action( time() + $interval, $interval, $hook, $args, self::GROUP );
	}

	/**
	 * Whether an identical action is already queued.
	 *
	 * Deduplication is what keeps a bulk edit sane: saving 500 products fires
	 * 500 hooks, and without this each would queue its own job for work the
	 * pending one already covers.
	 *
	 * @param array<int, mixed> $args Hook arguments.
	 */
	public function is_pending( string $hook, array $args = array() ): bool {
		if ( ! $this->is_available() ) {
			return false;
		}

		return (bool) as_has_scheduled_action( $hook, $args, self::GROUP );
	}

	/**
	 * Cancel every pending action for a hook, or the whole group.
	 *
	 * @param array<int, mixed> $args Hook arguments.
	 */
	public function cancel( string $hook = '', array $args = array() ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		if ( '' === $hook ) {
			// Cancel-by-group fast path.
			as_unschedule_all_actions( '', array(), self::GROUP );

			return;
		}

		if ( array() === $args ) {
			// Action Scheduler only takes its cancel-by-hook fast path when no
			// group is supplied. Passing one falls through to a loop that
			// matches arguments *exactly*, so an empty array there cancels only
			// actions that themselves have no arguments — silently leaving every
			// action that carries a run id or an object id still queued.
			// Our hooks are all `storecrew_`-prefixed, so cancelling by hook
			// alone cannot touch another plugin's queue.
			as_unschedule_all_actions( $hook );

			return;
		}

		as_unschedule_all_actions( $hook, $args, self::GROUP );
	}

	/**
	 * Queue health, for the dashboard.
	 *
	 * FR-ADMIN-08 wants job health on the screen an operator actually opens.
	 * "Available but nothing queued" and "not available at all" look identical
	 * from a count alone, so both are reported.
	 *
	 * @return array{available: bool, pending: int, oldest: int}
	 */
	public function health(): array {
		if ( ! $this->is_available() || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array( 'available' => false, 'pending' => 0, 'oldest' => 0 );
		}

		$pending = as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 100,
			),
			'ids'
		);

		$oldest = 0;

		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( '', null, self::GROUP );

			if ( is_int( $next ) ) {
				$oldest = $next;
			}
		}

		return array(
			'available' => true,
			'pending'   => is_array( $pending ) ? count( $pending ) : 0,
			'oldest'    => $oldest,
		);
	}
}
