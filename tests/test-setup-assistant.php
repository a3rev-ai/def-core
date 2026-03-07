<?php
/**
 * Setup Assistant endpoint tests.
 *
 * Verifies:
 * - Route registration (all 12 endpoints)
 * - Dual authentication (Mode A nonce, Mode B HMAC)
 * - Mixed-mode auth rejection
 * - HMAC validation (valid, expired, wrong sig, body hash mismatch, missing headers, invalid user)
 * - Setting GET/POST (valid key, unknown key, secret redaction, validation)
 * - Status endpoint (checkpoints, completion %, next action)
 * - User role add/remove/lockout prevention
 * - Chat proxy (forward + not-configured error)
 * - Connection test
 * - Thread CRUD
 * - Rate limiting
 * - Audit log (entry creation, FIFO rotation, redaction)
 * - Seen flag (GET/POST, first-visit detection)
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Additional WP stubs needed for Setup Assistant ──────────────────────

global $_wp_test_rest_routes, $_wp_test_current_user, $_wp_test_user_caps;
global $_wp_test_remote_calls, $_wp_test_remote_responses;
global $_wp_test_user_meta, $_wp_test_users, $_wp_test_transients;

$_wp_test_rest_routes      = array();
$_wp_test_current_user     = null;
$_wp_test_user_caps        = array();
$_wp_test_remote_calls     = array();
$_wp_test_remote_responses = array();
$_wp_test_user_meta        = array();
$_wp_test_users            = array();

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
		public $headers = array();

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

		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}

		public function get_headers(): array {
			return $this->headers;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params  = array();
		private $headers = array();
		private $body    = array();
		private $method  = 'GET';
		private $route   = '';

		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

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

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args ): void {
		global $_wp_test_rest_routes;
		$key = $namespace . $route;
		// Support multiple methods on same route.
		// Handle nested array format (array of arrays for multi-method registration).
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			foreach ( $args as $sub_args ) {
				$method = $sub_args['methods'] ?? 'GET';
				$_wp_test_rest_routes[ $key . '::' . $method ] = $sub_args;
			}
		} else {
			$method = $args['methods'] ?? 'GET';
			$_wp_test_rest_routes[ $key . '::' . $method ] = $args;
		}
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
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

// Stub WP_User with add_cap/remove_cap support.
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID             = 0;
		public $user_login     = '';
		public $user_email     = '';
		public $display_name   = '';
		public $roles          = array();
		public $caps           = array();

		public function __construct( int $id = 0 ) {
			$this->ID = $id;
		}

		public function exists(): bool {
			return $this->ID > 0;
		}

		public function has_cap( string $cap ): bool {
			return ! empty( $this->caps[ $cap ] );
		}

		public function add_cap( string $cap ): void {
			$this->caps[ $cap ] = true;
		}

		public function remove_cap( string $cap ): void {
			unset( $this->caps[ $cap ] );
		}
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): WP_User {
		global $_wp_test_current_user;
		return $_wp_test_current_user ?? new WP_User( 0 );
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, $value ) {
		global $_wp_test_users;
		if ( $field === 'id' ) {
			return $_wp_test_users[ intval( $value ) ] ?? null;
		}
		return null;
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ) {
		global $_wp_test_users;
		$cap    = $args['capability'] ?? '';
		$fields = $args['fields'] ?? '';
		$exclude = $args['exclude'] ?? array();
		$result = array();

		foreach ( $_wp_test_users as $user ) {
			if ( in_array( $user->ID, $exclude, true ) ) {
				continue;
			}
			if ( ! empty( $cap ) && ! $user->has_cap( $cap ) ) {
				continue;
			}
			if ( $fields === 'ids' ) {
				$result[] = $user->ID;
			} else {
				$result[] = $user;
			}
		}
		return $result;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) {
		global $_wp_test_users;
		return $_wp_test_users[ $user_id ] ?? null;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action = '' ) {
		return $nonce === 'valid_nonce' ? 1 : false;
	}
}

// User meta stubs.
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( int $user_id, string $key = '', bool $single = false ) {
		global $_wp_test_user_meta;
		$meta_key = $user_id . '::' . $key;
		if ( ! isset( $_wp_test_user_meta[ $meta_key ] ) ) {
			return $single ? '' : array();
		}
		return $single ? $_wp_test_user_meta[ $meta_key ] : array( $_wp_test_user_meta[ $meta_key ] );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( int $user_id, string $key, $value ): bool {
		global $_wp_test_user_meta;
		$_wp_test_user_meta[ $user_id . '::' . $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( int $user_id, string $key ): bool {
		global $_wp_test_user_meta;
		unset( $_wp_test_user_meta[ $user_id . '::' . $key ] );
		return true;
	}
}

// HTTP stubs.
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		global $_wp_test_remote_calls, $_wp_test_remote_responses;
		$_wp_test_remote_calls[] = array( 'method' => 'POST', 'url' => $url, 'args' => $args );
		if ( ! empty( $_wp_test_remote_responses ) ) {
			return array_shift( $_wp_test_remote_responses );
		}
		return new WP_Error( 'http_request_failed', 'Connection timed out' );
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		global $_wp_test_remote_calls, $_wp_test_remote_responses;
		$_wp_test_remote_calls[] = array( 'method' => 'GET', 'url' => $url, 'args' => $args );
		if ( ! empty( $_wp_test_remote_responses ) ) {
			return array_shift( $_wp_test_remote_responses );
		}
		return new WP_Error( 'http_request_failed', 'Connection timed out' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	function wp_attachment_is_image( int $id ): bool {
		// For tests: IDs 1-10 are valid images, others are not.
		return $id >= 1 && $id <= 10;
	}
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

// Load the class under test.
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-setup-assistant.php';

// ── Test helpers ────────────────────────────────────────────────────────

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
	global $pass, $fail;
	if ( ! $value ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected false, got truthy)\n";
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

function assert_not_null( $value, string $label ): void {
	global $pass, $fail;
	if ( $value !== null ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected non-null)\n";
	}
}

function assert_null( $value, string $label ): void {
	global $pass, $fail;
	if ( $value === null ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected null, got " . var_export( $value, true ) . ")\n";
	}
}

/**
 * Reset all global test state between test sections.
 */
function reset_test_state(): void {
	global $_wp_test_options, $_wp_test_transients, $_wp_test_rest_routes;
	global $_wp_test_current_user, $_wp_test_user_caps;
	global $_wp_test_remote_calls, $_wp_test_remote_responses;
	global $_wp_test_user_meta, $_wp_test_users;

	$_wp_test_options          = array();
	$_wp_test_transients       = array();
	$_wp_test_current_user     = null;
	$_wp_test_user_caps        = array();
	$_wp_test_remote_calls     = array();
	$_wp_test_remote_responses = array();
	$_wp_test_user_meta        = array();
	$_wp_test_users            = array();

	// Clear $_SERVER HMAC headers.
	unset(
		$_SERVER['HTTP_X_WP_NONCE'],
		$_SERVER['HTTP_X_DEF_SIGNATURE'],
		$_SERVER['HTTP_X_DEF_TIMESTAMP'],
		$_SERVER['HTTP_X_DEF_USER'],
		$_SERVER['HTTP_X_DEF_BODY_HASH']
	);
}

/**
 * Set up a test admin user (Mode A auth context).
 */
function setup_admin_user( int $user_id = 1 ): WP_User {
	global $_wp_test_current_user, $_wp_test_user_caps, $_wp_test_users;

	$user                = new WP_User( $user_id );
	$user->display_name  = 'Test Admin';
	$user->user_email    = 'admin@test.com';
	$user->caps          = array( 'def_admin_access' => true );

	$_wp_test_current_user = $user;
	$_wp_test_user_caps    = array( 'def_admin_access' );
	$_wp_test_users[ $user_id ] = $user;

	// Set valid nonce for Mode A.
	$_SERVER['HTTP_X_WP_NONCE'] = 'valid_nonce';

	return $user;
}

echo "=== Setup Assistant Tests ===\n";

$sa = new DEF_Core_Setup_Assistant();

// ── 1. Route registration ───────────────────────────────────────────────
echo "\n[1] REST route registration\n";
reset_test_state();
$_wp_test_rest_routes = array();
$sa->register_rest_routes();

$expected_routes = array(
	'def-core/v1/setup/status::GET',
	'def-core/v1/setup/setting/(?P<key>[a-z_]+)::GET',
	'def-core/v1/setup/setting/(?P<key>[a-z_]+)::POST',
	'def-core/v1/setup/test-connection::GET',
	'def-core/v1/setup/users::GET',
	'def-core/v1/setup/user-role::POST',
	'def-core/v1/setup/chat::POST',
	'def-core/v1/setup/thread::GET',
	'def-core/v1/setup/thread::POST',
	'def-core/v1/setup/thread::DELETE',
	'def-core/v1/setup/seen::GET',
	'def-core/v1/setup/seen::POST',
);

foreach ( $expected_routes as $route ) {
	assert_true(
		isset( $_wp_test_rest_routes[ $route ] ),
		"route registered: $route"
	);
}

// All routes have permission callbacks.
echo "\n[2] Permission callbacks assigned\n";
foreach ( $_wp_test_rest_routes as $route => $args ) {
	assert_true(
		isset( $args['permission_callback'] ) && ! empty( $args['permission_callback'] ),
		"permission_callback on $route"
	);
}

// ── 3. Mode A: nonce auth ───────────────────────────────────────────────
echo "\n[3] Mode A: nonce auth — valid\n";
reset_test_state();
setup_admin_user();
$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/status' );
$result  = $sa->permission_check( $request );
assert_true( $result === true, 'valid nonce + admin returns true' );

// ── 4. Mode A: invalid nonce ────────────────────────────────────────────
echo "\n[4] Mode A: invalid nonce\n";
reset_test_state();
$_SERVER['HTTP_X_WP_NONCE'] = 'bad_nonce';
$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/status' );
$result  = $sa->permission_check( $request );
assert_true( is_wp_error( $result ), 'invalid nonce returns WP_Error' );
assert_equals( 'INVALID_NONCE', $result->get_error_code(), 'error code is INVALID_NONCE' );

// ── 5. Mode A: no def_admin_access ──────────────────────────────────────
echo "\n[5] Mode A: no def_admin_access\n";
reset_test_state();
$_wp_test_current_user = new WP_User( 1 );
$_wp_test_user_caps    = array(); // No capabilities.
$_SERVER['HTTP_X_WP_NONCE'] = 'valid_nonce';
$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/status' );
$result  = $sa->permission_check( $request );
assert_true( is_wp_error( $result ), 'no capability returns WP_Error' );
assert_equals( 'FORBIDDEN', $result->get_error_code(), 'error code is FORBIDDEN' );

// ── 6. No auth headers → 401 ───────────────────────────────────────────
echo "\n[6] No auth headers\n";
reset_test_state();
$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/status' );
$result  = $sa->permission_check( $request );
assert_true( is_wp_error( $result ), 'no auth returns WP_Error' );
assert_equals( 'UNAUTHORIZED', $result->get_error_code(), 'error code is UNAUTHORIZED' );

// ── 7. Mixed-mode auth rejection ────────────────────────────────────────
echo "\n[7] Mixed-mode auth rejection\n";
reset_test_state();
$_SERVER['HTTP_X_WP_NONCE']     = 'valid_nonce';
$_SERVER['HTTP_X_DEF_SIGNATURE'] = 'some_sig';
$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/status' );
$result  = $sa->permission_check( $request );
assert_true( is_wp_error( $result ), 'mixed mode returns WP_Error' );
assert_equals( 'INVALID_AUTH_MODE', $result->get_error_code(), 'error code is INVALID_AUTH_MODE' );
$data = $result->get_error_data();
assert_equals( 400, $data['status'], 'HTTP status is 400' );

// ── 8. HMAC auth: valid ─────────────────────────────────────────────────
echo "\n[8] HMAC auth: valid signature\n";
reset_test_state();
$api_key = 'test_api_key_12345';
update_option( 'def_core_api_key', $api_key );

$admin       = new WP_User( 42 );
$admin->display_name = 'HMAC Admin';
$admin->user_email   = 'hmac@test.com';
$admin->caps         = array( 'def_admin_access' => true );
$_wp_test_users[42]  = $admin;

$method    = 'GET';
$path      = '/def-core/v1/setup/status';
$timestamp = (string) time();
$user_id   = '42';
$body_hash = hash( 'sha256', '' );
$payload   = "{$method}:{$path}:{$timestamp}:{$user_id}:{$body_hash}";
$signature = hash_hmac( 'sha256', $payload, $api_key );

$_SERVER['HTTP_X_DEF_SIGNATURE'] = $signature;
$_SERVER['HTTP_X_DEF_TIMESTAMP'] = $timestamp;
$_SERVER['HTTP_X_DEF_USER']      = $user_id;
$_SERVER['HTTP_X_DEF_BODY_HASH'] = $body_hash;

$request = new WP_REST_Request( $method, $path );
$result  = $sa->permission_check( $request );
assert_true( $result === true, 'valid HMAC returns true' );

// ── 9. HMAC auth: expired timestamp ─────────────────────────────────────
echo "\n[9] HMAC auth: expired timestamp\n";
reset_test_state();
update_option( 'def_core_api_key', 'test_key' );
$admin = new WP_User( 42 );
$admin->caps = array( 'def_admin_access' => true );
$_wp_test_users[42] = $admin;

$old_ts    = (string) ( time() - 600 ); // 10 minutes ago.
$body_hash = hash( 'sha256', '' );
$payload   = "GET:/def-core/v1/setup/status:{$old_ts}:42:{$body_hash}";
$signature = hash_hmac( 'sha256', $payload, 'test_key' );

$_SERVER['HTTP_X_DEF_SIGNATURE'] = $signature;
$_SERVER['HTTP_X_DEF_TIMESTAMP'] = $old_ts;
$_SERVER['HTTP_X_DEF_USER']      = '42';
$_SERVER['HTTP_X_DEF_BODY_HASH'] = $body_hash;

$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/status' );
$result  = $sa->permission_check( $request );
assert_true( is_wp_error( $result ), 'expired HMAC returns WP_Error' );
assert_equals( 'HMAC_EXPIRED', $result->get_error_code(), 'error code is HMAC_EXPIRED' );

// ── 10. HMAC auth: wrong signature ──────────────────────────────────────
echo "\n[10] HMAC auth: wrong signature\n";
reset_test_state();
update_option( 'def_core_api_key', 'test_key' );
$admin = new WP_User( 42 );
$admin->caps = array( 'def_admin_access' => true );
$_wp_test_users[42] = $admin;

$_SERVER['HTTP_X_DEF_SIGNATURE'] = 'totally_wrong_signature';
$_SERVER['HTTP_X_DEF_TIMESTAMP'] = (string) time();
$_SERVER['HTTP_X_DEF_USER']      = '42';
$_SERVER['HTTP_X_DEF_BODY_HASH'] = hash( 'sha256', '' );

$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/status' );
$result  = $sa->permission_check( $request );
assert_true( is_wp_error( $result ), 'wrong sig returns WP_Error' );
assert_equals( 'HMAC_INVALID_SIGNATURE', $result->get_error_code(), 'error code is HMAC_INVALID_SIGNATURE' );

// ── 11. HMAC auth: missing headers ──────────────────────────────────────
echo "\n[11] HMAC auth: missing headers\n";
reset_test_state();
$_SERVER['HTTP_X_DEF_SIGNATURE'] = 'some_sig';
// Missing timestamp, user, body_hash.
$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/status' );
$result  = $sa->permission_check( $request );
assert_true( is_wp_error( $result ), 'missing headers returns WP_Error' );
assert_equals( 'HMAC_MISSING_HEADERS', $result->get_error_code(), 'error code is HMAC_MISSING_HEADERS' );

// ── 12. HMAC auth: invalid user ─────────────────────────────────────────
echo "\n[12] HMAC auth: invalid user\n";
reset_test_state();
$api_key = 'test_key';
update_option( 'def_core_api_key', $api_key );
// No users registered.

$method    = 'GET';
$path      = '/def-core/v1/setup/status';
$timestamp = (string) time();
$user_id   = '999';
$body_hash = hash( 'sha256', '' );
$payload   = "{$method}:{$path}:{$timestamp}:{$user_id}:{$body_hash}";
$signature = hash_hmac( 'sha256', $payload, $api_key );

$_SERVER['HTTP_X_DEF_SIGNATURE'] = $signature;
$_SERVER['HTTP_X_DEF_TIMESTAMP'] = $timestamp;
$_SERVER['HTTP_X_DEF_USER']      = $user_id;
$_SERVER['HTTP_X_DEF_BODY_HASH'] = $body_hash;

$request = new WP_REST_Request( $method, $path );
$result  = $sa->permission_check( $request );
assert_true( is_wp_error( $result ), 'invalid user returns WP_Error' );
assert_equals( 'HMAC_INVALID_USER', $result->get_error_code(), 'error code is HMAC_INVALID_USER' );

// ── 13. HMAC auth: user without def_admin_access ────────────────────────
echo "\n[13] HMAC auth: user without def_admin_access\n";
reset_test_state();
$api_key = 'test_key';
update_option( 'def_core_api_key', $api_key );

$non_admin = new WP_User( 50 );
$non_admin->caps = array(); // No admin capability.
$_wp_test_users[50] = $non_admin;

$method    = 'GET';
$path      = '/def-core/v1/setup/status';
$timestamp = (string) time();
$user_id   = '50';
$body_hash = hash( 'sha256', '' );
$payload   = "{$method}:{$path}:{$timestamp}:{$user_id}:{$body_hash}";
$signature = hash_hmac( 'sha256', $payload, $api_key );

$_SERVER['HTTP_X_DEF_SIGNATURE'] = $signature;
$_SERVER['HTTP_X_DEF_TIMESTAMP'] = $timestamp;
$_SERVER['HTTP_X_DEF_USER']      = $user_id;
$_SERVER['HTTP_X_DEF_BODY_HASH'] = $body_hash;

$request = new WP_REST_Request( $method, $path );
$result  = $sa->permission_check( $request );
assert_true( is_wp_error( $result ), 'user without admin returns WP_Error' );
assert_equals( 'FORBIDDEN', $result->get_error_code(), 'error code is FORBIDDEN' );

// ── 14. Chat proxy: browser-only permission ─────────────────────────────
echo "\n[14] Chat proxy: HMAC rejected\n";
reset_test_state();
$_SERVER['HTTP_X_DEF_SIGNATURE'] = 'some_sig';
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/chat' );
$result  = $sa->permission_check_browser_only( $request );
assert_true( is_wp_error( $result ), 'HMAC on chat returns WP_Error' );
assert_equals( 'HMAC_NOT_SUPPORTED', $result->get_error_code(), 'error code is HMAC_NOT_SUPPORTED' );

// ── 15. GET /setup/status — all checkpoints ─────────────────────────────
echo "\n[15] GET /setup/status — empty config (0%)\n";
reset_test_state();
setup_admin_user();

$request  = new WP_REST_Request( 'GET', '/def-core/v1/setup/status' );
$response = $sa->rest_get_status( $request );
$data     = $response->get_data();

assert_equals( 200, $response->get_status(), 'status returns 200' );
assert_true( $data['success'], 'success is true' );
assert_true( isset( $data['data']['checkpoints'] ), 'checkpoints exist' );
assert_true( isset( $data['data']['completion_percentage'] ), 'completion_percentage exists' );
assert_equals( 8, count( $data['data']['checkpoints'] ), 'exactly 8 checkpoints' );
assert_equals( 0.0, $data['data']['completion_percentage'], 'completion is 0% with no config' );
assert_not_null( $data['data']['next_recommended_action'], 'next_recommended_action is set' );

// ── 16. GET /setup/status — 100% completion ─────────────────────────────
echo "\n[16] GET /setup/status — full config (100%)\n";
reset_test_state();
setup_admin_user();

// Set up all passing checkpoints.
update_option( 'def_core_staff_ai_api_url', 'https://api.example.com' );
update_option( 'def_core_api_key', 'key123' );
set_transient( 'def_core_connection_test', array( 'status' => 'ok' ), 300 );
update_option( 'def_core_escalation_customer', 'test@example.com' );
update_option( 'def_core_display_name', 'My Bot' );
// Staff user.
$staff = new WP_User( 10 );
$staff->caps = array( 'def_staff_access' => true );
$_wp_test_users[10] = $staff;
// Management user.
$mgmt = new WP_User( 11 );
$mgmt->caps = array( 'def_management_access' => true );
$_wp_test_users[11] = $mgmt;
// Tools — we don't have DEF_Core_API_Registry in test, so checkpoint 8 stays false.
// Instead, set all 7 that we CAN control.

$request  = new WP_REST_Request( 'GET', '/def-core/v1/setup/status' );
$response = $sa->rest_get_status( $request );
$data     = $response->get_data();

$cp = $data['data']['checkpoints'];
assert_true( $cp['api_url']['passed'], 'api_url checkpoint passed' );
assert_true( $cp['api_key']['passed'], 'api_key checkpoint passed' );
assert_true( $cp['connection']['passed'], 'connection checkpoint passed' );
assert_true( $cp['escalation']['passed'], 'escalation checkpoint passed' );
assert_true( $cp['branding']['passed'], 'branding checkpoint passed' );
assert_true( $cp['staff_user']['passed'], 'staff_user checkpoint passed' );
assert_true( $cp['management_user']['passed'], 'management_user checkpoint passed' );
// tools checkpoint depends on DEF_Core_API_Registry (not loaded in test).
assert_equals( 87.5, $data['data']['completion_percentage'], '7/8 checkpoints = 87.5%' );

// ── 17. GET /setup/setting — valid key ──────────────────────────────────
echo "\n[17] GET /setup/setting — valid key\n";
reset_test_state();
setup_admin_user();
update_option( 'def_core_staff_ai_api_url', 'https://api.example.com' );

$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/setting/def_core_staff_ai_api_url' );
$request->set_param( 'key', 'def_core_staff_ai_api_url' );
$response = $sa->rest_get_setting( $request );
$data     = $response->get_data();

assert_equals( 200, $response->get_status(), 'returns 200' );
assert_equals( 'def_core_staff_ai_api_url', $data['data']['key'], 'key matches' );
assert_equals( 'https://api.example.com', $data['data']['value'], 'value matches' );

// ── 18. GET /setup/setting — unknown key ────────────────────────────────
echo "\n[18] GET /setup/setting — unknown key\n";
reset_test_state();
setup_admin_user();

$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/setting/unknown_key' );
$request->set_param( 'key', 'unknown_key' );
$response = $sa->rest_get_setting( $request );
$data     = $response->get_data();

assert_equals( 400, $response->get_status(), 'returns 400' );
assert_false( $data['success'], 'success is false' );
assert_equals( 'UNKNOWN_SETTING', $data['error']['code'], 'error code is UNKNOWN_SETTING' );

// ── 19. GET /setup/setting — secret redaction (configured_only) ─────────
echo "\n[19] GET /setup/setting — API key redaction\n";
reset_test_state();
setup_admin_user();
update_option( 'def_core_api_key', 'super_secret_key_12345' );

$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/setting/def_core_api_key' );
$request->set_param( 'key', 'def_core_api_key' );
$response = $sa->rest_get_setting( $request );
$data     = $response->get_data();

assert_equals( 200, $response->get_status(), 'returns 200' );
assert_true( $data['data']['configured'], 'configured is true' );
assert_false( isset( $data['data']['value'] ), 'raw value NOT returned' );
assert_equals( 'def_core_api_key', $data['data']['key'], 'key matches' );

// ── 20. POST /setup/setting — valid update ──────────────────────────────
echo "\n[20] POST /setup/setting — valid update\n";
reset_test_state();
setup_admin_user();

$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_display_name' );
$request->set_param( 'key', 'def_core_display_name' );
$request->set_body_params( array( 'value' => 'My Assistant' ) );
$response = $sa->rest_update_setting( $request );
$data     = $response->get_data();

assert_equals( 200, $response->get_status(), 'returns 200' );
assert_true( $data['data']['saved'], 'saved is true' );
assert_equals( 'My Assistant', get_option( 'def_core_display_name', '' ), 'value persisted' );

// Verify UI action.
assert_true( count( $data['ui_actions'] ) > 0, 'ui_actions present' );
assert_equals( 'highlight_tab', $data['ui_actions'][0]['action'], 'ui_action is highlight_tab' );
assert_equals( 'branding', $data['ui_actions'][0]['tab'], 'tab is branding' );

// ── 21. POST /setup/setting — validation error ──────────────────────────
echo "\n[21] POST /setup/setting — validation error (display_mode)\n";
reset_test_state();
setup_admin_user();

$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_chat_display_mode' );
$request->set_param( 'key', 'def_core_chat_display_mode' );
$request->set_body_params( array( 'value' => 'fullscreen' ) );
$response = $sa->rest_update_setting( $request );
$data     = $response->get_data();

assert_equals( 400, $response->get_status(), 'returns 400' );
assert_equals( 'VALIDATION_ERROR', $data['error']['code'], 'error code is VALIDATION_ERROR' );

// ── 22. POST /setup/setting — drawer width validation ───────────────────
echo "\n[22] POST /setup/setting — drawer width out of range\n";
reset_test_state();
setup_admin_user();

$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_chat_drawer_width' );
$request->set_param( 'key', 'def_core_chat_drawer_width' );
$request->set_body_params( array( 'value' => 800 ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 400, $response->get_status(), 'width 800 rejected' );

$request->set_body_params( array( 'value' => 200 ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 400, $response->get_status(), 'width 200 rejected' );

$request->set_body_params( array( 'value' => 450 ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 200, $response->get_status(), 'width 450 accepted' );

// ── 23. POST /setup/setting — URL validation (readonly after Sub-PR C) ──
echo "\n[23] POST /setup/setting — URL validation (readonly)\n";
reset_test_state();
setup_admin_user();

// def_core_staff_ai_api_url is now readonly (pushed from DEFHO), so writes return 403.
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_staff_ai_api_url' );
$request->set_param( 'key', 'def_core_staff_ai_api_url' );
$request->set_body_params( array( 'value' => 'https://api.example.com' ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 403, $response->get_status(), 'readonly URL setting rejected with 403' );
assert_equals( 'READONLY_SETTING', $response->get_data()['error']['code'], 'readonly error code' );

// ── 24. User role: add and remove ───────────────────────────────────────
echo "\n[24] POST /setup/user-role — add and remove\n";
reset_test_state();
setup_admin_user();

$target = new WP_User( 20 );
$target->display_name = 'Staff User';
$target->user_email   = 'staff@test.com';
$_wp_test_users[20]   = $target;

// Add staff capability.
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/user-role' );
$request->set_body_params( array(
	'wp_user_id' => 20,
	'capability' => 'def_staff_access',
	'action'     => 'add',
) );
$response = $sa->rest_update_user_role( $request );
$data     = $response->get_data();
assert_equals( 200, $response->get_status(), 'add role returns 200' );
assert_true( $data['data']['applied'], 'applied is true' );
assert_true( $target->has_cap( 'def_staff_access' ), 'user now has def_staff_access' );

// Remove staff capability.
$request->set_body_params( array(
	'wp_user_id' => 20,
	'capability' => 'def_staff_access',
	'action'     => 'remove',
) );
$response = $sa->rest_update_user_role( $request );
assert_equals( 200, $response->get_status(), 'remove role returns 200' );
assert_false( $target->has_cap( 'def_staff_access' ), 'user no longer has def_staff_access' );

// ── 25. User role: lockout prevention ───────────────────────────────────
echo "\n[25] POST /setup/user-role — lockout prevention\n";
reset_test_state();
setup_admin_user();

// Only one admin (user 1).
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/user-role' );
$request->set_body_params( array(
	'wp_user_id' => 1,
	'capability' => 'def_admin_access',
	'action'     => 'remove',
) );
$response = $sa->rest_update_user_role( $request );
$data     = $response->get_data();
assert_equals( 409, $response->get_status(), 'lockout returns 409' );
assert_equals( 'LOCKOUT_PREVENTED', $data['error']['code'], 'error code is LOCKOUT_PREVENTED' );

// ── 26. User role: invalid params ───────────────────────────────────────
echo "\n[26] POST /setup/user-role — invalid params\n";
reset_test_state();
setup_admin_user();

$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/user-role' );

// Invalid capability.
$request->set_body_params( array( 'wp_user_id' => 1, 'capability' => 'manage_options', 'action' => 'add' ) );
$response = $sa->rest_update_user_role( $request );
assert_equals( 400, $response->get_status(), 'invalid capability returns 400' );

// Invalid action.
$request->set_body_params( array( 'wp_user_id' => 1, 'capability' => 'def_staff_access', 'action' => 'toggle' ) );
$response = $sa->rest_update_user_role( $request );
assert_equals( 400, $response->get_status(), 'invalid action returns 400' );

// User not found.
$request->set_body_params( array( 'wp_user_id' => 999, 'capability' => 'def_staff_access', 'action' => 'add' ) );
$response = $sa->rest_update_user_role( $request );
assert_equals( 404, $response->get_status(), 'user not found returns 404' );

// ── 27. GET /setup/users ────────────────────────────────────────────────
echo "\n[27] GET /setup/users\n";
reset_test_state();
setup_admin_user();

$staff = new WP_User( 10 );
$staff->display_name = 'Staff';
$staff->user_email   = 'staff@test.com';
$staff->caps         = array( 'def_staff_access' => true );
$_wp_test_users[10]  = $staff;

$mgmt = new WP_User( 11 );
$mgmt->display_name = 'Manager';
$mgmt->user_email   = 'mgmt@test.com';
$mgmt->caps         = array( 'def_management_access' => true, 'def_staff_access' => true );
$_wp_test_users[11]  = $mgmt;

$request  = new WP_REST_Request( 'GET', '/def-core/v1/setup/users' );
$response = $sa->rest_get_users( $request );
$data     = $response->get_data();

assert_equals( 200, $response->get_status(), 'returns 200' );
assert_true( count( $data['data']['users'] ) >= 2, 'at least 2 DEF users returned' );

// ── 28. Chat proxy: not configured ──────────────────────────────────────
echo "\n[28] POST /setup/chat — not configured\n";
reset_test_state();
setup_admin_user();

$request  = new WP_REST_Request( 'POST', '/def-core/v1/setup/chat' );
$request->set_body_params( array( 'message' => 'hello' ) );
$response = $sa->rest_proxy_chat( $request );
$data     = $response->get_data();

assert_equals( 400, $response->get_status(), 'returns 400 when not configured' );
assert_equals( 'NOT_CONFIGURED', $data['error']['code'], 'error code is NOT_CONFIGURED' );

// ── 29. Chat proxy: successful forward ──────────────────────────────────
echo "\n[29] POST /setup/chat — successful forward\n";
reset_test_state();
setup_admin_user();
update_option( 'def_core_staff_ai_api_url', 'https://api.example.com' );
update_option( 'def_core_api_key', 'key123' );

$_wp_test_remote_responses[] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode( array( 'reply' => 'I can help with setup!' ) ),
);

$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/chat' );
$request->set_body_params( array( 'message' => 'How do I configure?' ) );
$response = $sa->rest_proxy_chat( $request );

assert_equals( 200, $response->get_status(), 'proxied response is 200' );
assert_equals( 1, count( $_wp_test_remote_calls ), 'one HTTP call made' );
assert_true(
	strpos( $_wp_test_remote_calls[0]['url'], '/api/setup_assistant/chat' ) !== false,
	'called correct backend URL'
);

// ── 30. Chat proxy: backend error ───────────────────────────────────────
echo "\n[30] POST /setup/chat — backend error\n";
reset_test_state();
setup_admin_user();
update_option( 'def_core_staff_ai_api_url', 'https://api.example.com' );
update_option( 'def_core_api_key', 'key123' );
// No remote responses queued → WP_Error returned.

$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/chat' );
$request->set_body_params( array( 'message' => 'hello' ) );
$response = $sa->rest_proxy_chat( $request );
$data     = $response->get_data();

assert_equals( 502, $response->get_status(), 'returns 502 on backend failure' );
assert_equals( 'BACKEND_ERROR', $data['error']['code'], 'error code is BACKEND_ERROR' );

// ── 31. GET /setup/test-connection — success ────────────────────────────
echo "\n[31] GET /setup/test-connection — success\n";
reset_test_state();
setup_admin_user();
update_option( 'def_core_staff_ai_api_url', 'https://api.example.com' );
update_option( 'def_core_api_key', 'key123' );

$_wp_test_remote_responses[] = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{"status":"healthy"}',
);

$request  = new WP_REST_Request( 'GET', '/def-core/v1/setup/test-connection' );
$response = $sa->rest_test_connection( $request );
$data     = $response->get_data();

assert_equals( 200, $response->get_status(), 'returns 200' );
assert_equals( 'ok', $data['data']['status'], 'connection status is ok' );
assert_equals( 200, $data['data']['http_code'], 'http_code is 200' );
assert_true( isset( $data['data']['response_time'] ), 'response_time present' );

// ── 32. GET /setup/test-connection — not configured ─────────────────────
echo "\n[32] GET /setup/test-connection — not configured\n";
reset_test_state();
setup_admin_user();

$request  = new WP_REST_Request( 'GET', '/def-core/v1/setup/test-connection' );
$response = $sa->rest_test_connection( $request );
$data     = $response->get_data();

assert_equals( 200, $response->get_status(), 'returns 200 (with error status in data)' );
assert_equals( 'error', $data['data']['status'], 'status is error' );

// ── 33. Thread CRUD ─────────────────────────────────────────────────────
echo "\n[33] Thread CRUD\n";
reset_test_state();
setup_admin_user();

// GET: no thread yet.
$request  = new WP_REST_Request( 'GET', '/def-core/v1/setup/thread' );
$response = $sa->rest_get_thread( $request );
$data     = $response->get_data();
assert_null( $data['data']['thread_id'], 'thread_id is null initially' );

// POST: save thread.
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/thread' );
$request->set_body_params( array( 'thread_id' => 'thread_abc123' ) );
$response = $sa->rest_save_thread( $request );
$data     = $response->get_data();
assert_equals( 200, $response->get_status(), 'save returns 200' );
assert_true( $data['data']['saved'], 'saved is true' );

// GET: thread exists now.
$request  = new WP_REST_Request( 'GET', '/def-core/v1/setup/thread' );
$response = $sa->rest_get_thread( $request );
$data     = $response->get_data();
assert_equals( 'thread_abc123', $data['data']['thread_id'], 'thread_id matches saved value' );

// DELETE: remove thread.
$request  = new WP_REST_Request( 'DELETE', '/def-core/v1/setup/thread' );
$response = $sa->rest_delete_thread( $request );
$data     = $response->get_data();
assert_true( $data['data']['deleted'], 'deleted is true' );

// GET: thread gone after delete.
$request  = new WP_REST_Request( 'GET', '/def-core/v1/setup/thread' );
$response = $sa->rest_get_thread( $request );
$data     = $response->get_data();
assert_null( $data['data']['thread_id'], 'thread_id is null after delete' );

// ── 34. Thread: validation ──────────────────────────────────────────────
echo "\n[34] Thread: validation\n";
reset_test_state();
setup_admin_user();

// Empty thread ID.
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/thread' );
$request->set_body_params( array( 'thread_id' => '' ) );
$response = $sa->rest_save_thread( $request );
assert_equals( 400, $response->get_status(), 'empty thread_id returns 400' );

// Too long thread ID.
$request->set_body_params( array( 'thread_id' => str_repeat( 'a', 201 ) ) );
$response = $sa->rest_save_thread( $request );
assert_equals( 400, $response->get_status(), 'too-long thread_id returns 400' );

// ── 35. Rate limiting ───────────────────────────────────────────────────
echo "\n[35] Rate limiting\n";
reset_test_state();
setup_admin_user();

// Simulate 30 writes (should all succeed).
for ( $i = 0; $i < 30; $i++ ) {
	$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_display_name' );
	$request->set_param( 'key', 'def_core_display_name' );
	$request->set_body_params( array( 'value' => "Name $i" ) );
	$response = $sa->rest_update_setting( $request );
}
assert_equals( 200, $response->get_status(), '30th write succeeds' );

// 31st write should be rate limited.
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_display_name' );
$request->set_param( 'key', 'def_core_display_name' );
$request->set_body_params( array( 'value' => 'Name 31' ) );
$response = $sa->rest_update_setting( $request );
$data     = $response->get_data();
assert_equals( 429, $response->get_status(), '31st write returns 429' );
assert_equals( 'RATE_LIMITED', $data['error']['code'], 'error code is RATE_LIMITED' );
assert_true( isset( $data['error']['retry_after'] ), 'retry_after in error body' );
assert_true( $data['error']['retry_after'] > 0, 'retry_after is positive' );
$headers = $response->get_headers();
assert_true( isset( $headers['Retry-After'] ), 'Retry-After HTTP header set' );

// Read is NOT rate limited (different endpoint, no rate check).
$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/setting/def_core_display_name' );
$request->set_param( 'key', 'def_core_display_name' );
$response = $sa->rest_get_setting( $request );
assert_equals( 200, $response->get_status(), 'read still works when rate limited' );

// ── 36. Audit log: entry creation ───────────────────────────────────────
echo "\n[36] Audit log: entry creation\n";
reset_test_state();
setup_admin_user();

$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_display_name' );
$request->set_param( 'key', 'def_core_display_name' );
$request->set_body_params( array( 'value' => 'New Bot Name' ) );
$sa->rest_update_setting( $request );

$log = get_option( 'def_core_setup_audit_log', array() );
assert_true( count( $log ) > 0, 'audit log has entries' );

$last_entry = end( $log );
assert_equals( 'update_setting', $last_entry['action'], 'action is update_setting' );
assert_equals( 'def_core_display_name', $last_entry['setting_key'], 'setting_key matches' );
assert_equals( 1, $last_entry['acting_wp_user_id'], 'acting user ID is correct' );
assert_true( isset( $last_entry['timestamp'] ), 'timestamp present' );

// ── 37. Audit log: API key redaction ────────────────────────────────────
echo "\n[37] Audit log: API key redaction\n";
reset_test_state();
setup_admin_user();

$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_api_key' );
$request->set_param( 'key', 'def_core_api_key' );
$request->set_body_params( array( 'value' => 'super_secret_key' ) );
$sa->rest_update_setting( $request );

$log        = get_option( 'def_core_setup_audit_log', array() );
$last_entry = end( $log );
assert_equals( '[redacted]', $last_entry['old_value'], 'old_value is redacted' );
assert_equals( '[redacted]', $last_entry['new_value'], 'new_value is redacted' );

// ── 38. Audit log: FIFO rotation ────────────────────────────────────────
echo "\n[38] Audit log: FIFO rotation at 100\n";
reset_test_state();
setup_admin_user();

// Pre-fill with 100 entries.
$pre_log = array();
for ( $i = 0; $i < 100; $i++ ) {
	$pre_log[] = array(
		'timestamp'         => gmdate( 'c' ),
		'acting_wp_user_id' => 1,
		'acting_wp_user_name' => 'Test',
		'action'            => 'test_entry_' . $i,
	);
}
update_option( 'def_core_setup_audit_log', $pre_log, false );

// One more write to trigger rotation.
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_display_name' );
$request->set_param( 'key', 'def_core_display_name' );
$request->set_body_params( array( 'value' => 'After FIFO' ) );
$sa->rest_update_setting( $request );

$log = get_option( 'def_core_setup_audit_log', array() );
assert_equals( 100, count( $log ), 'log stays at 100 entries' );
$newest = end( $log );
assert_equals( 'update_setting', $newest['action'], 'newest entry is the latest write' );
$oldest = reset( $log );
assert_equals( 'test_entry_1', $oldest['action'], 'oldest entry (entry_0) was evicted' );

// ── 39. Setting allowlist coverage ──────────────────────────────────────
echo "\n[39] Setting allowlist coverage\n";
$allowlist = DEF_Core_Setup_Assistant::get_setting_allowlist();
assert_equals( 9, count( $allowlist ), 'exactly 9 settings in allowlist' );

$expected_keys = array(
	'def_core_staff_ai_api_url',
	'def_core_api_key',
	'def_core_display_name',
	'def_core_logo_id',
	'def_core_escalation_customer',
	'def_core_escalation_staff_ai',
	'def_core_escalation_setup_assistant',
	'def_core_chat_display_mode',
	'def_core_chat_drawer_width',
);
foreach ( $expected_keys as $key ) {
	assert_true( isset( $allowlist[ $key ] ), "allowlist contains $key" );
}

// ── 40. Response envelope structure ─────────────────────────────────────
echo "\n[40] Response envelope structure\n";
reset_test_state();
setup_admin_user();

// Success envelope.
$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/setting/def_core_display_name' );
$request->set_param( 'key', 'def_core_display_name' );
$response = $sa->rest_get_setting( $request );
$data     = $response->get_data();

assert_true( array_key_exists( 'success', $data ), 'has success field' );
assert_true( array_key_exists( 'data', $data ), 'has data field' );
assert_true( array_key_exists( 'error', $data ), 'has error field' );
assert_true( array_key_exists( 'ui_actions', $data ), 'has ui_actions field' );
assert_true( $data['success'], 'success is true for valid request' );
assert_null( $data['error'], 'error is null for valid request' );

// Error envelope.
$request->set_param( 'key', 'bad_key' );
$response = $sa->rest_get_setting( $request );
$data     = $response->get_data();
assert_false( $data['success'], 'success is false for error' );
assert_null( $data['data'], 'data is null for error' );
assert_not_null( $data['error'], 'error is set for error' );
assert_true( isset( $data['error']['code'] ), 'error has code' );
assert_true( isset( $data['error']['message'] ), 'error has message' );

// ── 41. Email setting validation ────────────────────────────────────────
echo "\n[41] Email setting validation\n";
reset_test_state();
setup_admin_user();

// Valid email.
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_escalation_customer' );
$request->set_param( 'key', 'def_core_escalation_customer' );
$request->set_body_params( array( 'value' => 'test@example.com' ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 200, $response->get_status(), 'valid email accepted' );

// Invalid email.
$request->set_body_params( array( 'value' => 'not-an-email' ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 400, $response->get_status(), 'invalid email rejected' );

// Empty email (allowed to clear).
$request->set_body_params( array( 'value' => '' ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 200, $response->get_status(), 'empty email allowed (to clear)' );

// ── 42. Logo ID validation ──────────────────────────────────────────────
echo "\n[42] Logo ID validation\n";
reset_test_state();
setup_admin_user();

$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_logo_id' );
$request->set_param( 'key', 'def_core_logo_id' );

// Valid image ID.
$request->set_body_params( array( 'value' => 5 ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 200, $response->get_status(), 'valid image ID accepted' );

// Zero to clear.
$request->set_body_params( array( 'value' => 0 ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 200, $response->get_status(), 'zero ID accepted (to clear)' );

// Invalid image ID (stub: IDs > 10 are not images).
$request->set_body_params( array( 'value' => 99 ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 400, $response->get_status(), 'non-image attachment rejected' );

// ── 43. Display name max length ─────────────────────────────────────────
echo "\n[43] Display name max length\n";
reset_test_state();
setup_admin_user();

$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_display_name' );
$request->set_param( 'key', 'def_core_display_name' );
$request->set_body_params( array( 'value' => str_repeat( 'a', 101 ) ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 400, $response->get_status(), '101 chars rejected' );

$request->set_body_params( array( 'value' => str_repeat( 'a', 100 ) ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 200, $response->get_status(), '100 chars accepted' );

// ── 44. Missing value in POST ───────────────────────────────────────────
echo "\n[44] Missing value in POST setting\n";
reset_test_state();
setup_admin_user();

$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_display_name' );
$request->set_param( 'key', 'def_core_display_name' );
$request->set_body_params( array() ); // No 'value' field.
$response = $sa->rest_update_setting( $request );
$data     = $response->get_data();
assert_equals( 400, $response->get_status(), 'missing value returns 400' );
assert_equals( 'MISSING_VALUE', $data['error']['code'], 'error code is MISSING_VALUE' );

// ── 45. HMAC canonical route ────────────────────────────────────────────
echo "\n[45] HMAC canonical route used in signature\n";
reset_test_state();
$api_key = 'canonical_test_key';
update_option( 'def_core_api_key', $api_key );

$admin = new WP_User( 42 );
$admin->display_name = 'Admin';
$admin->caps = array( 'def_admin_access' => true );
$_wp_test_users[42] = $admin;

// Use a route with a URL parameter.
$method    = 'GET';
$path      = '/def-core/v1/setup/setting/def_core_display_name';
$timestamp = (string) time();
$user_id   = '42';
$body_hash = hash( 'sha256', '' );
$payload   = "{$method}:{$path}:{$timestamp}:{$user_id}:{$body_hash}";
$signature = hash_hmac( 'sha256', $payload, $api_key );

$_SERVER['HTTP_X_DEF_SIGNATURE'] = $signature;
$_SERVER['HTTP_X_DEF_TIMESTAMP'] = $timestamp;
$_SERVER['HTTP_X_DEF_USER']      = $user_id;
$_SERVER['HTTP_X_DEF_BODY_HASH'] = $body_hash;

$request = new WP_REST_Request( $method, $path );
$result  = $sa->permission_check( $request );
assert_true( $result === true, 'HMAC with canonical route param accepted' );

// ── 46. GET /setup/seen — not yet seen ──────────────────────────────────
echo "\n[46] GET /setup/seen — not yet seen\n";
reset_test_state();
setup_admin_user();

$request  = new WP_REST_Request( 'GET', '/def-core/v1/setup/seen' );
$response = $sa->rest_get_seen( $request );
$data     = $response->get_data();
assert_equals( 200, $response->get_status(), 'returns 200' );
assert_true( $data['success'], 'success is true' );
assert_false( $data['data']['seen'], 'seen is false when no user meta' );

// ── 47. POST /setup/seen — mark seen ────────────────────────────────────
echo "\n[47] POST /setup/seen — mark seen\n";
reset_test_state();
setup_admin_user();

$request  = new WP_REST_Request( 'POST', '/def-core/v1/setup/seen' );
$response = $sa->rest_set_seen( $request );
$data     = $response->get_data();
assert_equals( 200, $response->get_status(), 'returns 200' );
assert_true( $data['success'], 'success is true' );
assert_true( $data['data']['seen'], 'seen is true after marking' );

// Verify user meta was set.
$meta = get_user_meta( 1, 'def_sa_drawer_seen', true );
assert_equals( '1', $meta, 'user meta def_sa_drawer_seen is "1"' );

// ── 48. GET /setup/seen — already seen ──────────────────────────────────
echo "\n[48] GET /setup/seen — already seen\n";
// User meta still set from test 47 (same user, not reset).
$request  = new WP_REST_Request( 'GET', '/def-core/v1/setup/seen' );
$response = $sa->rest_get_seen( $request );
$data     = $response->get_data();
assert_equals( 200, $response->get_status(), 'returns 200' );
assert_true( $data['data']['seen'], 'seen is true when meta exists' );

// ── 49. Seen endpoint requires auth ─────────────────────────────────────
echo "\n[49] Seen endpoint requires auth\n";
reset_test_state();
// No user setup — no nonce, no HMAC.
$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/seen' );
$result  = $sa->permission_check( $request );
assert_true( is_wp_error( $result ), 'returns WP_Error without auth' );
assert_equals( 'UNAUTHORIZED', $result->get_error_code(), 'error code is UNAUTHORIZED' );

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
