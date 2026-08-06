<?php
/**
 * OpenRouter provider.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * OpenRouter — a routing layer in front of many models.
 *
 * Chat only. Model ids are namespaced by upstream vendor ("anthropic/...",
 * "openai/..."), so the model policy cannot assume a bare model name is
 * portable between this provider and a direct one.
 */
final class OpenRouterProvider extends OpenAiCompatibleProvider {

	public const ID = 'openrouter';

	protected function base_url(): string {
		return 'https://openrouter.ai/api/v1';
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'OpenRouter';
	}

	public function default_models(): array {
		return array(
			'anthropic/claude-sonnet-5',
			'openai/gpt-4.1-mini',
			'google/gemini-2.5-flash',
		);
	}

	/**
	 * OpenRouter asks integrators to identify themselves for attribution and
	 * per-app rate limiting. The site URL is the merchant's own, not ours.
	 *
	 * @return array<string, string>
	 */
	protected function headers(): array {
		return parent::headers() + array(
			'HTTP-Referer' => (string) home_url(),
			'X-Title'      => 'StoreCrew AI',
		);
	}
}
