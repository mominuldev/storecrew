<?php
/**
 * Knowledge source extractor contract.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a class of WordPress object into indexable text.
 *
 * Implementations must honour FR-KB-08: **never emit a price, stock level,
 * stock status, or order state.** Those are read live at request time. A chunk
 * that embedded "£24.99, 3 in stock" keeps asserting it after the merchant
 * changes it, and no amount of prompt engineering repairs a stale corpus.
 */
interface ExtractorInterface {

	/**
	 * Source type this extractor produces, e.g. "product".
	 */
	public function source_type(): string;

	/**
	 * Human-readable label for the indexing UI.
	 */
	public function label(): string;

	/**
	 * Whether this extractor can run on this installation.
	 *
	 * The product extractor returns false without WooCommerce, so an install
	 * that loses Woo degrades to indexing pages rather than fataling.
	 */
	public function is_available(): bool;

	/**
	 * Total number of objects available to index.
	 */
	public function count(): int;

	/**
	 * A page of object ids, ascending.
	 *
	 * Paged by id rather than offset so a run that resumes mid-way cannot skip
	 * or duplicate rows when the underlying set changes between batches.
	 *
	 * @return list<int>
	 */
	public function ids( int $after_id = 0, int $limit = 50 ): array;

	/**
	 * Extract one object, or null when it should not be indexed.
	 */
	public function extract( int $object_id ): ?ExtractedDocument;
}
