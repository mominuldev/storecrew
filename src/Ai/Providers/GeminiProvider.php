<?php
/**
 * Google Gemini provider.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai\Providers;

use StoreCrew\Ai\Capabilities;
use StoreCrew\Ai\ChatProviderInterface;
use StoreCrew\Ai\ChatRequest;
use StoreCrew\Ai\ChatResponse;
use StoreCrew\Ai\EmbeddingProviderInterface;
use StoreCrew\Ai\EmbeddingRequest;
use StoreCrew\Ai\EmbeddingResponse;
use StoreCrew\Ai\Exception\ProviderException;
use StoreCrew\Ai\Http\HttpClientInterface;
use StoreCrew\Ai\Message;
use StoreCrew\Ai\TokenUsage;
use StoreCrew\Security\SecretStore;

defined( 'ABSPATH' ) || exit;

/**
 * Google Gemini — chat and task-typed embeddings.
 *
 * The wire format diverges from both other families: turns are `contents` with
 * `parts`, the assistant role is spelled `model`, the system prompt is
 * `systemInstruction`, and usage is `usageMetadata`.
 *
 * Its distinguishing feature is embedding task types. Gemini embeds a search
 * query and an indexed document into different spaces on request, and using the
 * document task type for a query measurably costs recall. That is exactly what
 * FR-KB-06 requires, and this is the provider that can honour it.
 *
 * @see docs/01-prd.md FR-KB-06
 */
final class GeminiProvider implements ChatProviderInterface, EmbeddingProviderInterface {

	public const ID = 'gemini';

	private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

	public function __construct(
		private readonly SecretStore $secrets,
		private readonly HttpClientInterface $http,
	) {}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'Google Gemini';
	}

	public function capabilities(): Capabilities {
		return new Capabilities(
			chat: true,
			embeddings: true,
			streaming: true,
			tools: true,
			sampling: true,
			prompt_caching: false,
			// The one provider here that distinguishes query from document.
			embedding_task_types: true,
			effort: false,
		);
	}

	public function is_configured(): bool {
		return $this->secrets->has( 'provider.gemini.key' );
	}

	public function verify(): string {
		if ( ! $this->is_configured() ) {
			return __( 'No API key configured.', 'storecrew' );
		}

		try {
			$this->http->get_json( $this->url( 'models' ), array(), self::ID, 20 );
		} catch ( ProviderException $e ) {
			if ( $e->is_auth_failure() || 400 === $e->status() ) {
				// Gemini answers a bad key with 400 rather than 401, so an auth
				// failure here does not look like one anywhere else.
				return __( 'The API key was rejected.', 'storecrew' );
			}

			return $e->getMessage();
		}

		return '';
	}

	public function default_models(): array {
		return array( 'gemini-2.5-pro', 'gemini-2.5-flash' );
	}

	public function default_embedding_models(): array {
		return array( 'gemini-embedding-001', 'text-embedding-004' );
	}

	public function chat( ChatRequest $request ): ChatResponse {
		$contents = array();

		foreach ( $request->messages as $message ) {
			$contents[] = array(
				// Gemini spells the assistant role "model".
				'role'  => Message::ROLE_ASSISTANT === $message->role ? 'model' : 'user',
				'parts' => array( array( 'text' => $message->content ) ),
			);
		}

		$payload = array(
			'contents'         => $contents,
			'generationConfig' => array( 'maxOutputTokens' => $request->max_tokens ),
		);

		if ( null !== $request->temperature ) {
			$payload['generationConfig']['temperature'] = $request->temperature;
		}

		if ( '' !== $request->system ) {
			$payload['systemInstruction'] = array(
				'parts' => array( array( 'text' => $request->system ) ),
			);
		}

		$result = $this->http->post_json(
			$this->url( 'models/' . rawurlencode( $request->model ) . ':generateContent' ),
			array(),
			$payload,
			self::ID,
			$request->timeout
		);

		return $this->to_response( $result['body'], $request->model, $result['latency_ms'] );
	}

	public function embed( EmbeddingRequest $request ): EmbeddingResponse {
		$model = 'models/' . $request->model;

		// FR-KB-06: embed a query with the query-side task type. Using
		// RETRIEVAL_DOCUMENT for a search query is a silent recall regression —
		// nothing errors, results are simply worse.
		$task_type = $request->is_query() ? 'RETRIEVAL_QUERY' : 'RETRIEVAL_DOCUMENT';

		$requests = array();

		foreach ( $request->inputs as $text ) {
			$requests[] = array(
				'model'    => $model,
				'content'  => array( 'parts' => array( array( 'text' => $text ) ) ),
				'taskType' => $task_type,
			);
		}

		$result = $this->http->post_json(
			$this->url( 'models/' . rawurlencode( $request->model ) . ':batchEmbedContents' ),
			array(),
			array( 'requests' => $requests ),
			self::ID,
			$request->timeout
		);

		$vectors = array();

		foreach ( (array) ( $result['body']['embeddings'] ?? array() ) as $embedding ) {
			$vectors[] = array_map( 'floatval', (array) ( $embedding['values'] ?? array() ) );
		}

		return new EmbeddingResponse(
			$vectors,
			$request->model,
			self::ID,
			// batchEmbedContents reports no token usage. Recording zero is
			// honest; inventing an estimate would corrupt the spend cap.
			TokenUsage::none(),
			$result['latency_ms']
		);
	}

	/**
	 * Build an endpoint URL with the key as a query parameter.
	 *
	 * Gemini authenticates by query string rather than a header, which means
	 * the key can land in server access logs. Nothing can be done about that
	 * from here, but it is a reason to prefer another provider on shared
	 * hosting with verbose logging.
	 */
	private function url( string $path ): string {
		return self::BASE . '/' . $path . '?key=' . rawurlencode(
			(string) $this->secrets->get( 'provider.gemini.key' )
		);
	}

	/**
	 * @param array<string, mixed> $body Decoded response.
	 */
	private function to_response( array $body, string $model, int $latency ): ChatResponse {
		$candidate = (array) ( ( (array) ( $body['candidates'] ?? array() ) )[0] ?? array() );

		$text = '';

		foreach ( (array) ( ( (array) ( $candidate['content'] ?? array() ) )['parts'] ?? array() ) as $part ) {
			$text .= (string) ( $part['text'] ?? '' );
		}

		$meta = (array) ( $body['usageMetadata'] ?? array() );

		$usage = new TokenUsage(
			(int) ( $meta['promptTokenCount'] ?? 0 ),
			(int) ( $meta['candidatesTokenCount'] ?? 0 ),
			0,
			(int) ( $meta['cachedContentTokenCount'] ?? 0 ),
		);

		return new ChatResponse(
			$text,
			$model,
			self::ID,
			$usage,
			$this->map_finish_reason( (string) ( $candidate['finishReason'] ?? '' ) ),
			$latency,
			array( 'safetyRatings' => $candidate['safetyRatings'] ?? null )
		);
	}

	private function map_finish_reason( string $reason ): string {
		return match ( $reason ) {
			'STOP'                              => ChatResponse::STOP_END,
			'MAX_TOKENS'                        => ChatResponse::STOP_MAX,
			'SAFETY', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'RECITATION' => ChatResponse::STOP_REFUSAL,
			default                             => ChatResponse::STOP_UNKNOWN,
		};
	}
}
