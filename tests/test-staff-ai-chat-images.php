<?php
/**
 * A picture stays in the chat — the console half (8.0.0, DEF images runsheet I-2).
 *
 * Pins the four WordPress-side pieces:
 *  1. rest_upload_init() forwards the console's companion-thumbnail declaration
 *     to DEF, as declared, and forwards nothing when there is none or it is junk.
 *  2. rest_load_conversation() carries a stored turn's attachments to the console
 *     as identity only — {file_id, kind, mime_type, filename, has_thumbnail} —
 *     with every junk entry dropped and no storage key on the wire.
 *  3. handle_attachment(), the cookie-auth'd proxy for /staff-ai-attachment/…:
 *     the download proxy's gates in the download proxy's order (400 on a bad id,
 *     401, 403, then the backend), the BFF header set and never a JWT, DEF's
 *     read-back route with the console's thread and the variant, DEF's own
 *     refusal passed through — and the headers it serves with: `Cache-Control:
 *     private, max-age=31536000, immutable` (the point of the design, D-I4),
 *     never the no-store WordPress sends every logged-in response with.
 *  4. maybe_flush_rewrite_rules(): once per plugin version, because an update
 *     never fires the activation hook that would otherwise flush.
 *
 * Runs standalone (no WordPress bootstrap), the test-staff-ai-download-auth.php
 * harness pattern.
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
if ( ! defined( 'DEF_CORE_VERSION' ) ) {
	define( 'DEF_CORE_VERSION', '8.0.0' );
}

require_once __DIR__ . '/wp-stubs.php';

// ── Controllable state ───────────────────────────────────────────────────

global $_wp_test_current_user, $_wp_test_user_caps, $_wp_test_query_vars;
global $_wp_test_remote_responses, $_wp_test_remote_calls, $_wp_test_flushes;
$_wp_test_current_user     = null;
$_wp_test_user_caps        = array();
$_wp_test_query_vars       = array();
$_wp_test_remote_responses = array();
$_wp_test_remote_calls     = array();
$_wp_test_flushes          = 0;

// ── Stubs ────────────────────────────────────────────────────────────────

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
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
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
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params;
		private $json;
		public function __construct( array $params = array(), array $json = array() ) {
			$this->params = $params;
			$this->json   = $json;
		}
		public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
		public function get_json_params() { return $this->json; }
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
		public function get_data() { return $this->data; }
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
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $t, string $d = 'default' ): string { return $t; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string { return 'name' === $show ? 'Test Site' : ''; }
}
if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {}
}
if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( bool $hard = true ): void {
		global $_wp_test_flushes;
		$_wp_test_flushes++;
	}
}
foreach ( array( 'add_action', 'add_filter', 'add_rewrite_rule', 'register_rest_route' ) as $noop ) {
	if ( ! function_exists( $noop ) ) {
		eval( "function $noop( ...\$a ): void {}" );
	}
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

// Capture outbound calls; a queued response answers, else WP_Error (the proxy
// halts in its error branch AFTER the call — headers are captured before it).
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
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( string $url, array $args = array() ) {
		global $_wp_test_remote_calls, $_wp_test_remote_responses;
		$_wp_test_remote_calls[] = array( 'method' => $args['method'] ?? 'POST', 'url' => $url, 'args' => $args );
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
if ( ! class_exists( 'DEF_Core' ) ) {
	class DEF_Core {
		public static function get_def_api_url_internal(): ?string {
			$url = get_option( 'def_core_staff_ai_api_url', '' );
			return ! empty( $url ) ? rtrim( $url, '/' ) : null;
		}
	}
}
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
update_option( 'def_core_api_key', 'test-api-key-images' );

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

function call_private( string $method, ...$args ) {
	$m = new ReflectionMethod( 'DEF_Core_Staff_AI', $method );
	$m->setAccessible( true );
	return $m->invoke( null, ...$args );
}

function invoke_attachment(): ?WPDieException {
	try {
		call_private( 'handle_attachment' );
	} catch ( WPDieException $e ) {
		return $e;
	}
	return null;
}

function reset_state( bool $logged_in, array $caps, array $query = array() ): void {
	global $_wp_test_current_user, $_wp_test_user_caps, $_wp_test_query_vars, $_wp_test_remote_calls, $_wp_test_remote_responses;
	$_wp_test_current_user     = $logged_in ? new WP_User( 7 ) : null;
	$_wp_test_user_caps        = $caps;
	$_wp_test_query_vars       = $query ?: array(
		'staff_ai_attachment' => 'upload_0123abcd',
		'staff_ai_thread'     => 'staff-1a2b3c4d5e6f7a8b',
		'staff_ai_variant'    => 'thumbnail',
	);
	$_wp_test_remote_calls     = array();
	$_wp_test_remote_responses = array();
}

function ok_json( array $data ): array {
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $data ), 'headers' => array() );
}

// ── 1. The init proxy forwards the companion declaration ─────────────────

echo "The init proxy and the companion declaration\n";

function init_body_sent( array $json ): array {
	global $_wp_test_remote_calls, $_wp_test_remote_responses;
	reset_state( true, array( 'def_staff_access' ) );
	$_wp_test_remote_responses[] = ok_json( array( 'file_id' => 'upload_1', 'upload_url' => 'https://blob/o' ) );
	DEF_Core_Staff_AI::rest_upload_init( new WP_REST_Request( array(), $json ) );
	return json_decode( $_wp_test_remote_calls[0]['args']['body'] ?? '{}', true );
}

$picture = array( 'filename' => 'garden.png', 'mime_type' => 'image/png', 'size_bytes' => 2600000, 'conversation_id' => 'staff-1' );

$sent = init_body_sent( $picture + array( 'thumbnail' => array( 'mime_type' => 'image/jpeg', 'size_bytes' => 41000 ) ) );
check( ( $sent['thumbnail'] ?? null ) === array( 'mime_type' => 'image/jpeg', 'size_bytes' => 41000 ), 'a declared companion reaches DEF as declared' );
check( $sent['filename'] === 'garden.png' && $sent['size_bytes'] === 2600000 && $sent['conversation_id'] === 'staff-1', 'the picture\'s own fields ride as before' );

$sent = init_body_sent( $picture );
check( ! array_key_exists( 'thumbnail', $sent ), 'no declaration → no thumbnail key (exactly today)' );

$sent = init_body_sent( $picture + array( 'thumbnail' => 'junk' ) );
check( ! array_key_exists( 'thumbnail', $sent ), 'a declaration that is not an object is dropped' );

$sent = init_body_sent( $picture + array( 'thumbnail' => array( 'mime_type' => 'image/jpeg', 'size_bytes' => 0 ) ) );
check( ! array_key_exists( 'thumbnail', $sent ), 'a declaration with no size is dropped' );

$sent = init_body_sent( $picture + array( 'thumbnail' => array( 'mime_type' => 'image/jpeg', 'size_bytes' => 400000 ) ) );
check( ( $sent['thumbnail']['size_bytes'] ?? 0 ) === 400000, 'the bound is the server\'s: an oversized declaration is forwarded, not judged here' );

// ── 2. The history mapper carries a stored turn's attachments ────────────

echo "\nThe history mapper\n";

reset_state( true, array( 'def_staff_access' ) );
$_wp_test_remote_responses[] = ok_json( array( 'messages' => array(
	array(
		'role' => 'user', 'content' => 'here', 'timestamp' => '2026-09-14T08:00:00Z',
		'attachments' => array(
			array( 'file_id' => 'upload_0123abcd', 'kind' => 'image', 'mime_type' => 'image/png', 'filename' => 'garden.png',
				'has_thumbnail' => true, 'storage' => array( 'backend' => 'azure_blob', 'object_key' => 't/x/y' ) ),
			array( 'file_id' => 'upload_9', 'kind' => 'document', 'mime_type' => 'application/pdf', 'filename' => '<b>q</b>.pdf' ),
			array( 'file_id' => '../etc/passwd', 'mime_type' => 'image/png' ),
			'junk',
		),
	),
	array( 'role' => 'assistant', 'content' => 'ok', 'timestamp' => '2026-09-14T08:00:01Z' ),
) ) );
$resp = DEF_Core_Staff_AI::rest_load_conversation( new WP_REST_Request( array( 'id' => 'staff-1' ) ) );
$msgs = $resp instanceof WP_REST_Response ? ( $resp->data['messages'] ?? array() ) : array();
check( count( $msgs ) === 2, 'both turns come back' );
check( ( $msgs[0]['attachments'] ?? null ) === array(
	array( 'file_id' => 'upload_0123abcd', 'kind' => 'image', 'mime_type' => 'image/png', 'filename' => 'garden.png', 'has_thumbnail' => true ),
	array( 'file_id' => 'upload_9', 'kind' => 'document', 'mime_type' => 'application/pdf', 'filename' => 'q.pdf', 'has_thumbnail' => false ),
), 'a stored turn\'s attachments come back as identity only — junk ids and non-objects dropped, markup stripped, no storage key' );
check( ( $msgs[1]['attachments'] ?? null ) === array(), 'a turn without attachments carries an empty list' );

// ── 3. The attachment proxy ──────────────────────────────────────────────

echo "\nThe attachment proxy\n";

reset_state( true, array( 'def_staff_access' ) );
$e = invoke_attachment();
check( count( $GLOBALS['_wp_test_remote_calls'] ) === 1, 'exactly one backend call made' );
$call    = $GLOBALS['_wp_test_remote_calls'][0];
$headers = $call['args']['headers'] ?? array();
check( $call['url'] === 'http://backend:8000/api/staff_ai/uploads/upload_0123abcd/content?thread_id=staff-1a2b3c4d5e6f7a8b&variant=thumbnail',
	'DEF\'s read-back route, with the console\'s thread and the variant' );
check( ( $headers['X-DEF-API-Key'] ?? '' ) === 'test-api-key-images' && ( $headers['X-DEF-User'] ?? '' ) === '7'
	&& strpos( $headers['X-DEF-User-Capabilities'] ?? '', 'def_staff_access' ) !== false, 'the BFF header set rides the request' );
check( ! isset( $headers['Authorization'] ), 'no Authorization/JWT header' );
check( $e !== null && strpos( $e->getMessage(), 'test-api-key-images' ) === false, 'the API key never appears in the error surface' );

reset_state( true, array( 'def_staff_access' ), array( 'staff_ai_attachment' => 'upload_0123abcd', 'staff_ai_thread' => 'staff-1a2b3c4d5e6f7a8b' ) );
invoke_attachment();
check( substr( $GLOBALS['_wp_test_remote_calls'][0]['url'], -16 ) === 'variant=original', 'no variant in the address → the original' );

reset_state( false, array() );
$e = invoke_attachment();
check( $e !== null && $e->status === 401 && count( $GLOBALS['_wp_test_remote_calls'] ) === 0, 'anonymous → 401, no backend call' );

reset_state( true, array() );
$e = invoke_attachment();
check( $e !== null && $e->status === 403 && count( $GLOBALS['_wp_test_remote_calls'] ) === 0, 'no staff capability → 403, no backend call' );

reset_state( true, array( 'def_staff_access' ), array( 'staff_ai_attachment' => '../etc/passwd', 'staff_ai_thread' => 'staff-1' ) );
$e = invoke_attachment();
check( $e !== null && $e->status === 400 && count( $GLOBALS['_wp_test_remote_calls'] ) === 0, 'a file id that is not an id → 400 before anything' );

reset_state( true, array( 'def_staff_access' ), array( 'staff_ai_attachment' => 'upload_1', 'staff_ai_thread' => 'staff 1/../x' ) );
$e = invoke_attachment();
check( $e !== null && $e->status === 400 && count( $GLOBALS['_wp_test_remote_calls'] ) === 0, 'a thread that is not an id → 400 before anything' );

reset_state( true, array( 'def_staff_access' ) );
$GLOBALS['_wp_test_remote_responses'][] = array( 'response' => array( 'code' => 404 ), 'body' => '{"detail":"Upload not found"}', 'headers' => array() );
$e = invoke_attachment();
check( $e !== null && $e->status === 404 && strpos( $e->getMessage(), 'Upload not found' ) === false, 'DEF\'s 404 (not this reader\'s, in this thread) stands; its body does not travel' );

// The headers a served attachment carries — pure, pinned without the exit.
$lines = call_private( 'attachment_response_headers', 'image/png', 'garden.png', 123 );
check( in_array( 'Cache-Control: private, max-age=31536000, immutable', $lines, true ), 'cacheable by this reader\'s browser, for a year, immutable (D-I4)' );
check( 0 === count( preg_grep( '/no-store|no-cache|max-age=0/', $lines ) ), 'not the no-store WordPress sends every logged-in response with' );
check( in_array( 'Content-Type: image/png', $lines, true ) && in_array( 'Content-Disposition: inline; filename="garden.png"', $lines, true ), 'a picture is served inline with its name' );
check( in_array( 'X-Content-Type-Options: nosniff', $lines, true ) && in_array( 'Content-Length: 123', $lines, true ), 'nosniff and the length' );
$lines = call_private( 'attachment_response_headers', 'text/html; charset=utf-8', 'x.html', 1 );
check( in_array( 'Content-Type: application/octet-stream', $lines, true ) && in_array( 'Content-Disposition: attachment; filename="x.html"', $lines, true ), 'a type that could run in the browser is served as an opaque attachment' );
$lines = call_private( 'attachment_response_headers', 'application/pdf', "q\r\nX: y.pdf", 5 );
check( in_array( 'Content-Disposition: attachment; filename="qX: y.pdf"', $lines, true ), 'a document is an attachment; a name cannot inject a header' );

// ── 4. The once-per-version flush ────────────────────────────────────────

echo "\nThe rewrite flush\n";

$GLOBALS['_wp_test_flushes'] = 0;
DEF_Core_Staff_AI::maybe_flush_rewrite_rules();
check( $GLOBALS['_wp_test_flushes'] === 1 && get_option( 'def_core_rewrite_version' ) === '8.0.0', 'a version not yet flushed flushes once and stamps the version' );
DEF_Core_Staff_AI::maybe_flush_rewrite_rules();
check( $GLOBALS['_wp_test_flushes'] === 1, 'the same version never flushes again' );
update_option( 'def_core_rewrite_version', '7.9.19' );
DEF_Core_Staff_AI::maybe_flush_rewrite_rules();
check( $GLOBALS['_wp_test_flushes'] === 2, 'an update (the stamp is the old version) flushes once more' );

$vars = DEF_Core_Staff_AI::add_query_vars( array() );
check( in_array( 'staff_ai_attachment', $vars, true ) && in_array( 'staff_ai_thread', $vars, true ) && in_array( 'staff_ai_variant', $vars, true ), 'the three query vars the rewrite fills are registered' );

// ── Summary ──────────────────────────────────────────────────────────────

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
