<?php
/**
 * Authorises and runs tool calls.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent\Tool;

use StoreCrew\Ai\ToolCall;
use StoreCrew\Api\Registry\ToolRegistry;
use StoreCrew\Database\Repositories\AgentConfigRepository;
use StoreCrew\Database\Repositories\AuditLogRepository;
use StoreCrew\Database\Repositories\ToolCallRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The security boundary between what a model asks for and what happens.
 *
 * Authorisation runs in a fixed order, and the order is the point:
 *
 * 1. **Does the tool exist?** A model can invent a tool name.
 * 2. **Is it disabled for this agent?** Merchant configuration wins outright.
 * 3. **Does the session hold the capability?** Derived from WordPress, never
 *    from arguments.
 * 4. **Is identity proven, if the tool needs it?** Order data is unreachable
 *    without it, regardless of anything else (FR-SUPPORT-02).
 * 5. **Is a write approved?** Writes default to requiring a human
 *    (FR-AGENT-05).
 * 6. **Does a filter object?** `storecrew_tool_authorized` runs last and
 *    **may only deny**. Its return value is ANDed with the decision already
 *    made, so no filter — and therefore no add-on, and therefore no prompt
 *    injection reaching an add-on — can grant a permission the earlier steps
 *    refused.
 *
 * Every call is recorded before it runs, so an attempt that is denied is as
 * visible as one that succeeds.
 *
 * @see docs/01-prd.md FR-AGENT-04, FR-AGENT-05, R-SEC-01, R-SEC-02
 */
final class ToolExecutor {

	public function __construct(
		private readonly ToolRegistry $tools,
		private readonly ToolCallRepository $calls,
		private readonly AgentConfigRepository $configs,
		private readonly AuditLogRepository $audit,
	) {}

	/**
	 * Authorise and run one call.
	 *
	 * @param int $run_id Agent run this belongs to, for the inspector.
	 */
	public function execute( ToolCall $call, ToolContext $context, int $run_id = 0 ): ToolResult {
		$tool = $this->tools->get( $call->name );

		if ( ! $tool instanceof ToolInterface ) {
			// Recorded even though nothing ran: a model repeatedly inventing
			// tool names is a prompt problem worth seeing in the inspector.
			$this->record( $call, $context, $run_id, ToolInterface::INTENT_READ, 'auto', ToolResult::STATUS_ERROR );

			return ToolResult::error(
				sprintf( 'There is no tool called "%s".', $call->name )
			);
		}

		$mode = $this->configs->tool_mode( $context->agent_id, $tool->id() );

		$call_id = $this->record( $call, $context, $run_id, $tool->intent(), $mode, ToolCallRepository::STATUS_PENDING );

		if ( AgentConfigRepository::MODE_DISABLED === $mode ) {
			$this->finish( $call_id, ToolResult::disabled( 'That action is switched off for this agent.' ) );

			return ToolResult::disabled( 'That action is switched off for this agent.' );
		}

		$denial = $this->authorise( $tool, $context );

		if ( null !== $denial ) {
			$this->audit_denial( $tool, $context, $denial->message );
			$this->finish( $call_id, $denial );

			return $denial;
		}

		// Writes wait for a human unless explicitly set to auto.
		if (
			ToolInterface::INTENT_WRITE === $tool->intent()
			&& AgentConfigRepository::MODE_AUTO !== $mode
		) {
			// Left pending in the approval queue rather than resolved. The
			// merchant decides; the agent is told to expect a delay.
			return ToolResult::pending(
				'That needs approval from the store team before it can happen. It has been queued for them.'
			);
		}

		$started = microtime( true );

		try {
			$result = $tool->execute( $context, $call->arguments );
		} catch ( \Throwable $e ) {
			$result = ToolResult::error( 'That action could not be completed.' );

			do_action( 'storecrew_tool_failed', $tool->id(), $e->getMessage() );
		}

		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		$this->finish( $call_id, $result, $duration );

		if ( ToolInterface::INTENT_WRITE === $tool->intent() && $result->is_ok() ) {
			$this->audit->record(
				'agent.tool_write',
				AuditLogRepository::ACTOR_AGENT,
				$context->agent_id,
				'tool',
				$call_id,
				array( 'tool' => $tool->id() )
			);
		}

		return $result;
	}

	/**
	 * Decide whether the call may proceed.
	 *
	 * Returns null when authorised, or the denial to hand back.
	 */
	private function authorise( ToolInterface $tool, ToolContext $context ): ?ToolResult {
		$authorised = true;
		$message    = '';

		if ( ! $context->can( $tool->required_capability() ) ) {
			$authorised = false;
			$message    = 'You do not have permission to do that.';
		}

		if ( $authorised && $tool->requires_identity() && ! $context->identity_verified ) {
			$authorised = false;
			// Phrased as the next step rather than a refusal: the customer has
			// done nothing wrong, they simply have not proven who they are yet.
			$message = 'Before I can look that up, I need to confirm who you are. '
				. 'Please give me your order number and the email address used on the order.';
		}

		/**
		 * Final veto on a tool call.
		 *
		 * **This filter may only deny.** The return value is ANDed with the
		 * decision already made, so returning true cannot grant a permission
		 * the capability and identity checks refused. That is deliberate: an
		 * add-on filtering this must not become a path by which model output
		 * escalates its own privileges (R-SEC-01).
		 *
		 * @param bool          $authorised Whether the checks so far allowed it.
		 * @param ToolInterface $tool       The tool.
		 * @param ToolContext   $context    Session facts.
		 */
		$filtered = (bool) apply_filters( 'storecrew_tool_authorized', $authorised, $tool, $context );

		$authorised = $authorised && $filtered;

		if ( $authorised ) {
			return null;
		}

		return ToolResult::denied(
			'' !== $message ? $message : 'That action is not permitted here.'
		);
	}

	/**
	 * @param array<string, mixed>|null $_unused Reserved.
	 */
	private function record(
		ToolCall $call,
		ToolContext $context,
		int $run_id,
		string $intent,
		string $mode,
		string $_status
	): int {
		$auth_mode = AgentConfigRepository::MODE_AUTO === $mode
			? ToolCallRepository::AUTH_AUTO
			: ToolCallRepository::AUTH_REQUIRED;

		// Reads never need approval, whatever the configured mode says — the
		// approval queue is for state changes, and filling it with lookups
		// would train merchants to approve without reading.
		if ( ToolInterface::INTENT_READ === $intent ) {
			$auth_mode = ToolCallRepository::AUTH_AUTO;
		}

		return $this->calls->record(
			$run_id,
			$context->conversation_id,
			$call->name,
			$call->arguments,
			$intent,
			$auth_mode
		);
	}

	private function finish( int $call_id, ToolResult $result, int $duration = 0 ): void {
		if ( 0 === $call_id ) {
			return;
		}

		if ( $result->is_ok() ) {
			$this->calls->succeed( $call_id, $result->data, $duration );

			return;
		}

		$this->calls->fail( $call_id, $result->message, $duration );
	}

	private function audit_denial( ToolInterface $tool, ToolContext $context, string $reason ): void {
		$this->audit->record(
			'agent.tool_denied',
			AuditLogRepository::ACTOR_AGENT,
			$context->agent_id,
			'tool',
			0,
			array(
				'tool'   => $tool->id(),
				'reason' => $reason,
			)
		);
	}
}
