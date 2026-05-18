<?php
/**
 * Page Context Build Plan V1.1 Sub-PR C — PHP-side tests for
 * DEF_Core_Page_Context.
 *
 * Runs standalone (no WordPress bootstrap). Stubs the minimum WP +
 * WooCommerce surface area needed to drive each helper.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

// ─── Stubs ─────────────────────────────────────────────────────────────

// Fixture state — each test resets the parts it cares about.
$GLOBALS['fx'] = array(
	'is_front_page'           => false,
	'is_home'                 => false,
	'is_product'              => false,
	'is_shop'                 => false,
	'is_cart'                 => false,
	'is_checkout'             => false,
	'is_account_page'         => false,
	'is_search'               => false,
	'is_singular'             => false,
	'is_singular_post'        => false,
	'is_singular_page'        => false,
	'is_category'             => false,
	'is_tag'                  => false,
	'is_tax'                  => false,
	'is_product_category'     => false,
	'is_product_tag'          => false,
	'is_taxonomy_hierarchical'=> array(),  // taxonomy_name => bool
	'queried_object'          => null,
	'queried_object_id'       => 0,
	'post_type'               => null,
	'object_taxonomies'       => array(),  // post_type => list<string>
	'object_terms'            => array(),  // post_id => list<object>
	'taxonomies_public'       => array(),  // taxonomy_name => bool (default true)
	'ancestors'               => array(),  // (term_id, taxonomy) => list<int>
	'terms_by_id'             => array(),  // (term_id, taxonomy) => object
	'document_title'          => 'Document Title',
	'locale'                  => 'en_US',
);

function _fx_set( array $values ) {
	foreach ( $values as $k => $v ) {
		$GLOBALS['fx'][ $k ] = $v;
	}
}
function _fx_reset() {
	foreach ( array(
		'is_front_page', 'is_home', 'is_product', 'is_shop', 'is_cart',
		'is_checkout', 'is_account_page', 'is_search', 'is_singular',
		'is_singular_post', 'is_singular_page', 'is_category', 'is_tag',
		'is_tax', 'is_product_category', 'is_product_tag',
	) as $k ) {
		$GLOBALS['fx'][ $k ] = false;
	}
	$GLOBALS['fx']['is_taxonomy_hierarchical'] = array();
	$GLOBALS['fx']['queried_object']           = null;
	$GLOBALS['fx']['queried_object_id']        = 0;
	$GLOBALS['fx']['post_type']                = null;
	$GLOBALS['fx']['object_taxonomies']        = array();
	$GLOBALS['fx']['object_terms']             = array();
	$GLOBALS['fx']['taxonomies_public']        = array();
	$GLOBALS['fx']['ancestors']                = array();
	$GLOBALS['fx']['terms_by_id']              = array();
	$GLOBALS['fx']['locale']                   = 'en_US';
	$GLOBALS['fx']['document_title']           = 'Document Title';
}

// WP function stubs the page-context class depends on.
if ( ! function_exists( 'is_front_page' ) )       { function is_front_page()       { return $GLOBALS['fx']['is_front_page']; } }
if ( ! function_exists( 'is_home' ) )             { function is_home()             { return $GLOBALS['fx']['is_home']; } }
if ( ! function_exists( 'is_product' ) )          { function is_product()          { return $GLOBALS['fx']['is_product']; } }
if ( ! function_exists( 'is_shop' ) )             { function is_shop()             { return $GLOBALS['fx']['is_shop']; } }
if ( ! function_exists( 'is_cart' ) )             { function is_cart()             { return $GLOBALS['fx']['is_cart']; } }
if ( ! function_exists( 'is_checkout' ) )         { function is_checkout()         { return $GLOBALS['fx']['is_checkout']; } }
if ( ! function_exists( 'is_account_page' ) )     { function is_account_page()     { return $GLOBALS['fx']['is_account_page']; } }
if ( ! function_exists( 'is_search' ) )           { function is_search()           { return $GLOBALS['fx']['is_search']; } }
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $post_types = '' ) {
		if ( $post_types === 'post' ) return $GLOBALS['fx']['is_singular_post'];
		if ( $post_types === 'page' ) return $GLOBALS['fx']['is_singular_page'];
		return $GLOBALS['fx']['is_singular'];
	}
}
if ( ! function_exists( 'is_category' ) )         { function is_category()         { return $GLOBALS['fx']['is_category']; } }
if ( ! function_exists( 'is_tag' ) )              { function is_tag()              { return $GLOBALS['fx']['is_tag']; } }
if ( ! function_exists( 'is_tax' ) )              { function is_tax()              { return $GLOBALS['fx']['is_tax']; } }
if ( ! function_exists( 'is_product_category' ) ) { function is_product_category() { return $GLOBALS['fx']['is_product_category']; } }
if ( ! function_exists( 'is_product_tag' ) )      { function is_product_tag()      { return $GLOBALS['fx']['is_product_tag']; } }
if ( ! function_exists( 'is_taxonomy_hierarchical' ) ) {
	function is_taxonomy_hierarchical( $taxonomy ) {
		return ! empty( $GLOBALS['fx']['is_taxonomy_hierarchical'][ $taxonomy ] );
	}
}
if ( ! function_exists( 'get_queried_object' ) )    { function get_queried_object()    { return $GLOBALS['fx']['queried_object']; } }
if ( ! function_exists( 'get_queried_object_id' ) ) { function get_queried_object_id() { return $GLOBALS['fx']['queried_object_id']; } }
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id = null ) { return $GLOBALS['fx']['post_type']; }
}
if ( ! function_exists( 'get_object_taxonomies' ) ) {
	function get_object_taxonomies( $post_type ) {
		return $GLOBALS['fx']['object_taxonomies'][ $post_type ] ?? array();
	}
}
if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( $post_id, $taxonomies ) {
		$out = array();
		foreach ( $GLOBALS['fx']['object_terms'][ $post_id ] ?? array() as $term ) {
			$tax_list = is_array( $taxonomies ) ? $taxonomies : array( $taxonomies );
			if ( in_array( $term->taxonomy, $tax_list, true ) ) {
				$out[] = $term;
			}
		}
		return $out;
	}
}
if ( ! function_exists( 'get_taxonomy' ) ) {
	function get_taxonomy( $tax_name ) {
		$is_public = $GLOBALS['fx']['taxonomies_public'][ $tax_name ] ?? true;
		return (object) array( 'name' => $tax_name, 'public' => $is_public );
	}
}
if ( ! function_exists( 'get_ancestors' ) ) {
	function get_ancestors( $term_id, $taxonomy ) {
		return $GLOBALS['fx']['ancestors'][ $term_id . '|' . $taxonomy ] ?? array();
	}
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy ) {
		return $GLOBALS['fx']['terms_by_id'][ $term_id . '|' . $taxonomy ] ?? null;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return is_object( $thing ) && isset( $thing->errors );
	}
}
if ( ! function_exists( 'wp_get_document_title' ) ) {
	function wp_get_document_title() { return $GLOBALS['fx']['document_title']; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $what = 'name' ) { return 'Test Site'; }
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() { return $GLOBALS['fx']['locale']; }
}
if ( ! function_exists( 'determine_locale' ) ) {
	function determine_locale() { return $GLOBALS['fx']['locale']; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		if ( isset( $GLOBALS['fx_filters'][ $tag ] ) ) {
			return $GLOBALS['fx_filters'][ $tag ];
		}
		return $value;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return preg_replace( '/<[^>]*>/', '', (string) $s ); }
}

// Load the class under test.
require_once __DIR__ . '/../includes/class-def-core-page-context.php';

// ─── Test runner ───────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$errors = array();

function assert_test( bool $cond, string $name ): void {
	global $passed, $failed, $errors;
	if ( $cond ) {
		$passed++;
		echo "  ✓ {$name}\n";
	} else {
		$failed++;
		$errors[] = $name;
		echo "  ✗ FAILED: {$name}\n";
	}
}

echo "=== DEF_Core_Page_Context Tests ===\n\n";

// ─── detect_page_type ─────────────────────────────────────────────────
echo "detect_page_type:\n";

_fx_reset();
_fx_set( array( 'is_front_page' => true ) );
assert_test( DEF_Core_Page_Context::detect_page_type() === 'home', 'front page → home' );

_fx_reset();
_fx_set( array( 'is_product' => true ) );
assert_test( DEF_Core_Page_Context::detect_page_type() === 'product', 'WC product → product' );

_fx_reset();
_fx_set( array( 'is_cart' => true ) );
assert_test( DEF_Core_Page_Context::detect_page_type() === 'cart', 'cart → cart' );

_fx_reset();
_fx_set( array( 'is_checkout' => true ) );
assert_test( DEF_Core_Page_Context::detect_page_type() === 'checkout', 'checkout → checkout' );

_fx_reset();
_fx_set( array( 'is_account_page' => true ) );
assert_test( DEF_Core_Page_Context::detect_page_type() === 'account', 'account → account' );

_fx_reset();
_fx_set( array( 'is_singular' => true, 'is_singular_post' => true ) );
assert_test( DEF_Core_Page_Context::detect_page_type() === 'post', 'single post → post' );

_fx_reset();
_fx_set( array( 'is_singular' => true, 'is_singular_page' => true ) );
assert_test( DEF_Core_Page_Context::detect_page_type() === 'page', 'single page → page' );

_fx_reset();
_fx_set( array( 'is_product_category' => true ) );
assert_test( DEF_Core_Page_Context::detect_page_type() === 'taxonomy_archive', 'WC product category → taxonomy_archive' );

_fx_reset();
_fx_set( array( 'is_category' => true ) );
assert_test( DEF_Core_Page_Context::detect_page_type() === 'taxonomy_archive', 'WP post category → taxonomy_archive' );

_fx_reset();
_fx_set( array( 'is_tag' => true ) );
assert_test( DEF_Core_Page_Context::detect_page_type() === 'taxonomy_archive', 'WP post tag → taxonomy_archive' );

_fx_reset();
_fx_set( array( 'is_tax' => true ) );
assert_test( DEF_Core_Page_Context::detect_page_type() === 'taxonomy_archive', 'custom tax archive → taxonomy_archive' );

_fx_reset();
_fx_set( array( 'is_singular' => true ) );
assert_test( DEF_Core_Page_Context::detect_page_type() === 'other_singular', 'unknown CPT → other_singular' );

_fx_reset();
assert_test( DEF_Core_Page_Context::detect_page_type() === 'other', 'unrecognised page → other' );

// ─── detect_language ─────────────────────────────────────────────────
echo "\ndetect_language:\n";

_fx_reset();
_fx_set( array( 'locale' => 'en_US' ) );
assert_test( DEF_Core_Page_Context::detect_language() === 'en', 'en_US → en' );

_fx_set( array( 'locale' => 'de_DE' ) );
assert_test( DEF_Core_Page_Context::detect_language() === 'de', 'de_DE → de' );

_fx_set( array( 'locale' => 'zh_CN' ) );
assert_test( DEF_Core_Page_Context::detect_language() === 'zh-Hans', 'zh_CN → zh-Hans' );

_fx_set( array( 'locale' => 'zh_TW' ) );
assert_test( DEF_Core_Page_Context::detect_language() === 'zh-Hant', 'zh_TW → zh-Hant' );

// Filter hook
$GLOBALS['fx_filters']['def_core_detected_language'] = 'fr';
assert_test( DEF_Core_Page_Context::detect_language() === 'fr', 'def_core_detected_language filter overrides fallback' );
unset( $GLOBALS['fx_filters']['def_core_detected_language'] );

// ─── collect_queried_taxonomy ────────────────────────────────────────
echo "\ncollect_queried_taxonomy:\n";

_fx_reset();
assert_test( DEF_Core_Page_Context::collect_queried_taxonomy() === null, 'non-archive page → null' );

_fx_reset();
_fx_set( array(
	'is_product_category' => true,
	'queried_object'      => (object) array( 'taxonomy' => 'product_cat', 'term_id' => 89, 'slug' => 'search-plugins', 'name' => 'Search Plugins' ),
	'queried_object_id'   => 89,
	'is_taxonomy_hierarchical' => array( 'product_cat' => true ),
) );
$qt = DEF_Core_Page_Context::collect_queried_taxonomy();
assert_test( is_array( $qt ) && $qt['taxonomy'] === 'product_cat' && $qt['term_id'] === 89, 'product_cat archive → identity fields populated' );
assert_test( $qt['hierarchy'] === array( 'Search Plugins' ), 'hierarchy contains the queried term name' );

// Hierarchical with ancestors. WP `get_ancestors` returns ancestors
// CLOSEST-first (direct parent → grandparent → great-grandparent ...).
// For the tree `Mens > Shirts > Long Sleeve`, leaf term_id=92 has
// direct parent term_id=12 (Shirts) and grandparent term_id=5 (Mens),
// so the ancestor list is [12, 5]. The helper reverses to [5, 12]
// then appends the leaf — yielding root→leaf order.
_fx_reset();
_fx_set( array(
	'is_product_category' => true,
	'queried_object'      => (object) array( 'taxonomy' => 'product_cat', 'term_id' => 92, 'slug' => 'long-sleeve', 'name' => 'Long Sleeve' ),
	'is_taxonomy_hierarchical' => array( 'product_cat' => true ),
	'ancestors'           => array( '92|product_cat' => array( 12, 5 ) ),
	'terms_by_id'         => array(
		'5|product_cat'  => (object) array( 'name' => 'Mens' ),
		'12|product_cat' => (object) array( 'name' => 'Shirts' ),
	),
) );
$qt = DEF_Core_Page_Context::collect_queried_taxonomy();
assert_test( $qt['hierarchy'] === array( 'Mens', 'Shirts', 'Long Sleeve' ), 'hierarchy is root→leaf' );

// Non-hierarchical taxonomy → empty hierarchy.
_fx_reset();
_fx_set( array(
	'is_product_tag'           => true,
	'is_tag'                   => true,
	'queried_object'           => (object) array( 'taxonomy' => 'product_tag', 'term_id' => 145, 'slug' => 'ajax', 'name' => 'AJAX' ),
	'is_taxonomy_hierarchical' => array( 'product_tag' => false ),
) );
$qt = DEF_Core_Page_Context::collect_queried_taxonomy();
assert_test( $qt['hierarchy'] === array(), 'non-hierarchical tag → empty hierarchy' );

// ─── collect_page_terms ──────────────────────────────────────────────
echo "\ncollect_page_terms:\n";

_fx_reset();
_fx_set( array(
	'is_product'         => true,
	'queried_object_id'  => 1234,
	'post_type'          => 'product',
	'object_taxonomies'  => array( 'product' => array( 'product_cat', 'product_tag' ) ),
	'object_terms'       => array(
		1234 => array(
			(object) array( 'taxonomy' => 'product_cat', 'term_id' => 89, 'slug' => 'search-plugins', 'name' => 'Search Plugins' ),
			(object) array( 'taxonomy' => 'product_tag', 'term_id' => 145, 'slug' => 'ajax', 'name' => 'AJAX' ),
		),
	),
) );
$terms = DEF_Core_Page_Context::collect_page_terms( 1234 );
assert_test( count( $terms ) === 2, 'product with 2 taxonomies → 2 terms' );
assert_test( $terms[0]['taxonomy'] === 'product_cat' && $terms[0]['term_id'] === 89, 'first entry is product_cat with right term_id' );

// pa_* excluded by default
_fx_reset();
_fx_set( array(
	'is_product'        => true,
	'queried_object_id' => 1234,
	'post_type'         => 'product',
	'object_taxonomies' => array( 'product' => array( 'product_cat', 'pa_color', 'pa_size' ) ),
	'object_terms'      => array(
		1234 => array(
			(object) array( 'taxonomy' => 'product_cat', 'term_id' => 89, 'slug' => 'shirts', 'name' => 'Shirts' ),
			(object) array( 'taxonomy' => 'pa_color',    'term_id' => 700, 'slug' => 'blue',  'name' => 'Blue' ),
			(object) array( 'taxonomy' => 'pa_size',     'term_id' => 701, 'slug' => 'xl',    'name' => 'XL' ),
		),
	),
) );
$terms = DEF_Core_Page_Context::collect_page_terms( 1234 );
$tax_names = array_column( $terms, 'taxonomy' );
assert_test( in_array( 'product_cat', $tax_names, true ), 'product_cat included' );
assert_test( ! in_array( 'pa_color', $tax_names, true ), 'pa_color excluded by default' );
assert_test( ! in_array( 'pa_size',  $tax_names, true ), 'pa_size excluded by default' );

// pa_brand allowlisted via filter
$GLOBALS['fx_filters']['def_core_page_context_allowed_attribute_taxonomies'] = array( 'pa_brand' );
_fx_set( array(
	'object_taxonomies' => array( 'product' => array( 'product_cat', 'pa_brand', 'pa_color' ) ),
	'object_terms'      => array(
		1234 => array(
			(object) array( 'taxonomy' => 'product_cat', 'term_id' => 89,  'slug' => 'shirts', 'name' => 'Shirts' ),
			(object) array( 'taxonomy' => 'pa_brand',    'term_id' => 800, 'slug' => 'acme',   'name' => 'Acme' ),
			(object) array( 'taxonomy' => 'pa_color',    'term_id' => 700, 'slug' => 'blue',   'name' => 'Blue' ),
		),
	),
) );
$terms = DEF_Core_Page_Context::collect_page_terms( 1234 );
$tax_names = array_column( $terms, 'taxonomy' );
assert_test( in_array( 'pa_brand', $tax_names, true ), 'pa_brand re-enabled via allowlist filter' );
assert_test( ! in_array( 'pa_color', $tax_names, true ), 'pa_color still excluded (not in allowlist)' );
unset( $GLOBALS['fx_filters']['def_core_page_context_allowed_attribute_taxonomies'] );

// Private taxonomy excluded
_fx_reset();
_fx_set( array(
	'is_product'        => true,
	'queried_object_id' => 1234,
	'post_type'         => 'product',
	'object_taxonomies' => array( 'product' => array( 'product_cat', 'internal-tag' ) ),
	'object_terms'      => array(
		1234 => array(
			(object) array( 'taxonomy' => 'product_cat',  'term_id' => 89,  'slug' => 'shirts', 'name' => 'Shirts' ),
			(object) array( 'taxonomy' => 'internal-tag', 'term_id' => 999, 'slug' => 'staff-only', 'name' => 'Staff Only' ),
		),
	),
	'taxonomies_public' => array( 'internal-tag' => false ),
) );
$terms = DEF_Core_Page_Context::collect_page_terms( 1234 );
$tax_names = array_column( $terms, 'taxonomy' );
assert_test( in_array( 'product_cat', $tax_names, true ), 'public product_cat kept' );
assert_test( ! in_array( 'internal-tag', $tax_names, true ), 'private internal-tag excluded' );

// 10-cap
_fx_reset();
$many_terms = array();
for ( $i = 0; $i < 15; $i++ ) {
	$many_terms[] = (object) array( 'taxonomy' => 'product_cat', 'term_id' => $i, 'slug' => "t-{$i}", 'name' => "T{$i}" );
}
_fx_set( array(
	'is_product'        => true,
	'queried_object_id' => 7,
	'post_type'         => 'product',
	'object_taxonomies' => array( 'product' => array( 'product_cat' ) ),
	'object_terms'      => array( 7 => $many_terms ),
) );
assert_test( count( DEF_Core_Page_Context::collect_page_terms( 7 ) ) === 10, '15 terms → capped at 10' );

// Empty post id
_fx_reset();
assert_test( DEF_Core_Page_Context::collect_page_terms( 0 ) === array(), 'post_id=0 → empty array' );

// Non-singular page returns empty
_fx_reset();
_fx_set( array(
	'is_product_category' => true,
	'queried_object_id'   => 89,
	'post_type'           => 'product',
	'object_taxonomies'   => array( 'product' => array( 'product_cat' ) ),
	'object_terms'        => array( 89 => array(
		(object) array( 'taxonomy' => 'product_cat', 'term_id' => 1, 'slug' => 'x', 'name' => 'X' ),
	) ),
) );
assert_test( DEF_Core_Page_Context::collect_page_terms( 89 ) === array(), 'taxonomy archive → no per-page terms (defensive)' );

// ─── safe_title ──────────────────────────────────────────────────────
echo "\nsafe_title:\n";
_fx_reset();
_fx_set( array( 'document_title' => 'Hello' ) );
assert_test( DEF_Core_Page_Context::safe_title() === 'Hello', 'plain title preserved' );

_fx_set( array( 'document_title' => '<script>alert(1)</script>Real' ) );
assert_test( DEF_Core_Page_Context::safe_title() === 'alert(1)Real', 'HTML tags stripped' );

_fx_set( array( 'document_title' => "Hello\x00World" ) );
assert_test( DEF_Core_Page_Context::safe_title() === 'HelloWorld', 'control chars stripped' );

_fx_set( array( 'document_title' => str_repeat( 'a', 250 ) ) );
assert_test( strlen( DEF_Core_Page_Context::safe_title() ) === 200, 'title capped at 200 chars' );

// ─── normalise_path ──────────────────────────────────────────────────
echo "\nnormalise_path:\n";
assert_test( DEF_Core_Page_Context::normalise_path( '/' ) === '/', 'root preserved' );
assert_test( DEF_Core_Page_Context::normalise_path( '/foo' ) === '/foo', 'no trailing slash → unchanged' );
assert_test( DEF_Core_Page_Context::normalise_path( '/foo/' ) === '/foo', 'trailing slash trimmed' );
assert_test( DEF_Core_Page_Context::normalise_path( '/foo//' ) === '/foo', 'multiple trailing slashes trimmed' );

// ─── build_payload (end-to-end) ──────────────────────────────────────
echo "\nbuild_payload:\n";
_fx_reset();
_fx_set( array(
	'is_product'        => true,
	'queried_object_id' => 1234,
	'post_type'         => 'product',
	'document_title'    => 'Predictive Search Pro',
	'object_taxonomies' => array( 'product' => array( 'product_cat' ) ),
	'object_terms'      => array(
		1234 => array(
			(object) array( 'taxonomy' => 'product_cat', 'term_id' => 89, 'slug' => 'search-plugins', 'name' => 'Search Plugins' ),
		),
	),
) );
$payload = DEF_Core_Page_Context::build_payload();
assert_test( $payload['page_type'] === 'product', 'payload.page_type populated' );
assert_test( $payload['product_id'] === 1234, 'payload.product_id populated for product page' );
assert_test( $payload['page_id'] === 1234, 'payload.page_id populated' );
assert_test( $payload['language_code'] === 'en', 'payload.language_code defaults to en' );
assert_test( $payload['title'] === 'Predictive Search Pro', 'payload.title populated' );
assert_test( count( $payload['terms'] ) === 1, 'payload.terms populated from collect_page_terms' );
assert_test( ! array_key_exists( 'canonical_path', $payload ), 'canonical_path NOT in PHP payload (JS overrides at submit time)' );

// ─── Summary ─────────────────────────────────────────────────────────
echo "\n=== Summary ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

if ( $failed > 0 ) {
	echo "\nFailures:\n";
	foreach ( $errors as $name ) {
		echo "  - {$name}\n";
	}
	exit( 1 );
}

exit( 0 );
