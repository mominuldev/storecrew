<?php
/**
 * A conversation turn as sent to a provider.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-neutral message.
 *
 * Deliberately does NOT carry a `system` role. Anthropic takes the system
 * prompt as a separate top-level field rather than a message, and OpenAI and
 * Gemini each disagree again about where it goes. Modelling it as a role would
 * force every provider to fish it back out of the array; ChatRequest carries it
 * separately instead.
 *
 * The `tool` role and the `tool_calls` list exist because a tool-using turn is
 * three messages, not one: the assistant asks, the harness answers, the
 * assistant continues. Every provider models that differently — Anthropic uses
 * `tool_use` / `tool_result` content blocks, OpenAI a `tool_calls` array and a
 * `tool`-role reply, Gemini `functionCall` / `functionResponse` parts — so it is
 * normalised here once rather than at three call sites.
 */
final readonly class Message {

	public const ROLE_USER      = 'user';
	public const ROLE_ASSISTANT = 'assistant';
	public const ROLE_TOOL      = 'tool';

	/**
	 * @param list<ToolCall> $tool_calls   Assistant turns only: what the model asked to run.
	 * @param string         $tool_call_id Tool turns only: which call this answers.
	 */
	public function __construct(
		public string $role,
		public string $content,
		public array $tool_calls = array(),
		public string $tool_call_id = '',
		public string $tool_name = '',
		public bool $is_error = false,
	) {
		if ( ! in_array( $role, array( self::ROLE_USER, self::ROLE_ASSISTANT, self::ROLE_TOOL ), true ) ) {
			throw new \InvalidArgumentException(
				esc_html( sprintf( 'Unsupported message role "%s".', $role ) )
			);
		}

		if ( self::ROLE_TOOL === $role && '' === $tool_call_id ) {
			// A tool result with no id cannot be matched to its call. Providers
			// reject the request outright, so failing here gives a far better
			// error than a 400 from three layers away.
			throw new \InvalidArgumentException( 'A tool result must reference the call it answers.' );
		}
	}

	public static function user( string $content ): self {
		return new self( self::ROLE_USER, $content );
	}

	public static function assistant( string $content ): self {
		return new self( self::ROLE_ASSISTANT, $content );
	}

	/**
	 * An assistant turn that asked to run one or more tools.
	 *
	 * @param list<ToolCall> $calls Requested calls.
	 */
	public static function tool_request( array $calls, string $content = '' ): self {
		return new self( self::ROLE_ASSISTANT, $content, $calls );
	}

	/**
	 * The harness's answer to one tool call.
	 */
	public static function tool_result( string $call_id, string $tool_name, string $content, bool $is_error = false ): self {
		return new self( self::ROLE_TOOL, $content, array(), $call_id, $tool_name, $is_error );
	}

	public function has_tool_calls(): bool {
		return array() !== $this->tool_calls;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$out = array(
			'role'    => $this->role,
			'content' => $this->content,
		);

		if ( array() !== $this->tool_calls ) {
			$out['tool_calls'] = array_map(
				static fn ( ToolCall $c ): array => $c->to_array(),
				$this->tool_calls
			);
		}

		if ( '' !== $this->tool_call_id ) {
			$out['tool_call_id'] = $this->tool_call_id;
			$out['tool_name']    = $this->tool_name;
			$out['is_error']     = $this->is_error;
		}

		return $out;
	}
}
