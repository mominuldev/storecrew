<?php
/**
 * Registry of agent tools.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Registry;

use StoreCrew\Agent\Tool\ToolInterface;
use StoreCrew\Ai\ToolDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Every tool an agent could reach for.
 *
 * Populated via `storecrew_register_tools`. Registration is not permission — an
 * agent still has to name a tool in its allow-list, and the executor still
 * authorises every call.
 *
 * **Tools are registered as factories and resolved on first use**, the same way
 * REST controllers are. A tool can depend on retrieval, which depends on
 * repositories, which depend on the database — building that chain on every
 * storefront page load, for a visitor who never opens the chat widget, is waste.
 * Resolution is memoised, so a tool used twice in one turn is built once.
 *
 * @extends Registry<callable>
 */
final class ToolRegistry extends Registry {

	/**
	 * @var array<string, ToolInterface>
	 */
	private array $resolved = array();

	protected function name(): string {
		return 'tool';
	}

	/**
	 * @throws \InvalidArgumentException When the item is not callable.
	 */
	protected function validate( mixed $item ): void {
		if ( ! is_callable( $item ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Expected a tool factory, got %s.', get_debug_type( $item ) )
			);
		}
	}

	/**
	 * Register a tool factory.
	 *
	 * @param callable(): ToolInterface $factory Returns the tool.
	 *
	 * @return static
	 */
	public function register( string $id, callable $factory, string $owner = 'storecrew' ): static {
		return $this->add( $id, $factory, $owner );
	}

	/**
	 * Resolve a tool.
	 *
	 * A factory that returns the wrong type yields null rather than throwing:
	 * a broken add-on should cost its own tool, not every turn that mentions it.
	 */
	public function get( string $id ): ?ToolInterface {
		if ( isset( $this->resolved[ $id ] ) ) {
			return $this->resolved[ $id ];
		}

		$factory = $this->items[ $id ] ?? null;

		if ( ! is_callable( $factory ) ) {
			return null;
		}

		try {
			$tool = $factory();
		} catch ( \Throwable $e ) {
			do_action( 'storecrew_tool_resolution_failed', $id, $e->getMessage() );

			return null;
		}

		if ( ! $tool instanceof ToolInterface ) {
			do_action( 'storecrew_tool_resolution_failed', $id, 'factory returned ' . get_debug_type( $tool ) );

			return null;
		}

		return $this->resolved[ $id ] = $tool;
	}

	/**
	 * Registered tool ids, without resolving anything.
	 *
	 * @return list<string>
	 */
	public function ids(): array {
		return array_keys( $this->items );
	}

	/**
	 * Every tool, resolved.
	 *
	 * Used by admin listings. Chat paths should prefer `definitions_for()`,
	 * which only builds the tools one agent actually declares.
	 *
	 * @return array<string, ToolInterface>
	 */
	public function all(): array {
		$out = array();

		foreach ( $this->ids() as $id ) {
			$tool = $this->get( $id );

			if ( $tool instanceof ToolInterface ) {
				$out[ $id ] = $tool;
			}
		}

		return $out;
	}

	/**
	 * Definitions for a named subset, in the order given.
	 *
	 * A named tool that does not exist is skipped rather than fataling — an
	 * add-on that deactivates should cost its own tools, not every agent that
	 * referenced them.
	 *
	 * @param list<string> $ids Tool ids.
	 *
	 * @return list<ToolDefinition>
	 */
	public function definitions_for( array $ids ): array {
		$out = array();

		foreach ( $ids as $id ) {
			$tool = $this->get( $id );

			if ( $tool instanceof ToolInterface ) {
				$out[] = $tool->definition();
			}
		}

		return $out;
	}

	/**
	 * Tools that change state.
	 *
	 * @return array<string, ToolInterface>
	 */
	public function write_tools(): array {
		return array_filter(
			$this->all(),
			static fn ( ToolInterface $t ): bool => ToolInterface::INTENT_WRITE === $t->intent()
		);
	}
}
