<?php
/**
 * Registry of REST controllers.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Registry;

use StoreCrew\Api\Rest\RestController;

defined( 'ABSPATH' ) || exit;

/**
 * Every REST controller contributed to the `storecrew/v1` namespace.
 *
 * Populated via `storecrew_register_rest_controllers`. Premium registers into
 * the same namespace rather than claiming its own, so the admin SPA has one API
 * surface and never has to know which plugin owns a route. Ownership is tracked
 * here instead.
 *
 * **Controllers are registered as factories, not instances.** The registration
 * window has to close at `plugins_loaded` 20 so the contributed set is final —
 * but constructing seven controllers and the ten repositories behind them on
 * every request, including storefront page loads that will never serve a REST
 * route, is pure waste. Factories keep the window where it belongs and defer
 * construction to `rest_api_init`.
 *
 * @extends Registry<callable>
 */
final class ControllerRegistry extends Registry {

	protected function name(): string {
		return 'REST controller';
	}

	/**
	 * @throws \InvalidArgumentException When the item is not callable.
	 */
	protected function validate( mixed $item ): void {
		if ( ! is_callable( $item ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Expected a controller factory, got %s.', get_debug_type( $item ) )
			);
		}
	}

	/**
	 * Register a controller factory under a stable id.
	 *
	 * @param callable(): RestController $factory Returns the controller.
	 *
	 * @return static
	 */
	public function register( string $id, callable $factory, string $owner = 'storecrew' ): self {
		return $this->add( $id, $factory, $owner );
	}

	/**
	 * Register every contributed controller's routes.
	 *
	 * A controller that throws must not take the whole API down with it — a
	 * broken add-on should cost its own routes, not the settings screen the
	 * merchant needs in order to disable it.
	 */
	public function register_routes(): void {
		foreach ( $this->items as $id => $factory ) {
			try {
				$controller = $factory();

				if ( ! $controller instanceof RestController ) {
					throw new \RuntimeException(
						sprintf( 'Factory returned %s, not a controller.', get_debug_type( $controller ) )
					);
				}

				$controller->register_routes();
			} catch ( \Throwable $e ) {
				/**
				 * Fires when a controller fails to register its routes.
				 *
				 * @param string $id      Controller id.
				 * @param string $owner   Contributing plugin.
				 * @param string $message Failure reason.
				 */
				do_action(
					'storecrew_rest_controller_failed',
					(string) $id,
					(string) $this->owner( (string) $id ),
					$e->getMessage()
				);
			}
		}
	}
}
