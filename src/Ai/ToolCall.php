<?php
/**
 * A tool invocation requested by a model.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * What the model asked to run, and with what.
 *
 * **This is a request, never a grant.** The provider id, the tool name, and the
 * arguments all originate in model output, which is untrusted — a prompt
 * injection in a product review can produce a perfectly well-formed ToolCall.
 * Nothing here is authorisation; that decision belongs to the executor and is
 * derived from the session's capabilities.
 */
final readonly class ToolCall {

	/**
	 * @param string               $id        Provider-assigned id, echoed back with the result.
	 * @param string               $name      Tool name the model chose.
	 * @param array<string, mixed> $arguments Decoded arguments.
	 */
	public function __construct(
		public string $id,
		public string $name,
		public array $arguments = array(),
	) {}

	/**
	 * @return array{id: string, name: string, arguments: array<string, mixed>}
	 */
	public function to_array(): array {
		return array(
			'id'        => $this->id,
			'name'      => $this->name,
			'arguments' => $this->arguments,
		);
	}
}
