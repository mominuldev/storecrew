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

	/**
	 * Corpus size below which every query gets a full dense scan.
	 *
	 * Measured, not guessed. Cosine over a 1536-dimension vector costs about
	 * 90 microseconds in PHP, so a full scan is roughly:
	 *
	 *     1,000 chunks -> 91 ms      30,000 chunks -> 2.7 s
	 *     2,000 chunks -> 182 ms    150,000 chunks -> 13.6 s
	 *
	 * 2,000 keeps the scan inside the 300 ms p95 retrieval budget with headroom.
	 *
	 * This threshold exists because the two arms are not equally good. On a
	 * fixture set of ten shopper questions, full dense scan scored **1.00
	 * recall@3** and the lexical-prefilter path scored **0.80** — below the 0.88
	 * bar FR-KB-09 sets. The prefilter's failure is structural rather than a
	 * tuning problem: MySQL FULLTEXT cannot match "warm hat for winter" to a
	 * product named "Beanie", because they share no words. Widening the
	 * candidate limit cannot help when the correct answer scores zero lexically
	 * and is never a candidate at all.
	 *
	 * Above this size the prefilter is used anyway, because a multi-second scan
	 * is worse than an imperfect answer — but recall really is lower there, and
	 * `search()` reports which path ran so that is visible rather than assumed.
	 * Fixing the large-corpus case properly needs a real vector index, which is
	 * the escape hatch R-TECH-01 always named.
	 */
	public const DENSE_SCAN_THRESHOLD = 2000;

	/**
	 * Default weight given to the dense arm when fusing scores.
	 *
	 * **1.0 — the lexical term contributes nothing to ranking by default, and
	 * that is a measured decision rather than a preference.** A sweep over 23
	 * shopper-phrased fixture questions:
	 *
	 *     dense 0.80 -> recall@3 0.83     dense 0.90 -> 0.91
	 *     dense 0.95 -> recall@3 0.91     dense 1.00 -> 0.96
	 *
	 * Recall improves monotonically as lexical influence falls, and 0.80 — the
	 * previous default — failed the 0.88 bar outright. The mechanism is the
	 * normalisation: the lexical score is scaled against the best match *within
	 * the candidate set*, so the top keyword hit always scores 1.0 however weak
	 * the absolute match. On a narrow candidate set that lets an incidental word
	 * overlap outrank a strong semantic match, which is how "warm hat for
	 * winter" returned a wholesale policy page.
	 *
	 * The obvious counter-argument — that lexical rescues exact identifier
	 * lookups — was tested and did not hold: exact product names are retrieved
	 * correctly at every weight, and an exact SKU fails at every weight. SKU
	 * lookup needs its own exact-match tool, not a scoring tweak.
	 *
	 * Lexical is still load-bearing as the **prefilter** above
	 * DENSE_SCAN_THRESHOLD, where scanning everything is too slow. That is
	 * candidate selection, not scoring, and it is a different job.
	 */
	public const DEFAULT_DENSE_WEIGHT = 1.0;

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
	 * Chunks still awaiting a usable embedding.
	 *
	 * "Usable" is doing real work here. A vector embedded at a different width,
	 * or by a different model, is **worse than no vector**: cosine similarity
	 * against a mismatched width returns 0.0, so the chunk silently never ranks
	 * and nothing anywhere reports a problem. Treating those as pending is what
	 * makes changing the embedding model or dimensionality a safe, self-healing
	 * operation rather than a quiet corruption of the whole index.
	 *
	 * @param string $model      Current embedding model, or '' to ignore.
	 * @param int    $dimensions Current width, or 0 to ignore.
	 *
	 * @return list<object>
	 */
	public function needing_embedding( int $limit = 100, string $model = '', int $dimensions = 0, array $source_ids = array() ): array {
		$table = $this->table_name();

		$stale  = array( 'embedding IS NULL' );
		$params = array();

		if ( '' !== $model ) {
			$stale[]  = 'embedding_model <> %s';
			$params[] = $model;
		}

		if ( $dimensions > 0 ) {
			$stale[]  = 'embedding_dims <> %d';
			$params[] = $dimensions;
		}

		$where = '(' . implode( ' OR ', $stale ) . ')';

		// An explicit scope confines the drain to the named sources. The case
		// that demanded it: a consumer running under a different model policy —
		// a test suite's fake provider — sees every real chunk as mismatched
		// and re-embeds a merchant's whole corpus with vectors that score 0.0.
		$source_ids = array_values( array_filter( array_map( 'intval', $source_ids ) ) );

		if ( array() !== $source_ids ) {
			$where .= ' AND source_id IN (' . implode( ', ', array_fill( 0, count( $source_ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $source_ids );
		}

		$params[] = $limit;

		// Never-embedded chunks drain before mismatched ones. Both are equally
		// unsearchable, so production loses nothing — and a consumer that
		// forgot its scope at least exhausts fresh fixtures before touching a
		// corpus's stale-but-real vectors.
		$sql = "SELECT id, source_id, content FROM {$table} WHERE {$where}"
			. ' ORDER BY (embedding IS NULL) DESC, id ASC LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->get_results( $this->db->prepare( $sql, ...$params ) );
	}

	/**
	 * Chunks whose vector does not match the current model and width.
	 *
	 * Surfaced separately so index health can say "1,200 chunks were embedded
	 * with a model you no longer use" rather than reporting a healthy index
	 * that quietly returns nothing.
	 */
	public function count_mismatched( string $model, int $dimensions ): int {
		if ( '' === $model ) {
			return 0;
		}

		$table = $this->table_name();

		$sql = "SELECT COUNT(*) FROM {$table} WHERE embedding IS NOT NULL AND (embedding_model <> %s";

		$params = array( $model );

		if ( $dimensions > 0 ) {
			$sql     .= ' OR embedding_dims <> %d';
			$params[] = $dimensions;
		}

		$sql .= ')';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->db->get_var( $this->db->prepare( $sql, ...$params ) );
	}

	public function delete_for_source( int $source_id ): int {
		$deleted = $this->db->delete( $this->table_name(), array( 'source_id' => $source_id ), array( '%d' ) );

		return false === $deleted ? 0 : (int) $deleted;
	}

	public function count(): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() );
	}

	public function count_embedded( string $model = '', int $dimensions = 0 ): int {
		$table = $this->table_name();

		if ( '' === $model ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$table} WHERE embedding IS NOT NULL" );
		}

		$sql    = "SELECT COUNT(*) FROM {$table} WHERE embedding IS NOT NULL AND embedding_model = %s";
		$params = array( $model );

		if ( $dimensions > 0 ) {
			$sql     .= ' AND embedding_dims = %d';
			$params[] = $dimensions;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->db->get_var( $this->db->prepare( $sql, ...$params ) );
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
		?float $dense_weight = null,
		int $candidate_limit = self::DEFAULT_CANDIDATES
	): array {
		/**
		 * Weight given to the dense arm when fusing retrieval scores.
		 *
		 * @param float $weight Between 0 (lexical only) and 1 (dense only).
		 */
		$dense_weight = $dense_weight ?? (float) apply_filters(
			'storecrew_dense_weight',
			self::DEFAULT_DENSE_WEIGHT
		);

		$table     = $this->table_name();
		$strategy  = 'hybrid';
		$truncated = false;

		// Small corpus: skip the prefilter entirely and score everything. This
		// is the accurate path, and the measurement says so — see
		// DENSE_SCAN_THRESHOLD.
		if ( array() !== $query_vector && $this->count_embedded() <= self::DENSE_SCAN_THRESHOLD ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$candidates = $this->db->get_results(
				$this->db->prepare(
					"SELECT id, source_id, content, embedding,
					        MATCH(content) AGAINST(%s IN NATURAL LANGUAGE MODE) AS lex
					 FROM {$table}
					 WHERE embedding IS NOT NULL
					 LIMIT %d",
					$query,
					self::MAX_DENSE_SCAN
				)
			);

			if ( is_array( $candidates ) && array() !== $candidates ) {
				return $this->score( $candidates, $query_vector, $limit, $dense_weight, 'dense_full', false );
			}
		}

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

		return $this->score( $candidates, $query_vector, $limit, $dense_weight, $strategy, $truncated );
	}

	/**
	 * Fuse dense and lexical scores over a candidate set.
	 *
	 * @param list<object> $candidates Rows carrying embedding and lex score.
	 * @param list<float>  $query_vector Embedded query.
	 *
	 * @return array{results: list<array<string, mixed>>, strategy: string, candidates: int, truncated: bool}
	 */
	private function score(
		array $candidates,
		array $query_vector,
		int $limit,
		float $dense_weight,
		string $strategy,
		bool $truncated
	): array {
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
			// Note on the lexical term: it is normalised against the best match
			// *in this candidate set*, so the top lexical hit always scores 1.0
			// however weak the absolute match. On a small candidate set that
			// lets a barely-relevant keyword hit outrank a strong semantic one,
			// which is precisely how "warm hat for winter" returned a wholesale
			// policy page. Dense weight is high by default for that reason.
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
