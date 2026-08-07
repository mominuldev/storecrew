<?php
/**
 * A source document ready for indexing.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Text pulled out of a WordPress or WooCommerce object.
 *
 * The content hash is computed from the text alone. That is the whole cost
 * control in the indexing pipeline: because extractors exclude volatile fields
 * (FR-KB-08), editing a product's stock level or price produces byte-identical
 * text, an identical hash, and therefore no re-embedding. Without it, a
 * merchant running a bulk stock update would re-embed their entire catalogue
 * and be billed for it.
 *
 * @see docs/04-database-schema.md § 5.1
 */
final readonly class ExtractedDocument {

	public string $content_hash;

	public function __construct(
		public string $source_type,
		public int $object_id,
		public string $title,
		public string $content,
		public string $url = '',
		public string $external_ref = '',
	) {
		$this->content_hash = hash( 'sha256', $content );
	}

	public function is_empty(): bool {
		return '' === trim( $this->content );
	}
}
