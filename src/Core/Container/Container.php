<?php
/**
 * PSR-11 service container.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core\Container;

use Psr\Container\ContainerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * A deliberately small PSR-11 container.
 *
 * No autowiring, no reflection, no compiled cache. Services are declared with
 * an explicit factory closure, which means every dependency edge in the plugin
 * is greppable and there is no runtime reflection cost on a storefront request.
 *
 * Add-ons contribute definitions through the `storecrew_register_container`
 * filter rather than touching this class.
 *
 * @see docs/15-free-premium-split.md § 4.1
 */
final class Container implements ContainerInterface {

	/**
	 * Factory closures keyed by service id.
	 *
	 * @var array<string, callable>
	 */
	private array $definitions = array();

	/**
	 * Resolved singletons keyed by service id.
	 *
	 * @var array<string, mixed>
	 */
	private array $resolved = array();

	/**
	 * Ids currently mid-resolution, used to detect circular dependencies.
	 *
	 * @var array<string, true>
	 */
	private array $resolving = array();

	/**
	 * Register a service factory.
	 *
	 * The factory receives the container and is invoked at most once; the
	 * result is cached for the request.
	 *
	 * @param string   $id      Service identifier, conventionally a class name.
	 * @param callable $factory Receives Container, returns the service.
	 */
	public function set( string $id, callable $factory ): void {
		$this->definitions[ $id ] = $factory;

		// Re-defining a service that was already built discards the stale
		// instance rather than silently serving it.
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Register an already-constructed instance.
	 *
	 * @param string $id       Service identifier.
	 * @param mixed  $instance The service.
	 */
	public function instance( string $id, mixed $instance ): void {
		$this->resolved[ $id ] = $instance;
	}

	/**
	 * Resolve a service.
	 *
	 * @param string $id Service identifier.
	 *
	 * @throws NotFoundException  When the id is not registered.
	 * @throws ContainerException When resolution is circular or the factory throws.
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! array_key_exists( $id, $this->definitions ) ) {
			throw new NotFoundException(
				sprintf( 'StoreCrew: service "%s" is not registered.', $id )
			);
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new ContainerException(
				sprintf(
					'StoreCrew: circular dependency resolving "%s". Chain: %s.',
					$id,
					implode( ' -> ', array_keys( $this->resolving ) ) . ' -> ' . $id
				)
			);
		}

		$this->resolving[ $id ] = true;

		try {
			$service = ( $this->definitions[ $id ] )( $this );
		} catch ( NotFoundException | ContainerException $e ) {
			unset( $this->resolving[ $id ] );

			throw $e;
		} catch ( \Throwable $e ) {
			unset( $this->resolving[ $id ] );

			throw new ContainerException(
				sprintf( 'StoreCrew: factory for "%s" threw: %s', $id, $e->getMessage() ),
				0,
				$e
			);
		}

		unset( $this->resolving[ $id ] );

		$this->resolved[ $id ] = $service;

		return $service;
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service identifier.
	 */
	public function has( string $id ): bool {
		return array_key_exists( $id, $this->definitions )
			|| array_key_exists( $id, $this->resolved );
	}

	/**
	 * Registered service ids. Useful for diagnostics.
	 *
	 * @return list<string>
	 */
	public function ids(): array {
		return array_values(
			array_unique(
				array_merge(
					array_keys( $this->definitions ),
					array_keys( $this->resolved )
				)
			)
		);
	}
}
