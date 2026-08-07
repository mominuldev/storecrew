<?php
/**
 * Order status lookup.
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
 * Reads one order, and only after identity has been proven.
 *
 * `requires_identity()` returns true, so the executor refuses this before it
 * runs unless the conversation has passed verification. That check is not
 * duplicated here — but a **second, narrower** one is: the order returned must
 * be the specific order that was verified, or belong to the logged-in customer.
 *
 * The reason for both is that identity proves *an* identity, not *every*
 * identity. A customer who verified order 1042 must not be able to then ask
 * about order 1043. Without the ownership check, verification would become a
 * skeleton key to the entire order table.
 *
 * @see docs/01-prd.md FR-SUPPORT-01, FR-SUPPORT-02, FR-SUPPORT-03, R-SEC-02
 */
final class OrderLookupTool implements ToolInterface {

	public const ID = 'order.lookup';

	public function id(): string {
		return self::ID;
	}

	public function definition(): ToolDefinition {
		return new ToolDefinition(
			self::ID,
			'Look up the status of an order the customer has already been verified for. '
			. 'Use this when they ask where their order is, when it will arrive, or what it contained. '
			. 'Only works after their identity has been confirmed.',
			array(
				'type'       => 'object',
				'properties' => array(
					'order_id' => array(
						'type'        => 'integer',
						'description' => 'The order number the customer asked about.',
					),
				),
				'required'   => array( 'order_id' ),
			)
		);
	}

	public function intent(): string {
		return self::INTENT_READ;
	}

	public function required_capability(): string {
		// Customers are not logged-in administrators. Access is governed by
		// proven identity below, not by a WordPress capability.
		return '';
	}

	public function requires_identity(): bool {
		return true;
	}

	public function execute( ToolContext $context, array $input ): ToolResult {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return ToolResult::error( 'Order lookup is unavailable.' );
		}

		$order_id = (int) ( $input['order_id'] ?? 0 );

		if ( $order_id < 1 ) {
			return ToolResult::error( 'I need an order number to look that up.' );
		}

		// Verification proves one identity, not every identity. Asking about a
		// different order than the one proven is refused even though the
		// conversation is "verified".
		if ( ! $this->owns_order( $context, $order_id ) ) {
			return ToolResult::denied(
				'I can only discuss the order we confirmed together. '
				. 'To ask about a different order, we will need to verify that one too.'
			);
		}

		// wc_get_order() goes through the CRUD layer, so this reads correctly
		// whether or not the store is on HPOS.
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return ToolResult::error( 'I could not find that order.' );
		}

		$items = array();

		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
			);
		}

		return ToolResult::ok(
			'Here is the current state of that order.',
			array(
				'orderId'   => $order_id,
				'status'    => wc_get_order_status_name( $order->get_status() ),
				'placedAt'  => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : null,
				'total'     => $order->get_formatted_order_total(),
				'items'     => $items,
				'tracking'  => $this->tracking( $order ),
			)
		);
	}

	/**
	 * Whether this conversation may read this order.
	 */
	private function owns_order( ToolContext $context, int $order_id ): bool {
		if ( $context->verified_order_id === $order_id ) {
			return true;
		}

		// A logged-in customer may read their own orders without verifying each
		// one individually — the session itself is the proof.
		if ( $context->customer_id > 0 ) {
			$order = wc_get_order( $order_id );

			return $order && (int) $order->get_customer_id() === $context->customer_id;
		}

		return false;
	}

	/**
	 * Tracking details, where a shipment plugin has recorded them.
	 *
	 * Read through a filter rather than by reaching into a specific plugin's
	 * meta keys, so the several competing tracking plugins can each supply
	 * their own without this file knowing about any of them (FR-SUPPORT-03).
	 *
	 * @return array<int, array<string, string>>
	 */
	private function tracking( \WC_Order $order ): array {
		/**
		 * Filter shipment tracking details for an order.
		 *
		 * @param array<int, array<string, string>> $tracking Tracking entries.
		 * @param \WC_Order                         $order    The order.
		 */
		$tracking = apply_filters( 'storecrew_order_tracking', array(), $order );

		return is_array( $tracking ) ? $tracking : array();
	}
}
