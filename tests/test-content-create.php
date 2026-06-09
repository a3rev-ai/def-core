<?php
/**
 * Content Agent "Create New" (Engine 2, Wave 1) — create-post bridge tests.
 *
 * Verifies the def-core half of on-demand create:
 *  - semantic_to_blocks() serializes authored semantic JSON to core Gutenberg
 *    block structures (paragraph / heading / list / image placeholder).
 *  - POST /content/create-post (rest_create_post) inserts a DRAFT post
 *    (post_type=post, status=draft, slug), sets the SEO meta + focus keyphrase,
 *    and returns {post_id, edit_link}.
 *  - The create-post capability is enforced (no write without it).
 *  - A missing title is rejected before any insert.
 *
 * Drives the real handler with WP function stubs (HMAC verify lives in the
 * permission_callback, not exercised here).
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── WP stubs ────────────────────────────────────────────────────────────

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
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

// Yoast active → real metadesc/title/focus keys exercised by apply_create_meta.
if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', '99.0' );
}

$GLOBALS['t_can_create']  = true;
$GLOBALS['t_inserts']     = array();
$GLOBALS['t_meta_writes'] = array();
$GLOBALS['t_current_user'] = 0;

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, ...$a ) {
		if ( 'edit_posts' === $cap ) { return (bool) $GLOBALS['t_can_create']; }
		return true; // def_staff_access etc.
	}
}
if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $id ) { $GLOBALS['t_current_user'] = (int) $id; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) $GLOBALS['t_current_user']; }
}
if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $name ) {
		$o = new stdClass();
		$o->cap = (object) array( 'create_posts' => 'edit_posts' );
		return $o;
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $arr, $wp_error = false ) {
		$GLOBALS['t_inserts'][] = $arr;
		return 123;
	}
}
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $id, $context = 'display' ) {
		return 'https://site.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['t_meta_writes'][] = array( 'id' => (int) $id, 'key' => $key, 'value' => $value );
		return true;
	}
}
if ( ! function_exists( 'serialize_blocks' ) ) {
	// Return the block structures as JSON so the test can inspect what the
	// serializer produced (real serialize_blocks isn't available standalone).
	function serialize_blocks( $blocks ) { return json_encode( $blocks ); }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $s ) { return $s; }
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $string, $allowed ) { return $string; } // passthrough for structure tests
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return str_replace( '"', '&quot;', (string) $s ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) {
		$s = strtolower( trim( (string) $s ) );
		$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
		return trim( (string) $s, '-' );
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-seo-meta.php';
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

function priv_static( string $class, string $method, array $args ) {
	$ref = new ReflectionMethod( $class, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

// ── 1. semantic_to_blocks serializes the supported node types ───────────
echo "[1] semantic JSON → core block structures\n";
$blocks = priv_static( 'DEF_Core_Blocks', 'semantic_to_blocks', array( array(
	array( 'type' => 'heading', 'level' => 2, 'text' => 'Hello' ),
	array( 'type' => 'paragraph', 'text' => 'World' ),
	array( 'type' => 'list', 'ordered' => false, 'items' => array( 'a', 'b' ) ),
	array( 'type' => 'image-placeholder', 'alt' => 'cat' ),
	array( 'type' => 'paragraph', 'text' => '   ' ),   // blank → skipped
	array( 'type' => 'mystery', 'text' => '' ),         // unknown, no text → skipped
	array( 'type' => 'list', 'items' => array( 1, true ) ), // all non-string → skipped
) ) );
assert_same( 4, count( $blocks ), 'four blocks emitted (blanks/unknowns/empty-list skipped)' );
assert_same( 'core/heading', $blocks[0]['blockName'], 'heading block' );
assert_true( false !== strpos( $blocks[0]['innerHTML'], '<h2 class="wp-block-heading">Hello</h2>' ), 'heading html' );
assert_same( 'core/paragraph', $blocks[1]['blockName'], 'paragraph block' );
assert_same( '<p>World</p>', $blocks[1]['innerHTML'], 'paragraph html' );
assert_same( 'core/list', $blocks[2]['blockName'], 'list block' );
assert_same( 2, count( $blocks[2]['innerBlocks'] ), 'list has two list-items' );
assert_same( 'core/list-item', $blocks[2]['innerBlocks'][0]['blockName'], 'list-item block' );
// Image placeholder serializes to a VALID paragraph (empty-src core/image would
// fail Gutenberg block validation); images are a later wave.
assert_same( 'core/paragraph', $blocks[3]['blockName'], 'image placeholder → valid paragraph' );
assert_true( false !== strpos( $blocks[3]['innerHTML'], 'Image: cat' ), 'image alt carried into placeholder' );

// Heading level clamps and non-2 carries the level attr.
$h3 = priv_static( 'DEF_Core_Blocks', 'semantic_to_blocks', array( array(
	array( 'type' => 'heading', 'level' => 9, 'text' => 'X' ), // out of range → 2
	array( 'type' => 'heading', 'level' => 3, 'text' => 'Y' ),
) ) );
assert_true( false !== strpos( $h3[0]['innerHTML'], '<h2' ), 'level 9 clamps to h2' );
assert_same( array( 'level' => 3 ), $h3[1]['attrs'], 'level 3 carries level attr' );

// ── 2. rest_create_post inserts a DRAFT + sets meta + returns links ─────
echo "[2] create-post inserts a draft and sets SEO meta\n";
$GLOBALS['t_can_create']  = true;
$GLOBALS['t_inserts']     = array();
$GLOBALS['t_meta_writes'] = array();
$req = new WP_REST_Request( 'POST', '' );
$req->set_json( array(
	'title'               => 'My New Post',
	'slug'                => 'My New Post',
	'status'              => 'draft',
	'content'             => array( array( 'type' => 'paragraph', 'text' => 'Body text' ) ),
	'focus_keyphrase'     => 'blue widgets',
	'meta_description'    => 'A meta description.',
	'seo_title'           => 'My SEO Title',
) );
$resp = DEF_Core_Blocks::rest_create_post( $req );
$data = $resp->get_data();
assert_same( 'created', $data['status'], 'returns status=created' );
assert_same( 123, $data['post_id'], 'returns post_id' );
assert_true( false !== strpos( $data['edit_link'], 'post=123' ), 'returns edit_link' );
assert_same( 1, count( $GLOBALS['t_inserts'] ), 'wp_insert_post called once' );
$ins = $GLOBALS['t_inserts'][0];
assert_same( 'post', $ins['post_type'], 'post_type=post' );
assert_same( 'draft', $ins['post_status'], 'post_status=draft' );
assert_same( 'My New Post', $ins['post_title'], 'post_title set' );
assert_same( 'my-new-post', $ins['post_name'], 'slug sanitized to post_name' );
$meta_keys = array_map( static function ( $w ) { return $w['key']; }, $GLOBALS['t_meta_writes'] );
assert_true( in_array( '_yoast_wpseo_metadesc', $meta_keys, true ), 'meta description written (yoast)' );
assert_true( in_array( '_yoast_wpseo_title', $meta_keys, true ), 'seo title written (yoast)' );
assert_true( in_array( '_yoast_wpseo_focuskw', $meta_keys, true ), 'focus keyphrase written (create sets it)' );
assert_true( in_array( '_def_content_optimized_at', $meta_keys, true ), 'optimized stamp written' );

// ── 3. Capability enforced — no create-post cap, no insert ──────────────
echo "[3] create-post requires the create-post capability\n";
$GLOBALS['t_can_create'] = false;
$GLOBALS['t_inserts']    = array();
$req = new WP_REST_Request( 'POST', '' );
$req->set_json( array( 'title' => 'Nope', 'content' => array() ) );
$resp = DEF_Core_Blocks::rest_create_post( $req );
assert_true( is_wp_error( $resp ), 'returns WP_Error without capability' );
assert_same( 'rest_forbidden', $resp->get_error_code(), 'error code rest_forbidden' );
assert_same( 403, $resp->get_error_data()['status'], 'HTTP 403' );
assert_same( array(), $GLOBALS['t_inserts'], 'no post inserted without capability' );

// ── 4. Missing title rejected before insert ─────────────────────────────
echo "[4] missing title is rejected\n";
$GLOBALS['t_can_create'] = true;
$GLOBALS['t_inserts']    = array();
$req = new WP_REST_Request( 'POST', '' );
$req->set_json( array( 'content' => array( array( 'type' => 'paragraph', 'text' => 'x' ) ) ) );
$data = DEF_Core_Blocks::rest_create_post( $req )->get_data();
assert_same( 'invalid', $data['status'], 'returns status=invalid' );
assert_same( array(), $GLOBALS['t_inserts'], 'no insert when title missing' );

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
