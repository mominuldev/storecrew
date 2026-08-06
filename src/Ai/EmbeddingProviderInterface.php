<?php
/**
 * Embedding capability.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

use StoreCrew\Ai\Exception\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * A provider that can embed text.
 *
 * Anthropic deliberately does not implement this — it has no embeddings
 * endpoint, so a merchant running Anthropic for chat must configure a second
 * provider before the knowledge base can be indexed. Onboarding checks for at
 * least one implementation of this interface before offering to index.
 */
interface EmbeddingProviderInterface extends ProviderInterface {

	/**
	 * Embed one or more texts.
	 *
	 * @throws ProviderException On transport failure or an API error.
	 */
	public function embed( EmbeddingRequest $request ): EmbeddingResponse;

	/**
	 * Default embedding model ids, newest first.
	 *
	 * @return list<string>
	 */
	public function default_embedding_models(): array;
}
