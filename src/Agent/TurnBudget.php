<?php
/**
 * Hard ceiling on one agent turn.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Stops a turn that will not stop itself.
 *
 * FR-AGENT-06. A model that misreads a tool result can call the same tool
 * forever, and each iteration costs the merchant real money against their own
 * API key. Three independent ceilings, because the failure modes differ: tool
 * calls catch a loop, tokens catch a verbose one, and wall-clock catches a slow
 * provider holding a storefront request open.
 *
 * Exhaustion is a **recorded outcome**, not an error — `budget_exceeded` is a
 * first-class agent-run status, so a turn that hit the ceiling is visible in
 * the inspector rather than looking like a normal short answer.
 */
final class TurnBudget {

	private int $tool_calls = 0;

	private int $tokens = 0;

	private float $started;

	private string $reason = '';

	public function __construct(
		private readonly int $max_tool_calls = 8,
		private readonly int $max_tokens = 32000,
		private readonly int $max_seconds = 45,
	) {
		$this->started = microtime( true );
	}

	public function record_tool_call(): void {
		++$this->tool_calls;
	}

	public function record_tokens( int $tokens ): void {
		$this->tokens += max( 0, $tokens );
	}

	/**
	 * Whether the turn must stop now.
	 */
	public function exhausted(): bool {
		if ( $this->tool_calls >= $this->max_tool_calls ) {
			$this->reason = 'tool_calls';

			return true;
		}

		if ( $this->tokens >= $this->max_tokens ) {
			$this->reason = 'tokens';

			return true;
		}

		if ( ( microtime( true ) - $this->started ) >= $this->max_seconds ) {
			$this->reason = 'time';

			return true;
		}

		return false;
	}

	/**
	 * Which ceiling was hit. Empty until one is.
	 */
	public function reason(): string {
		return $this->reason;
	}

	public function tool_calls(): int {
		return $this->tool_calls;
	}

	public function tokens(): int {
		return $this->tokens;
	}

	public function elapsed_ms(): int {
		return (int) round( ( microtime( true ) - $this->started ) * 1000 );
	}
}
