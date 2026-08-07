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
				sprintf( 'Expected %s, got %s.', Agent::class, get_debug_type( $item ) )
			);
		}
	}

	/**
	 * Register an agent, keyed by its own id.
	 *
	 * @return static
	 */
	public function register( Agent $agent, string $owner = 'storecrew' ): static {
		return $this->add( $agent->id, $agent, $owner );
	}

	/**
	 * Agents whose feature is entitled and whose config has them enabled.
	 *
	 * @param callable(string): bool $is_entitled Feature gate.
	 * @param callable(string): bool $is_enabled  Merchant configuration.
	 *
	 * @return array<string, Agent>
	 */
	public function available( callable $is_entitled, callable $is_enabled ): array {
		return array_filter(
			$this->items,
			static function ( Agent $agent ) use ( $is_entitled, $is_enabled ): bool {
				if ( '' !== $agent->feature && ! $is_entitled( $agent->feature ) ) {
					return false;
				}

				return $is_enabled( $agent->id );
			}
		);
	}
}
