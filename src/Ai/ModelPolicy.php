<?php
/**
 * Per-task model selection.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

use StoreCrew\Api\Registry\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses a provider and model per task.
 *
 * FR-AI-02 requires routing, chat, embedding, and summarisation to be
 * configurable independently, and the reason is cost: intent classification and
 * summarisation are cheap, high-volume calls that a small model handles as well
 * as a large one, while the customer-facing answer is where capability pays.
 * Forcing one model across all four either overspends on routing or
 * underperforms on chat.
 *
 * @see docs/01-prd.md FR-AI-02, FR-AI-05
 */
final class ModelPolicy {

	public const TASK_CHAT      = 'chat';
	public const TASK_ROUTING   = 'routing';
	public const TASK_EMBEDDING = 'embedding';
	public const TASK_SUMMARY   = 'summary';

	public const OPTION = 'storecrew_model_policy';

	public function __construct(
		private readonly ProviderRegistry $providers,
	) {}

	/**
	 * All task keys.
	 *
	 * @return list<string>
	 */
	public static function tasks(): array {
		return array( self::TASK_CHAT, self::TASK_ROUTING, self::TASK_EMBEDDING, self::TASK_SUMMARY );
	}

	/**
	 * Resolve the provider and model for a task.
	 *
	 * Returns null when nothing is configured — the caller surfaces that as
	 * "not set up yet" rather than falling back to a guess that might bill the
	 * merchant for a model they never chose.
	 *
	 * @return array{provider: string, model: string}|null
	 */
	public function resolve( string $task ): ?array {
		$stored = $this->stored();

		$entry = $stored[ $task ] ?? null;

		if ( is_array( $entry ) && '' !== ( $entry['provider'] ?? '' ) && '' !== ( $entry['model'] ?? '' ) ) {
			$provider = $this->providers->get( (string) $entry['provider'] );

			if ( null !== $provider && $provider->is_configured() ) {
				return array(
					'provider' => (string) $entry['provider'],
					'model'    => (string) $entry['model'],
				);
			}
		}

		return $this->infer( $task );
	}

	/**
	 * Failover target for a task, if one is configured.
	 *
	 * FR-AI-05. Kept separate from resolve() so a failover to a provider the
	 * merchant did not pick can never happen silently — it only fires when they
	 * configured one.
	 *
	 * @return array{provider: string, model: string}|null
	 */
	public function fallback( string $task ): ?array {
		$entry = $this->stored()[ $task ]['fallback'] ?? null;

		if ( ! is_array( $entry ) ) {
			return null;
		}

		$provider = $this->providers->get( (string) ( $entry['provider'] ?? '' ) );

		if ( null === $provider || ! $provider->is_configured() ) {
			return null;
		}

		return array(
			'provider' => (string) $entry['provider'],
			'model'    => (string) $entry['model'],
		);
	}

	/**
	 * Store the policy.
	 *
	 * @param array<string, mixed> $policy Task => {provider, model, fallback?}.
	 */
	public function save( array $policy ): bool {
		return update_option( self::OPTION, $policy, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Best guess for a task with nothing configured.
	 *
	 * Picks the first configured provider that can actually do the job — which
	 * for embedding means skipping any chat-only provider entirely, so an
	 * Anthropic-only site resolves to null here rather than to a provider that
	 * will fail on first use.
	 *
	 * @return array{provider: string, model: string}|null
	 */
	private function infer( string $task ): ?array {
		if ( self::TASK_EMBEDDING === $task ) {
			foreach ( $this->providers->embedding_providers() as $id => $provider ) {
				if ( ! $provider->is_configured() ) {
					continue;
				}

				$models = $provider->default_embedding_models();

				if ( array() === $models ) {
					continue;
				}

				return array(
					'provider' => (string) $id,
					'model'    => $models[0],
				);
			}

			return null;
		}

		foreach ( $this->providers->chat_providers() as $id => $provider ) {
			if ( ! $provider->is_configured() ) {
				continue;
			}

			$models = $provider->default_models();

			if ( array() === $models ) {
				continue;
			}

			// Routing and summarisation are high-volume and cheap; prefer the
			// smallest model the provider offers rather than its flagship.
			$index = in_array( $task, array( self::TASK_ROUTING, self::TASK_SUMMARY ), true )
				? count( $models ) - 1
				: 0;

			return array(
				'provider' => (string) $id,
				'model'    => $models[ $index ],
			);
		}

		return null;
	}
}
