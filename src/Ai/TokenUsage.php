<?php
/**
 * Token accounting for one provider call.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Normalised token counts.
 *
 * Every provider names these differently — Anthropic returns `input_tokens` /
 * `output_tokens` plus `cache_creation_input_tokens` and
 * `cache_read_input_tokens`; OpenAI returns `prompt_tokens` /
 * `completion_tokens`; Gemini returns `usageMetadata.promptTokenCount` /
 * `candidatesTokenCount`. Each provider maps into this shape so metering never
 * has to know which vendor produced a number.
 *
 * FR-AI-04.
 */
final readonly class TokenUsage {

	public function __construct(
		public int $input = 0,
		public int $output = 0,
		public int $cache_write = 0,
		public int $cache_read = 0,
	) {}

	public static function none(): self {
		return new self();
	}

	/**
	 * Billable input tokens.
	 *
	 * Cache reads are counted separately because they are priced at roughly a
	 * tenth of base input; folding them into `input` would overstate cost by an
	 * order of magnitude on a well-cached workload.
	 */
	public function total_input(): int {
		return $this->input + $this->cache_write + $this->cache_read;
	}

	public function total(): int {
		return $this->total_input() + $this->output;
	}

	public function add( self $other ): self {
		return new self(
			$this->input + $other->input,
			$this->output + $other->output,
			$this->cache_write + $other->cache_write,
			$this->cache_read + $other->cache_read,
		);
	}

	/**
	 * @return array{input: int, output: int, cacheWrite: int, cacheRead: int}
	 */
	public function to_array(): array {
		return array(
			'input'      => $this->input,
			'output'     => $this->output,
			'cacheWrite' => $this->cache_write,
			'cacheRead'  => $this->cache_read,
		);
	}
}
