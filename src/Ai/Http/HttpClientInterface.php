<?php
/**
 * HTTP transport contract.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai\Http;

use StoreCrew\Ai\Exception\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * The transport providers talk through.
 *
 * Extracted so provider request-shaping can be tested against a recorder rather
 * than a live API. That matters more than usual here: the three provider
 * families disagree about where the system prompt goes, what the assistant role
 * is called, and which sampling parameters are legal — and every one of those
 * is a silent wrong-answer bug rather than a crash if it is mistranslated.
 */
interface HttpClientInterface {

	/**
	 * POST a JSON body and decode the JSON response.
	 *
	 * @param array<string, string> $headers Request headers.
	 * @param array<string, mixed>  $body    Payload.
	 *
	 * @return array{status: int, body: array<string, mixed>, latency_ms: int}
	 *
	 * @throws ProviderException On network failure or a non-2xx status.
	 */
	public function post_json(
		string $url,
		array $headers,
		array $body,
		string $provider,
		int $timeout = 60
	): array;

	/**
	 * GET a JSON endpoint.
	 *
	 * @param array<string, string> $headers Request headers.
	 *
	 * @return array{status: int, body: array<string, mixed>, latency_ms: int}
	 *
	 * @throws ProviderException On network failure or a non-2xx status.
	 */
	public function get_json( string $url, array $headers, string $provider, int $timeout = 30 ): array;
}
