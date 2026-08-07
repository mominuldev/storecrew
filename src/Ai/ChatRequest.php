<?php
/**
 * A chat completion request.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-neutral chat request.
 *
 * `temperature` is nullable and defaults to null — meaning "don't send one".
 * That default is deliberate: Anthropic's current models reject the parameter
 * outright, so a non-null default would make the most capable provider the one
 * that never works. Providers that do accept sampling send it only when it is
 * set, and only when their Capabilities say so.
 */
final readonly class ChatRequest {

	public function __construct(
		public string $model,
		/** @var list<Message> */
		public array $messages,
		public string $system = '',
		public int $max_tokens = 4096,
		public ?float $temperature = null,
		public bool $stream = false,
		/** Reasoning effort hint, where supported: low|medium|high|xhigh|max. */
		public string $effort = '',
		/** Cache the system prompt where the provider supports breakpoints. */
		public bool $cache_system = false,
		public int $timeout = 60,
		/** @var list<ToolDefinition> */
		public array $tools = array(),
	) {
		if ( array() === $messages ) {
			throw new \InvalidArgumentException( 'A chat request needs at least one message.' );
		}

		if ( $max_tokens < 1 ) {
			throw new \InvalidArgumentException( 'max_tokens must be positive.' );
		}
	}

	public function has_tools(): bool {
		return array() !== $this->tools;
	}

	/**
	 * Copy with additional messages appended — used to continue a tool loop.
	 *
	 * @param list<Message> $messages Turns to append.
	 */
	public function with_messages( array $messages ): self {
		return new self(
			$this->model,
			array_merge( $this->messages, $messages ),
			$this->system,
			$this->max_tokens,
			$this->temperature,
			$this->stream,
			$this->effort,
			$this->cache_system,
			$this->timeout,
			$this->tools,
		);
	}

	/**
	 * Messages as provider-neutral arrays.
	 *
	 * @return list<array{role: string, content: string}>
	 */
	public function messages_array(): array {
		return array_map(
			static fn ( Message $m ): array => $m->to_array(),
			$this->messages
		);
	}

	/**
	 * Copy with a different model — used by failover (FR-AI-05).
	 */
	public function with_model( string $model ): self {
		return new self(
			$model,
			$this->messages,
			$this->system,
			$this->max_tokens,
			$this->temperature,
			$this->stream,
			$this->effort,
			$this->cache_system,
			$this->timeout,
			$this->tools,
		);
	}
}
