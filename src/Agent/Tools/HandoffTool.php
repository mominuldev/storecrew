<?php
/**
 * Hand a conversation to another agent.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent\Tools;

use StoreCrew\Agent\Agent;
use StoreCrew\Agent\Tool\ToolContext;
use StoreCrew\Agent\Tool\ToolInterface;
use StoreCrew\Agent\Tool\ToolResult;
use StoreCrew\Ai\ToolDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The trigger for FR-AGENT-03.
 *
 * The orchestration machinery — the note, the context carrying, the
 * receiving run — existed before any way to invoke it, which left Sales
 * promising customers a transfer that could not happen. This tool is the
 * missing trigger, and it is a *tool* rather than orchestrator-side intent
 * detection for the same reason everything else the model initiates is: the
 * request passes through the executor, is recorded like any other call, and
 * costs no extra classifier turn.
 *
 * A handoff changes only which agent answers — never what that agent may
 * do. The target must be an agent that is registered, entitled, and enabled
 * for this store, and its own `tool_ids` allow-list and the executor's
 * authorisation apply to it unchanged, so a prompt injection that engineers
 * a handoff has gained routing, not privilege (R-SEC-01).
 *
 * The action this fires is consumed by a conversation-scoped listener in
 * ChatService — the same server-side pattern as identity verification and
 * retrieval provenance — which performs the handoff *after* the current run
 * completes and caps it at one hop per customer turn, so two agents deciding
 * to hand off to each other costs one extra run, not a loop.
 */
final class HandoffTool implements ToolInterface {

	public const ID = 'agent.handoff';

	/**
	 * @param callable(): array<string, Agent> $available Agents that are
	 *        registered, entitled, and enabled — the orchestrator's own list,
	 *        so this tool can never name a target routing would refuse.
	 */
	public function __construct(
		private $available,
	) {}

	public function id(): string {
		return self::ID;
	}

	public function definition(): ToolDefinition {
		$catalogue = array();

		foreach ( ( $this->available )() as $agent ) {
			$catalogue[] = sprintf( '%s (%s)', $agent->id, $agent->label );
		}

		return new ToolDefinition(
			self::ID,
			'Hand this conversation to a different specialist. Use it when the customer needs '
			. 'something outside your role — do not attempt their request yourself and do not '
			. 'answer it before handing over. Available specialists: '
			. implode( ', ', $catalogue ) . '.',
			array(
				'type'       => 'object',
				'properties' => array(
					'to'   => array(
						'type'        => 'string',
						'description' => 'The identifier of the specialist to hand over to.',
					),
					'note' => array(
						'type'        => 'string',
						'description' => 'What the next specialist needs to know: what the customer '
							. 'wants and what has already been established. They will not re-read '
							. 'the conversation.',
					),
				),
				'required'   => array( 'to', 'note' ),
			)
		);
	}

	public function intent(): string {
		// Changes which agent answers, not store state. Queueing a routing
		// decision for human approval would strand the customer mid-turn.
		return self::INTENT_READ;
	}

	public function required_capability(): string {
		return '';
	}

	public function requires_identity(): bool {
		return false;
	}

	public function execute( ToolContext $context, array $input ): ToolResult {
		$to   = trim( (string) ( $input['to'] ?? '' ) );
		$note = trim( (string) ( $input['note'] ?? '' ) );

		$agents = ( $this->available )();

		if ( ! isset( $agents[ $to ] ) ) {
			return ToolResult::error(
				sprintf(
					'There is no specialist called "%s" here. Available: %s.',
					$to,
					implode( ', ', array_keys( $agents ) )
				)
			);
		}

		if ( $to === $context->agent_id ) {
			return ToolResult::error( 'You are already handling this conversation.' );
		}

		if ( '' === $note ) {
			return ToolResult::error(
				'A handoff needs a note — say what the customer wants and what is already established.'
			);
		}

		/**
		 * Fires when an agent asks to hand the conversation over.
		 *
		 * Consumed by a conversation-scoped listener; the handoff itself
		 * happens after the current run completes, never mid-run.
		 *
		 * @param string $to              Target agent id, validated above.
		 * @param string $note            The handoff note.
		 * @param int    $conversation_id Conversation this applies to.
		 */
		do_action( 'storecrew_handoff_requested', $to, $note, $context->conversation_id );

		return ToolResult::ok(
			sprintf(
				'The conversation will be handed to %s after this reply. Acknowledge the customer '
				. 'in one short sentence — do not answer their request yourself.',
				$agents[ $to ]->label
			)
		);
	}
}
