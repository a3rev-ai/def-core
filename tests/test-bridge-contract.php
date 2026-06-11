<?php
/**
 * Bridge contract tests.
 *
 * Verifies the outbound bridge client behaviour:
 * - URL construction (base URL + endpoint)
 * - Auth headers (BFF proxy: X-DEF-API-Key + identity, no JWT — see v2.0.9 / 0be0102)
 * - Error mapping (401/403 → fail-closed, 429 → backoff, 5xx → service error)
 * - No secrets/tokens in user-visible error messages
 * - Escalation service auth (X-DEF-AUTH constant-time comparison)
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Additional stubs for bridge testing ─────────────────────────────────

global $_wp_test_rest_routes, $_wp_test_current_user, $_wp_test_user_caps;
global $_wp_test_remote_responses, $_wp_test_remote_calls;
$_wp_test_rest_routes       = array();
$_wp_test_current_user      = null;
$_wp_test_user_caps         = array();
$_wp_test_remote_responses  = array(); // Queued responses for wp_remote_*.
$_wp_test_remote_calls      = array(); // Captured outbound calls.

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
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_status(): int { return $this->status; }
		public function get_data() { return $this->data; }
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params  = array();
		private $headers = array();
		private $body    = array();
		public function __construct( string $method = 'GET', string $route = '' ) {}
		public function set_param( string $key, $value ): void { $this->params[ $key ] = $value; }
		public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
		public function get_json_params(): array { return $this->body; }
		public function set_body_params( array $body ): void { $this->body = $body; }
		public function get_header( string $key ): ?string { return $this->headers[ strtolower( $key ) ] ?? null; }
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID = 0;
		public $user_login = 'testuser';
		public $user_email = 'test@example.com';
		public $display_name = 'Test User';
		public $user_firstname = 'Test';
		public $roles = array( 'administrator' );
		public function __construct( int $id = 0 ) { $this->ID = $id; }
		public function exists(): bool { return $this->ID > 0; }
		public function has_cap( string $cap ): bool {
			global $_wp_test_user_caps;
			return in_array( $cap, $_wp_test_user_caps, true );
		}
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $ns, string $r, array $a ): void {
		global $_wp_test_rest_routes;
		$_wp_test_rest_routes[ $ns . $r ] = $a;
	}
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
if ( ! function_exists( '__' ) ) {
	function __( string $t, string $d = 'default' ): string { return $t; }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		global $_wp_test_current_user;
		return $_wp_test_current_user !== null && $_wp_test_current_user->ID > 0;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		global $_wp_test_user_caps;
		return in_array( $cap, $_wp_test_user_caps, true );
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
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( string $v, $d = '' ) { return $d; }
}

// Mock HTTP functions — capture calls, return queued responses.
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

// backend_request routes all write verbs (POST/PATCH/DELETE) through
// wp_remote_request with an explicit 'method' arg (Engine 2.5 curation).
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( string $url, array $args = array() ) {
		global $_wp_test_remote_calls, $_wp_test_remote_responses;
		$_wp_test_remote_calls[] = array( 'method' => $args['method'] ?? 'GET', 'url' => $url, 'args' => $args );
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

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( ...$a ) { return null; }
}
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '' ): bool { return true; }
}
if ( ! function_exists( 'hash_equals' ) ) {
	// Built-in PHP function.
}

// Staff AI resolves the backend URL via \DEF_Core::get_def_api_url_internal().
// Mirror the production server-side resolver: stored option (rtrim'd), else the
// production default. Reading the option lets these tests drive the base URL via
// update_option( 'def_core_staff_ai_api_url', … ) and exercises trailing-slash
// normalisation, instead of fataling on a missing DEF_Core class.
if ( ! class_exists( 'DEF_Core' ) ) {
	class DEF_Core {
		public static function get_def_api_url_internal(): ?string {
			if ( defined( 'DEF_API_URL' ) && DEF_API_URL ) {
				return rtrim( DEF_API_URL, '/' );
			}
			$url = get_option( 'def_core_staff_ai_api_url', '' );
			if ( empty( $url ) ) {
				return 'https://api.defho.ai';
			}
			return rtrim( $url, '/' );
		}
	}
}

// backend_request() attaches the acting user's DEF capabilities (X-DEF-User-
// Capabilities). Mirror DEF_Core_Tools::get_user_def_capabilities() minimally —
// it just maps the three DEF caps through WP_User::has_cap() (driven here by
// $_wp_test_user_caps).
if ( ! class_exists( 'DEF_Core_Tools' ) ) {
	class DEF_Core_Tools {
		public static function get_user_def_capabilities( WP_User $user ): array {
			$caps = array();
			foreach ( array( 'def_admin_access', 'def_staff_access', 'def_management_access' ) as $cap ) {
				if ( $user->has_cap( $cap ) ) {
					$caps[] = $cap;
				}
			}
			return $caps;
		}
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return 'Test Site';
	}
}

// Load classes.
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-jwt.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-staff-ai.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-escalation.php';

$pass = 0;
$fail = 0;

function assert_true( $value, string $label ): void {
	global $pass, $fail;
	if ( $value ) { $pass++; } else { $fail++; echo "  FAIL: $label\n"; }
}

function assert_equals( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) { $pass++; } else { $fail++; echo "  FAIL: $label (expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . ")\n"; }
}

echo "=== Bridge Contract Tests ===\n";

// ── Setup ───────────────────────────────────────────────────────────────
_wp_test_reset_options();
_wp_test_seed_rsa_keys();

// BFF proxy auth (v2.0.9): backend_request() requires a configured API key, or
// it short-circuits with staff_ai_not_configured before any HTTP call. Seed one
// so the request reaches the (stubbed) wp_remote_* layer under test.
update_option( 'def_core_api_key', 'test-api-key-bridge' );

$user = new WP_User( 42 );
$user->user_email = 'staff@example.com';
$user->user_login = 'staffuser';
$_wp_test_current_user = $user;
$_wp_test_user_caps    = array( 'def_staff_access' );

// ── 1. URL construction ─────────────────────────────────────────────────
echo "\n[1] URL construction\n";
update_option( 'def_core_staff_ai_api_url', 'http://backend:8000' );

// Queue a successful response.
$_wp_test_remote_responses = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( 'threads' => array() ) ),
	),
);
$_wp_test_remote_calls = array();

$request = new WP_REST_Request( 'GET', '/staff-ai/conversations' );
DEF_Core_Staff_AI::rest_list_conversations( $request );

assert_true( count( $_wp_test_remote_calls ) === 1, 'exactly 1 HTTP call made' );
assert_equals(
	'http://backend:8000/api/staff-ai/threads',
	$_wp_test_remote_calls[0]['url'],
	'URL = base + /api/staff-ai/threads'
);

// 2. Trailing slash stripped from base URL.
echo "\n[2] Trailing slash normalization\n";
update_option( 'def_core_staff_ai_api_url', 'http://backend:8000/' ); // Note trailing slash.

$_wp_test_remote_responses = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( 'threads' => array() ) ),
	),
);
$_wp_test_remote_calls = array();

DEF_Core_Staff_AI::rest_list_conversations( $request );
assert_equals(
	'http://backend:8000/api/staff-ai/threads',
	$_wp_test_remote_calls[0]['url'],
	'trailing slash stripped — no double slash'
);

// ── 3. Auth headers (BFF proxy: API key + identity, no JWT) ──────────────
// The Staff AI bridge moved off JWT Bearer to BFF proxy auth in v2.0.9
// (commit 0be0102): backend_request() carries X-DEF-API-Key plus the acting
// user's ID and DEF capabilities — no Authorization/JWT. The per-field identity
// header contract (display name, email, roles, Unicode) is covered in detail by
// test-proxy-identity-headers.php; here we assert the core auth set.
echo "\n[3] BFF proxy auth headers present (no JWT)\n";
$call = ! empty( $_wp_test_remote_calls ) ? $_wp_test_remote_calls[0] : null;
assert_true( $call !== null, 'HTTP call was captured' );
$headers = $call ? ( $call['args']['headers'] ?? array() ) : array();
if ( $call ) {
	assert_true( ! isset( $headers['Authorization'] ), 'no JWT Bearer Authorization header (BFF proxy auth)' );
	assert_equals( 'test-api-key-bridge', $headers['X-DEF-API-Key'] ?? null, 'X-DEF-API-Key carries the configured key' );
	assert_equals( '42', $headers['X-DEF-User'] ?? null, 'X-DEF-User = acting user ID' );
	assert_true( isset( $headers['X-DEF-User-Capabilities'] ), 'X-DEF-User-Capabilities header present' );
	assert_true(
		strpos( $headers['X-DEF-User-Capabilities'] ?? '', 'def_staff_access' ) !== false,
		'capabilities include def_staff_access'
	);
}

// ── 4. Content-Type header ──────────────────────────────────────────────
echo "\n[4] Content-Type header\n";
assert_equals( 'application/json', $headers['Content-Type'] ?? null, 'Content-Type is application/json' );
assert_equals( 'application/json', $headers['Accept'] ?? null, 'Accept is application/json' );

// ── 5. Error mapping: 401/403 → fail-closed ─────────────────────────────
echo "\n[5] 401/403 → fail-closed error\n";
$_wp_test_remote_responses = array(
	array(
		'response' => array( 'code' => 401 ),
		'body'     => json_encode( array( 'detail' => 'Invalid token' ) ),
	),
);

$chat_request = new WP_REST_Request( 'POST', '/staff-ai/chat' );
$chat_request->set_body_params( array( 'message' => 'hello' ) );
$result = DEF_Core_Staff_AI::rest_send_message( $chat_request );
assert_true( is_wp_error( $result ), '401 returns WP_Error' );
assert_equals( 'staff_ai_auth_failed', $result->get_error_code(), 'error code is staff_ai_auth_failed' );
// Verify no raw backend token/secret in message.
$msg = $result->get_error_message();
assert_true( strpos( $msg, 'eyJ' ) === false, 'no JWT in error message' );

// ── 6. Error mapping: 403 → same handling ───────────────────────────────
echo "\n[6] 403 → fail-closed error\n";
$_wp_test_remote_responses = array(
	array(
		'response' => array( 'code' => 403 ),
		'body'     => json_encode( array( 'detail' => 'Forbidden' ) ),
	),
);

$result = DEF_Core_Staff_AI::rest_send_message( $chat_request );
assert_true( is_wp_error( $result ), '403 returns WP_Error' );
assert_equals( 'staff_ai_auth_failed', $result->get_error_code(), '403 also maps to auth_failed' );

// ── 7. Error mapping: 5xx → service error ───────────────────────────────
echo "\n[7] 5xx → service error\n";
$_wp_test_remote_responses = array(
	array(
		'response' => array( 'code' => 500 ),
		'body'     => json_encode( array( 'detail' => 'Internal server error with stack trace' ) ),
	),
);

$result = DEF_Core_Staff_AI::rest_send_message( $chat_request );
assert_true( is_wp_error( $result ), '500 returns WP_Error' );
assert_equals( 'staff_ai_service_error', $result->get_error_code(), 'error code is staff_ai_service_error' );
$msg = $result->get_error_message();
assert_true( strpos( $msg, 'stack trace' ) === false, 'no backend stack trace leaked' );
assert_true( strpos( $msg, 'temporarily unavailable' ) !== false, 'user-safe message' );

// ── 8. Error mapping: 404 → not found ───────────────────────────────────
echo "\n[8] 404 → not found\n";
$_wp_test_remote_responses = array(
	array(
		'response' => array( 'code' => 404 ),
		'body'     => json_encode( array( 'detail' => 'Not found' ) ),
	),
);

$result = DEF_Core_Staff_AI::rest_send_message( $chat_request );
assert_true( is_wp_error( $result ), '404 returns WP_Error' );
assert_equals( 'staff_ai_not_found', $result->get_error_code(), 'error code is staff_ai_not_found' );

// ── 9. Network error → 502 ──────────────────────────────────────────────
echo "\n[9] Network error → 502\n";
$_wp_test_remote_responses = array(); // Empty = returns WP_Error from stub.
$result = DEF_Core_Staff_AI::rest_send_message( $chat_request );
assert_true( is_wp_error( $result ), 'network error returns WP_Error' );
assert_equals( 'staff_ai_request_failed', $result->get_error_code(), 'error code is staff_ai_request_failed' );

// ── 10. Escalation: service auth (X-DEF-AUTH) ───────────────────────────
echo "\n[10] Escalation service auth\n";
$secret = DEF_Core_Escalation::get_service_secret();
assert_true( strlen( $secret ) === 64, 'service secret is 64 hex chars (32 bytes)' );
assert_true( ctype_xdigit( $secret ), 'service secret is hex-only (HTTP-safe)' );

// Verify idempotent.
$secret2 = DEF_Core_Escalation::get_service_secret();
assert_equals( $secret, $secret2, 'secret is stable on second call' );

// Force regenerate.
$secret3 = DEF_Core_Escalation::get_service_secret( true );
assert_true( $secret3 !== $secret, 'force_regenerate produces new secret' );
assert_true( strlen( $secret3 ) === 64, 'regenerated secret is also 64 hex chars' );

// ── 11. Escalation: channel validation ──────────────────────────────────
echo "\n[11] Escalation channel validation\n";
assert_true( DEF_Core_Escalation::validate_channel( 'customer' ), 'customer is valid channel' );
assert_true( DEF_Core_Escalation::validate_channel( 'staff_ai' ), 'staff_ai is valid channel' );
assert_true( DEF_Core_Escalation::validate_channel( 'setup_assistant' ), 'setup_assistant is valid channel' );
assert_true( ! DEF_Core_Escalation::validate_channel( 'admin' ), 'admin is invalid channel' );
assert_true( ! DEF_Core_Escalation::validate_channel( '' ), 'empty string is invalid channel' );
assert_true( ! DEF_Core_Escalation::validate_channel( '../etc' ), 'path traversal rejected' );

// ── 12. Escalation email: validation ────────────────────────────────────
echo "\n[12] Escalation email input validation\n";
DEF_Core_Escalation::register_rest_routes();

// Missing channel.
$_wp_test_current_user = $user;
$_wp_test_user_caps    = array( 'def_staff_access' );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer dummy'; // Will be checked by permission_check.

$req = new WP_REST_Request( 'POST', '/escalation/send-email' );
$req->set_body_params( array(
	'channel' => '',
	'subject' => 'Test',
	'body'    => 'Test body',
) );
$result = DEF_Core_Escalation::send_escalation_email( $req );
assert_true( $result instanceof WP_REST_Response, 'returns WP_REST_Response' );
assert_equals( 400, $result->get_status(), 'invalid channel → 400' );
$data = $result->get_data();
assert_equals( 'VALIDATION_ERROR', $data['error'], 'error code is VALIDATION_ERROR' );

// Missing subject.
$req->set_body_params( array(
	'channel' => 'customer',
	'subject' => '',
	'body'    => 'Test body',
) );
$result = DEF_Core_Escalation::send_escalation_email( $req );
assert_equals( 400, $result->get_status(), 'missing subject → 400' );

// Missing body.
$req->set_body_params( array(
	'channel' => 'customer',
	'subject' => 'Test',
	'body'    => '',
) );
$result = DEF_Core_Escalation::send_escalation_email( $req );
assert_equals( 400, $result->get_status(), 'missing body → 400' );

// ── 13. Escalation: staff_ai allowed_recipients enforcement ─────────────
echo "\n[13] Staff AI allowed_recipients enforcement\n";
update_option( 'def_core_escalation_staff_ai', array(
	'allowed_recipients' => array( 'admin@example.com' ),
) );
update_option( 'admin_email', 'admin@example.com' );

$req->set_body_params( array(
	'channel' => 'staff_ai',
	'subject' => 'Test',
	'body'    => 'Test body',
	'to'      => array( 'hacker@evil.com' ),
) );
$result = DEF_Core_Escalation::send_escalation_email( $req );
assert_equals( 400, $result->get_status(), 'non-allowed recipient rejected' );
$data = $result->get_data();
assert_true( strpos( $data['message'], 'allowed_recipients' ) !== false, 'error mentions allowed_recipients' );

// Cleanup.
$_wp_test_current_user = null;
$_wp_test_user_caps    = array();

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n--- Bridge Contract Tests: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
