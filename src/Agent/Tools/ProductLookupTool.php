<?php
/**
 * Exact product lookup by identifier.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent\Tools;

use StoreCrew\Agent\Tool\ToolContext;
use StoreCrew\Agent\Tool\ToolInterface;
use StoreCrew\Agent\Tool\ToolResult;
use StoreCrew\Ai\ToolDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * "SKU-1042" is an address, not a description — so it gets a lookup, not a
 * search.
 *
 * The FR-KB-09 measurement showed exact-identifier queries failing at every
 * fusion weight, and the failure is structural: an embedding of "SCR-1042"
 * carries no meaning for cosine similarity to find, and softening the
 * identifier into nearby tokens *worked against* precision. The measured
 * conclusion was that this needs its own tool rather than a retrieval tweak
 * (13 § 1), and this is that tool.
 *
 * Resolution order: exact SKU → exact-SKU-of-a-variation (resolved to a
 * purchasable line with its parent named) → nothing. No fuzzy matching. A
 * shopper who typed an identifier wants *that* product or an honest miss —
 * "did you mean" belongs to the semantic tool, which the agent falls back to
 * on the miss this tool reports.
 *
 * Prices and stock are read live at lookup time, same as every product
 * surface (FR-KB-08); unpurchasable products are named as such rather than
 * hidden, because someone asking for a specific SKU deserves "that one is out
 * of stock", not "no such product".
 */
final class ProductLookupTool implements ToolInterface {

	public const ID = 'product.lookup';

	public function id(): string {
		return self::ID;
	}

	public function definition(): ToolDefinition {
		return new ToolDefinition(
			self::ID,
			'Look up one exact product by its SKU or product code. Use this whenever the shopper '
			. 'gives a specific code, SKU, part number, or model number — anything that looks like '
			. 'an identifier rather than a description. If it finds nothing, fall back to the '
			. 'catalogue search with the shopper\'s own words.',
			array(
				'type'       => 'object',
				'properties' => array(
					'sku' => array(
						'type'        => 'string',
						'description' => 'The exact SKU or code the shopper gave.',
					),
				),
				'required'   => array( 'sku' ),
			)
		);
	}

	public function intent(): string {
		return self::INTENT_READ;
	}

	public function required_capability(): string {
		// A printed catalogue lists SKUs; looking one up is storefront-public.
		return '';
	}

	public function requires_identity(): bool {
		return false;
	}

	public function execute( ToolContext $context, array $input ): ToolResult {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return ToolResult::error( 'The catalogue is unavailable.' );
		}

		$sku = trim( (string) ( $input['sku'] ?? '' ) );

		if ( '' === $sku ) {
			return ToolResult::error( 'I need the product code to look it up.' );
		}

		$product_id = (int) wc_get_product_id_by_sku( $sku );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;

		if ( ! $product ) {
			// An honest miss the model can act on: fall back to search.
			return ToolResult::error(
				sprintf( 'No product carries the code "%s". Try the catalogue search with a description instead.', $sku )
			);
		}

		// A variation's SKU resolves to the variation; the shopper is told
		// which product it belongs to rather than being shown an orphan line.
		$parent = null;

		if ( $product instanceof \WC_Product_Variation ) {
			$parent = wc_get_product( $product->get_parent_id() );
		}

		$visible = ( $parent ?? $product );

		if ( 'publish' !== $visible->get_status() ) {
			// Draft and private products do not exist to the storefront —
			// the same sentence as a true miss, or the tool becomes an oracle
			// for unpublished catalogue.
			return ToolResult::error(
				sprintf( 'No product carries the code "%s". Try the catalogue search with a description instead.', $sku )
			);
		}

		$purchasable = $product->is_purchasable() && $product->is_in_stock();

		$data = array(
			'id'        => (int) $product->get_id(),
			'sku'       => $product->get_sku(),
			'name'      => $product->get_name(),
			'parent'    => $parent ? $parent->get_name() : null,
			// Live, every time. Never from the index (FR-KB-08).
			'price'     => wc_format_decimal( (float) $product->get_price(), wc_get_price_decimals() ),
			'currency'  => get_woocommerce_currency(),
			'inStock'   => $product->is_in_stock(),
			'stockQty'  => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
			'url'       => $visible->get_permalink(),
		);

		/** This filter is documented on ProductSearchTool; same contract. */
		do_action( 'storecrew_products_surfaced', array( (int) $visible->get_id() ) );

		return ToolResult::ok(
			$purchasable
				? sprintf( 'Found "%s" (%s).', $product->get_name(), $sku )
				: sprintf( 'Found "%s" (%s), but it is not available to buy right now.', $product->get_name(), $sku ),
			$data
		);
	}
}
