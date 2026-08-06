<?php
/**
 * DeepSeek provider.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * DeepSeek — chat only, via an OpenAI-compatible endpoint.
 *
 * No embeddings capability is declared. If DeepSeek ships one, adding it is a
 * capability flag and an `embed()` method here; claiming it speculatively would
 * let onboarding tell a merchant their catalogue can be indexed when it cannot.
 */
final class DeepSeekProvider extends OpenAiCompatibleProvider {

	public const ID = 'deepseek';

	protected function base_url(): string {
		return 'https://api.deepseek.com/v1';
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'DeepSeek';
	}

	public function default_models(): array {
		return array( 'deepseek-chat', 'deepseek-reasoner' );
	}
}
