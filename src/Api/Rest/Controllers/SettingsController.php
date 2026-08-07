<?php
/**
 * Model policy and spend settings.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Rest\Controllers;

use StoreCrew\Ai\ModelPolicy;
use StoreCrew\Ai\Pricing;
use StoreCrew\Ai\SpendGuard;
use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Api\Rest\RestController;
use StoreCrew\Chat\ChatSettings;
use StoreCrew\Core\Capabilities\Capabilities;
use StoreCrew\Database\Repositories\AuditLogRepository;
use StoreCrew\Licensing\FeatureGate;

defined( 'ABSPATH' ) || exit;

/**
 * Which model does what, and how much may be spent doing it.
 */
final class SettingsController extends RestController {

	public function __construct(
		FeatureGate $features,
		private readonly ModelPolicy $policy,
		private readonly SpendGuard $spend,
		private readonly ProviderRegistry $providers,
		private readonly AuditLogRepository $audit,
	) {
		parent::__construct( $features );
	}

	public function register_routes(): void {
		$this->route(
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $this->permission( Capabilities::MANAGE ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $this->permission( Capabilities::MANAGE ),
				),
			)
		);
	}

	public function get_settings(): \WP_REST_Response {
		$resolved = array();

		foreach ( ModelPolicy::tasks() as $task ) {
			$resolved[ $task ] = $this->policy->resolve( $task );
		}

		return $this->ok(
			array(
				'modelPolicy' => $this->policy->stored(),
				'resolved'    => $resolved,
				'spend'       => $this->spend->status(),
				'pricing'     => array(
					// Surfaced so a stale rate table is visible rather than
					// assumed. Unpriced models report unknown, never zero.
					'ratesVerified' => Pricing::RATES_VERIFIED,
				),
				'canEmbed'    => $this->providers->can_embed(),
				'tasks'       => ModelPolicy::tasks(),
				'chat'        => ChatSettings::all(),
			)
		);
	}

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return $this->error( 'invalid_body', __( 'Expected a JSON object.', 'storecrew' ) );
		}

		if ( isset( $body['modelPolicy'] ) ) {
			$policy = $this->sanitise_policy( (array) $body['modelPolicy'] );

			if ( $policy instanceof \WP_Error ) {
				return $policy;
			}

			$this->policy->save( $policy );
		}

		if ( isset( $body['spend'] ) && is_array( $body['spend'] ) ) {
			$cap = (int) ( $body['spend']['capMicros'] ?? 0 );

			$behaviour = (string) ( $body['spend']['behaviour'] ?? SpendGuard::BEHAVIOUR_STOP );

			if ( ! in_array( $behaviour, array( SpendGuard::BEHAVIOUR_STOP, SpendGuard::BEHAVIOUR_WARN ), true ) ) {
				return $this->error( 'invalid_behaviour', __( 'Unknown spend-cap behaviour.', 'storecrew' ) );
			}

			$this->spend->set_cap( max( 0, $cap ), $behaviour );
		}

		if ( isset( $body['chat'] ) && is_array( $body['chat'] ) ) {
			ChatSettings::save( $body['chat'] );
		}

		$this->audit->record(
			'settings.updated',
			AuditLogRepository::ACTOR_USER,
			(string) get_current_user_id(),
			'settings',
			0,
			array( 'keys' => array_keys( $body ) ),
			isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : ''
		);

		return $this->get_settings();
	}

	/**
	 * Validate a submitted model policy.
	 *
	 * Rejects a provider that cannot do the task it is assigned. Accepting an
	 * embedding assignment for a chat-only provider would store a policy that
	 * fails on first use, hours later, in a background job — far from the screen
	 * where it was set.
	 *
	 * @param array<string, mixed> $submitted Raw policy.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function sanitise_policy( array $submitted ): array|\WP_Error {
		$clean = array();

		foreach ( $submitted as $task => $entry ) {
			$task = (string) $task;

			if ( ! in_array( $task, ModelPolicy::tasks(), true ) ) {
				return $this->error(
					'unknown_task',
					sprintf( /* translators: %s: task name */ __( 'Unknown task "%s".', 'storecrew' ), $task )
				);
			}

			if ( ! is_array( $entry ) ) {
				continue;
			}

			$provider_id = sanitize_key( (string) ( $entry['provider'] ?? '' ) );
			$model       = sanitize_text_field( (string) ( $entry['model'] ?? '' ) );

			if ( '' === $provider_id || '' === $model ) {
				continue;
			}

			$provider = $this->providers->get( $provider_id );

			if ( null === $provider ) {
				return $this->error(
					'unknown_provider',
					sprintf( /* translators: %s: provider id */ __( 'Unknown provider "%s".', 'storecrew' ), $provider_id )
				);
			}

			if ( ModelPolicy::TASK_EMBEDDING === $task && ! $provider->capabilities()->embeddings ) {
				return $this->error(
					'provider_cannot_embed',
					sprintf(
						/* translators: %s: provider label */
						__( '%s cannot generate embeddings.', 'storecrew' ),
						$provider->label()
					)
				);
			}

			if ( ModelPolicy::TASK_EMBEDDING !== $task && ! $provider->capabilities()->chat ) {
				return $this->error(
					'provider_cannot_chat',
					sprintf(
						/* translators: %s: provider label */
						__( '%s cannot hold a conversation.', 'storecrew' ),
						$provider->label()
					)
				);
			}

			$clean[ $task ] = array(
				'provider' => $provider_id,
				'model'    => $model,
			);

			// The failover target, validated by the same rules as the primary.
			// Without this branch the API silently strips a submitted fallback
			// and the merchant's failover never exists — a policy the runner
			// executes must be a policy the settings screen can store.
			if ( isset( $entry['fallback'] ) && is_array( $entry['fallback'] ) ) {
				$fb_provider = sanitize_key( (string) ( $entry['fallback']['provider'] ?? '' ) );
				$fb_model    = sanitize_text_field( (string) ( $entry['fallback']['model'] ?? '' ) );

				if ( '' !== $fb_provider && '' !== $fb_model ) {
					$fb = $this->providers->get( $fb_provider );

					if ( null === $fb ) {
						return $this->error(
							'unknown_provider',
							sprintf( /* translators: %s: provider id */ __( 'Unknown provider "%s".', 'storecrew' ), $fb_provider )
						);
					}

					$needs_chat = ModelPolicy::TASK_EMBEDDING !== $task;

					if ( ( $needs_chat && ! $fb->capabilities()->chat ) || ( ! $needs_chat && ! $fb->capabilities()->embeddings ) ) {
						return $this->error(
							'fallback_incapable',
							sprintf(
								/* translators: %s: provider label */
								__( '%s cannot serve as the fallback for this task.', 'storecrew' ),
								$fb->label()
							)
						);
					}

					$clean[ $task ]['fallback'] = array(
						'provider' => $fb_provider,
						'model'    => $fb_model,
					);
				}
			}
		}

		return $clean;
	}
}
