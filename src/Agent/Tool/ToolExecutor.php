<?php
/**
 * Authorises and runs tool calls.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent\Tool;

use StoreCrew\Agent\Agent;
use StoreCrew\Ai\ToolCall;
use StoreCrew\Api\Registry\AgentRegistry;
use StoreCrew\Api\Registry\ToolRegistry;
use StoreCrew\Database\Repositories\AgentConfigRepository;
use StoreCrew\Database\Repositories\AgentRunRepository;
use StoreCrew\Database\Repositories\AuditLogRepository;
use StoreCrew\Database\Repositories\ConversationRepository;
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
 * 5. **Does a filter object?** `storecrew_tool_authorized` is the last word
 *    on *whether*, and it **may only deny**. Its return value is ANDed with
 *    the decision already made, so no filter — and therefore no add-on, and
 *    therefore no prompt injection reaching an add-on — can grant a
 *    permission the earlier steps refused.
 * 6. **Is a write approved?** Writes default to requiring a human
 *    (FR-AGENT-05). This runs after every deny, so approval decides *when* a
 *    permitted write happens, never *whether* — a call nothing authorised is
 *    already refused and never reaches the queue.
 *
 * Every call is recorded before it runs, so an attempt that is denied is as
 * visible as one that succeeds — and recorded redacted: `identity.verify`
 * receives a customer email on every attempt, and no plugin table may store
 * one (04 § 11).
 *
 * **A queued write is executed later by `execute_approved()`, and the second
 * half of that sentence is where the care goes.** The approval loop used to
 * stop at recording the decision, so a merchant approved a write that never
 * happened. Running it later means running it from the *stored* row, and the
 * stored row is redacted — which is why `execute()` refuses to queue a call
 * whose arguments redaction altered (§ `record()`), and why approval-time
 * authorisation is re-derived from live state rather than replayed.
 *
 * @see docs/01-prd.md FR-AGENT-04, FR-AGENT-05, R-SEC-01, R-SEC-02
 */
final class ToolExecutor {

	public function __construct(
		private readonly ToolRegistry $tools,
		private readonly ToolCallRepository $calls,
		private readonly AgentConfigRepository $configs,
		private readonly AuditLogRepository $audit,
		private readonly AgentRegistry $agents,
		private readonly AgentRunRepository $runs,
		private readonly ConversationRepository $conversations,
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
			// tool names is a prompt problem worth seeing in the inspector —
			// and resolved by the finish() below, not left pending, so a
			// hallucinated call is as visible as a denial and is never
			// mistaken for one awaiting approval.
			$call_id = $this->record(
				$call,
				$context,
				$run_id,
				ToolInterface::INTENT_READ,
				AgentConfigRepository::MODE_AUTO,
				$this->redact( $call->arguments )
			);

			$missing = ToolResult::error(
				sprintf( 'There is no tool called "%s".', $call->name )
			);

			$this->finish( $call_id, $missing );

			return $missing;
		}

		$mode = $this->configs->tool_mode( $context->agent_id, $tool->id() );

		// Redacted once, here, so the pending branch below can compare it with
		// what the model actually sent. Both versions only exist together in
		// this request.
		$stored = $this->redact( $call->arguments );

		$call_id = $this->record( $call, $context, $run_id, $tool->intent(), $mode, $stored );

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
			// A queued write runs later from the stored row, and the stored row
			// is redacted. If redaction changed anything, replaying it would
			// execute something *different* from what the merchant approved —
			// an order note with the customer's email silently replaced by
			// "[redacted]". Refuse to queue rather than promise a write we
			// cannot reproduce faithfully; the model is told how to retry, and
			// the row is resolved so it never reaches the approval queue.
			if ( $stored !== $call->arguments ) {
				$unreplayable = ToolResult::error(
					'That action includes personal details, which cannot be held in the approval queue. '
					. 'Ask for it again without them — refer to the order by its number rather than by '
					. 'anyone\'s email address.'
				);

				$this->finish( $call_id, $unreplayable );

				return $unreplayable;
			}

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
	 * Run a queued write, now that a human has approved it (FR-AGENT-05).
	 *
	 * The second half of the approval loop. For a long time only the first
	 * half existed — the decision was recorded and nothing ran — so a merchant
	 * approved a coupon that was never created, and the queue looked like it
	 * worked because the row left it.
	 *
	 * Four properties hold this together:
	 *
	 * 1. **Approval is the claim.** `approve()` transitions
	 *    `required → approved` with the pending state in its WHERE, so exactly
	 *    one caller can ever win it. A second click, a double-submit, or two
	 *    administrators deciding at once cannot execute the write twice.
	 * 2. **Authorisation is re-derived, never replayed.** The agent, its
	 *    allow-list, the merchant's per-tool mode, the conversation's identity
	 *    state, and the capability check all read live state at approval time.
	 *    Verification revoked since the call was queued (a shared device, a
	 *    reassigned customer) revokes the write with it.
	 * 3. **The capability checked is the approver's.** They are the one taking
	 *    responsibility, and the route that reaches here already required
	 *    `storecrew_manage_agents`.
	 * 4. **Crash-safe in the direction that matters.** A failure between the
	 *    claim and the result leaves the row `approved` + `pending`, which
	 *    `approve()` will never match again. The write did not happen and
	 *    cannot happen twice — a stuck row a merchant can re-ask for, rather
	 *    than a coupon issued twice.
	 *
	 * @param int $call_id     The queued call.
	 * @param int $approver_id WordPress user approving it.
	 *
	 * @return ToolResult|null Null when the row could not be claimed — already
	 *                         decided, already run, or never pending.
	 */
	public function execute_approved( int $call_id, int $approver_id ): ?ToolResult {
		$row = $this->calls->find( $call_id );

		if ( null === $row ) {
			return null;
		}

		// Claim first. Everything below runs exactly once because this
		// transition can only succeed once.
		if ( ! $this->calls->approve( $call_id, $approver_id ) ) {
			return null;
		}

		$resolved = $this->rebuild( $row );

		if ( $resolved instanceof ToolResult ) {
			$this->finish( $call_id, $resolved );

			return $resolved;
		}

		[ $tool, $context ] = $resolved;

		$denial = $this->authorise( $tool, $context );

		if ( null !== $denial ) {
			// A denial at approval time is more interesting than one during a
			// turn: it means the world changed between asking and agreeing.
			$this->audit_denial( $tool, $context, $denial->message );
			$this->finish( $call_id, $denial );

			return $denial;
		}

		$arguments = json_decode( (string) $row->arguments, true );
		$started   = microtime( true );

		try {
			$result = $tool->execute( $context, is_array( $arguments ) ? $arguments : array() );
		} catch ( \Throwable $e ) {
			$result = ToolResult::error( 'That action could not be completed.' );

			do_action( 'storecrew_tool_failed', $tool->id(), $e->getMessage() );
		}

		$this->finish( $call_id, $result, (int) round( ( microtime( true ) - $started ) * 1000 ) );

		if ( $result->is_ok() ) {
			$this->audit->record(
				'agent.tool_write',
				AuditLogRepository::ACTOR_USER,
				(string) $approver_id,
				'tool',
				$call_id,
				array(
					'tool'     => $tool->id(),
					'agent'    => $context->agent_id,
					'approved' => true,
				)
			);
		}

		/**
		 * Fires after an approved write has run, whatever the outcome.
		 *
		 * @param ToolResult $result      What happened.
		 * @param int        $call_id     The queued call.
		 * @param int        $approver_id Who approved it.
		 */
		do_action( 'storecrew_approved_call_executed', $result, $call_id, $approver_id );

		return $result;
	}

	/**
	 * Rebuild what a queued call needs in order to run.
	 *
	 * Returns `[ tool, context ]`, or a `ToolResult` describing why it cannot
	 * be reconstructed. Each refusal is a real state the merchant can reach:
	 * an add-on deactivated since the call was queued, an agent whose
	 * allow-list no longer includes the tool, a conversation pruned by
	 * retention.
	 *
	 * @param object $row The tool_calls row.
	 *
	 * @return array{0: ToolInterface, 1: ToolContext}|ToolResult
	 */
	private function rebuild( object $row ): array|ToolResult {
		$tool = $this->tools->get( (string) $row->tool_id );

		if ( ! $tool instanceof ToolInterface ) {
			return ToolResult::error(
				sprintf(
					'"%s" is no longer available on this site, so this action cannot be carried out.',
					(string) $row->tool_id
				)
			);
		}

		$run      = $this->runs->find( (int) $row->agent_run_id );
		$agent_id = null === $run ? '' : (string) $run->agent_id;
		$agent    = '' === $agent_id ? null : $this->agents->get( $agent_id );

		if ( ! $agent instanceof Agent ) {
			return ToolResult::error(
				'The agent that asked for this is no longer available on this site, so its permissions '
				. 'cannot be checked and the action was not carried out.'
			);
		}

		// The allow-list check the runner makes before every live call. An
		// agent that lost this tool between asking and approval must not get
		// it back by way of the queue.
		if ( ! $agent->can_use( $tool->id() ) ) {
			return ToolResult::denied(
				sprintf( 'The %s agent is no longer permitted to use that action.', $agent->label )
			);
		}

		if ( AgentConfigRepository::MODE_DISABLED === $this->configs->tool_mode( $agent_id, $tool->id() ) ) {
			return ToolResult::disabled( 'That action has since been switched off for this agent.' );
		}

		$conversation = $this->conversations->find( (int) $row->conversation_id );

		if ( null === $conversation ) {
			return ToolResult::error(
				'The conversation this belongs to is no longer stored, so the action was not carried out.'
			);
		}

		// Read from the conversation now, not from anything captured when the
		// call was queued. Identity that has since been revoked is revoked.
		return array(
			$tool,
			new ToolContext(
				(int) $conversation->id,
				(int) $conversation->customer_id,
				'1' === (string) $conversation->identity_verified,
				(int) $conversation->verified_order_id,
				$agent_id,
				$agent->is_storefront()
			),
		);
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
	 * Write the call row, redacted, before anything runs.
	 *
	 * The row always inserts `pending` — the repository owns that, and this
	 * method has no say in it. Every path out of `execute()` that ran or
	 * refused anything resolves the row through `finish()`; the one path that
	 * deliberately leaves it pending is a write waiting in the approval
	 * queue, which is what `pending` means to the merchant reading it.
	 *
	 * @param string                  $intent `ToolInterface::INTENT_*` — reads never queue.
	 * @param string                  $mode   `AgentConfigRepository::MODE_*` for this agent.
	 * @param array<array-key, mixed> $stored Arguments as they will be persisted,
	 *                                     already through `redact()`.
	 * @return int Row id, for `finish()`.
	 */
	private function record(
		ToolCall $call,
		ToolContext $context,
		int $run_id,
		string $intent,
		string $mode,
		array $stored
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
			$stored,
			$intent,
			$auth_mode
		);
	}

	/**
	 * Strip identity-bearing values before arguments are persisted.
	 *
	 * The schema's privacy promise (04 § 11) is that no raw email address is
	 * stored in any plugin table — and `identity.verify` receives one as an
	 * argument on every attempt, including failed ones. The record needs to
	 * show *that* an email was supplied, never which one; the verified outcome
	 * lives on the conversation row as `verified_order_id`.
	 *
	 * Key-based redaction catches declared parameters; the pattern pass
	 * catches an address a model volunteers inside a free-text argument.
	 *
	 * @param array<array-key, mixed> $arguments Model-supplied arguments.
	 * @return array<array-key, mixed>
	 */
	private function redact( array $arguments ): array {
		/**
		 * Extend the argument keys whose values are redacted before storage.
		 *
		 * Additions only — the shipped keys are merged in afterwards, so a
		 * filter cannot reintroduce the leak this exists to prevent.
		 *
		 * @param list<string> $keys Extra keys to redact, lowercase.
		 */
		$keys = array_merge(
			(array) apply_filters( 'storecrew_redacted_argument_keys', array() ),
			array( 'email' )
		);

		foreach ( $arguments as $key => $value ) {
			if ( is_array( $value ) ) {
				$arguments[ $key ] = $this->redact( $value );
				continue;
			}

			if ( is_string( $key ) && in_array( strtolower( $key ), $keys, true ) ) {
				$arguments[ $key ] = '[redacted]';
				continue;
			}

			if ( is_string( $value ) && str_contains( $value, '@' ) ) {
				$arguments[ $key ] = (string) preg_replace(
					'/[^\s@"\'<>]+@[^\s@"\'<>]+\.[^\s@"\'<>.,;]+/',
					'[redacted]',
					$value
				);
			}
		}

		return $arguments;
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
