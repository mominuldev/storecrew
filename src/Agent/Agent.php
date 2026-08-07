<?php
/**
 * A declarative agent definition.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent;

use StoreCrew\Ai\ModelPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Who an agent is and what it may reach for.
 *
 * Declarative on purpose (FR-AGENT-01): an agent is data, not a subclass. That
 * is what makes FR-AGENT-09 possible — a merchant editing a persona is editing
 * a row, not deploying code — and what lets premium contribute agents through
 * the same registry the core ones use.
 *
 * `tool_ids` is an allow-list. An agent can only reach tools it names, so a
 * prompt injection persuading the Support agent to create a coupon fails at the
 * agent boundary before it ever reaches the executor's authorisation.
 */
final readonly class Agent {

	/**
	 * @param list<string> $tool_ids   Tools this agent may use.
	 * @param list<string> $guardrails Behavioural constraints.
	 */
	public function __construct(
		public string $id,
		public string $label,
		public string $mission,
		public string $persona,
		public array $tool_ids = array(),
		public string $model_task = ModelPolicy::TASK_CHAT,
		public array $guardrails = array(),
		public string $feature = '',
	) {
		if ( '' === trim( $id ) ) {
			throw new \InvalidArgumentException( 'An agent needs an id.' );
		}

		if ( '' === trim( $mission ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Agent "%s" needs a mission — it becomes the system prompt.', $id )
			);
		}
	}

	public function can_use( string $tool_id ): bool {
		return in_array( $tool_id, $this->tool_ids, true );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'      => $this->id,
			'label'   => $this->label,
			'mission' => $this->mission,
			'tools'   => $this->tool_ids,
			'feature' => $this->feature,
		);
	}
}
