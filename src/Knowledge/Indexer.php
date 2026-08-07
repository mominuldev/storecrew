<?php
/**
 * The indexing pipeline.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Knowledge;

use StoreCrew\Ai\EmbeddingProviderInterface;
use StoreCrew\Ai\EmbeddingRequest;
use StoreCrew\Ai\Exception\ProviderException;
use StoreCrew\Ai\ModelPolicy;
use StoreCrew\Ai\Pricing;
use StoreCrew\Ai\SpendGuard;
use StoreCrew\Api\Registry\ExtractorRegistry;
use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Database\Repositories\KnowledgeChunkRepository;
use StoreCrew\Database\Repositories\KnowledgeSourceRepository;
use StoreCrew\Database\Repositories\UsageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Extract, hash, chunk, embed, store.
 *
 * Every stage is resumable and every batch is bounded, because R-TECH-03 says
 * budget hosts terminate long-running PHP without warning. A run that dies at
 * product 3,000 of 5,000 must continue from 3,000 — restarting would re-embed
 * everything and bill the merchant twice for the same work.
 *
 * @see docs/01-prd.md FR-KB-01 … FR-KB-07, R-COST-01, R-TECH-03
 */
final class Indexer {

	public function __construct(
		private readonly ExtractorRegistry $extractors,
		private readonly ProviderRegistry $providers,
		private readonly ModelPolicy $policy,
		private readonly Chunker $chunker,
		private readonly KnowledgeSourceRepository $sources,
		private readonly KnowledgeChunkRepository $chunks,
		private readonly UsageRepository $usage,
		private readonly SpendGuard $spend,
	) {}

	/**
	 * Register or refresh one object's source record and chunks.
	 *
	 * Returns what happened, so the caller can report accurately rather than
	 * claiming work it skipped.
	 *
	 * @return array{status: string, source_id: int, chunks: int}
	 */
	public function index_object( string $source_type, int $object_id ): array {
		$extractor = $this->extractors->get( $source_type );

		if ( ! $extractor instanceof ExtractorInterface || ! $extractor->is_available() ) {
			return array(
				'status'    => 'unavailable',
				'source_id' => 0,
				'chunks'    => 0,
			);
		}

		$document = $extractor->extract( $object_id );

		if ( null === $document || $document->is_empty() ) {
			// The object stopped qualifying — unpublished, hidden, emptied. Drop
			// it so it cannot keep being retrieved.
			$this->forget( $source_type, $object_id );

			return array(
				'status'    => 'removed',
				'source_id' => 0,
				'chunks'    => 0,
			);
		}

		$upsert = $this->sources->upsert(
			$document->source_type,
			$document->object_id,
			$document->content_hash,
			$document->title,
			$document->url,
			$document->external_ref
		);

		// The cost control. An unchanged hash means the text did not move, so
		// there is nothing to re-embed no matter what else changed on the object.
		if ( ! $upsert['changed'] ) {
			return array(
				'status'    => 'unchanged',
				'source_id' => $upsert['id'],
				'chunks'    => 0,
			);
		}

		$pieces = $this->chunker->chunk( $document->content );

		if ( array() === $pieces ) {
			$this->sources->mark_indexed( $upsert['id'], 0 );

			return array(
				'status'    => 'empty',
				'source_id' => $upsert['id'],
				'chunks'    => 0,
			);
		}

		$chunk_ids = $this->chunks->replace_for_source( $upsert['id'], $pieces );

		// Without this the source stays `pending` forever: needing_index()
		// keeps returning it, index health reports nothing as indexed, and a
		// merchant watching the dashboard sees a run that never finishes.
		$this->sources->mark_indexed( $upsert['id'], count( $chunk_ids ) );

		$this->usage->record( UsageRepository::METRIC_DOCUMENT, 1 );

		return array(
			'status'    => 'chunked',
			'source_id' => $upsert['id'],
			'chunks'    => count( $chunk_ids ),
		);
	}

	/**
	 * Embed a batch of chunks that are waiting for vectors.
	 *
	 * Separate from chunking on purpose: chunking is cheap and local, embedding
	 * is billable and remote. Keeping them apart means a provider outage or an
	 * exhausted spend cap stops the expensive half without losing the cheap
	 * half's work.
	 *
	 * @return array{embedded: int, failed: int, blocked: bool, reason: string}
	 */
	/**
	 * @param list<int> $source_ids Confine the drain to these sources; empty
	 *                              means everything pending. Callers running
	 *                              under anything but the live model policy
	 *                              (test harnesses) must pass their own.
	 */
	public function embed_pending( int $batch = 50, array $source_ids = array() ): array {
		if ( ! $this->spend->allows_call() ) {
			return array(
				'embedded' => 0,
				'failed'   => 0,
				'blocked'  => true,
				'reason'   => __( 'The monthly spend limit has been reached.', 'storecrew' ),
			);
		}

		$resolved = $this->policy->resolve( ModelPolicy::TASK_EMBEDDING );

		if ( null === $resolved ) {
			return array(
				'embedded' => 0,
				'failed'   => 0,
				'blocked'  => true,
				'reason'   => __( 'No provider capable of embeddings is configured.', 'storecrew' ),
			);
		}

		$provider = $this->providers->get( $resolved['provider'] );

		if ( ! $provider instanceof EmbeddingProviderInterface ) {
			return array(
				'embedded' => 0,
				'failed'   => 0,
				'blocked'  => true,
				'reason'   => __( 'The configured embedding provider cannot embed.', 'storecrew' ),
			);
		}

		$pending = $this->chunks->needing_embedding( $batch, $resolved['model'], self::dimensions(), $source_ids );

		if ( array() === $pending ) {
			return array(
				'embedded' => 0,
				'failed'   => 0,
				'blocked'  => false,
				'reason'   => '',
			);
		}

		$texts = array();
		$ids   = array();

		foreach ( $pending as $row ) {
			$ids[]   = (int) $row->id;
			$texts[] = (string) $row->content;
		}

		try {
			$response = $provider->embed(
				new EmbeddingRequest(
					$resolved['model'],
					$texts,
					EmbeddingRequest::TASK_DOCUMENT,
					60,
					self::dimensions()
				)
			);
		} catch ( ProviderException $e ) {
			return array(
				'embedded' => 0,
				'failed'   => count( $ids ),
				'blocked'  => ! $e->is_retryable(),
				'reason'   => $e->getMessage(),
			);
		}

		// A ragged response would poison the index silently: a mismatched vector
		// scores 0.0 against every query and the chunk simply never ranks, with
		// nothing logged anywhere.
		if ( ! $response->is_uniform() || $response->count() !== count( $ids ) ) {
			return array(
				'embedded' => 0,
				'failed'   => count( $ids ),
				'blocked'  => true,
				'reason'   => __( 'The embedding provider returned an unexpected number or shape of vectors.', 'storecrew' ),
			);
		}

		$embedded = 0;

		foreach ( $response->vectors as $index => $vector ) {
			if ( $this->chunks->store_embedding( $ids[ $index ], $vector, $resolved['model'] ) ) {
				++$embedded;
			}
		}

		$cost = Pricing::estimate( $resolved['provider'], $resolved['model'], $response->usage );

		$this->usage->record(
			UsageRepository::METRIC_CHUNK,
			$embedded,
			0,
			'',
			$resolved['provider'],
			$resolved['model'],
			$cost['micros']
		);

		return array(
			'embedded' => $embedded,
			'failed'   => count( $ids ) - $embedded,
			'blocked'  => false,
			'reason'   => '',
		);
	}

	/**
	 * Configured embedding dimensionality. Zero means the model's default.
	 *
	 * Read from an option so the recall-versus-storage tradeoff can be changed
	 * and re-measured without a code change. Changing it invalidates the index:
	 * vectors of different widths score 0.0 against each other, so a re-embed
	 * is required.
	 */
	public static function dimensions(): int {
		return max( 0, (int) get_option( 'storecrew_embedding_dimensions', 1536 ) );
	}

	/**
	 * Remove an object from the index entirely.
	 */
	public function forget( string $source_type, int $object_id ): bool {
		$existing = $this->sources->find_by_key(
			KnowledgeSourceRepository::key( $source_type, $object_id )
		);

		if ( null === $existing ) {
			return false;
		}

		$this->chunks->delete_for_source( (int) $existing->id );

		return $this->sources->forget( $source_type, $object_id );
	}

	/**
	 * What indexing would cost before it starts.
	 *
	 * R-COST-01 rates a surprise provider bill as high impact. A merchant with
	 * 50,000 products deserves the number before the run, not after — and when
	 * the model has no published rate, this says so rather than showing a
	 * confident zero.
	 *
	 * @return array{objects: array<string, int>, total: int, estimatedChunks: int, costKnown: bool, costMicros: int}
	 */
	public function estimate(): array {
		$counts = $this->extractors->counts();
		$total  = array_sum( $counts );

		// Three chunks per object is a planning figure, not a measurement.
		$estimated_chunks = $total * 3;

		$resolved = $this->policy->resolve( ModelPolicy::TASK_EMBEDDING );

		if ( null === $resolved ) {
			return array(
				'objects'         => $counts,
				'total'           => $total,
				'estimatedChunks' => $estimated_chunks,
				'costKnown'       => false,
				'costMicros'      => 0,
			);
		}

		$tokens = $estimated_chunks * 400;

		$cost = Pricing::estimate(
			$resolved['provider'],
			$resolved['model'],
			new \StoreCrew\Ai\TokenUsage( $tokens )
		);

		return array(
			'objects'         => $counts,
			'total'           => $total,
			'estimatedChunks' => $estimated_chunks,
			'costKnown'       => $cost['known'],
			'costMicros'      => $cost['micros'],
		);
	}

	/**
	 * Index health, for the dashboard.
	 *
	 * @return array{sources: array<string, int>, chunks: int, embedded: int, pending: int}
	 */
	public function health(): array {
		$resolved = $this->policy->resolve( ModelPolicy::TASK_EMBEDDING );

		$model = $resolved['model'] ?? '';
		$dims  = self::dimensions();

		$chunks = $this->chunks->count();

		// With no embedding model configured, nothing is searchable — not
		// because the vectors are missing, but because the *query* cannot be
		// embedded either, and vectors from some earlier model are not
		// comparable to whatever gets configured next.
		//
		// The repository's `''` means "do not filter by model", which is right
		// for counting raw rows but wrong for answering "is the index ready".
		// Taking the raw count here is what reported a full, healthy index on
		// an install that could not answer a single question.
		if ( '' === $model ) {
			return array(
				'sources'    => $this->sources->status_counts(),
				'chunks'     => $chunks,
				'embedded'   => 0,
				'pending'    => $chunks,
				'model'      => '',
				'dimensions' => $dims,
				'mismatched' => $this->chunks->count_embedded(),
			);
		}

		$embedded = $this->chunks->count_embedded( $model, $dims );

		return array(
			'sources'    => $this->sources->status_counts(),
			'chunks'     => $chunks,
			'embedded'   => $embedded,
			'pending'    => max( 0, $chunks - $embedded ),
			'model'      => $model,
			'dimensions' => $dims,
			// Embedded, but with the wrong model or width — so scoring 0.0
			// against every query while looking perfectly healthy.
			'mismatched' => $this->chunks->count_mismatched( $model, $dims ),
		);
	}
}
