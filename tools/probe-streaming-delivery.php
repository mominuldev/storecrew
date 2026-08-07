<?php
/**
 * FR-CHAT-02 timed incremental delivery measurement.
 *
 * Run with: php tools/probe-streaming-delivery.php [site-url]
 *
 * Plain PHP CLI, not `wp eval-file`, because the point is the wire: it drives
 * the public chat surface over real HTTP — webserver, PHP handler, and every
 * buffer between them included — and timestamps each network chunk as cURL
 * hands it over. The schema suites already prove the frames are *correct*;
 * only this measurement can prove they arrive *while the model is still
 * writing* rather than all at once when the turn ends (which is the R-TECH-02
 * buffered fallback, indistinguishable from streaming in every test that
 * ignores time).
 *
 * What it does: opens a session, sends one message with
 * `Accept: text/event-stream`, records (arrival offset, bytes) per chunk,
 * parses the SSE frames, then closes the conversation it opened. Verdict
 * criteria, printed with the raw timeline so the numbers can be re-judged:
 *
 *  - deltas arrived in >= 3 separate network chunks (one chunk = buffered);
 *  - first delta to `done` spans >= 300 ms (all-at-once = buffered);
 *  - the concatenated deltas equal the `done` payload's reply content
 *    (the widget's one-contract-either-way guarantee, checked live).
 *
 * Spends one real model call on the configured chat provider. On a free-tier
 * Gemini key remember 09 § 3: `streamGenerateContent` is metered separately
 * from `generateContent`, so "chat works but this probe reports zero deltas
 * and an escalated sentence in `done`" is a quota state, not a bug — the run
 * record's `error_code` will say 429:RESOURCE_EXHAUSTED.
 *
 * @package StoreCrew
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$base = rtrim( $argv[1] ?? 'http://wpproduct.test', '/' ) . '/wp-json/storecrew/v1';

/**
 * One JSON request, buffered — for the session and close calls.
 *
 * @param string               $url     Absolute URL.
 * @param array<string, mixed> $body    JSON body.
 * @param array<string>        $headers Extra headers.
 * @return array{status: int, body: mixed}
 */
$json = static function ( string $url, array $body, array $headers = array() ) {
	$curl = curl_init( $url );
	curl_setopt_array(
		$curl,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode( $body ),
			CURLOPT_HTTPHEADER     => array_merge( array( 'Content-Type: application/json' ), $headers ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
		)
	);
	$raw    = curl_exec( $curl );
	$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
	curl_close( $curl );

	return array(
		'status' => $status,
		'body'   => is_string( $raw ) ? json_decode( $raw, true ) : null,
	);
};

echo "== FR-CHAT-02 timed incremental delivery ==\n";
echo "Target: {$base}\n\n";

// 1. Open a session — no cookie jar on purpose, so this is the stranger path:
// the token comes back once in the minting response and travels by header,
// exactly as a widget behind a cookie-stripping page cache would carry it.
$session = $json( $base . '/chat/session', array() );
$opened  = $session['body']['data'] ?? array();

if ( 200 !== $session['status'] || empty( $opened['token'] ) || empty( $opened['uuid'] ) ) {
	echo 'FAIL: /chat/session returned HTTP ' . $session['status'] . ' — ' . json_encode( $session['body'] ) . "\n";
	exit( 1 );
}

$uuid          = (string) $opened['uuid'];
$token_header  = 'X-StoreCrew-Session: ' . (string) $opened['token'];
echo "Session open, conversation {$uuid}.\n";

// 2. The streamed turn. The write callback is the measurement: cURL calls it
// once per network chunk, so distinct timestamps are distinct arrivals on the
// wire — the one thing a buffered response cannot fake.
$t0     = null;
$chunks = array();
$buffer = '';
$events = array();

$curl = curl_init( $base . '/chat/' . $uuid . '/messages' );
curl_setopt_array(
	$curl,
	array(
		CURLOPT_POST          => true,
		CURLOPT_POSTFIELDS    => json_encode(
			array( 'message' => 'What products do you sell? Please describe two or three of them in a couple of sentences each.' )
		),
		CURLOPT_HTTPHEADER    => array(
			'Content-Type: application/json',
			'Accept: text/event-stream',
			$token_header,
		),
		CURLOPT_TIMEOUT       => 120,
		CURLOPT_WRITEFUNCTION => static function ( $curl, string $data ) use ( &$t0, &$chunks, &$buffer, &$events ): int {
			unset( $curl );

			if ( null === $t0 ) {
				$t0 = microtime( true );
			}

			$at       = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			$chunks[] = array(
				'at'    => $at,
				'bytes' => strlen( $data ),
			);

			// Same normalisation the widget parser applies: Gemini taught us
			// upstreams may separate events with CRLF pairs (09 § 3).
			$buffer .= str_replace( "\r\n", "\n", $data );

			while ( false !== ( $split = strpos( $buffer, "\n\n" ) ) ) {
				$frame  = substr( $buffer, 0, $split );
				$buffer = substr( $buffer, $split + 2 );
				$name   = '';
				$data_  = '';

				foreach ( explode( "\n", $frame ) as $line ) {
					if ( str_starts_with( $line, 'event: ' ) ) {
						$name = substr( $line, 7 );
					} elseif ( str_starts_with( $line, 'data: ' ) ) {
						$data_ .= substr( $line, 6 );
					}
				}

				if ( '' !== $name ) {
					$events[] = array(
						'at'   => $at,
						'name' => $name,
						'data' => json_decode( $data_, true ),
					);
				}
			}

			return strlen( $data );
		},
	)
);

$sent = microtime( true );
curl_exec( $curl );
$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
$error  = curl_error( $curl );
curl_close( $curl );

// 3. Close what we opened, before judging anything.
$closed = $json( $base . '/chat/' . $uuid . '/close', array(), array( $token_header ) );
echo 'Conversation closed (HTTP ' . $closed['status'] . ").\n\n";

if ( 200 !== $status || '' !== $error ) {
	echo "FAIL: streamed request returned HTTP {$status}" . ( '' !== $error ? " ({$error})" : '' ) . "\n";
	exit( 1 );
}

// 4. The timeline, verbatim — the verdict below is derived from these numbers
// and anyone rerunning this can re-derive it.
echo "-- Chunk arrivals (ms after first byte) --\n";

foreach ( $chunks as $chunk ) {
	printf( "  +%5d ms  %4d bytes\n", $chunk['at'], $chunk['bytes'] );
}

echo "\n-- Events --\n";

$delta_ats = array();
$assembled = '';
$done      = null;

foreach ( $events as $event ) {
	if ( 'delta' === $event['name'] ) {
		$delta_ats[] = $event['at'];
		$text        = (string) ( $event['data']['text'] ?? '' );
		$assembled  .= $text;
		printf( "  +%5d ms  delta  %3d chars\n", $event['at'], mb_strlen( $text ) );
	} else {
		printf( "  +%5d ms  %s\n", $event['at'], $event['name'] );

		if ( 'done' === $event['name'] ) {
			$done = $event['data'];
		}
	}
}

$first_byte_wait = null !== $t0 ? (int) round( ( $t0 - $sent ) * 1000 ) : 0;
$distinct_ats    = count( array_unique( $delta_ats ) );
$spread          = array() === $delta_ats ? 0 : ( end( $events )['at'] - $delta_ats[0] );
$reply           = (string) ( $done['reply']['content'] ?? '' );

echo "\n-- Verdict --\n";
printf( "  request sent -> first byte      %d ms\n", $first_byte_wait );
printf( "  delta events                    %d\n", count( $delta_ats ) );
printf( "  distinct delta arrival times    %d\n", $distinct_ats );
printf( "  first delta -> done             %d ms\n", $spread );

$checks = array(
	'deltas arrived in >= 3 separate chunks' => $distinct_ats >= 3,
	'first delta -> done spans >= 300 ms'    => $spread >= 300,
	'deltas reassemble to the done reply'    => '' !== $reply && $assembled === $reply,
	'done carries the JSON-path payload'     => null !== $done && isset( $done['reply']['role'] ),
);

$pass = true;

foreach ( $checks as $label => $ok ) {
	echo '  ' . ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}\n";
	$pass = $pass && $ok;
}

if ( ! $pass && array() === $delta_ats && null !== $done ) {
	echo "\n  Zero deltas with a served `done` is the 09 § 3 quota shape:\n";
	echo "  free-tier streamGenerateContent metered separately. Check the run\n";
	echo "  record's error_code before treating this as a transport failure.\n";
}

echo "\n" . ( $pass ? 'TIMED INCREMENTAL DELIVERY VERIFIED' : 'NOT VERIFIED' ) . "\n";
exit( $pass ? 0 : 1 );
