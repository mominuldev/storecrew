<?php
/**
 * Registry of AI providers.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Registry;

use StoreCrew\Ai\ChatProviderInterface;
use StoreCrew\Ai\EmbeddingProviderInterface;
use StoreCrew\Ai\ProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Every AI provider the installation knows about.
 *
 * Populated via `storecrew_register_providers`. Add-ons contribute their own
 * providers through the same filter the core ones use.
 *
 * @extends Registry<ProviderInterface>
 */
final class ProviderRegistry extends Registry {

	protected function name(): string {
		return 'provider';
	}

	/**
	 * @throws \InvalidArgumentException When the item is not a provider.
	 */
	protected function validate( mixed $item ): void {
		if ( ! $item instanceof ProviderInterface ) {
			throw new \InvalidArgumentException(
				sprintf( 'Expected %s, got %s.', ProviderInterface::class, get_debug_type( $item ) )
			);
		}
	}

	/**
	 * Register a provider, keyed by its own id.
	 *
	 * @return static
	 */
	public function register( ProviderInterface $provider, string $owner = 'storecrew' ): self {
		return $this->add( $provider->id(), $provider, $owner );
	}

	/**
	 * Providers that can hold a conversation.
	 *
	 * @return array<string, ChatProviderInterface>
	 */
	public function chat_providers(): array {
		return array_filter(
			$this->items,
			static fn ( $p ): bool => $p instanceof ChatProviderInterface
		);
	}

	/**
	 * Providers that can embed text.
	 *
	 * Onboarding checks this is non-empty before offering to index. An
	 * Anthropic-only installation has chat but no embeddings, so the knowledge
	 * base cannot be built until a second provider is configured — surfacing
	 * that at setup beats discovering it when indexing silently produces
	 * nothing.
	 *
	 * @return array<string, EmbeddingProviderInterface>
	 */
	public function embedding_providers(): array {
		return array_filter(
			$this->items,
			static fn ( $p ): bool => $p instanceof EmbeddingProviderInterface
		);
	}

	/**
	 * Providers with a working key configured.
	 *
	 * @return array<string, ProviderInterface>
	 */
	public function configured(): array {
		return array_filter(
			$this->items,
			static fn ( ProviderInterface $p ): bool => $p->is_configured()
		);
	}

	/**
	 * Whether anything can embed right now.
	 */
	public function can_embed(): bool {
		foreach ( $this->embedding_providers() as $provider ) {
			if ( $provider->is_configured() ) {
				return true;
			}
		}

		return false;
	}
}
