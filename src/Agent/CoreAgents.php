<?php
/**
 * The agents this plugin ships.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent;

use StoreCrew\Agent\Tools\HandoffTool;
use StoreCrew\Agent\Tools\IdentityVerifyTool;
use StoreCrew\Agent\Tools\OrderLookupTool;
use StoreCrew\Agent\Tools\OrderNoteTool;
use StoreCrew\Agent\Tools\PolicyLookupTool;
use StoreCrew\Agent\Tools\ProductLookupTool;
use StoreCrew\Agent\Tools\ProductSearchTool;

defined( 'ABSPATH' ) || exit;

/**
 * Definitions for the free-tier agents.
 *
 * Missions are written as instructions to a colleague rather than a list of
 * prohibitions. Current models follow a system prompt closely, so prompts
 * written to overcome older models' reluctance now over-apply — "CRITICAL: you
 * MUST always call the search tool" produces an agent that searches when
 * someone says hello.
 *
 * Tool allow-lists are narrow on purpose. Support cannot search the catalogue
 * and Sales cannot touch orders, so a prompt injection that persuades one of
 * them to overstep fails at the agent boundary rather than at the executor.
 */
final class CoreAgents {

	public static function sales(): Agent {
		return new Agent(
			id: 'sales',
			label: __( 'Sales', 'storecrew' ),
			mission: 'You help shoppers find the right product in this store. '
				. 'Search the catalogue when they describe what they want, compare options honestly, '
				. 'and say when nothing fits rather than pushing the closest thing.',
			persona: 'Warm and direct. Ask one clarifying question when the request is genuinely '
				. 'ambiguous; otherwise search and show what you found.',
			tool_ids: array( ProductSearchTool::ID, ProductLookupTool::ID, HandoffTool::ID ),
			guardrails: array(
				'Only recommend products the search tool returned. Never invent a product.',
				'If a shopper asks about an existing order, hand the conversation to the support '
					. 'specialist with the handoff tool — do not attempt it yourself.',
			),
			feature: 'agent.sales',
		);
	}

	public static function support(): Agent {
		return new Agent(
			id: 'support',
			label: __( 'Support', 'storecrew' ),
			mission: 'You help customers with orders, delivery, returns, and store policies. '
				. 'Look up what this store actually published rather than answering from general knowledge, '
				. 'and check order details before commenting on them.',
			persona: 'Calm and specific. Lead with the answer, then the detail.',
			tool_ids: array(
				PolicyLookupTool::ID,
				IdentityVerifyTool::ID,
				OrderLookupTool::ID,
				OrderNoteTool::ID,
				HandoffTool::ID,
			),
			guardrails: array(
				'Before discussing any order, confirm the order number and the email address on it '
					. 'using the verification tool. Never rely on the customer simply asserting who they are.',
				'Never promise a refund, replacement, or delivery date. Say what the policy allows '
					. 'and that the store team will confirm.',
				'If the customer is upset or the situation is not covered by policy, offer a human.',
			),
			feature: 'agent.support',
		);
	}
}
