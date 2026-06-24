<?php
/**
 * Content Drafts "Optimize" tab — dismissed list + item dismiss/restore BFF tests.
 *
 * Verifies (DEF #522):
 *  - REST routes are registered with correct methods and permission callbacks:
 *      GET  /staff-ai/content/list
 *      POST /staff-ai/content/items/{item_id}/dismiss
 *      POST /staff-ai/content/items/{item_id}/restore
 *  - rest_list_content_items() rejects a missing/empty bucket with 400.
 *  - rest_dismiss_content_item() rejects item_id = 0 with 400.
 *  - rest_restore_content_item() rejects item_id = 0 with 400.
 *  - Valid item_ids pass validation and reach the backend_request layer.
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Additional stubs ─────────────────────────────────────────────────────────

global $_wp_test_rest_routes, $_wp_test_current_user, $_wp_test_user_caps;
$_wp_test_rest_routes  = array();
$_wp_test_current_user = null;
$_wp_test_user_caps    = array();

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = array() ): bool {
		global $_wp_test_rest_routes;
		$_wp_test_rest_routes[ $namespace . $route ] = $args;
		return true;
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

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID           = 0;
		public $display_name = '';
		public $user_email   = '';
		public $roles        = array();
		public function __construct( int $id = 0 ) { $this->ID = $id; }
		public function exists(): bool { return $this->ID > 0; }
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

if ( ! class_exists( 'DEF_Core' ) ) {
	class DEF_Core {
		public static function get_def_api_url_internal(): ?string {
			return $GLOBALS['_def_test_api_url'] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = array();
		public function __construct( string $method = 'GET', string $route = '' ) {}
		public function set_param( string $key, $value ): void { $this->params[ $key ] = $value; }
		public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
		public function get_json_params(): array { return array(); }
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-staff-ai.php';

// ── Assertion harness ─────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function assert_true( $value, string $label ): void {
	global $pass, $fail;
	if ( $value ) { $pass++; } else { $fail++; echo "  FAIL: $label\n"; }
}
function assert_same( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) { $pass++; } else {
		$fail++;
		echo "  FAIL: $label (expected " . var_export( $expected, true )
			. ', got ' . var_export( $actual, true ) . ")\n";
	}
}

// ── 1. Route registration ────────────────────────────────────────────────────
echo "[1] Route registration\n";
DEF_Core_Staff_AI::register_rest_routes();

$ns = DEF_CORE_API_NAME_SPACE;

$list_key    = $ns . '/staff-ai/content/list';
$dismiss_key = $ns . '/staff-ai/content/items/(?P<item_id>\d+)/dismiss';
$restore_key = $ns . '/staff-ai/content/items/(?P<item_id>\d+)/restore';

assert_true( isset( $_wp_test_rest_routes[ $list_key ] ),    'GET /content/list registered' );
assert_true( isset( $_wp_test_rest_routes[ $dismiss_key ] ), 'POST /content/items/{id}/dismiss registered' );
assert_true( isset( $_wp_test_rest_routes[ $restore_key ] ), 'POST /content/items/{id}/restore registered' );

// ── 2. HTTP methods ──────────────────────────────────────────────────────────
echo "[2] HTTP methods\n";
if ( isset( $_wp_test_rest_routes[ $list_key ] ) ) {
	assert_same( 'GET',  $_wp_test_rest_routes[ $list_key ]['methods'],    'list route is GET' );
}
if ( isset( $_wp_test_rest_routes[ $dismiss_key ] ) ) {
	assert_same( 'POST', $_wp_test_rest_routes[ $dismiss_key ]['methods'], 'dismiss route is POST' );
}
if ( isset( $_wp_test_rest_routes[ $restore_key ] ) ) {
	assert_same( 'POST', $_wp_test_rest_routes[ $restore_key ]['methods'], 'restore route is POST' );
}

// ── 3. Permission callbacks present ─────────────────────────────────────────
echo "[3] Permission callbacks\n";
foreach ( array( $list_key, $dismiss_key, $restore_key ) as $rk ) {
	if ( isset( $_wp_test_rest_routes[ $rk ] ) ) {
		assert_true(
			! empty( $_wp_test_rest_routes[ $rk ]['permission_callback'] ),
			"permission_callback on $rk"
		);
	}
}

// ── 4. Missing bucket → 400 ──────────────────────────────────────────────────
echo "[4] rest_list_content_items — missing bucket → 400\n";
$req = new WP_REST_Request( 'GET', '/staff-ai/content/list' );
// bucket param not set → get_param('bucket') returns null → sanitize_key('') = ''
$result = DEF_Core_Staff_AI::rest_list_content_items( $req );
assert_true( is_wp_error( $result ), 'missing bucket returns WP_Error' );
assert_same( 'invalid_bucket', $result->get_error_code(), 'error code is invalid_bucket' );
$data = $result->get_error_data();
assert_same( 400, $data['status'], 'HTTP status is 400' );

// ── 5. Empty bucket → 400 ───────────────────────────────────────────────────
echo "[5] rest_list_content_items — empty bucket → 400\n";
$req = new WP_REST_Request( 'GET', '/staff-ai/content/list' );
$req->set_param( 'bucket', '' );
$result = DEF_Core_Staff_AI::rest_list_content_items( $req );
assert_true( is_wp_error( $result ), 'empty bucket returns WP_Error' );
assert_same( 'invalid_bucket', $result->get_error_code(), 'error code is invalid_bucket' );

// ── 6. Valid bucket reaches backend (backend not configured → 503, not 400) ──
echo "[6] rest_list_content_items — valid bucket passes validation\n";
$req = new WP_REST_Request( 'GET', '/staff-ai/content/list' );
$req->set_param( 'bucket', 'dismissed' );
$GLOBALS['_def_test_api_url'] = null; // backend not configured
$result = DEF_Core_Staff_AI::rest_list_content_items( $req );
// Validation passes — error comes from backend_request (unconfigured), not from our gate.
if ( is_wp_error( $result ) ) {
	assert_true(
		$result->get_error_code() !== 'invalid_bucket',
		'valid bucket does not trigger invalid_bucket'
	);
}

// ── 7. rest_dismiss_content_item — item_id 0 → 400 ──────────────────────────
echo "[7] rest_dismiss_content_item — item_id 0 → 400\n";
$req = new WP_REST_Request( 'POST', '/staff-ai/content/items/0/dismiss' );
$req->set_param( 'item_id', '0' );
$result = DEF_Core_Staff_AI::rest_dismiss_content_item( $req );
assert_true( is_wp_error( $result ), 'item_id=0 returns WP_Error' );
assert_same( 'invalid_item_id', $result->get_error_code(), 'error code is invalid_item_id' );
$data = $result->get_error_data();
assert_same( 400, $data['status'], 'HTTP status is 400' );

// ── 8. rest_dismiss_content_item — missing item_id → 400 ────────────────────
echo "[8] rest_dismiss_content_item — missing item_id → 400\n";
$req = new WP_REST_Request( 'POST', '/staff-ai/content/items//dismiss' );
// item_id not set → get_param returns null → absint(null) = 0
$result = DEF_Core_Staff_AI::rest_dismiss_content_item( $req );
assert_true( is_wp_error( $result ), 'missing item_id returns WP_Error' );
assert_same( 'invalid_item_id', $result->get_error_code(), 'error code is invalid_item_id' );

// ── 9. rest_dismiss_content_item — valid item_id passes validation ────────────
echo "[9] rest_dismiss_content_item — valid item_id passes validation\n";
$req = new WP_REST_Request( 'POST', '/staff-ai/content/items/42/dismiss' );
$req->set_param( 'item_id', '42' );
$GLOBALS['_def_test_api_url'] = null;
$result = DEF_Core_Staff_AI::rest_dismiss_content_item( $req );
if ( is_wp_error( $result ) ) {
	assert_true(
		$result->get_error_code() !== 'invalid_item_id',
		'item_id=42 does not trigger invalid_item_id'
	);
}

// ── 10. rest_restore_content_item — item_id 0 → 400 ─────────────────────────
echo "[10] rest_restore_content_item — item_id 0 → 400\n";
$req = new WP_REST_Request( 'POST', '/staff-ai/content/items/0/restore' );
$req->set_param( 'item_id', '0' );
$result = DEF_Core_Staff_AI::rest_restore_content_item( $req );
assert_true( is_wp_error( $result ), 'item_id=0 returns WP_Error' );
assert_same( 'invalid_item_id', $result->get_error_code(), 'error code is invalid_item_id' );
$data = $result->get_error_data();
assert_same( 400, $data['status'], 'HTTP status is 400' );

// ── 11. rest_restore_content_item — missing item_id → 400 ───────────────────
echo "[11] rest_restore_content_item — missing item_id → 400\n";
$req = new WP_REST_Request( 'POST', '/staff-ai/content/items//restore' );
$result = DEF_Core_Staff_AI::rest_restore_content_item( $req );
assert_true( is_wp_error( $result ), 'missing item_id returns WP_Error' );
assert_same( 'invalid_item_id', $result->get_error_code(), 'error code is invalid_item_id' );

// ── 12. rest_restore_content_item — valid item_id passes validation ───────────
echo "[12] rest_restore_content_item — valid item_id passes validation\n";
$req = new WP_REST_Request( 'POST', '/staff-ai/content/items/99/restore' );
$req->set_param( 'item_id', '99' );
$GLOBALS['_def_test_api_url'] = null;
$result = DEF_Core_Staff_AI::rest_restore_content_item( $req );
if ( is_wp_error( $result ) ) {
	assert_true(
		$result->get_error_code() !== 'invalid_item_id',
		'item_id=99 does not trigger invalid_item_id'
	);
}

// ── 13. item_id regex pattern rejects non-digits ─────────────────────────────
echo "[13] item_id regex rejects non-digits\n";
assert_true( preg_match( '/^\d+$/', '42' )  === 1, 'numeric item_id matches \d+ pattern' );
assert_true( preg_match( '/^\d+$/', '0' )   === 1, '0 matches pattern (absint() gate catches it)' );
assert_true( preg_match( '/^\d+$/', 'abc' ) === 0, 'alpha string rejected by \d+ pattern' );
assert_true( preg_match( '/^\d+$/', '../42' ) === 0, 'path traversal rejected by \d+ pattern' );
assert_true( preg_match( '/^\d+$/', '42;rm -rf /' ) === 0, 'injection attempt rejected' );

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
