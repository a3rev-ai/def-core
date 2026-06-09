<?php
/**
 * Content Drafts "last run" status strip — BFF tests.
 *
 * Verifies:
 *  - DEF_Core_Staff_AI::normalize_last_run_payload() shapes the backend payload
 *    safely: null/never-run collapses to null; counts are coerced to
 *    non-negative ints; status/timestamps are strings-or-null; optional
 *    audit_failed_types is a clean list of non-empty strings.
 *  - The GET /staff-ai/content/last-run route is registered.
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

// ── 1. last_run: null → "no runs yet" (null) ────────────────────────────
echo "[1] last_run null → null\n";
assert_same( null, DEF_Core_Staff_AI::normalize_last_run_payload( array( 'last_run' => null ) ), 'explicit null collapses to null' );
assert_same( null, DEF_Core_Staff_AI::normalize_last_run_payload( array() ), 'missing key collapses to null' );
assert_same( null, DEF_Core_Staff_AI::normalize_last_run_payload( 'not-an-array' ), 'non-array payload collapses to null' );
assert_same( null, DEF_Core_Staff_AI::normalize_last_run_payload( array( 'last_run' => 'oops' ) ), 'malformed last_run collapses to null' );

// ── 2. Finished run: counts coerced to non-negative ints ────────────────
echo "[2] finished run normalizes counts to ints\n";
$norm = DEF_Core_Staff_AI::normalize_last_run_payload( array(
	'last_run' => array(
		'status'      => 'completed',
		'started_at'  => '2026-06-09T10:00:00Z',
		'finished_at' => '2026-06-09T10:03:00Z',
		'counts'      => array(
			'audited'         => '84',   // string from JSON → int
			'flagged'         => 3,
			'staged'          => 2,
			'needs_keyphrase' => 1,
			'skipped'         => 0,
			'errored'         => -5,     // nonsense negative → clamped to 0
		),
	),
) );
assert_true( is_array( $norm ), 'finished run returns an array' );
assert_same( 'completed', $norm['status'], 'status passthrough' );
assert_same( '2026-06-09T10:00:00Z', $norm['started_at'], 'started_at passthrough' );
assert_same( '2026-06-09T10:03:00Z', $norm['finished_at'], 'finished_at passthrough' );
assert_same( 84, $norm['counts']['audited'], 'audited coerced "84" → 84 (int)' );
assert_same( 2, $norm['counts']['staged'], 'staged int' );
assert_same( 1, $norm['counts']['needs_keyphrase'], 'needs_keyphrase int' );
assert_same( 0, $norm['counts']['errored'], 'negative errored clamped to 0' );
assert_true( ! isset( $norm['counts']['audit_failed_types'] ), 'no audit_failed_types when absent' );

// ── 3. In-flight run: finished_at null preserved ────────────────────────
echo "[3] in-flight run keeps finished_at null\n";
$run = DEF_Core_Staff_AI::normalize_last_run_payload( array(
	'last_run' => array(
		'status'      => 'running',
		'started_at'  => '2026-06-09T11:00:00Z',
		'finished_at' => null,
		'counts'      => array(),
	),
) );
assert_same( '2026-06-09T11:00:00Z', $run['started_at'], 'started_at set' );
assert_same( null, $run['finished_at'], 'finished_at null (in flight)' );
assert_same( 0, $run['counts']['audited'], 'missing counts default to 0' );

// ── 4. audit_failed_types: keep non-empty strings only ──────────────────
echo "[4] audit_failed_types cleaned to non-empty strings\n";
$ft = DEF_Core_Staff_AI::normalize_last_run_payload( array(
	'last_run' => array(
		'status'      => 'completed',
		'started_at'  => '2026-06-09T10:00:00Z',
		'finished_at' => '2026-06-09T10:03:00Z',
		'counts'      => array(
			'audited'            => 10,
			'audit_failed_types' => array( 'product', '', 'post', 42, null ),
		),
	),
) );
assert_same( array( 'product', 'post' ), $ft['counts']['audit_failed_types'], 'drops empty/non-string entries' );

// ── 5. Bad status/timestamp types degrade safely ────────────────────────
echo "[5] bad scalar types degrade safely\n";
$bad = DEF_Core_Staff_AI::normalize_last_run_payload( array(
	'last_run' => array(
		'status'      => 123,            // non-string → ''
		'started_at'  => false,          // non-string → null
		'finished_at' => array( 'x' ),   // non-string → null
		'counts'      => 'not-an-array', // non-array → all zeros
	),
) );
assert_same( '', $bad['status'], 'non-string status → empty string' );
assert_same( null, $bad['started_at'], 'non-string started_at → null' );
assert_same( null, $bad['finished_at'], 'non-string finished_at → null' );
assert_same( 0, $bad['counts']['staged'], 'non-array counts → zeros' );

// ── 6. Route registration: GET /staff-ai/content/last-run ───────────────
echo "[6] last-run route is registered (GET)\n";
DEF_Core_Staff_AI::register_rest_routes();
$key = DEF_CORE_API_NAME_SPACE . '/staff-ai/content/last-run';
assert_true( isset( $_wp_test_rest_routes[ $key ] ), 'last-run route registered' );
if ( isset( $_wp_test_rest_routes[ $key ] ) ) {
	assert_same( 'GET', $_wp_test_rest_routes[ $key ]['methods'], 'last-run route is GET' );
	assert_true( ! empty( $_wp_test_rest_routes[ $key ]['permission_callback'] ), 'last-run route has a permission_callback' );
}

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
