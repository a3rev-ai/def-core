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
 * Usage: php sse-proxy-driver.php <upstream-url>
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
			return 'http://127.0.0.1:1';
		}
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-tools.php';

$method = new ReflectionMethod( 'DEF_Core_Tools', 'stream_proxy' );
$method->setAccessible( true );
$method->invoke( null, (string) $argv[1], array( 'Content-Type: application/json' ), '{}' );
