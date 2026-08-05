<?php
/**
 * SSE error visibility tests.
 *
 * The bug: DEF answers a refused request (a GATE 2 rate-limit 429, or any non-200) with a
 * JSON document, and stream_proxy() echoed that document into a text/event-stream. A JSON
 * body parses as zero SSE events, so the widget rendered NOTHING — the ceiling worked and
 * looked like a dead chat widget.
 *
 * Verifies:
 * - a 429 with Retry-After becomes an SSE error event the widgets already render
 * - the seconds from Retry-After reach the visitor
 * - the error is emitted ONCE, not per chunk
 * - the 2xx path is byte-identical to before, including chunks that look like errors
 * - upstream status + Retry-After parsing, including the forms we deliberately ignore
 *
 * Runs standalone (no WordPress bootstrap). Uses ReflectionMethod for the private helpers.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'DEF_CORE_PLUGIN_DIR' ) ) {
	define( 'DEF_CORE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/wp-stubs.php';

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return false;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args ): void {}
}
if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! class_exists( 'DEF_Core' ) ) {
	class DEF_Core {
		public static function get_def_api_url_internal(): string {
			return 'http://localhost:8000';
		}
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-tools.php';

$pass = 0;
$fail = 0;

function assert_true( $value, string $label ): void {
	global $pass, $fail;
	if ( $value ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label\n";
	}
}

function assert_false( $value, string $label ): void {
	assert_true( ! $value, $label );
}

/** Call a private static on DEF_Core_Tools. */
function call_private( string $method, array $args ) {
	$m = new ReflectionMethod( 'DEF_Core_Tools', $method );
	$m->setAccessible( true );
	return $m->invokeArgs( null, $args );
}

/** stream_chunk() mutates its state array, so it needs by-reference invocation. */
function call_stream_chunk( string $data, array &$state ): string {
	$m = new ReflectionMethod( 'DEF_Core_Tools', 'stream_chunk' );
	$m->setAccessible( true );
	$args = array( $data, &$state );
	return $m->invokeArgs( null, $args );
}

function call_note_header( string $header, array &$state ): void {
	$m = new ReflectionMethod( 'DEF_Core_Tools', 'note_upstream_header' );
	$m->setAccessible( true );
	$args = array( $header, &$state );
	$m->invokeArgs( null, $args );
}

function fresh_state(): array {
	return array(
		'status'      => 0,
		'retry_after' => 0,
		'error_sent'  => false,
	);
}

/** Decode the JSON out of a `data: {...}\n\n` frame. */
function decode_frame( string $frame ): ?array {
	if ( strpos( $frame, 'data: ' ) !== 0 || substr( $frame, -2 ) !== "\n\n" ) {
		return null;
	}
	$json = substr( $frame, 6, -2 );
	$out  = json_decode( $json, true );
	return is_array( $out ) ? $out : null;
}

echo "=== SSE Error Visibility Tests ===\n";

// ── 1. A 429 becomes an event the widget renders ─────────────────────────
echo "\n[1] 429 with Retry-After -> a visible SSE error event\n";

$state = fresh_state();
call_note_header( "HTTP/1.1 429 Too Many Requests\r\n", $state );
call_note_header( "Retry-After: 37\r\n", $state );
assert_true( 429 === $state['status'], 'status parsed from the upstream status line' );
assert_true( 37 === $state['retry_after'], 'Retry-After parsed' );

// DEF answers with a JSON document; it must never reach the client as-is.
$out = call_stream_chunk( '{"detail":"Too many messages — slow down"}', $state );
$evt = decode_frame( $out );
assert_true( null !== $evt, 'output is one well-formed SSE data frame' );
assert_true( 'error' === ( $evt['type'] ?? '' ), 'type is "error" — the shape both widgets render' );
assert_true( ! empty( $evt['message'] ), 'carries a message (widgets fall back to generic copy without it)' );
assert_true( 429 === ( $evt['status'] ?? 0 ), 'status rides along' );
assert_true( 37 === ( $evt['retry_after'] ?? 0 ), 'retry_after rides along' );
assert_true(
	is_array( $evt ) && false !== strpos( (string) ( $evt['message'] ?? '' ), '37' ),
	'the visitor is told how long to wait'
);
assert_true( strpos( $out, '{"detail"' ) === false, 'the upstream JSON document is NOT forwarded' );

// ── 2. Emitted once, not per chunk ───────────────────────────────────────
echo "\n[2] The error is emitted once, however many chunks arrive\n";

$second = call_stream_chunk( 'more of the error document', $state );
$third  = call_stream_chunk( 'and more', $state );
assert_true( '' === $second, 'second chunk emits nothing' );
assert_true( '' === $third, 'third chunk emits nothing' );

// ── 3. Singular/plural and the no-Retry-After case ───────────────────────
echo "\n[3] Wait-time copy: seconds, one second, and unknown\n";

$one = decode_frame( call_private( 'sse_error_payload', array( 429, 1 ) ) );
assert_true(
	is_array( $one ) && false !== strpos( (string) ( $one['message'] ?? '' ), '1 second before' ),
	'singular form for 1 second'
);

$none = decode_frame( call_private( 'sse_error_payload', array( 429, 0 ) ) );
assert_true( is_array( $none ) && ! empty( $none['message'] ), 'still a message with no Retry-After' );
assert_false( isset( $none['retry_after'] ), 'retry_after omitted when unknown, never sent as 0' );
assert_true( 429 === $none['status'], 'status still present' );

// ── 4. Other non-200s get a message too, not silence ─────────────────────
echo "\n[4] Any non-200 is visible, not only 429\n";

foreach ( array( 500, 502, 401, 400 ) as $code ) {
	$state = fresh_state();
	call_note_header( "HTTP/1.1 $code Whatever\r\n", $state );
	$evt = decode_frame( call_stream_chunk( '{"error":"nope"}', $state ) );
	assert_true( null !== $evt && 'error' === $evt['type'], "status $code produces an error event" );
	assert_true( ! empty( $evt['message'] ), "status $code carries a message" );
	assert_false( isset( $evt['retry_after'] ), "status $code has no retry_after" );
}

// ── 5. The 2xx path is byte-identical ────────────────────────────────────
// The regression that would matter most: this function is on every streamed token of
// every channel. A chunk must come back EXACTLY as it went in — including one that
// happens to look like an error, and including binary-ish and empty chunks.
echo "\n[5] 2xx path is byte-identical to the input\n";

$state = fresh_state();
call_note_header( "HTTP/1.1 200 OK\r\n", $state );
$chunks = array(
	"data: {\"type\":\"text_delta\",\"text\":\"Hello\"}\n\n",
	"data: {\"type\":\"error\",\"message\":\"a real upstream error EVENT, must pass through\"}\n\n",
	"data: {\"type\":\"done\"}\n\n",
	'',
	"\n",
	"partial frame without a terminator",
	"data: {\"text\":\"emoji 🙂 and \\\"quotes\\\"\"}\n\n",
);
foreach ( $chunks as $i => $chunk ) {
	assert_true( call_stream_chunk( $chunk, $state ) === $chunk, "chunk $i passes through unchanged" );
}
assert_false( $state['error_sent'], 'no error was synthesised on the 2xx path' );

// ── 6. Header parsing: what we accept and what we ignore ─────────────────
echo "\n[6] Upstream header parsing\n";

$state = fresh_state();
call_note_header( "retry-after: 12\r\n", $state );
assert_true( 12 === $state['retry_after'], 'Retry-After is case-insensitive' );

$state = fresh_state();
// The HTTP-date form is legal but DEF never sends it; a date parsed wrong would be worse
// than saying nothing, so it is ignored and the message falls back to "a moment".
call_note_header( "Retry-After: Wed, 21 Oct 2026 07:28:00 GMT\r\n", $state );
assert_true( 0 === $state['retry_after'], 'HTTP-date Retry-After ignored, not misread' );

$state = fresh_state();
call_note_header( "X-Retry-After-Something: 99\r\n", $state );
assert_true( 0 === $state['retry_after'], 'a lookalike header name does not match' );

$state = fresh_state();
call_note_header( "HTTP/1.1 100 Continue\r\n", $state );
call_note_header( "HTTP/2 429 \r\n", $state );
assert_true( 429 === $state['status'], 'the last status line wins, so 100-continue cannot mask it' );

$state = fresh_state();
call_note_header( "Content-Type: text/event-stream\r\n", $state );
assert_true( 0 === $state['status'], 'a normal header leaves status untouched' );

// ── 7. A non-200 with an empty body still speaks ─────────────────────────
// The write callback never fires when there is no body, which is why stream_proxy has a
// post-exec belt. Assert the payload builder works standalone for that path.
echo "\n[7] Non-200 with an empty body still yields an event\n";

$evt = decode_frame( call_private( 'sse_error_payload', array( 503, 0 ) ) );
assert_true( null !== $evt && 'error' === $evt['type'], 'empty-body path has an event to send' );

echo "\n--- SSE Error Visibility Tests: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
