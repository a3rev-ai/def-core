<?php
/**
 * Staff-AI download proxy auth tests.
 *
 * Pins the 5.6.3 fix: handle_file_download() must authenticate to DEF with
 * the BFF header set (X-DEF-API-Key + X-DEF-User + X-DEF-User-Capabilities)
 * and NEVER the retired JWT (Authorization: Bearer) — the JWT was rejected
 * with 401 by DEF's staff routes ever since the BFF migration, which made
 * every Download button click die as "File not found or access denied".
 * Also pins that the WordPress-side gates (login, staff capability) run
 * BEFORE any backend call is made.
 *
 * Runs standalone (no WordPress bootstrap), same harness pattern as
 * test-bridge-contract.php.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Controllable state ───────────────────────────────────────────────────

global $_wp_test_current_user, $_wp_test_user_caps, $_wp_test_query_vars;
global $_wp_test_remote_responses, $_wp_test_remote_calls;
$_wp_test_current_user     = null;
$_wp_test_user_caps        = array();
$_wp_test_query_vars       = array();
$_wp_test_remote_responses = array();
$_wp_test_remote_calls     = array();

// ── Stubs ────────────────────────────────────────────────────────────────

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID = 0;
		public $user_email = 'test@example.com';
		public $display_name = 'Test User';
		public $roles = array( 'editor' );
		public function __construct( int $id = 0 ) { $this->ID = $id; }
		public function exists(): bool { return $this->ID > 0; }
		public function has_cap( string $cap ): bool {
			global $_wp_test_user_caps;
			return in_array( $cap, $_wp_test_user_caps, true );
		}
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		global $_wp_test_current_user;
		return $_wp_test_current_user !== null && $_wp_test_current_user->ID > 0;
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): WP_User {
		global $_wp_test_current_user;
		return $_wp_test_current_user ?? new WP_User( 0 );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		global $_wp_test_user_caps;
		return in_array( $cap, $_wp_test_user_caps, true );
	}
}
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( string $v, $d = '' ) {
		global $_wp_test_query_vars;
		return $_wp_test_query_vars[ $v ] ?? $d;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $t, string $d = 'default' ): string { return $t; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $t ): string { return $t; }
}
if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$a ): void {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$a ): void {}
}
if ( ! function_exists( 'add_rewrite_rule' ) ) {
	function add_rewrite_rule( ...$a ): void {}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( ...$a ): void {}
}

// wp_die must HALT the function under test without killing the process.
if ( ! class_exists( 'WPDieException' ) ) {
	class WPDieException extends Exception {
		public $status;
		public function __construct( string $message, int $status ) {
			parent::__construct( $message );
			$this->status = $status;
		}
	}
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ): void {
		$status = is_array( $args ) && isset( $args['response'] ) ? intval( $args['response'] ) : 500;
		throw new WPDieException( is_string( $message ) ? $message : 'wp_die', $status );
	}
}

// Capture outbound calls; default to WP_Error so the function halts in the
// error branch (via wp_die above) — headers are captured BEFORE that.
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		global $_wp_test_remote_calls, $_wp_test_remote_responses;
		$_wp_test_remote_calls[] = array( 'url' => $url, 'args' => $args );
		if ( ! empty( $_wp_test_remote_responses ) ) {
			return array_shift( $_wp_test_remote_responses );
		}
		return new WP_Error( 'http_request_failed', 'Connection timed out' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, string $header ): string {
		return is_array( $response ) ? (string) ( $response['headers'][ $header ] ?? '' ) : '';
	}
}

// Backend URL resolver (same shape as test-bridge-contract.php).
if ( ! class_exists( 'DEF_Core' ) ) {
	class DEF_Core {
		public static function get_def_api_url_internal(): ?string {
			$url = get_option( 'def_core_staff_ai_api_url', '' );
			return ! empty( $url ) ? rtrim( $url, '/' ) : null;
		}
	}
}

// Capability derivation is pinned by test-proxy-identity-headers.php against
// the real class; here it is stubbed so this file pins ONLY the header
// contract and gate order of the download proxy.
if ( ! class_exists( 'DEF_Core_Tools' ) ) {
	class DEF_Core_Tools {
		public static function get_user_def_capabilities( $user ): array {
			global $_wp_test_user_caps;
			return $_wp_test_user_caps;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-def-core-staff-ai.php';

update_option( 'def_core_staff_ai_api_url', 'http://backend:8000' );
update_option( 'def_core_api_key', 'test-api-key-download' );

// ── Helpers ──────────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function check( bool $cond, string $label ): void {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  ✓ $label\n";
	} else {
		$fail++;
		echo "  ✗ $label\n";
	}
}

function invoke_download(): ?WPDieException {
	try {
		$m = new ReflectionMethod( 'DEF_Core_Staff_AI', 'handle_file_download' );
		$m->setAccessible( true );
		$m->invoke( null );
	} catch ( WPDieException $e ) {
		return $e;
	}
	return null;
}

function reset_state( bool $logged_in, array $caps ): void {
	global $_wp_test_current_user, $_wp_test_user_caps, $_wp_test_query_vars, $_wp_test_remote_calls;
	$_wp_test_current_user = $logged_in ? new WP_User( 7 ) : null;
	$_wp_test_user_caps    = $caps;
	$_wp_test_query_vars   = array(
		'staff_ai_tenant'   => 'tenant-uuid-1',
		'staff_ai_filename' => '20260804_090816_ab_report.md',
	);
	$_wp_test_remote_calls = array();
}

// ── Tests ────────────────────────────────────────────────────────────────

echo "Staff-AI download proxy auth\n";

// 1. Authenticated staff request sends the BFF header set — and no JWT.
reset_state( true, array( 'def_staff_access' ) );
$e = invoke_download(); // Backend errors (stub default) → wp_die AFTER the call.
check( count( $GLOBALS['_wp_test_remote_calls'] ) === 1, 'exactly one backend call made' );
$headers = $GLOBALS['_wp_test_remote_calls'][0]['args']['headers'] ?? array();
check( ( $headers['X-DEF-API-Key'] ?? '' ) === 'test-api-key-download', 'X-DEF-API-Key rides the request' );
check( ( $headers['X-DEF-User'] ?? '' ) === '7', 'X-DEF-User is the WP user ID' );
check( isset( $headers['X-DEF-User-Capabilities'] ) && strpos( $headers['X-DEF-User-Capabilities'], 'def_staff_access' ) !== false, 'capabilities header present' );
check( ! isset( $headers['Authorization'] ), 'NO Authorization/JWT header (the retired auth that 401d every download)' );
check( strpos( $GLOBALS['_wp_test_remote_calls'][0]['url'], 'http://backend:8000/api/files/' ) === 0, 'URL targets /api/files/ on the backend' );

// 2. Anonymous request dies 401 BEFORE any backend call.
reset_state( false, array() );
$e = invoke_download();
check( $e !== null && $e->status === 401, 'anonymous → 401' );
check( count( $GLOBALS['_wp_test_remote_calls'] ) === 0, 'no backend call for anonymous' );

// 3. Logged-in but no staff capability dies 403 BEFORE any backend call.
reset_state( true, array() );
$e = invoke_download();
check( $e !== null && $e->status === 403, 'no staff capability → 403' );
check( count( $GLOBALS['_wp_test_remote_calls'] ) === 0, 'no backend call without capability' );

// 4. Backend non-200 surfaces the proxy error, never the API key.
reset_state( true, array( 'def_staff_access' ) );
$GLOBALS['_wp_test_remote_responses'][] = array(
	'response' => array( 'code' => 401 ),
	'body'     => 'Authentication required for Staff AI',
);
$e = invoke_download();
check( $e !== null && strpos( $e->getMessage(), 'test-api-key-download' ) === false, 'API key never appears in the error surface' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
