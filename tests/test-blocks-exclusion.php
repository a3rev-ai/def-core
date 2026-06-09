<?php
/**
 * Block-edit bridge (Adapter G) — ingestion-exclusion guard tests.
 *
 * Verifies that DEF_Core_Blocks honours the existing per-item exclusion flag
 * (DEF_Core_Knowledge_Exclusion::is_excluded — the _def_exclude_from_ingestion
 * meta) at the live-write boundary:
 *  - GET  /content/blocks manifest reports `excluded` true/false.
 *  - POST /content/blocks/{id}/apply REFUSES an excluded item with
 *    {status:'excluded'} BEFORE any block parse or wp_update_post (no write).
 *  - A non-excluded item is unaffected and still applies a patch (real write).
 *
 * Drives the real rest_manifest / rest_apply handlers with WP function stubs.
 * (The handlers gate on $_SERVER['HTTP_X_DEF_USER'] + current_user_can; the HMAC
 * verify lives in the permission_callback, which isn't exercised here.)
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── WP stubs the block bridge needs ─────────────────────────────────────

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
		private $params = array();
		private $body   = array();
		public function __construct( string $method = 'GET', string $route = '' ) {}
		public function set_param( string $k, $v ): void { $this->params[ $k ] = $v; }
		public function get_param( string $k ) { return $this->params[ $k ] ?? null; }
		public function set_json( array $b ): void { $this->body = $b; }
		public function get_json_params() { return $this->body; }
	}
}

// Mutable test state.
$GLOBALS['t_meta']             = array();   // post meta by key
$GLOBALS['t_parse_calls']      = 0;         // parse_blocks invocations
$GLOBALS['t_update_calls']     = array();   // wp_update_post invocations
$GLOBALS['t_current_user']     = 0;

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) {
		return $GLOBALS['t_meta'][ $key ] ?? '';
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		if ( (int) $id <= 0 ) { return null; }
		$p = new stdClass();
		$p->ID = (int) $id;
		$p->post_content = "<!-- wp:paragraph --><p>old</p><!-- /wp:paragraph -->";
		return $p;
	}
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
if ( ! function_exists( 'has_blocks' ) ) {
	function has_blocks( $content = '' ) { return true; }
}
// Deterministic single-paragraph tree, independent of input (structure is what
// the fingerprint/equivalence gates care about; the edit happens in-memory).
if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		$GLOBALS['t_parse_calls']++;
		return array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(),
				'innerHTML'    => '<p>old</p>',
				'innerContent' => array( '<p>old</p>' ),
				'innerBlocks'  => array(),
			),
		);
	}
}
if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( $blocks ) { return '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'; }
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $string, $allowed ) { return $string; } // patch text is plain
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return $s; }
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $arr, $wp_error = false ) {
		$GLOBALS['t_update_calls'][] = $arr;
		return (int) ( $arr['ID'] ?? 0 );
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-knowledge-exclusion.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-blocks.php';

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

// Reflection helpers to compute fingerprint / sha / extract_inner the same way
// the handler does, so the non-excluded apply uses real matching values.
$ref = new ReflectionClass( 'DEF_Core_Blocks' );
function priv( ReflectionClass $ref, string $m, array $args ) {
	$mm = $ref->getMethod( $m );
	$mm->setAccessible( true );
	return $mm->invokeArgs( null, $args );
}

$tree = array(
	array(
		'blockName'    => 'core/paragraph',
		'attrs'        => array(),
		'innerHTML'    => '<p>old</p>',
		'innerContent' => array( '<p>old</p>' ),
		'innerBlocks'  => array(),
	),
);
$fingerprint = priv( $ref, 'fingerprint', array( $tree ) );
$inner_old   = priv( $ref, 'extract_inner', array( '<p>old</p>' ) );
$base_sha    = priv( $ref, 'sha', array( (string) $inner_old ) );

// ── 1. Manifest reports excluded = true ─────────────────────────────────
echo "[1] manifest reports excluded=true for a flagged item\n";
$GLOBALS['t_meta'] = array( '_def_exclude_from_ingestion' => '1' );
$req = new WP_REST_Request( 'GET', '' );
$req->set_param( 'item_id', 5 );
$resp = DEF_Core_Blocks::rest_manifest( $req );
assert_true( $resp instanceof WP_REST_Response, 'manifest returns a response' );
$data = $resp->get_data();
assert_same( true, $data['excluded'], 'excluded=true present in manifest' );
assert_same( true, $data['body_editable'], 'still reports body_editable (manifest unchanged otherwise)' );

// ── 2. Manifest reports excluded = false ────────────────────────────────
echo "[2] manifest reports excluded=false for an unflagged item\n";
$GLOBALS['t_meta'] = array( '_def_exclude_from_ingestion' => '' );
$req = new WP_REST_Request( 'GET', '' );
$req->set_param( 'item_id', 5 );
$data = DEF_Core_Blocks::rest_manifest( $req )->get_data();
assert_same( false, $data['excluded'], 'excluded=false present in manifest' );

// ── 3. Apply REFUSES an excluded item — no parse, no write ──────────────
echo "[3] apply refuses an excluded item before any write\n";
$GLOBALS['t_meta']        = array( '_def_exclude_from_ingestion' => '1' );
$GLOBALS['t_parse_calls'] = 0;
$GLOBALS['t_update_calls'] = array();
$req = new WP_REST_Request( 'POST', '' );
$req->set_param( 'item_id', 5 );
$req->set_json( array(
	'fingerprint' => $fingerprint,
	'patches'     => array( array( 'path' => '0', 'inner_html' => 'new', 'base_sha' => $base_sha ) ),
) );
$resp = DEF_Core_Blocks::rest_apply( $req );
$data = $resp->get_data();
assert_same( 'excluded', $data['status'], 'apply returns status=excluded' );
assert_same( 0, $GLOBALS['t_parse_calls'], 'no parse_blocks — refused before parse/serialize' );
assert_same( array(), $GLOBALS['t_update_calls'], 'no wp_update_post — no write to an excluded item' );

// ── 4. Apply on a NON-excluded item still writes the patch ──────────────
echo "[4] apply still applies a non-excluded item\n";
$GLOBALS['t_meta']         = array( '_def_exclude_from_ingestion' => '' );
$GLOBALS['t_parse_calls']  = 0;
$GLOBALS['t_update_calls'] = array();
$req = new WP_REST_Request( 'POST', '' );
$req->set_param( 'item_id', 5 );
$req->set_json( array(
	'fingerprint' => $fingerprint,
	'patches'     => array( array( 'path' => '0', 'inner_html' => 'new', 'base_sha' => $base_sha ) ),
) );
$data = DEF_Core_Blocks::rest_apply( $req )->get_data();
assert_same( 'applied', $data['status'], 'non-excluded item applies' );
assert_same( 1, count( $GLOBALS['t_update_calls'] ), 'wp_update_post called exactly once' );
assert_same( 5, (int) $GLOBALS['t_update_calls'][0]['ID'], 'write targets the right post' );

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
