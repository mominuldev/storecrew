<?php
/**
 * Anthropic Messages API provider.
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
 * Anthropic, via the Messages API.
 *
 * Three things about this provider are unlike the others and are the reason the
 * abstraction has the shape it does:
 *
 * 1. **It rejects sampling parameters.** `temperature`, `top_p`, and `top_k`
 *    return a 400 on current models. This class never sends them, and declares
 *    `sampling: false` so the admin UI hides the control rather than offering a
 *    setting that guarantees failure.
 * 2. **The system prompt is a top-level field**, not a message with a system
 *    role — which is why Message has no such role.
 * 3. **There is no embeddings endpoint.** This class implements chat only; a
 *    merchant on Anthropic must configure a second provider to index anything.
 *
 * @see docs/01-prd.md FR-AI-01, FR-AI-03, FR-AI-07
 */
final class AnthropicProvider implements ChatProviderInterface {

	public const ID = 'anthropic';

	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * Required on every request; not a beta flag.
	 */
	private const API_VERSION = '2023-06-01';

	public function __construct(
		private readonly SecretStore $secrets,
		private readonly HttpClientInterface $http,
	) {}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'Anthropic (Claude)';
	}

	public function capabilities(): Capabilities {
		return new Capabilities(
			chat: true,
			// No embeddings endpoint exists. This is a fact about the provider,
			// not a gap in this implementation.
			embeddings: false,
			streaming: true,
			tools: true,
			// temperature / top_p / top_k return a 400 on current models.
			sampling: false,
			prompt_caching: true,
			embedding_task_types: false,
			effort: true,
		);
	}

	public function is_configured(): bool {
		return $this->secrets->has( 'provider.anthropic.key' );
	}

	public function verify(): string {
		if ( ! $this->is_configured() ) {
			return __( 'No API key configured.', 'storecrew' );
		}

		try {
			// Cheapest possible real call: one token out. There is no dedicated
			// credential endpoint, and a malformed key must fail here rather
			// than on a customer's first question.
			$this->http->post_json(
				self::ENDPOINT,
				$this->headers(),
				array(
					'model'      => $this->default_models()[0],
					'max_tokens' => 1,
					'messages'   => array( array( 'role' => 'user', 'content' => 'ping' ) ),
				),
				self::ID,
				20
			);
		} catch ( ProviderException $e ) {
			if ( $e->is_auth_failure() ) {
				return __( 'The API key was rejected.', 'storecrew' );
			}

			return $e->getMessage();
		}

		return '';
	}

	public function default_models(): array {
		return array(
			'claude-opus-5',
			'claude-sonnet-5',
			'claude-haiku-4-5',
		);
	}

	public function chat( ChatRequest $request ): ChatResponse {
		$payload = array(
			'model'      => $request->model,
			'max_tokens' => $request->max_tokens,
			'messages'   => $this->to_messages( $request->messages ),
		);

		if ( $request->has_tools() ) {
			$payload['tools'] = array_map(
				static fn ( ToolDefinition $t ): array => array(
					'name'         => $t->name,
					'description'  => $t->description,
					'input_schema' => $t->input_schema,
				),
				$request->tools
			);
		}

		if ( '' !== $request->system ) {
			// A system prompt is an array of blocks so a cache breakpoint can be
			// attached to it. Caching the system prompt is the single highest-
			// value breakpoint here: it is the largest stable prefix, and cache
			// reads bill at roughly a tenth of base input.
			$block = array(
				'type' => 'text',
				'text' => $request->system,
			);

			if ( $request->cache_system ) {
				$block['cache_control'] = array( 'type' => 'ephemeral' );
			}

			$payload['system'] = array( $block );
		}

		if ( '' !== $request->effort ) {
			$payload['output_config'] = array( 'effort' => $request->effort );
		}

		// Deliberately absent: temperature, top_p, top_k. Sending any of them
		// returns a 400 on current models, so ChatRequest::$temperature is
		// ignored here by design rather than silently mistranslated.

		$result = $this->http->post_json(
			self::ENDPOINT,
			$this->headers(),
			$payload,
			self::ID,
			$request->timeout
		);

		return $this->to_response( $result['body'], $request->model, $result['latency_ms'] );
	}

	/**
	 * Map neutral messages onto Anthropic content blocks.
	 *
	 * Tool calls are `tool_use` blocks on an assistant turn; results are
	 * `tool_result` blocks on a *user* turn, not a dedicated role — the one
	 * shape here that differs most from the other two families.
	 *
	 * @param list<Message> $messages Turns.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function to_messages( array $messages ): array {
		$out = array();

		foreach ( $messages as $message ) {
			if ( Message::ROLE_TOOL === $message->role ) {
				$block = array(
					'type'        => 'tool_result',
					'tool_use_id' => $message->tool_call_id,
					'content'     => $message->content,
				);

				if ( $message->is_error ) {
					$block['is_error'] = true;
				}

				$out[] = array( 'role' => 'user', 'content' => array( $block ) );

				continue;
			}

			if ( $message->has_tool_calls() ) {
				$blocks = array();

				if ( '' !== $message->content ) {
					$blocks[] = array( 'type' => 'text', 'text' => $message->content );
				}

				foreach ( $message->tool_calls as $call ) {
					$blocks[] = array(
						'type'  => 'tool_use',
						'id'    => $call->id,
						'name'  => $call->name,
						'input' => (object) $call->arguments,
					);
				}

				$out[] = array( 'role' => 'assistant', 'content' => $blocks );

				continue;
			}

			$out[] = array( 'role' => $message->role, 'content' => $message->content );
		}

		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'x-api-key'         => (string) $this->secrets->get( 'provider.anthropic.key' ),
			'anthropic-version' => self::API_VERSION,
		);
	}

	/**
	 * Map a Messages API response into the normalised shape.
	 *
	 * @param array<string, mixed> $body Decoded response.
	 */
	private function to_response( array $body, string $model, int $latency ): ChatResponse {
		$text       = '';
		$tool_calls = array();

		foreach ( (array) ( $body['content'] ?? array() ) as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) ( $block['text'] ?? '' );

				continue;
			}

			if ( 'tool_use' === ( $block['type'] ?? '' ) ) {
				$tool_calls[] = new ToolCall(
					(string) ( $block['id'] ?? '' ),
					(string) ( $block['name'] ?? '' ),
					(array) ( $block['input'] ?? array() )
				);
			}
		}

		$usage_raw = (array) ( $body['usage'] ?? array() );

		$usage = new TokenUsage(
			(int) ( $usage_raw['input_tokens'] ?? 0 ),
			(int) ( $usage_raw['output_tokens'] ?? 0 ),
			(int) ( $usage_raw['cache_creation_input_tokens'] ?? 0 ),
			(int) ( $usage_raw['cache_read_input_tokens'] ?? 0 ),
		);

		return new ChatResponse(
			$text,
			(string) ( $body['model'] ?? $model ),
			self::ID,
			$usage,
			$this->map_stop_reason( (string) ( $body['stop_reason'] ?? '' ) ),
			$latency,
			array(
				// Populated only on a refusal; null for every other stop reason,
				// so it is read defensively rather than assumed present.
				'stop_details' => $body['stop_details'] ?? null,
			),
			$tool_calls
		);
	}

	/**
	 * Normalise Anthropic's stop reasons.
	 *
	 * `refusal` is the one that matters: it arrives with HTTP 200 and a possibly
	 * empty content array, so treating it as a normal completion yields a
	 * confident-looking empty answer.
	 */
	private function map_stop_reason( string $reason ): string {
		return match ( $reason ) {
			'end_turn'                      => ChatResponse::STOP_END,
			'max_tokens'                    => ChatResponse::STOP_MAX,
			'tool_use', 'pause_turn'        => ChatResponse::STOP_TOOL,
			'refusal'                       => ChatResponse::STOP_REFUSAL,
			default                         => ChatResponse::STOP_UNKNOWN,
		};
	}
}
