<?php
/**
 * Runs stream_proxy() once and lets its output go to stdout, so a caller can capture
 * exactly what a browser would receive.
 *
 * A separate process is required, not a convenience: stream_proxy() begins with
 * `while ( ob_get_level() ) { ob_end_clean(); }` — it deliberately destroys every output
 * buffer so tokens reach the client unbuffered. An in-process ob_start() capture is
 * therefore impossible by design, and trying it silently captures nothing.
 *
 * Usage: php sse-proxy-driver.php <upstream-url>           (stream_proxy, a POST)
 *        php sse-proxy-driver.php <upstream-base> resume  (the Staff AI turn resume, a GET)
 *
 * @package def-core/tests
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'DEF_CORE_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );

require_once dirname( __DIR__ ) . '/wp-stubs.php';

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return false;
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
			global $argv;
			return 'resume' === ( $argv[2] ?? '' ) ? (string) $argv[1] : 'http://127.0.0.1:1';
		}
	}
}
// Each log row goes to STDERR as one JSON line: the test reads how the stream ended
// there, and STDOUT stays exactly what the client received.
if ( ! class_exists( 'DEF_Core_Logger' ) ) {
	class DEF_Core_Logger {
		const LEVEL_INFO    = 'info';
		const LEVEL_WARNING = 'warning';
		public static function log( string $level, string $source, string $message, array $context = array() ): void {
			fwrite( STDERR, json_encode( compact( 'level', 'source', 'message', 'context' ) ) . "\n" );
		}
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-tools.php';

if ( 'resume' === ( $argv[2] ?? '' ) ) {
	// The REST callback itself (8.7.14), its upstream base being argv[1]. A finite PHP limit
	// going in, so the limit stream_proxy sets is what the caller reads back off the end of
	// STDERR.
	set_time_limit( 30 );
	DEF_Core_Tools::rest_proxy_staff_ai_turn_resume( new class() {
		public function get_param( string $key ) {
			return array( 'id' => 'staff-1a2b3c', 'turn' => '0f0e9d8c-uuid', 'after' => 3 )[ $key ] ?? null;
		}
	} );
	fwrite( STDERR, 'limit=' . ini_get( 'max_execution_time' ) );
} else {
	$method = new ReflectionMethod( 'DEF_Core_Tools', 'stream_proxy' );
	$method->setAccessible( true );
	$method->invoke( null, (string) $argv[1], array( 'Content-Type: application/json' ), '{}' );
}
