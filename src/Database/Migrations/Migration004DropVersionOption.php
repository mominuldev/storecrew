<?php
/**
 * Remove the write-only version option.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Migrations;

use StoreCrew\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes `storecrew_version`.
 *
 * The sibling of Migration003's flag, and the worse of the two. `Activator`
 * wrote `STORECREW_VERSION` into it and nothing ever read it — every surface
 * that reports a version (`/bootstrap`, the admin page, asset cache-busting)
 * reads the constant, which is compiled into the running code and cannot be
 * stale.
 *
 * The option can. It is written at activation only, and neither a
 * WordPress.org update nor an FTP upload re-activates a plugin — so on the
 * ordinary upgrade path it goes on reporting the version the merchant was
 * running *before* the update, for as long as they never toggle the plugin. A
 * reader added in good faith later ("which version is this site on?") would
 * have got a confidently wrong answer, which is the failure shape this codebase
 * spends its rules avoiding.
 *
 * The other thing it might have been — "what changed since we last ran here",
 * the classic upgrade-detection pattern — is already owned by the migrator, and
 * keyed better: one-time work belongs to a schema or state change, not to a
 * marketing version bump that may alter nothing.
 *
 * A separate migration rather than an edit to 003 because 003 has already been
 * applied. Amending an applied migration is the forward-only contract's one
 * forbidden move: every site already at that version silently skips the new
 * work.
 */
final class Migration004DropVersionOption implements MigrationInterface {

	/**
	 * The option this migration exists to remove.
	 *
	 * A literal for the same reason as Migration003's: the constant that named
	 * it is gone, and reintroducing one would suggest something still writes it.
	 */
	private const LEGACY_OPTION = 'storecrew_version';

	public function version(): int {
		return 4;
	}

	public function description(): string {
		return 'Remove the unread storecrew_version option';
	}

	public function up(): void {
		delete_option( self::LEGACY_OPTION );

		// Read back rather than trusting the return: `delete_option()` reports
		// false for an option that was already absent, which is the state a
		// re-run after a mid-series fatal must treat as success.
		if ( false !== get_option( self::LEGACY_OPTION, false ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'Option %s could not be removed.', self::LEGACY_OPTION ) )
			);
		}
	}
}
