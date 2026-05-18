<?php
/**
 * Export taxonomy emission tests.
 *
 * Verifies the new `taxonomies` field on the content/products export endpoints:
 * - Posts with category + post_tag → both taxonomies enumerated
 * - Products with product_cat + product_tag → both enumerated
 * - Custom CPT with custom taxonomy → custom taxonomy appears
 * - WC attribute taxonomies (pa_*) → INCLUDED (search index benefits)
 * - Posts with no taxonomies → empty array
 * - Term tuple shape: {taxonomy, term_id, slug, name}
 * - Backward-compat: existing `categories`/`tags` fields still populated
 *
 * Runs standalone with WP stubs (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

// ── Stubs specific to this test ────────────────────────────────────────

// Per-post fixture: post_id => [ post_type, taxonomies_map ].
// taxonomies_map: taxonomy_name => list of term objects.
$GLOBALS['fixture_posts'] = array();

// Per-taxonomy fixture: taxonomy_name => bool public. Tests can flip
// individual taxonomies to public=false to verify the public-only filter.
$GLOBALS['fixture_taxonomies_public'] = array();

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int $post_id ) {
		$f = $GLOBALS['fixture_posts'][ $post_id ] ?? null;
		return $f ? $f['post_type'] : false;
	}
}

if ( ! function_exists( 'get_object_taxonomies' ) ) {
	function get_object_taxonomies( string $post_type ): array {
		// Mirror WP behaviour: return the taxonomy names registered for the
		// post type. For the fixture we infer from any post that has this type.
		$taxonomies = array();
		foreach ( $GLOBALS['fixture_posts'] as $p ) {
			if ( $p['post_type'] !== $post_type ) {
				continue;
			}
			foreach ( array_keys( $p['taxonomies'] ) as $tax ) {
				$taxonomies[ $tax ] = $tax;
			}
		}
		return array_values( $taxonomies );
	}
}

if ( ! function_exists( 'get_taxonomy' ) ) {
	function get_taxonomy( string $tax_name ) {
		// Default to public=true (matches WP's default for non-internal taxonomies)
		// unless the test fixture overrides it.
		$is_public = $GLOBALS['fixture_taxonomies_public'][ $tax_name ] ?? true;
		return (object) array(
			'name'   => $tax_name,
			'public' => $is_public,
		);
	}
}

if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( int $post_id, $taxonomies ) {
		// Sentinel post_id to exercise the is_wp_error() branch.
		if ( 8800 === $post_id ) {
			return (object) array( 'errors' => array( 'invalid_taxonomy' => array( 'fixture' ) ) );
		}
		$f = $GLOBALS['fixture_posts'][ $post_id ] ?? null;
		if ( ! $f ) {
			return array();
		}
		$want = is_array( $taxonomies ) ? $taxonomies : array( $taxonomies );
		$out = array();
		foreach ( $want as $tax ) {
			foreach ( $f['taxonomies'][ $tax ] ?? array() as $term ) {
				$out[] = (object) $term;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return is_object( $thing ) && property_exists( $thing, 'errors' );
	}
}

// Load the class under test.
require_once __DIR__ . '/../includes/class-def-core-export.php';

// ── Test Runner ────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$errors = array();

function assert_test( bool $condition, string $name ): void {
	global $passed, $failed, $errors;
	if ( $condition ) {
		$passed++;
		echo "  ✓ {$name}\n";
	} else {
		$failed++;
		$errors[] = $name;
		echo "  ✗ FAILED: {$name}\n";
	}
}

// Reflect the private helper for direct test access.
$reflection = new ReflectionClass( 'DEF_Core_Export' );
$collect    = $reflection->getMethod( 'collect_taxonomy_terms' );
$collect->setAccessible( true );

echo "=== Export Taxonomies Tests ===\n\n";

// ── Test 1: WC product with product_cat + product_tag ──────────────────
echo "Product with product_cat + product_tag:\n";
$GLOBALS['fixture_posts'] = array(
	100 => array(
		'post_type'  => 'product',
		'taxonomies' => array(
			'product_cat' => array(
				array( 'taxonomy' => 'product_cat', 'term_id' => 89, 'slug' => 'search-plugins', 'name' => 'Search Plugins' ),
				array( 'taxonomy' => 'product_cat', 'term_id' => 92, 'slug' => 'woocommerce-plugins', 'name' => 'WooCommerce Plugins' ),
			),
			'product_tag' => array(
				array( 'taxonomy' => 'product_tag', 'term_id' => 145, 'slug' => 'ajax', 'name' => 'AJAX' ),
			),
		),
	),
);
$out = $collect->invoke( null, 100 );
assert_test( count( $out ) === 3, 'product → 3 terms across product_cat + product_tag' );
assert_test( $out[0]['taxonomy'] === 'product_cat' && $out[0]['term_id'] === 89 && $out[0]['slug'] === 'search-plugins' && $out[0]['name'] === 'Search Plugins', 'first entry has full tuple shape' );
assert_test( $out[2]['taxonomy'] === 'product_tag' && $out[2]['term_id'] === 145, 'tag entry has correct taxonomy and term_id' );

// ── Test 2: Blog post with category + post_tag ─────────────────────────
echo "\nPost with category + post_tag:\n";
$GLOBALS['fixture_posts'] = array(
	200 => array(
		'post_type'  => 'post',
		'taxonomies' => array(
			'category' => array(
				array( 'taxonomy' => 'category', 'term_id' => 1, 'slug' => 'uncategorized', 'name' => 'Uncategorized' ),
				array( 'taxonomy' => 'category', 'term_id' => 12, 'slug' => 'tutorials', 'name' => 'Tutorials' ),
			),
			'post_tag' => array(
				array( 'taxonomy' => 'post_tag', 'term_id' => 50, 'slug' => 'woocommerce', 'name' => 'WooCommerce' ),
			),
		),
	),
);
$out = $collect->invoke( null, 200 );
assert_test( count( $out ) === 3, 'post → 3 terms across category + post_tag' );
assert_test( $out[0]['taxonomy'] === 'category', 'first entry is category taxonomy' );
assert_test( $out[2]['taxonomy'] === 'post_tag' && $out[2]['name'] === 'WooCommerce', 'post_tag entry has correct name' );

// ── Test 3: Custom CPT with custom taxonomy ────────────────────────────
echo "\nCustom CPT with custom taxonomy:\n";
$GLOBALS['fixture_posts'] = array(
	300 => array(
		'post_type'  => 'service',
		'taxonomies' => array(
			'service-type' => array(
				array( 'taxonomy' => 'service-type', 'term_id' => 500, 'slug' => 'implementation', 'name' => 'Implementation' ),
			),
		),
	),
);
$out = $collect->invoke( null, 300 );
assert_test( count( $out ) === 1, 'custom CPT → 1 term in custom taxonomy' );
assert_test( $out[0]['taxonomy'] === 'service-type' && $out[0]['term_id'] === 500, 'custom taxonomy emitted with correct identity' );

// ── Test 4: WC attribute taxonomies (pa_*) ARE included ────────────────
// Sub-PR 0.1.a emits everything; pa_* exclusion is Sub-PR C's concern for
// Joe's prompt context, not the search index.
echo "\nProduct with pa_* attribute taxonomies (included for index):\n";
$GLOBALS['fixture_posts'] = array(
	400 => array(
		'post_type'  => 'product',
		'taxonomies' => array(
			'product_cat' => array(
				array( 'taxonomy' => 'product_cat', 'term_id' => 89, 'slug' => 'shirts', 'name' => 'Shirts' ),
			),
			'pa_color' => array(
				array( 'taxonomy' => 'pa_color', 'term_id' => 700, 'slug' => 'blue', 'name' => 'Blue' ),
			),
			'pa_size' => array(
				array( 'taxonomy' => 'pa_size', 'term_id' => 701, 'slug' => 'xl', 'name' => 'XL' ),
			),
		),
	),
);
$out = $collect->invoke( null, 400 );
assert_test( count( $out ) === 3, 'pa_* taxonomies INCLUDED in indexer payload (excluded only in prompt context)' );
$tax_names = array_column( $out, 'taxonomy' );
assert_test( in_array( 'pa_color', $tax_names, true ) && in_array( 'pa_size', $tax_names, true ), 'pa_color and pa_size both present' );

// ── Test 5: Post with no taxonomies attached ───────────────────────────
echo "\nPost with no taxonomies:\n";
$GLOBALS['fixture_posts'] = array(
	500 => array(
		'post_type'  => 'page',
		'taxonomies' => array(),
	),
);
$out = $collect->invoke( null, 500 );
assert_test( $out === array(), 'page with no taxonomies → empty array' );

// ── Test 6: Unknown post ID → empty array ──────────────────────────────
echo "\nUnknown post ID:\n";
$GLOBALS['fixture_posts'] = array();
$out = $collect->invoke( null, 9999 );
assert_test( $out === array(), 'unknown post_id → empty array (no errors)' );

// ── Test 7a: Private taxonomy EXCLUDED (post-review hardening) ─────────
// Pre-merge fix per converged code + security review on PR #177.
// Admin-internal taxonomies (public=false) must NOT flow to DEF index.
echo "\nPrivate (public=false) taxonomy excluded:\n";
$GLOBALS['fixture_posts'] = array(
	700 => array(
		'post_type'  => 'product',
		'taxonomies' => array(
			'product_cat' => array(
				array( 'taxonomy' => 'product_cat', 'term_id' => 89, 'slug' => 'shirts', 'name' => 'Shirts' ),
			),
			'internal-tag' => array(
				array( 'taxonomy' => 'internal-tag', 'term_id' => 999, 'slug' => 'do-not-quote', 'name' => 'Do Not Quote' ),
			),
		),
	),
);
// Mark internal-tag as private; product_cat defaults to public=true.
$GLOBALS['fixture_taxonomies_public'] = array(
	'internal-tag' => false,
);
$out = $collect->invoke( null, 700 );
assert_test( count( $out ) === 1, 'private taxonomy filtered out → only 1 term remains' );
$tax_names = array_column( $out, 'taxonomy' );
assert_test( in_array( 'product_cat', $tax_names, true ) && ! in_array( 'internal-tag', $tax_names, true ), 'product_cat kept, internal-tag dropped' );
// Reset fixture for subsequent tests.
$GLOBALS['fixture_taxonomies_public'] = array();

// ── Test 7b: wp_get_object_terms returning WP_Error → empty array ──────
// Closes Code Review #5 gap. The is_wp_error() branch should silently
// return an empty array, not crash or leak the WP_Error object.
// post_id 8800 is a sentinel in the wp_get_object_terms stub.
echo "\nWP_Error from wp_get_object_terms → empty array:\n";
$GLOBALS['fixture_posts'] = array(
	8800 => array(
		'post_type'  => 'product',
		'taxonomies' => array(
			'product_cat' => array(
				// Term content doesn't matter — stub returns WP_Error for post_id 8800.
				array( 'taxonomy' => 'product_cat', 'term_id' => 1, 'slug' => 'x', 'name' => 'X' ),
			),
		),
	),
);
$out = $collect->invoke( null, 8800 );
assert_test( $out === array(), 'WP_Error response → empty array (no crash, no leak)' );

// ── Test 8: term_id coerced to int ─────────────────────────────────────
echo "\nterm_id type coercion:\n";
$GLOBALS['fixture_posts'] = array(
	600 => array(
		'post_type'  => 'product',
		'taxonomies' => array(
			'product_cat' => array(
				// WP sometimes returns term_id as string from the DB layer.
				array( 'taxonomy' => 'product_cat', 'term_id' => '89', 'slug' => 'shirts', 'name' => 'Shirts' ),
			),
		),
	),
);
$out = $collect->invoke( null, 600 );
assert_test( $out[0]['term_id'] === 89 && is_int( $out[0]['term_id'] ), 'string term_id from DB coerced to int' );

// ── Summary ────────────────────────────────────────────────────────────
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
