<?php
/**
 * Chunk persistence and hybrid retrieval.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Repositories;

use StoreCrew\Database\Repository;
use StoreCrew\Database\Tables;
use StoreCrew\Knowledge\Vector;

defined( 'ABSPATH' ) || exit;

/**
 * Chunks, their embeddings, and the retrieval that reads them.
 *
 * This class owns the vector storage format entirely. Nothing outside it knows
 * that embeddings are packed float32 in a LONGBLOB — which is what makes the
 * escape hatch in R-TECH-01 real: if the recall measurement required by
 * FR-KB-09 comes in below 0.88, swapping to an external vector store changes
 * this file and nothing else.
 *
 * @see docs/04-database-schema.md § 5.2, § 6
 */
final class KnowledgeChunkRepository extends Repository {

	/**
	 * How many chunks the lexical arm hands to the dense reranker.
	 */
	public const DEFAULT_CANDIDATES = 200;

	/**
	 * Minimum lexical hits before the dense fallback engages.
	 *
	 * Deliberately 1: the fallback fires only when the lexical arm returns
	 * *nothing at all*. An earlier value of 3 was wrong and the repository
	 * suite caught it — a query matching one or two chunks is a precise query,
	 * not a failed one, and treating it as failure triggered the full dense
	 * scan this whole two-stage design exists to avoid. Raising this trades
	 * away the design's only performance guarantee.
	 */
	public const LEXICAL_FLOOR = 1;

	/**
	 * Hard ceiling on the dense fallback scan.
	 *
	 * A full scan is exactly what the two-stage design exists to avoid, so when
	 * it does happen it is bounded and reported rather than silently quadratic.
	 */
	public const MAX_DENSE_SCAN = 5000;

	protected function table(): string {
		return Tables::KNOWLEDGE_CHUNKS;
	}

	/**
	 * Replace every chunk belonging to a source.
	 *
	 * Delete-then-insert rather than diffing: chunk boundaries shift when
	 * content changes, so chunk 3 of the old text and chunk 3 of the new text
	 * are not the same thing and matching them up would be fiction.
	 *
	 * @param list<array{content: string, tokens?: int}> $chunks Ordered chunks.
	 *
	 * @return list<int> Inserted chunk ids, in order.
	 */
	public function replace_for_source( int $source_id, array $chunks ): array {
		$this->delete_for_source( $source_id );

		$ids = array();
		$now = $this->now();

		foreach ( array_values( $chunks ) as $index => $chunk ) {
			$id = $this->insert_row(
				array(
					'source_id'      => $source_id,
					'chunk_index'    => $index,
					'content'        => $chunk['content'],
					'content_tokens' => (int) ( $chunk['tokens'] ?? 0 ),
					'created_at'     => $now,
				),
				array( '%d', '%d', '%s', '%d', '%s' )
			);

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Attach an embedding to a chunk.
	 *
	 * @param list<float> $vector Embedding.
	 */
	public function store_embedding( int $chunk_id, array $vector, string $model ): bool {
		return $this->update_by_id(
			$chunk_id,
			array(
				'embedding'       => Vector::encode( $vector ),
				'embedding_model' => $model,
				'embedding_dims'  => count( $vector ),
				'embedded_at'     => $this->now(),
			),
			array( '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Chunks still awaiting an embedding.
	 *
	 * @return list<object>
	 */
	public function needing_embedding( int $limit = 100 ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT id, source_id, content FROM {$table} WHERE embedding IS NULL ORDER BY id ASC LIMIT %d",
				$limit
			)
		);
	}

	public function delete_for_source( int $source_id ): int {
		$deleted = $this->db->delete( $this->table_name(), array( 'source_id' => $source_id ), array( '%d' ) );

		return false === $deleted ? 0 : (int) $deleted;
	}

	public function count(): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() );
	}

	public function count_embedded(): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE embedding IS NOT NULL' );
	}

	/**
	 * Hybrid retrieval: lexical prefilter, then dense rerank.
	 *
	 * Stage 1 uses the FULLTEXT index to narrow the corpus to a few hundred
	 * candidates — an indexed operation. Stage 2 computes cosine similarity in
	 * PHP over only those candidates. 200 x 1536 floats is around 1.2 MB of
	 * arithmetic, which fits the 300 ms p95 budget where scanning an entire
	 * corpus would not.
	 *
	 * When the lexical arm returns almost nothing — a query phrased in words
	 * absent from the corpus — it falls back to a bounded dense scan rather
	 * than returning an empty set. That fallback is capped and flagged in the
	 * result so a caller can see it happened.
	 *
	 * @param list<float> $query_vector Embedded query. Empty to run lexical-only.
	 *
	 * @return array{
	 *     results: list<array{id: int, source_id: int, content: string, score: float, lexical: float, dense: float}>,
	 *     strategy: string,
	 *     candidates: int,
	 *     truncated: bool
	 * }
	 */
	public function search(
		string $query,
		array $query_vector = array(),
		int $limit = 5,
		float $dense_weight = 0.8,
		int $candidate_limit = self::DEFAULT_CANDIDATES
	): array {
		$table     = $this->table_name();
		$strategy  = 'hybrid';
		$truncated = false;

		// Stage 1 — lexical prefilter over the FULLTEXT index.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$candidates = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, source_id, content, embedding,
				        MATCH(content) AGAINST(%s IN NATURAL LANGUAGE MODE) AS lex
				 FROM {$table}
				 WHERE MATCH(content) AGAINST(%s IN NATURAL LANGUAGE MODE)
				 ORDER BY lex DESC
				 LIMIT %d",
				$query,
				$query,
				$candidate_limit
			)
		);

		if ( ! is_array( $candidates ) ) {
			$candidates = array();
		}

		// Fallback — the lexical arm found nothing useful.
		if ( count( $candidates ) < self::LEXICAL_FLOOR && array() !== $query_vector ) {
			$strategy = 'dense_fallback';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$candidates = $this->db->get_results(
				$this->db->prepare(
					"SELECT id, source_id, content, embedding, 0 AS lex
					 FROM {$table}
					 WHERE embedding IS NOT NULL
					 ORDER BY id ASC
					 LIMIT %d",
					self::MAX_DENSE_SCAN
				)
			);

			if ( ! is_array( $candidates ) ) {
				$candidates = array();
			}

			$truncated = count( $candidates ) >= self::MAX_DENSE_SCAN;

			if ( $truncated ) {
				/**
				 * Fires when a dense fallback scan hit its ceiling.
				 *
				 * Recall is incomplete when this fires. Surfaced rather than
				 * swallowed so index-health can report it honestly.
				 *
				 * @param string $query Original query.
				 * @param int    $cap   Scan ceiling.
				 */
				do_action( 'storecrew_retrieval_truncated', $query, self::MAX_DENSE_SCAN );
			}
		}

		if ( array() === $candidates ) {
			return array(
				'results'    => array(),
				'strategy'   => 'empty',
				'candidates' => 0,
				'truncated'  => false,
			);
		}

		// Stage 2 — score.
		$max_lex = 0.0;

		foreach ( $candidates as $row ) {
			$max_lex = max( $max_lex, (float) $row->lex );
		}

		$dense_available = array() !== $query_vector;

		if ( ! $dense_available ) {
			$strategy     = 'lexical_only';
			$dense_weight = 0.0;
		}

		$scored = array();

		foreach ( $candidates as $row ) {
			// MATCH relevance is unbounded, so normalise against this result
			// set's own maximum to make it comparable with cosine.
			$lexical = $max_lex > 0.0 ? (float) $row->lex / $max_lex : 0.0;

			$dense = 0.0;

			if ( $dense_available && null !== $row->embedding && '' !== $row->embedding ) {
				// Cosine is [-1, 1]; map to [0, 1] so fusion weights mean what
				// they look like they mean.
				$dense = ( Vector::cosine_against_blob( $query_vector, (string) $row->embedding ) + 1.0 ) / 2.0;
			}

			$scored[] = array(
				'id'        => (int) $row->id,
				'source_id' => (int) $row->source_id,
				'content'   => (string) $row->content,
				'lexical'   => $lexical,
				'dense'     => $dense,
				'score'     => ( $dense_weight * $dense ) + ( ( 1.0 - $dense_weight ) * $lexical ),
			);
		}

		usort( $scored, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return array(
			'results'    => array_slice( $scored, 0, $limit ),
			'strategy'   => $strategy,
			'candidates' => count( $candidates ),
			'truncated'  => $truncated,
		);
	}
}
