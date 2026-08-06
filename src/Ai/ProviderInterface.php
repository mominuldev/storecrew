<?php
/**
 * AI provider contracts.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Everything every provider can answer.
 *
 * Chat and embeddings are separate interfaces below rather than methods here,
 * because at least one supported provider genuinely cannot do one of them:
 * Anthropic has no embeddings endpoint. Putting `embed()` on this interface
 * would force a `throw new NotSupportedException` implementation and move a
 * compile-time fact to runtime.
 *
 * @see docs/01-prd.md FR-AI-01
 */
interface ProviderInterface {

	/**
	 * Stable identifier, e.g. "anthropic". Used as a settings and metering key.
	 */
	public function id(): string;

	/**
	 * Human-readable name for the admin UI.
	 */
	public function label(): string;

	/**
	 * What this provider actually supports.
	 */
	public function capabilities(): Capabilities;

	/**
	 * Whether an API key is configured.
	 */
	public function is_configured(): bool;

	/**
	 * Cheap credential check.
	 *
	 * Returns an empty string on success, or a merchant-facing reason. Used by
	 * onboarding so a bad key is caught at setup rather than on a customer's
	 * first question.
	 */
	public function verify(): string;
}
