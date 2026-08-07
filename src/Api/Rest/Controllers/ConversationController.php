<?php
/**
 * Conversation inspection.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Rest\Controllers;

use StoreCrew\Api\Rest\RestController;
use StoreCrew\Core\Capabilities\Capabilities;
use StoreCrew\Database\Repositories\AgentRunRepository;
use StoreCrew\Database\Repositories\ConversationRepository;
use StoreCrew\Database\Repositories\MessageRepository;
use StoreCrew\Database\Repositories\ToolCallRepository;
use StoreCrew\Licensing\FeatureGate;

defined( 'ABSPATH' ) || exit;

/**
 * The conversation inspector (FR-ADMIN-04).
 *
 * Answers "why did the agent say that" after the fact: the turns, the runs
 * behind them, the retrieval trace, and every tool call with its arguments and
 * result. Without this, a bad answer is unexplainable and therefore unfixable.
 *
 * Conversations are addressed by **uuid**, never by the auto-increment id.
 * Exposing sequential ids would let anyone with the capability enumerate the
 * store's entire conversation history by counting, and would leak conversation
 * volume to anyone who saw a single URL.
 */
final class ConversationController extends RestController {

	public function __construct(
		FeatureGate $features,
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly AgentRunRepository $runs,
		private readonly ToolCallRepository $calls,
	) {
		parent::__construct( $features );
	}

	public function register_routes(): void {
		$this->route(
			'/conversations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
				'args'                => array(
					'limit'  => array(
						'type'    => 'integer',
						'default' => 25,
					),
					'offset' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'status' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		$this->route(
			'/conversations/(?P<uuid>[a-f0-9-]{36})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
			)
		);

		$this->route(
			'/approvals',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'approvals' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE_AGENTS ),
			)
		);

		$this->route(
			'/approvals/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'decide' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE_AGENTS ),
				'args'                => array(
					'decision' => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => static fn ( $v ): bool => in_array( $v, array( 'approve', 'deny' ), true ),
					),
				),
			)
		);
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$limit  = max( 1, min( 100, (int) $request->get_param( 'limit' ) ) );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );

		$rows = array();

		foreach ( $this->conversations->recent( $limit, $offset, $status ) as $row ) {
			$rows[] = array(
				'uuid'             => (string) $row->uuid,
				'status'           => (string) $row->status,
				'channel'          => (string) $row->channel,
				'messageCount'     => (int) $row->message_count,
				'runCount'         => (int) $row->run_count,
				'identityVerified' => '1' === (string) $row->identity_verified,
				'startedAt'        => (string) $row->started_at,
				'lastActivityAt'   => (string) $row->last_activity_at,
			);
		}

		return $this->ok( $rows );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$conversation = $this->conversations->find_by_uuid( (string) $request->get_param( 'uuid' ) );

		if ( null === $conversation ) {
			return $this->error( 'not_found', __( 'Conversation not found.', 'storecrew' ), 404 );
		}

		$id = (int) $conversation->id;

		$turns = array();

		foreach ( $this->messages->for_conversation( $id ) as $message ) {
			$turns[] = array(
				'role'      => (string) $message->role,
				'agentId'   => (string) $message->agent_id,
				'content'   => (string) $message->content,
				'tokensIn'  => (int) $message->tokens_in,
				'tokensOut' => (int) $message->tokens_out,
				'createdAt' => (string) $message->created_at,
			);
		}

		$runs = array();

		foreach ( $this->runs->for_conversation( $id ) as $run ) {
			$tool_calls = array();

			foreach ( $this->calls->for_run( (int) $run->id ) as $call ) {
				$tool_calls[] = array(
					'id'         => (int) $call->id,
					'toolId'     => (string) $call->tool_id,
					'intent'     => (string) $call->intent,
					'authMode'   => (string) $call->auth_mode,
					'status'     => (string) $call->status,
					'arguments'  => json_decode( (string) $call->arguments, true ),
					'result'     => json_decode( (string) $call->result, true ),
					'durationMs' => (int) $call->duration_ms,
				);
			}

			$runs[] = array(
				'id'         => (int) $run->id,
				'agentId'    => (string) $run->agent_id,
				'provider'   => (string) $run->provider,
				'model'      => (string) $run->model,
				'status'     => (string) $run->status,
				'tokensIn'   => (int) $run->tokens_in,
				'tokensOut'  => (int) $run->tokens_out,
				'costMicros' => (int) $run->cost_micros,
				// False means the model had no published rate and the figure
				// under-counts — surfaced so unknown never renders as free.
				'costKnown'  => 1 === (int) $run->cost_known,
				'latencyMs'  => (int) $run->latency_ms,
				// Chunk ids and scores, never chunk text — that is what makes
				// FR-KB-10 answerable without duplicating the corpus.
				'retrieved'  => $this->runs->retrieved( (int) $run->id ),
				'errorCode'  => (string) $run->error_code,
				'toolCalls'  => $tool_calls,
			);
		}

		return $this->ok(
			array(
				'uuid'             => (string) $conversation->uuid,
				'status'           => (string) $conversation->status,
				'channel'          => (string) $conversation->channel,
				'identityVerified' => '1' === (string) $conversation->identity_verified,
				'verifiedOrderId'  => (int) $conversation->verified_order_id,
				'startedAt'        => (string) $conversation->started_at,
				'turns'            => $turns,
				'runs'             => $runs,
			)
		);
	}

	/**
	 * Writes waiting for a human (FR-ADMIN-06).
	 */
	public function approvals(): \WP_REST_Response {
		$rows = array();

		foreach ( $this->calls->approval_queue( 50 ) as $call ) {
			$rows[] = array(
				'id'        => (int) $call->id,
				'toolId'    => (string) $call->tool_id,
				'arguments' => json_decode( (string) $call->arguments, true ),
				'createdAt' => (string) $call->created_at,
			);
		}

		return $this->ok( $rows );
	}

	public function decide( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id       = (int) $request->get_param( 'id' );
		$decision = (string) $request->get_param( 'decision' );

		$ok = 'approve' === $decision
			? $this->calls->approve( $id, get_current_user_id() )
			: $this->calls->deny( $id, get_current_user_id() );

		if ( ! $ok ) {
			// The repository only transitions a genuinely pending call, so this
			// covers both "already decided" and "never existed" — deciding an
			// executed call would be a lie in the audit trail.
			return $this->error(
				'not_pending',
				__( 'That action is no longer awaiting approval.', 'storecrew' ),
				409
			);
		}

		return $this->ok(
			array(
				'id'       => $id,
				'decision' => $decision,
			)
		);
	}
}
