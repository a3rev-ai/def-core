<?php
/**
 * Content Drafts "Optimize" tab — clickable summary buckets (DEF #523) tests.
 *
 * Verifies:
 *  - Buckets good / optimized / awaiting_review pass the bucket validation gate.
 *  - view_url is included in the allowlist and preserved when the backend returns it.
 *  - view_url is enriched locally from get_permalink when get_post_status is truthy.
 *  - Unknown fields are still stripped even after view_url was added to the allowlist.
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

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
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
			$this->data = $data; $this->status = $status;
		}
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
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
if ( ! class_exists( 'DEF_Core_Tools' ) ) {
	class DEF_Core_Tools {
		public static function get_user_def_capabilities( $user ): array {
			return array( 'def_staff_access' );
		}
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $key = '' ): string { return ''; }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		return $GLOBALS['_wp_test_remote_response'] ?? array( 'stub' => true );
	}
}
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( string $url, array $args = array() ) {
		return $GLOBALS['_wp_test_remote_response'] ?? array( 'stub' => true );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $GLOBALS['_wp_test_remote_status'] ?? 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return $GLOBALS['_wp_test_remote_body'] ?? '{}';
	}
}

// Controllable get_post_status — backed by a global map of item_id → status string.
global $_wp_test_post_statuses;
$_wp_test_post_statuses = array();
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post_id ) {
		global $_wp_test_post_statuses;
		return $_wp_test_post_statuses[ (int) $post_id ] ?? false;
	}
}

// Controllable get_the_title.
global $_wp_test_post_titles;
$_wp_test_post_titles = array();
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post ) {
		global $_wp_test_post_titles;
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return $_wp_test_post_titles[ $id ] ?? '';
	}
}

// Controllable get_edit_post_link.
global $_wp_test_edit_urls;
$_wp_test_edit_urls = array();
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $post_id, string $context = 'display' ): string {
		global $_wp_test_edit_urls;
		return $_wp_test_edit_urls[ (int) $post_id ] ?? '';
	}
}

// Controllable get_permalink.
global $_wp_test_view_urls;
$_wp_test_view_urls = array();
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ) {
		global $_wp_test_view_urls;
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return $_wp_test_view_urls[ $id ] ?? '';
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

// ── 1. Route spot-check ───────────────────────────────────────────────────────
echo "[1] Route spot-check\n";
DEF_Core_Staff_AI::register_rest_routes();
$ns = DEF_CORE_API_NAME_SPACE;
assert_true( isset( $_wp_test_rest_routes[ $ns . '/staff-ai/content/list' ] ), 'GET /content/list registered' );

// ── 2. Bucket validation — good / optimized / awaiting_review all pass ────────
echo "[2] rest_list_content_items — non-dismissed buckets pass validation\n";
foreach ( array( 'good', 'optimized', 'awaiting_review' ) as $bucket ) {
	$req = new WP_REST_Request( 'GET', '/staff-ai/content/list' );
	$req->set_param( 'bucket', $bucket );
	$GLOBALS['_def_test_api_url'] = null;
	$result = DEF_Core_Staff_AI::rest_list_content_items( $req );
	if ( is_wp_error( $result ) ) {
		assert_true(
			$result->get_error_code() !== 'invalid_bucket',
			"bucket=$bucket does not trigger invalid_bucket"
		);
	} else {
		$pass++;
	}
}

// ── 3. view_url preserved in allowlist (backend-supplied, no local enrichment) ─
echo "[3] rest_list_content_items — view_url preserved by allowlist\n";
$_wp_test_current_user     = new WP_User( 1 );
$_wp_test_current_user->ID = 1;
update_option( 'def_core_api_key', 'test-api-key' );
$GLOBALS['_def_test_api_url']      = 'https://def-api.test';
$GLOBALS['_wp_test_remote_status'] = 200;
$GLOBALS['_wp_test_remote_body']   = (string) json_encode( array(
	'items' => array(
		array(
			'item_id'      => 77,
			'item_type'    => 'product',
			'draft_id'     => 'dr_good01',
			'restorable'   => false,
			'last_audited' => '2026-06-24T09:00:00Z',
			'view_url'     => 'https://example.com/products/widget/',
			'edit_url'     => 'https://example.com/wp-admin/post.php?post=77&action=edit',
		),
	),
) );

// get_post_status returns false for item 77 → no local enrichment loop runs;
// backend-supplied view_url must pass through the allowlist unchanged.
$req = new WP_REST_Request( 'GET', '/staff-ai/content/list' );
$req->set_param( 'bucket', 'good' );
$result      = DEF_Core_Staff_AI::rest_list_content_items( $req );
$is_response = $result instanceof WP_REST_Response;
assert_true( $is_response, 'good bucket with backend view_url → WP_REST_Response' );
if ( $is_response ) {
	$items = $result->get_data()['items'] ?? array();
	assert_true( count( $items ) === 1, 'one item returned' );
	if ( count( $items ) === 1 ) {
		$item = $items[0];
		assert_true( array_key_exists( 'view_url', $item ),  'view_url preserved by allowlist' );
		assert_true( array_key_exists( 'edit_url', $item ),  'edit_url preserved' );
		assert_true( array_key_exists( 'item_id', $item ),   'item_id preserved' );
		assert_true( array_key_exists( 'draft_id', $item ),  'draft_id preserved' );
		assert_same(
			'https://example.com/products/widget/',
			$item['view_url'],
			'backend view_url value passes through unchanged'
		);
	}
}

// ── 4. view_url enriched locally from get_permalink when post exists ──────────
echo "[4] rest_list_content_items — view_url enriched from get_permalink\n";
$_wp_test_post_statuses[88] = 'publish';
$_wp_test_post_titles[88]   = 'My Great Product';
$_wp_test_edit_urls[88]     = 'https://example.com/wp-admin/post.php?post=88&action=edit';
$_wp_test_view_urls[88]     = 'https://example.com/products/my-great-product/';

$GLOBALS['_wp_test_remote_body'] = (string) json_encode( array(
	'items' => array(
		array(
			'item_id'      => 88,
			'item_type'    => 'product',
			'draft_id'     => 'dr_opt01',
			'restorable'   => false,
			'last_audited' => '2026-06-24T09:00:00Z',
		),
	),
) );

$req    = new WP_REST_Request( 'GET', '/staff-ai/content/list' );
$req->set_param( 'bucket', 'optimized' );
$result = DEF_Core_Staff_AI::rest_list_content_items( $req );
assert_true( $result instanceof WP_REST_Response, 'optimized bucket → WP_REST_Response' );
if ( $result instanceof WP_REST_Response ) {
	$items = $result->get_data()['items'] ?? array();
	assert_true( count( $items ) === 1, 'one enriched item returned' );
	if ( count( $items ) === 1 ) {
		$item = $items[0];
		assert_same( 'My Great Product',                                              $item['title']    ?? '', 'title enriched from WP' );
		assert_same( 'https://example.com/products/my-great-product/',                $item['view_url'] ?? '', 'view_url enriched from get_permalink' );
		assert_same( 'https://example.com/wp-admin/post.php?post=88&action=edit',     $item['edit_url'] ?? '', 'edit_url enriched from get_edit_post_link' );
	}
}

// ── 5. Unknown fields still stripped after view_url added to allowlist ─────────
echo "[5] rest_list_content_items — unknown fields stripped (view_url allowed, others not)\n";
$GLOBALS['_wp_test_remote_body'] = (string) json_encode( array(
	'items' => array(
		array(
			'item_id'         => 99,
			'item_type'       => 'product',
			'draft_id'        => 'dr_good03',
			'restorable'      => false,
			'last_audited'    => '2026-06-24T09:05:00Z',
			'view_url'        => 'https://example.com/products/foo/',
			'internal_secret' => 'must-be-stripped',
			'author_data'     => array( 'email' => 'priv@example.com' ),
		),
	),
) );
$req = new WP_REST_Request( 'GET', '/staff-ai/content/list' );
$req->set_param( 'bucket', 'good' );
$result = DEF_Core_Staff_AI::rest_list_content_items( $req );
if ( $result instanceof WP_REST_Response ) {
	$item = ( $result->get_data()['items'] ?? array() )[0] ?? array();
	assert_true( array_key_exists( 'view_url', $item ),         'view_url passes through allowlist' );
	assert_true( ! array_key_exists( 'internal_secret', $item ), 'internal_secret stripped' );
	assert_true( ! array_key_exists( 'author_data', $item ),     'author_data stripped' );
}

// ── Cleanup ───────────────────────────────────────────────────────────────────
$_wp_test_current_user             = null;
$GLOBALS['_def_test_api_url']      = null;
$GLOBALS['_wp_test_remote_status'] = null;
$GLOBALS['_wp_test_remote_body']   = null;

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
