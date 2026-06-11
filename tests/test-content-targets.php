<?php
/**
 * Content Agent Engine 2.5 — Clusters curation BFF tests (targets + keyphrase
 * queues, PR 2.5-2).
 *
 * Behaviour verified:
 *  - All curation routes are registered with permission callbacks.
 *  - backend_request speaks PATCH and DELETE (not just GET/POST).
 *  - Nomination forwards EVERY accepted field faithfully (the BFF has a history
 *    of dropping fields in the remap — this pins the passthrough).
 *  - reference_urls are validated: max 5, http(s) only, rejected explicitly.
 *  - PATCH semantics: only the keys present in the request are forwarded.
 *  - Keyphrase queue rows pass through unchanged; written rows with a local
 *    post_id are enriched with edit_url/view_url.
 *  - Create forwards the optional notes field (and omits it when empty).
 *  - The local target-search picker returns nomination-shaped items with
 *    source_route from the type's rest_base, and never searches attachments.
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── WP stubs ────────────────────────────────────────────────────────────

global $_wp_test_rest_routes, $_wp_test_current_user, $_wp_test_user_caps;
$_wp_test_rest_routes  = array();
$_wp_test_current_user = null;
$_wp_test_user_caps    = array();

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
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, int $status = 200 ) { $this->data = $data; $this->status = $status; }
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = array();
		private $body   = array();
		public function __construct( string $method = 'GET', string $route = '' ) {}
		public function set_param( string $key, $value ): void { $this->params[ $key ] = $value; }
		public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
		public function set_json( array $b ): void { $this->body = $b; }
		public function get_json_params() { return $this->body; }
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args ): void {
		global $_wp_test_rest_routes;
		$_wp_test_rest_routes[ $namespace . $route ] = $args;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( 'add_rewrite_rule' ) ) {
	function add_rewrite_rule( string $regex, string $query, string $after = 'bottom' ): void {}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string { return $text; }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		global $_wp_test_current_user;
		return null !== $_wp_test_current_user;
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
		public $user_email   = '';
		public $display_name = '';
		public $roles        = array();
		public function __construct( int $id = 0 ) { $this->ID = $id; }
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
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, $value ) { return null; }
}
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( string $var, $default = '' ) { return $default; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string { return 'Test Site'; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! class_exists( 'DEF_Core' ) ) {
	class DEF_Core {
		public static function get_def_api_url_internal(): ?string { return 'https://def-api.test'; }
	}
}
// Capability resolver used by backend_request's proxy headers.
if ( ! class_exists( 'DEF_Core_Tools' ) ) {
	class DEF_Core_Tools {
		public static function get_user_def_capabilities( $user ): array {
			return array( 'def_staff_access' );
		}
	}
}

// ── HTTP capture: every backend call is logged; the next response is canned ──
$GLOBALS['_t_http_log']  = array();
$GLOBALS['_t_http_next'] = array( 'code' => 200, 'body' => '{}' );

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( string $url, array $args = array() ) {
		$GLOBALS['_t_http_log'][] = array(
			'url'    => $url,
			'method' => $args['method'] ?? 'GET',
			'args'   => $args,
		);
		return $GLOBALS['_t_http_next'];
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		$args['method'] = 'GET';
		return wp_remote_request( $url, $args );
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		$args['method'] = 'POST';
		return wp_remote_request( $url, $args );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['code'] ?? 0 ) : 0; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
}

// ── Local WP content stubs (enrichment + the target-search picker) ──────
$GLOBALS['_t_posts']          = array(); // id => ['status','title','permalink','type']
$GLOBALS['_t_get_posts_args'] = null;
$GLOBALS['_t_search_results'] = array();

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id ) {
		return $GLOBALS['_t_posts'][ (int) $id ]['status'] ?? false;
	}
}
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $id, $context = 'display' ) {
		return 'https://site.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['_t_posts'][ $id ]['permalink'] ?? ( 'https://site.test/?p=' . $id );
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['_t_posts'][ $id ]['title'] ?? '';
	}
}
if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( array $args = array(), string $output = 'names' ): array {
		return array(
			'post'       => 'post',
			'page'       => 'page',
			'product'    => 'product',
			'attachment' => 'attachment',
		);
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = array() ): array {
		$GLOBALS['_t_get_posts_args'] = $args;
		return $GLOBALS['_t_search_results'];
	}
}
if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $type ) {
		$rest_bases = array( 'product' => 'products', 'post' => 'posts' );
		$o            = new stdClass();
		$o->rest_base = $rest_bases[ $type ] ?? '';
		return $o;
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-staff-ai.php';

// Authenticated staff user + configured API key (backend_request preconditions).
$_wp_test_current_user     = new WP_User( 5 );
$_wp_test_user_caps        = array( 'def_staff_access' );
\DEF_Core_Encryption::set_secret( 'def_core_api_key', 'test-api-key' );

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

/** Reset the HTTP log and set the canned backend response. */
function http_reset( int $code = 200, $payload = array() ): void {
	$GLOBALS['_t_http_log']  = array();
	$GLOBALS['_t_http_next'] = array( 'code' => $code, 'body' => json_encode( $payload ) );
}

/** The single captured backend call (asserts exactly one was made). */
function http_one(): ?array {
	return 1 === count( $GLOBALS['_t_http_log'] ) ? $GLOBALS['_t_http_log'][0] : null;
}

function req_json( array $body, array $params = array() ): WP_REST_Request {
	$r = new WP_REST_Request( 'POST', '' );
	$r->set_json( $body );
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	return $r;
}

// ── 1. Route registration ───────────────────────────────────────────────
echo "[1] curation routes registered with permission callbacks\n";
DEF_Core_Staff_AI::register_rest_routes();

$id_pat = '(?P<id>[a-zA-Z0-9_-]+)';
$dual   = array(
	'a3-ai/v1/staff-ai/content/targets'                          => array( 'GET', 'POST' ),
	"a3-ai/v1/staff-ai/content/targets/$id_pat"                  => array( 'PATCH', 'DELETE' ),
	"a3-ai/v1/staff-ai/content/targets/$id_pat/keyphrases"       => array( 'GET', 'POST' ),
);
foreach ( $dual as $route => $methods ) {
	$args = $_wp_test_rest_routes[ $route ] ?? null;
	assert_true( is_array( $args ), "route registered: $route" );
	if ( is_array( $args ) ) {
		assert_same( $methods[0], $args[0]['methods'] ?? null, "$route first method" );
		assert_same( $methods[1], $args[1]['methods'] ?? null, "$route second method" );
		assert_true( ! empty( $args[0]['permission_callback'] ) && ! empty( $args[1]['permission_callback'] ),
			"$route both handlers have permission callbacks" );
	}
}
$single = array(
	"a3-ai/v1/staff-ai/content/targets/$id_pat/derive"                          => 'POST',
	"a3-ai/v1/staff-ai/content/targets/$id_pat/keyphrases/dismiss-remaining"    => 'POST',
	"a3-ai/v1/staff-ai/content/keyphrases/$id_pat"                              => 'PATCH',
	"a3-ai/v1/staff-ai/content/keyphrases/$id_pat/approve"                      => 'POST',
	"a3-ai/v1/staff-ai/content/keyphrases/$id_pat/dismiss"                      => 'POST',
	'a3-ai/v1/staff-ai/content/target-search'                                   => 'GET',
);
foreach ( $single as $route => $method ) {
	$args = $_wp_test_rest_routes[ $route ] ?? null;
	assert_true( is_array( $args ), "route registered: $route" );
	if ( is_array( $args ) ) {
		assert_same( $method, $args['methods'] ?? null, "$route method" );
		assert_true( ! empty( $args['permission_callback'] ), "$route has a permission callback" );
	}
}

// ── 2. Nomination forwards every accepted field faithfully ──────────────
echo "[2] POST /targets — field-faithful passthrough\n";
http_reset( 200, array( 'id' => 't-1', 'title' => 'Quotes & Orders' ) );
$resp = DEF_Core_Staff_AI::rest_create_content_target( req_json( array(
	'item_type'      => 'page',
	'item_id'        => '42',
	'source_route'   => 'pages',
	'title'          => 'Quotes & Orders',
	'url'            => 'https://site.test/quotes-orders/',
	'reference_urls' => array( 'https://docs.site.test/quotes', 'https://docs.site.test/orders' ),
) ) );
assert_true( $resp instanceof WP_REST_Response, 'returns a REST response' );
$call = http_one();
assert_true( null !== $call, 'exactly one backend call' );
assert_same( 'POST', $call['method'] ?? null, 'method POST' );
assert_same( 'https://def-api.test/api/staff-ai/content/targets', $call['url'] ?? null, 'DEF targets URL' );
$sent = json_decode( $call['args']['body'] ?? '', true );
assert_same( 'page', $sent['item_type'] ?? null, 'item_type forwarded' );
assert_same( '42', $sent['item_id'] ?? null, 'item_id forwarded' );
assert_same( 'pages', $sent['source_route'] ?? null, 'source_route forwarded' );
assert_same( 'Quotes & Orders', $sent['title'] ?? null, 'title forwarded' );
assert_same( 'https://site.test/quotes-orders/', $sent['url'] ?? null, 'url forwarded' );
assert_same(
	array( 'https://docs.site.test/quotes', 'https://docs.site.test/orders' ),
	$sent['reference_urls'] ?? null,
	'reference_urls forwarded intact (no dropped fields)'
);
assert_true( ! empty( $call['args']['headers']['X-DEF-API-Key'] ), 'BFF auth header present' );
assert_same( 't-1', $resp->get_data()['id'] ?? null, 'DEF response passed back to the client' );

// Numeric item_id (int over JSON) is accepted as digits.
http_reset();
$resp = DEF_Core_Staff_AI::rest_create_content_target( req_json( array(
	'item_type' => 'post', 'item_id' => 42, 'title' => 'T', 'url' => 'https://site.test/t/',
) ) );
assert_true( $resp instanceof WP_REST_Response, 'integer item_id accepted' );
$sent = json_decode( http_one()['args']['body'] ?? '', true );
assert_same( '42', $sent['item_id'] ?? null, 'integer item_id forwarded as digit string' );
assert_true( ! array_key_exists( 'source_route', $sent ), 'absent source_route not invented' );
assert_true( ! array_key_exists( 'reference_urls', $sent ), 'absent reference_urls not invented' );

// ── 3. Nomination validation ────────────────────────────────────────────
echo "[3] POST /targets — validation rejects bad input before any backend call\n";
$bad_cases = array(
	'missing item_id'    => array( 'item_type' => 'page', 'title' => 'T', 'url' => 'https://x.test/' ),
	'non-digit item_id'  => array( 'item_type' => 'page', 'item_id' => '12abc', 'title' => 'T', 'url' => 'https://x.test/' ),
	'missing title'      => array( 'item_type' => 'page', 'item_id' => '1', 'url' => 'https://x.test/' ),
	'non-http url'       => array( 'item_type' => 'page', 'item_id' => '1', 'title' => 'T', 'url' => 'javascript:alert(1)' ),
);
foreach ( $bad_cases as $label => $body ) {
	http_reset();
	$resp = DEF_Core_Staff_AI::rest_create_content_target( req_json( $body ) );
	assert_true( is_wp_error( $resp ), "$label → WP_Error" );
	assert_same( 400, $resp->get_error_data()['status'] ?? null, "$label → 400" );
	assert_same( array(), $GLOBALS['_t_http_log'], "$label → no backend call" );
}

// reference_urls: over the cap and invalid entries are rejected explicitly.
http_reset();
$six  = array_map( static function ( $i ) { return "https://docs.test/$i"; }, range( 1, 6 ) );
$resp = DEF_Core_Staff_AI::rest_create_content_target( req_json( array(
	'item_type' => 'page', 'item_id' => '1', 'title' => 'T', 'url' => 'https://x.test/',
	'reference_urls' => $six,
) ) );
assert_true( is_wp_error( $resp ), '6 reference URLs → WP_Error' );
assert_same( 'invalid_reference_urls', $resp->get_error_code(), '6 URLs → invalid_reference_urls' );
http_reset();
$resp = DEF_Core_Staff_AI::rest_create_content_target( req_json( array(
	'item_type' => 'page', 'item_id' => '1', 'title' => 'T', 'url' => 'https://x.test/',
	'reference_urls' => array( 'ftp://files.test/doc' ),
) ) );
assert_true( is_wp_error( $resp ), 'non-http reference URL → WP_Error (rejected, not silently dropped)' );
assert_same( array(), $GLOBALS['_t_http_log'], 'invalid reference_urls → no backend call' );

// ── 4. PATCH /targets/{id} — true PATCH semantics ───────────────────────
echo "[4] PATCH target — forwards only the provided keys, via HTTP PATCH\n";
http_reset( 200, array( 'id' => 't-1', 'status' => 'paused' ) );
$resp = DEF_Core_Staff_AI::rest_update_content_target( req_json( array( 'status' => 'paused' ), array( 'id' => 't-1' ) ) );
$call = http_one();
assert_same( 'PATCH', $call['method'] ?? null, 'HTTP method is PATCH' );
assert_same( 'https://def-api.test/api/staff-ai/content/targets/t-1', $call['url'] ?? null, 'target URL carries the id' );
assert_same( array( 'status' => 'paused' ), json_decode( $call['args']['body'] ?? '', true ), 'body contains ONLY status' );

http_reset();
$resp = DEF_Core_Staff_AI::rest_update_content_target( req_json( array( 'status' => 'archived' ), array( 'id' => 't-1' ) ) );
assert_true( is_wp_error( $resp ), 'unknown status → WP_Error' );
http_reset();
$resp = DEF_Core_Staff_AI::rest_update_content_target( req_json( array(), array( 'id' => 't-1' ) ) );
assert_true( is_wp_error( $resp ), 'empty PATCH → WP_Error (nothing to update)' );
assert_same( array(), $GLOBALS['_t_http_log'], 'invalid PATCH → no backend call' );

// reference_urls replace rides PATCH too (the Clusters tab's Save).
http_reset( 200, array( 'id' => 't-1' ) );
DEF_Core_Staff_AI::rest_update_content_target( req_json( array( 'reference_urls' => array( 'https://docs.test/a' ) ), array( 'id' => 't-1' ) ) );
assert_same(
	array( 'reference_urls' => array( 'https://docs.test/a' ) ),
	json_decode( http_one()['args']['body'] ?? '', true ),
	'reference_urls-only PATCH body'
);

// ── 5. DELETE /targets/{id} ─────────────────────────────────────────────
echo "[5] DELETE target — HTTP DELETE to the DEF route\n";
http_reset( 200, array( 'status' => 'deleted' ) );
$resp = DEF_Core_Staff_AI::rest_delete_content_target( req_json( array(), array( 'id' => 't-9' ) ) );
$call = http_one();
assert_same( 'DELETE', $call['method'] ?? null, 'HTTP method is DELETE' );
assert_same( 'https://def-api.test/api/staff-ai/content/targets/t-9', $call['url'] ?? null, 'delete URL' );
assert_same( 'deleted', $resp->get_data()['status'] ?? null, 'status passed through' );

// ── 6. Keyphrase queue: list passthrough + written-row enrichment ───────
echo "[6] GET keyphrases — rows pass through; written rows gain post links\n";
$GLOBALS['_t_posts'] = array(
	7 => array( 'status' => 'publish', 'title' => 'Cluster post', 'permalink' => 'https://site.test/cluster-post/' ),
);
http_reset( 200, array( 'keyphrases' => array(
	array(
		'id' => 'k1', 'target_id' => 't-1', 'phrase' => 'how does a quote become an order',
		'intent_type' => 'how_to', 'status' => 'written', 'rationale' => 'gap vs competitor docs',
		'staged_change_id' => 'sc-1', 'post_id' => 7, 'created_at' => '2026-06-11T00:00:00Z',
	),
	array(
		'id' => 'k2', 'target_id' => 't-1', 'phrase' => 'woocommerce quote plugin comparison',
		'intent_type' => 'comparison_buying', 'status' => 'proposed', 'rationale' => null,
		'staged_change_id' => null, 'post_id' => null, 'created_at' => '2026-06-11T00:00:01Z',
	),
) ) );
$resp = DEF_Core_Staff_AI::rest_list_target_keyphrases( req_json( array(), array( 'id' => 't-1' ) ) );
$rows = $resp->get_data()['keyphrases'];
assert_same( 2, count( $rows ), 'both rows returned' );
assert_same( 'gap vs competitor docs', $rows[0]['rationale'], 'rationale passed through (no dropped fields)' );
assert_same( 'sc-1', $rows[0]['staged_change_id'], 'staged_change_id passed through' );
assert_same( 'how_to', $rows[0]['intent_type'], 'intent_type passed through' );
assert_same( '2026-06-11T00:00:00Z', $rows[0]['created_at'], 'created_at passed through' );
assert_true( false !== strpos( $rows[0]['edit_url'] ?? '', 'post=7' ), 'written row enriched with edit_url' );
assert_same( 'https://site.test/cluster-post/', $rows[0]['view_url'] ?? null, 'written row enriched with view_url' );
assert_true( ! array_key_exists( 'edit_url', $rows[1] ), 'row without a local post is not enriched' );
assert_same( 'proposed', $rows[1]['status'], 'second row intact' );

// ── 7. Keyphrase add / edit / approve / dismiss / derive proxies ────────
echo "[7] keyphrase actions — bodies and verbs hit the right DEF routes\n";
http_reset( 200, array( 'id' => 'k3' ) );
DEF_Core_Staff_AI::rest_add_target_keyphrase( req_json(
	array( 'phrase' => 'quote follow up email', 'intent_type' => 'use_case' ),
	array( 'id' => 't-1' )
) );
$call = http_one();
assert_same( 'POST', $call['method'] ?? null, 'add is POST' );
assert_same( 'https://def-api.test/api/staff-ai/content/targets/t-1/keyphrases', $call['url'] ?? null, 'add URL' );
assert_same(
	array( 'phrase' => 'quote follow up email', 'intent_type' => 'use_case' ),
	json_decode( $call['args']['body'] ?? '', true ),
	'add body'
);

http_reset();
$resp = DEF_Core_Staff_AI::rest_add_target_keyphrase( req_json( array( 'phrase' => 'x' ), array( 'id' => 't-1' ) ) );
assert_true( is_wp_error( $resp ), 'add without intent_type → WP_Error' );
assert_same( array(), $GLOBALS['_t_http_log'], 'invalid add → no backend call' );

http_reset( 200, array( 'id' => 'k1' ) );
DEF_Core_Staff_AI::rest_update_keyphrase( req_json( array( 'phrase' => 'edited phrase' ), array( 'id' => 'k1' ) ) );
$call = http_one();
assert_same( 'PATCH', $call['method'] ?? null, 'keyphrase edit is PATCH' );
assert_same( 'https://def-api.test/api/staff-ai/content/keyphrases/k1', $call['url'] ?? null, 'keyphrase URL' );
assert_same( array( 'phrase' => 'edited phrase' ), json_decode( $call['args']['body'] ?? '', true ), 'edit body has only phrase' );

http_reset();
$resp = DEF_Core_Staff_AI::rest_update_keyphrase( req_json( array(), array( 'id' => 'k1' ) ) );
assert_true( is_wp_error( $resp ), 'empty keyphrase PATCH → WP_Error' );

foreach ( array( 'approve', 'dismiss' ) as $action ) {
	http_reset( 200, array( 'id' => 'k1', 'status' => $action === 'approve' ? 'approved' : 'dismissed' ) );
	$method = 'rest_' . $action . '_keyphrase';
	DEF_Core_Staff_AI::$method( req_json( array(), array( 'id' => 'k1' ) ) );
	$call = http_one();
	assert_same( 'POST', $call['method'] ?? null, "$action is POST" );
	assert_same( "https://def-api.test/api/staff-ai/content/keyphrases/k1/$action", $call['url'] ?? null, "$action URL" );
}

http_reset( 200, array( 'status' => 'accepted' ) );
$resp = DEF_Core_Staff_AI::rest_derive_content_target( req_json( array(), array( 'id' => 't-1' ) ) );
$call = http_one();
assert_same( 'https://def-api.test/api/staff-ai/content/targets/t-1/derive', $call['url'] ?? null, 'derive URL' );
assert_same( 'accepted', $resp->get_data()['status'] ?? null, 'derive ack passed through' );

// ── 7b. Bulk "Dismiss remaining" (Clusters UX v2) ───────────────────────
echo "[7b] dismiss-remaining — proxy pass-through + in_review normalization\n";
http_reset( 200, array(
	'dismissed'        => 7,
	'keyphrase_counts' => array( 'proposed' => 0, 'approved' => 5, 'in_review' => 1, 'written' => 2, 'dismissed' => 11 ),
) );
$resp = DEF_Core_Staff_AI::rest_dismiss_remaining_keyphrases( req_json( array(), array( 'id' => 't-1' ) ) );
$call = http_one();
assert_true( null !== $call, 'dismiss-remaining: exactly one backend call' );
assert_same( 'POST', $call['method'] ?? null, 'dismiss-remaining is POST' );
assert_same(
	'https://def-api.test/api/staff-ai/content/targets/t-1/keyphrases/dismiss-remaining',
	$call['url'] ?? null,
	'dismiss-remaining DEF URL carries the target id'
);
assert_same( 7, $resp->get_data()['dismissed'] ?? null, 'dismissed count passed through' );
assert_same(
	array( 'proposed' => 0, 'approved' => 5, 'in_review' => 1, 'written' => 2, 'dismissed' => 11 ),
	$resp->get_data()['keyphrase_counts'] ?? null,
	'keyphrase_counts passed through unchanged when in_review present'
);

// Older DEF without the in_review split → normalized to 0; other counts intact.
http_reset( 200, array(
	'dismissed'        => 3,
	'keyphrase_counts' => array( 'proposed' => 0, 'approved' => 4, 'written' => 1, 'dismissed' => 6 ),
) );
$resp   = DEF_Core_Staff_AI::rest_dismiss_remaining_keyphrases( req_json( array(), array( 'id' => 't-2' ) ) );
$counts = $resp->get_data()['keyphrase_counts'] ?? array();
assert_same( 0, $counts['in_review'] ?? null, 'missing in_review normalized to 0' );
assert_same( 4, $counts['approved'] ?? null, 'approved intact alongside the normalization' );

// Backend errors surface as errors (not silently swallowed).
http_reset( 404, array( 'detail' => 'target not found' ) );
$resp = DEF_Core_Staff_AI::rest_dismiss_remaining_keyphrases( req_json( array(), array( 'id' => 't-x' ) ) );
assert_true( is_wp_error( $resp ), 'dismiss-remaining backend 404 → WP_Error' );
assert_same( 404, $resp->get_error_data()['status'] ?? null, '404 status preserved' );

// ── 8. Create: optional notes forwarded, omitted when empty ─────────────
echo "[8] create — notes passthrough\n";
http_reset( 200, array( 'status' => 'accepted' ) );
DEF_Core_Staff_AI::rest_content_create( req_json( array( 'keyphrase' => 'best running shoes', 'notes' => 'audience: trail runners' ) ) );
$sent = json_decode( http_one()['args']['body'] ?? '', true );
assert_same( 'best running shoes', $sent['keyphrase'] ?? null, 'keyphrase forwarded' );
assert_same( 'audience: trail runners', $sent['notes'] ?? null, 'notes forwarded' );

http_reset( 200, array( 'status' => 'accepted' ) );
DEF_Core_Staff_AI::rest_content_create( req_json( array( 'keyphrase' => 'best running shoes' ) ) );
$sent = json_decode( http_one()['args']['body'] ?? '', true );
assert_true( ! array_key_exists( 'notes', $sent ), 'no notes key when the field is empty' );

http_reset();
$resp = DEF_Core_Staff_AI::rest_content_create( req_json( array( 'keyphrase' => 'kp', 'notes' => str_repeat( 'a', 2001 ) ) ) );
assert_true( is_wp_error( $resp ), 'over-long notes → WP_Error' );
assert_same( 'invalid_notes', $resp->get_error_code(), 'over-long notes → invalid_notes' );
assert_same( array(), $GLOBALS['_t_http_log'], 'over-long notes → no backend call' );

// ── 9. Local target-search picker ───────────────────────────────────────
echo "[9] target-search — nomination-shaped items, attachments excluded\n";
$p1 = new stdClass(); $p1->ID = 42; $p1->post_type = 'page';
$p2 = new stdClass(); $p2->ID = 99; $p2->post_type = 'product';
$GLOBALS['_t_search_results'] = array( $p1, $p2 );
$GLOBALS['_t_posts'][42] = array( 'status' => 'publish', 'title' => 'Quotes & Orders', 'permalink' => 'https://site.test/quotes-orders/' );
$GLOBALS['_t_posts'][99] = array( 'status' => 'publish', 'title' => 'Quote Pro', 'permalink' => 'https://site.test/product/quote-pro/' );
$GLOBALS['_t_get_posts_args'] = null;

$resp  = DEF_Core_Staff_AI::rest_target_search( req_json( array(), array( 'q' => 'quote' ) ) );
$items = $resp->get_data()['items'];
assert_same( 2, count( $items ), 'two items returned' );
assert_same( 'page', $items[0]['item_type'], 'item_type from the post type' );
assert_same( '42', $items[0]['item_id'], 'item_id is a digit string' );
assert_same( 'Quotes & Orders', $items[0]['title'], 'title resolved locally' );
assert_same( 'https://site.test/quotes-orders/', $items[0]['url'], 'url is the permalink' );
assert_same( 'page', $items[0]['source_route'], 'built-in without rest_base falls back to the type' );
assert_same( 'products', $items[1]['source_route'], 'source_route from the discovered rest_base' );
$q_args = $GLOBALS['_t_get_posts_args'];
assert_same( 'quote', $q_args['s'] ?? null, 'search term passed to WP_Query' );
assert_true( ! in_array( 'attachment', $q_args['post_type'] ?? array(), true ), 'attachments never searched' );
assert_true( in_array( 'post', $q_args['post_type'] ?? array(), true ) && in_array( 'page', $q_args['post_type'] ?? array(), true ),
	'posts and pages first-class in the picker' );
assert_same( 'publish', $q_args['post_status'] ?? null, 'published items only' );

// Short query short-circuits without touching WP_Query.
$GLOBALS['_t_get_posts_args'] = null;
$resp = DEF_Core_Staff_AI::rest_target_search( req_json( array(), array( 'q' => 'q' ) ) );
assert_same( array(), $resp->get_data()['items'], 'sub-2-char query → empty items' );
assert_same( null, $GLOBALS['_t_get_posts_args'], 'sub-2-char query → no WP_Query' );

// ── 10. Backend errors surface as errors (not silently swallowed) ───────
echo "[10] DEF 409/422 surface to the client\n";
http_reset( 409, array( 'detail' => 'phrase already queued for this tenant' ) );
$resp = DEF_Core_Staff_AI::rest_add_target_keyphrase( req_json(
	array( 'phrase' => 'duplicate phrase', 'intent_type' => 'how_to' ),
	array( 'id' => 't-1' )
) );
assert_true( is_wp_error( $resp ), 'backend 409 → WP_Error' );
assert_same( 409, $resp->get_error_data()['status'] ?? null, '409 status preserved' );
assert_true( false !== strpos( $resp->get_error_message(), 'phrase already queued' ), 'backend detail carried in the message' );

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
