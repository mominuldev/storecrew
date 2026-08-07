<?php
/**
 * Executable tool contract.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent\Tool;

use StoreCrew\Ai\ToolDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Something an agent can actually do.
 *
 * Every tool declares three things the model is never asked about and can never
 * influence: whether it **reads or writes**, which **capability** the session
 * must hold, and whether it needs **proven identity**. Those three answers are
 * what the executor authorises against. The model's only input is which tool to
 * call and with what arguments — both untrusted.
 *
 * FR-AGENT-04.
 */
interface ToolInterface {

	public const INTENT_READ  = 'read';
	public const INTENT_WRITE = 'write';

	/**
	 * Stable identifier, e.g. "product.search". Also the name the model sees.
	 */
	public function id(): string;

	/**
	 * How the tool is described to the model.
	 *
	 * The description must say *when* to use it, not just what it does —
	 * trigger conditions are the largest factor in whether a model reaches for
	 * a tool at the right moment.
	 */
	public function definition(): ToolDefinition;

	/**
	 * Read or write.
	 *
	 * Writes are approval-gated by default (FR-AGENT-05). Getting this wrong
	 * lets a state-changing tool run unattended.
	 */
	public function intent(): string;

	/**
	 * WordPress capability the session must hold.
	 *
	 * An empty string means the tool is safe for an anonymous storefront
	 * visitor — which is a deliberate, reviewable statement, not a default.
	 */
	public function required_capability(): string;

	/**
	 * Whether the conversation must have proven identity first.
	 *
	 * True for anything touching order or customer data (FR-SUPPORT-02).
	 */
	public function requires_identity(): bool;

	/**
	 * Run the tool.
	 *
	 * Implementations must treat `$input` as untrusted — it originates in model
	 * output, and a prompt injection in an indexed product review can produce
	 * well-formed arguments.
	 *
	 * @param array<string, mixed> $input Arguments from the model.
	 */
	public function execute( ToolContext $context, array $input ): ToolResult;
}
