<?php
/**
 * Migration runner.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Applies pending migrations in version order.
 *
 * Runs on admin_init rather than during activation, for two reasons that both
 * come from real failure modes: a fatal partway through schema work during
 * activation leaves a site that cannot retry, and a merchant who updates by
 * uploading files over FTP never fires the activation hook at all.
 *
 * @see docs/04-database-schema.md § 9
 */
final class Migrator {

	public const OPTION_SCHEMA_VERSION = 'storecrew_schema_version';
	public const OPTION_LOCK           = 'storecrew_migration_lock';
	public const OPTION_LOG            = 'storecrew_migration_log';

	private const LOCK_TTL = 300;

	/**
	 * @var list<MigrationInterface>
	 */
	private array $migrations;

	/**
	 * @param list<MigrationInterface> $migrations Unordered.
	 */
	public function __construct( array $migrations ) {
		usort(
			$migrations,
			static fn ( MigrationInterface $a, MigrationInterface $b ): int => $a->version() <=> $b->version()
		);

		$this->migrations = $migrations;
	}

	/**
	 * Schema version currently applied.
	 */
	public function current_version(): int {
		return (int) get_option( self::OPTION_SCHEMA_VERSION, 0 );
	}

	/**
	 * Highest registered migration version.
	 */
	public function target_version(): int {
		if ( array() === $this->migrations ) {
			return 0;
		}

		return $this->migrations[ count( $this->migrations ) - 1 ]->version();
	}

	/**
	 * Migrations not yet applied.
	 *
	 * @return list<MigrationInterface>
	 */
	public function pending(): array {
		$current = $this->current_version();

		return array_values(
			array_filter(
				$this->migrations,
				static fn ( MigrationInterface $m ): bool => $m->version() > $current
			)
		);
	}

	/**
	 * Whether there is work to do.
	 */
	public function needs_migration(): bool {
		return array() !== $this->pending();
	}

	/**
	 * Apply every pending migration.
	 *
	 * Each migration commits its own version immediately, so a failure at
	 * version 3 leaves 1 and 2 applied and recorded — the next request resumes
	 * at 3 rather than replaying everything.
	 *
	 * @return array{applied: list<int>, failed: int|null, error: string}
	 */
	public function run(): array {
		$applied = array();

		if ( ! $this->acquire_lock() ) {
			return array(
				'applied' => $applied,
				'failed'  => null,
				'error'   => 'Another migration is already running.',
			);
		}

		try {
			foreach ( $this->pending() as $migration ) {
				try {
					$migration->up();
				} catch ( \Throwable $e ) {
					$this->log(
						sprintf(
							'Migration %d (%s) failed: %s',
							$migration->version(),
							$migration->description(),
							$e->getMessage()
						)
					);

					return array(
						'applied' => $applied,
						'failed'  => $migration->version(),
						'error'   => $e->getMessage(),
					);
				}

				update_option( self::OPTION_SCHEMA_VERSION, $migration->version(), false );

				$applied[] = $migration->version();

				$this->log(
					sprintf( 'Applied migration %d (%s).', $migration->version(), $migration->description() )
				);
			}
		} finally {
			$this->release_lock();
		}

		delete_option( 'storecrew_needs_upgrade' );

		return array(
			'applied' => $applied,
			'failed'  => null,
			'error'   => '',
		);
	}

	/**
	 * Take the migration lock.
	 *
	 * Uses add_option rather than a transient: option_name carries a unique
	 * index, so the INSERT either wins or fails atomically. A transient can be
	 * served from a shared object cache and hand the lock to two requests.
	 */
	private function acquire_lock(): bool {
		$existing = get_option( self::OPTION_LOCK );

		if ( false !== $existing ) {
			// Break a stale lock left by a killed process — the exact scenario
			// R-TECH-03 describes on budget hosting.
			if ( ( time() - (int) $existing ) < self::LOCK_TTL ) {
				return false;
			}

			delete_option( self::OPTION_LOCK );
		}

		return add_option( self::OPTION_LOCK, (string) time(), '', false );
	}

	private function release_lock(): void {
		delete_option( self::OPTION_LOCK );
	}

	/**
	 * Append to the rolling migration log, capped at 50 entries.
	 */
	private function log( string $message ): void {
		$log = get_option( self::OPTION_LOG, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'at'      => gmdate( 'Y-m-d H:i:s' ),
			'message' => $message,
		);

		update_option( self::OPTION_LOG, array_slice( $log, -50 ), false );
	}
}
