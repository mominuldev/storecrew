<?php
/**
 * Provider configuration.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Rest\Controllers;

use StoreCrew\Ai\ChatProviderInterface;
use StoreCrew\Ai\EmbeddingProviderInterface;
use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Api\Rest\RestController;
use StoreCrew\Core\Capabilities\Capabilities;
use StoreCrew\Database\Repositories\AuditLogRepository;
use StoreCrew\Licensing\FeatureGate;
use StoreCrew\Security\SecretStore;

defined( 'ABSPATH' ) || exit;

/**
 * Lists providers and manages their API keys.
 *
 * **A key that has been written is never readable again through this API.**
 * Responses carry a masked hint — enough for a merchant to recognise which key
 * is installed — and nothing more. There is no endpoint that returns a secret,
 * because the moment one exists, every XSS on the admin screen becomes a key
 * exfiltration.
 *
 * Capabilities are returned alongside each provider so the UI can hide controls
 * a provider would reject rather than offering a setting that guarantees a 400
 * — Anthropic and `temperature` being the case that motivated it.
 */
final class ProviderController extends RestController {

	public function __construct(
		FeatureGate $features,
		private readonly ProviderRegistry $providers,
		private readonly SecretStore $secrets,
		private readonly AuditLogRepository $audit,
	) {
		parent::__construct( $features );
	}

	public function register_routes(): void {
		$this->route(
			'/providers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
			)
		);

		$this->route(
			'/providers/(?P<id>[a-z0-9_-]+)/key',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_key' ),
					'permission_callback' => $this->permission( Capabilities::MANAGE ),
					'args'                => array(
						'key' => array(
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => static fn ( $v ): bool => is_string( $v ) && '' !== trim( $v ),
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_key' ),
					'permission_callback' => $this->permission( Capabilities::MANAGE ),
				),
			)
		);

		$this->route(
			'/providers/(?P<id>[a-z0-9_-]+)/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
			)
		);
	}

	public function index(): \WP_REST_Response {
		$out = array();

		foreach ( $this->providers->all() as $id => $provider ) {
			$out[] = array(
				'id'           => (string) $id,
				'label'        => $provider->label(),
				'configured'   => $provider->is_configured(),
				// Recognisable, not reusable.
				'keyHint'      => $this->secrets->hint( 'provider.' . $id . '.key' ),
				'capabilities' => $provider->capabilities()->to_array(),
				'chatModels'   => $provider instanceof ChatProviderInterface
					? $provider->default_models()
					: array(),
				'embedModels'  => $provider instanceof EmbeddingProviderInterface
					? $provider->default_embedding_models()
					: array(),
				'owner'        => $this->providers->owner( (string) $id ),
			);
		}

		return $this->ok( $out );
	}

	public function save_key( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (string) $request->get_param( 'id' );

		if ( ! $this->providers->has( $id ) ) {
			return $this->error( 'unknown_provider', __( 'Unknown provider.', 'storecrew' ), 404 );
		}

		$key = trim( (string) $request->get_param( 'key' ) );

		$this->secrets->put( 'provider.' . $id . '.key', $key );

		// The action is audited; the key is not. `data` here must never carry
		// the secret — the audit log is readable by anyone with the capability
		// and is exported under GDPR requests.
		$this->audit->record(
			'provider.key_saved',
			AuditLogRepository::ACTOR_USER,
			(string) get_current_user_id(),
			'provider',
			0,
			array( 'provider' => $id ),
			(string) ( $_SERVER['REMOTE_ADDR'] ?? '' )
		);

		return $this->ok(
			array(
				'id'         => $id,
				'configured' => true,
				'keyHint'    => $this->secrets->hint( 'provider.' . $id . '.key' ),
			)
		);
	}

	public function delete_key( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (string) $request->get_param( 'id' );

		if ( ! $this->providers->has( $id ) ) {
			return $this->error( 'unknown_provider', __( 'Unknown provider.', 'storecrew' ), 404 );
		}

		$this->secrets->forget( 'provider.' . $id . '.key' );

		$this->audit->record(
			'provider.key_removed',
			AuditLogRepository::ACTOR_USER,
			(string) get_current_user_id(),
			'provider',
			0,
			array( 'provider' => $id ),
			(string) ( $_SERVER['REMOTE_ADDR'] ?? '' )
		);

		return $this->ok( array( 'id' => $id, 'configured' => false ) );
	}

	/**
	 * Check a stored key against the provider.
	 *
	 * Verification is a real network call, so it is a POST rather than a GET —
	 * it has a cost, and it must not be triggered by a prefetch or a browser
	 * revisiting a cached URL.
	 */
	public function verify( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (string) $request->get_param( 'id' );

		$provider = $this->providers->get( $id );

		if ( null === $provider ) {
			return $this->error( 'unknown_provider', __( 'Unknown provider.', 'storecrew' ), 404 );
		}

		$reason = $provider->verify();

		return $this->ok(
			array(
				'id'    => $id,
				'ok'    => '' === $reason,
				'error' => $reason,
			)
		);
	}
}
