<?php
/**
 * Registry of admin SPA routes.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Registry;

use StoreCrew\Api\AdminRoute;

defined( 'ABSPATH' ) || exit;

/**
 * Holds every screen contributed to the admin application.
 *
 * Populated via the `storecrew_register_admin_routes` filter.
 *
 * @extends Registry<AdminRoute>
 */
final class AdminRouteRegistry extends Registry {

	protected function name(): string {
		return 'admin route';
	}

	/**
	 * @throws \InvalidArgumentException When the item is not an AdminRoute.
	 */
	protected function validate( mixed $item ): void {
		if ( ! $item instanceof AdminRoute ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Expected %s, got %s.',
					AdminRoute::class,
					get_debug_type( $item )
				)
			);
		}
	}

	/**
	 * Register a route, keyed by its own path.
	 *
	 * @return static
	 */
	public function register( AdminRoute $route, string $owner = 'storecrew' ): self {
		return $this->add( $route->path, $route, $owner );
	}

	/**
	 * Routes in menu order.
	 *
	 * @return list<AdminRoute>
	 */
	public function sorted(): array {
		$routes = array_values( $this->items );

		usort(
			$routes,
			static fn ( AdminRoute $a, AdminRoute $b ): int => $a->order <=> $b->order
				?: strcmp( $a->label, $b->label )
		);

		return $routes;
	}
}
