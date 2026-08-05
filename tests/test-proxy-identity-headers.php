<?php
/**
 * BFF Proxy Identity Headers Tests
 *
 * Verifies:
 * - build_proxy_headers(true) includes identity headers (staff_ai / setup_assistant)
 * - build_proxy_headers(false) does NOT include identity headers (customer chat privacy)
 * - Unicode display names survive rawurlencode round-trip
 * - Missing/empty identity fields produce no broken headers
 * - X-DEF-Client-IP carries the visitor's own IP on every channel, is validated,
 *   and ignores client-supplied forwarding headers
 *
 * Runs standalone (no WordPress bootstrap). Uses ReflectionMethod to access
 * the private build_proxy_headers() method.
 *
 * Added as part of the Bug #2 fix (staff_ai_bff_identity regression).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

// ── WordPress stubs (minimal — only what build_proxy_headers needs) ──────

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'DEF_CORE_PLUGIN_DIR' ) ) {
	define( 'DEF_CORE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// Load shared stubs (gets DEF_Core_Encryption, options, etc.)
require_once __DIR__ . '/wp-stubs.php';

// Controllable user state.
global $_proxy_test_logged_in, $_proxy_test_user;
$_proxy_test_logged_in = false;
$_proxy_test_user      = null;

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		global $_proxy_test_logged_in;
		return $_proxy_test_logged_in;
	}
}

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
			return false;
		}
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): WP_User {
		global $_proxy_test_user;
		return $_proxy_test_user ?? new WP_User( 0 );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		global $_proxy_test_user;
		return $_proxy_test_user ? $_proxy_test_user->ID : 0;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		return false;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

// Minimal filter registry — get_visitor_ip() runs its value through apply_filters,
// and test [10] registers a CDN-style override on it.
global $_proxy_test_filters;
$_proxy_test_filters = array();

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		global $_proxy_test_filters;
		$cb = $_proxy_test_filters[ $hook ] ?? null;
		return $cb ? $cb( $value ) : $value;
	}
}

if ( ! function_exists( 'add_rewrite_rule' ) ) {
	function add_rewrite_rule( string $regex, string $query, string $after = 'bottom' ): void {}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args ): void {}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

// Stub DEF_Core so get_def_api_url_internal() doesn't fail at class load.
if ( ! class_exists( 'DEF_Core' ) ) {
	class DEF_Core {
		public static function get_def_api_url_internal(): string {
			return 'http://localhost:8000';
		}
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		if ( $show === 'name' ) {
			return 'Test Site';
		}
		return '';
	}
}

// Seed a dummy API key so build_proxy_headers() produces the key header.
\DEF_Core_Encryption::set_secret( 'def_core_api_key', 'test-api-key-for-proxy-test' );

// ── Load class under test ───────────────────────────────────────────────
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-tools.php';

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
	assert_true( ! $value, $label );
}

/**
 * Call the private build_proxy_headers() via reflection.
 */
function call_build_proxy_headers( bool $include_capabilities = false, bool $include_visitor_ip = true ): array {
	$method = new ReflectionMethod( 'DEF_Core_Tools', 'build_proxy_headers' );
	$method->setAccessible( true );
	return $method->invoke( null, $include_capabilities, $include_visitor_ip );
}

/**
 * Check if any header in the array starts with the given prefix.
 */
function has_header( array $headers, string $prefix ): bool {
	foreach ( $headers as $h ) {
		if ( strpos( $h, $prefix ) === 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Get the value of a header by prefix (e.g. "X-DEF-User-Display-Name: ").
 */
function get_header_value( array $headers, string $prefix ): ?string {
	foreach ( $headers as $h ) {
		if ( strpos( $h, $prefix ) === 0 ) {
			return substr( $h, strlen( $prefix ) );
		}
	}
	return null;
}

// ── Set up a logged-in user with identity ───────────────────────────────

$_proxy_test_logged_in = true;
$_proxy_test_user      = new WP_User( 1 );
$_proxy_test_user->ID           = 1;
$_proxy_test_user->display_name = 'Steve Truman';
$_proxy_test_user->user_email   = 'steve@a3rev.com';
$_proxy_test_user->roles        = array( 'administrator' );

echo "=== Proxy Identity Headers Tests ===\n";

// ── 1. Customer Chat: build_proxy_headers(false) — name YES, rest NO ────
echo "\n[1] Customer Chat path — display name sent, email/roles/caps withheld\n";

$headers = call_build_proxy_headers( false );
// Display name now crosses for Customer Chat so a logged-in visitor is greeted by name.
assert_true( has_header( $headers, 'X-DEF-User-Display-Name:' ), 'display name now sent for customer chat' );
assert_true(
	rawurldecode( (string) get_header_value( $headers, 'X-DEF-User-Display-Name: ' ) ) === 'Steve Truman',
	'customer chat display name decodes correctly'
);
// But email / roles / capabilities remain staff-only (privacy boundary preserved).
assert_false( has_header( $headers, 'X-DEF-User-Email:' ), 'no email in customer chat' );
assert_false( has_header( $headers, 'X-DEF-User-Roles:' ), 'no roles in customer chat' );
assert_false( has_header( $headers, 'X-DEF-User-Capabilities:' ), 'no capabilities in customer chat' );
// And X-DEF-User (id) is still sent.
assert_true( has_header( $headers, 'X-DEF-User:' ), 'user ID still sent for customer chat' );

// ── 2. Staff AI: build_proxy_headers(true) HAS identity ─────────────────
echo "\n[2] Staff AI path — identity headers present\n";

$headers = call_build_proxy_headers( true );
assert_true( has_header( $headers, 'X-DEF-User-Display-Name:' ), 'display name present for staff_ai' );
assert_true( has_header( $headers, 'X-DEF-User-Email:' ), 'email present for staff_ai' );
assert_true( has_header( $headers, 'X-DEF-User-Roles:' ), 'roles present for staff_ai' );

// ── 3. Values are correct + URL-encoded ──────────────────────────────────
echo "\n[3] Header values are correct and URL-encoded\n";

$display = get_header_value( $headers, 'X-DEF-User-Display-Name: ' );
assert_true( $display === rawurlencode( 'Steve Truman' ), 'display name value correct (' . $display . ')' );
assert_true( rawurldecode( $display ) === 'Steve Truman', 'display name decodes correctly' );

$email = get_header_value( $headers, 'X-DEF-User-Email: ' );
assert_true( $email === rawurlencode( 'steve@a3rev.com' ), 'email value correct (' . $email . ')' );
assert_true( rawurldecode( $email ) === 'steve@a3rev.com', 'email decodes correctly' );

$roles = get_header_value( $headers, 'X-DEF-User-Roles: ' );
assert_true( $roles === 'administrator', 'roles value correct' );

$site_name = get_header_value( $headers, 'X-DEF-Site-Name: ' );
assert_true( $site_name === rawurlencode( 'Test Site' ), 'site name value correct (' . $site_name . ')' );
assert_true( rawurldecode( $site_name ) === 'Test Site', 'site name decodes correctly' );

// ── 4. Unicode display name round-trips correctly ────────────────────────
echo "\n[4] Unicode display name round-trip\n";

$_proxy_test_user->display_name = "\u{00C9}tienne D\u{00E9}carie";
$headers = call_build_proxy_headers( true );
$encoded = get_header_value( $headers, 'X-DEF-User-Display-Name: ' );
assert_true( $encoded !== null, 'unicode display name header present' );
assert_true( rawurldecode( $encoded ) === "\u{00C9}tienne D\u{00E9}carie", 'unicode display name decodes correctly' );
$_proxy_test_user->display_name = 'Steve Truman'; // restore

// ── 5. Empty display name → no header (not broken empty header) ──────────
echo "\n[5] Empty identity fields produce no headers\n";

$_proxy_test_user->display_name = '';
$_proxy_test_user->user_email   = '';
$_proxy_test_user->roles        = array();

$headers = call_build_proxy_headers( true );
assert_false( has_header( $headers, 'X-DEF-User-Display-Name:' ), 'no display name header when empty' );
assert_false( has_header( $headers, 'X-DEF-User-Email:' ), 'no email header when empty' );
assert_false( has_header( $headers, 'X-DEF-User-Roles:' ), 'no roles header when empty' );

// Restore.
$_proxy_test_user->display_name = 'Steve Truman';
$_proxy_test_user->user_email   = 'steve@a3rev.com';
$_proxy_test_user->roles        = array( 'administrator' );

// ── 6. Multiple roles are comma-separated ────────────────────────────────
echo "\n[6] Multiple roles comma-separated\n";

$_proxy_test_user->roles = array( 'administrator', 'shop_manager' );
$headers = call_build_proxy_headers( true );
$roles = get_header_value( $headers, 'X-DEF-User-Roles: ' );
assert_true( $roles === 'administrator,shop_manager', 'multiple roles comma-separated (' . $roles . ')' );
$_proxy_test_user->roles = array( 'administrator' ); // restore

// ── 7. Anonymous user — no identity headers ──────────────────────────────
echo "\n[7] Anonymous user — no identity headers at all\n";

$_proxy_test_logged_in = false;
$headers = call_build_proxy_headers( true );
assert_false( has_header( $headers, 'X-DEF-User:' ), 'no user ID when anonymous' );
assert_false( has_header( $headers, 'X-DEF-User-Display-Name:' ), 'no display name when anonymous' );
assert_false( has_header( $headers, 'X-DEF-User-Email:' ), 'no email when anonymous' );
assert_false( has_header( $headers, 'X-DEF-User-Roles:' ), 'no roles when anonymous' );
$_proxy_test_logged_in = true; // restore

// ── 8. Visitor IP crosses on every channel ──────────────────────────────
echo "\n[8] X-DEF-Client-IP — the visitor's IP, both channels, logged in or not\n";

$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

foreach ( array( false, true ) as $with_caps ) {
	$headers = call_build_proxy_headers( $with_caps );
	$label   = $with_caps ? 'staff_ai' : 'customer chat';
	assert_true(
		get_header_value( $headers, 'X-DEF-Client-IP: ' ) === '203.0.113.44',
		"visitor IP sent for $label"
	);
}

// Anonymous is the case that matters most — Customer Chat has no user to key on.
$_proxy_test_logged_in = false;
$headers = call_build_proxy_headers( false );
assert_true(
	get_header_value( $headers, 'X-DEF-Client-IP: ' ) === '203.0.113.44',
	'visitor IP sent for an anonymous visitor'
);
$_proxy_test_logged_in = true;

// ── 9. A client-supplied forwarding header is NOT the visitor IP ────────
echo "\n[9] Spoofed forwarding headers are ignored — REMOTE_ADDR wins\n";

$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.7';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.8';
$headers = call_build_proxy_headers( false );
assert_true(
	get_header_value( $headers, 'X-DEF-Client-IP: ' ) === '203.0.113.44',
	'X-Forwarded-For / CF-Connecting-IP do not move the bucket'
);
unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'] );

// ── 10. CDN sites opt in through the filter ─────────────────────────────
echo "\n[10] def_core_visitor_ip filter overrides (CDN topology)\n";

$_proxy_test_filters['def_core_visitor_ip'] = fn( $ip ) => '198.51.100.99';
$headers = call_build_proxy_headers( false );
assert_true(
	get_header_value( $headers, 'X-DEF-Client-IP: ' ) === '198.51.100.99',
	'filtered IP is forwarded'
);

// A filter returning junk must not put junk on the wire.
$_proxy_test_filters['def_core_visitor_ip'] = fn( $ip ) => "not-an-ip\r\nX-Injected: 1";
$headers = call_build_proxy_headers( false );
assert_false( has_header( $headers, 'X-DEF-Client-IP:' ), 'invalid filtered IP is dropped' );
assert_false( has_header( $headers, 'X-Injected:' ), 'no header injection through the filter' );
$_proxy_test_filters = array();

// ── 11. No REMOTE_ADDR (WP-Cron / WP-CLI) → no header ───────────────────
echo "\n[11] No REMOTE_ADDR → header omitted, never empty\n";

unset( $_SERVER['REMOTE_ADDR'] );
$headers = call_build_proxy_headers( false );
assert_false( has_header( $headers, 'X-DEF-Client-IP:' ), 'no visitor IP header off a web request' );
assert_true( has_header( $headers, 'X-DEF-API-Key:' ), 'the rest of the headers still build' );

// IPv6 is a valid visitor too.
$_SERVER['REMOTE_ADDR'] = '2001:db8::1';
$headers = call_build_proxy_headers( false );
assert_true(
	get_header_value( $headers, 'X-DEF-Client-IP: ' ) === '2001:db8::1',
	'IPv6 visitor IP forwarded'
);

// ── 12. Un-rewritten proxy address is NOT a visitor ─────────────────────
// A private/reserved REMOTE_ADDR is the proxy in front of WP, identical for every
// visitor. Forwarding it would look like a per-visitor key to DEF while actually
// being the site — and several sites would collide on the same 172.17.0.1.
echo "\n[12] Private / reserved REMOTE_ADDR is not forwarded\n";

foreach ( array( '10.0.0.5', '172.17.0.1', '192.168.1.10', '127.0.0.1', '::1', '169.254.1.1' ) as $private ) {
	$_SERVER['REMOTE_ADDR'] = $private;
	$headers = call_build_proxy_headers( false );
	assert_false( has_header( $headers, 'X-DEF-Client-IP:' ), "not forwarded: $private" );
}

// Carrier-grade NAT (100.64/10) is real mobile traffic — it must still be forwarded.
$_SERVER['REMOTE_ADDR'] = '100.64.10.20';
$headers = call_build_proxy_headers( false );
assert_true(
	get_header_value( $headers, 'X-DEF-Client-IP: ' ) === '100.64.10.20',
	'CGNAT visitor IP still forwarded (mobile networks)'
);

$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

// ── 13. Opt-out for calls that are not something the visitor did ────────
echo "\n[13] include_visitor_ip = false omits the IP, keeps everything else\n";

$headers = call_build_proxy_headers( false, false );
assert_false( has_header( $headers, 'X-DEF-Client-IP:' ), 'no visitor IP when opted out' );
assert_true( has_header( $headers, 'X-DEF-API-Key:' ), 'API key still sent when opted out' );

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n--- Proxy Identity Headers Tests: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
