<?php
/**
 * SEO-meta bridge — ingestion-exclusion guard tests.
 *
 * Companion to test-blocks-exclusion.php: a metadata-only draft (no body
 * patches) writes SEO meta via POST /content/seo-meta, so that write path needs
 * the same exclusion refusal as the block-edit apply path. Verifies:
 *  - POST /content/seo-meta REFUSES an excluded item with {status:'excluded'}
 *    and writes NOTHING (no update_post_meta).
 *  - A non-excluded item still writes (real update_post_meta).
 *  - The GET/read path is not affected (not exercised here — it isn't guarded).
 *
 * Drives the real rest_write handler with WP function stubs (HMAC verify lives
 * in the permission_callback, not exercised here).
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── WP stubs the SEO-meta bridge needs ──────────────────────────────────

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, int $status = 200 ) { $this->data = $data; $this->status = $status; }
		public function get_data() { return $this->data; }
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $body = array();
		public function __construct( string $method = 'GET', string $route = '' ) {}
		public function set_json( array $b ): void { $this->body = $b; }
		public function get_json_params() { return $this->body; }
	}
}

$GLOBALS['t_meta']        = array();   // post meta by key (drives is_excluded)
$GLOBALS['t_meta_writes'] = array();   // update_post_meta invocations
$GLOBALS['t_current_user'] = 0;

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) {
		return $GLOBALS['t_meta'][ $key ] ?? '';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['t_meta_writes'][] = array( 'id' => (int) $id, 'key' => $key, 'value' => $value );
		return true;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id ) { return (int) $id > 0 ? 'publish' : false; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, ...$a ) { return true; }
}
if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $id ) { $GLOBALS['t_current_user'] = (int) $id; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) $GLOBALS['t_current_user']; }
}

// Pretend Yoast is active so active_plugin() resolves real metadesc/title keys —
// this lets the non-excluded test exercise the actual SEO-meta write paths
// (meta_description + seo_title), not just the optimized stamp.
if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', '99.0' );
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-knowledge-exclusion.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-seo-meta.php';

$_SERVER['HTTP_X_DEF_USER'] = '5';

// ── Tiny assertion harness ──────────────────────────────────────────────

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
		echo "  FAIL: $label (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
	}
}

// ── 1. Excluded item: write REFUSED, nothing written ────────────────────
echo "[1] seo-meta write refuses an excluded item (no write)\n";
$GLOBALS['t_meta']        = array( '_def_exclude_from_ingestion' => '1' );
$GLOBALS['t_meta_writes'] = array();
$req = new WP_REST_Request( 'POST', '' );
$req->set_json( array(
	'item_id'             => 5,
	'meta_description'    => 'a new meta description',
	'seo_title'           => 'A New SEO Title',
	'optimized_keyphrase' => 'blue widgets',
) );
$resp = DEF_Core_SEO_Meta::rest_write( $req );
assert_true( $resp instanceof WP_REST_Response, 'returns a response' );
$data = $resp->get_data();
assert_same( 'excluded', $data['status'], 'returns status=excluded' );
assert_same( array(), $GLOBALS['t_meta_writes'], 'no update_post_meta — nothing written to an excluded item' );

// ── 2. Non-excluded item: still writes (incl. the real SEO meta) ────────
echo "[2] seo-meta write still applies to a non-excluded item\n";
$GLOBALS['t_meta']        = array( '_def_exclude_from_ingestion' => '' );
$GLOBALS['t_meta_writes'] = array();
$req = new WP_REST_Request( 'POST', '' );
$req->set_json( array(
	'item_id'             => 5,
	'meta_description'    => 'a new meta description',
	'seo_title'           => 'A New SEO Title',
	'optimized_keyphrase' => 'blue widgets',
) );
$data = DEF_Core_SEO_Meta::rest_write( $req )->get_data();
assert_true( ! isset( $data['status'] ) || 'excluded' !== $data['status'], 'non-excluded item is not refused' );
assert_true( array_key_exists( 'written', $data ), 'normal write response shape (has "written")' );
assert_same( 'yoast', $data['plugin'], 'active plugin resolved (yoast)' );
assert_true( in_array( 'meta_description', $data['written'], true ), 'meta_description written' );
assert_true( in_array( 'seo_title', $data['written'], true ), 'seo_title written' );
assert_true( count( $GLOBALS['t_meta_writes'] ) > 0, 'update_post_meta called for a non-excluded item' );
// The actual SEO meta + the optimized stamp/keyphrase are all written.
$keys_written = array_map( static function ( $w ) { return $w['key']; }, $GLOBALS['t_meta_writes'] );
assert_true( in_array( '_def_content_optimized_at', $keys_written, true ), 'optimized stamp written' );
assert_true( in_array( '_def_optimized_keyphrase', $keys_written, true ), 'optimized keyphrase written' );

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
