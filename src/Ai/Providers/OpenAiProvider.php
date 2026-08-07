<?php
/**
 * OpenAI provider.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai\Providers;

use StoreCrew\Ai\Capabilities;
use StoreCrew\Ai\EmbeddingProviderInterface;
use StoreCrew\Ai\EmbeddingRequest;
use StoreCrew\Ai\EmbeddingResponse;
use StoreCrew\Ai\TokenUsage;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI — chat and embeddings.
 *
 * The embeddings half matters disproportionately: it is one of only two
 * configured providers that can build the knowledge base at all, so a merchant
 * running Anthropic or DeepSeek for chat still needs this or Gemini configured
 * before anything can be indexed.
 */
final class OpenAiProvider extends OpenAiCompatibleProvider implements EmbeddingProviderInterface {

	public const ID = 'openai';

	protected function base_url(): string {
		return 'https://api.openai.com/v1';
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'OpenAI';
	}

	public function capabilities(): Capabilities {
		return new Capabilities(
			chat: true,
			embeddings: true,
			// Not yet implemented — flips true when this provider implements
			// StreamingChatProviderInterface (the Gemini pattern). Declaring it
			// before then was a capability the code could not honour.
			streaming: false,
			tools: true,
			sampling: true,
			prompt_caching: true,
			// Embeddings are symmetric here — the same model embeds queries and
			// documents identically, so FR-KB-06's task distinction is a no-op.
			embedding_task_types: false,
			effort: false,
		);
	}

	public function default_models(): array {
		return array( 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o' );
	}

	public function default_embedding_models(): array {
		return array( 'text-embedding-3-small', 'text-embedding-3-large' );
	}

	public function embed( EmbeddingRequest $request ): EmbeddingResponse {
		$result = $this->http->post_json(
			$this->base_url() . '/embeddings',
			$this->headers(),
			array(
				'model' => $request->model,
				'input' => $request->inputs,
			),
			self::ID,
			$request->timeout
		);

		$body = $result['body'];

		// The API documents that results come back in input order, but it also
		// returns an explicit index on each. Sorting by it costs nothing and
		// removes a silent misalignment between chunk and vector if that ever
		// stops holding.
		$rows = (array) ( $body['data'] ?? array() );

		usort(
			$rows,
			static fn ( $a, $b ): int => ( (int) ( $a['index'] ?? 0 ) ) <=> ( (int) ( $b['index'] ?? 0 ) )
		);

		$vectors = array();

		foreach ( $rows as $row ) {
			$vectors[] = array_map( 'floatval', (array) ( $row['embedding'] ?? array() ) );
		}

		$usage_raw = (array) ( $body['usage'] ?? array() );

		return new EmbeddingResponse(
			$vectors,
			(string) ( $body['model'] ?? $request->model ),
			self::ID,
			new TokenUsage( (int) ( $usage_raw['prompt_tokens'] ?? 0 ) ),
			$result['latency_ms']
		);
	}
}
