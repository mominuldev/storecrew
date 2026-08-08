<?php
/**
 * Migration contract.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database;

defined( 'ABSPATH' ) || exit;

/**
 * One forward-only change to plugin-owned state.
 *
 * Usually schema. Not always: Migration003 removes a stored option, because the
 * migrator is the only upgrade path that runs whether a merchant updated from
 * WordPress.org, re-activated by hand, or uploaded files over FTP.
 *
 * There is no down(). Reversing a data transform against a live store is more
 * dangerous than rolling forward, and a rollback path that is never exercised
 * is a rollback path that does not work.
 *
 * Implementations must be idempotent: `up()` may run twice if a request dies
 * after the schema change but before the version option is written.
 *
 * @see docs/04-database-schema.md § 9
 */
interface MigrationInterface {

	/**
	 * Monotonic version. Applied in ascending order.
	 *
	 * Add-ons own their own series and must not reuse core numbers.
	 */
	public function version(): int;

	/**
	 * Short description, surfaced in the migration log.
	 */
	public function description(): string;

	/**
	 * Apply the change.
	 *
	 * @throws \RuntimeException On unrecoverable failure.
	 */
	public function up(): void;
}
