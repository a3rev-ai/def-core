<?php
/**
 * Security regression tests for CRITICAL fixes.
 *
 * Tests:
 * - C1: JWT audience (aud) claim enforcement
 * - C1b: JWT not-before (nbf) claim enforcement
 * - C2: Content-Disposition header injection prevention
 * - C3: Content-Type reflection XSS prevention
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// Additional stubs for Staff AI methods.
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
			$this->data = $data; $this->status = $status;
		}
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = array();
		private $headers = array();
		private $body = array();
		public function __construct( string $m = 'GET', string $r = '' ) {}
		public function set_param( string $k, $v ): void { $this->params[$k] = $v; }
		public function get_param( string $k ) { return $this->params[$k] ?? null; }
		public function get_json_params(): array { return $this->body; }
		public function set_body_params( array $b ): void { $this->body = $b; }
		public function get_header( string $k ): ?string { return $this->headers[strtolower($k)] ?? null; }
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( ...$a ): void {}
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
	function is_user_logged_in(): bool { return false; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $c ): bool { return false; }
}
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( string $v, $d = '' ) { return $d; }
}
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID = 0;
		public function exists(): bool { return $this->ID > 0; }
		public function has_cap( string $c ): bool { return false; }
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): WP_User { return new WP_User(); }
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-jwt.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-staff-ai.php';

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

function assert_null( $value, string $label ): void {
	assert_equals( null, $value, $label );
}

echo "=== Security Regression Tests ===\n";

// ── Setup ───────────────────────────────────────────────────────────────
_wp_test_reset_options();
$keys = _wp_test_seed_rsa_keys();

// =====================================================================
// C1: JWT audience (aud) claim enforcement
// =====================================================================
echo "\n[C1] JWT audience claim enforcement\n";

// 1. Token with correct audience → accepted.
$valid_claims = array(
	'sub' => '1',
	'iss' => 'https://test.example.com',
	'aud' => DEF_CORE_AUDIENCE, // 'digital-employee-framework'
);
$valid_token = DEF_Core_JWT::issue_token( $valid_claims, 300 );
$result      = DEF_Core_JWT::verify_token( $valid_token );
assert_true( is_array( $result ), 'correct aud accepted' );
assert_equals( '1', $result['sub'], 'payload preserved with correct aud' );

// 2. Token with wrong audience → rejected.
// Forge a token with wrong aud by manually building it.
$wrong_aud_claims = array(
	'sub' => '1',
	'iss' => 'https://test.example.com',
	'aud' => 'some-other-service',
	'iat' => time(),
	'exp' => time() + 300,
	'nbf' => time() - 30,
	'jti' => 'test-' . mt_rand(),
);

// Sign with the real key.
$header_json    = json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT', 'kid' => $keys['kid'] ) );
$payload_json   = json_encode( $wrong_aud_claims, JSON_UNESCAPED_SLASHES );
$b64url_encode  = function ( string $d ): string { return rtrim( strtr( base64_encode( $d ), '+/', '-_' ), '=' ); };
$encoded_header = $b64url_encode( $header_json );
$encoded_body   = $b64url_encode( $payload_json );
$signing_input  = $encoded_header . '.' . $encoded_body;
$php_dir        = dirname( PHP_BINARY );
$cnf            = $php_dir . '/extras/ssl/openssl.cnf';
$config         = file_exists( $cnf ) ? array( 'config' => $cnf ) : array();
$private_key    = openssl_pkey_get_private( $keys['private'] );
$signature      = '';
openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
$wrong_aud_token = $signing_input . '.' . $b64url_encode( $signature );

$result = DEF_Core_JWT::verify_token( $wrong_aud_token );
assert_null( $result, 'wrong aud rejected' );

// 3. Token with aud as array not containing our audience → rejected.
$array_aud_claims = $wrong_aud_claims;
$array_aud_claims['aud'] = array( 'service-a', 'service-b' );
$payload_json   = json_encode( $array_aud_claims, JSON_UNESCAPED_SLASHES );
$encoded_body   = $b64url_encode( $payload_json );
$signing_input  = $encoded_header . '.' . $encoded_body;
openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
$array_wrong_token = $signing_input . '.' . $b64url_encode( $signature );

$result = DEF_Core_JWT::verify_token( $array_wrong_token );
assert_null( $result, 'array aud not containing our audience rejected' );

// 4. Token with aud as array containing our audience → accepted.
$array_ok_claims = $wrong_aud_claims;
$array_ok_claims['aud'] = array( 'other-service', DEF_CORE_AUDIENCE );
$payload_json   = json_encode( $array_ok_claims, JSON_UNESCAPED_SLASHES );
$encoded_body   = $b64url_encode( $payload_json );
$signing_input  = $encoded_header . '.' . $encoded_body;
openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
$array_ok_token = $signing_input . '.' . $b64url_encode( $signature );

$result = DEF_Core_JWT::verify_token( $array_ok_token );
assert_true( is_array( $result ), 'array aud containing our audience accepted' );

// 5. Token with no aud claim → accepted (backwards compat — external tokens may not set aud).
$no_aud_claims = $wrong_aud_claims;
unset( $no_aud_claims['aud'] );
$payload_json   = json_encode( $no_aud_claims, JSON_UNESCAPED_SLASHES );
$encoded_body   = $b64url_encode( $payload_json );
$signing_input  = $encoded_header . '.' . $encoded_body;
openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
$no_aud_token = $signing_input . '.' . $b64url_encode( $signature );

$result = DEF_Core_JWT::verify_token( $no_aud_token );
assert_true( is_array( $result ), 'no aud claim still accepted (backwards compat)' );

// =====================================================================
// C1b: JWT not-before (nbf) claim enforcement
// =====================================================================
echo "\n[C1b] JWT not-before (nbf) claim enforcement\n";

// Token with nbf far in the future → rejected.
$future_nbf_claims = array(
	'sub' => '1',
	'iss' => 'https://test.example.com',
	'aud' => DEF_CORE_AUDIENCE,
	'iat' => time(),
	'exp' => time() + 3600,
	'nbf' => time() + 3600, // Not valid for another hour.
	'jti' => 'test-nbf',
);
$payload_json   = json_encode( $future_nbf_claims, JSON_UNESCAPED_SLASHES );
$encoded_body   = $b64url_encode( $payload_json );
$signing_input  = $encoded_header . '.' . $encoded_body;
openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
$future_nbf_token = $signing_input . '.' . $b64url_encode( $signature );

$result = DEF_Core_JWT::verify_token( $future_nbf_token );
assert_null( $result, 'future nbf rejected' );

// Token with nbf in the past → accepted.
$past_nbf_claims = $future_nbf_claims;
$past_nbf_claims['nbf'] = time() - 60;
$payload_json   = json_encode( $past_nbf_claims, JSON_UNESCAPED_SLASHES );
$encoded_body   = $b64url_encode( $payload_json );
$signing_input  = $encoded_header . '.' . $encoded_body;
openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
$past_nbf_token = $signing_input . '.' . $b64url_encode( $signature );

$result = DEF_Core_JWT::verify_token( $past_nbf_token );
assert_true( is_array( $result ), 'past nbf accepted' );

// =====================================================================
// C2: Content-Disposition header injection prevention
// =====================================================================
echo "\n[C2] Content-Disposition filename sanitization\n";

// Use reflection to access the private method.
$ref = new ReflectionMethod( 'DEF_Core_Staff_AI', 'sanitize_proxy_filename' );
$ref->setAccessible( true );

// Normal filename → passes through.
$result = $ref->invoke( null, 'report.pdf' );
assert_equals( 'report.pdf', $result, 'normal filename preserved' );

// Filename with double quotes → stripped.
$result = $ref->invoke( null, 'file"name.pdf' );
assert_true( strpos( $result, '"' ) === false, 'double quotes stripped' );

// Filename with newline (header injection attempt) → stripped.
$result = $ref->invoke( null, "file\r\nX-Injected: evil\r\n.pdf" );
assert_true( strpos( $result, "\r" ) === false, 'CR stripped' );
assert_true( strpos( $result, "\n" ) === false, 'LF stripped' );
// Note: "X-Injected: evil" text remains but is harmless without CRLF — it's just part of the filename.
assert_true( strpos( $result, "\r\n" ) === false, 'CRLF sequence fully removed (injection neutralised)' );

// Filename with null byte → stripped.
$result = $ref->invoke( null, "file\x00.pdf" );
assert_true( strpos( $result, "\x00" ) === false, 'null byte stripped' );

// Filename with backslash → stripped.
$result = $ref->invoke( null, 'file\\name.pdf' );
assert_true( strpos( $result, '\\' ) === false, 'backslash stripped' );

// Filename with path traversal → stripped.
$result = $ref->invoke( null, '../../../etc/passwd' );
assert_true( strpos( $result, '/' ) === false, 'path separators stripped' );
assert_true( strpos( $result, '..' ) === false, 'path traversal dots stripped after separator removal' );

// Empty filename → fallback to 'download'.
$result = $ref->invoke( null, '' );
assert_equals( 'download', $result, 'empty filename falls back to download' );

// Only control chars → fallback to 'download'.
$result = $ref->invoke( null, "\r\n\x00" );
assert_equals( 'download', $result, 'all-control-chars falls back to download' );

// Unicode filename → preserved.
$result = $ref->invoke( null, 'résumé.pdf' );
assert_true( strpos( $result, 'résumé' ) !== false, 'unicode filename preserved' );

// =====================================================================
// C3: Content-Type reflection XSS prevention
// =====================================================================
echo "\n[C3] Content-Type sanitization\n";

$ref_ct = new ReflectionMethod( 'DEF_Core_Staff_AI', 'sanitize_proxy_content_type' );
$ref_ct->setAccessible( true );

// Safe types → preserved.
assert_equals( 'application/pdf', $ref_ct->invoke( null, 'application/pdf' ), 'application/pdf preserved' );
assert_equals( 'image/png', $ref_ct->invoke( null, 'image/png' ), 'image/png preserved' );
assert_equals( 'text/csv', $ref_ct->invoke( null, 'text/csv' ), 'text/csv preserved' );
assert_equals( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $ref_ct->invoke( null, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ), 'xlsx type preserved' );

// Dangerous types → forced to application/octet-stream.
assert_equals( 'application/octet-stream', $ref_ct->invoke( null, 'text/html' ), 'text/html blocked' );
assert_equals( 'application/octet-stream', $ref_ct->invoke( null, 'text/html; charset=utf-8' ), 'text/html with charset blocked' );
assert_equals( 'application/octet-stream', $ref_ct->invoke( null, 'TEXT/HTML' ), 'TEXT/HTML (case insensitive) blocked' );
assert_equals( 'application/octet-stream', $ref_ct->invoke( null, 'application/xhtml+xml' ), 'xhtml+xml blocked' );
assert_equals( 'application/octet-stream', $ref_ct->invoke( null, 'image/svg+xml' ), 'svg+xml blocked' );
assert_equals( 'application/octet-stream', $ref_ct->invoke( null, 'application/javascript' ), 'javascript blocked' );
assert_equals( 'application/octet-stream', $ref_ct->invoke( null, 'text/javascript' ), 'text/javascript blocked' );
assert_equals( 'application/octet-stream', $ref_ct->invoke( null, 'application/xml' ), 'application/xml blocked' );
assert_equals( 'application/octet-stream', $ref_ct->invoke( null, 'text/xml' ), 'text/xml blocked' );

// Empty → fallback.
assert_equals( 'application/octet-stream', $ref_ct->invoke( null, '' ), 'empty type falls back' );

// Garbage → fallback.
assert_equals( 'application/octet-stream', $ref_ct->invoke( null, 'not-a-mime-type' ), 'garbage type falls back' );

// Charset stripped, base type used.
assert_equals( 'application/pdf', $ref_ct->invoke( null, 'application/pdf; charset=utf-8' ), 'charset stripped, base type used' );

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n--- Security Regression Tests: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
