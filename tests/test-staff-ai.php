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

// Staff AI resolves the backend URL via \DEF_Core::get_def_api_url_internal();
// stub it so backend_request() reaches the (stubbed) wp_remote_* layer instead
// of fataling on a missing DEF_Core class.
if ( ! class_exists( 'DEF_Core' ) ) {
	class DEF_Core {
		public static function get_def_api_url_internal(): ?string {
			return $GLOBALS['_def_test_api_url'] ?? 'https://def-api.test';
		}
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
	'a3-ai/v1/staff-ai/documents',
	'a3-ai/v1/staff-ai/documents/(?P<id>[a-zA-Z0-9-]+)',
	'a3-ai/v1/staff-ai/memories',
	'a3-ai/v1/staff-ai/memories/(?P<id>[a-zA-Z0-9-]+)',
	'a3-ai/v1/staff-ai/triage-schedule',
	'a3-ai/v1/staff-ai/triage-schedule/run-now',
	// Free-text scheduled tasks (Phase 3) — per-user like the triage routes,
	// but a user holds MANY, so the item routes carry a task id.
	'a3-ai/v1/staff-ai/tasks',
	'a3-ai/v1/staff-ai/tasks/(?P<task_id>[A-Za-z0-9-]{1,64})',
	'a3-ai/v1/staff-ai/tasks/(?P<task_id>[A-Za-z0-9-]{1,64})/run-now',
	// C2 — the user's own disconnect. Destructive and per-user, so it is pinned even
	// though its authorize sibling predates this convention.
	'a3-ai/v1/staff-ai/user/integrations/(?P<server_id>[a-zA-Z0-9_-]+)/disconnect',
);

foreach ( $expected_routes as $route ) {
	assert_true(
		isset( $_wp_test_rest_routes[ $route ] ),
		"route registered: $route"
	);
}

// 2. All Staff AI routes have permission callbacks. A route registered with a
// single handler has permission_callback at the top level; a multi-method
// route (e.g. GET+POST /content/targets) is a list of handlers, each of which
// must carry its own.
echo "\n[2] Permission callbacks assigned\n";
foreach ( $_wp_test_rest_routes as $route => $args ) {
	if ( strpos( $route, 'staff-ai' ) === false ) {
		continue;
	}
	$handlers = isset( $args['methods'] ) ? array( $args ) : $args;
	foreach ( $handlers as $i => $handler ) {
		assert_true(
			is_array( $handler ) && ! empty( $handler['permission_callback'] ),
			"permission_callback on $route (handler $i)"
		);
	}
}

// 3. Methods are correct (GET vs POST).
echo "\n[3] HTTP methods\n";
assert_equals( 'GET', $_wp_test_rest_routes['a3-ai/v1/staff-ai/conversations']['methods'], 'conversations = GET' );
assert_equals( 'POST', $_wp_test_rest_routes['a3-ai/v1/staff-ai/chat']['methods'], 'chat = POST' );
assert_equals( 'GET', $_wp_test_rest_routes['a3-ai/v1/staff-ai/tools']['methods'], 'tools = GET' );
assert_equals( 'GET', $_wp_test_rest_routes['a3-ai/v1/staff-ai/status']['methods'], 'status = GET' );
assert_equals( 'GET', $_wp_test_rest_routes['a3-ai/v1/staff-ai/documents']['methods'], 'documents = GET' );
assert_equals( 'DELETE', $_wp_test_rest_routes['a3-ai/v1/staff-ai/documents/(?P<id>[a-zA-Z0-9-]+)']['methods'], 'documents delete = DELETE' );
assert_equals( 'GET', $_wp_test_rest_routes['a3-ai/v1/staff-ai/memories']['methods'], 'memories = GET' );
assert_equals( 'DELETE', $_wp_test_rest_routes['a3-ai/v1/staff-ai/memories/(?P<id>[a-zA-Z0-9-]+)']['methods'], 'memories delete = DELETE' );
$_sched_handlers = $_wp_test_rest_routes['a3-ai/v1/staff-ai/triage-schedule'];
assert_equals( 'GET', $_sched_handlers[0]['methods'] ?? '', 'triage-schedule handler 0 = GET' );
assert_equals( 'PUT', $_sched_handlers[1]['methods'] ?? '', 'triage-schedule handler 1 = PUT' );
assert_equals( 'DELETE', $_sched_handlers[2]['methods'] ?? '', 'triage-schedule handler 2 = DELETE' );
// Run Now is a POST with NO body: the run belongs to the logged-in caller and
// DEF resolves that from the forwarded identity, so there is no field here that
// could name someone else's mailbox.
$_run_now = $_wp_test_rest_routes['a3-ai/v1/staff-ai/triage-schedule/run-now'];
assert_equals( 'POST', $_run_now[0]['methods'] ?? '', 'triage run-now = POST' );
assert_true(
	is_array( $_run_now[0]['permission_callback'] ?? null ),
	'triage run-now carries a permission callback'
);
$_tasks_handlers = $_wp_test_rest_routes['a3-ai/v1/staff-ai/tasks'];
assert_equals( 'GET', $_tasks_handlers[0]['methods'] ?? '', 'tasks handler 0 = GET' );
assert_equals( 'POST', $_tasks_handlers[1]['methods'] ?? '', 'tasks handler 1 = POST' );
$_task_item = $_wp_test_rest_routes['a3-ai/v1/staff-ai/tasks/(?P<task_id>[A-Za-z0-9-]{1,64})'];
assert_equals( 'PUT', $_task_item[0]['methods'] ?? '', 'task item handler 0 = PUT' );
assert_equals( 'DELETE', $_task_item[1]['methods'] ?? '', 'task item handler 1 = DELETE' );
$_task_run_now = $_wp_test_rest_routes['a3-ai/v1/staff-ai/tasks/(?P<task_id>[A-Za-z0-9-]{1,64})/run-now'];
assert_equals( 'POST', $_task_run_now[0]['methods'] ?? '', 'task run-now = POST' );

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
// 5.7.7: the capability refusal carries a DISTINCT named code so callers can
// tell the gate from every other 403 (was the generic rest_forbidden).
assert_equals( 'def_staff_access_required', $result->get_error_code(), 'error code is def_staff_access_required' );
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

// ── 12. File download REST twin stays deleted ────────────────────────────
// 5.6.3: rest_download_file was removed — it still carried the retired JWT
// auth (DEF 401s it), had no product caller (downloads ride the
// /staff-ai-download/ rewrite), and a live-but-inert second download door
// invites an unreviewed "fix". Downloads must have exactly ONE route.
echo "\n[12] File download REST twin stays deleted\n";
$file_route = 'a3-ai/v1/staff-ai/files/(?P<tenant>[^/]+)/(?P<filename>.+)';
assert_true( ! isset( $_wp_test_rest_routes[ $file_route ] ), 'REST file-download twin is NOT registered' );

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
		&& $result->get_error_code() !== 'invalid_size',
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

// ── 16. (moved) — the no-client-ceiling + BFF-legibility block needs the
// success-path stubs first defined at section 27, so it now runs as
// section 31 at the end of this file.

// ── 17. Upload init — zero size → 400 invalid_size ───────────────────
// Malformed is not a ration: the zero/negative check stays, as its own code.
echo "\n[17] Upload init — zero size\n";
$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/init' );
$request->set_body_params( array(
	'filename'   => 'empty.pdf',
	'mime_type'  => 'application/pdf',
	'size_bytes' => 0,
) );
$result = DEF_Core_Staff_AI::rest_upload_init( $request );
assert_true( is_wp_error( $result ), 'zero size returns WP_Error' );
assert_equals( 'invalid_size', $result->get_error_code(), 'zero size is refused as malformed (400), not as over-limit' );

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
// (boundary probes for the deleted 10MB ceiling moved to section 31 —
// they reach backend_request, which needs the section-27 stubs.)

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

// ── 27. rest_list_documents — q forwarding to DEF ───────────────────────
// Success-path stubs live here: every earlier section exercises error paths
// only, so the wp_remote_* layer is first defined at this point (guarded).
echo "\n[27] rest_list_documents q= forwarding\n";

// The REAL DEF_Core_Encryption is loaded by wp-stubs.php; its get_secret
// legacy-plaintext path returns a seeded option value as-is.
$_wp_test_options['def_core_api_key'] = 'test-api-key';

if ( ! class_exists( 'DEF_Core_Tools' ) ) {
	class DEF_Core_Tools {
		public static function get_user_def_capabilities( $user ): array { return array( 'def_staff_access' ); }
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string { return ''; }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		$GLOBALS['_def_test_last_get_url'] = $url;
		return array(
			// Code is settable so a backend failure (e.g. DEF's 503) can be driven.
			'response' => array( 'code' => $GLOBALS['_def_test_get_code'] ?? 200 ),
			'body'     => $GLOBALS['_def_test_get_body'] ?? '{"success":true,"documents":[]}',
		);
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
$_wp_test_current_user     = new WP_User( 1 );
$_wp_test_current_user->ID = 1;

$req = new WP_REST_Request();
$req->set_param( 'q', '  Progress: 100%  ' );
DEF_Core_Staff_AI::rest_list_documents( $req );
assert_equals(
	'https://def-api.test/api/staff-ai/documents?q=Progress%3A%20100%25',
	$GLOBALS['_def_test_last_get_url'] ?? '',
	'q is trimmed + rawurlencoded onto the DEF path'
);

$GLOBALS['_def_test_last_get_url'] = '';
$req = new WP_REST_Request();
$req->set_param( 'q', '   ' );
DEF_Core_Staff_AI::rest_list_documents( $req );
assert_equals(
	'https://def-api.test/api/staff-ai/documents',
	$GLOBALS['_def_test_last_get_url'] ?? '',
	'whitespace-only q adds no query string'
);

// ── 28. rest_list_documents — download_url rewrite normalizes BOTH DEF forms ──
// DEF emits download_url raw (pre-#836) or single-encoded (post-#836). WP
// matches the pretty URL against the RAW request URI, and handle_file_download
// urldecode()s exactly once — so the rewrite must emit exactly ONE encode for
// either input form (normalize-then-encode; version-skew-safe both directions).
echo "\n[28] rest_list_documents download_url normalize-then-encode\n";

function _def_test_panel_download_url( string $backend_download_url ): string {
	$GLOBALS['_def_test_get_body'] = json_encode( array(
		'success'   => true,
		'documents' => array( array(
			'document_id'  => 'abc123-def',
			'title'        => 'T',
			'file_type'    => 'md',
			'version'      => 1,
			'size_bytes'   => 10,
			'created_at'   => '2026-08-05T00:00:00',
			'download_url' => $backend_download_url,
		) ),
	) );
	$resp = DEF_Core_Staff_AI::rest_list_documents( new WP_REST_Request() );
	unset( $GLOBALS['_def_test_get_body'] );
	$data = is_object( $resp ) ? $resp->data : $resp;
	return $data['documents'][0]['download_url'] ?? '';
}

assert_equals(
	'https://test.example.com/staff-ai-download/tenant-a/My%20Report.md',
	_def_test_panel_download_url( '/api/files/tenant-a/My Report.md' ),
	'raw DEF form (pre-#836) → single-encoded'
);
assert_equals(
	'https://test.example.com/staff-ai-download/tenant-a/My%20Report.md',
	_def_test_panel_download_url( '/api/files/tenant-a/My%20Report.md' ),
	'encoded DEF form (post-#836) → still single-encoded, no double-encode'
);
assert_equals(
	'https://test.example.com/staff-ai-download/tenant-a/100%25%20Done.md',
	_def_test_panel_download_url( '/api/files/tenant-a/100% Done.md' ),
	'literal % in a raw name survives normalization (invalid %-sequence passes through rawurldecode)'
);

// ── 29. rest_list_memories — field allowlist round trip ─────────────────
// The proxy remaps field by field, so an unlisted field vanishes silently and
// the panel renders a blank column with no error anywhere. DEF returns exactly
// entry_id / category / content / created_at / updated_at, and deliberately
// withholds confidence, source and provenance — the allowlist is what keeps
// them out if a future backend adds them back.
echo "\n[29] rest_list_memories field allowlist\n";

function _def_test_memories( array $rows ): array {
	$GLOBALS['_def_test_get_body'] = json_encode( array( 'success' => true, 'memories' => $rows ) );
	$resp = DEF_Core_Staff_AI::rest_list_memories();
	unset( $GLOBALS['_def_test_get_body'] );
	$data = is_object( $resp ) ? $resp->data : $resp;
	return $data['memories'] ?? array();
}

$rows = _def_test_memories( array(
	array(
		'entry_id'         => '8f14e45f-ceea-467a-9e2f-6b2c40f5e1a3',
		'category'         => 'preferences',
		'content'          => 'Prefers short answers',
		'created_at'       => '2026-08-07T01:00:00',
		'updated_at'       => '2026-08-08T02:00:00',
		'confidence'       => 0.9,
		'source'           => 'extracted',
		'source_thread_id' => 'thread-123',
		'staleness_score'  => 0.4,
	),
) );
assert_equals( 1, count( $rows ), 'a valid row survives' );
$keys = array_keys( $rows[0] );
sort( $keys );
assert_equals(
	array( 'category', 'content', 'created_at', 'entry_id', 'updated_at' ),
	$keys,
	'exactly the five DEF fields — no extras (order not pinned, nothing depends on it)'
);
assert_equals( 'Prefers short answers', $rows[0]['content'], 'content passes through' );
assert_equals( 'preferences', $rows[0]['category'], 'category passes through' );
assert_equals( '2026-08-08T02:00:00', $rows[0]['updated_at'], 'updated_at passes through' );

// The panel's DELETE route only matches [a-zA-Z0-9-]+, so a row whose id the
// route could never accept is dropped rather than rendered undeletable.
assert_equals(
	0,
	count( _def_test_memories( array( array( 'entry_id' => 'not$a/uuid', 'content' => 'x' ) ) ) ),
	'row with an unroutable entry_id is skipped'
);
assert_equals(
	0,
	count( _def_test_memories( array( array( 'category' => 'role', 'content' => 'x' ) ) ) ),
	'row with no entry_id is skipped'
);
$rows = _def_test_memories( array( array( 'entry_id' => 'abc-123', 'category' => array( 'role' ) ) ) );
assert_equals( '', $rows[0]['category'], 'non-string category coerces to empty, not to a PHP array' );

// ── 30. rest_list_memories — a backend failure is an ERROR, not an empty list ──
// DEF answers 503 when the store is unreachable. An empty list would read as
// "it has forgotten you" — a lie about the user's own data.
echo "\n[30] rest_list_memories 503 surfaces as an error\n";

$GLOBALS['_def_test_get_code'] = 503;
$result = DEF_Core_Staff_AI::rest_list_memories();
unset( $GLOBALS['_def_test_get_code'] );
$is_err = is_wp_error( $result );
assert_true( $is_err, '503 returns WP_Error, not a 200 response' );
// Guarded: a mutation that returns a response here must FAIL these two, not
// fatal on ->get_error_data() and take every later section down with it.
assert_equals( 503, $is_err ? ( $result->get_error_data()['status'] ?? 0 ) : 0, 'status 503 is preserved for the panel' );
// The 5xx branch of backend_request carries no URL, so it reaches the user as-is.
assert_true(
	$is_err && strpos( $result->get_error_message(), 'def-api.test' ) === false,
	'503 message does not leak the backend URL'
);

// A 404 means DEF does not serve this route (version skew / rollback), and that
// branch of backend_request interpolates the INTERNAL backend URL. The panel
// renders the message verbatim, so it must be replaced — while staying an error.
$GLOBALS['_def_test_get_code'] = 404;
$result = DEF_Core_Staff_AI::rest_list_memories();
unset( $GLOBALS['_def_test_get_code'] );
$is_err = is_wp_error( $result );
assert_true( $is_err, '404 stays an error, not an empty list' );
assert_equals( 404, $is_err ? ( $result->get_error_data()['status'] ?? 0 ) : 0, '404 status preserved' );
assert_true(
	strpos( $is_err ? $result->get_error_message() : '', 'def-api.test' ) === false,
	'404 message does not leak the internal backend URL'
);

// ── 31. rest_delete_memory — id forwarding + plain copy on 404 / 409 ────
echo "\n[31] rest_delete_memory\n";

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( string $url, array $args = array() ) {
		$GLOBALS['_def_test_last_request'] = array( 'url' => $url, 'method' => $args['method'] ?? '', 'body' => $args['body'] ?? '' );
		return array(
			'response' => array( 'code' => $GLOBALS['_def_test_request_code'] ?? 200 ),
			'body'     => $GLOBALS['_def_test_request_body'] ?? '{"success":true}',
		);
	}
}

$req = new WP_REST_Request();
$req->set_param( 'id', '8f14e45f-ceea-467a-9e2f-6b2c40f5e1a3' );
$resp = DEF_Core_Staff_AI::rest_delete_memory( $req );
assert_equals(
	'https://def-api.test/api/staff-ai/memories/8f14e45f-ceea-467a-9e2f-6b2c40f5e1a3',
	$GLOBALS['_def_test_last_request']['url'] ?? '',
	'entry id is forwarded on the DEF path — nothing user-scoped alongside it'
);
assert_equals( 'DELETE', $GLOBALS['_def_test_last_request']['method'] ?? '', 'verb is DELETE' );
assert_true( ! is_wp_error( $resp ), 'a successful delete returns a response' );

$result = DEF_Core_Staff_AI::rest_delete_memory( new WP_REST_Request() );
$is_err = is_wp_error( $result );
assert_true( $is_err, 'missing id is rejected' );
assert_equals( 'invalid_memory_id', $is_err ? $result->get_error_code() : '', 'missing id → invalid_memory_id' );

// get_param() resolves JSON → POST → GET → URL, so a query string outranks the
// path segment the route regex matched: an id that never passed the pattern can
// still reach get_param(). The handler must charset-check it itself.
$GLOBALS['_def_test_last_request'] = array();
foreach ( array( '../../anything', 'abc 123', 'abc/def', '' ) as $bad_id ) {
	$req = new WP_REST_Request();
	$req->set_param( 'id', $bad_id );
	$result = DEF_Core_Staff_AI::rest_delete_memory( $req );
	$is_err = is_wp_error( $result );
	assert_equals( 'invalid_memory_id', $is_err ? $result->get_error_code() : '', "id '$bad_id' is refused by the handler" );
}
assert_equals(
	array(),
	$GLOBALS['_def_test_last_request'],
	'no refused id ever reached the backend'
);

// A vanished id and a concurrent-change 409 are both normal panel traffic, and
// both must return plain copy — backend_request's diagnostic string carries the
// internal backend URL.
foreach ( array( 404 => 'staff_ai_not_found', 409 => 'staff_ai_conflict' ) as $code => $expected_error ) {
	$GLOBALS['_def_test_request_code'] = $code;
	$req = new WP_REST_Request();
	$req->set_param( 'id', 'abc-123' );
	$result = DEF_Core_Staff_AI::rest_delete_memory( $req );
	unset( $GLOBALS['_def_test_request_code'] );
	$is_err = is_wp_error( $result );
	assert_true( $is_err, "$code returns WP_Error" );
	assert_equals( $expected_error, $is_err ? $result->get_error_code() : '', "$code → $expected_error" );
	assert_equals( $code, $is_err ? ( $result->get_error_data()['status'] ?? 0 ) : 0, "$code status preserved" );
	assert_true(
		strpos( $is_err ? $result->get_error_message() : '', 'def-api.test' ) === false,
		"$code message does not leak the backend URL"
	);
}

// Cleanup.
$_wp_test_current_user = null;
$_wp_test_user_caps    = array();

// ── 31. Upload init — NO client-side size ceiling (5.7.11) ───────────
// The 10MB UPLOAD_MAX_SIZE_BYTES twin was deleted: the server's env-tunable
// UPLOAD_MAX_FILE_MB is the one ceiling, and its refusal must surface
// LEGIBLY through the BFF reshaping seam — asserted on the surfaced
// message, not just the status (the ten-bounds rider). Runs here because
// the success-path stubs (wp_remote_request, DEF_Core_Tools) exist from
// section 27 on.
echo "\n[31] Upload init — over-size reaches the server; the refusal surfaces legibly\n";

$_wp_test_options['def_core_api_key'] = 'test-api-key';
$_wp_test_current_user     = new WP_User( 1 );
$_wp_test_current_user->ID = 1;

// (a) def-core no longer refuses: a 20MB init that used to 413 client-side
// now succeeds end-to-end against a healthy backend stub.
$GLOBALS['_def_test_request_code'] = 200;
$GLOBALS['_def_test_request_body'] = '{"success":true,"file_id":"upload_ok"}';
$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/init' );
$request->set_body_params( array(
	'filename'   => 'big.pdf',
	'mime_type'  => 'application/pdf',
	'size_bytes' => 20000000, // 20MB — was refused client-side before 5.7.11.
) );
$result = DEF_Core_Staff_AI::rest_upload_init( $request );
assert_true( ! is_wp_error( $result ), '20MB init passes def-core — no client-side ceiling' );

// (b) the deleted boundary: 10MB and 10MB+1 are indistinguishable to def-core.
foreach ( array( 10485760, 10485761 ) as $boundary_probe ) {
	$request = new WP_REST_Request( 'POST', '/staff-ai/uploads/init' );
	$request->set_body_params( array(
		'filename'   => 'probe.pdf',
		'mime_type'  => 'application/pdf',
		'size_bytes' => $boundary_probe,
	) );
	$result = DEF_Core_Staff_AI::rest_upload_init( $request );
	assert_true( ! is_wp_error( $result ), "size $boundary_probe passes def-core (no boundary at the old 10MB)" );
}

// (c) BFF legibility, pinned against the LIVE PRODUCER's shapes — not an
// invented one. DEF's real over-size refusal on this path is HTTP 400 with a
// DICT detail (upload_routes.py:238-244; DEF has no 413 here). The first
// version of this pin stubbed 413 + a string detail — a shape DEF never
// produces — and stayed green while the real dict stringified to "Array"
// (#277 security leg). The wire-the-live-producer lesson, applied to itself.
$GLOBALS['_def_test_request_code'] = 400;
$GLOBALS['_def_test_request_body'] = '{"detail":{"error":"validation_failed","message":"File size exceeds maximum of 50.0MB"}}';
$result = DEF_Core_Staff_AI::rest_upload_init( $request );
assert_true( is_wp_error( $result ), 'server 400 surfaces as an error' );
assert_equals( 'staff_ai_http_400', $result->get_error_code(), 'the 400 keeps its identity through the seam' );
$data = $result->get_error_data();
assert_equals( 400, $data['status'], 'HTTP status stays 400' );
$msg = $result->get_error_message();
assert_true(
	false !== strpos( $msg, 'File size exceeds maximum of 50.0MB' ),
	"the SERVER's dict message reaches the user — the seam is legible, not just green"
);
assert_true( false === strpos( $msg, 'Array' ), 'the dict never stringifies to "Array"' );
assert_true( false === strpos( $msg, 'def-api.test' ), 'the internal DEF URL is not shown to the user' );

// (d) the 422-list shape (Pydantic) — DEF's count/validation refusals: the
// entry msgs flatten into the user-facing message.
$GLOBALS['_def_test_request_code'] = 422;
$GLOBALS['_def_test_request_body'] = '{"detail":[{"loc":["body","urls"],"msg":"ensure this value has at most 5 items","type":"value_error.list.max_items"}]}';
$result = DEF_Core_Staff_AI::rest_upload_init( $request );
assert_true( is_wp_error( $result ), 'server 422 surfaces as an error' );
$msg = $result->get_error_message();
assert_true(
	false !== strpos( $msg, 'ensure this value has at most 5 items' ),
	'a Pydantic 422 list flattens to its msg strings'
);
assert_true( false === strpos( $msg, 'Array' ), 'the list never stringifies to "Array"' );
$GLOBALS['_def_test_request_code'] = 200;
$GLOBALS['_def_test_request_body'] = '{"success":true}';

// ── Triage schedule (S4b): GET allowlist, 503-honesty, PUT full-replace ──
// The card is the Staff-AI channel's first per-user WRITE-back settings
// surface; the shapes pinned here are the ones the next such route will copy.
echo "\n[TS-1] rest_get_triage_schedule field allowlist\n";

$GLOBALS['_def_test_get_body'] = json_encode( array(
	'success'  => true,
	'schedule' => array(
		'enabled'           => true,
		'send_hour_local'   => 7,
		'send_minute_local' => 30,
		'timezone'          => 'Australia/Brisbane',
		'destinations'      => array( 'email', 'slack', 'carrier-pigeon' ),
		'internal_field'    => 'must-not-pass',
	),
) );
$resp = DEF_Core_Staff_AI::rest_get_triage_schedule();
unset( $GLOBALS['_def_test_get_body'] );
$data     = is_object( $resp ) ? $resp->data : $resp;
$schedule = $data['schedule'] ?? array();
$keys     = array_keys( $schedule );
sort( $keys );
assert_equals(
	array( 'connected_account_id', 'destinations', 'enabled', 'exists', 'search_correspondent_mail', 'send_hour_local', 'send_minute_local', 'timezone' ),
	$keys,
	'exactly the eight schedule fields - no extras'
);
// 6-A: DEF omitted the field (a row written before the column existed). ABSENT
// MEANS ON at every layer, so the box renders ticked and the next full-replace
// save preserves it. Reading absence as off here would silently disable the
// correspondent brief on any schedule whose owner merely edited a send time.
assert_true( true === $schedule['search_correspondent_mail'], 'an absent mail-search flag reads as ON' );
// And the other direction, which absent-means-ON alone does not pin: a mirror
// hardcoded to true passes every assertion above. Then a user who unticks the box
// reopens the form to find it ticked, and their next send-time edit full-replaces
// ON over the stored OFF - this PR's own governing failure, on the read side.
$GLOBALS['_def_test_get_body'] = json_encode( array( 'success' => true, 'schedule' => array(
	'enabled' => true, 'send_hour_local' => 7, 'send_minute_local' => 0,
	'timezone' => 'UTC', 'destinations' => array( 'email' ),
	'search_correspondent_mail' => false,
) ) );
$off = DEF_Core_Staff_AI::rest_get_triage_schedule();
unset( $GLOBALS['_def_test_get_body'] );
$off = ( is_object( $off ) ? $off->data : $off )['schedule'] ?? array();
assert_true( false === $off['search_correspondent_mail'], 'a stored OFF reads as OFF' );
assert_equals( array( 'email', 'slack' ), $schedule['destinations'], 'unknown destination types are dropped' );
assert_equals( 'Australia/Brisbane', $schedule['timezone'], 'timezone passes through' );
assert_equals( 30, $schedule['send_minute_local'], 'minute passes through' );

echo "\n[TS-2] rest_get_triage_schedule 503 surfaces as an error, never defaults\n";
$GLOBALS['_def_test_get_code'] = 503;
$result = DEF_Core_Staff_AI::rest_get_triage_schedule();
unset( $GLOBALS['_def_test_get_code'] );
assert_true( is_wp_error( $result ), 'a backend 503 is an error, not disabled-defaults' );
$msg = is_wp_error( $result ) ? $result->get_error_message() : '';
assert_true( false === strpos( $msg, 'def-api.test' ), 'the internal DEF URL is not shown to the user' );

echo "\n[TS-3] rest_put_triage_schedule refuses bad shapes before the backend\n";
$GLOBALS['_def_test_last_request'] = array();
$bad_bodies = array(
	'missing enabled'   => array( 'send_hour_local' => 7, 'send_minute_local' => 0, 'timezone' => 'UTC', 'destinations' => array( 'email' ) ),
	'hour out of range' => array( 'enabled' => true, 'send_hour_local' => 24, 'send_minute_local' => 0, 'timezone' => 'UTC', 'destinations' => array( 'email' ) ),
	'minute not int'    => array( 'enabled' => true, 'send_hour_local' => 7, 'send_minute_local' => 'zero', 'timezone' => 'UTC', 'destinations' => array( 'email' ) ),
	'timezone too long' => array( 'enabled' => true, 'send_hour_local' => 7, 'send_minute_local' => 0, 'timezone' => str_repeat( 'x', 65 ), 'destinations' => array( 'email' ) ),
	'unknown dest'      => array( 'enabled' => true, 'send_hour_local' => 7, 'send_minute_local' => 0, 'timezone' => 'UTC', 'destinations' => array( 'webhook' ) ),
	'empty dests'       => array( 'enabled' => true, 'send_hour_local' => 7, 'send_minute_local' => 0, 'timezone' => 'UTC', 'destinations' => array() ),
	// Phase 4a: shape-only door - DEF's run owns ownership fail-closed.
	'binding too long'  => array( 'enabled' => true, 'send_hour_local' => 7, 'send_minute_local' => 0, 'timezone' => 'UTC', 'destinations' => array( 'email' ), 'connected_account_id' => str_repeat( 'x', 65 ) ),
	'binding not str'   => array( 'enabled' => true, 'send_hour_local' => 7, 'send_minute_local' => 0, 'timezone' => 'UTC', 'destinations' => array( 'email' ), 'connected_account_id' => 123 ),
	// 6-A: absent is defaulted ON, but present-and-not-boolean is refused - never coerced.
	'search flag not bool' => array( 'enabled' => true, 'send_hour_local' => 7, 'send_minute_local' => 0, 'timezone' => 'UTC', 'destinations' => array( 'email' ), 'search_correspondent_mail' => 'yes' ),
);
foreach ( $bad_bodies as $label => $body ) {
	$req = new WP_REST_Request();
	foreach ( $body as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$result = DEF_Core_Staff_AI::rest_put_triage_schedule( $req );
	$is_err = is_wp_error( $result );
	assert_equals( 'def_triage_schedule_invalid', $is_err ? $result->get_error_code() : '', "PUT refused: $label" );
}
assert_equals( array(), $GLOBALS['_def_test_last_request'], 'no refused body ever reached the backend' );

echo "\n[TS-4] rest_put_triage_schedule forwards the FULL object (full-replace)\n";
$GLOBALS['_def_test_request_body'] = json_encode( array(
	'success'  => true,
	'schedule' => array(
		'enabled'           => true,
		'send_hour_local'   => 7,
		'send_minute_local' => 0,
		'timezone'          => 'Australia/Brisbane',
		'destinations'      => array( 'email', 'slack', 'teams' ),
	),
) );
$req = new WP_REST_Request();
$req->set_param( 'enabled', true );
$req->set_param( 'send_hour_local', 7 );
$req->set_param( 'send_minute_local', 0 );
$req->set_param( 'timezone', 'Australia/Brisbane' );
$req->set_param( 'destinations', array( 'email', 'slack', 'teams', 'slack' ) );
$resp = DEF_Core_Staff_AI::rest_put_triage_schedule( $req );
unset( $GLOBALS['_def_test_request_body'] );
assert_true( ! is_wp_error( $resp ), 'a valid PUT succeeds' );
assert_equals( 'PUT', $GLOBALS['_def_test_last_request']['method'] ?? '', 'verb is PUT' );
assert_equals(
	'https://def-api.test/api/staff-ai/triage-schedule',
	$GLOBALS['_def_test_last_request']['url'] ?? '',
	'the DEF path carries nothing user-scoped'
);
$sent = json_decode( $GLOBALS['_def_test_last_request']['body'] ?? '', true );
$sent_keys = array_keys( is_array( $sent ) ? $sent : array() );
sort( $sent_keys );
assert_equals(
	array( 'connected_account_id', 'destinations', 'enabled', 'search_correspondent_mail', 'send_hour_local', 'send_minute_local', 'timezone' ),
	$sent_keys,
	'the COMPLETE object is forwarded - DEF PUT is full-replace, a partial body would silently reset fields'
);
// 6-A, the write half of the same rule: this body carried no flag (a cached
// pre-6.8.0 page). Full-replace means whatever this door forwards IS the stored
// value, so defaulting it false here would turn the feature off on save.
assert_true( true === ( $sent['search_correspondent_mail'] ?? null ), 'an absent mail-search flag forwards as ON' );
assert_equals( array( 'email', 'slack', 'teams' ), $sent['destinations'], 'destinations forwarded deduplicated' );
// Phase 4a: this body carried NO binding - the cached pre-6.3.0 page. It
// forwards '' (= unbound, full-replace) exactly as DEF documents, never an error.
assert_equals( '', $sent['connected_account_id'] ?? 'MISSING', 'absent binding forwards as empty (cached-page compatibility)' );

echo "\n[TS-4b] the mail-search flag survives OFF, and refuses a non-boolean\n";
// The default is ON everywhere, which makes OFF the state a mistake would
// silently lose: a truthy default, a dropped forward, or a mirror reading
// absence as on would all still pass TS-4 and leave the switch inoperative.
$GLOBALS['_def_test_request_body'] = json_encode( array( 'success' => true, 'schedule' => array() ) );
$req = new WP_REST_Request();
$req->set_param( 'enabled', true );
$req->set_param( 'send_hour_local', 7 );
$req->set_param( 'send_minute_local', 0 );
$req->set_param( 'timezone', 'UTC' );
$req->set_param( 'destinations', array( 'email' ) );
$req->set_param( 'search_correspondent_mail', false );
$resp = DEF_Core_Staff_AI::rest_put_triage_schedule( $req );
unset( $GLOBALS['_def_test_request_body'] );
assert_true( ! is_wp_error( $resp ), 'switching the mail search off is a valid save' );
$sent = json_decode( $GLOBALS['_def_test_last_request']['body'] ?? '', true );
assert_true( false === ( $sent['search_correspondent_mail'] ?? null ), 'OFF reaches DEF as false, not dropped and not re-defaulted' );

echo "\n[TS-5] rest_run_now_triage_schedule asks DEF, carrying nothing user-scoped\n";
$GLOBALS['_def_test_request_body'] = json_encode( array( 'success' => true, 'run_now' => array() ) );
$resp = DEF_Core_Staff_AI::rest_run_now_triage_schedule();
unset( $GLOBALS['_def_test_request_body'] );
assert_true( ! is_wp_error( $resp ), 'run-now succeeds' );
assert_equals( 'POST', $GLOBALS['_def_test_last_request']['method'] ?? '', 'verb is POST' );
assert_equals(
	'https://def-api.test/api/staff-ai/triage-schedule/run-now',
	$GLOBALS['_def_test_last_request']['url'] ?? '',
	'the DEF path carries nothing user-scoped - the owner comes from the forwarded identity'
);
$run_sent = json_decode( $GLOBALS['_def_test_last_request']['body'] ?? '', true );
assert_equals(
	array(),
	is_array( $run_sent ) ? $run_sent : array( 'unexpected' ),
	'an EMPTY body - no field exists that could name another user mailbox'
);

echo "\n[TS-6] rest_run_now_triage_schedule refuses a switched-off schedule, actionably\n";
$GLOBALS['_def_test_request_code'] = 409;
$resp = DEF_Core_Staff_AI::rest_run_now_triage_schedule();
unset( $GLOBALS['_def_test_request_code'] );
assert_true( is_wp_error( $resp ), 'a switched-off schedule is refused' );
assert_true(
	false !== strpos( is_wp_error( $resp ) ? $resp->get_error_message() : '', 'Turn on' ),
	'the user is told to turn the schedule on, not to "try again in a moment"'
);

echo "\n[TS-7] rest_run_now_triage_schedule surfaces a DEF failure, never a silent no-op\n";
$GLOBALS['_def_test_request_code'] = 503;
$resp = DEF_Core_Staff_AI::rest_run_now_triage_schedule();
unset( $GLOBALS['_def_test_request_code'] );
assert_true( is_wp_error( $resp ), 'a DEF failure is an error, not a cheerful success' );
$run_msg = is_wp_error( $resp ) ? $resp->get_error_message() : '';
assert_true( false === strpos( $run_msg, 'def-api.test' ), 'the internal DEF URL is not shown to the user' );

echo "\n[TS-9] rest_delete_triage_schedule retires the SETUP, carrying nothing user-scoped\n";
$GLOBALS['_def_test_request_body'] = json_encode( array( 'success' => true, 'deleted' => true ) );
$resp = DEF_Core_Staff_AI::rest_delete_triage_schedule();
unset( $GLOBALS['_def_test_request_body'] );
assert_true( ! is_wp_error( $resp ), 'delete succeeds' );
assert_equals( 'DELETE', $GLOBALS['_def_test_last_request']['method'] ?? '', 'verb is DELETE' );
assert_equals(
	'https://def-api.test/api/staff-ai/triage-schedule',
	$GLOBALS['_def_test_last_request']['url'] ?? '',
	'the DEF path carries nothing user-scoped - the owner comes from the forwarded identity'
);
assert_true( true === ( $resp->get_data()['deleted'] ?? null ), 'the deleted flag is passed through' );

echo "\n[TS-10] the last-run summary is allowlisted to status and time - never the digest\n";
$GLOBALS['_def_test_get_body'] = json_encode( array(
	'success'  => true,
	'schedule' => array( 'enabled' => true ),
	// A backend that grew fields must not leak them into the card. The digest
	// carries mail subjects, sender addresses and drafted reply text.
	'last_run' => array(
		'status' => 'failed',
		'at'     => '2026-08-16T09:05:00+00:00',
		'digest' => array( 'mailbox' => 'owner@example.com', 'items' => array( 'secret subject' ) ),
	),
) );
$resp = DEF_Core_Staff_AI::rest_get_triage_schedule();
unset( $GLOBALS['_def_test_get_body'] );
$last = $resp->get_data()['last_run'] ?? array();
assert_equals( array( 'status', 'at' ), array_keys( $last ), 'exactly status and at' );
assert_equals( 'failed', $last['status'] ?? '', 'status passed through' );
assert_true(
	false === strpos( json_encode( $resp->get_data() ), 'secret subject' ),
	'the digest never rides along'
);

echo "\n[TS-11] no runs yet reads as null, never an invented status\n";
$GLOBALS['_def_test_get_body'] = json_encode( array(
	'success' => true, 'schedule' => array( 'enabled' => true ), 'last_run' => null,
) );
$resp = DEF_Core_Staff_AI::rest_get_triage_schedule();
unset( $GLOBALS['_def_test_get_body'] );
// array_key_exists, not ??: the null-coalesce treats a present null and an
// absent key identically, so `?? 'missing'` could never distinguish them.
$_data = $resp->get_data();
assert_true(
	array_key_exists( 'last_run', $_data ) && null === $_data['last_run'],
	'last_run is present and null - the card shows no status rather than inventing one'
);

// ── Phase 4a: the mailbox picker's BFF route ─────────────────────────────
echo "\n[TS-16] rest_mail_connections allowlists rows and carries setup_required\n";
$GLOBALS['_def_test_get_body'] = json_encode( array(
	'success'        => true,
	'setup_required' => false,
	'connections'    => array(
		// DEF never sends token material; this allowlist is the second gate
		// that keeps any future extra out of the browser regardless.
		array( 'id' => 'ca_1', 'toolkit' => 'outlook', 'address' => 'steve@a3rev.com', 'future_extra' => 'MUST-NOT-PASS' ),
		// Slice 4-A: `primary` passes as a coerced bool; a truthy non-bool is state, not a claim.
		array( 'id' => 'ca_2', 'toolkit' => 'outlook', 'address' => 'ceo@defho.ai', 'primary' => 1 ),
		array( 'toolkit' => 'gmail' ), // no id - not a bindable row, dropped
	),
) );
$resp = DEF_Core_Staff_AI::rest_mail_connections();
unset( $GLOBALS['_def_test_get_body'] );
$data = is_object( $resp ) ? $resp->data : $resp;
assert_equals(
	array(
		array( 'id' => 'ca_1', 'toolkit' => 'outlook', 'address' => 'steve@a3rev.com', 'primary' => false ),
		array( 'id' => 'ca_2', 'toolkit' => 'outlook', 'address' => 'ceo@defho.ai', 'primary' => true ),
	),
	$data['connections'],
	'rows allowlisted to id/toolkit/address/primary - extras and id-less rows stay out'
);
assert_true( false === strpos( json_encode( $data ), 'MUST-NOT-PASS' ), 'no extra field reaches the browser' );
assert_equals( false, $data['setup_required'], 'setup_required rides through' );

echo "\n[TS-17] rest_mail_connections surfaces a DEF fault, never an empty picker\n";
$GLOBALS['_def_test_get_code'] = 502;
$result = DEF_Core_Staff_AI::rest_mail_connections();
unset( $GLOBALS['_def_test_get_code'] );
assert_true( is_wp_error( $result ), 'an aggregator fault is an error - an empty list would read as "no other mailbox"' );

// ── Slice 4-A (v6.6.0): the chat primary-mailbox BFF routes ──────────────
echo "\n[TS-4A-1] rest_set_primary_mailbox forwards the pick and allowlists DEF's answer\n";
$GLOBALS['_def_test_request_body'] = json_encode( array(
	'success' => true,
	'primary' => array( 'toolkit' => 'outlook', 'connected_account_id' => 'ca_2',
	                    'future_extra' => 'MUST-NOT-PASS' ),
) );
$request = new WP_REST_Request( 'PUT', '/staff-ai/primary-mailbox' );
$request->set_param( 'connected_account_id', 'ca_2' );
$resp = DEF_Core_Staff_AI::rest_set_primary_mailbox( $request );
unset( $GLOBALS['_def_test_request_body'] );
$data = is_object( $resp ) ? $resp->data : $resp;
assert_equals(
	array( 'toolkit' => 'outlook', 'connected_account_id' => 'ca_2' ),
	$data['primary'],
	'response allowlisted to toolkit/connected_account_id'
);
assert_true( false === strpos( json_encode( $data ), 'MUST-NOT-PASS' ), 'no extra field reaches the browser' );

echo "\n[TS-4A-2] rest_set_primary_mailbox refuses an empty pick without calling DEF\n";
$request = new WP_REST_Request( 'PUT', '/staff-ai/primary-mailbox' );
$result = DEF_Core_Staff_AI::rest_set_primary_mailbox( $request );
assert_true( is_wp_error( $result ), 'no connected_account_id refuses 422 by name' );

echo "\n[TS-4A-3] rest_clear_primary_mailbox clears one provider, refuses none\n";
$GLOBALS['_def_test_request_body'] = json_encode( array( 'success' => true, 'cleared' => 'outlook' ) );
$request = new WP_REST_Request( 'DELETE', '/staff-ai/primary-mailbox' );
$request->set_param( 'toolkit', 'outlook' );
$resp = DEF_Core_Staff_AI::rest_clear_primary_mailbox( $request );
unset( $GLOBALS['_def_test_request_body'] );
$data = is_object( $resp ) ? $resp->data : $resp;
assert_equals( 'outlook', $data['cleared'], 'the cleared provider is named back' );
$request = new WP_REST_Request( 'DELETE', '/staff-ai/primary-mailbox' );
$result = DEF_Core_Staff_AI::rest_clear_primary_mailbox( $request );
assert_true( is_wp_error( $result ), 'no toolkit refuses 422 by name' );

echo "\n[TS-4A-4] a DEF fault on the primary PUT surfaces as an error, never a silent no-op\n";
$GLOBALS['_def_test_request_code'] = 502;
$request = new WP_REST_Request( 'PUT', '/staff-ai/primary-mailbox' );
$request->set_param( 'connected_account_id', 'ca_2' );
$result = DEF_Core_Staff_AI::rest_set_primary_mailbox( $request );
unset( $GLOBALS['_def_test_request_code'] );
assert_true( is_wp_error( $result ), 'a DEF fault is an error - the picker must not pretend it saved' );

// ── Free-text tasks (Phase 3): allowlist exactness, refusals, forwarding ──
echo "\n[FT-1] rest_list_tasks allowlists each task - owner_email NEVER passes\n";
$GLOBALS['_def_test_get_body'] = json_encode( array(
	'success' => true,
	'tasks'   => array( array(
		'id'                => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
		'name'              => 'Daily brief',
		'instruction'       => 'Write my checklist.',
		'enabled'           => true,
		'cadence'           => 'weekly',
		'send_weekday'      => 2,
		'send_hour_local'   => 7,
		'send_minute_local' => 30,
		'timezone'          => 'Australia/Brisbane',
		'destinations'      => array( 'email', 'carrier-pigeon' ),
		'model'             => 'claude-haiku-4-5-20251001',
		'last_run'          => array( 'status' => 'succeeded', 'at' => '2026-08-17T07:00:00+00:00' ),
		// The delivery address is captured DEF-side from the verified identity
		// and consumed by the scheduler's email leg - the UI never needs it,
		// so the allowlist is where it must stop if DEF ever exposes it.
		'owner_email'       => 'must-not-pass@example.com',
	) ),
) );
$resp = DEF_Core_Staff_AI::rest_list_tasks();
unset( $GLOBALS['_def_test_get_body'] );
$task = $resp->get_data()['tasks'][0] ?? array();
$keys = array_keys( $task );
sort( $keys );
assert_equals(
	array( 'cadence', 'destinations', 'enabled', 'id', 'instruction', 'last_run', 'model', 'name', 'send_hour_local', 'send_minute_local', 'send_weekday', 'timezone' ),
	$keys,
	'exactly the twelve task fields - owner_email and any future extras stay out'
);
assert_equals( 'weekly', $task['cadence'], 'cadence rides the allowlist' );
assert_equals( 2, $task['send_weekday'], 'send_weekday rides the allowlist' );
assert_equals( 'claude-haiku-4-5-20251001', $task['model'], 'model rides the allowlist (P3-C)' );
assert_equals( array( 'email' ), $task['destinations'], 'unknown destination types are dropped' );
assert_true(
	false === strpos( json_encode( $resp->get_data() ), 'must-not-pass' ),
	'owner_email never reaches the UI'
);

echo "\n[FT-2] rest_create_task refuses bad shapes before the backend\n";
$GLOBALS['_def_test_last_request'] = array();
$_ft_valid = array(
	'name'              => 'Daily brief',
	'instruction'       => 'Write my checklist.',
	'enabled'           => true,
	'send_hour_local'   => 7,
	'send_minute_local' => 0,
	'timezone'          => 'UTC',
	'destinations'      => array( 'email' ),
);
$_ft_bad = array(
	'missing name'        => array_diff_key( $_ft_valid, array( 'name' => 1 ) ),
	'blank instruction'   => array_merge( $_ft_valid, array( 'instruction' => '   ' ) ),
	'name too long'       => array_merge( $_ft_valid, array( 'name' => str_repeat( 'x', 121 ) ) ),
	'instruction too big' => array_merge( $_ft_valid, array( 'instruction' => str_repeat( 'x', 10001 ) ) ),
	'unknown dest'        => array_merge( $_ft_valid, array( 'destinations' => array( 'webhook' ) ) ),
	'enabled not bool'    => array_merge( $_ft_valid, array( 'enabled' => 'yes' ) ),
	'unknown cadence'     => array_merge( $_ft_valid, array( 'cadence' => 'fortnightly' ) ),
	'weekday too big'     => array_merge( $_ft_valid, array( 'cadence' => 'weekly', 'send_weekday' => 7 ) ),
	'weekday negative'    => array_merge( $_ft_valid, array( 'cadence' => 'weekly', 'send_weekday' => -1 ) ),
	'weekday not int'     => array_merge( $_ft_valid, array( 'cadence' => 'weekly', 'send_weekday' => 'monday' ) ),
	// A NUMERIC string is the case the is_int half alone protects: PHP 8's
	// comparison rules let '3' sail through the range checks, and DEF would
	// then refuse it with a proxied 422 - the exact class of error this
	// validator exists to turn into one plain sentence (round-1 code leg).
	'weekday numeric str' => array_merge( $_ft_valid, array( 'cadence' => 'weekly', 'send_weekday' => '3' ) ),
	// P3-C: the BFF bounds the SHAPE only - DEF is the authority on which
	// model ids exist, so a well-formed unknown id passes through to DEF.
	'model too long'      => array_merge( $_ft_valid, array( 'model' => str_repeat( 'x', 65 ) ) ),
	'model not string'    => array_merge( $_ft_valid, array( 'model' => 123 ) ),
);
foreach ( $_ft_bad as $label => $body ) {
	$req = new WP_REST_Request();
	foreach ( $body as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$result = DEF_Core_Staff_AI::rest_create_task( $req );
	assert_equals( 'def_task_invalid', is_wp_error( $result ) ? $result->get_error_code() : '', "create refused: $label" );
}
assert_equals( array(), $GLOBALS['_def_test_last_request'], 'no refused body ever reached the backend' );

echo "\n[FT-3] rest_create_task forwards the complete whitelisted object only\n";
$GLOBALS['_def_test_request_body'] = json_encode( array( 'success' => true, 'task' => $_ft_valid + array( 'id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' ) ) );
$req = new WP_REST_Request();
foreach ( $_ft_valid as $k => $v ) {
	$req->set_param( $k, $v );
}
// Smuggled addressing fields: the body must never be able to point a task -
// or its delivery - at another user. The whitelist simply never reads them.
$req->set_param( 'owner_email', 'attacker@evil.example' );
$req->set_param( 'actor_sub', 'user-999' );
$resp = DEF_Core_Staff_AI::rest_create_task( $req );
unset( $GLOBALS['_def_test_request_body'] );
assert_true( ! is_wp_error( $resp ), 'a valid create succeeds' );
assert_equals( 'POST', $GLOBALS['_def_test_last_request']['method'] ?? '', 'verb is POST' );
assert_equals(
	'https://def-api.test/api/staff-ai/tasks',
	$GLOBALS['_def_test_last_request']['url'] ?? '',
	'the DEF path carries nothing user-scoped'
);
$sent = json_decode( $GLOBALS['_def_test_last_request']['body'] ?? '', true );
$sent_keys = array_keys( is_array( $sent ) ? $sent : array() );
sort( $sent_keys );
assert_equals(
	array( 'cadence', 'destinations', 'enabled', 'instruction', 'model', 'name', 'send_hour_local', 'send_minute_local', 'send_weekday', 'timezone' ),
	$sent_keys,
	'the COMPLETE whitelisted object and nothing else - smuggled fields never forwarded'
);
// This body carried NO cadence fields - the cached pre-6.1.0 page. The
// forward must default them the way DEF does, so old pages keep saving
// daily tasks instead of being refused.
assert_equals( 'daily', $sent['cadence'] ?? '', 'absent cadence forwards as daily (cached-page compatibility)' );
assert_equals( 0, $sent['send_weekday'] ?? -1, 'absent send_weekday forwards as 0' );
// Same for the pre-6.2.0 page: no model posted forwards as '' = employee
// default, which is exactly what those pages' tasks were running.
assert_equals( '', $sent['model'] ?? 'MISSING', 'absent model forwards as empty (cached-page compatibility)' );

echo "\n[FT-4] rest_run_now_task maps DEF's two actionable refusals\n";
$GLOBALS['_def_test_request_code'] = 409;
$req = new WP_REST_Request();
$req->set_param( 'task_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' );
$result = DEF_Core_Staff_AI::rest_run_now_task( $req );
unset( $GLOBALS['_def_test_request_code'] );
assert_true( is_wp_error( $result ), 'a 409 surfaces as an error' );
assert_true(
	false !== strpos( is_wp_error( $result ) ? $result->get_error_message() : '', 'Turn this task on' ),
	'the disabled-task refusal is actionable'
);

echo "\n[FT-4b] rest_run_now_task surfaces a vanished task actionably\n";
$GLOBALS['_def_test_request_code'] = 404;
$req = new WP_REST_Request();
$req->set_param( 'task_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' );
$result = DEF_Core_Staff_AI::rest_run_now_task( $req );
unset( $GLOBALS['_def_test_request_code'] );
assert_true( is_wp_error( $result ), 'a 404 surfaces as an error' );
assert_true(
	false !== strpos( is_wp_error( $result ) ? $result->get_error_message() : '', 'no longer exists' ),
	'the vanished-task refusal names what happened'
);

echo "\n[FT-6] rest_update_task forwards PUT to the encoded task path\n";
$GLOBALS['_def_test_request_body'] = json_encode( array( 'success' => true, 'task' => $_ft_valid + array( 'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' ) ) );
$req = new WP_REST_Request();
foreach ( $_ft_valid as $k => $v ) {
	$req->set_param( $k, $v );
}
$req->set_param( 'task_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' );
$resp = DEF_Core_Staff_AI::rest_update_task( $req );
unset( $GLOBALS['_def_test_request_body'] );
assert_true( ! is_wp_error( $resp ), 'a valid update succeeds' );
assert_equals( 'PUT', $GLOBALS['_def_test_last_request']['method'] ?? '', 'verb is PUT' );
assert_equals(
	'https://def-api.test/api/staff-ai/tasks/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
	$GLOBALS['_def_test_last_request']['url'] ?? '',
	'the task id rides the encoded item path'
);

echo "\n[FT-7] a multibyte name is measured in CHARACTERS, never bytes\n";
$GLOBALS['_def_test_request_body'] = json_encode( array( 'success' => true, 'task' => $_ft_valid + array( 'id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' ) ) );
$req = new WP_REST_Request();
foreach ( $_ft_valid as $k => $v ) {
	$req->set_param( $k, $v );
}
// 50 CJK characters = 150 UTF-8 bytes: over 120 as bytes, well under as
// characters. strlen here would refuse a name DEF accepts - the 5.7.5
// display-name byte-cut class, pinned so it cannot ship a third time.
$req->set_param( 'name', str_repeat( "\u{6F22}", 50 ) );
$resp = DEF_Core_Staff_AI::rest_create_task( $req );
unset( $GLOBALS['_def_test_request_body'] );
assert_true( ! is_wp_error( $resp ), 'a 50-character CJK name is accepted (150 bytes is irrelevant)' );

echo "\n[FT-8] a weekly task's cadence and day ride the forward intact (6.1.0)\n";
$GLOBALS['_def_test_request_body'] = json_encode( array( 'success' => true, 'task' => $_ft_valid + array( 'id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' ) ) );
$req = new WP_REST_Request();
foreach ( array_merge( $_ft_valid, array( 'cadence' => 'weekly', 'send_weekday' => 4 ) ) as $k => $v ) {
	$req->set_param( $k, $v );
}
$resp = DEF_Core_Staff_AI::rest_create_task( $req );
unset( $GLOBALS['_def_test_request_body'] );
assert_true( ! is_wp_error( $resp ), 'a weekly create succeeds' );
$sent = json_decode( $GLOBALS['_def_test_last_request']['body'] ?? '', true );
assert_equals( 'weekly', $sent['cadence'] ?? '', 'cadence forwards as chosen' );
assert_equals( 4, $sent['send_weekday'] ?? -1, 'the chosen day forwards untouched' );

echo "\n[FT-8b] a cadence DEF never sent defaults to daily at the allowlist\n";
$GLOBALS['_def_test_get_body'] = json_encode( array(
	'success' => true,
	'tasks'   => array(
		array( 'id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'name' => 'X', 'instruction' => 'Y', 'enabled' => true, 'cadence' => 'fortnightly', 'send_weekday' => 99 ),
		array( 'id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'name' => 'X', 'instruction' => 'Y', 'enabled' => true, 'cadence' => 'weekly', 'send_weekday' => -1 ),
	),
) );
$resp = DEF_Core_Staff_AI::rest_list_tasks();
unset( $GLOBALS['_def_test_get_body'] );
$task = $resp->get_data()['tasks'][0] ?? array();
assert_equals( 'daily', $task['cadence'] ?? '', 'an unknown cadence renders as daily, never raw' );
assert_equals( 0, $task['send_weekday'] ?? -1, 'an out-of-range weekday renders as 0' );
// -1 would otherwise reach dayName() and silently render "Sunday" (the
// Date.UTC day before Monday-0's anchor) - the wrong-day class, clamped.
$task2 = $resp->get_data()['tasks'][1] ?? array();
assert_equals( 0, $task2['send_weekday'] ?? -1, 'a negative weekday clamps to 0, never reaches the badge' );

echo "\n[FT-5] rest_delete_task forwards DELETE to the task path\n";
$GLOBALS['_def_test_request_body'] = json_encode( array( 'success' => true, 'deleted' => true ) );
$req = new WP_REST_Request();
$req->set_param( 'task_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' );
$resp = DEF_Core_Staff_AI::rest_delete_task( $req );
unset( $GLOBALS['_def_test_request_body'] );
assert_true( ! is_wp_error( $resp ), 'delete succeeds' );
assert_equals( 'DELETE', $GLOBALS['_def_test_last_request']['method'] ?? '', 'verb is DELETE' );
assert_equals(
	'https://def-api.test/api/staff-ai/tasks/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
	$GLOBALS['_def_test_last_request']['url'] ?? '',
	'the task id rides the path, nothing else user-scoped'
);
assert_true( true === ( $resp->get_data()['deleted'] ?? null ), 'the deleted flag is passed through' );

echo "\n[TS-12] rest_user_integration_disconnect ends the caller's own access\n";
$GLOBALS['_def_test_request_body'] = json_encode( array( 'requested' => 1, 'failed' => 0 ) );
$req = new WP_REST_Request();
$req->set_param( 'server_id', 'srv-1' );
$resp = DEF_Core_Staff_AI::rest_user_integration_disconnect( $req );
unset( $GLOBALS['_def_test_request_body'] );
assert_true( ! is_wp_error( $resp ), 'disconnect succeeds' );
assert_equals( 'POST', $GLOBALS['_def_test_last_request']['method'] ?? '', 'verb is POST' );
assert_equals(
	'https://def-api.test/api/staff-ai/user/integrations/srv-1/disconnect',
	$GLOBALS['_def_test_last_request']['url'] ?? '',
	'the server_id is the ONLY thing scoped in the URL - the user comes from the forwarded identity'
);
assert_equals( 1, $resp->get_data()['requested'] ?? -1, 'requested count passed through' );

echo "\n[TS-13] a grant the provider would not end is REPORTED, never smoothed over\n";
$GLOBALS['_def_test_request_body'] = json_encode( array( 'requested' => 0, 'failed' => 1 ) );
$req = new WP_REST_Request();
$req->set_param( 'server_id', 'srv-1' );
$resp = DEF_Core_Staff_AI::rest_user_integration_disconnect( $req );
unset( $GLOBALS['_def_test_request_body'] );
assert_equals( 1, $resp->get_data()['failed'] ?? -1, 'the surviving grant is surfaced so the UI cannot claim a clean disconnect' );

echo "\n[TS-14] a missing server_id refuses before reaching the backend\n";
$req = new WP_REST_Request();
$req->set_param( 'server_id', '' );
$resp = DEF_Core_Staff_AI::rest_user_integration_disconnect( $req );
assert_true( is_wp_error( $resp ), 'empty server_id is refused' );

echo "
[TS-15] has_grant rides through so the Disconnect control cannot go missing
";
$GLOBALS['_def_test_get_body'] = json_encode( array(
	'configured' => true,
	'apps'       => array(
		// The a3rev Gmail case: NOT authorized (the tool rows point at a newer server that
		// was never authorised) while the original grant is still live. Gating Disconnect on
		// 'authorized' hides it from exactly this row - the one grant C2 exists to end.
		array( 'server_id' => 'srv-1', 'category' => 'gmail', 'no_auth' => false, 'authorized' => false, 'has_grant' => true ),
	),
) );
$req  = new WP_REST_Request();
$resp = DEF_Core_Staff_AI::rest_user_integrations( $req );
unset( $GLOBALS['_def_test_get_body'] );
$_app = $resp->get_data()['apps'][0] ?? array();
assert_true(
	true === ( $_app['has_grant'] ?? null ) && false === ( $_app['authorized'] ?? null ),
	'has_grant survives the allowlist independently of authorized - dropping it from the allowlist strips every Disconnect button'
);

// ── Phase 4a (v6.4.0): the plural triage surface ─────────────────────────
echo "\n[TS-18] rest_list_triage_schedules allowlists each row\n";
$GLOBALS['_def_test_get_body'] = json_encode( array(
	'success'   => true,
	'schedules' => array( array(
		'schedule_id'          => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
		'enabled'              => true,
		'send_hour_local'      => 7,
		'send_minute_local'    => 0,
		'timezone'             => 'Australia/Brisbane',
		'destinations'         => array( 'email' ),
		'connected_account_id' => 'ca_1',
		'last_run'             => array( 'status' => 'succeeded', 'at' => '2026-08-18T07:00:00+00:00' ),
		'future_extra'         => 'MUST-NOT-PASS',
	) ),
) );
$resp = DEF_Core_Staff_AI::rest_list_triage_schedules();
unset( $GLOBALS['_def_test_get_body'] );
$data = is_object( $resp ) ? $resp->data : $resp;
$row  = $data['schedules'][0] ?? array();
$keys = array_keys( $row );
sort( $keys );
assert_equals(
	array( 'connected_account_id', 'destinations', 'enabled', 'last_run', 'schedule_id', 'search_correspondent_mail', 'send_hour_local', 'send_minute_local', 'timezone' ),
	$keys,
	'exactly the nine plural-row fields - extras stay out'
);
assert_equals( 'ca_1', $row['connected_account_id'], 'the binding rides the row' );
assert_equals( 'succeeded', $row['last_run']['status'], 'each row carries its own last run' );
assert_true( false === strpos( json_encode( $data ), 'MUST-NOT-PASS' ), 'no extra field reaches the browser' );

echo "\n[TS-19] plural create forwards the full object and maps the two refusals\n";
$GLOBALS['_def_test_last_request'] = array();
$GLOBALS['_def_test_request_body'] = json_encode( array( 'success' => true, 'schedule' => array( 'schedule_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' ) ) );
$req = new WP_REST_Request();
$req->set_param( 'enabled', true );
$req->set_param( 'send_hour_local', 7 );
$req->set_param( 'send_minute_local', 0 );
$req->set_param( 'timezone', 'UTC' );
$req->set_param( 'destinations', array( 'email' ) );
$req->set_param( 'connected_account_id', 'ca_2' );
$resp = DEF_Core_Staff_AI::rest_create_triage_schedule( $req );
unset( $GLOBALS['_def_test_request_body'] );
assert_true( ! is_wp_error( $resp ), 'a valid create succeeds' );
assert_equals( 'POST', $GLOBALS['_def_test_last_request']['method'] ?? '', 'verb is POST' );
$sent = json_decode( $GLOBALS['_def_test_last_request']['body'] ?? '', true );
assert_equals( 'ca_2', $sent['connected_account_id'] ?? '', 'the binding is forwarded' );

// DEF's 422 surfaces DEF's OWN message (panel C2): mailbox_required and
// validation_failed are different refusals, and a fixed mailbox caption
// would mislead on the second (reachable via browser/server tzdata skew).
$GLOBALS['_def_test_request_code'] = 422;
$GLOBALS['_def_test_request_body'] = json_encode( array( 'detail' => array(
	'error' => 'mailbox_required',
	'message' => 'A second schedule must say which mailbox it reads - pick one.' ) ) );
$result = DEF_Core_Staff_AI::rest_create_triage_schedule( $req );
assert_true( is_wp_error( $result ), 'DEF 422 surfaces as an error' );
assert_true(
	false !== strpos( $result->get_error_message(), 'which mailbox it reads' ),
	'the mailbox_required refusal keeps DEF\'s actionable message'
);
$GLOBALS['_def_test_request_body'] = json_encode( array( 'detail' => array(
	'error' => 'validation_failed', 'message' => 'unknown timezone: Foo/Bar' ) ) );
$result = DEF_Core_Staff_AI::rest_create_triage_schedule( $req );
unset( $GLOBALS['_def_test_request_code'], $GLOBALS['_def_test_request_body'] );
assert_true(
	is_wp_error( $result )
	&& false !== strpos( $result->get_error_message(), 'unknown timezone' )
	&& false === strpos( $result->get_error_message(), 'mailbox' ),
	'a validation 422 keeps its OWN message - never the mailbox caption'
);

// DEF's 409 (one setup per mailbox) becomes its own sentence.
$GLOBALS['_def_test_request_code'] = 409;
$result = DEF_Core_Staff_AI::rest_create_triage_schedule( $req );
unset( $GLOBALS['_def_test_request_code'] );
assert_true(
	is_wp_error( $result )
	&& false !== strpos( $result->get_error_message(), 'already reads that mailbox' ),
	'the duplicate-mailbox refusal is actionable'
);

echo "\n[TS-20] plural update/delete/run-now hit the id-scoped DEF paths\n";
$GLOBALS['_def_test_request_body'] = json_encode( array( 'success' => true, 'schedule' => array( 'schedule_id' => 'cccc' ) ) );
$req->set_param( 'schedule_id', 'cccc-dddd' );
DEF_Core_Staff_AI::rest_update_triage_schedule_by_id( $req );
assert_true(
	false !== strpos( $GLOBALS['_def_test_last_request']['url'] ?? '', '/api/staff-ai/triage-schedules/cccc-dddd' ),
	'update targets the named setup'
);
$GLOBALS['_def_test_request_body'] = json_encode( array( 'success' => true, 'deleted' => true ) );
DEF_Core_Staff_AI::rest_delete_triage_schedule_by_id( $req );
assert_true(
	false !== strpos( $GLOBALS['_def_test_last_request']['url'] ?? '', '/api/staff-ai/triage-schedules/cccc-dddd' ),
	'delete targets the named setup'
);
$GLOBALS['_def_test_request_body'] = json_encode( array( 'success' => true, 'run_now' => array() ) );
DEF_Core_Staff_AI::rest_run_now_triage_by_id( $req );
assert_true(
	false !== strpos( $GLOBALS['_def_test_last_request']['url'] ?? '', '/api/staff-ai/triage-schedules/cccc-dddd/run-now' ),
	'run-now targets the named setup'
);
unset( $GLOBALS['_def_test_request_body'] );

// ── Summary ─────────────────────────────────────────────────────────────

// ── Slice 4-B (v6.7.0): connect-another + per-account disconnect proxies ──
echo "\n[TS-4B-1] rest_user_connect_another forwards and allowlists the link answer\n";
$GLOBALS['_def_test_request_body'] = json_encode( array(
	'success' => true, 'status' => 'awaiting_authorization',
	'redirect_url' => 'https://connect.composio.dev/x', 'future_extra' => 'MUST-NOT-PASS',
) );
$request = new WP_REST_Request( 'POST', '/staff-ai/user/integrations/srv-1/connect-another' );
$request->set_param( 'server_id', 'srv-1' );
$resp = DEF_Core_Staff_AI::rest_user_connect_another( $request );
unset( $GLOBALS['_def_test_request_body'] );
$data = is_object( $resp ) ? $resp->data : $resp;
assert_equals( 'awaiting_authorization', $data['status'], 'status rides through' );
assert_equals( 'https://connect.composio.dev/x', $data['redirect_url'], 'the link rides through' );
assert_true( false === strpos( json_encode( $data ), 'MUST-NOT-PASS' ), 'no extra field reaches the browser' );

echo "\n[TS-4B-2] rest_user_connect_another refuses a missing server_id without calling DEF\n";
$request = new WP_REST_Request( 'POST', '/staff-ai/user/integrations//connect-another' );
$result = DEF_Core_Staff_AI::rest_user_connect_another( $request );
assert_true( is_wp_error( $result ), 'no server_id refuses by name' );

echo "\n[TS-4B-3] rest_user_account_disconnect forwards both ids and reports counts\n";
$GLOBALS['_def_test_request_body'] = json_encode( array(
	'success' => true, 'requested' => 1, 'failed' => 0, 'category' => 'outlook',
) );
$request = new WP_REST_Request( 'POST', '/staff-ai/user/integrations/srv-1/accounts/ca_2/disconnect' );
$request->set_param( 'server_id', 'srv-1' );
$request->set_param( 'account_id', 'ca_2' );
$resp = DEF_Core_Staff_AI::rest_user_account_disconnect( $request );
unset( $GLOBALS['_def_test_request_body'] );
$data = is_object( $resp ) ? $resp->data : $resp;
assert_equals( 1, $data['requested'], 'requested count rides through' );
assert_equals( 0, $data['failed'], 'failed count rides through' );
assert_true( ! array_key_exists( 'category', $data ), 'a DEF extra never transits the allowlist' );

echo "\n[TS-4B-4] rest_user_account_disconnect refuses missing params; surfaces a DEF fault\n";
$request = new WP_REST_Request( 'POST', '/staff-ai/user/integrations/srv-1/accounts//disconnect' );
$request->set_param( 'server_id', 'srv-1' );
$result = DEF_Core_Staff_AI::rest_user_account_disconnect( $request );
assert_true( is_wp_error( $result ), 'no account_id refuses by name' );
$GLOBALS['_def_test_request_code'] = 502;
$request = new WP_REST_Request( 'POST', '/staff-ai/user/integrations/srv-1/accounts/ca_2/disconnect' );
$request->set_param( 'server_id', 'srv-1' );
$request->set_param( 'account_id', 'ca_2' );
$result = DEF_Core_Staff_AI::rest_user_account_disconnect( $request );
unset( $GLOBALS['_def_test_request_code'] );
assert_true( is_wp_error( $result ), 'a DEF fault is an error - the row must not pretend it disconnected' );


// ── Projects P-A — BFF routes (docs/projects-runsheet.md in the DEF repo) ──
echo "\nProjects P-A - BFF routes\n";

// list_documents forwards a charset-valid project_id and DROPS an invalid one.
$GLOBALS['_def_test_last_get_url'] = '';
$req = new WP_REST_Request();
$req->set_param( 'project_id', 'abc-123-DEF' );
DEF_Core_Staff_AI::rest_list_documents( $req );
assert_equals(
	'https://def-api.test/api/staff-ai/documents?project_id=abc-123-DEF',
	$GLOBALS['_def_test_last_get_url'] ?? '',
	'valid project_id filter forwarded to DEF'
);
$GLOBALS['_def_test_last_get_url'] = '';
$req = new WP_REST_Request();
$req->set_param( 'project_id', '../../etc' );
DEF_Core_Staff_AI::rest_list_documents( $req );
assert_equals(
	'https://def-api.test/api/staff-ai/documents',
	$GLOBALS['_def_test_last_get_url'] ?? '',
	'invalid project_id filter dropped, never forwarded'
);

// rest_list_projects allowlists rows: bad-charset id dropped, status normalized.
$GLOBALS['_def_test_get_body'] = json_encode( array(
	'success'  => true,
	'projects' => array(
		array( 'project_id' => 'p-1', 'name' => 'Alpha', 'status' => 'archived',
			'created_at' => '2026-08-26T00:00:00', 'updated_at' => '2026-08-26T00:00:00',
			'sneaky' => 'dropped' ),
		array( 'project_id' => 'bad/../id', 'name' => 'Evil' ),
		array( 'project_id' => 'p-2', 'name' => 'Beta', 'status' => 'weird' ),
	),
) );
$req    = new WP_REST_Request();
$result = DEF_Core_Staff_AI::rest_list_projects( $req );
$data   = $result->get_data();
assert_equals( 2, count( $data['projects'] ), 'bad-charset project row dropped' );
assert_equals( 'archived', $data['projects'][0]['status'], 'archived status survives' );
assert_equals( 'active', $data['projects'][1]['status'], 'unknown status normalizes to active' );
assert_true( ! isset( $data['projects'][0]['sneaky'] ), 'unknown DEF fields never reach the panel' );
$GLOBALS['_def_test_get_body'] = '{"success":true,"documents":[]}';

// rest_create_project refuses an empty name before any backend call.
$req = new WP_REST_Request();
$req->set_param( 'name', '   ' );
$result = DEF_Core_Staff_AI::rest_create_project( $req );
assert_true( is_wp_error( $result ), 'blank project name refused' );
assert_equals( 422, $result->get_error_data()['status'] ?? 0, 'blank name is 422' );

// rest_update_project refuses an empty change set; unknown status never forwards.
$req = new WP_REST_Request();
$req->set_param( 'project_id', 'p-1' );
$result = DEF_Core_Staff_AI::rest_update_project( $req );
assert_true( is_wp_error( $result ), 'empty update refused' );
$req = new WP_REST_Request();
$req->set_param( 'project_id', 'p-1' );
$req->set_param( 'status', 'paused' );
$result = DEF_Core_Staff_AI::rest_update_project( $req );
assert_true( is_wp_error( $result ), 'unknown status never forwarded' );

// rest_assign_document_project: slot whitelist + project-id charset, refused locally.
$req = new WP_REST_Request();
$req->set_param( 'id', 'doc-1' );
$req->set_param( 'project_id', 'p-1' );
$req->set_param( 'slot', 'chapter' );
$result = DEF_Core_Staff_AI::rest_assign_document_project( $req );
assert_true( is_wp_error( $result ), 'unknown slot refused' );
assert_equals( 422, $result->get_error_data()['status'] ?? 0, 'unknown slot is 422' );
$req = new WP_REST_Request();
$req->set_param( 'id', 'doc-1' );
$req->set_param( 'project_id', 'p/../1' );
$result = DEF_Core_Staff_AI::rest_assign_document_project( $req );
assert_true( is_wp_error( $result ), 'bad-charset project id refused' );
assert_equals( 404, $result->get_error_data()['status'] ?? 0, 'bad-charset project id is 404, no oracle' );

// The happy assign forwards exactly {project_id, slot} as PUT to the document path.
$GLOBALS['_def_test_last_request'] = array();
$req = new WP_REST_Request();
$req->set_param( 'id', 'doc-1' );
$req->set_param( 'project_id', 'p-1' );
$req->set_param( 'slot', 'runsheet' );
DEF_Core_Staff_AI::rest_assign_document_project( $req );
assert_equals(
	'https://def-api.test/api/staff-ai/documents/doc-1/project',
	$GLOBALS['_def_test_last_request']['url'] ?? '',
	'assign PUTs the document project path'
);
assert_equals( 'PUT', $GLOBALS['_def_test_last_request']['method'] ?? '', 'assign verb is PUT' );
$assign_body = json_decode( (string) ( $GLOBALS['_def_test_last_request']['body'] ?? '' ), true );
assert_equals( 'p-1', $assign_body['project_id'] ?? '', 'project_id rides the body' );
assert_equals( 'runsheet', $assign_body['slot'] ?? '', 'slot rides the body' );

echo "\n--- Staff AI Tests: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );

