<?php
/**
 * Class Digital_Employee_WP_Bridge
 *
 * Main plugin class for the Digital Employee Framework WordPress Bridge plugin.
 *
 * @package digital-employee-wp-bridge
 * @since 0.2.0
 * @version 0.2.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 *
 * @package digital-employee-wp-bridge
 */
final class Digital_Employee_WP_Bridge {
	/**
	 * The instance of the Digital_Employee_WP_Bridge class.
	 *
	 * @var Digital_Employee_WP_Bridge
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * @return Digital_Employee_WP_Bridge The instance.
	 */
	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Load all required files.
		$this->load_dependencies();

		// Register assets.
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// Initialize components.
		Digital_Employee_WP_Bridge_Admin::init();
		Digital_Employee_WP_Bridge_Routes::init();
		Digital_Employee_WP_Bridge_Cache::init();

		// Register activation hook.
		register_activation_hook( DE_WP_BRIDGE_PLUGIN_DIR . 'digital-employee-wp-bridge.php', array( __CLASS__, 'on_activate' ) );

		// Add settings link to plugin action links.
		add_filter( 'plugin_action_links_' . plugin_basename( DE_WP_BRIDGE_PLUGIN_DIR . 'digital-employee-wp-bridge.php' ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Load all plugin dependencies.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	private function load_dependencies(): void {
		// Main plugin class (this file).
		// Core classes.
		require_once DE_WP_BRIDGE_PLUGIN_DIR . 'includes/class-digital-employee-wp-bridge-jwt.php';
		require_once DE_WP_BRIDGE_PLUGIN_DIR . 'includes/class-digital-employee-wp-bridge-cache.php';
		require_once DE_WP_BRIDGE_PLUGIN_DIR . 'includes/class-digital-employee-wp-bridge-admin.php';
		require_once DE_WP_BRIDGE_PLUGIN_DIR . 'includes/class-digital-employee-wp-bridge-tools.php';

		// API Registry (must be loaded before routes).
		require_once DE_WP_BRIDGE_PLUGIN_DIR . 'includes/class-digital-employee-wp-bridge-api-registry.php';

		// Tool base class (for addons).
		require_once DE_WP_BRIDGE_PLUGIN_DIR . 'includes/tools/class-digital-employee-wp-bridge-tool-base.php';

		// Routes (registers core tools and allows addons to register).
		require_once DE_WP_BRIDGE_PLUGIN_DIR . 'includes/class-digital-employee-wp-bridge-routes.php';

		// Plugin inited action hook.
		do_action( 'digital_employee_wp_bridge_inited' );
	}

	/**
	 * Activation hook handler.
	 */
	public static function on_activate(): void {
		Digital_Employee_WP_Bridge_JWT::ensure_keys_exist();
		if ( get_option( DE_WP_BRIDGE_OPTION_ALLOWED_ORIGINS ) === false ) {
			add_option( DE_WP_BRIDGE_OPTION_ALLOWED_ORIGINS, array(), '', false );
		}
	}

	/**
	 * Register assets (available everywhere, but not enqueued).
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function register_assets(): void {
		// Register frontend scripts (only enqueued on frontend via wp_enqueue_scripts).
		wp_register_script(
			'digital-employee-wp-bridge',
			DE_WP_BRIDGE_PLUGIN_URL . 'assets/js/digital-employee-wp-bridge.js',
			array(),
			DE_WP_BRIDGE_VERSION,
			array( 'in_footer' => true )
		);

		wp_register_script(
			'digital-employee-cart-sync',
			DE_WP_BRIDGE_PLUGIN_URL . 'assets/js/digital-employee-cart-sync.js',
			array(),
			DE_WP_BRIDGE_VERSION,
			array( 'in_footer' => true )
		);

		// Register admin assets (only enqueued on admin pages).
		wp_register_style(
			'digital-employee-wp-bridge-admin',
			DE_WP_BRIDGE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			DE_WP_BRIDGE_VERSION
		);
		wp_register_script(
			'digital-employee-wp-bridge-admin',
			DE_WP_BRIDGE_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			DE_WP_BRIDGE_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Enqueue frontend assets (only on frontend).
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function enqueue_frontend_assets(): void {
		// Only enqueue on frontend (not in admin).
		if ( is_admin() ) {
			return;
		}

		// Enqueue main bridge script.
		$rest_data = array(
			'restUrl'        => esc_url_raw( rest_url( DE_WP_BRIDGE_API_NAME_SPACE . '/context-token' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'allowedOrigins' => $this->get_allowed_origins(),
		);
		wp_localize_script( 'digital-employee-wp-bridge', 'DEWPBridge', $rest_data );
		wp_enqueue_script( 'digital-employee-wp-bridge' );

		// Enqueue cart sync script only if WooCommerce is installed and Add to Cart API is enabled.
		if ( $this->should_enqueue_cart_sync() ) {
			wp_enqueue_script( 'digital-employee-cart-sync' );
		}
	}

	/**
	 * Check if cart sync script should be enqueued.
	 *
	 * @return bool True if WooCommerce is installed and Add to Cart API is enabled.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	private function should_enqueue_cart_sync(): bool {
		// Check if WooCommerce is installed and active.
		$woocommerce_active = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
		if ( ! $woocommerce_active ) {
			return false;
		}

		// Check if Add to Cart tool is registered.
		$registry = Digital_Employee_WP_Bridge_API_Registry::instance();
		$route    = '/tools/wc/add-to-cart';

		// Check if tool is registered.
		if ( ! $registry->is_registered( $route ) ) {
			return false;
		}

		// Check if tool is enabled.
		return $registry->is_tool_enabled( $route );
	}

	/**
	 * Get allowed origins from options.
	 *
	 * @return array Array of allowed origins.
	 */
	private function get_allowed_origins(): array {
		$origins = get_option( DE_WP_BRIDGE_OPTION_ALLOWED_ORIGINS, array() );
		if ( ! is_array( $origins ) ) {
			$origins = array();
		}
		return array_values( array_filter( array_map( 'trim', $origins ) ) );
	}

	/**
	 * Add settings link to plugin action links.
	 *
	 * @param array $links Existing links.
	 * @return array Modified links.
	 */
	public function add_settings_link( array $links ): array {
		$url     = admin_url( 'options-general.php?page=digital-employee-wp-bridge' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'digital-employee-wp-bridge' ) . '</a>';
		return $links;
	}
}

// Initialize the plugin.
Digital_Employee_WP_Bridge::instance();
