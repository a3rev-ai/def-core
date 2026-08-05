<?php
/**
 * A scripted HTTP upstream, for driving stream_proxy() against a real socket.
 *
 * The SSE-visibility unit tests exercise three private helpers via Reflection and never
 * invoke stream_proxy() itself — so the wiring was uncovered, and four mutations that break
 * the fix outright (deleting CURLOPT_HEADERFUNCTION, deleting the post-exec belt, returning
 * 0 from the write callback, un-anchoring the status regex) left every assertion green. Two
 * of those restore the original bug; one is worse than it. Hence a real upstream.
 *
 * Usage: php sse-upstream-server.php <port> <scenario>
 * Writes "READY\n" to stdout once listening, so the driver never races the bind.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

$port     = (int) ( $argv[1] ?? 0 );
$scenario = (string) ( $argv[2] ?? 'ok' );

$server = @stream_socket_server( "tcp://127.0.0.1:$port", $errno, $errstr );
if ( ! $server ) {
	fwrite( STDERR, "bind failed: $errstr\n" );
	exit( 1 );
}
fwrite( STDOUT, "READY\n" );
fflush( STDOUT );

$conn = @stream_socket_accept( $server, 10 );
if ( ! $conn ) {
	exit( 1 );
}

// Drain the request head so curl does not see a reset before we answer.
stream_set_timeout( $conn, 2 );
while ( ( $line = fgets( $conn, 8192 ) ) !== false ) {
	if ( "\r\n" === $line || "\n" === $line ) {
		break;
	}
}

/**
 * Each scenario is [status line, headers[], body chunks[]].
 * Chunks are written with a small delay so multi-chunk cases really arrive separately.
 */
$scenarios = array(
	// A normal stream: three SSE frames, one of them a legitimate upstream error EVENT
	// which must pass through untouched.
	'ok' => array(
		"HTTP/1.1 200 OK",
		array( "Content-Type: text/event-stream" ),
		array(
			"data: {\"type\":\"text_delta\",\"text\":\"Hel\"}\n\n",
			"data: {\"type\":\"error\",\"message\":\"upstream event, must relay\"}\n\n",
			"data: {\"type\":\"done\"}\n\n",
		),
	),
	// The case this whole change exists for.
	'rate_limited' => array(
		"HTTP/1.1 429 Too Many Requests",
		array( "Content-Type: application/json", "Retry-After: 37" ),
		array( "{\"detail\":\"Too many messages\"}" ),
	),
	// Non-200 whose body arrives in several writes — exactly one frame must reach the client.
	'error_multi_chunk' => array(
		"HTTP/1.1 500 Internal Server Error",
		array( "Content-Type: application/json" ),
		array( "{\"detail\":\"part one", " and part two", " and three\"}" ),
	),
	// Non-200 with NO body: the write callback never fires, so only the post-exec belt speaks.
	'error_empty_body' => array(
		"HTTP/1.1 503 Service Unavailable",
		array( "Content-Length: 0" ),
		array(),
	),
	// A redirect surfaces AS the response (FOLLOWLOCATION is off) and its HTML body is just
	// as invisible in an SSE stream as a JSON one.
	'redirect' => array(
		"HTTP/1.1 302 Found",
		array( "Location: https://elsewhere.invalid/api/chat/stream", "Content-Type: text/html" ),
		array( "<html><body>Moved</body></html>" ),
	),
	// 100-continue then the real status: the last status line must win.
	'continue_then_429' => array(
		"HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 429 Too Many Requests",
		array( "Retry-After: 5" ),
		array( "{\"detail\":\"slow down\"}" ),
	),
	// A 200 carrying a HEADER whose VALUE looks like a status line. This is what the `^`
	// anchor in the status regex defends: header values reach the header callback, so an
	// unanchored pattern would read 500 out of this and turn a healthy stream into an error.
	'status_line_in_header' => array(
		"HTTP/1.1 200 OK",
		array( "Content-Type: text/event-stream", "X-Upstream-Note: HTTP/1.1 500 Internal Server Error" ),
		array( "data: {\"type\":\"done\"}\n\n" ),
	),
	// A 200 whose token text looks like a status line — the body must never be parsed as one.
	'status_line_in_body' => array(
		"HTTP/1.1 200 OK",
		array( "Content-Type: text/event-stream" ),
		array( "data: {\"type\":\"text_delta\",\"text\":\"HTTP/1.1 429 Too Many Requests\"}\n\n" ),
	),
);

if ( ! isset( $scenarios[ $scenario ] ) ) {
	exit( 2 );
}
list( $status, $headers, $chunks ) = $scenarios[ $scenario ];

$head = $status . "\r\n";
foreach ( $headers as $h ) {
	$head .= $h . "\r\n";
}
$head .= "Connection: close\r\n\r\n";
fwrite( $conn, $head );
fflush( $conn );

foreach ( $chunks as $chunk ) {
	usleep( 20000 );
	fwrite( $conn, $chunk );
	fflush( $conn );
}

fclose( $conn );
fclose( $server );
