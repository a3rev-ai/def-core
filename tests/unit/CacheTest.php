<?php
/**
 * PHPUnit tests for DEF_Core_Cache.
 *
 * Converted from tests/test-cache.php — all original test cases preserved.
 *
 * @package def-core/tests/unit
 */

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers DEF_Core_Cache
 */
final class CacheTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		global $_wp_test_transients, $_wp_test_deleted_transients, $wpdb;

		$_wp_test_transients         = array();
		$_wp_test_deleted_transients = array();

		// Minimal $wpdb mock for cache invalidation queries.
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
	}

	// ── 1. invalidate_user() with prefix ────────────────────────────────

	public function test_invalidate_user_with_prefix_executes_query(): void {
		global $wpdb;
		DEF_Core_Cache::invalidate_user( 42, 'cart_' );
		$this->assertCount( 1, $wpdb->queries );
	}

	public function test_invalidate_user_with_prefix_targets_correct_pattern(): void {
		global $wpdb;
		DEF_Core_Cache::invalidate_user( 42, 'cart_' );
		$this->assertStringContainsString( 'de\\_tool\\_42\\_cart\\_', $wpdb->queries[0] );
	}

	// ── 2. invalidate_user() without prefix ─────────────────────────────

	public function test_invalidate_user_without_prefix_executes_query(): void {
		global $wpdb;
		DEF_Core_Cache::invalidate_user( 42 );
		$this->assertCount( 1, $wpdb->queries );
	}

	public function test_invalidate_user_without_prefix_targets_all_user_keys(): void {
		global $wpdb;
		DEF_Core_Cache::invalidate_user( 42 );
		$this->assertStringContainsString( 'de\\_tool\\_42\\_', $wpdb->queries[0] );
	}

	// ── 3. invalidate_user() with prefix only targets prefixed keys ─────

	public function test_invalidate_user_prefix_targets_specific_user(): void {
		global $wpdb;
		DEF_Core_Cache::invalidate_user( 7, 'cart_' );
		$this->assertStringContainsString( 'de\\_tool\\_7\\_cart\\_', $wpdb->queries[0] ?? '' );
	}

	// ── 4. on_product_changed() deletes correct transient key ───────────

	public function test_on_product_changed_deletes_correct_transient(): void {
		global $_wp_test_deleted_transients;

		set_transient( 'de_tool_0_products_list', array( 'product1', 'product2' ) );
		$this->assertNotFalse( get_transient( 'de_tool_0_products_list' ) );

		DEF_Core_Cache::on_product_changed();

		$this->assertFalse( get_transient( 'de_tool_0_products_list' ) );
		$this->assertContains( 'de_tool_0_products_list', $_wp_test_deleted_transients );
	}

	// ── 5. on_product_changed() does NOT use old wrong key ──────────────

	public function test_on_product_changed_does_not_use_wrong_key(): void {
		global $_wp_test_deleted_transients;
		DEF_Core_Cache::on_product_changed();
		$this->assertNotContains( 'de_products_list', $_wp_test_deleted_transients );
	}

	// ── 6. get_or_set() basic caching ───────────────────────────────────

	public function test_get_or_set_calls_callback_on_miss(): void {
		$call_count = 0;
		$result = DEF_Core_Cache::get_or_set( 'products_list', 0, 300, function () use ( &$call_count ) {
			++$call_count;
			return array( 'widget_a', 'widget_b' );
		} );

		$this->assertSame( array( 'widget_a', 'widget_b' ), $result );
		$this->assertSame( 1, $call_count );
	}

	public function test_get_or_set_returns_cached_on_hit(): void {
		$call_count = 0;
		$callback = function () use ( &$call_count ) {
			++$call_count;
			return array( 'widget_a', 'widget_b' );
		};

		DEF_Core_Cache::get_or_set( 'products_list', 0, 300, $callback );

		$result2 = DEF_Core_Cache::get_or_set( 'products_list', 0, 300, function () use ( &$call_count ) {
			++$call_count;
			return array( 'should_not_see_this' );
		} );

		$this->assertSame( array( 'widget_a', 'widget_b' ), $result2 );
		$this->assertSame( 1, $call_count );
	}

	public function test_get_or_set_stores_with_correct_key(): void {
		DEF_Core_Cache::get_or_set( 'products_list', 0, 300, function () {
			return array( 'data' );
		} );
		$this->assertNotFalse( get_transient( 'de_tool_0_products_list' ) );
	}
}
