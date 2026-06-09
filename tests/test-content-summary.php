<?php
/**
 * Content Drafts coverage strip — /content/summary BFF tests.
 *
 * Verifies:
 *  - DEF_Core_Staff_AI::normalize_summary_payload() shapes the backend payload
 *    safely: null/no-summary collapses to null; every bucket is whitelisted and
 *    coerced to a non-negative int; unknown bucket keys are dropped; malformed
 *    type keys are dropped; missing types/buckets are tolerated; totals carry a
 *    sanitized total_reviewed.
 *  - The GET /staff-ai/content/summary route is registered.
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// Capture register_rest_route() calls so we can assert the route is wired.
global $_wp_test_rest_routes;
$_wp_test_rest_routes = array();
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = array() ): bool {
		global $_wp_test_rest_routes;
		$_wp_test_rest_routes[ $namespace . $route ] = $args;
		return true;
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-staff-ai.php';

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

// ── 1. No summary → null ────────────────────────────────────────────────
echo "[1] absent/malformed summary → null\n";
assert_same( null, DEF_Core_Staff_AI::normalize_summary_payload( array() ), 'missing key → null' );
assert_same( null, DEF_Core_Staff_AI::normalize_summary_payload( array( 'summary' => null ) ), 'null summary → null' );
assert_same( null, DEF_Core_Staff_AI::normalize_summary_payload( 'nope' ), 'non-array payload → null' );
assert_same( null, DEF_Core_Staff_AI::normalize_summary_payload( array( 'summary' => 'oops' ) ), 'non-array summary → null' );

// ── 2. Buckets coerced to non-negative ints; unknown keys dropped ───────
echo "[2] buckets whitelisted + int-coerced\n";
$norm = DEF_Core_Staff_AI::normalize_summary_payload( array(
	'summary' => array(
		'by_type' => array(
			'product' => array(
				'good'            => '9',   // string from JSON → int
				'optimized'       => 2,
				'awaiting_review' => 3,
				'needs_keyphrase' => 20,
				'errored'         => -4,    // negative → clamped to 0
				'bogus_key'       => 99,    // not whitelisted → dropped
			),
		),
		'totals' => array(
			'good'           => 9,
			'optimized'      => 2,
			'needs_keyphrase' => 20,
			'total_reviewed' => '34',
		),
	),
) );
assert_true( is_array( $norm ), 'returns an array' );
$prod = $norm['by_type']['product'];
assert_same( 9, $prod['good'], 'good "9" → 9 int' );
assert_same( 2, $prod['optimized'], 'optimized int' );
assert_same( 3, $prod['awaiting_review'], 'awaiting_review int' );
assert_same( 20, $prod['needs_keyphrase'], 'needs_keyphrase int' );
assert_same( 0, $prod['errored'], 'negative errored clamped to 0' );
assert_same( 0, $prod['needs_work'], 'missing bucket defaults to 0' );
assert_true( ! array_key_exists( 'bogus_key', $prod ), 'unknown bucket key dropped' );
assert_same( 34, $norm['totals']['total_reviewed'], 'total_reviewed "34" → 34' );

// ── 3. Malformed type keys dropped; missing buckets tolerated ───────────
echo "[3] malformed type keys dropped\n";
$mixed = DEF_Core_Staff_AI::normalize_summary_payload( array(
	'summary' => array(
		'by_type' => array(
			'post' => array( 'needs_keyphrase' => 10 ),
			''     => array( 'good' => 5 ),   // empty type key → dropped
			'page' => 'not-an-array',          // non-array buckets → all zeros
		),
	),
) );
assert_true( isset( $mixed['by_type']['post'] ), 'valid type kept' );
assert_true( ! isset( $mixed['by_type'][''] ), 'empty type key dropped' );
assert_same( 10, $mixed['by_type']['post']['needs_keyphrase'], 'post needs_keyphrase' );
assert_same( 0, $mixed['by_type']['page']['good'], 'non-array buckets → zeros' );
assert_same( 0, $mixed['totals']['total_reviewed'], 'missing totals → total_reviewed 0' );

// ── 4. a3rev sanity values pass through intact ──────────────────────────
echo "[4] a3rev sanity values\n";
$a3 = DEF_Core_Staff_AI::normalize_summary_payload( array(
	'summary' => array(
		'by_type' => array(
			'product' => array( 'good' => 9, 'optimized' => 2, 'needs_keyphrase' => 20, 'total' => 31 ),
			'post'    => array( 'needs_keyphrase' => 10, 'total' => 10 ),
			'page'    => array( 'needs_keyphrase' => 10, 'total' => 10 ),
		),
		'totals' => array( 'good' => 9, 'optimized' => 2, 'needs_keyphrase' => 40, 'total_reviewed' => 51 ),
	),
) );
assert_same( 9, $a3['by_type']['product']['good'], 'product good 9' );
assert_same( 2, $a3['by_type']['product']['optimized'], 'product optimized 2' );
assert_same( 20, $a3['by_type']['product']['needs_keyphrase'], 'product needs_keyphrase 20' );
assert_same( 10, $a3['by_type']['post']['needs_keyphrase'], 'post needs_keyphrase 10' );
assert_same( 10, $a3['by_type']['page']['needs_keyphrase'], 'page needs_keyphrase 10' );

// ── 5. Route registration: GET /staff-ai/content/summary ────────────────
echo "[5] summary route is registered (GET)\n";
DEF_Core_Staff_AI::register_rest_routes();
$key = DEF_CORE_API_NAME_SPACE . '/staff-ai/content/summary';
assert_true( isset( $_wp_test_rest_routes[ $key ] ), 'summary route registered' );
if ( isset( $_wp_test_rest_routes[ $key ] ) ) {
	assert_same( 'GET', $_wp_test_rest_routes[ $key ]['methods'], 'summary route is GET' );
	assert_true( ! empty( $_wp_test_rest_routes[ $key ]['permission_callback'] ), 'summary route has a permission_callback' );
}

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
