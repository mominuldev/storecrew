<?php
/**
 * The one place raw cURL is allowed.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai\Http;

use StoreCrew\Ai\Exception\ProviderException;

defined( 'ABSPATH' ) || exit;

// Every throw here is a ProviderException carrying structured transport metadata
// (provider id, HTTP status, timeout) and is caught by the provider/agent layer,
// never echoed. The extra constructor arguments are typed data, not output, and
// escaping them would break their types — so the exception-output sniff is a
// false positive across this transport file.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * SSE over raw cURL with a write callback.
 *
 * The no-bundled-client rule (03 § 5) stands: this bundles nothing — it uses
 * PHP's own cURL extension, because `wp_remote_post` buffers the whole
 * response by design and streaming is the one job that cannot be done that
 * way (09 § 6, R-TECH-02). What the rule actually protects is honoured
 * explicitly: the WordPress proxy constants are consulted, and a host
 * without cURL degrades to the buffered path via `available()` rather than
 * erroring.
 *
 * SSE framing: events are separated by a blank line; each `data:` line
 * carries a JSON payload. A `[DONE]` sentinel (the OpenAI dialect) ends the
 * stream and is swallowed here — providers should not each re-learn it.
 */
final class CurlSseClient implements SseClientInterface {

	public function available(): bool {
		return function_exists( 'curl_init' );
	}

	public function post_sse(
		string $url,
		array $headers,
		array $body,
		string $provider,
		callable $on_event,
		int $timeout = 120
	): array {
		if ( ! $this->available() ) {
			throw new ProviderException( 'Streaming transport is unavailable on this host.', 'sse' );
		}

		$started = microtime( true );

		$header_lines = array( 'Content-Type: application/json', 'Accept: text/event-stream' );

		foreach ( $headers as $name => $value ) {
			$header_lines[] = $name . ': ' . $value;
		}

		$buffer = '';
		$parsed = static function ( string $chunk ) use ( &$buffer, $on_event ): int {
			// The SSE spec permits CRLF line endings and Gemini uses them, so
			// the event separator arrives as \r\n\r\n — normalise before
			// splitting or every event stays glued in the buffer and the
			// stream "succeeds" with nothing parsed. Found live; invisible to
			// every scripted probe, which naturally wrote tidy \n\n.
			$buffer .= str_replace( "\r\n", "\n", $chunk );

			// Events end at a blank line. Anything after the last separator is
			// an incomplete event and stays in the buffer for the next chunk.
			// phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the classic incremental-parse loop; assignment in the condition is the idiom.
			while ( false !== ( $cut = strpos( $buffer, "\n\n" ) ) ) {
				$event  = substr( $buffer, 0, $cut );
				$buffer = substr( $buffer, $cut + 2 );

				foreach ( explode( "\n", $event ) as $line ) {
					$line = rtrim( $line, "\r" );

					if ( ! str_starts_with( $line, 'data:' ) ) {
						continue;
					}

					$payload = trim( substr( $line, 5 ) );

					if ( '' === $payload || '[DONE]' === $payload ) {
						continue;
					}

					$decoded = json_decode( $payload, true );

					if ( is_array( $decoded ) ) {
						$on_event( $decoded );
					}
				}
			}

			return strlen( $chunk );
		};

		// phpcs:disable WordPress.WP.AlternativeFunctions -- see the class comment: streaming is the documented exception, and wp_remote_post cannot do it.
		$handle = curl_init( $url );

		if ( false === $handle ) {
			throw new ProviderException( 'Could not initialise the streaming transport.', $provider );
		}

		$error_body = '';

		curl_setopt_array(
			$handle,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => (string) wp_json_encode( $body ),
				CURLOPT_HTTPHEADER     => $header_lines,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_WRITEFUNCTION  => static function ( $h, string $chunk ) use ( $parsed, &$error_body ): int {
					$status = (int) curl_getinfo( $h, CURLINFO_RESPONSE_CODE );

					// A failing request delivers its error JSON through the same
					// callback; capture it for the exception instead of feeding
					// it to the event parser.
					if ( $status >= 400 ) {
						$error_body .= $chunk;

						return strlen( $chunk );
					}

					return $parsed( $chunk );
				},
			)
		);

		// The locked-down-host rule the WP HTTP API normally enforces for us:
		// honour the site's proxy constants here too.
		if ( defined( 'WP_PROXY_HOST' ) && defined( 'WP_PROXY_PORT' ) ) {
			curl_setopt( $handle, CURLOPT_PROXY, WP_PROXY_HOST );
			curl_setopt( $handle, CURLOPT_PROXYPORT, (int) WP_PROXY_PORT );

			if ( defined( 'WP_PROXY_USERNAME' ) && defined( 'WP_PROXY_PASSWORD' ) ) {
				curl_setopt( $handle, CURLOPT_PROXYUSERPWD, WP_PROXY_USERNAME . ':' . WP_PROXY_PASSWORD );
			}
		}

		$ok     = curl_exec( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$errno  = curl_errno( $handle );
		$error  = curl_error( $handle );
		curl_close( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( false === $ok && CURLE_OPERATION_TIMEDOUT === $errno ) {
			throw new ProviderException(
				sprintf( '%s streaming timed out after %ds.', $provider, $timeout ),
				$provider,
				0,
				true
			);
		}

		if ( false === $ok ) {
			throw new ProviderException(
				sprintf( '%s streaming failed: %s', $provider, '' !== $error ? $error : 'unknown transport error' ),
				$provider,
				0,
				true
			);
		}

		if ( $status >= 400 ) {
			$detail = '';

			$decoded = json_decode( $error_body, true );

			if ( is_array( $decoded ) ) {
				$detail = (string) ( $decoded['error']['message'] ?? '' );
			}

			throw new ProviderException(
				sprintf(
					'%s returned %d%s',
					$provider,
					$status,
					'' !== $detail ? ': ' . $detail : ''
				),
				$provider,
				$status,
				in_array( $status, array( 408, 409, 425, 429, 500, 502, 503, 504 ), true )
			);
		}

		return array(
			'status'     => $status,
			'latency_ms' => $latency,
		);
	}
}
