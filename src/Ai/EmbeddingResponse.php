<?php
/**
 * An embedding response.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Normalised embedding response.
 */
final readonly class EmbeddingResponse {

	/**
	 * @param list<list<float>> $vectors One vector per input, in input order.
	 */
	public function __construct(
		public array $vectors,
		public string $model,
		public string $provider,
		public TokenUsage $usage,
		public int $latency_ms = 0,
	) {}

	public function count(): int {
		return count( $this->vectors );
	}

	/**
	 * Dimensionality of the first vector, or 0 when empty.
	 */
	public function dimensions(): int {
		return isset( $this->vectors[0] ) ? count( $this->vectors[0] ) : 0;
	}

	/**
	 * Whether every vector has the same dimensionality.
	 *
	 * A provider returning ragged vectors would silently poison the index —
	 * cosine similarity against a mismatched vector scores 0.0 and the chunk
	 * simply never ranks, with no error anywhere.
	 */
	public function is_uniform(): bool {
		$dims = $this->dimensions();

		foreach ( $this->vectors as $vector ) {
			if ( count( $vector ) !== $dims ) {
				return false;
			}
		}

		return true;
	}
}
