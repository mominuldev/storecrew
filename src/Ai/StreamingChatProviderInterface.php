<?php
/**
 * Streaming chat contract.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * A chat provider that can deliver its answer as it is generated.
 *
 * An **addition** to the published contract, never a change (09 § 6): third-
 * party providers built against `ChatProviderInterface` keep working, and a
 * provider opts in by implementing this and declaring `streaming: true` —
 * which should be conditional on the transport actually being available on
 * the host (`SseClientInterface::available()`), so a declared capability is
 * never a lie on a cURL-less install.
 *
 * The contract that keeps 12 § 10 true: `stream()` returns the **same
 * assembled ChatResponse `chat()` would have returned** — text, tool calls,
 * usage, stop reason. The deltas are a duplicate view of the text for a
 * caller that wants paint-as-you-go; every decision the runner makes (tool
 * authorisation, budget, refusal, metering) reads the assembled response.
 * Streaming changes when pixels appear, never what is decided.
 */
interface StreamingChatProviderInterface extends ChatProviderInterface {

	/**
	 * Run one chat call, emitting text deltas as they arrive.
	 *
	 * @param callable $on_delta function (string $text): void — called zero or
	 *                           more times, in order; concatenating every call
	 *                           equals the returned response's text.
	 *
	 * @throws \StoreCrew\Ai\Exception\ProviderException As chat() does.
	 */
	public function stream( ChatRequest $request, callable $on_delta ): ChatResponse;
}
