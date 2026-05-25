<?php
/**
 * Knowledge exclusion (v3.1.0) — focused tests for the non-trivial behavior.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

global $_wp_test_post_meta, $_wp_test_post_types, $_wp_test_caps,
	$_wp_test_nonce_ok, $_wp_test_get_post_type;

// Mirror WP's get_post_types($args, 'names') return shape: key === value.
$_wp_test_post_meta     = array();
$_wp_test_post_types    = array(
	'post' => 'post', 'page' => 'page', 'product' => 'product',
	'a3-portfolio' => 'a3-portfolio', 'attachment' => 'attachment',
);
$_wp_test_caps          = array();
$_wp_test_nonce_ok      = true;
$_wp_test_get_post_type = array();

function get_post_meta( int $id, string $k, bool $single = true ) {
	global $_wp_test_post_meta;
	$v = $_wp_test_post_meta[ $id ][ $k ] ?? '';
	return $single ? $v : array( $v );
}
function update_post_meta( int $id, string $k, $v ): bool {
	global $_wp_test_post_meta;
	$_wp_test_post_meta[ $id ][ $k ] = $v;
	return true;
}
function register_post_meta( string $pt, string $k, array $a ): bool { return true; }
function get_post_types( $args, string $output = 'names' ): array {
	global $_wp_test_post_types;
	return $_wp_test_post_types;
}
function current_user_can( string $cap, $id = 0 ): bool {
	global $_wp_test_caps;
	return $cap === 'edit_post' && ! empty( $_wp_test_caps[ (int) $id ] );
}
function wp_verify_nonce( $n, $a ): bool { global $_wp_test_nonce_ok; return (bool) $_wp_test_nonce_ok; }
function wp_is_post_revision( int $id ): bool { return false; }
function get_post_type( int $id ) { global $_wp_test_get_post_type; return $_wp_test_get_post_type[ $id ] ?? 'post'; }
function add_query_arg( array $args, string $url ): string {
	return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $args );
}
foreach ( array( 'add_action', 'add_filter', 'add_meta_box', 'wp_enqueue_script', 'wp_nonce_field' ) as $fn ) {
	if ( ! function_exists( $fn ) ) eval( "function {$fn}() { return true; }" );
}
function esc_html( string $s ): string { return $s; }
function esc_html__( string $s, string $d = '' ): string { return $s; }
function esc_attr( string $s ): string { return $s; }
function esc_html_e( string $s, string $d = '' ): void { echo $s; }
function sanitize_key( string $k ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $k ) ); }
function checked( $v ): string { return $v ? ' checked' : ''; }

if ( ! defined( 'DEF_CORE_PLUGIN_URL' ) ) {
	define( 'DEF_CORE_PLUGIN_URL', 'https://example.test/' );
}

// Per-item deindex path collaborators: a mock export class that records the
// exclusion-change tracking, a $wpdb that records post_modified bumps, and
// clean_post_cache. (record_exclusion_transition() calls these at runtime.)
global $_wp_test_tracked_exclusions;
$_wp_test_tracked_exclusions = array();

if ( ! class_exists( 'DEF_Core_Knowledge_Export' ) ) {
	class DEF_Core_Knowledge_Export {
		public static function track_exclusion_change( int $post_id, string $post_type, bool $excluded ): void {
			global $_wp_test_tracked_exclusions;
			$_wp_test_tracked_exclusions[] = array( $post_id, $post_type, $excluded );
		}
	}
}

class _Def_Test_WPDB {
	public $posts = 'wp_posts';
	public $calls = array();
	public function update( $table, $data, $where, $fmt = null, $wfmt = null ) {
		$this->calls[] = array( $table, $data, $where );
		return 1;
	}
}
global $wpdb;
$wpdb = new _Def_Test_WPDB();

if ( ! function_exists( 'clean_post_cache' ) ) {
	function clean_post_cache( $id ) {}
}

require_once __DIR__ . '/../includes/class-def-core-knowledge-exclusion.php';

$pass = 0;
$fail = 0;
$assert = static function ( bool $cond, string $msg ) use ( &$pass, &$fail ): void {
	if ( $cond ) { $pass++; echo "  ✓ {$msg}\n"; } else { $fail++; echo "  ✗ {$msg}\n"; }
};

echo "Knowledge exclusion (v3.1.0)\n";

// 1. Meta key contract — must match what the DEF backend reads.
$assert(
	DEF_Core_Knowledge_Exclusion::META_KEY === '_def_exclude_from_ingestion',
	'META_KEY constant matches DEF backend contract'
);

// 2. Supported post types: attachment is filtered out (not standalone-ingested).
$types = DEF_Core_Knowledge_Exclusion::get_supported_post_types();
$assert(
	! in_array( 'attachment', $types, true ) && in_array( 'product', $types, true ),
	'attachment filtered out, real post types kept'
);

// 3. handle_bulk_actions: writes meta on each capable+supported post, skips others, counts only updates.
$_wp_test_post_meta     = array();
$_wp_test_caps          = array( 8516 => true, 8517 => false, 8518 => true, 8519 => true );
$_wp_test_get_post_type = array( 8516 => 'product', 8517 => 'product', 8518 => 'attachment', 8519 => 'product' );
$url = DEF_Core_Knowledge_Exclusion::handle_bulk_actions(
	'https://e.test', DEF_Core_Knowledge_Exclusion::BULK_EXCLUDE,
	array( 8516, 8517, 8518, 8519 )
);
$assert(
	($_wp_test_post_meta[8516]['_def_exclude_from_ingestion'] ?? null) === true
	&& ! isset( $_wp_test_post_meta[ 8517 ] )            // no edit_post cap
	&& ! isset( $_wp_test_post_meta[ 8518 ] )            // attachment (unsupported)
	&& ($_wp_test_post_meta[8519]['_def_exclude_from_ingestion'] ?? null) === true,
	'BULK_EXCLUDE writes only on capable + supported post types'
);
$assert(
	str_contains( $url, 'def_core_bulk_count=2' ),
	'bulk count reflects only authorized + supported updates'
);

// 4. handle_bulk_actions ignores unrelated bulk actions (e.g. Trash).
$assert(
	DEF_Core_Knowledge_Exclusion::handle_bulk_actions( 'https://e.test/x', 'trash', array( 8516 ) )
		=== 'https://e.test/x',
	'unrelated bulk actions pass through untouched'
);

// 5. auth_can_edit_post delegates correctly (defense-in-depth meta auth).
$_wp_test_caps = array( 8516 => true, 8517 => false );
$assert(
	DEF_Core_Knowledge_Exclusion::auth_can_edit_post( false, '_def_exclude_from_ingestion', 8516 ) === true
	&& DEF_Core_Knowledge_Exclusion::auth_can_edit_post( false, '_def_exclude_from_ingestion', 8517 ) === false,
	'auth_can_edit_post delegates to current_user_can(edit_post, $id)'
);

// 6. save_post: nonce + capability + post_type all enforced.
$cases = array(
	array(
		'name'  => 'happy path writes true',
		'meta'  => array(),
		'caps'  => array( 8516 => true ),
		'type'  => array( 8516 => 'product' ),
		'nonce' => true,
		'post'  => array( 'def_core_exclusion_nonce' => 'x', '_def_exclude_from_ingestion' => '1' ),
		'expect_set'   => true,
		'expect_value' => true,
	),
	array(
		'name'  => 'unchecked writes false',
		'meta'  => array(),
		'caps'  => array( 8516 => true ),
		'type'  => array( 8516 => 'product' ),
		'nonce' => true,
		'post'  => array( 'def_core_exclusion_nonce' => 'x' ),
		'expect_set'   => true,
		'expect_value' => false,
	),
	array(
		'name'  => 'bails when nonce field missing',
		'meta'  => array(),
		'caps'  => array( 8516 => true ),
		'type'  => array( 8516 => 'product' ),
		'nonce' => true,
		'post'  => array( '_def_exclude_from_ingestion' => '1' ),
		'expect_set'   => false,
	),
	array(
		'name'  => 'bails when nonce invalid',
		'meta'  => array(),
		'caps'  => array( 8516 => true ),
		'type'  => array( 8516 => 'product' ),
		'nonce' => false,
		'post'  => array( 'def_core_exclusion_nonce' => 'x', '_def_exclude_from_ingestion' => '1' ),
		'expect_set'   => false,
	),
	array(
		'name'  => 'bails on unsupported post type',
		'meta'  => array(),
		'caps'  => array( 8516 => true ),
		'type'  => array( 8516 => 'attachment' ),
		'nonce' => true,
		'post'  => array( 'def_core_exclusion_nonce' => 'x', '_def_exclude_from_ingestion' => '1' ),
		'expect_set'   => false,
	),
);
foreach ( $cases as $c ) {
	$_wp_test_post_meta     = $c['meta'];
	$_wp_test_caps          = $c['caps'];
	$_wp_test_get_post_type = $c['type'];
	$_wp_test_nonce_ok      = $c['nonce'];
	$_POST                  = $c['post'];
	DEF_Core_Knowledge_Exclusion::save_post( 8516 );
	$is_set = isset( $_wp_test_post_meta[ 8516 ]['_def_exclude_from_ingestion'] );
	if ( $c['expect_set'] ) {
		$assert(
			$is_set && $_wp_test_post_meta[ 8516 ]['_def_exclude_from_ingestion'] === $c['expect_value'],
			"save_post: {$c['name']}"
		);
	} else {
		$assert( ! $is_set, "save_post: {$c['name']}" );
	}
}

// 7. Per-item deindex on flag change ----------------------------------------
global $_wp_test_tracked_exclusions, $wpdb;
$_wp_test_get_post_type = array( 700 => 'product', 701 => 'attachment' );

// Supported type → records the transition AND bumps post_modified (this item).
$_wp_test_tracked_exclusions = array();
$wpdb->calls                 = array();
DEF_Core_Knowledge_Exclusion::record_exclusion_transition( 700, true );
$assert(
	count( $_wp_test_tracked_exclusions ) === 1
		&& $_wp_test_tracked_exclusions[0] === array( 700, 'product', true )
		&& count( $wpdb->calls ) === 1
		&& ( $wpdb->calls[0][2]['ID'] ?? null ) === 700,
	'record_exclusion_transition: tracks excluded + bumps post_modified'
);

// Unsupported type (attachment) → no tracking, no modified bump.
$_wp_test_tracked_exclusions = array();
$wpdb->calls                 = array();
DEF_Core_Knowledge_Exclusion::record_exclusion_transition( 701, true );
$assert(
	count( $_wp_test_tracked_exclusions ) === 0 && count( $wpdb->calls ) === 0,
	'record_exclusion_transition: skips unsupported post type'
);

// Meta-write hook ignores unrelated meta keys (fires for ALL post meta).
$_wp_test_tracked_exclusions = array();
DEF_Core_Knowledge_Exclusion::on_exclusion_meta_write( 1, 700, '_some_other_meta', '1' );
$assert( count( $_wp_test_tracked_exclusions ) === 0, 'meta-write hook ignores unrelated keys' );

// Value mapping: '1'/true = excluded; '0'/'' = included (re-included).
$map = array(
	array( '1', true ),
	array( 1, true ),
	array( true, true ),
	array( '0', false ),
	array( '', false ),
	array( false, false ),
);
foreach ( $map as $i => $case ) {
	$_wp_test_tracked_exclusions = array();
	DEF_Core_Knowledge_Exclusion::on_exclusion_meta_write( 1, 700, '_def_exclude_from_ingestion', $case[0] );
	$got = $_wp_test_tracked_exclusions[0][2] ?? null;
	$assert( $got === $case[1], "meta-write value " . var_export( $case[0], true ) . " → excluded=" . var_export( $case[1], true ) );
}

// Deleting the flag === back to included.
$_wp_test_tracked_exclusions = array();
DEF_Core_Knowledge_Exclusion::on_exclusion_meta_delete( array( 1 ), 700, '_def_exclude_from_ingestion', '1' );
$assert(
	( $_wp_test_tracked_exclusions[0][2] ?? null ) === false,
	'meta-delete → excluded=false (re-included)'
);

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
