<?php
/**
 * Remove the write-only upgrade flag.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Migrations;

use StoreCrew\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes `storecrew_needs_upgrade`.
 *
 * The activator wrote it, the migrator deleted it, and in between nothing ever
 * read it (Gate 2). Two things made it worse than merely unused.
 *
 * It was a second answer to a question `Migrator::needs_migration()` already
 * answers — and the wrong answer. A merchant who upgrades by uploading files
 * never fires the activation hook, so the flag stays absent on exactly the path
 * that has migrations to apply. Anything that had grown to trust it would have
 * skipped them.
 *
 * And it leaked. The delete lived at the end of `Migrator::run()`, which
 * `maybe_migrate()` only reaches when there *is* pending work — so activating a
 * site whose schema was already current wrote an autoloaded `1` that nothing
 * would ever clear, and every storefront page load carried it from then on.
 *
 * The write is gone from `Activator`; this removes the rows already out there.
 * It runs as a migration rather than in the activator because a WordPress.org
 * update does not re-activate the plugin, and the migrator is the one upgrade
 * path that fires either way.
 */
final class Migration003DropUpgradeFlag implements MigrationInterface {

	/**
	 * The option this migration exists to remove.
	 *
	 * Deliberately a literal. The constant that named it is gone, and
	 * reintroducing one would leave the next reader believing something still
	 * writes it.
	 */
	private const LEGACY_OPTION = 'storecrew_needs_upgrade';

	public function version(): int {
		return 3;
	}

	public function description(): string {
		return 'Remove the unread storecrew_needs_upgrade flag';
	}

	public function up(): void {
		delete_option( self::LEGACY_OPTION );

		// `delete_option()` returns false for an option that was already absent,
		// so its return cannot distinguish "removed" from "nothing to remove" —
		// which is the state this migration wants on a re-run. Read it back
		// instead; that is idempotent and still catches a delete that failed.
		if ( false !== get_option( self::LEGACY_OPTION, false ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'Option %s could not be removed.', self::LEGACY_OPTION ) )
			);
		}
	}
}
