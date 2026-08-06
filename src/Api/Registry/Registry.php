<?php
/**
 * Freezable registry base.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for every extension-point registry.
 *
 * Registries are open during `storecrew_api_ready` and frozen at
 * `plugins_loaded` priority 20. Freezing is what makes the contributed set
 * deterministic: nothing can appear after the plugin has decided what exists,
 * so REST routes, capabilities, and the SPA's capability manifest are all
 * computed from a stable set.
 *
 * A write after freeze is a programming error in the contributing add-on. It
 * throws when WP_DEBUG is on so the author sees it immediately, and is ignored
 * with a logged warning in production so a badly-behaved add-on degrades to
 * "my feature is missing" rather than taking the merchant's store down.
 *
 * @see docs/15-free-premium-split.md § 3.1
 *
 * @template TItem
 */
abstract class Registry {

	/**
	 * Registered items keyed by id.
	 *
	 * @var array<string, TItem>
	 */
	protected array $items = array();

	/**
	 * Which plugin contributed each id, for diagnostics and route ownership.
	 *
	 * @var array<string, string>
	 */
	protected array $owners = array();

	/**
	 * Whether the registry is closed to further writes.
	 */
	protected bool $frozen = false;

	/**
	 * Human-readable registry name, used in error messages.
	 */
	abstract protected function name(): string;

	/**
	 * Validate an item before it is accepted.
	 *
	 * Implementations throw \InvalidArgumentException to reject.
	 *
	 * @param mixed $item Candidate item.
	 */
	abstract protected function validate( mixed $item ): void;

	/**
	 * Register an item.
	 *
	 * @param string $id    Unique identifier within this registry.
	 * @param mixed  $item  The item.
	 * @param string $owner Contributing plugin slug, for diagnostics.
	 *
	 * @return static
	 */
	public function add( string $id, mixed $item, string $owner = 'storecrew' ): static {
		if ( $this->frozen ) {
			$this->reject(
				sprintf(
					'StoreCrew: "%s" tried to register "%s" in the %s registry after it was frozen. '
					. 'Add-ons must register on the storecrew_api_ready action.',
					$owner,
					$id,
					$this->name()
				)
			);

			return $this;
		}

		if ( isset( $this->items[ $id ] ) ) {
			$this->reject(
				sprintf(
					'StoreCrew: "%s" tried to register "%s" in the %s registry, but "%s" already registered it.',
					$owner,
					$id,
					$this->name(),
					$this->owners[ $id ]
				)
			);

			return $this;
		}

		try {
			$this->validate( $item );
		} catch ( \InvalidArgumentException $e ) {
			$this->reject(
				sprintf(
					'StoreCrew: "%s" registered an invalid item as "%s" in the %s registry: %s',
					$owner,
					$id,
					$this->name(),
					$e->getMessage()
				)
			);

			return $this;
		}

		$this->items[ $id ]  = $item;
		$this->owners[ $id ] = $owner;

		return $this;
	}

	/**
	 * Whether an id is registered.
	 */
	public function has( string $id ): bool {
		return isset( $this->items[ $id ] );
	}

	/**
	 * Fetch one item, or null.
	 *
	 * @return TItem|null
	 */
	public function get( string $id ): mixed {
		return $this->items[ $id ] ?? null;
	}

	/**
	 * All registered items keyed by id.
	 *
	 * @return array<string, TItem>
	 */
	public function all(): array {
		return $this->items;
	}

	/**
	 * The plugin slug that registered an id.
	 */
	public function owner( string $id ): ?string {
		return $this->owners[ $id ] ?? null;
	}

	/**
	 * Ids contributed by a given plugin.
	 *
	 * @return list<string>
	 */
	public function owned_by( string $owner ): array {
		return array_keys(
			array_filter(
				$this->owners,
				static fn ( string $candidate ): bool => $candidate === $owner
			)
		);
	}

	/**
	 * Close the registry to further writes.
	 */
	public function freeze(): void {
		$this->frozen = true;
	}

	/**
	 * Whether the registry is closed.
	 */
	public function is_frozen(): bool {
		return $this->frozen;
	}

	/**
	 * Reject a bad write: loud in development, survivable in production.
	 *
	 * @throws \LogicException When WP_DEBUG is enabled.
	 */
	protected function reject( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			throw new \LogicException( $message );
		}

		if ( function_exists( 'do_action' ) ) {
			/**
			 * Fires when a registry write is rejected in production.
			 *
			 * @param string $message Human-readable reason.
			 */
			do_action( 'storecrew_registry_rejected', $message );
		}
	}
}
