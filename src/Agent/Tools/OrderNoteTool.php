<?php
/**
 * Adds a note to an order.
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
 * Writes an internal note onto an order.
 *
 * The first **write** tool, and therefore the first that defaults to requiring
 * approval (FR-AGENT-05). Notes are deliberately internal-only: a customer-
 * visible note is an outbound message, and an agent sending those unattended is
 * a different risk decision than an agent leaving a record for staff.
 *
 * FR-SUPPORT-06 needs this as one step of the exchange workflow.
 */
final class OrderNoteTool implements ToolInterface {

	public const ID = 'order.note';

	public function id(): string {
		return self::ID;
	}

	public function definition(): ToolDefinition {
		return new ToolDefinition(
			self::ID,
			'Leave an internal note on an order for the store team to see. '
			. 'Use this to record what a customer asked for or what you agreed, so staff picking '
			. 'the conversation up later have the context. The customer does not see these notes.',
			array(
				'type'       => 'object',
				'properties' => array(
					'order_id' => array(
						'type'        => 'integer',
						'description' => 'Order to annotate.',
					),
					'note'     => array(
						'type'        => 'string',
						'description' => 'What to record, in plain language.',
					),
				),
				'required'   => array( 'order_id', 'note' ),
			)
		);
	}

	public function intent(): string {
		return self::INTENT_WRITE;
	}

	public function required_capability(): string {
		return '';
	}

	public function requires_identity(): bool {
		return true;
	}

	public function execute( ToolContext $context, array $input ): ToolResult {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return ToolResult::error( 'Orders are unavailable.' );
		}

		$order_id = (int) ( $input['order_id'] ?? 0 );
		$note     = trim( (string) ( $input['note'] ?? '' ) );

		if ( $order_id < 1 || '' === $note ) {
			return ToolResult::error( 'I need both an order number and something to record.' );
		}

		if ( $context->verified_order_id !== $order_id && $context->customer_id < 1 ) {
			return ToolResult::denied( 'I can only annotate the order we confirmed together.' );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return ToolResult::error( 'I could not find that order.' );
		}

		// Attributed, so staff reading the order history can tell agent-written
		// notes from human ones at a glance.
		$order->add_order_note(
			sprintf( '[StoreCrew AI] %s', wp_strip_all_tags( $note ) ),
			0,
			false
		);

		return ToolResult::ok( 'Note added to the order for the store team.', array( 'orderId' => $order_id ) );
	}
}
