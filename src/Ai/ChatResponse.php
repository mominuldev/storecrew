<?php
/**
 * A chat completion response.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Normalised chat response.
 *
 * `STOP_REFUSAL` is a first-class outcome, not an error. Anthropic's safety
 * classifiers can decline a request and still return HTTP 200 with an empty or
 * partial content array — code that reads the text unconditionally breaks on
 * exactly the requests it most needs to handle gracefully. Callers check
 * `is_refusal()` before using `text`.
 */
final readonly class ChatResponse {

	public const STOP_END     = 'end_turn';
	public const STOP_MAX     = 'max_tokens';
	public const STOP_TOOL    = 'tool_use';
	public const STOP_REFUSAL = 'refusal';
	public const STOP_UNKNOWN = 'unknown';

	public function __construct(
		public string $text,
		public string $model,
		public string $provider,
		public TokenUsage $usage,
		public string $stop_reason = self::STOP_END,
		public int $latency_ms = 0,
		/** Provider-specific detail, for the conversation inspector. */
		public array $raw_meta = array(),
		/** @var list<ToolCall> What the model asked to run. Requests, not grants. */
		public array $tool_calls = array(),
	) {}

	public function has_tool_calls(): bool {
		return array() !== $this->tool_calls;
	}

	public function is_refusal(): bool {
		return self::STOP_REFUSAL === $this->stop_reason;
	}

	/**
	 * Whether the answer was cut off by the output ceiling.
	 *
	 * Worth surfacing separately: a truncated answer looks like a complete one
	 * to a user, and the fix (raise max_tokens) is different from every other
	 * failure mode.
	 */
	public function is_truncated(): bool {
		return self::STOP_MAX === $this->stop_reason;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'text'       => $this->text,
			'model'      => $this->model,
			'provider'   => $this->provider,
			'usage'      => $this->usage->to_array(),
			'stopReason' => $this->stop_reason,
			'latencyMs'  => $this->latency_ms,
		);
	}
}
