<?php
/**
 * WooCommerce product extractor.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Knowledge\Extractor;

use StoreCrew\Knowledge\ExtractedDocument;
use StoreCrew\Knowledge\ExtractorInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Indexes WooCommerce products.
 *
 * **This class is where FR-KB-08 is enforced.** Price, sale price, stock
 * status, and stock quantity are deliberately excluded from the emitted text.
 * Two things follow from that, and both matter:
 *
 * 1. The agent can never quote a stale price, because a stale price is not in
 *    the corpus to quote. Prices and availability are read live at request time
 *    and injected into the prompt separately.
 * 2. A stock change produces byte-identical text, an identical content hash,
 *    and therefore **no re-embedding**. A merchant running a bulk stock update
 *    on 5,000 products would otherwise re-embed the whole catalogue and be
 *    billed for content that did not change.
 *
 * Everything reads through `wc_get_product()` rather than post meta, which is
 * what keeps HPOS compatibility real rather than merely declared (FR-CORE-02).
 *
 * @see docs/01-prd.md FR-KB-08, FR-SALES-09
 */
final class ProductExtractor implements ExtractorInterface {

	use PagesPostTypeIds;

	public const SOURCE_TYPE = 'product';

	public function source_type(): string {
		return self::SOURCE_TYPE;
	}

	public function label(): string {
		return __( 'Products', 'storecrew' );
	}

	public function is_available(): bool {
		return function_exists( 'wc_get_product' ) && function_exists( 'wc_get_products' );
	}

	public function count(): int {
		if ( ! $this->is_available() ) {
			return 0;
		}

		return $this->count_post_type( array( 'product' ) );
	}

	public function ids( int $after_id = 0, int $limit = 50 ): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		return $this->paged_post_ids( array( 'product' ), $after_id, $limit );
	}

	public function extract( int $object_id ): ?ExtractedDocument {
		if ( ! $this->is_available() ) {
			return null;
		}

		$product = wc_get_product( $object_id );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return null;
		}

		// A product hidden from the catalogue should not be recommended, so it
		// should not be retrievable either.
		if ( 'visible' !== $product->get_catalog_visibility() && 'search' !== $product->get_catalog_visibility() ) {
			return null;
		}

		$lines = array();

		$lines[] = $product->get_name();

		$sku = $product->get_sku();

		if ( '' !== $sku ) {
			// The SKU is stable and useful for exact-match lookups. It is not a
			// volatile field.
			$lines[] = sprintf( 'SKU: %s', $sku );
		}

		$short = trim( wp_strip_all_tags( (string) $product->get_short_description() ) );

		if ( '' !== $short ) {
			$lines[] = $short;
		}

		$long = trim( wp_strip_all_tags( (string) $product->get_description() ) );

		if ( '' !== $long ) {
			$lines[] = $long;
		}

		$categories = $this->term_names( $object_id, 'product_cat' );

		if ( array() !== $categories ) {
			$lines[] = sprintf( 'Categories: %s', implode( ', ', $categories ) );
		}

		$tags = $this->term_names( $object_id, 'product_tag' );

		if ( array() !== $tags ) {
			$lines[] = sprintf( 'Tags: %s', implode( ', ', $tags ) );
		}

		foreach ( $this->attribute_lines( $product ) as $line ) {
			$lines[] = $line;
		}

		/**
		 * Filter the lines extracted from a product before hashing.
		 *
		 * Anything added here becomes part of the content hash and therefore
		 * part of the re-embedding decision. **Do not add price or stock** — see
		 * this class's docblock for why.
		 *
		 * @param list<string> $lines   Extracted lines.
		 * @param \WC_Product  $product The product.
		 */
		$lines = apply_filters( 'storecrew_product_extract_lines', $lines, $product );

		$content = trim( implode( "\n\n", array_filter( array_map( 'strval', $lines ) ) ) );

		if ( '' === $content ) {
			return null;
		}

		return new ExtractedDocument(
			self::SOURCE_TYPE,
			$object_id,
			$product->get_name(),
			$content,
			(string) $product->get_permalink()
		);
	}

	/**
	 * Attribute names and values, excluding anything volatile.
	 *
	 * Variation attributes are emitted as the set of available options — "Size:
	 * S, M, L" — never as which of them happen to be in stock right now.
	 *
	 * @return list<string>
	 */
	private function attribute_lines( \WC_Product $product ): array {
		$lines = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			$name = wc_attribute_label( $attribute->get_name() );

			$values = $attribute->is_taxonomy()
				? $this->taxonomy_attribute_values( $product->get_id(), $attribute->get_name() )
				: array_map( 'trim', $attribute->get_options() );

			$values = array_values( array_filter( array_map( 'strval', $values ) ) );

			if ( array() === $values ) {
				continue;
			}

			$lines[] = sprintf( '%s: %s', $name, implode( ', ', $values ) );
		}

		return $lines;
	}

	/**
	 * @return list<string>
	 */
	private function taxonomy_attribute_values( int $product_id, string $taxonomy ): array {
		return $this->term_names( $product_id, $taxonomy );
	}

	/**
	 * @return list<string>
	 */
	private function term_names( int $object_id, string $taxonomy ): array {
		$terms = get_the_terms( $object_id, $taxonomy );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_map(
				static fn ( \WP_Term $term ): string => $term->name,
				array_filter( $terms, static fn ( $t ): bool => $t instanceof \WP_Term )
			)
		);
	}
}
