<?php
/**
 * Class DEF_Core
 *
 * Main plugin class for the Digital Employee Framework - Core plugin.
 *
 * @package def-core
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
 * @package def-core
 */
final class DEF_Core {
	/**
	 * The instance of the DEF_Core class.
	 *
	 * @var DEF_Core
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * @return DEF_Core The instance.
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
		DEF_Core_Admin::init();
		DEF_Core_Routes::init();
		DEF_Core_Cache::init();
		DEF_Core_Staff_AI::init();

		// Register activation hook.
		register_activation_hook( DEF_CORE_PLUGIN_DIR . 'def-core.php', array( __CLASS__, 'on_activate' ) );

		// Add settings link to plugin action links.
		add_filter( 'plugin_action_links_' . plugin_basename( DEF_CORE_PLUGIN_DIR . 'def-core.php' ), array( $this, 'add_settings_link' ) );
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
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-jwt.php';
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-cache.php';
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-admin.php';
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-tools.php';

		// API Registry (must be loaded before routes).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-api-registry.php';

		// Tool base class (for modules).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/tools/class-def-core-tool-base.php';

		// Staff AI frontend.
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-staff-ai.php';

		// Routes (registers core tools and allows modules to register).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-routes.php';

		// Plugin inited action hook.
		add_action(
			'plugins_loaded',
			function () {
				do_action( 'def_core_inited' );
			}
		);
	}

	/**
	 * Activation hook handler.
	 */
	public static function on_activate(): void {
		DEF_Core_JWT::ensure_keys_exist();
		if ( get_option( DEF_CORE_OPTION_ALLOWED_ORIGINS ) === false ) {
			add_option( DEF_CORE_OPTION_ALLOWED_ORIGINS, array(), '', false );
		}
		// Flush rewrite rules for Staff AI endpoint.
		DEF_Core_Staff_AI::on_activate();
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
			'def-core',
			DEF_CORE_PLUGIN_URL . 'assets/js/def-core.js',
			array(),
			DEF_CORE_VERSION,
			array( 'in_footer' => true )
		);

		wp_register_script(
			'def-core-cart-sync',
			DEF_CORE_PLUGIN_URL . 'assets/js/def-core-cart-sync.js',
			array(),
			DEF_CORE_VERSION,
			array( 'in_footer' => true )
		);

		// Register admin assets (only enqueued on admin pages).
		wp_register_style(
			'def-core-admin',
			DEF_CORE_PLUGIN_URL . 'assets/css/def-core-admin.css',
			array(),
			DEF_CORE_VERSION
		);
		wp_register_script(
			'def-core-admin',
			DEF_CORE_PLUGIN_URL . 'assets/js/def-core-admin.js',
			array(),
			DEF_CORE_VERSION,
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
			'restUrl'        => esc_url_raw( rest_url( DEF_CORE_API_NAME_SPACE . '/context-token' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'allowedOrigins' => $this->get_allowed_origins(),
		);
		wp_localize_script( 'def-core', 'DEFCore', $rest_data );
		wp_enqueue_script( 'def-core' );

		// Enqueue cart sync script only if WooCommerce is installed and Add to Cart API is enabled.
		if ( $this->should_enqueue_cart_sync() ) {
			wp_enqueue_script( 'def-core-cart-sync' );
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
		$registry = DEF_Core_API_Registry::instance();
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
		$origins = get_option( DEF_CORE_OPTION_ALLOWED_ORIGINS, array() );
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
		$url     = admin_url( 'options-general.php?page=def-core' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'def-core' ) . '</a>';
		return $links;
	}
}

// Initialize the plugin.
DEF_Core::instance();
