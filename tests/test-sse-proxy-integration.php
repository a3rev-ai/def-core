<?php
/**
 * stream_proxy() driven against a real HTTP upstream.
 *
 * WHY this exists, from the review panel: the helper tests
 * (test-sse-error-visibility.php) cover the three private statics via Reflection and never
 * invoke stream_proxy(), so the fix's WIRING was untested. Four mutations that break it
 * outright survived all 41 assertions:
 *
 *   - delete CURLOPT_HEADERFUNCTION  -> status stays 0, the JSON document flows into the SSE
 *                                       stream, the original bug is fully back
 *   - delete the post-exec belt      -> a non-200 with an empty body says nothing
 *   - write callback returns 0       -> curl aborts after the FIRST chunk on every channel,
 *                                       which is worse than the bug being fixed
 *   - un-anchor the status regex     -> a status line inside a body could set the status
 *
 * All four are killed here. Same lesson as the metering gate the same day: testing the
 * decision is not testing the feature.
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

foreach ( array( 'is_user_logged_in' => 'return false;' ) as $fn => $_body ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function $fn() { $_body }" );
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $h, $c, int $p = 10, int $a = 1 ): void {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $h, $c, int $p = 10, int $a = 1 ): void {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $h, $v ) {
		return $v;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $n, string $r, array $a ): void {}
}
if ( ! function_exists( '__' ) ) {
	function __( string $t, string $d = 'default' ): string {
		return $t;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( string $s, string $p, int $n, string $d = 'default' ): string {
		return 1 === $n ? $s : $p;
	}
}
if ( ! class_exists( 'DEF_Core' ) ) {
	class DEF_Core {
		public static function get_def_api_url_internal(): string {
			return 'http://127.0.0.1:1';
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

/** A free localhost port. */
function free_port(): int {
	$s = stream_socket_server( 'tcp://127.0.0.1:0', $e, $m );
	$name = stream_socket_get_name( $s, false );
	fclose( $s );
	return (int) substr( $name, strrpos( $name, ':' ) + 1 );
}

/**
 * Run one scenario through the REAL stream_proxy() and capture what the client receives.
 *
 * Two subprocesses: a scripted upstream, and a driver that calls stream_proxy and lets its
 * output reach stdout. The driver cannot be in-process — stream_proxy starts by destroying
 * every output buffer (`while ( ob_get_level() ) ob_end_clean();`) so tokens are not held
 * back, which means an ob_start() capture silently collects nothing.
 */
function drive( string $scenario ): string {
	$port   = free_port();
	$server = __DIR__ . '/fixtures/sse-upstream-server.php';
	$driver = __DIR__ . '/fixtures/sse-proxy-driver.php';

	$srv = proc_open(
		sprintf( '%s %s %d %s', escapeshellarg( PHP_BINARY ), escapeshellarg( $server ), $port, escapeshellarg( $scenario ) ),
		array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
		$sp
	);
	if ( ! is_resource( $srv ) ) {
		return '';
	}
	// Wait for READY rather than sleeping — a race here would look like a proxy bug.
	stream_set_blocking( $sp[1], true );
	if ( 'READY' !== trim( (string) fgets( $sp[1], 64 ) ) ) {
		proc_terminate( $srv );
		return '';
	}

	$drv = proc_open(
		sprintf( '%s %s %s', escapeshellarg( PHP_BINARY ), escapeshellarg( $driver ),
			escapeshellarg( "http://127.0.0.1:$port/api/chat/stream" ) ),
		array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
		$dp
	);
	$out = is_resource( $drv ) ? (string) stream_get_contents( $dp[1] ) : '';

	foreach ( array( $dp, $sp ) as $pipes ) {
		foreach ( $pipes as $pipe ) {
			if ( is_resource( $pipe ) ) {
				fclose( $pipe );
			}
		}
	}
	if ( is_resource( $drv ) ) {
		proc_close( $drv );
	}
	proc_close( $srv );
	return $out;
}

/** Decode every `data: {...}` frame the client received. */
function frames( string $stream ): array {
	$events = array();
	foreach ( explode( "\n\n", $stream ) as $block ) {
		if ( '' === trim( $block ) ) {
			continue;
		}
		foreach ( explode( "\n", $block ) as $line ) {
			if ( 0 === strpos( $line, 'data: ' ) ) {
				$decoded = json_decode( substr( $line, 6 ), true );
				if ( is_array( $decoded ) ) {
					$events[] = $decoded;
				}
			}
		}
	}
	return $events;
}

echo "=== SSE Proxy Integration Tests (real upstream) ===\n";

// ── 1. The 2xx path relays byte-for-byte ─────────────────────────────────
echo "\n[1] A 200 stream is relayed unchanged\n";

$out = drive( 'ok' );
$expected = "data: {\"type\":\"text_delta\",\"text\":\"Hel\"}\n\n"
	. "data: {\"type\":\"error\",\"message\":\"upstream event, must relay\"}\n\n"
	. "data: {\"type\":\"done\"}\n\n";
assert_true( $out === $expected, 'the 200 body arrives byte-identical' );

$evts = frames( $out );
assert_true( 3 === count( $evts ), 'three frames reach the client (got ' . count( $evts ) . ')' );
assert_true(
	'upstream event, must relay' === ( $evts[1]['message'] ?? '' ),
	'a legitimate upstream error EVENT is relayed, not swallowed'
);

// ── 2. A 429 becomes exactly one visible frame ───────────────────────────
// Kills: delete CURLOPT_HEADERFUNCTION (status stays 0 -> body relayed).
echo "\n[2] A 429 with Retry-After becomes one visible error event\n";

$out  = drive( 'rate_limited' );
$evts = frames( $out );
assert_true( 1 === count( $evts ), 'exactly one frame (got ' . count( $evts ) . ')' );
assert_true( 'error' === ( $evts[0]['type'] ?? '' ), 'type is error' );
assert_true( 429 === ( $evts[0]['status'] ?? 0 ), 'status carried' );
assert_true( 37 === ( $evts[0]['retry_after'] ?? 0 ), 'Retry-After carried' );
assert_true( false !== strpos( (string) ( $evts[0]['message'] ?? '' ), '37' ), 'wait time reaches the visitor' );
assert_false( false !== strpos( $out, 'Too many messages' ), 'the upstream JSON document is not forwarded' );

// ── 3. A multi-chunk error body still yields ONE frame ───────────────────
// Kills: write callback returning 0 (curl would abort after chunk one).
echo "\n[3] An error body split across writes yields one frame\n";

$out  = drive( 'error_multi_chunk' );
$evts = frames( $out );
assert_true( 1 === count( $evts ), 'one frame for a three-chunk error body (got ' . count( $evts ) . ')' );
assert_true( 500 === ( $evts[0]['status'] ?? 0 ), 'status 500 reported' );
assert_false( false !== strpos( $out, 'part two' ), 'no part of the error document leaks' );

// ── 4. Non-200 with an empty body still speaks ───────────────────────────
// Kills: deleting the post-exec belt.
echo "\n[4] A non-200 with an empty body still reaches the visitor\n";

$evts = frames( drive( 'error_empty_body' ) );
assert_true( 1 === count( $evts ), 'the belt emits one frame (got ' . count( $evts ) . ')' );
assert_true( 503 === ( $evts[0]['status'] ?? 0 ), 'status 503 reported' );

// ── 5. A redirect is not silence ─────────────────────────────────────────
echo "\n[5] A 3xx is visible too — FOLLOWLOCATION is off, so it IS the response\n";

$out  = drive( 'redirect' );
$evts = frames( $out );
assert_true( 1 === count( $evts ), 'a 302 produces one frame (got ' . count( $evts ) . ')' );
assert_true( 302 === ( $evts[0]['status'] ?? 0 ), 'status 302 reported' );
assert_false( false !== strpos( $out, '<html>' ), 'the HTML body is not echoed into the stream' );

// ── 6. The last status line wins ─────────────────────────────────────────
echo "\n[6] 100-continue does not mask the real status\n";

$evts = frames( drive( 'continue_then_429' ) );
assert_true( 1 === count( $evts ), 'one frame (got ' . count( $evts ) . ')' );
assert_true( 429 === ( $evts[0]['status'] ?? 0 ), '429 wins over the 100' );

// ── 7a. A header VALUE that looks like a status line ────────────────────
// Kills: un-anchoring the status regex. Header values DO reach the header callback, so
// this is the case the `^` defends — the body-shaped one below never gets there.
echo "\n[7a] A header value shaped like a status line does not set the status\n";

$out = drive( 'status_line_in_header' );
assert_true( $out === "data: {\"type\":\"done\"}\n\n", 'the 200 stream is relayed, not turned into an error' );
$evts = frames( $out );
assert_true( 'done' === ( $evts[0]['type'] ?? '' ), 'still the upstream done event' );

// ── 7b. A body that looks like a status line is still a body ─────────────
// Kills: un-anchoring the status regex.
echo "\n[7b] A 200 whose token text looks like a status line is relayed untouched\n";

$out = drive( 'status_line_in_body' );
assert_true(
	$out === "data: {\"type\":\"text_delta\",\"text\":\"HTTP/1.1 429 Too Many Requests\"}\n\n",
	'the body is relayed and never parsed as a status line'
);
$evts = frames( $out );
assert_true( 'text_delta' === ( $evts[0]['type'] ?? '' ), 'still a text_delta, not an error' );

echo "\n--- SSE Proxy Integration Tests: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
