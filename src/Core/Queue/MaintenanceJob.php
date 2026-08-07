<?php
/**
 * Recurring housekeeping.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core\Queue;

use StoreCrew\Database\Repositories\AgentRunRepository;
use StoreCrew\Database\Repositories\AuditLogRepository;
use StoreCrew\Database\Repositories\ConversationRepository;
use StoreCrew\Database\Repositories\IndexRunRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Sweeps up what a killed process leaves behind.
 *
 * Every item here exists because something can stop without cleaning up after
 * itself: a host kills PHP mid-run and leaves `status = running` forever, a
 * visitor closes the tab and leaves a conversation open, a retention window
 * passes and nothing deletes the rows. None of these are errors — they are the
 * normal residue of a system that can be interrupted, and a dashboard that
 * reports them as live work is lying to the operator.
 *
 * @see docs/04-database-schema.md § 11
 */
final class MaintenanceJob {

	public const HOOK = 'storecrew_maintenance';

	public const INTERVAL = HOUR_IN_SECONDS;

	public const OPTION_AUDIT_RETENTION_DAYS = 'storecrew_audit_retention_days';

	private const DEFAULT_AUDIT_RETENTION_DAYS = 730;

	public function __construct(
		private readonly IndexRunRepository $runs,
		private readonly AgentRunRepository $agent_runs,
		private readonly ConversationRepository $conversations,
		private readonly AuditLogRepository $audit,
		private readonly Scheduler $scheduler,
	) {}

	/**
	 * Make sure the recurring sweep is scheduled.
	 */
	public function ensure_scheduled(): void {
		$this->scheduler->ensure_recurring( self::INTERVAL, self::HOOK );
	}

	/**
	 * Run one sweep.
	 *
	 * @return array{indexRuns: int, agentRuns: int, conversations: int, auditRows: int}
	 */
	public function run(): array {
		$result = array(
			// A job killed mid-flight leaves status=running with a dead
			// heartbeat. Reporting that as in-progress is the failure mode
			// merchants actually hit.
			'indexRuns'     => $this->runs->reap_stalled(),
			'agentRuns'     => $this->agent_runs->reap_stalled(),
			'conversations' => $this->conversations->abandon_stale(),
			'auditRows'     => $this->prune_audit(),
		);

		/**
		 * Fires after a maintenance sweep.
		 *
		 * @param array<string, int> $result What was swept.
		 */
		do_action( 'storecrew_maintenance_completed', $result );

		return $result;
	}

	/**
	 * Prune the audit log past its retention window.
	 *
	 * Batched: a single mass DELETE would lock the table and time out on a busy
	 * store, which is exactly when the log is largest.
	 */
	private function prune_audit(): int {
		$days = (int) get_option( self::OPTION_AUDIT_RETENTION_DAYS, self::DEFAULT_AUDIT_RETENTION_DAYS );

		// A floor rather than an off switch. Audit history is a security
		// control, and letting it be set to a day would quietly disable it.
		$days = max( 180, $days );

		return $this->audit->prune( $days );
	}
}
