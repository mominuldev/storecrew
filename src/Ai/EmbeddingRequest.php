<?php
/**
 * An embedding request.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-neutral embedding request.
 *
 * `task` carries the query-vs-document distinction FR-KB-06 requires. Providers
 * that model it (Gemini's `taskType`) map it onto their own vocabulary;
 * providers that don't ignore it. Embedding a search query with the
 * document-side task type measurably degrades recall on providers that
 * distinguish them, which is why this rides on the request rather than being
 * inferred at the call site.
 */
final readonly class EmbeddingRequest {

	public const TASK_DOCUMENT = 'document';
	public const TASK_QUERY    = 'query';

	/**
	 * @param list<string> $inputs Texts to embed.
	 */
	public function __construct(
		public string $model,
		public array $inputs,
		public string $task = self::TASK_DOCUMENT,
		public int $timeout = 60,
		/**
		 * Requested output dimensionality, where the provider supports it.
		 *
		 * Zero means the model's default. This is the single largest lever on
		 * index size: Gemini's default is 3072 dimensions — 12 KB per vector —
		 * and truncating to 1536 or 768 halves or quarters storage. At 50,000
		 * products that is the difference between roughly 1.8 GB and 460 MB,
		 * which decides whether the plugin is viable on budget shared hosting
		 * at all.
		 *
		 * Recall at each setting has to be measured, not assumed.
		 */
		public int $dimensions = 0,
	) {
		if ( array() === $inputs ) {
			throw new \InvalidArgumentException( 'An embedding request needs at least one input.' );
		}

		if ( ! in_array( $task, array( self::TASK_DOCUMENT, self::TASK_QUERY ), true ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown embedding task "%s".', $task ) ) );
		}
	}

	public function is_query(): bool {
		return self::TASK_QUERY === $this->task;
	}
}
