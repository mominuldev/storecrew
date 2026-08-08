<?php
/**
 * Recurring housekeeping.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core\Queue;

use StoreCrew\Database\Repositories\AgentRunRepository;
use StoreCrew\Database\Repositories\AttributionRepository;
use StoreCrew\Database\Repositories\AuditLogRepository;
use StoreCrew\Database\Repositories\ConversationRepository;
use StoreCrew\Database\Repositories\IndexRunRepository;
use StoreCrew\Database\Repositories\MessageRepository;
use StoreCrew\Database\Repositories\ToolCallRepository;
use StoreCrew\Database\Repositories\UsageRepository;

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

	/**
	 * Retention windows in months (04 § 11), one option row:
	 * `conversations` (0 = never), `runs`, `usage`.
	 */
	public const OPTION_RETENTION = 'storecrew_retention';

	private const DEFAULT_AUDIT_RETENTION_DAYS = 730;

	private const DEFAULT_CONVERSATION_MONTHS = 12;
	private const DEFAULT_RUN_MONTHS          = 6;
	private const DEFAULT_USAGE_MONTHS        = 24;

	/** Close enough for a retention window; exactness is not the point. */
	private const DAYS_PER_MONTH = 30;

	public function __construct(
		private readonly IndexRunRepository $runs,
		private readonly AgentRunRepository $agent_runs,
		private readonly ConversationRepository $conversations,
		private readonly AuditLogRepository $audit,
		private readonly Scheduler $scheduler,
		private readonly MessageRepository $messages,
		private readonly ToolCallRepository $tool_calls,
		private readonly UsageRepository $usage,
		private readonly AttributionRepository $attributions,
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
			'indexRuns'           => $this->runs->reap_stalled(),
			'agentRuns'           => $this->agent_runs->reap_stalled(),
			'conversations'       => $this->conversations->abandon_stale(),
			'auditRows'           => $this->prune_audit(),
			// Retention windows (04 § 11). Runs and usage prune by their own
			// age; conversations cascade to everything they own.
			'prunedRuns'          => $this->prune_runs(),
			'prunedUsage'         => $this->prune_usage(),
			'prunedConversations' => $this->prune_conversations(),
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

	/**
	 * A configured retention window, in days.
	 *
	 * @param string $key           Key in the retention option.
	 * @param int    $default_months Shipped default.
	 * @param bool   $zero_disables  Whether 0 means "never prune".
	 */
	private function window_days( string $key, int $default_months, bool $zero_disables = false ): int {
		$stored = get_option( self::OPTION_RETENTION, array() );
		$months = (int) ( is_array( $stored ) ? ( $stored[ $key ] ?? $default_months ) : $default_months );

		if ( $zero_disables && 0 === $months ) {
			return 0;
		}

		// 1–60 months (04 § 11). Below the floor is clamped up rather than
		// silently disabling retention the merchant believes is on.
		return max( 1, min( 60, $months ) ) * self::DAYS_PER_MONTH;
	}

	/**
	 * Prune conversations past their window, cascading to messages, runs,
	 * and tool calls — a transcript fragment whose conversation is gone is
	 * not privacy, it is litter.
	 */
	private function prune_conversations(): int {
		$days = $this->window_days( 'conversations', self::DEFAULT_CONVERSATION_MONTHS, true );

		if ( 0 === $days ) {
			return 0;
		}

		$ids = $this->conversations->ids_older_than( $days );

		if ( array() === $ids ) {
			return 0;
		}

		$this->messages->delete_for_conversations( $ids );
		$this->agent_runs->delete_for_conversations( $ids );
		$this->tool_calls->delete_for_conversations( $ids );

		// Attribution links go with the conversation that explains them. A
		// surviving link would leave the Analytics agent reporting revenue
		// against a conversation nobody can open — a figure that cannot be
		// checked, which this codebase treats as worse than a missing one.
		$this->attributions->delete_for_conversations( $ids );

		return $this->conversations->delete_ids( $ids );
	}

	private function prune_runs(): int {
		$days = $this->window_days( 'runs', self::DEFAULT_RUN_MONTHS );

		return $this->agent_runs->prune( $days ) + $this->tool_calls->prune( $days );
	}

	private function prune_usage(): int {
		return $this->usage->prune( $this->window_days( 'usage', self::DEFAULT_USAGE_MONTHS ) );
	}
}
