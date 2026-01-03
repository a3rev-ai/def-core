<?php
/**
 * Class DEF_Core_Cache
 *
 * Handles caching for the Digital Employee Framework - Core plugin.
 *
 * @package def-core
 * @since 0.1.0
 * @version 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DEF_Core_Cache
 *
 * Provides user-scoped transient caching with automatic invalidation.
 *
 * @package def-core
 * @since 0.1.0
 * @version 0.1.0
 */
final class DEF_Core_Cache {
	/**
	 * Cache key prefix.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'de_tool_';

	/**
	 * Initialize cache hooks.
	 *
	 * @return void
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function init(): void {
		// Invalidate order cache when order status changes.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_changed' ), 10, 1 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_order_changed' ), 10, 1 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'on_order_changed' ), 10, 1 );

		// Invalidate user cache when profile is updated.
		add_action( 'profile_update', array( __CLASS__, 'on_user_updated' ), 10, 1 );

		// Invalidate cart cache when cart is updated.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_cart_updated' ), 10, 1 );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'on_cart_updated' ), 10, 1 );
		add_action( 'woocommerce_cart_item_restored', array( __CLASS__, 'on_cart_updated' ), 10, 1 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( __CLASS__, 'on_cart_updated' ), 10, 1 );

		// Invalidate products list cache when products change.
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product_changed' ), 10, 0 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_changed' ), 10, 0 );
		add_action( 'woocommerce_delete_product', array( __CLASS__, 'on_product_changed' ), 10, 0 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'on_product_changed' ), 10, 0 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_product_changed' ), 10, 0 );
		add_action( 'woocommerce_delete_product_variation', array( __CLASS__, 'on_product_changed' ), 10, 0 );
		add_action( 'woocommerce_trash_product', array( __CLASS__, 'on_product_changed' ), 10, 0 );
		add_action( 'woocommerce_untrash_product', array( __CLASS__, 'on_product_changed' ), 10, 0 );
	}

	/**
	 * Get cached data or execute callback and cache the result.
	 *
	 * @param string   $cache_key The cache key.
	 * @param int      $user_id   The user ID.
	 * @param int      $ttl       Time to live in seconds.
	 * @param callable $callback  Callback to execute if cache miss.
	 * @return mixed The cached or fresh data.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function get_or_set( string $cache_key, int $user_id, int $ttl, callable $callback ) {
		// Build full cache key with user ID.
		$full_key = self::build_key( $user_id, $cache_key );

		// Try to get from cache.
		$cached = get_transient( $full_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Cache miss, execute callback.
		$data = $callback();

		// Store in cache.
		if ( null !== $data ) {
			set_transient( $full_key, $data, $ttl );
		}

		// Return the data.
		return $data;
	}

	/**
	 * Build a cache key.
	 *
	 * @param int    $user_id   The user ID.
	 * @param string $cache_key The cache key suffix.
	 * @return string The full cache key.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	private static function build_key( int $user_id, string $cache_key ): string {
		return self::CACHE_PREFIX . $user_id . '_' . $cache_key;
	}

	/**
	 * Invalidate cache for a specific user and key.
	 *
	 * @param int    $user_id   The user ID.
	 * @param string $cache_key The cache key or pattern (with trailing _ for wildcard).
	 * @return void
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function invalidate( int $user_id, string $cache_key ): void {
		global $wpdb;

		// If cache_key ends with _, treat as wildcard pattern.
		if ( substr( $cache_key, -1 ) === '_' ) {
			$full_key = self::build_key( $user_id, $cache_key );
			$pattern  = $wpdb->esc_like( '_transient_' . $full_key ) . '%';
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$pattern,
					$wpdb->esc_like( '_transient_timeout_' . $full_key ) . '%'
				)
			);
		} else {
			// Exact key match.
			$full_key = self::build_key( $user_id, $cache_key );
			delete_transient( $full_key );
		}
	}

	/**
	 * Invalidate all caches for a specific user.
	 *
	 * @param int $user_id The user ID.
	 * @return void
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function invalidate_user( int $user_id ): void {
		global $wpdb;
		// Delete all transients matching the pattern.
		$pattern = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX . $user_id . '_' ) . '%';
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$pattern,
				$wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX . $user_id . '_' ) . '%'
			)
		);
	}

	/**
	 * Invalidate order cache when an order changes.
	 *
	 * @param int $order_id The order ID.
	 * @return void
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function on_order_changed( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$user_id = $order->get_customer_id();
		if ( $user_id ) {
			// Invalidate orders list cache (all variations).
			self::invalidate( $user_id, 'orders_' );
			// Invalidate specific order detail cache.
			self::invalidate( $user_id, "order_detail_{$order_id}" );
		}
	}

	/**
	 * Invalidate user cache when profile is updated.
	 *
	 * @param int $user_id The user ID.
	 * @return void
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function on_user_updated( int $user_id ): void {
		self::invalidate( $user_id, 'me' );
	}

	/**
	 * Get cache statistics.
	 *
	 * @return array Array of cache statistics.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function get_stats(): array {
		global $wpdb;

		// Count all de_tool transients.
		$total = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%'
			)
		);

		return array(
			'total_entries' => (int) $total,
			'prefix'        => self::CACHE_PREFIX,
		);
	}

	/**
	 * Clear all expired transients.
	 *
	 * @return int Number of expired transients cleared.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function clear_expired(): int {
		global $wpdb;

		// Delete expired de_tool transients.
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE a, b FROM {$wpdb->options} a
				LEFT JOIN {$wpdb->options} b ON b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12))
				WHERE a.option_name LIKE %s
				AND b.option_value < %d",
				$wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%',
				time()
			)
		);

		return (int) $result;
	}

	/**
	 * Clear all de_tool caches.
	 *
	 * @return int Number of entries cleared.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function clear_all(): int {
		global $wpdb;

		// Delete all de_tool transients and their timeouts.
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%'
			)
		);

		return (int) $result;
	}

	/**
	 * Invalidate cart cache when cart is updated.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function on_cart_updated(): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			self::invalidate_user( $user_id, 'cart_' );
		}
	}

	/**
	 * Handle product change events.
	 * Invalidates the global products list cache.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function on_product_changed(): void {
		// Delete the products list cache (not user-specific).
		delete_transient( 'de_products_list' );
	}
}
