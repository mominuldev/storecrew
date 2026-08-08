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
 *
 * `audience` says **who the agent talks to**, and it is a boundary rather than a
 * label. Routing only ever considers storefront agents, so a merchant-facing
 * agent — one that reads customer order history or writes store configuration —
 * cannot be reached by a shopper, cannot be named as a handoff target, and does
 * not appear in the classifier's catalogue. Without it, contributing a marketing
 * agent through the registry would silently make it answerable to anyone who
 * opens the widget.
 */
final readonly class Agent {

	/**
	 * Answers customers. Routed to from the widget; reachable by anyone.
	 */
	public const AUDIENCE_STOREFRONT = 'storefront';

	/**
	 * Answers the merchant, inside wp-admin. Never routed to from the widget.
	 */
	public const AUDIENCE_ADMIN = 'admin';

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
		public string $audience = self::AUDIENCE_STOREFRONT,
	) {
		if ( '' === trim( $id ) ) {
			throw new \InvalidArgumentException( 'An agent needs an id.' );
		}

		if ( '' === trim( $mission ) ) {
			throw new \InvalidArgumentException(
				esc_html( sprintf( 'Agent "%s" needs a mission — it becomes the system prompt.', $id ) )
			);
		}

		// Thrown rather than defaulted. A misspelt audience that fell back to
		// the default would put a merchant-facing agent on the storefront —
		// silently, and in the direction that costs the merchant. Registration
		// happens in one window at boot, so this fails where it can be seen.
		if ( ! in_array( $audience, array( self::AUDIENCE_STOREFRONT, self::AUDIENCE_ADMIN ), true ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						'Agent "%s" declares an unknown audience "%s". Use Agent::AUDIENCE_STOREFRONT or Agent::AUDIENCE_ADMIN.',
						$id,
						$audience
					)
				)
			);
		}
	}

	/**
	 * Whether this agent answers customers.
	 */
	public function is_storefront(): bool {
		return self::AUDIENCE_STOREFRONT === $this->audience;
	}

	public function can_use( string $tool_id ): bool {
		return in_array( $tool_id, $this->tool_ids, true );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'       => $this->id,
			'label'    => $this->label,
			'mission'  => $this->mission,
			'tools'    => $this->tool_ids,
			'feature'  => $this->feature,
			'audience' => $this->audience,
		);
	}
}
