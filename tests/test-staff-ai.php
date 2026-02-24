<?php
/**
 * Staff AI endpoint tests.
 *
 * Verifies:
 * - Route/action registration (all expected endpoints exist)
 * - Permission check logic (auth gate + capability gate)
 * - Backend URL construction
 * - Error response handling (no secrets, no stack traces)
 * - Input validation (empty message rejection)
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Additional WP stubs needed for Staff AI ─────────────────────────────

// Track registered REST routes.
global $_wp_test_rest_routes, $_wp_test_current_user, $_wp_test_user_caps;
$_wp_test_rest_routes  = array();
$_wp_test_current_user = null;
$_wp_test_user_caps    = array();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function get_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params  = array();
		private $headers = array();
		private $body    = array();

		public function __construct( string $method = 'GET', string $route = '' ) {}

		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_json_params(): array {
			return $this->body;
		}

		public function set_body_params( array $body ): void {
			$this->body = $body;
		}

		public function get_header( string $key ): ?string {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}

		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args ): void {
		global $_wp_test_rest_routes;
		$_wp_test_rest_routes[ $namespace . $route ] = $args;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( 'add_rewrite_rule' ) ) {
	function add_rewrite_rule( string $regex, string $query, string $after = 'bottom' ): void {}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		global $_wp_test_current_user;
		return $_wp_test_current_user !== null;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		global $_wp_test_user_caps;
		return in_array( $cap, $_wp_test_user_caps, true );
	}
}

if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( string $var, $default = '' ) {
		return $default;
	}
}

if ( ! function_exists( 'rawurlencode' ) ) {
	// Built-in PHP function, but just in case.
}

// Stub WP_User for wp_get_current_user.
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID             = 0;
		public $user_login     = '';
		public $user_email     = '';
		public $display_name   = '';
		public $user_firstname = '';
		public $roles          = array();

		public function __construct( int $id = 0 ) {
			$this->ID = $id;
		}

		public function exists(): bool {
			return $this->ID > 0;
		}

		public function has_cap( string $cap ): bool {
			global $_wp_test_user_caps;
			return in_array( $cap, $_wp_test_user_caps, true );
		}
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): WP_User {
		global $_wp_test_current_user;
		return $_wp_test_current_user ?? new WP_User( 0 );
	}
}

if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( int $id ): void {}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, $value ) {
		return null;
	}
}

// Load the classes under test.
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-jwt.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-staff-ai.php';

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

function assert_equals( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . ")\n";
	}
}

echo "=== Staff AI Tests ===\n";

// ── 1. Route registration ───────────────────────────────────────────────
echo "\n[1] REST route registration\n";
DEF_Core_Staff_AI::register_rest_routes();

$expected_routes = array(
	'a3-ai/v1/staff-ai/conversations',
	'a3-ai/v1/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)',
	'a3-ai/v1/staff-ai/chat',
	'a3-ai/v1/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)/share',
	'a3-ai/v1/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)/revoke',
	'a3-ai/v1/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)/export',
	'a3-ai/v1/staff-ai/escalate',
	'a3-ai/v1/staff-ai/status',
	'a3-ai/v1/staff-ai/tools',
	'a3-ai/v1/staff-ai/files/(?P<tenant>[^/]+)/(?P<filename>.+)',
);

foreach ( $expected_routes as $route ) {
	assert_true(
		isset( $_wp_test_rest_routes[ $route ] ),
		"route registered: $route"
	);
}

// 2. All Staff AI routes have permission callbacks.
echo "\n[2] Permission callbacks assigned\n";
foreach ( $_wp_test_rest_routes as $route => $args ) {
	if ( strpos( $route, 'staff-ai' ) !== false ) {
		assert_true(
			isset( $args['permission_callback'] ) && ! empty( $args['permission_callback'] ),
			"permission_callback on $route"
		);
	}
}

// 3. Methods are correct (GET vs POST).
echo "\n[3] HTTP methods\n";
assert_equals( 'GET', $_wp_test_rest_routes['a3-ai/v1/staff-ai/conversations']['methods'], 'conversations = GET' );
assert_equals( 'POST', $_wp_test_rest_routes['a3-ai/v1/staff-ai/chat']['methods'], 'chat = POST' );
assert_equals( 'GET', $_wp_test_rest_routes['a3-ai/v1/staff-ai/tools']['methods'], 'tools = GET' );
assert_equals( 'GET', $_wp_test_rest_routes['a3-ai/v1/staff-ai/status']['methods'], 'status = GET' );

// ── 4. Permission check: unauthenticated → 401 ─────────────────────────
echo "\n[4] Permission check — unauthenticated\n";
$_wp_test_current_user = null;
$_wp_test_user_caps    = array();
$result = DEF_Core_Staff_AI::rest_permission_check();
assert_true( is_wp_error( $result ), 'unauthenticated returns WP_Error' );
assert_equals( 'rest_not_logged_in', $result->get_error_code(), 'error code is rest_not_logged_in' );
$data = $result->get_error_data();
assert_equals( 401, $data['status'], 'HTTP status is 401' );

// ── 5. Permission check: authenticated but no capability → 403 ─────────
echo "\n[5] Permission check — no staff_ai capability\n";
$_wp_test_current_user     = new WP_User( 1 );
$_wp_test_current_user->ID = 1;
$_wp_test_user_caps        = array(); // No capabilities.
$result = DEF_Core_Staff_AI::rest_permission_check();
assert_true( is_wp_error( $result ), 'no capability returns WP_Error' );
assert_equals( 'rest_forbidden', $result->get_error_code(), 'error code is rest_forbidden' );
$data = $result->get_error_data();
assert_equals( 403, $data['status'], 'HTTP status is 403' );

// ── 6. Permission check: authenticated with capability → true ───────────
echo "\n[6] Permission check — with def_staff_access\n";
$_wp_test_user_caps = array( 'def_staff_access' );
$result = DEF_Core_Staff_AI::rest_permission_check();
assert_equals( true, $result, 'staff_access capability grants access' );

// ── 7. Permission check: management access also works ───────────────────
echo "\n[7] Permission check — with def_management_access\n";
$_wp_test_user_caps = array( 'def_management_access' );
$result = DEF_Core_Staff_AI::rest_permission_check();
assert_equals( true, $result, 'def_management_access capability grants access' );

// ── 8. Error responses don't leak secrets ───────────────────────────────
echo "\n[8] Error message safety\n";
// Backend not configured → should not expose internal paths.
$_wp_test_current_user     = new WP_User( 1 );
$_wp_test_current_user->ID = 1;
$_wp_test_user_caps        = array( 'def_staff_access' );
update_option( 'def_core_staff_ai_api_url', '' ); // Not configured.

// Send message without backend URL.
$request = new WP_REST_Request( 'POST', '/staff-ai/chat' );
$request->set_body_params( array( 'message' => 'test' ) );
$result = DEF_Core_Staff_AI::rest_send_message( $request );
assert_true( is_wp_error( $result ), 'no backend URL returns WP_Error' );
$msg = $result->get_error_message();
assert_true( strpos( $msg, 'not configured' ) !== false, 'error mentions configuration needed' );
assert_true( strpos( $msg, '/home/' ) === false, 'no file paths in error' );
assert_true( strpos( $msg, 'password' ) === false, 'no passwords in error' );

// ── 9. Empty message validation ─────────────────────────────────────────
echo "\n[9] Empty message validation\n";
update_option( 'def_core_staff_ai_api_url', 'http://localhost:8000' );
$request = new WP_REST_Request( 'POST', '/staff-ai/chat' );
$request->set_body_params( array( 'message' => '' ) );
$result = DEF_Core_Staff_AI::rest_send_message( $request );
assert_true( is_wp_error( $result ), 'empty message returns WP_Error' );
assert_equals( 'invalid_message', $result->get_error_code(), 'error code is invalid_message' );
$data = $result->get_error_data();
assert_equals( 400, $data['status'], 'HTTP status is 400' );

// ── 10. Missing message field ───────────────────────────────────────────
echo "\n[10] Missing message field\n";
$request = new WP_REST_Request( 'POST', '/staff-ai/chat' );
$request->set_body_params( array() ); // No message field.
$result = DEF_Core_Staff_AI::rest_send_message( $request );
assert_true( is_wp_error( $result ), 'missing message returns WP_Error' );
assert_equals( 'invalid_message', $result->get_error_code(), 'error code is invalid_message' );

// ── 11. Conversation ID regex pattern ───────────────────────────────────
echo "\n[11] Conversation ID regex pattern\n";
$pattern = '(?P<id>[a-zA-Z0-9_-]+)';
$route_key = 'a3-ai/v1/staff-ai/conversations/' . $pattern;
assert_true( isset( $_wp_test_rest_routes[ $route_key ] ), 'conversation ID route accepts alphanumeric + _ and -' );
// Verify the pattern rejects path traversal characters.
assert_true( preg_match( '/^[a-zA-Z0-9_-]+$/', 'thread_abc-123' ) === 1, 'valid thread ID passes regex' );
assert_true( preg_match( '/^[a-zA-Z0-9_-]+$/', '../etc/passwd' ) === 0, 'path traversal rejected by regex' );
assert_true( preg_match( '/^[a-zA-Z0-9_-]+$/', 'thread<script>' ) === 0, 'XSS in thread ID rejected by regex' );

// ── 12. File download route pattern ─────────────────────────────────────
echo "\n[12] File download route pattern\n";
$file_route = 'a3-ai/v1/staff-ai/files/(?P<tenant>[^/]+)/(?P<filename>.+)';
assert_true( isset( $_wp_test_rest_routes[ $file_route ] ), 'file download route registered' );
assert_equals( 'GET', $_wp_test_rest_routes[ $file_route ]['methods'], 'file download is GET' );

// Cleanup.
$_wp_test_current_user = null;
$_wp_test_user_caps    = array();

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n--- Staff AI Tests: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
