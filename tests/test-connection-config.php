<?php
/**
 * Connection Config endpoint tests.
 *
 * Verifies:
 * - Route registration (2 endpoints)
 * - Service auth (valid secret, invalid secret, missing header, dual-key rotation)
 * - Receive config push (success, stale rejection, idempotent, missing fields)
 * - Config values written to WP options
 * - Revision tracking
 * - Dual-key rotation window (service secret + API key)
 * - Connection status endpoint
 * - Setup Assistant readonly enforcement
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Additional WP stubs ──────────────────────────────────────────────────

global $_wp_test_rest_routes, $_wp_test_transients;
$_wp_test_rest_routes = array();

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
		$method = $args['methods'] ?? 'GET';
		$_wp_test_rest_routes[ $namespace . $route . '::' . $method ] = $args;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

// ── Load the class under test ────────────────────────────────────────────
// Encryption class already loaded via wp-stubs.php.

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-connection-config.php';

// ── Test harness ─────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function assert_true( $value, string $label ): void {
	global $pass, $fail;
	if ( $value ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected true, got " . var_export( $value, true ) . ")\n";
	}
}

function assert_false( $value, string $label ): void {
	global $pass, $fail;
	if ( ! $value ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected false, got " . var_export( $value, true ) . ")\n";
	}
}

function assert_eq( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label\n    expected: " . var_export( $expected, true ) . "\n    got:      " . var_export( $actual, true ) . "\n";
	}
}

function reset_state(): void {
	_wp_test_reset_options();
	global $_wp_test_transients;
	$_wp_test_transients = array();
	unset( $_SERVER['HTTP_X_DEF_AUTH'] );
}

// ── Register routes ──────────────────────────────────────────────────────

DEF_Core_Connection_Config::register_rest_routes();

// =========================================================================
// 1. Route Registration
// =========================================================================

echo "1. Route registration\n";

assert_true(
	isset( $GLOBALS['_wp_test_rest_routes']['a3-ai/v1/internal/connection-config::POST'] ),
	'POST /internal/connection-config route registered'
);
assert_true(
	isset( $GLOBALS['_wp_test_rest_routes']['a3-ai/v1/connection-status::GET'] ),
	'GET /connection-status route registered'
);

// =========================================================================
// 2. Service Auth — Permission Check
// =========================================================================

echo "2. Service auth — permission check\n";

// 2a. No header → 401
reset_state();
update_option( 'def_service_auth_secret', 'test-secret-abc123' );
$result = DEF_Core_Connection_Config::permission_check();
assert_true( is_wp_error( $result ), '2a. No auth header → WP_Error' );
assert_eq( 401, $result->get_error_data()['status'], '2a. Status 401' );

// 2b. Wrong secret → 401
reset_state();
update_option( 'def_service_auth_secret', 'test-secret-abc123' );
$_SERVER['HTTP_X_DEF_AUTH'] = 'wrong-secret';
$result = DEF_Core_Connection_Config::permission_check();
assert_true( is_wp_error( $result ), '2b. Wrong secret → WP_Error' );

// 2c. Correct secret → true
reset_state();
update_option( 'def_service_auth_secret', 'test-secret-abc123' );
$_SERVER['HTTP_X_DEF_AUTH'] = 'test-secret-abc123';
$result = DEF_Core_Connection_Config::permission_check();
assert_true( $result === true, '2c. Correct secret → true' );

// 2d. No secret configured → 401
reset_state();
$_SERVER['HTTP_X_DEF_AUTH'] = 'any-secret';
$result = DEF_Core_Connection_Config::permission_check();
assert_true( is_wp_error( $result ), '2d. No secret configured → WP_Error' );

// 2e. Previous secret during rotation window → true
reset_state();
update_option( 'def_service_auth_secret', 'new-secret' );
update_option( 'def_core_conn_previous_service_secret', 'old-secret' );
update_option( 'def_core_conn_rotation_expires', time() + 300 );
$_SERVER['HTTP_X_DEF_AUTH'] = 'old-secret';
$result = DEF_Core_Connection_Config::permission_check();
assert_true( $result === true, '2e. Previous secret during rotation window → true' );

// 2f. Previous secret after rotation window → 401
reset_state();
update_option( 'def_service_auth_secret', 'new-secret' );
update_option( 'def_core_conn_previous_service_secret', 'old-secret' );
update_option( 'def_core_conn_rotation_expires', time() - 1 );
$_SERVER['HTTP_X_DEF_AUTH'] = 'old-secret';
$result = DEF_Core_Connection_Config::permission_check();
assert_true( is_wp_error( $result ), '2f. Previous secret after rotation window → WP_Error' );

// =========================================================================
// 3. Receive Config Push — Success
// =========================================================================

echo "3. Receive config push — success\n";

reset_state();
$request = new WP_REST_Request( 'POST', '/a3-ai/v1/internal/connection-config' );
$request->set_body_params( array(
	'config_revision'     => 1,
	'api_url'             => 'https://def-api.example.com',
	'api_key'             => 'test-api-key-12345',
	'allowed_origins'     => array( 'https://tenant.example.com' ),
	'external_jwks_url'   => 'https://tenant.example.com/wp-json/a3-ai/v1/jwks',
	'external_issuer'     => 'https://tenant.example.com',
	'service_auth_secret' => 'test-service-secret',
) );

$response = DEF_Core_Connection_Config::receive_connection_config( $request );

assert_eq( 200, $response->get_status(), '3a. Response status 200' );
assert_eq( 'applied', $response->get_data()['status'], '3b. Status = applied' );
assert_eq( 1, $response->get_data()['config_revision'], '3c. Revision returned' );

// Verify options were written.
assert_eq( 'https://def-api.example.com', get_option( 'def_core_staff_ai_api_url' ), '3d. API URL stored' );
assert_eq( 'test-api-key-12345', DEF_Core_Encryption::get_secret( 'def_core_api_key' ), '3e. API key stored (encrypted)' );
assert_eq( array( 'https://tenant.example.com' ), get_option( DEF_CORE_OPTION_ALLOWED_ORIGINS ), '3f. Origins stored' );
assert_eq( 'https://tenant.example.com/wp-json/a3-ai/v1/jwks', get_option( 'def_core_external_jwks_url' ), '3g. JWKS URL stored' );
assert_eq( 'https://tenant.example.com', get_option( 'def_core_external_issuer' ), '3h. Issuer stored' );
assert_eq( 'test-service-secret', DEF_Core_Encryption::get_secret( 'def_service_auth_secret' ), '3i. Service secret stored (encrypted)' );
assert_eq( 1, get_option( 'def_core_conn_config_revision' ), '3j. Revision stored' );
assert_true( ! empty( get_option( 'def_core_conn_last_sync_at' ) ), '3k. Last sync timestamp set' );

// =========================================================================
// 4. Stale Revision Rejected
// =========================================================================

echo "4. Stale revision rejected\n";

reset_state();
update_option( 'def_core_conn_config_revision', 5 );

$request = new WP_REST_Request( 'POST', '/a3-ai/v1/internal/connection-config' );
$request->set_body_params( array(
	'config_revision'     => 3,
	'api_key'             => 'key',
	'service_auth_secret' => 'secret',
) );

$response = DEF_Core_Connection_Config::receive_connection_config( $request );

assert_eq( 409, $response->get_status(), '4a. Stale push → 409' );
assert_eq( 'rejected', $response->get_data()['status'], '4b. Status = rejected' );
assert_true( strpos( $response->get_data()['message'], 'Stale push rejected' ) !== false, '4c. Stale message' );

// =========================================================================
// 5. Same Revision Idempotent
// =========================================================================

echo "5. Same revision idempotent\n";

reset_state();
update_option( 'def_core_conn_config_revision', 3 );

$request = new WP_REST_Request( 'POST', '/a3-ai/v1/internal/connection-config' );
$request->set_body_params( array(
	'config_revision'     => 3,
	'api_key'             => 'key',
	'service_auth_secret' => 'secret',
) );

$response = DEF_Core_Connection_Config::receive_connection_config( $request );

assert_eq( 200, $response->get_status(), '5a. Same revision → 200' );
assert_true( strpos( $response->get_data()['message'], 'already at this revision' ) !== false, '5b. Idempotent message' );

// =========================================================================
// 6. Missing Required Fields
// =========================================================================

echo "6. Missing required fields\n";

// 6a. Missing api_key
reset_state();
$request = new WP_REST_Request( 'POST', '/a3-ai/v1/internal/connection-config' );
$request->set_body_params( array(
	'config_revision'     => 1,
	'service_auth_secret' => 'secret',
) );
$response = DEF_Core_Connection_Config::receive_connection_config( $request );
assert_eq( 422, $response->get_status(), '6a. Missing api_key → 422' );

// 6b. Missing service_auth_secret
reset_state();
$request = new WP_REST_Request( 'POST', '/a3-ai/v1/internal/connection-config' );
$request->set_body_params( array(
	'config_revision' => 1,
	'api_key'         => 'key',
) );
$response = DEF_Core_Connection_Config::receive_connection_config( $request );
assert_eq( 422, $response->get_status(), '6b. Missing service_auth_secret → 422' );

// 6c. Missing config_revision
reset_state();
$request = new WP_REST_Request( 'POST', '/a3-ai/v1/internal/connection-config' );
$request->set_body_params( array(
	'api_key'             => 'key',
	'service_auth_secret' => 'secret',
) );
$response = DEF_Core_Connection_Config::receive_connection_config( $request );
assert_eq( 422, $response->get_status(), '6c. Missing config_revision → 422' );

// =========================================================================
// 7. Revision Increment
// =========================================================================

echo "7. Revision increment\n";

reset_state();

// Push revision 1.
$request = new WP_REST_Request( 'POST', '/a3-ai/v1/internal/connection-config' );
$request->set_body_params( array(
	'config_revision'     => 1,
	'api_url'             => 'https://v1.example.com',
	'api_key'             => 'key-v1',
	'service_auth_secret' => 'secret-v1',
) );
$response = DEF_Core_Connection_Config::receive_connection_config( $request );
assert_eq( 200, $response->get_status(), '7a. Push rev 1 → 200' );
assert_eq( 'https://v1.example.com', get_option( 'def_core_staff_ai_api_url' ), '7b. API URL v1' );

// Push revision 2 with different values.
$request2 = new WP_REST_Request( 'POST', '/a3-ai/v1/internal/connection-config' );
$request2->set_body_params( array(
	'config_revision'     => 2,
	'api_url'             => 'https://v2.example.com',
	'api_key'             => 'key-v2',
	'service_auth_secret' => 'secret-v2',
	'allowed_origins'     => array( 'https://origin-v2.example.com' ),
) );
$response2 = DEF_Core_Connection_Config::receive_connection_config( $request2 );
assert_eq( 200, $response2->get_status(), '7c. Push rev 2 → 200' );
assert_eq( 2, $response2->get_data()['config_revision'], '7d. Revision 2 returned' );
assert_eq( 'https://v2.example.com', get_option( 'def_core_staff_ai_api_url' ), '7e. API URL updated to v2' );
assert_eq( 'key-v2', DEF_Core_Encryption::get_secret( 'def_core_api_key' ), '7f. API key updated to v2 (encrypted)' );
assert_eq( 2, get_option( 'def_core_conn_config_revision' ), '7g. Stored revision is 2' );

// =========================================================================
// 8. Dual-Key Rotation — Service Secret
// =========================================================================

echo "8. Dual-key rotation — service secret\n";

reset_state();
update_option( 'def_service_auth_secret', 'old-secret' );

$request = new WP_REST_Request( 'POST', '/a3-ai/v1/internal/connection-config' );
$request->set_body_params( array(
	'config_revision'     => 1,
	'api_key'             => 'key',
	'service_auth_secret' => 'new-secret',
) );
$response = DEF_Core_Connection_Config::receive_connection_config( $request );
assert_eq( 200, $response->get_status(), '8a. Push with secret rotation → 200' );
assert_eq( 'new-secret', DEF_Core_Encryption::get_secret( 'def_service_auth_secret' ), '8b. New secret stored (encrypted)' );
assert_eq( 'old-secret', DEF_Core_Encryption::get_secret( 'def_core_conn_previous_service_secret' ), '8c. Old secret stored as previous (encrypted)' );
assert_true( get_option( 'def_core_conn_rotation_expires' ) > time(), '8d. Rotation expiry set in future' );

// =========================================================================
// 9. Dual-Key Rotation — Previous fields from payload
// =========================================================================

echo "9. Dual-key rotation — previous fields from payload\n";

reset_state();
$request = new WP_REST_Request( 'POST', '/a3-ai/v1/internal/connection-config' );
$request->set_body_params( array(
	'config_revision'              => 1,
	'api_key'                      => 'new-key',
	'service_auth_secret'          => 'new-secret',
	'previous_api_key'             => 'old-key',
	'previous_service_auth_secret' => 'old-svc-secret',
) );
$response = DEF_Core_Connection_Config::receive_connection_config( $request );
assert_eq( 200, $response->get_status(), '9a. Push with previous fields → 200' );
assert_eq( 'old-key', DEF_Core_Encryption::get_secret( 'def_core_conn_previous_api_key' ), '9b. Previous API key stored (encrypted)' );
assert_eq( 'old-svc-secret', DEF_Core_Encryption::get_secret( 'def_core_conn_previous_service_secret' ), '9c. Previous service secret stored (encrypted)' );

// =========================================================================
// 10. Empty Allowed Origins
// =========================================================================

echo "10. Empty allowed origins\n";

reset_state();
$request = new WP_REST_Request( 'POST', '/a3-ai/v1/internal/connection-config' );
$request->set_body_params( array(
	'config_revision'     => 1,
	'api_key'             => 'key',
	'service_auth_secret' => 'secret',
	'allowed_origins'     => array(),
) );
$response = DEF_Core_Connection_Config::receive_connection_config( $request );
assert_eq( 200, $response->get_status(), '10a. Empty origins → 200' );
assert_eq( array(), get_option( DEF_CORE_OPTION_ALLOWED_ORIGINS ), '10b. Empty array stored' );

// =========================================================================
// 11. Connection Status Endpoint
// =========================================================================

echo "11. Connection status endpoint\n";

// 11a. No config → not connected.
reset_state();
$request = new WP_REST_Request( 'GET', '/a3-ai/v1/connection-status' );
$response = DEF_Core_Connection_Config::get_connection_status( $request );
assert_eq( 200, $response->get_status(), '11a. Status → 200' );
assert_false( $response->get_data()['def_connected'], '11b. Not connected when no config' );
assert_eq( 0, $response->get_data()['last_config_revision'], '11c. Revision 0' );
assert_eq( null, $response->get_data()['last_sync_at'], '11d. No sync time' );

// 11e. With config → connected.
reset_state();
update_option( 'def_core_staff_ai_api_url', 'https://api.example.com' );
update_option( 'def_core_api_key', 'some-key' );
update_option( 'def_core_conn_config_revision', 3 );
update_option( 'def_core_conn_last_sync_at', '2026-03-07T12:00:00+00:00' );
$response = DEF_Core_Connection_Config::get_connection_status( $request );
assert_true( $response->get_data()['def_connected'], '11e. Connected when config present' );
assert_eq( 3, $response->get_data()['last_config_revision'], '11f. Revision 3' );
assert_eq( '2026-03-07T12:00:00+00:00', $response->get_data()['last_sync_at'], '11g. Sync time returned' );
assert_true( ! empty( $response->get_data()['plugin_version'] ), '11h. Plugin version present' );

// =========================================================================
// 12. Config revision must be >= 1
// =========================================================================

echo "12. Config revision must be >= 1\n";

reset_state();
$request = new WP_REST_Request( 'POST', '/a3-ai/v1/internal/connection-config' );
$request->set_body_params( array(
	'config_revision'     => 0,
	'api_key'             => 'key',
	'service_auth_secret' => 'secret',
) );
$response = DEF_Core_Connection_Config::receive_connection_config( $request );
assert_eq( 422, $response->get_status(), '12a. Revision 0 → 422' );

// =========================================================================
// 13. Connection test transient cleared on push
// =========================================================================

echo "13. Connection test transient cleared on push\n";

reset_state();
set_transient( 'def_core_connection_test', array( 'status' => 'ok' ) );
assert_true( get_transient( 'def_core_connection_test' ) !== false, '13a. Transient exists before push' );

$request = new WP_REST_Request( 'POST', '/a3-ai/v1/internal/connection-config' );
$request->set_body_params( array(
	'config_revision'     => 1,
	'api_key'             => 'key',
	'service_auth_secret' => 'secret',
) );
DEF_Core_Connection_Config::receive_connection_config( $request );
assert_false( get_transient( 'def_core_connection_test' ), '13b. Transient cleared after push' );

// =========================================================================
// 14. Setup Assistant readonly enforcement
// =========================================================================

echo "14. Setup Assistant readonly enforcement\n";

// Load the Setup Assistant class to check setting_allowlist.
// We need the extra stubs it requires.
global $_wp_test_current_user, $_wp_test_user_caps, $_wp_test_user_meta, $_wp_test_users;
$_wp_test_current_user = null;
$_wp_test_user_caps    = array();
$_wp_test_user_meta    = array();
$_wp_test_users        = array();

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

// Verify the allowlist has readonly flag (by reading class source directly).
$source = file_get_contents( DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-admin-api.php' );
assert_true(
	strpos( $source, "'def_core_staff_ai_api_url' => array(" ) !== false
	&& strpos( $source, "'readonly'  => true," ) !== false,
	'14a. def_core_staff_ai_api_url has readonly flag'
);
assert_true(
	strpos( $source, "'def_core_api_key' => array(" ) !== false,
	'14b. def_core_api_key exists in allowlist'
);
// Check readonly enforcement code exists.
assert_true(
	strpos( $source, 'READONLY_SETTING' ) !== false,
	'14c. READONLY_SETTING error code exists'
);

// =========================================================================
// Results
// =========================================================================

echo "\n=== Results ===\n";
echo "Passed: $pass\n";
echo "Failed: $fail\n";

if ( $fail > 0 ) {
	exit( 1 );
}
echo "All connection config tests passed.\n";
