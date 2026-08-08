<?php
/**
 * Who is on duty.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Rest\Controllers;

use StoreCrew\Agent\Agent;
use StoreCrew\Api\Registry\AgentRegistry;
use StoreCrew\Api\Rest\RestController;
use StoreCrew\Core\Capabilities\Capabilities;
use StoreCrew\Database\Repositories\AgentConfigRepository;
use StoreCrew\Database\Repositories\AuditLogRepository;
use StoreCrew\Licensing\FeatureGate;

defined( 'ABSPATH' ) || exit;

/**
 * The roster, and the one control over it that exists today.
 *
 * FR-ADMIN-02's fourth step is agent activation, and until now "on duty" was
 * something the console inferred from entitlement — the `enabled` column the
 * orchestrator has always honoured had no way to be written. This is that way.
 *
 * Deliberately not the whole of FR-ADMIN-05: persona editing and per-tool
 * autonomy are Phase 2 UI over the same rows. The route surfaces those fields
 * read-only so the screen can say what a stood-down agent still carries, and
 * writes exactly one of them.
 *
 * @see docs/01-prd.md FR-ADMIN-02, FR-AGENT-01
 */
final class AgentController extends RestController {

	public function __construct(
		FeatureGate $features,
		private readonly AgentRegistry $agents,
		private readonly AgentConfigRepository $configs,
		private readonly AuditLogRepository $audit,
	) {
		parent::__construct( $features );
	}

	public function register_routes(): void {
		$this->route(
			'/agents',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
			)
		);

		$this->route(
			'/agents/(?P<id>[A-Za-z0-9._-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE_AGENTS ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public function index(): \WP_REST_Response {
		$rows = array();

		foreach ( $this->agents->all() as $id => $agent ) {
			$rows[] = $this->present( (string) $id, $agent );
		}

		return $this->ok( $rows );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id    = (string) $request->get_param( 'id' );
		$agent = $this->agents->get( $id );

		if ( ! $agent instanceof Agent ) {
			return $this->error( 'unknown_agent', __( 'That agent does not exist.', 'storecrew' ), 404 );
		}

		$body = $request->get_json_params();

		if ( ! is_array( $body ) || ! array_key_exists( 'enabled', $body ) ) {
			return $this->error( 'invalid_body', __( 'Expected an "enabled" flag.', 'storecrew' ) );
		}

		$enabled = (bool) $body['enabled'];

		// Standing up an agent the plan does not include would be a licence
		// bypass by the back door: the orchestrator re-checks entitlement, so
		// the row would simply never take effect, and the console would show an
		// agent as on duty that can never answer.
		if ( $enabled && '' !== $agent->feature && ! $this->features->enabled( $agent->feature ) ) {
			return $this->error(
				'not_entitled',
				__( 'That agent is not available on your plan.', 'storecrew' ),
				403
			);
		}

		$this->configs->set_enabled( $id, $enabled );

		$this->audit->record(
			'agent.' . ( $enabled ? 'enabled' : 'disabled' ),
			AuditLogRepository::ACTOR_USER,
			(string) get_current_user_id(),
			'agent',
			0,
			array( 'agent_id' => $id ),
			isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : ''
		);

		return $this->ok( $this->present( $id, $agent ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present( string $id, Agent $agent ): array {
		$config = $this->configs->get( $id );

		return array(
			'id'         => $id,
			'label'      => $agent->label,
			'mission'    => $agent->mission,
			'feature'    => $agent->feature,
			'toolIds'    => $agent->tool_ids,
			// Who this agent answers. The console lists every agent side by
			// side, and without this a merchant has no way to tell that one of
			// them never picks up a storefront conversation — an on/off switch
			// over an agent whose reach you cannot see is a switch you cannot
			// reason about.
			'audience'   => $agent->audience,
			'entitled'   => '' === $agent->feature || $this->features->enabled( $agent->feature ),
			// No row means shipped defaults, which are on — the same reading
			// the orchestrator takes, so the console cannot disagree with the
			// thing actually routing turns.
			'enabled'    => null === $config || $config['enabled'],
			'persona'    => null === $config ? '' : $config['persona'],
			'configured' => null !== $config,
		);
	}
}
