<?php
/**
 * REST controller base.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Rest;

use StoreCrew\Core\Capabilities\Capabilities;
use StoreCrew\Licensing\FeatureGate;

defined( 'ABSPATH' ) || exit;

/**
 * Base for every StoreCrew REST controller.
 *
 * Two things are centralised here because getting either wrong on a single
 * route is a security hole rather than a bug:
 *
 * 1. **Every route declares a capability.** There is no default-allow path —
 *    `permission()` denies unless a capability is satisfied, so forgetting to
 *    think about permissions produces a locked route rather than an open one.
 * 2. **Feature gating is re-checked server-side.** The capability manifest the
 *    SPA receives is a rendering hint. A user who edits it in the browser gets
 *    a 403 here, because entitlement is decided on this side of the wire
 *    (FR-DIST-09).
 *
 * @see docs/15-free-premium-split.md § 5, § 6
 */
abstract class RestController {

	public const NAMESPACE = 'storecrew/v1';

	public function __construct(
		protected readonly FeatureGate $features,
	) {}

	/**
	 * Register this controller's routes.
	 */
	abstract public function register_routes(): void;

	/**
	 * Which plugin contributed this controller. Diagnostics only.
	 */
	public function owner(): string {
		return 'storecrew';
	}

	/**
	 * Permission callback factory.
	 *
	 * @param string $capability WordPress capability required.
	 * @param string $feature    Feature slug that must also be entitled, if any.
	 */
	protected function permission( string $capability = Capabilities::MANAGE, string $feature = '' ): callable {
		return function () use ( $capability, $feature ) {
			if ( ! current_user_can( $capability ) ) {
				return new \WP_Error(
					'storecrew_forbidden',
					__( 'You do not have permission to do that.', 'storecrew' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			if ( '' !== $feature && ! $this->features->enabled( $feature ) ) {
				return new \WP_Error(
					'storecrew_not_entitled',
					__( 'That feature is not available on your plan.', 'storecrew' ),
					array( 'status' => 403 )
				);
			}

			return true;
		};
	}

	/**
	 * Permission callback for public storefront routes.
	 *
	 * Deliberately named so an unauthenticated route is a visible, deliberate
	 * choice at the call site rather than an omitted permission callback.
	 */
	protected function public_access(): callable {
		return '__return_true';
	}

	/**
	 * Register a route on this plugin's namespace.
	 *
	 * @param array<string, mixed> $args Route arguments.
	 */
	protected function route( string $path, array $args ): void {
		register_rest_route( self::NAMESPACE, $path, $args );
	}

	/**
	 * A successful response.
	 *
	 * @param mixed $data Payload.
	 */
	protected function ok( mixed $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'data' => $data ), $status );
	}

	/**
	 * An error response.
	 */
	protected function error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'storecrew_' . $code, $message, array( 'status' => $status ) );
	}
}
