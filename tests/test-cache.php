<?php
/**
 * Cache bug fix tests.
 *
 * Tests for the two cache bugs fixed in Sub-PR A:
 * 1. invalidate_user() now accepts optional $prefix parameter
 * 2. on_product_changed() uses correct transient key via build_key()
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Additional stubs needed for cache tests ──────────────────────────────

// Track deleted transients.
global $_wp_test_deleted_transients;
$_wp_test_deleted_transients = array();

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		global $_wp_test_transients, $_wp_test_deleted_transients;
		$_wp_test_deleted_transients[] = $key;
		unset( $_wp_test_transients[ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		global $_wp_test_current_user_id;
		return $_wp_test_current_user_id ?? 0;
	}
}

// Minimal $wpdb mock for cache invalidation queries.
global $wpdb;
$wpdb = new class {
	public $options = 'wp_options';
	public $queries = array();

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, ...$args ): string {
		$prepared = $query;
		foreach ( $args as $arg ) {
			$pos = strpos( $prepared, '%s' );
			if ( $pos !== false ) {
				$prepared = substr_replace( $prepared, "'" . (string) $arg . "'", $pos, 2 );
			}
			$pos = strpos( $prepared, '%d' );
			if ( $pos !== false ) {
				$prepared = substr_replace( $prepared, (string) intval( $arg ), $pos, 2 );
			}
		}
		return $prepared;
	}

	public function query( string $query ): int {
		$this->queries[] = $query;
		return 0;
	}
};

// ── Load cache class ─────────────────────────────────────────────────────
require_once dirname( __DIR__ ) . '/includes/class-def-core-cache.php';

// ── Test helpers ─────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;

function assert_true( bool $condition, string $label ): void {
	global $pass, $fail;
	if ( $condition ) {
		++$pass;
	} else {
		++$fail;
		echo "  FAIL: $label\n";
	}
}

function assert_equals( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		++$pass;
	} else {
		++$fail;
		$exp_str = var_export( $expected, true );
		$act_str = var_export( $actual, true );
		echo "  FAIL: $label (expected $exp_str, got $act_str)\n";
	}
}

function reset_cache_test_state(): void {
	global $_wp_test_transients, $_wp_test_deleted_transients, $wpdb;
	$_wp_test_transients = array();
	$_wp_test_deleted_transients = array();
	$wpdb->queries = array();
}

// ── Tests ────────────────────────────────────────────────────────────────

echo "=== Cache Bug Fix Tests ===\n";

// ── 1. invalidate_user() with prefix delegates to invalidate() ──────────
echo "\n[1] invalidate_user() with prefix delegates to invalidate()\n";
reset_cache_test_state();

// Call invalidate_user with a prefix — should delegate to invalidate().
// invalidate() with trailing _ uses $wpdb->query() for wildcard deletion.
DEF_Core_Cache::invalidate_user( 42, 'cart_' );

// Should have generated a $wpdb query targeting cart_ keys for user 42.
assert_true(
	count( $wpdb->queries ) === 1,
	'exactly one $wpdb query executed'
);

// The query should target the correct key pattern: de_tool_42_cart_
// Note: esc_like() escapes underscores, so check for the escaped form.
assert_true(
	! empty( $wpdb->queries[0] ) && strpos( $wpdb->queries[0], 'de\\_tool\\_42\\_cart\\_' ) !== false,
	'query targets de_tool_42_cart_ pattern'
);

// ── 2. invalidate_user() without prefix deletes all user transients ─────
echo "\n[2] invalidate_user() without prefix deletes all user transients\n";
reset_cache_test_state();

// Call without prefix — should delete all transients for user.
DEF_Core_Cache::invalidate_user( 42 );

assert_true(
	count( $wpdb->queries ) === 1,
	'exactly one $wpdb query executed'
);

// Should target de_tool_42_ (all keys for user 42).
// esc_like() escapes underscores in the LIKE pattern.
assert_true(
	! empty( $wpdb->queries[0] ) && strpos( $wpdb->queries[0], 'de\\_tool\\_42\\_' ) !== false,
	'query targets de_tool_42_ pattern'
);

// ── 3. invalidate_user() with prefix only targets prefixed keys ─────────
echo "\n[3] invalidate_user() with prefix targets only prefixed keys\n";
reset_cache_test_state();

DEF_Core_Cache::invalidate_user( 7, 'cart_' );

// The query should NOT contain a broad pattern for all user keys.
// It should specifically target cart_ prefixed keys.
$query = $wpdb->queries[0] ?? '';
assert_true(
	strpos( $query, 'de\\_tool\\_7\\_cart\\_' ) !== false,
	'query includes cart_ prefix for user 7'
);

// ── 4. on_product_changed() deletes correct transient key ───────────────
echo "\n[4] on_product_changed() uses correct transient key\n";
reset_cache_test_state();

// Set a transient using the same key format as build_key(0, 'products_list').
// CACHE_PREFIX = 'de_tool_', so build_key(0, 'products_list') = 'de_tool_0_products_list'.
set_transient( 'de_tool_0_products_list', array( 'product1', 'product2' ) );

// Verify it exists before deletion.
assert_true(
	get_transient( 'de_tool_0_products_list' ) !== false,
	'products_list transient exists before on_product_changed()'
);

// Call the handler.
DEF_Core_Cache::on_product_changed();

// Verify the correct transient was deleted.
assert_true(
	get_transient( 'de_tool_0_products_list' ) === false,
	'products_list transient deleted after on_product_changed()'
);

// Verify the correct key was passed to delete_transient.
assert_true(
	in_array( 'de_tool_0_products_list', $_wp_test_deleted_transients, true ),
	'delete_transient called with de_tool_0_products_list'
);

// ── 5. on_product_changed() does NOT use wrong key ──────────────────────
echo "\n[5] on_product_changed() does not use old wrong key\n";
reset_cache_test_state();

DEF_Core_Cache::on_product_changed();

// The old buggy code would have called delete_transient('de_products_list').
// Verify the wrong key was NOT used.
assert_true(
	! in_array( 'de_products_list', $_wp_test_deleted_transients, true ),
	'delete_transient NOT called with wrong key de_products_list'
);

// ── 6. get_or_set() caches correctly ────────────────────────────────────
echo "\n[6] get_or_set() basic caching works\n";
reset_cache_test_state();

$call_count = 0;
$result = DEF_Core_Cache::get_or_set( 'products_list', 0, 300, function() use ( &$call_count ) {
	++$call_count;
	return array( 'widget_a', 'widget_b' );
});

assert_equals( array( 'widget_a', 'widget_b' ), $result, 'first call returns callback data' );
assert_equals( 1, $call_count, 'callback called once on cache miss' );

// Second call should return cached data without calling callback.
$result2 = DEF_Core_Cache::get_or_set( 'products_list', 0, 300, function() use ( &$call_count ) {
	++$call_count;
	return array( 'should_not_see_this' );
});

assert_equals( array( 'widget_a', 'widget_b' ), $result2, 'second call returns cached data' );
assert_equals( 1, $call_count, 'callback not called on cache hit' );

// Verify the transient was stored with the correct key.
assert_true(
	get_transient( 'de_tool_0_products_list' ) !== false,
	'transient stored with key de_tool_0_products_list'
);

// ── Summary ──────────────────────────────────────────────────────────────

echo "\n--- Cache Bug Fix Tests: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
