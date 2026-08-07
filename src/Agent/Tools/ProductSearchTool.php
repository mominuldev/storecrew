<?php
/**
 * Product discovery.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent\Tools;

use StoreCrew\Agent\Tool\ToolContext;
use StoreCrew\Agent\Tool\ToolInterface;
use StoreCrew\Agent\Tool\ToolResult;
use StoreCrew\Ai\ToolDefinition;
use StoreCrew\Knowledge\Retriever;

defined( 'ABSPATH' ) || exit;

/**
 * Finds products, then reads their price and stock **live**.
 *
 * This is the other half of FR-KB-08. Retrieval finds candidates from indexed
 * descriptive text, which deliberately contains no price and no stock — and
 * then this tool reads those values from WooCommerce at request time, so the
 * number the agent quotes is the number on the product page right now.
 *
 * FR-SALES-02 is enforced here too: anything unpurchasable is dropped before
 * the model ever sees it. An agent cannot recommend an out-of-stock product it
 * was never shown.
 *
 * @see docs/01-prd.md FR-SALES-01, FR-SALES-02, FR-SALES-09, FR-KB-08
 */
final class ProductSearchTool implements ToolInterface {

	public const ID = 'product.search';

	public function __construct(
		private readonly Retriever $retriever,
	) {}

	public function id(): string {
		return self::ID;
	}

	public function definition(): ToolDefinition {
		return new ToolDefinition(
			self::ID,
			'Search the store catalogue for products matching what the shopper described. '
			. 'Use this whenever the shopper asks what you sell, describes something they want, '
			. 'or asks about availability or price. Returns live prices and stock, so never '
			. 'quote a price from memory — call this instead.',
			array(
				'type'       => 'object',
				'properties' => array(
					'query'     => array(
						'type'        => 'string',
						'description' => 'What the shopper is looking for, in their own words.',
					),
					'max_price' => array(
						'type'        => 'number',
						'description' => 'Optional upper price limit, in store currency.',
					),
					'limit'     => array(
						'type'        => 'integer',
						'description' => 'How many products to return. Defaults to 5.',
					),
				),
				'required'   => array( 'query' ),
			)
		);
	}

	public function intent(): string {
		return self::INTENT_READ;
	}

	public function required_capability(): string {
		// Catalogue search is what a storefront visitor is here to do.
		return '';
	}

	public function requires_identity(): bool {
		return false;
	}

	public function execute( ToolContext $context, array $input ): ToolResult {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return ToolResult::error( 'The catalogue is unavailable.' );
		}

		$query = trim( (string) ( $input['query'] ?? '' ) );

		if ( '' === $query ) {
			return ToolResult::error( 'I need to know what to search for.' );
		}

		$limit     = max( 1, min( 10, (int) ( $input['limit'] ?? 5 ) ) );
		$max_price = isset( $input['max_price'] ) ? (float) $input['max_price'] : null;

		// Over-fetch: some candidates will be dropped as unpurchasable or over
		// budget, and returning three when five were asked for reads as "we
		// only have three" rather than "three survived filtering".
		$found = $this->retriever->retrieve( $query, $limit * 3 );

		$seen     = array();
		$products = array();

		foreach ( $found['results'] as $chunk ) {
			$object_id = (int) ( $chunk['objectId'] ?? 0 );

			if ( $object_id < 1 || isset( $seen[ $object_id ] ) ) {
				continue;
			}

			if ( 'product' !== ( $chunk['sourceType'] ?? '' ) ) {
				continue;
			}

			$seen[ $object_id ] = true;

			$product = wc_get_product( $object_id );

			if ( ! $product || 'publish' !== $product->get_status() ) {
				continue;
			}

			// FR-SALES-02: never surface something that cannot be bought.
			if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}

			$price = (float) $product->get_price();

			if ( null !== $max_price && $price > $max_price ) {
				continue;
			}

			$products[] = array(
				'id'        => $object_id,
				'name'      => $product->get_name(),
				// Live, every time. Never from the index.
				'price'     => wc_format_decimal( $price, wc_get_price_decimals() ),
				'currency'  => get_woocommerce_currency(),
				'inStock'   => true,
				'stockQty'  => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
				'url'       => $product->get_permalink(),
				'sku'       => $product->get_sku(),
			);

			if ( count( $products ) >= $limit ) {
				break;
			}
		}

		if ( array() === $products ) {
			return ToolResult::ok(
				'No purchasable products matched that. Suggest the shopper try different words, '
				. 'or offer to look for something related.',
				array( 'products' => array(), 'strategy' => $found['strategy'] )
			);
		}

		return ToolResult::ok(
			sprintf( 'Found %d matching products. Prices and stock are current.', count( $products ) ),
			array( 'products' => $products )
		);
	}
}
