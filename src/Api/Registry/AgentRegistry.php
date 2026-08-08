<?php
/**
 * Registry of agents.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Registry;

use StoreCrew\Agent\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Every agent the installation knows about.
 *
 * Populated via `storecrew_register_agents`. Premium contributes the Marketing
 * and Analytics agents through this same filter.
 *
 * @extends Registry<Agent>
 */
final class AgentRegistry extends Registry {

	protected function name(): string {
		return 'agent';
	}

	/**
	 * @throws \InvalidArgumentException When the item is not an agent.
	 */
	protected function validate( mixed $item ): void {
		if ( ! $item instanceof Agent ) {
			throw new \InvalidArgumentException(
				esc_html( sprintf( 'Expected %s, got %s.', Agent::class, get_debug_type( $item ) ) )
			);
		}
	}

	/**
	 * Register an agent, keyed by its own id.
	 *
	 * @return static
	 */
	public function register( Agent $agent, string $owner = 'storecrew' ): self {
		return $this->add( $agent->id, $agent, $owner );
	}

	/**
	 * Agents for one audience whose feature is entitled and whose config has
	 * them enabled.
	 *
	 * The audience filter is first and is not optional-by-omission: the
	 * parameter defaults to the storefront, so every existing caller — routing,
	 * the handoff catalogue, the onboarding check — keeps considering exactly
	 * the agents that answer customers. A merchant-facing agent has to be asked
	 * for by name.
	 *
	 * @param callable(string): bool $is_entitled Feature gate.
	 * @param callable(string): bool $is_enabled  Merchant configuration.
	 * @param string                 $audience    `Agent::AUDIENCE_*`.
	 *
	 * @return array<string, Agent>
	 */
	public function available(
		callable $is_entitled,
		callable $is_enabled,
		string $audience = Agent::AUDIENCE_STOREFRONT
	): array {
		return array_filter(
			$this->items,
			static function ( Agent $agent ) use ( $is_entitled, $is_enabled, $audience ): bool {
				if ( $agent->audience !== $audience ) {
					return false;
				}

				if ( '' !== $agent->feature && ! $is_entitled( $agent->feature ) ) {
					return false;
				}

				return $is_enabled( $agent->id );
			}
		);
	}
}
