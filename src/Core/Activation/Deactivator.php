<?php
/**
 * Deactivation routine.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core\Activation;

defined( 'ABSPATH' ) || exit;

/**
 * Runs when the plugin is deactivated.
 *
 * Deactivation is reversible and must stay that way: no tables dropped, no
 * options deleted, no capabilities revoked. A merchant deactivating to debug a
 * conflict should get everything back on reactivation. Destruction belongs in
 * uninstall.php, behind an explicit opt-in (FR-CORE-08).
 */
final class Deactivator {

	/**
	 * Deactivate.
	 */
	public static function deactivate(): void {
		// Scheduled work is cancelled so a deactivated plugin does not keep
		// consuming the merchant's Action Scheduler queue.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), \StoreCrew\Core\Queue\Scheduler::GROUP );
		}

		/**
		 * Fires after StoreCrew deactivation completes.
		 */
		do_action( 'storecrew_deactivated' );
	}
}
