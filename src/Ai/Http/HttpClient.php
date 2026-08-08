<?php
/**
 * HTTP transport for provider calls.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai\Http;

use StoreCrew\Ai\Exception\ProviderException;

defined( 'ABSPATH' ) || exit;

// Every throw here is a ProviderException carrying structured transport metadata
// (provider id, HTTP status, retryability, retry-after) and is caught by the
// provider/agent layer, never echoed. The extra constructor arguments are typed
// data, not output, and escaping them would break their types — so the
// exception-output sniff is a false positive across this transport file.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * JSON-over-HTTP with retry and backoff.
 *
 * Built on the WordPress HTTP API rather than a bundled client library. Two
 * reasons, both practical: shipping Guzzle inside a .org plugin collides with
 * every other plugin shipping a different Guzzle on the same site, and
 * `wp_remote_post` honours the proxy constants and `http_request_args` filters
 * that merchants on locked-down hosting depend on. A vendored client bypasses
 * both and fails on exactly the hosts that are hardest to debug.
 *
 * FR-AI-05.
 */
final class HttpClient implements HttpClientInterface {

	/**
	 * Statuses worth retrying: rate limits, server faults, and overload.
	 * Everything else in the 4xx range is a request the provider will reject
	 * identically no matter how many times it is sent.
	 */
	private const RETRYABLE_STATUSES = array( 408, 409, 425, 429, 500, 502, 503, 504, 529 );

	private const BASE_DELAY_MS = 500;
	private const MAX_DELAY_MS  = 8000;

	public function __construct(
		private readonly int $max_retries = 2,
	) {}

	/**
	 * POST a JSON body and decode the JSON response.
	 *
	 * @param array<string, string> $headers Request headers.
	 * @param array<string, mixed>  $body    Payload, JSON-encoded before sending.
	 *
	 * @return array{status: int, body: array<string, mixed>, latency_ms: int}
	 *
	 * @throws ProviderException On network failure, a non-2xx status, or unparseable JSON.
	 */
	public function post_json(
		string $url,
		array $headers,
		array $body,
		string $provider,
		int $timeout = 60
	): array {
		$encoded = wp_json_encode( $body );

		if ( false === $encoded ) {
			throw new ProviderException( 'Request body could not be encoded as JSON.', $provider );
		}

		$attempt = 0;
		$started = microtime( true );

		while ( true ) {
			$response = wp_remote_post(
				$url,
				array(
					'headers' => $headers + array( 'Content-Type' => 'application/json' ),
					'body'    => $encoded,
					'timeout' => $timeout,
				)
			);

			$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( is_wp_error( $response ) ) {
				$error = new ProviderException(
					sprintf( 'Network error contacting %s: %s', $provider, $response->get_error_message() ),
					$provider,
					0,
					true
				);

				if ( $this->should_give_up( $attempt ) ) {
					throw $error;
				}

				$this->sleep_before_retry( $attempt, 0 );
				++$attempt;

				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );

			if ( $status >= 200 && $status < 300 ) {
				$decoded = json_decode( $raw, true );

				if ( ! is_array( $decoded ) ) {
					throw new ProviderException(
						sprintf( '%s returned a %d with a body that is not JSON.', $provider, $status ),
						$provider,
						$status
					);
				}

				return array(
					'status'     => $status,
					'body'       => $decoded,
					'latency_ms' => $latency,
				);
			}

			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$retryable   = in_array( $status, self::RETRYABLE_STATUSES, true );

			if ( ! $retryable || $this->should_give_up( $attempt ) ) {
				throw $this->build_error( $provider, $status, $raw, $retryable, $retry_after );
			}

			$this->sleep_before_retry( $attempt, $retry_after );
			++$attempt;
		}
	}

	/**
	 * GET a JSON endpoint. Used for credential verification and model listing.
	 *
	 * @param array<string, string> $headers Request headers.
	 *
	 * @return array{status: int, body: array<string, mixed>, latency_ms: int}
	 *
	 * @throws ProviderException On network failure or a non-2xx status.
	 */
	public function get_json( string $url, array $headers, string $provider, int $timeout = 30 ): array {
		$attempt = 0;
		$started = microtime( true );

		// The same retry discipline as POST. This is the credential-check
		// path for most providers, and without it a transient 503 during
		// verify() reads to the merchant as "your API key was rejected".
		while ( true ) {
			$response = wp_remote_get(
				$url,
				array(
					'headers' => $headers,
					'timeout' => $timeout,
				)
			);

			$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( is_wp_error( $response ) ) {
				$error = new ProviderException(
					sprintf( 'Network error contacting %s: %s', $provider, $response->get_error_message() ),
					$provider,
					0,
					true
				);

				if ( $this->should_give_up( $attempt ) ) {
					throw $error;
				}

				$this->sleep_before_retry( $attempt, 0 );
				++$attempt;

				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );

			if ( $status >= 200 && $status < 300 ) {
				$decoded = json_decode( $raw, true );

				return array(
					'status'     => $status,
					'body'       => is_array( $decoded ) ? $decoded : array(),
					'latency_ms' => $latency,
				);
			}

			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$retryable   = in_array( $status, self::RETRYABLE_STATUSES, true );

			if ( ! $retryable || $this->should_give_up( $attempt ) ) {
				throw $this->build_error( $provider, $status, $raw, $retryable, $retry_after );
			}

			$this->sleep_before_retry( $attempt, $retry_after );
			++$attempt;
		}
	}

	private function should_give_up( int $attempt ): bool {
		return $attempt >= $this->max_retries;
	}

	/**
	 * Wait before the next attempt.
	 *
	 * Honours the provider's own Retry-After when present — guessing shorter
	 * than a rate limiter asked for just earns another 429. Otherwise
	 * exponential backoff with jitter, so a burst of failing requests doesn't
	 * retry in lockstep and reproduce the spike that caused the limit.
	 */
	private function sleep_before_retry( int $attempt, int $retry_after ): void {
		if ( $retry_after > 0 ) {
			// Cap it: a provider asking us to wait ten minutes should not hold a
			// storefront request open for ten minutes.
			sleep( min( $retry_after, 10 ) );

			return;
		}

		$delay_ms = min( self::BASE_DELAY_MS * ( 2 ** $attempt ), self::MAX_DELAY_MS );
		$jitter   = wp_rand( 0, (int) ( $delay_ms * 0.25 ) );

		usleep( ( $delay_ms + $jitter ) * 1000 );
	}

	/**
	 * Turn an error body into a typed exception.
	 *
	 * Providers disagree on error shape — Anthropic nests under `error.message`,
	 * OpenAI under `error.message` too but with a different `type` vocabulary,
	 * Gemini under `error.message` with a `status` string. Pull what we can and
	 * fall back to the raw body rather than losing the reason.
	 */
	private function build_error(
		string $provider,
		int $status,
		string $raw,
		bool $retryable,
		int $retry_after
	): ProviderException {
		$decoded = json_decode( $raw, true );
		$message = '';
		$code    = '';

		if ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$message = (string) ( $decoded['error']['message'] ?? '' );
			$code    = (string) ( $decoded['error']['type'] ?? $decoded['error']['status'] ?? '' );
		}

		if ( '' === $message ) {
			$message = mb_substr( $raw, 0, 300 );
		}

		return new ProviderException(
			sprintf( '%s returned %d: %s', $provider, $status, $message ),
			$provider,
			$status,
			$retryable,
			$retry_after,
			$code
		);
	}
}
