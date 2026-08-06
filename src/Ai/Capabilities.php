<?php
/**
 * What a provider can actually do.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Declares a provider's real surface, so callers stop guessing.
 *
 * This exists because the providers genuinely do not agree, and pretending
 * otherwise breaks things:
 *
 * - **Anthropic rejects `temperature`, `top_p`, and `top_k` with a 400** on
 *   current models. A client that always sends a temperature does not degrade
 *   gracefully there — every request fails.
 * - **Anthropic has no embeddings endpoint at all.** A knowledge base cannot be
 *   built on it; the merchant must configure a second provider for embeddings.
 * - **Gemini distinguishes query embeddings from document embeddings** via a
 *   task type, which is what FR-KB-06 requires and what most providers cannot
 *   express.
 *
 * The admin UI reads these to hide controls a provider will reject, and the
 * onboarding flow reads `embeddings` to explain why an Anthropic-only setup
 * cannot index a catalogue.
 *
 * @see docs/01-prd.md FR-AI-01, FR-AI-02, FR-KB-06
 */
final readonly class Capabilities {

	public function __construct(
		public bool $chat = true,
		public bool $embeddings = false,
		public bool $streaming = false,
		public bool $tools = false,
		/** Whether temperature / top_p are accepted at all. */
		public bool $sampling = true,
		/** Whether the provider supports explicit prompt-cache breakpoints. */
		public bool $prompt_caching = false,
		/** Whether embeddings distinguish query from document (FR-KB-06). */
		public bool $embedding_task_types = false,
		/** Whether a reasoning-effort hint is accepted. */
		public bool $effort = false,
	) {}

	/**
	 * @return array<string, bool>
	 */
	public function to_array(): array {
		return array(
			'chat'               => $this->chat,
			'embeddings'         => $this->embeddings,
			'streaming'          => $this->streaming,
			'tools'              => $this->tools,
			'sampling'           => $this->sampling,
			'promptCaching'      => $this->prompt_caching,
			'embeddingTaskTypes' => $this->embedding_task_types,
			'effort'             => $this->effort,
		);
	}
}
