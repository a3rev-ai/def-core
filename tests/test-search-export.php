<?php
/**
 * DEF Core Search Export tests.
 *
 * Covers the search-index export helpers + the taxonomy-term export path:
 * - to_float(): numeric coercion (null for empty/non-numeric, keeps 0.0).
 * - focus_keywords(): Yoast + legacy AIOSEO postmeta, deduped.
 * - export_terms(): term-row shape {object_type, source_id, title, permalink,
 *   object_count} + pagination (orphan term count=0 included).
 *
 * The product/item path mirrors DEF_Core_Export (proven) and relies on
 * WooCommerce; it's covered by integration rather than heavy WC stubbing here.
 *
 * Runs standalone with WP stubs:  php tests/test-search-export.php
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

// ── Stubs ──────────────────────────────────────────────────────────────

$GLOBALS['fixture_post_meta'] = array();   // post_id => [ meta_key => value ]
$GLOBALS['fixture_terms']     = array();   // taxonomy => list of term objects

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		return $GLOBALS['fixture_post_meta'][ $post_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'wp_count_terms' ) ) {
	function wp_count_terms( array $args ): int {
		return count( $GLOBALS['fixture_terms'][ $args['taxonomy'] ] ?? array() );
	}
}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( array $args ) {
		$all = $GLOBALS['fixture_terms'][ $args['taxonomy'] ] ?? array();
		return array_slice( $all, $args['offset'] ?? 0, $args['number'] ?? count( $all ) );
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		return 'https://example.test/t/' . $term->slug . '/';
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url() {
		return 'https://example.test';
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return is_object( $thing ) && property_exists( $thing, 'errors' );
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public int $status;
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_data() {
			return $this->data;
		}
	}
}

require_once __DIR__ . '/../includes/class-def-core-export.php';
require_once __DIR__ . '/../includes/class-def-core-search-export.php';

// ── Test runner ──────────────────────────────────────────────────────────

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

$ref = new ReflectionClass( 'DEF_Core_Search_Export' );
function _method( ReflectionClass $ref, string $name ): ReflectionMethod {
	$m = $ref->getMethod( $name );
	$m->setAccessible( true );
	return $m;
}
$to_float     = _method( $ref, 'to_float' );
$focus        = _method( $ref, 'focus_keywords' );
$export_terms = _method( $ref, 'export_terms' );

echo "=== Search Export Tests ===\n\n";

echo "to_float():\n";
assert_test( 12.5 === $to_float->invoke( null, '12.50' ), 'numeric string -> float' );
assert_test( null === $to_float->invoke( null, '' ), 'empty -> null' );
assert_test( null === $to_float->invoke( null, 'abc' ), 'non-numeric -> null' );
assert_test( 0.0 === $to_float->invoke( null, '0' ), 'zero kept (not dropped)' );

echo "\nfocus_keywords():\n";
$GLOBALS['fixture_post_meta'][101] = array(
	'_yoast_wpseo_focuskw' => 'fuel filter',
	'_aioseop_keywords'    => 'kubota, fuel filter, L26Y',
);
$kw = $focus->invoke( null, 101 );
assert_test( in_array( 'fuel filter', $kw, true ), 'Yoast focuskw extracted' );
assert_test( in_array( 'kubota', $kw, true ) && in_array( 'L26Y', $kw, true ), 'AIOSEO split + extracted' );
assert_test( 1 === count( array_keys( $kw, 'fuel filter', true ) ), 'duplicate keyword deduped' );
assert_test( array() === $focus->invoke( null, 999 ), 'no SEO meta -> empty' );

echo "\nexport_terms(product_cat) -> term rows:\n";
$GLOBALS['fixture_terms']['product_cat'] = array(
	(object) array( 'term_id' => 45, 'name' => 'Fuel Filters', 'slug' => 'fuel-filters', 'count' => 37 ),
	(object) array( 'term_id' => 9, 'name' => 'Oil', 'slug' => 'oil', 'count' => 0 ),
);
$data  = $export_terms->invoke( null, 'product_cat', 1, 50 )->get_data();
$first = $data['items'][0];
assert_test( 'term' === $data['object_kind'], 'object_kind = term' );
assert_test( 2 === count( $data['items'] ), 'two term rows' );
assert_test( 'product_cat' === $first['object_type'], 'object_type = taxonomy name' );
assert_test( '45' === $first['source_id'], 'source_id = term_id (string)' );
assert_test( 'Fuel Filters' === $first['title'], 'title = term name' );
assert_test( 37 === $first['object_count'], 'object_count = term count' );
assert_test( 0 === $data['items'][1]['object_count'], 'orphan term (count 0) included' );
assert_test( 1 === $data['total_pages'], 'total_pages = ceil(2/50)' );

echo "\n=== {$passed} passed, {$failed} failed ===\n";
if ( $failed > 0 ) {
	echo 'FAILURES: ' . implode( ', ', $errors ) . "\n";
	exit( 1 );
}
exit( 0 );
