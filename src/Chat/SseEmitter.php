<?php
/**
 * Server-sent events, from a REST callback.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Emits one turn as an SSE stream and ends the request.
 *
 * WP_REST_Server buffers: a callback returns a value and the server encodes
 * it once, at the end. Streaming therefore steps outside that contract —
 * headers go out directly, events are echoed and flushed as they happen, and
 * the request is terminated before the REST server can wrap anything. That
 * is a documented pattern, not a hack, and it is confined to this class.
 *
 * **The fallback is by construction** (R-TECH-02): a host that buffers output
 * anyway delivers the same event stream in one piece at the end, and the
 * widget's parser handles arrived-all-at-once identically to
 * arrived-token-by-token. Nothing needs to detect buffering; buffering just
 * degrades streaming into exactly the buffered experience the JSON path
 * gives.
 *
 * The writer and terminator are injectable because the default terminator is
 * `exit` — and a probe that exits is a probe that never reports.
 */
final class SseEmitter {

	/** @var callable */
	private $write;

	/** @var callable */
	private $terminate;

	private bool $started = false;

	public function __construct( ?callable $write = null, ?callable $terminate = null ) {
		$this->write = $write ?? static function ( string $chunk ): void {
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE frames of JSON-encoded data; encoding is the escaping.

			if ( function_exists( 'flush' ) ) {
				flush();
			}
		};

		$this->terminate = $terminate ?? static function (): void {
			exit;
		};
	}

	/**
	 * Send the SSE headers and disable every buffer we can reach.
	 */
	public function open(): void {
		if ( $this->started ) {
			return;
		}

		$this->started = true;

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/event-stream; charset=utf-8' );
			header( 'Cache-Control: no-cache' );
			// Tells nginx's proxy layer not to buffer this response — the
			// most common "streaming works locally, buffers in production".
			header( 'X-Accel-Buffering: no' );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
	}

	/**
	 * One text delta.
	 */
	public function delta( string $text ): void {
		$this->event( 'delta', array( 'text' => $text ) );
	}

	/**
	 * The final payload — the same shape the JSON path returns, so the widget
	 * has one contract however the answer travelled.
	 *
	 * @param array<string, mixed> $payload The JSON path's response data.
	 */
	public function done( array $payload ): void {
		$this->event( 'done', $payload );
		( $this->terminate )();
	}

	/**
	 * A terminal error in SSE form, for failures after headers are gone.
	 *
	 * @param array<string, mixed> $detail Error payload.
	 */
	public function fail( array $detail ): void {
		$this->event( 'error', $detail );
		( $this->terminate )();
	}

	/**
	 * @param array<string, mixed> $data Event payload.
	 */
	private function event( string $name, array $data ): void {
		$this->open();

		( $this->write )(
			'event: ' . $name . "\n"
			. 'data: ' . (string) wp_json_encode( $data ) . "\n\n"
		);
	}
}
