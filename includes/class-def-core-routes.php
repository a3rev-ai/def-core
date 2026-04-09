<?php
/**
 * Class DEF_Core_Routes
 *
 * Registers the REST routes for the Digital Employee Framework - Core plugin.
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
 * Class DEF_Core_Routes
 *
 * Registers the REST routes for the Digital Employee Framework - Core plugin.
 *
 * @package def-core
 * @since 0.1.0
 * @version 0.1.0
 */
final class DEF_Core_Routes {
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

		// Allow modules to register their tools.
		do_action( 'def_core_register_tools' );
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
			DEF_CORE_API_NAME_SPACE,
			'/context-token',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'callback'            => array( 'DEF_Core_Tools', 'rest_issue_context_token' ),
			)
		);

		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/jwks',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( 'DEF_Core_Tools', 'rest_get_jwks' ),
			)
		);

		// Customer Chat BFF proxy — proxies chat requests to DEF backend
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/chat/stream',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( 'DEF_Core_Tools', 'rest_proxy_chat_stream' ),
			)
		);

		// Customer Chat BFF proxy — upload init/commit (same pattern as chat)
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/uploads/init',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( 'DEF_Core_Tools', 'rest_proxy_upload_init' ),
			)
		);

		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/uploads/commit',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( 'DEF_Core_Tools', 'rest_proxy_upload_commit' ),
			)
		);

		// Staff AI BFF proxy — proxies chat requests to DEF backend
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/chat/stream',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					if ( ! is_user_logged_in() ) {
						return false;
					}
					return current_user_can( 'def_staff_access' ) || current_user_can( 'def_management_access' );
				},
				'callback'            => array( 'DEF_Core_Tools', 'rest_proxy_staff_ai_stream' ),
			)
		);

		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					if ( ! is_user_logged_in() ) {
						return false;
					}
					return current_user_can( 'def_staff_access' ) || current_user_can( 'def_management_access' );
				},
				'callback'            => array( 'DEF_Core_Tools', 'rest_proxy_staff_ai_status' ),
			)
		);

		// Setup Assistant BFF proxy — proxies chat requests to DEF backend
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/setup-assistant/chat/stream',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					if ( ! is_user_logged_in() ) {
						return false;
					}
					return current_user_can( 'def_admin_access' );
				},
				'callback'            => array( 'DEF_Core_Tools', 'rest_proxy_setup_assistant_stream' ),
			)
		);

		// Register all tools with WordPress REST API.
		// Tools are already registered in register_tools() on 'init' hook.
		$registry = DEF_Core_API_Registry::instance();
		$registry->register_all_tools();
	}

	/**
	 * Register core tools.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	private static function register_core_tools(): void {
		$registry = DEF_Core_API_Registry::instance();

		// User profile tool (always available).
		$registry->register_tool(
			'/tools/me',
			__( 'User Profile', 'digital-employees' ),
			array( 'GET' ),
			array( 'DEF_Core_Tools', 'me' ),
			array( 'DEF_Core_Tools', 'permission_check' ),
			array(),
			'core'
		);

		// Check if WooCommerce is installed and active.
		$woocommerce_active = class_exists( 'WooCommerce' ) || function_exists( 'WC' );

		if ( $woocommerce_active ) {
			// WooCommerce Orders.
			$registry->register_tool(
				'/tools/wc/orders',
				__( 'WooCommerce Orders', 'digital-employees' ),
				array( 'GET' ),
				array( 'DEF_Core_Tools', 'wc_orders' ),
				array( 'DEF_Core_Tools', 'permission_check' ),
				array(),
				'core'
			);

			// WooCommerce Order Detail.
			$registry->register_tool(
				'/tools/wc/orders/(?P<order_id>\d+)',
				__( 'WooCommerce Order Detail', 'digital-employees' ),
				array( 'GET' ),
				array( 'DEF_Core_Tools', 'wc_order_detail' ),
				array( 'DEF_Core_Tools', 'permission_check' ),
				array(),
				'core'
			);

			// WooCommerce Add to Cart (respects guest checkout settings).
			$registry->register_tool(
				'/tools/wc/add-to-cart',
				__( 'WooCommerce Add to Cart', 'digital-employees' ),
				array( 'POST' ),
				array( 'DEF_Core_Tools', 'wc_add_to_cart' ),
				array( 'DEF_Core_Tools', 'permission_check_add_to_cart' ), // Custom permission check.
				array(),
				'core'
			);

			// WooCommerce Products (public - no authentication required).
			$registry->register_tool(
				'/tools/wc/products',
				__( 'WooCommerce Products', 'digital-employees' ),
				array( 'GET' ),
				array( 'DEF_Core_Tools', 'wc_get_products_list' ),
				'__return_true',
				array(),
				'core'
			);
		}
	}
}
