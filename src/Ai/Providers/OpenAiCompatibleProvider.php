<?php
/**
 * Shared implementation for OpenAI-shaped APIs.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai\Providers;

use StoreCrew\Ai\Capabilities;
use StoreCrew\Ai\ChatProviderInterface;
use StoreCrew\Ai\ChatRequest;
use StoreCrew\Ai\ChatResponse;
use StoreCrew\Ai\Exception\ProviderException;
use StoreCrew\Ai\Http\HttpClientInterface;
use StoreCrew\Ai\Message;
use StoreCrew\Ai\ToolCall;
use StoreCrew\Ai\ToolDefinition;
use StoreCrew\Ai\TokenUsage;
use StoreCrew\Security\SecretStore;

defined( 'ABSPATH' ) || exit;

/**
 * Base for providers speaking the OpenAI chat-completions shape.
 *
 * OpenAI, DeepSeek, and OpenRouter share a wire format: a `messages` array
 * carrying the system prompt as a `system`-role first entry, `temperature` as a
 * top-level field, and usage reported as `prompt_tokens` / `completion_tokens`.
 * The differences between them are a base URL, a model list, and a couple of
 * headers — so they differ by configuration here rather than by copied code.
 *
 * This is the family where `temperature` genuinely works, which is why
 * ChatRequest carries it at all.
 */
abstract class OpenAiCompatibleProvider implements ChatProviderInterface {

	public function __construct(
		protected readonly SecretStore $secrets,
		protected readonly HttpClientInterface $http,
	) {}

	/**
	 * API root, without a trailing slash.
	 */
	abstract protected function base_url(): string;

	/**
	 * Secret name holding this provider's API key.
	 */
	protected function secret_name(): string {
		return 'provider.' . $this->id() . '.key';
	}

	public function capabilities(): Capabilities {
		return new Capabilities(
			chat: true,
			embeddings: false,
			// Not yet implemented — flips true when this provider implements
			// StreamingChatProviderInterface (the Gemini pattern). Declaring it
			// before then was a capability the code could not honour.
			streaming: false,
			tools: true,
			sampling: true,
			prompt_caching: false,
			embedding_task_types: false,
			effort: false,
		);
	}

	public function is_configured(): bool {
		return $this->secrets->has( $this->secret_name() );
	}

	public function verify(): string {
		if ( ! $this->is_configured() ) {
			return __( 'No API key configured.', 'storecrew' );
		}

		try {
			// The models endpoint is a real credential check and costs nothing.
			$this->http->get_json( $this->base_url() . '/models', $this->headers(), $this->id(), 20 );
		} catch ( ProviderException $e ) {
			if ( $e->is_auth_failure() ) {
				return __( 'The API key was rejected.', 'storecrew' );
			}

			return $e->getMessage();
		}

		return '';
	}

	public function chat( ChatRequest $request ): ChatResponse {
		$messages = $this->to_messages( $request->messages );

		if ( '' !== $request->system ) {
			// Unlike Anthropic, the system prompt rides in the messages array.
			array_unshift(
				$messages,
				array(
					'role'    => 'system',
					'content' => $request->system,
				)
			);
		}

		$payload = array(
			'model'      => $request->model,
			'messages'   => $messages,
			'max_tokens' => $request->max_tokens,
		);

		if ( null !== $request->temperature ) {
			$payload['temperature'] = $request->temperature;
		}

		if ( $request->has_tools() ) {
			$payload['tools'] = array_map(
				static fn ( ToolDefinition $t ): array => array(
					'type'     => 'function',
					'function' => array(
						'name'        => $t->name,
						'description' => $t->description,
						'parameters'  => $t->input_schema,
					),
				),
				$request->tools
			);
		}

		$result = $this->http->post_json(
			$this->base_url() . '/chat/completions',
			$this->headers(),
			$payload,
			$this->id(),
			$request->timeout
		);

		return $this->to_response( $result['body'], $request->model, $result['latency_ms'] );
	}

	/**
	 * Map neutral messages onto the OpenAI shape.
	 *
	 * Tool results are their own `tool` role here, and arguments travel as a
	 * JSON *string* rather than an object — a difference that silently produces
	 * an unusable request if missed.
	 *
	 * @param list<Message> $messages Turns.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function to_messages( array $messages ): array {
		$out = array();

		foreach ( $messages as $message ) {
			if ( Message::ROLE_TOOL === $message->role ) {
				$out[] = array(
					'role'         => 'tool',
					'tool_call_id' => $message->tool_call_id,
					'content'      => $message->content,
				);

				continue;
			}

			if ( $message->has_tool_calls() ) {
				$out[] = array(
					'role'       => 'assistant',
					'content'    => '' === $message->content ? null : $message->content,
					'tool_calls' => array_map(
						static fn ( ToolCall $c ): array => array(
							'id'       => $c->id,
							'type'     => 'function',
							'function' => array(
								'name'      => $c->name,
								'arguments' => (string) wp_json_encode( $c->arguments ),
							),
						),
						$message->tool_calls
					),
				);

				continue;
			}

			$out[] = array(
				'role'    => $message->role,
				'content' => $message->content,
			);
		}

		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	protected function headers(): array {
		return array(
			'Authorization' => 'Bearer ' . (string) $this->secrets->get( $this->secret_name() ),
		);
	}

	/**
	 * @param array<string, mixed> $body Decoded response.
	 */
	protected function to_response( array $body, string $model, int $latency ): ChatResponse {
		$choice = (array) ( ( (array) ( $body['choices'] ?? array() ) )[0] ?? array() );

		$assistant = (array) ( $choice['message'] ?? array() );

		$text = (string) ( $assistant['content'] ?? '' );

		$tool_calls = array();

		foreach ( (array) ( $assistant['tool_calls'] ?? array() ) as $raw ) {
			$fn = (array) ( $raw['function'] ?? array() );

			// Arguments arrive as a JSON string. A model can emit malformed
			// JSON here, so decode defensively — an unparseable argument set
			// becomes an empty one and the executor rejects it against the
			// schema, rather than a fatal on array access.
			$decoded = json_decode( (string) ( $fn['arguments'] ?? '' ), true );

			$tool_calls[] = new ToolCall(
				(string) ( $raw['id'] ?? '' ),
				(string) ( $fn['name'] ?? '' ),
				is_array( $decoded ) ? $decoded : array()
			);
		}

		$usage_raw = (array) ( $body['usage'] ?? array() );

		// Cached prompt tokens, where the provider reports them, are a subset of
		// prompt_tokens — not an addition. Subtracting keeps total_input() from
		// double-counting them.
		$cache_read = (int) ( ( (array) ( $usage_raw['prompt_tokens_details'] ?? array() ) )['cached_tokens'] ?? 0 );
		$prompt     = (int) ( $usage_raw['prompt_tokens'] ?? 0 );

		$usage = new TokenUsage(
			max( 0, $prompt - $cache_read ),
			(int) ( $usage_raw['completion_tokens'] ?? 0 ),
			0,
			$cache_read,
		);

		return new ChatResponse(
			$text,
			(string) ( $body['model'] ?? $model ),
			$this->id(),
			$usage,
			$this->map_finish_reason( (string) ( $choice['finish_reason'] ?? '' ) ),
			$latency,
			array(),
			$tool_calls
		);
	}

	protected function map_finish_reason( string $reason ): string {
		return match ( $reason ) {
			'stop'                      => ChatResponse::STOP_END,
			'length'                    => ChatResponse::STOP_MAX,
			'tool_calls', 'function_call' => ChatResponse::STOP_TOOL,
			'content_filter'            => ChatResponse::STOP_REFUSAL,
			default                     => ChatResponse::STOP_UNKNOWN,
		};
	}
}
