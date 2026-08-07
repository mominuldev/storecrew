<?php
/**
 * Knowledge base inspection.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Rest\Controllers;

use StoreCrew\Api\Rest\RestController;
use StoreCrew\Core\Capabilities\Capabilities;
use StoreCrew\Database\Repositories\KnowledgeSourceRepository;
use StoreCrew\Knowledge\Retriever;
use StoreCrew\Licensing\FeatureGate;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a merchant see what the agent would retrieve.
 *
 * FR-KB-10 requires the merchant be able to inspect what was retrieved for an
 * answer. This is the interactive half of that: type a question, see the exact
 * chunks and scores that would ground it. Without it, "the agent gave a bad
 * answer" is unfalsifiable — there is no way to tell a retrieval problem from a
 * prompt problem.
 *
 * The response carries the retrieval **strategy** too, because "hybrid",
 * "lexical only", and "dense fallback" fail for different reasons and the fix
 * differs for each.
 */
final class KnowledgeController extends RestController {

	public function __construct(
		FeatureGate $features,
		private readonly Retriever $retriever,
		private readonly KnowledgeSourceRepository $sources,
	) {
		parent::__construct( $features );
	}

	public function register_routes(): void {
		$this->route(
			'/knowledge/search',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
				'args'                => array(
					'query' => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => static fn ( $v ): bool => is_string( $v ) && '' !== trim( $v ),
					),
					'limit' => array(
						'type'    => 'integer',
						'default' => 5,
					),
				),
			)
		);

		$this->route(
			'/knowledge/sources',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'sources' ),
				'permission_callback' => $this->permission( Capabilities::MANAGE ),
			)
		);
	}

	/**
	 * Run a retrieval exactly as the agent would.
	 *
	 * POST rather than GET: it embeds the query, which costs money. A GET would
	 * be prefetchable and cacheable, and neither is true of a billable call.
	 */
	public function search( \WP_REST_Request $request ): \WP_REST_Response {
		$query = trim( (string) $request->get_param( 'query' ) );
		$limit = max( 1, min( 20, (int) $request->get_param( 'limit' ) ) );

		$found = $this->retriever->retrieve( $query, $limit );

		return $this->ok(
			array(
				'query'      => $query,
				// hybrid | lexical_only | dense_fallback | empty
				'strategy'   => $found['strategy'],
				'candidates' => $found['candidates'],
				// True when a dense fallback hit its ceiling: recall is
				// incomplete and the merchant should know rather than assume
				// the corpus simply lacks an answer.
				'truncated'  => $found['truncated'],
				'degraded'   => $found['degraded'],
				'results'    => $found['results'],
			)
		);
	}

	public function sources(): \WP_REST_Response {
		return $this->ok(
			array(
				'statusCounts' => $this->sources->status_counts(),
				'needingIndex' => count( $this->sources->needing_index( 100 ) ),
			)
		);
	}
}
