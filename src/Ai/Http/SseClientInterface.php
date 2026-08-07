<?php
/**
 * Streaming transport contract.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai\Http;

defined( 'ABSPATH' ) || exit;

/**
 * POST a request and surface server-sent events as they arrive.
 *
 * A separate interface rather than a method on HttpClientInterface: the
 * buffered client is implemented on the WordPress HTTP API, which cannot
 * stream (09 § 6), and widening its contract would force every test double to
 * fake a capability the real implementation lacks. Providers that stream take
 * this alongside the buffered client and consult `available()` — a host with
 * the cURL extension disabled falls back to buffered chat rather than failing.
 */
interface SseClientInterface {

	/**
	 * Whether this transport can stream at all on this host.
	 */
	public function available(): bool;

	/**
	 * POST JSON and invoke $on_event with each decoded SSE data payload.
	 *
	 * @param array<string, string> $headers  Request headers.
	 * @param array<string, mixed>  $body     JSON payload.
	 * @param callable              $on_event function (array $event): void — one
	 *                                        decoded `data:` payload per call,
	 *                                        in arrival order.
	 *
	 * @return array{status: int, latency_ms: int}
	 *
	 * @throws \StoreCrew\Ai\Exception\ProviderException On network failure or a non-2xx status.
	 */
	public function post_sse(
		string $url,
		array $headers,
		array $body,
		string $provider,
		callable $on_event,
		int $timeout = 120
	): array;
}
