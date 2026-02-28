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
	'a3-ai/v1/staff-ai/share-settings',
	'a3-ai/v1/staff-ai/share-send',
	'a3-ai/v1/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)/share-event',
	'a3-ai/v1/staff-ai/uploads/init',
	'a3-ai/v1/staff-ai/uploads/commit',
	'a3-ai/v1/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)/summarize',
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

// ── 13. Upload init — valid input ─────────────────────────────────────
echo "\n[13] Upload init — valid input\n";
$_wp_test_current_user     = new WP_User( 1 );
$_wp_test_current_user->ID = 1;
$_wp_test_user_caps        = array( 'def_staff_access' );
update_option( 'def_core_staff_ai_api_url', 'http://localhost:8000' );

$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/init' );
$request->set_body_params( array(
	'filename'        => 'test.pdf',
	'mime_type'       => 'application/pdf',
	'size_bytes'      => 1024,
	'conversation_id' => 'thread_abc-123',
) );
// This will try to reach the backend and fail, but we verify pre-validation passes.
// The error should come from backend_request(), not from validation.
$result = DEF_Core_Staff_AI::rest_upload_init( $request );
// If backend is down, it returns WP_Error but NOT from our validation.
if ( is_wp_error( $result ) ) {
	assert_true(
		$result->get_error_code() !== 'invalid_filename'
		&& $result->get_error_code() !== 'unsupported_media_type'
		&& $result->get_error_code() !== 'payload_too_large',
		'valid input does not fail validation'
	);
}

// ── 14. Upload init — empty filename → 400 ───────────────────────────
echo "\n[14] Upload init — empty filename\n";
$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/init' );
$request->set_body_params( array(
	'filename'   => '',
	'mime_type'  => 'application/pdf',
	'size_bytes' => 1024,
) );
$result = DEF_Core_Staff_AI::rest_upload_init( $request );
assert_true( is_wp_error( $result ), 'empty filename returns WP_Error' );
assert_equals( 'invalid_filename', $result->get_error_code(), 'error code is invalid_filename' );
$data = $result->get_error_data();
assert_equals( 400, $data['status'], 'HTTP status is 400' );

// ── 15. Upload init — unsupported MIME → 415 ─────────────────────────
echo "\n[15] Upload init — unsupported MIME type\n";
$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/init' );
$request->set_body_params( array(
	'filename'   => 'malware.exe',
	'mime_type'  => 'application/x-msdownload',
	'size_bytes' => 1024,
) );
$result = DEF_Core_Staff_AI::rest_upload_init( $request );
assert_true( is_wp_error( $result ), 'unsupported MIME returns WP_Error' );
assert_equals( 'unsupported_media_type', $result->get_error_code(), 'error code is unsupported_media_type' );
$data = $result->get_error_data();
assert_equals( 415, $data['status'], 'HTTP status is 415' );

// ── 16. Upload init — file too large → 413 ───────────────────────────
echo "\n[16] Upload init — file too large\n";
$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/init' );
$request->set_body_params( array(
	'filename'   => 'big.pdf',
	'mime_type'  => 'application/pdf',
	'size_bytes' => 20000000, // 20MB, over 10MB limit.
) );
$result = DEF_Core_Staff_AI::rest_upload_init( $request );
assert_true( is_wp_error( $result ), 'too-large file returns WP_Error' );
assert_equals( 'payload_too_large', $result->get_error_code(), 'error code is payload_too_large' );
$data = $result->get_error_data();
assert_equals( 413, $data['status'], 'HTTP status is 413' );

// ── 17. Upload init — zero size → 413 ────────────────────────────────
echo "\n[17] Upload init — zero size\n";
$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/init' );
$request->set_body_params( array(
	'filename'   => 'empty.pdf',
	'mime_type'  => 'application/pdf',
	'size_bytes' => 0,
) );
$result = DEF_Core_Staff_AI::rest_upload_init( $request );
assert_true( is_wp_error( $result ), 'zero size returns WP_Error' );
assert_equals( 'payload_too_large', $result->get_error_code(), 'zero size triggers payload_too_large' );

// ── 18. Upload commit — valid file_id ─────────────────────────────────
echo "\n[18] Upload commit — valid file_id\n";
$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/commit' );
$request->set_body_params( array( 'file_id' => 'upload_abc123def456' ) );
$result = DEF_Core_Staff_AI::rest_upload_commit( $request );
// Valid file_id passes validation — error comes from backend_request, not validation.
if ( is_wp_error( $result ) ) {
	assert_true(
		$result->get_error_code() !== 'invalid_file_id',
		'valid file_id does not fail validation'
	);
}

// ── 19. Upload commit — empty file_id → 400 ──────────────────────────
echo "\n[19] Upload commit — empty file_id\n";
$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/commit' );
$request->set_body_params( array( 'file_id' => '' ) );
$result = DEF_Core_Staff_AI::rest_upload_commit( $request );
assert_true( is_wp_error( $result ), 'empty file_id returns WP_Error' );
assert_equals( 'invalid_file_id', $result->get_error_code(), 'error code is invalid_file_id' );
$data = $result->get_error_data();
assert_equals( 400, $data['status'], 'HTTP status is 400' );

// ── 20. Upload commit — malformed file_id → 400 ──────────────────────
echo "\n[20] Upload commit — malformed file_id\n";
$malformed_ids = array(
	'not_a_file_id',          // wrong prefix
	'upload_',                // no hex part
	'upload_ABCDEF',          // uppercase hex (we require lowercase)
	'upload_abc123; rm -rf /', // injection attempt
	'../etc/passwd',          // path traversal
	'upload_<script>alert(1)</script>', // XSS attempt
);
foreach ( $malformed_ids as $bad_id ) {
	$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/commit' );
	$request->set_body_params( array( 'file_id' => $bad_id ) );
	$result = DEF_Core_Staff_AI::rest_upload_commit( $request );
	assert_true( is_wp_error( $result ), "malformed file_id '$bad_id' returns WP_Error" );
	assert_equals( 'invalid_file_id', $result->get_error_code(), "malformed file_id '$bad_id' code is invalid_file_id" );
}

// ── 21. Upload routes are POST ────────────────────────────────────────
echo "\n[21] Upload route methods\n";
assert_equals( 'POST', $_wp_test_rest_routes['a3-ai/v1/staff-ai/uploads/init']['methods'], 'uploads/init = POST' );
assert_equals( 'POST', $_wp_test_rest_routes['a3-ai/v1/staff-ai/uploads/commit']['methods'], 'uploads/commit = POST' );

// ── 22. Chat with file_ids — empty message allowed ────────────────────
echo "\n[22] Chat with file_ids — empty message allowed\n";
update_option( 'def_core_staff_ai_api_url', 'http://localhost:8000' );
$request = new WP_REST_Request( 'POST', '/staff-ai/chat' );
$request->set_body_params( array(
	'message'  => '',
	'file_ids' => array( 'upload_abc123' ),
) );
$result = DEF_Core_Staff_AI::rest_send_message( $request );
// Should NOT be invalid_message since files are present.
if ( is_wp_error( $result ) ) {
	assert_true(
		$result->get_error_code() !== 'invalid_message',
		'empty message with file_ids does not trigger invalid_message'
	);
}

// ── 23. Chat with invalid file_ids silently dropped ───────────────────
echo "\n[23] Chat with invalid file_ids — silently dropped\n";
$request = new WP_REST_Request( 'POST', '/staff-ai/chat' );
$request->set_body_params( array(
	'message'  => '',
	'file_ids' => array( 'bad_id', '../etc/passwd' ),
) );
$result = DEF_Core_Staff_AI::rest_send_message( $request );
// All file_ids are invalid → no valid files → empty message → invalid_message.
assert_true( is_wp_error( $result ), 'all-invalid file_ids with empty message returns WP_Error' );
assert_equals( 'invalid_message', $result->get_error_code(), 'invalid file_ids dropped, falls back to empty-message check' );

// ── 24. Upload init — spoofed MIME type (security) ────────────────────
echo "\n[24] Upload init — spoofed MIME type (security: V1.1 G4)\n";
// Attacker renames .exe to .pdf but claims application/x-msdownload.
$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/init' );
$request->set_body_params( array(
	'filename'   => 'report.pdf',
	'mime_type'  => 'application/x-msdownload',
	'size_bytes' => 2048,
) );
$result = DEF_Core_Staff_AI::rest_upload_init( $request );
assert_true( is_wp_error( $result ), 'spoofed MIME rejected' );
assert_equals( 'unsupported_media_type', $result->get_error_code(), 'spoofed MIME returns unsupported_media_type' );

// Also: valid filename extension but unsupported MIME (e.g., application/octet-stream).
$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/init' );
$request->set_body_params( array(
	'filename'   => 'photo.png',
	'mime_type'  => 'application/octet-stream',
	'size_bytes' => 5000,
) );
$result = DEF_Core_Staff_AI::rest_upload_init( $request );
assert_true( is_wp_error( $result ), 'octet-stream MIME rejected' );
assert_equals( 'unsupported_media_type', $result->get_error_code(), 'octet-stream MIME returns unsupported_media_type' );

// ── 25. Upload init — boundary size values ────────────────────────────
echo "\n[25] Upload init — boundary size values\n";
// Exactly at the 10MB limit — should pass validation.
$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/init' );
$request->set_body_params( array(
	'filename'   => 'exact.pdf',
	'mime_type'  => 'application/pdf',
	'size_bytes' => 10485760, // Exactly 10MB.
) );
$result = DEF_Core_Staff_AI::rest_upload_init( $request );
if ( is_wp_error( $result ) ) {
	assert_true(
		$result->get_error_code() !== 'payload_too_large',
		'exactly 10MB passes size validation'
	);
}

// 1 byte over the limit — should fail.
$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/init' );
$request->set_body_params( array(
	'filename'   => 'over.pdf',
	'mime_type'  => 'application/pdf',
	'size_bytes' => 10485761, // 10MB + 1 byte.
) );
$result = DEF_Core_Staff_AI::rest_upload_init( $request );
assert_true( is_wp_error( $result ), '10MB+1 returns WP_Error' );
assert_equals( 'payload_too_large', $result->get_error_code(), '10MB+1 triggers payload_too_large' );

// ── 26. All allowed MIME types accepted ───────────────────────────────
echo "\n[26] All allowed MIME types accepted\n";
$allowed = array(
	'image/png', 'image/jpeg', 'image/gif', 'image/webp',
	'application/pdf', 'text/plain', 'text/markdown', 'text/csv',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
);
foreach ( $allowed as $mime ) {
	$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/init' );
	$request->set_body_params( array(
		'filename'   => 'test-file.dat',
		'mime_type'  => $mime,
		'size_bytes' => 1024,
	) );
	$result = DEF_Core_Staff_AI::rest_upload_init( $request );
	if ( is_wp_error( $result ) ) {
		assert_true(
			$result->get_error_code() !== 'unsupported_media_type',
			"MIME '$mime' accepted by validation"
		);
	} else {
		assert_true( true, "MIME '$mime' accepted by validation" );
	}
}

// Cleanup.
$_wp_test_current_user = null;
$_wp_test_user_caps    = array();

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n--- Staff AI Tests: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
