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

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
