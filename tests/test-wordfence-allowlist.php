<?php
/**
 * Wordfence auto-allowlist tests.
 *
 * Verifies DEF_Core_HMAC_Auth::maybe_allowlist_wordfence_ip() behaviour,
 * exercised through the public verify_request() trust gate:
 *  - A valid HMAC signature allowlists the caller's egress IP in Wordfence.
 *  - The same IP is NOT re-written on subsequent requests (de-dupe).
 *  - A different IP IS allowlisted (the set grows).
 *  - A failed HMAC (bad signature) allowlists NOTHING.
 *  - When Wordfence is not installed, allowlisting is a silent no-op and the
 *    valid request still passes.
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Minimal WP stubs not provided by wp-stubs.php ───────────────────────

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
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $method = 'GET';
		private $route  = '';
		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}
		public function get_method(): string { return $this->method; }
		public function get_route(): string { return $this->route; }
		public function get_query_params(): array { return array(); }
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-hmac-auth.php';

use A3Rev\DefCore\DEF_Core_HMAC_Auth;

// ── Tiny assertion harness (mirrors test-admin-api.php) ─────────────────

$pass = 0;
$fail = 0;

function assert_true( $value, string $label ): void {
	global $pass, $fail;
	if ( $value ) { $pass++; } else { $fail++; echo "  FAIL: $label\n"; }
}
function assert_false( $value, string $label ): void {
	global $pass, $fail;
	if ( ! $value ) { $pass++; } else { $fail++; echo "  FAIL: $label (expected false)\n"; }
}
function assert_equals( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) { $pass++; } else {
		$fail++;
		echo "  FAIL: $label (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
	}
}

/**
 * Build a valid HMAC request for /def-core/v1/export against the given key,
 * setting the $_SERVER headers verify_request() reads. Body is always empty
 * (php://input is empty under CLI), so body_hash = sha256('').
 */
function sign_valid_request( string $api_key, string $route = '/def-core/v1/export' ): WP_REST_Request {
	$method    = 'GET';
	$timestamp = (string) time();
	$user_id   = 'system';
	$body_hash = hash( 'sha256', '' );
	$payload   = "{$method}:{$route}:{$timestamp}:{$user_id}:{$body_hash}";

	$_SERVER['HTTP_X_DEF_SIGNATURE'] = hash_hmac( 'sha256', $payload, $api_key );
	$_SERVER['HTTP_X_DEF_TIMESTAMP'] = $timestamp;
	$_SERVER['HTTP_X_DEF_USER']      = $user_id;
	$_SERVER['HTTP_X_DEF_BODY_HASH'] = $body_hash;

	return new WP_REST_Request( $method, $route );
}

function clear_hmac_headers(): void {
	unset(
		$_SERVER['HTTP_X_DEF_SIGNATURE'],
		$_SERVER['HTTP_X_DEF_TIMESTAMP'],
		$_SERVER['HTTP_X_DEF_USER'],
		$_SERVER['HTTP_X_DEF_BODY_HASH']
	);
}

/** Reset the in-memory WP option store (wp-stubs.php backing global). */
function reset_options(): void {
	global $_wp_test_options;
	$_wp_test_options = array();
}

$api_key      = 'wf_test_api_key_abc123';
$option_name  = 'def_core_wf_allowlisted_ips';

// ── 1. Wordfence absent: valid HMAC passes, nothing allowlisted ─────────
// Declared BEFORE the wordfence/wfUtils stubs exist, so class_exists() is
// false here and the allowlist path must be a clean no-op.
echo "[1] Wordfence not installed: valid HMAC is a no-op\n";
reset_options();
clear_hmac_headers();
update_option( 'def_core_api_key', $api_key );

$request = sign_valid_request( $api_key );
$result  = DEF_Core_HMAC_Auth::verify_request( $request );
assert_true( $result === true, 'valid HMAC returns true with Wordfence absent' );
assert_equals( false, get_option( $option_name, false ), 'no allowlist option written when Wordfence absent' );

// ── Define Wordfence stubs (conditionally → declared at this point) ─────
if ( ! class_exists( 'wfUtils' ) ) {
	class wfUtils {
		public static function getIP() {
			if ( ! empty( $GLOBALS['wf_throw_on_getip'] ) ) {
				throw new \RuntimeException( 'simulated wfUtils::getIP failure' );
			}
			return $GLOBALS['wf_current_ip'] ?? '203.0.113.7';
		}
	}
}
if ( ! class_exists( 'wordfence' ) ) {
	class wordfence {
		public static function whitelistIP( $ip ): void {
			if ( ! empty( $GLOBALS['wf_throw_on_whitelist'] ) ) {
				// Wordfence's real whitelistIP() throws on failure — simulate that.
				throw new \Exception( 'simulated wordfence::whitelistIP failure' );
			}
			$GLOBALS['wf_whitelist_calls'][] = $ip;
		}
	}
}

$GLOBALS['wf_whitelist_calls'] = array();
$GLOBALS['wf_current_ip']      = '203.0.113.7';
$GLOBALS['wf_throw_on_whitelist'] = false;
$GLOBALS['wf_throw_on_getip']     = false;

// ── 2. Valid HMAC with Wordfence active: IP allowlisted once ────────────
echo "[2] Valid HMAC: egress IP allowlisted in Wordfence\n";
reset_options();
clear_hmac_headers();
update_option( 'def_core_api_key', $api_key );
$GLOBALS['wf_whitelist_calls'] = array();
$GLOBALS['wf_current_ip']      = '203.0.113.7';

$result = DEF_Core_HMAC_Auth::verify_request( sign_valid_request( $api_key ) );
assert_true( $result === true, 'valid HMAC returns true' );
assert_equals( array( '203.0.113.7' ), $GLOBALS['wf_whitelist_calls'], 'whitelistIP called once with the egress IP' );
$seen = get_option( $option_name, array() );
assert_true( is_array( $seen ) && isset( $seen['203.0.113.7'] ), 'IP recorded in de-dupe option' );

// ── 3. De-dupe: same IP on a second request does NOT re-write config ────
echo "[3] De-dupe: same IP is not re-allowlisted\n";
$GLOBALS['wf_whitelist_calls'] = array();
$result = DEF_Core_HMAC_Auth::verify_request( sign_valid_request( $api_key ) );
assert_true( $result === true, 'second valid HMAC returns true' );
assert_equals( array(), $GLOBALS['wf_whitelist_calls'], 'whitelistIP NOT called again for a known IP' );

// ── 4. New IP IS allowlisted (the set grows) ────────────────────────────
echo "[4] New egress IP is allowlisted\n";
$GLOBALS['wf_whitelist_calls'] = array();
$GLOBALS['wf_current_ip']      = '198.51.100.42';
$result = DEF_Core_HMAC_Auth::verify_request( sign_valid_request( $api_key ) );
assert_true( $result === true, 'valid HMAC from new IP returns true' );
assert_equals( array( '198.51.100.42' ), $GLOBALS['wf_whitelist_calls'], 'whitelistIP called for the new IP' );
$seen = get_option( $option_name, array() );
assert_true( isset( $seen['203.0.113.7'] ) && isset( $seen['198.51.100.42'] ), 'both IPs tracked in option' );

// ── 5. Failed HMAC (bad signature) allowlists NOTHING ───────────────────
echo "[5] Invalid HMAC: no IP allowlisted\n";
reset_options();
clear_hmac_headers();
update_option( 'def_core_api_key', $api_key );
$GLOBALS['wf_whitelist_calls'] = array();
$GLOBALS['wf_current_ip']      = '203.0.113.99';

$request = sign_valid_request( $api_key );
$_SERVER['HTTP_X_DEF_SIGNATURE'] = 'tampered_signature'; // Break the signature.
$result = DEF_Core_HMAC_Auth::verify_request( $request );
assert_true( is_wp_error( $result ), 'tampered HMAC returns WP_Error' );
assert_equals( 'HMAC_INVALID_SIGNATURE', $result->get_error_code(), 'error code is HMAC_INVALID_SIGNATURE' );
assert_equals( array(), $GLOBALS['wf_whitelist_calls'], 'whitelistIP NOT called on invalid signature' );
assert_equals( false, get_option( $option_name, false ), 'no allowlist option written on invalid signature' );

// ── 6. Headline safety property: an allowlisting failure NEVER turns a ──
//     valid request into an auth failure. Drives the catch(\Throwable) path.
echo "[6] Allowlisting failure never breaks a valid request\n";

// 6a. wordfence::whitelistIP() throws for a fresh, verified request.
reset_options();
clear_hmac_headers();
update_option( 'def_core_api_key', $api_key );
$GLOBALS['wf_whitelist_calls'] = array();
$GLOBALS['wf_current_ip']      = '203.0.113.55'; // Fresh IP → attempts whitelistIP.
$GLOBALS['wf_throw_on_whitelist'] = true;

$result = DEF_Core_HMAC_Auth::verify_request( sign_valid_request( $api_key ) );
assert_true( $result === true, 'verify_request still returns true when whitelistIP() throws' );
$seen = get_option( $option_name, array() );
assert_true(
	! is_array( $seen ) || ! isset( $seen['203.0.113.55'] ),
	'IP is NOT marked seen when the Wordfence write threw (so it retries next time)'
);
$GLOBALS['wf_throw_on_whitelist'] = false;

// 6b. wfUtils::getIP() throws for a verified request.
reset_options();
clear_hmac_headers();
update_option( 'def_core_api_key', $api_key );
$GLOBALS['wf_whitelist_calls'] = array();
$GLOBALS['wf_throw_on_getip']  = true;

$result = DEF_Core_HMAC_Auth::verify_request( sign_valid_request( $api_key ) );
assert_true( $result === true, 'verify_request still returns true when getIP() throws' );
assert_equals( array(), $GLOBALS['wf_whitelist_calls'], 'whitelistIP not reached when getIP() throws' );
$GLOBALS['wf_throw_on_getip'] = false;

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
