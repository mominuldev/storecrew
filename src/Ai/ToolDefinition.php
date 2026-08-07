<?php
/**
 * A tool as described to a model.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * The wire-level description of a tool.
 *
 * Deliberately separate from the executable tool itself. This is the half that
 * crosses the network to a provider — a name, a description, and a JSON Schema
 * — and it carries no capability, no intent, and no callable. That separation
 * matters: what the model is *told* about a tool and what the harness will
 * *permit* it to do are different questions, and conflating them is how a model
 * ends up appearing to authorise its own calls.
 */
final readonly class ToolDefinition {

	/**
	 * @param array<string, mixed> $input_schema JSON Schema for the arguments.
	 */
	public function __construct(
		public string $name,
		public string $description,
		public array $input_schema,
	) {
		if ( '' === trim( $name ) ) {
			throw new \InvalidArgumentException( 'A tool needs a name.' );
		}

		if ( '' === trim( $description ) ) {
			// A tool the model cannot tell when to use is a tool it will use
			// wrongly. Descriptions are the single largest factor in tool
			// selection quality, so an empty one is a defect, not a shortcut.
			throw new \InvalidArgumentException(
				sprintf( 'Tool "%s" needs a description saying when to use it.', $name )
			);
		}
	}

	/**
	 * @return array{name: string, description: string, input_schema: array<string, mixed>}
	 */
	public function to_array(): array {
		return array(
			'name'         => $this->name,
			'description'  => $this->description,
			'input_schema' => $this->input_schema,
		);
	}
}
