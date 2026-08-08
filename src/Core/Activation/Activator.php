<?php
/**
 * Activation routine.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core\Activation;

use StoreCrew\Core\Capabilities\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated.
 *
 * Deliberately minimal. Schema creation belongs to the migration runner, which
 * also runs on upgrade — doing it here would mean a site that updated by
 * uploading files never got its tables. Activation records intent; the migrator
 * reconciles state.
 *
 * FR-CORE-04, FR-CORE-05.
 */
final class Activator {

	public const OPTION_ACTIVATED_AT   = 'storecrew_activated_at';
	public const OPTION_SETUP_REDIRECT = 'storecrew_setup_redirect';

	/**
	 * Activate.
	 */
	public static function activate(): void {
		Capabilities::install();

		$first_ever = ! get_option( self::OPTION_ACTIVATED_AT );

		if ( $first_ever ) {
			add_option( self::OPTION_ACTIVATED_AT, time() );

			// Hand the merchant straight to the setup flow on the next admin
			// request (FR-ADMIN-02). A flag rather than a redirect: activation
			// runs in a request WordPress is already redirecting, and headers
			// sent from here fight it. `AdminPage` consumes this exactly once.
			//
			// Only on a *first* activation. Someone toggling the plugin off and
			// on to clear a cache has already been through setup, and throwing
			// them back to step one reads as the plugin having forgotten them.
			add_option( self::OPTION_SETUP_REDIRECT, '1' );
		}

		// Nothing is recorded about the upgrade here, and that is the whole
		// design. The migrator reconciles schema on the next admin request — a
		// fatal during schema work here would leave activation half-done with no
		// way to retry — and it gates on the version comparison, which is the
		// only answer that also holds for the merchant who updates from
		// WordPress.org or over FTP and never reaches this hook at all. Both
		// options that used to be written here were absent or stale on exactly
		// that path, and read by nothing (Migration003, Migration004). The
		// running version is `STORECREW_VERSION`, which cannot go stale.

		/**
		 * Fires after StoreCrew activation completes.
		 */
		do_action( 'storecrew_activated' );
	}
}
