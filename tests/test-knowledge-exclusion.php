<?php
/**
 * Knowledge exclusion feature tests (v3.1.0).
 *
 * Verifies:
 * - Meta key constant matches the agreed contract (DEF backend reads this).
 * - get_supported_post_types filters out attachment + private/non-REST types.
 * - is_excluded reads the meta correctly.
 * - add_list_column adds the DEF column without clobbering existing columns.
 * - add_bulk_actions exposes both Exclude and Include options.
 * - handle_bulk_actions updates each post's meta and counts results.
 * - auth_can_edit_post delegates to current_user_can('edit_post', $id).
 * - save_post is gated by nonce, capability, and supported-post-type.
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

global $_wp_test_post_meta, $_wp_test_post_types, $_wp_test_caps,
	$_wp_test_nonce_ok, $_wp_test_get_post_type;

$_wp_test_post_meta     = array();
// Mirror WP's get_post_types($args, 'names') return shape: key === value (both string).
$_wp_test_post_types    = array(
	'post'         => 'post',
	'page'         => 'page',
	'product'      => 'product',
	'a3-portfolio' => 'a3-portfolio',
	'attachment'   => 'attachment',
);
$_wp_test_caps          = array(); // map post_id => bool
$_wp_test_nonce_ok      = true;
$_wp_test_get_post_type = array(); // post_id => post_type

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single = true ) {
		global $_wp_test_post_meta;
		$v = $_wp_test_post_meta[ $post_id ][ $key ] ?? '';
		return $single ? $v : array( $v );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, $value ): bool {
		global $_wp_test_post_meta;
		$_wp_test_post_meta[ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'register_post_meta' ) ) {
	function register_post_meta( string $post_type, string $meta_key, array $args ): bool {
		// No-op for tests.
		return true;
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args, string $output = 'names' ): array {
		global $_wp_test_post_types;
		// Our stub returns the configured list; the args (public, show_in_rest)
		// would normally filter — we trust the stub set the right list.
		return $_wp_test_post_types;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, $object_id = 0 ): bool {
		global $_wp_test_caps;
		if ( $cap === 'edit_post' ) {
			return ! empty( $_wp_test_caps[ (int) $object_id ] );
		}
		return false;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action ): bool {
		global $_wp_test_nonce_ok;
		return (bool) $_wp_test_nonce_ok;
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( int $post_id ): bool {
		return false;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int $post_id ) {
		global $_wp_test_get_post_type;
		return $_wp_test_get_post_type[ $post_id ] ?? 'post';
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		$qs = http_build_query( $args );
		$sep = strpos( $url, '?' ) === false ? '?' : '&';
		return $url . $sep . $qs;
	}
}

// add_action / add_filter / add_meta_box / wp_enqueue_script — no-op stubs.
foreach ( array( 'add_action', 'add_filter', 'add_meta_box', 'wp_enqueue_script', 'wp_set_script_translations', 'wp_nonce_field' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function {$fn}() { return true; }" );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $s ): string { return $s; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $s, string $d = '' ): string { return $s; }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $s ): string { return $s; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $s, string $d = '' ): string { return $s; }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $s, string $d = '' ): void { echo $s; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $v ): string { return $v ? ' checked' : ''; }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $n, $d = '' ) { return $n === 1 ? $single : $plural; }
}

// Plugin constants used by the class.
if ( ! defined( 'DEF_CORE_PLUGIN_URL' ) ) {
	define( 'DEF_CORE_PLUGIN_URL', 'https://example.test/wp-content/plugins/def-core/' );
}

require_once __DIR__ . '/../includes/class-def-core-knowledge-exclusion.php';

// ────────────────────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
$assert = static function ( bool $cond, string $msg ) use ( &$pass, &$fail ): void {
	if ( $cond ) {
		$pass++;
		echo "  ✓ {$msg}\n";
	} else {
		$fail++;
		echo "  ✗ {$msg}\n";
	}
};

echo "Knowledge exclusion (v3.1.0)\n";

// 1. Meta key contract.
$assert(
	DEF_Core_Knowledge_Exclusion::META_KEY === '_def_exclude_from_ingestion',
	'META_KEY constant matches DEF backend contract'
);

// 2. Supported post types — attachment must be filtered out.
$types = DEF_Core_Knowledge_Exclusion::get_supported_post_types();
$assert(
	! in_array( 'attachment', $types, true ),
	'attachment is filtered out of supported post types'
);
$assert(
	in_array( 'post', $types, true ) && in_array( 'page', $types, true ) && in_array( 'product', $types, true ),
	'post / page / product are included in supported types'
);

// 3. is_excluded reads meta correctly.
$_wp_test_post_meta[ 8516 ] = array( '_def_exclude_from_ingestion' => true );
$assert(
	DEF_Core_Knowledge_Exclusion::is_excluded( 8516 ) === true,
	'is_excluded(8516) returns true when meta is set'
);
$assert(
	DEF_Core_Knowledge_Exclusion::is_excluded( 9999 ) === false,
	'is_excluded(9999) returns false when meta is unset'
);

// 4. List column add.
$cols    = array( 'cb' => '', 'title' => 'Title', 'date' => 'Date' );
$cols2   = DEF_Core_Knowledge_Exclusion::add_list_column( $cols );
$assert(
	isset( $cols2[ DEF_Core_Knowledge_Exclusion::COLUMN_KEY ] ),
	'add_list_column adds the DEF column'
);
$assert(
	isset( $cols2['title'] ) && isset( $cols2['date'] ),
	'add_list_column preserves existing columns'
);

// 5. Bulk actions add.
$actions  = array( 'trash' => 'Trash' );
$actions2 = DEF_Core_Knowledge_Exclusion::add_bulk_actions( $actions );
$assert(
	isset( $actions2[ DEF_Core_Knowledge_Exclusion::BULK_EXCLUDE ] ),
	'add_bulk_actions exposes Exclude option'
);
$assert(
	isset( $actions2[ DEF_Core_Knowledge_Exclusion::BULK_INCLUDE ] ),
	'add_bulk_actions exposes Include option'
);
$assert(
	isset( $actions2['trash'] ),
	'add_bulk_actions preserves existing actions'
);

// 6. handle_bulk_actions updates meta + counts.
$_wp_test_post_meta = array();
$_wp_test_caps      = array( 8516 => true, 8517 => true, 8518 => true );
$_wp_test_get_post_type = array( 8516 => 'product', 8517 => 'product', 8518 => 'product' );
$url                = DEF_Core_Knowledge_Exclusion::handle_bulk_actions(
	'https://example.test/wp-admin/edit.php?post_type=product',
	DEF_Core_Knowledge_Exclusion::BULK_EXCLUDE,
	array( 8516, 8517, 8518 )
);
$assert(
	$_wp_test_post_meta[ 8516 ]['_def_exclude_from_ingestion'] === true
	&& $_wp_test_post_meta[ 8517 ]['_def_exclude_from_ingestion'] === true
	&& $_wp_test_post_meta[ 8518 ]['_def_exclude_from_ingestion'] === true,
	'BULK_EXCLUDE flips meta to true for each capable post id'
);
$assert(
	str_contains( $url, 'def_core_bulk_count=3' ),
	'handle_bulk_actions returns redirect with count=3'
);

// 7. handle_bulk_actions skips posts without edit_post cap.
$_wp_test_post_meta = array();
$_wp_test_caps      = array( 8516 => true, 8517 => false, 8518 => true );
$_wp_test_get_post_type = array( 8516 => 'product', 8517 => 'product', 8518 => 'product' );
$url                = DEF_Core_Knowledge_Exclusion::handle_bulk_actions(
	'https://example.test',
	DEF_Core_Knowledge_Exclusion::BULK_EXCLUDE,
	array( 8516, 8517, 8518 )
);
$assert(
	! isset( $_wp_test_post_meta[ 8517 ] ),
	'handle_bulk_actions skips posts where current_user_can(edit_post) is false'
);
$assert(
	str_contains( $url, 'def_core_bulk_count=2' ),
	'handle_bulk_actions count reflects only authorized updates'
);

// 8. handle_bulk_actions skips posts on unsupported post types.
$_wp_test_post_meta = array();
$_wp_test_caps      = array( 8516 => true );
$_wp_test_get_post_type = array( 8516 => 'attachment' );
DEF_Core_Knowledge_Exclusion::handle_bulk_actions(
	'https://example.test',
	DEF_Core_Knowledge_Exclusion::BULK_EXCLUDE,
	array( 8516 )
);
$assert(
	! isset( $_wp_test_post_meta[ 8516 ] ),
	'handle_bulk_actions skips posts on unsupported post types (attachment)'
);

// 9. handle_bulk_actions ignores unrelated actions.
$url = DEF_Core_Knowledge_Exclusion::handle_bulk_actions(
	'https://example.test/foo',
	'trash',
	array( 8516 )
);
$assert(
	$url === 'https://example.test/foo',
	'handle_bulk_actions ignores unrelated actions (no redirect mutation)'
);

// 10. auth_can_edit_post delegates to current_user_can('edit_post', $id).
$_wp_test_caps = array( 8516 => true, 8517 => false );
$assert(
	DEF_Core_Knowledge_Exclusion::auth_can_edit_post( false, '_def_exclude_from_ingestion', 8516 ) === true,
	'auth_can_edit_post returns true when user can edit post'
);
$assert(
	DEF_Core_Knowledge_Exclusion::auth_can_edit_post( false, '_def_exclude_from_ingestion', 8517 ) === false,
	'auth_can_edit_post returns false when user cannot edit post'
);

// 11. save_post — happy path.
$_wp_test_post_meta = array();
$_wp_test_caps      = array( 8516 => true );
$_wp_test_get_post_type = array( 8516 => 'product' );
$_wp_test_nonce_ok  = true;
$_POST = array(
	'def_core_exclusion_nonce' => 'fake',
	'_def_exclude_from_ingestion' => '1',
);
DEF_Core_Knowledge_Exclusion::save_post( 8516 );
$assert(
	$_wp_test_post_meta[ 8516 ]['_def_exclude_from_ingestion'] === true,
	'save_post writes meta=true when checkbox=1, nonce valid, capability ok'
);

// 12. save_post — checkbox unchecked saves false.
$_wp_test_post_meta = array();
$_POST = array(
	'def_core_exclusion_nonce' => 'fake',
	// no '_def_exclude_from_ingestion' key — checkbox unchecked
);
DEF_Core_Knowledge_Exclusion::save_post( 8516 );
$assert(
	$_wp_test_post_meta[ 8516 ]['_def_exclude_from_ingestion'] === false,
	'save_post writes meta=false when checkbox absent (unchecked)'
);

// 13. save_post — bails when nonce missing.
$_wp_test_post_meta = array();
$_POST = array( '_def_exclude_from_ingestion' => '1' );
DEF_Core_Knowledge_Exclusion::save_post( 8516 );
$assert(
	! isset( $_wp_test_post_meta[ 8516 ] ),
	'save_post bails when nonce field missing'
);

// 14. save_post — bails when nonce invalid.
$_wp_test_post_meta = array();
$_wp_test_nonce_ok  = false;
$_POST = array(
	'def_core_exclusion_nonce' => 'bogus',
	'_def_exclude_from_ingestion' => '1',
);
DEF_Core_Knowledge_Exclusion::save_post( 8516 );
$assert(
	! isset( $_wp_test_post_meta[ 8516 ] ),
	'save_post bails when nonce invalid'
);
$_wp_test_nonce_ok = true; // restore

// 15. save_post — bails on autosave.
$_wp_test_post_meta = array();
if ( ! defined( 'DOING_AUTOSAVE' ) ) {
	define( 'DOING_AUTOSAVE', true );
}
$_POST = array(
	'def_core_exclusion_nonce' => 'fake',
	'_def_exclude_from_ingestion' => '1',
);
DEF_Core_Knowledge_Exclusion::save_post( 8516 );
$assert(
	! isset( $_wp_test_post_meta[ 8516 ] ),
	'save_post bails when DOING_AUTOSAVE is true'
);
// No way to undefine constants; subsequent save_post tests need a fresh PHP process.

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
