<?php
/**
 * Retrieval for grounded answers.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Knowledge;

use StoreCrew\Ai\EmbeddingProviderInterface;
use StoreCrew\Ai\EmbeddingRequest;
use StoreCrew\Ai\Exception\ProviderException;
use StoreCrew\Ai\ModelPolicy;
use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Database\Repositories\KnowledgeChunkRepository;
use StoreCrew\Database\Repositories\KnowledgeSourceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a question into grounded context.
 *
 * Embeds the query with the **query-side** task type where the provider
 * distinguishes them (FR-KB-06) and hands it to the two-stage hybrid search.
 * Using the document task type for a query costs recall on providers that model
 * the difference, and nothing errors — the answers are just quietly worse.
 *
 * When embedding fails or no embedding provider is configured, retrieval falls
 * back to lexical-only rather than returning nothing. A degraded answer beats
 * no answer, and the strategy is reported so the caller can say which it got.
 *
 * @see docs/04-database-schema.md § 6
 */
final class Retriever {

	public function __construct(
		private readonly ProviderRegistry $providers,
		private readonly ModelPolicy $policy,
		private readonly KnowledgeChunkRepository $chunks,
		private readonly KnowledgeSourceRepository $sources,
	) {}

	/**
	 * Retrieve context for a question.
	 *
	 * @return array{
	 *     results: list<array<string, mixed>>,
	 *     strategy: string,
	 *     candidates: int,
	 *     truncated: bool,
	 *     degraded: string
	 * }
	 */
	public function retrieve( string $query, int $limit = 5, float $dense_weight = 0.8 ): array {
		$degraded = '';
		$vector   = array();

		try {
			$vector = $this->embed_query( $query );
		} catch ( ProviderException $e ) {
			// Lexical-only still answers many questions. Record why the dense arm
			// is missing so index health can show it rather than leaving a
			// merchant wondering why results got worse.
			$degraded = $e->getMessage();
		}

		if ( array() === $vector && '' === $degraded ) {
			$degraded = __( 'No embedding provider is configured, so search is keyword-only.', 'storecrew' );
		}

		$found = $this->chunks->search( $query, $vector, $limit, $dense_weight );

		$results = array();

		foreach ( $found['results'] as $row ) {
			$source = $this->sources->find( (int) $row['source_id'] );

			$results[] = $row + array(
				'sourceType'  => null !== $source ? (string) $source->source_type : '',
				'sourceTitle' => null !== $source ? (string) $source->title : '',
				'sourceUrl'   => null !== $source ? (string) $source->url : '',
				// The originating object id, so a caller can read live price and
				// stock for it — those are never in the chunk text (FR-KB-08).
				'objectId'    => null !== $source ? (int) $source->object_id : 0,
			);
		}

		/**
		 * Post-process retrieved chunks before they enter a prompt.
		 *
		 * Retrieved content is untrusted input and must stay that way — it can
		 * never alter tool authorisation or agent routing.
		 *
		 * @param list<array<string, mixed>> $results Retrieved chunks.
		 * @param string                     $query   Original query.
		 */
		$results = apply_filters( 'storecrew_retrieval_results', $results, $query );

		return array(
			'results'    => $results,
			'strategy'   => $found['strategy'],
			'candidates' => $found['candidates'],
			'truncated'  => $found['truncated'],
			'degraded'   => $degraded,
		);
	}

	/**
	 * Embed the query, using the query-side task type.
	 *
	 * @return list<float>
	 *
	 * @throws ProviderException When the provider call fails.
	 */
	private function embed_query( string $query ): array {
		$resolved = $this->policy->resolve( ModelPolicy::TASK_EMBEDDING );

		if ( null === $resolved ) {
			return array();
		}

		$provider = $this->providers->get( $resolved['provider'] );

		if ( ! $provider instanceof EmbeddingProviderInterface ) {
			return array();
		}

		/**
		 * Rewrite a retrieval query before it is embedded.
		 *
		 * @param string $query The query.
		 */
		$query = (string) apply_filters( 'storecrew_retrieval_query', $query );

		$response = $provider->embed(
			new EmbeddingRequest(
				$resolved['model'],
				array( $query ),
				// FR-KB-06. This is the whole point of the task parameter.
				EmbeddingRequest::TASK_QUERY
			)
		);

		return $response->vectors[0] ?? array();
	}
}
