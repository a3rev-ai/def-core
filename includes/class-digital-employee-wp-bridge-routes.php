<?php
/**
 * Class Digital_Employee_WP_Bridge_Routes
 *
 * Registers the REST routes for the a3 AI Session Bridge plugin.
 *
 * @package digital-employee-wp-bridge
 * @since 0.1.0
 * @version 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Digital_Employee_WP_Bridge_Routes
 *
 * Registers the REST routes for the a3 AI Session Bridge plugin.
 *
 * @package digital-employee-wp-bridge
 * @since 0.1.0
 * @version 0.1.0
 */
final class Digital_Employee_WP_Bridge_Routes {
	/**
	 * Initialize the routes.
	 *
	 * @since 0.1.0
	 * @version 0.2.0
	 */
	public static function init(): void {
		// Register tools early so they're available for admin page and REST API.
		add_action( 'init', array( __CLASS__, 'register_tools' ), 10 );
		// Register REST routes when REST API is initialized.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Register tools with the registry (called early on 'init').
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public static function register_tools(): void {
		// Register core tools via registry.
		self::register_core_tools();

		// Allow addons to register their tools.
		do_action( 'digital_employee_wp_bridge_register_tools' );
	}

	/**
	 * Register the REST routes (called on 'rest_api_init').
	 *
	 * @since 0.1.0
	 * @version 0.2.0
	 */
	public static function register_rest_routes(): void {
		// Core routes (not tools, so registered directly).
		register_rest_route(
			DE_WP_BRIDGE_API_NAME_SPACE,
			'/context-token',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'callback'            => array( 'Digital_Employee_WP_Bridge_Tools', 'rest_issue_context_token' ),
			)
		);

		register_rest_route(
			DE_WP_BRIDGE_API_NAME_SPACE,
			'/jwks',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( 'Digital_Employee_WP_Bridge_Tools', 'rest_get_jwks' ),
			)
		);

		// Register all tools with WordPress REST API.
		// Tools are already registered in register_tools() on 'init' hook.
		$registry = Digital_Employee_WP_Bridge_API_Registry::instance();
		$registry->register_all_tools();
	}

	/**
	 * Register core tools.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	private static function register_core_tools(): void {
		$registry = Digital_Employee_WP_Bridge_API_Registry::instance();

		// User profile tool (always available).
		$registry->register_tool(
			'/tools/me',
			__( 'User Profile', 'digital-employee-wp-bridge' ),
			array( 'GET' ),
			array( 'Digital_Employee_WP_Bridge_Tools', 'me' ),
			array( 'Digital_Employee_WP_Bridge_Tools', 'permission_check' ),
			array(),
			'core'
		);

		// Check if WooCommerce is installed and active.
		$woocommerce_active = class_exists( 'WooCommerce' ) || function_exists( 'WC' );

		if ( $woocommerce_active ) {
			// WooCommerce Orders.
			$registry->register_tool(
				'/tools/wc/orders',
				__( 'WooCommerce Orders', 'digital-employee-wp-bridge' ),
				array( 'GET' ),
				array( 'Digital_Employee_WP_Bridge_Tools', 'wc_orders' ),
				array( 'Digital_Employee_WP_Bridge_Tools', 'permission_check' ),
				array(),
				'core'
			);

			// WooCommerce Order Detail.
			$registry->register_tool(
				'/tools/wc/orders/(?P<order_id>\d+)',
				__( 'WooCommerce Order Detail', 'digital-employee-wp-bridge' ),
				array( 'GET' ),
				array( 'Digital_Employee_WP_Bridge_Tools', 'wc_order_detail' ),
				array( 'Digital_Employee_WP_Bridge_Tools', 'permission_check' ),
				array(),
				'core'
			);

			// WooCommerce Add to Cart (respects guest checkout settings).
			$registry->register_tool(
				'/tools/wc/add-to-cart',
				__( 'WooCommerce Add to Cart', 'digital-employee-wp-bridge' ),
				array( 'POST' ),
				array( 'Digital_Employee_WP_Bridge_Tools', 'wc_add_to_cart' ),
				array( 'Digital_Employee_WP_Bridge_Tools', 'permission_check_add_to_cart' ), // Custom permission check.
				array(),
				'core'
			);

			// WooCommerce Products (public - no authentication required).
			$registry->register_tool(
				'/tools/wc/products',
				__( 'WooCommerce Products', 'digital-employee-wp-bridge' ),
				array( 'GET' ),
				array( 'Digital_Employee_WP_Bridge_Tools', 'wc_get_products_list' ),
				'__return_true',
				array(),
				'core'
			);
		}
	}
}
