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

		// Load text domain for translations.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Register assets.
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// Initialize components.
		DEF_Core_Admin::init();
		DEF_Core_Routes::init();
		DEF_Core_Cache::init();
		DEF_Core_Staff_AI::init();
		DEF_Core_Escalation::init();
		DEF_Core_Connection_Config::init();
		DEF_Core_OAuth::init();
		DEF_Core_Export::init();
		DEF_Core_Search_Export::init();
		DEF_Core_Knowledge_Export::init();
		DEF_Core_Knowledge_Exclusion::init();
		DEF_Core_Admin_API::init();
		DEF_Core_Site_Tools::init();
		DEF_Core_Logs_Page::init();
		DEF_Core_Chat_Attribution::init();

		// Schedule log cleanup cron.
		DEF_Core_Logger::schedule_cleanup();

		// Register deactivation hook for logger cleanup.
		register_deactivation_hook( DEF_CORE_PLUGIN_DIR . 'def-core.php', array( 'DEF_Core_Logger', 'unschedule_cleanup' ) );

		// Register [def_chat_button] shortcode and action hook.
		add_shortcode( 'def_chat_button', array( $this, 'shortcode_chat_button' ) );
		add_action( 'def_core_chat_button', array( $this, 'action_chat_button' ) );

		// Register AJAX handlers for inline login (Loop 6).
		add_action( 'wp_ajax_nopriv_def_core_inline_login', array( $this, 'ajax_inline_login' ) );
		add_action( 'wp_ajax_def_core_inline_login', array( $this, 'ajax_inline_login' ) );

		// Register activation hook.
		register_activation_hook( DEF_CORE_PLUGIN_DIR . 'def-core.php', array( __CLASS__, 'on_activate' ) );

		// Add settings link to plugin action links.
		add_filter( 'plugin_action_links_' . plugin_basename( DEF_CORE_PLUGIN_DIR . 'def-core.php' ), array( $this, 'add_settings_link' ) );

		// Check for version upgrade (D-II capabilities).
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
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
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-encryption.php';
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-jwt.php';
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-cache.php';
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-admin.php';
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-tools.php';

		// API Registry (must be loaded before routes).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-api-registry.php';

		// GitHub-based auto-updater (available to def-core and all module plugins).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-github-updater.php';

		// Tool base class (for modules).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/tools/class-def-core-tool-base.php';

		// Built-in conditional tools (auto-register when their plugin is active).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/tools/class-def-core-tool-bbpress-tickets.php';

		// Staff AI frontend.
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-staff-ai.php';

		// Escalation email bridge.
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-escalation.php';

		// Theme color detection utility.
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-theme-colors.php';

		// Connection config (receive pushed config from DEFHO).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-connection-config.php';

		// OAuth client (one-click DEFHO connect).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-oauth.php';

		// Shared HMAC auth (used by export + admin API routes).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-hmac-auth.php';

		// Structured logger + logs viewer page.
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-logger.php';
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-logs-page.php';

		// Knowledge export endpoints.
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-export.php';
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-search-export.php';
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-knowledge-export.php';
		// Page Context Build Plan V1.1 Sub-PR C: server-side page-context
		// detection helpers; consumed by wp_localize_script (frontend) to
		// produce window.DefCorePageContext.
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-page-context.php';

		// Per-item exclusion from knowledge ingestion (v3.1.0).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-knowledge-exclusion.php';

		// Admin REST API (settings, users, connection, chat proxy).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-admin-api.php';

		// Site Intelligence Tools — REST passthrough for Staff AI.
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-site-tools.php';

		// Routes (registers core tools and allows modules to register).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-routes.php';

		// Chat-driven sale attribution (stamps _def_chat_id onto orders).
		require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-chat-attribution.php';

		// Plugin inited action hook.
		add_action(
			'plugins_loaded',
			function () {
				do_action( 'def_core_inited' );
			}
		);

		// Instantiate built-in conditional tools (auto-register via should_register()).
		add_action(
			'plugins_loaded',
			function () {
				new DEF_Core_Tool_BbPress_Tickets();
			},
			20
		);

		// GitHub auto-updater for def-core itself.
		new DEF_Core_GitHub_Updater( array(
			'file'    => DEF_CORE_PLUGIN_DIR . 'def-core.php',
			'repo'    => 'a3rev-ai/def-core',
			'slug'    => 'def-core',
			'asset'   => 'digital-employees.zip',
			'version' => DEF_CORE_VERSION,
		) );
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
		// Grant def_admin_access to administrators.
		self::ensure_def_admin_capability();
		// Set button colors from theme if not already configured.
		self::maybe_set_theme_button_colors();
		// Create log table and schedule cleanup.
		DEF_Core_Logger::create_table();
		DEF_Core_Logger::schedule_cleanup();
		update_option( 'def_core_db_version', '2.2.0' );
	}

	/**
	 * Check for version upgrade and run required migrations.
	 */
	public static function maybe_upgrade(): void {
		$current = get_option( 'def_core_db_version', '0' );
		if ( version_compare( $current, '2.1.0', '<' ) ) {
			self::ensure_def_admin_capability();
			update_option( 'def_core_db_version', '2.1.0' );
		}
		if ( version_compare( $current, '2.2.0', '<' ) ) {
			DEF_Core_Logger::create_table();
			self::ensure_def_admin_capability();
			update_option( 'def_core_db_version', '2.2.0' );
		}
	}

	/**
	 * Ensure DEF admin capability exists.
	 * Grants def_admin_access to all current administrators.
	 * Includes lockout prevention with V1.1 fallback chain.
	 */
	public static function ensure_def_admin_capability(): void {
		$admins = get_users( array(
			'capability' => 'manage_options',
			'fields'     => 'ids',
		) );

		foreach ( $admins as $user_id ) {
			$user = new \WP_User( $user_id );
			if ( ! $user->has_cap( 'def_admin_access' ) ) {
				$user->add_cap( 'def_admin_access' );
			}
		}

		// Lockout prevention: at least one user must have the capability.
		$def_admins = get_users( array(
			'capability' => 'def_admin_access',
			'fields'     => 'ids',
			'number'     => 1,
		) );

		if ( empty( $def_admins ) ) {
			// Fallback 1: admin_email user.
			$admin_email = get_option( 'admin_email' );
			$admin_user  = get_user_by( 'email', $admin_email );

			if ( $admin_user ) {
				$admin_user->add_cap( 'def_admin_access' );
			} else {
				// V1.1 fallback: first Administrator by user ID ascending.
				$fallback = get_users( array(
					'role'    => 'administrator',
					'orderby' => 'ID',
					'order'   => 'ASC',
					'number'  => 1,
				) );
				if ( ! empty( $fallback ) ) {
					$fallback[0]->add_cap( 'def_admin_access' );
				}
			}
		}
	}

	/**
	 * Register assets (available everywhere, but not enqueued).
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'digital-employees', false, dirname( plugin_basename( DEF_CORE_PLUGIN_DIR . 'def-core.php' ) ) . '/languages/' );
	}

	/**
	 * Register all plugin assets.
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

		// V2 agent persona helper — shared across all three channels (Customer
		// Chat, Staff AI, Setup Assistant). Renders the "X is helping" divider
		// + persistent thinking-row prefix when an Orchestrator V2 specialist
		// takes over from the Concierge. window.DefPersona global, IIFE-loaded.
		wp_register_script(
			'def-core-persona',
			DEF_CORE_PLUGIN_URL . 'assets/js/def-persona.js',
			array(),
			DEF_CORE_VERSION,
			array( 'in_footer' => true )
		);

		// Page Context Build Plan V1.1 Sub-PR C: client-side capture +
		// sessionStorage trail accumulation + chat-payload builder.
		// Loaded on the frontend BEFORE the customer-chat module so
		// window.DefCorePageContextHelper is available when the chat
		// builds its message body.
		wp_register_script(
			'def-core-page-context',
			DEF_CORE_PLUGIN_URL . 'assets/js/def-core-page-context.js',
			array(),
			DEF_CORE_VERSION,
			array( 'in_footer' => true )
		);

		// Native Customer Chat loader (enqueued on frontend).
		// Depends on def-core-persona so window.DefPersona is available
		// before the lazy-loaded chat module fetches. Also depends on
		// def-core-page-context so window.DefCorePageContextHelper is
		// loaded + window.DefCorePageContext is populated before any
		// chat message is sent.
		wp_register_script(
			'def-core-customer-chat-loader',
			DEF_CORE_PLUGIN_URL . 'assets/js/def-core-customer-chat-loader.js',
			array( 'def-core-persona', 'def-core-page-context' ),
			DEF_CORE_VERSION,
			array( 'in_footer' => true )
		);

		// Trigger-button styles for the [def_chat_button] shortcode and the
		// def_core_chat_button action hook. The floating trigger button
		// lives inside the chat Shadow DOM and gets its styles from the
		// loader's inline <style> block — those styles cannot reach
		// shortcode-placed buttons in the main document. This stylesheet
		// mirrors the floating button's design so both render identically.
		wp_register_style(
			'def-core-chat-trigger',
			DEF_CORE_PLUGIN_URL . 'assets/css/def-chat-trigger.css',
			array(),
			DEF_CORE_VERSION
		);

		// Full chat module (lazy-loaded by loader, NOT enqueued directly).
		wp_register_script(
			'def-core-customer-chat',
			DEF_CORE_PLUGIN_URL . 'assets/js/def-core-customer-chat.js',
			array(),
			DEF_CORE_VERSION,
			array( 'in_footer' => true )
		);

		// Chat styles (injected into Shadow DOM by loader, NOT enqueued directly).
		wp_register_style(
			'def-core-customer-chat',
			DEF_CORE_PLUGIN_URL . 'assets/css/def-core-customer-chat.css',
			array(),
			DEF_CORE_VERSION
		);

		// Vendor libraries for customer chat (lazy-loaded by loader, NOT enqueued directly).
		wp_register_script(
			'def-core-marked',
			DEF_CORE_PLUGIN_URL . 'assets/js/vendor/marked.min.js',
			array(),
			'15.0.12',
			array( 'in_footer' => true )
		);
		wp_register_script(
			'def-core-purify',
			DEF_CORE_PLUGIN_URL . 'assets/js/vendor/purify.min.js',
			array(),
			'3.1.6',
			array( 'in_footer' => true )
		);

		// Setup Assistant drawer assets.
		wp_register_style(
			'def-core-setup-assistant',
			DEF_CORE_PLUGIN_URL . 'assets/css/setup-assistant-drawer.css',
			array( 'def-core-admin' ),
			DEF_CORE_VERSION
		);
		wp_register_script(
			'def-core-setup-assistant',
			DEF_CORE_PLUGIN_URL . 'assets/js/setup-assistant-drawer.js',
			array( 'def-core-admin', 'def-core-persona' ),
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
		if ( is_admin() ) {
			return;
		}

		$current_user   = wp_get_current_user();
		$user_first_name = ( $current_user->ID > 0 )
			? ( $current_user->first_name ?: $current_user->display_name )
			: '';

		$rest_data = array(
			// Existing keys.
			'restUrl'        => esc_url_raw( rest_url( DEF_CORE_API_NAME_SPACE . '/context-token' ) ),
			'chatStreamUrl'  => esc_url_raw( rest_url( DEF_CORE_API_NAME_SPACE . '/chat/stream' ) ),
			'uploadInitUrl'  => esc_url_raw( rest_url( DEF_CORE_API_NAME_SPACE . '/uploads/init' ) ),
			'uploadCommitUrl' => esc_url_raw( rest_url( DEF_CORE_API_NAME_SPACE . '/uploads/commit' ) ),
			'loginUrl'       => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'siteUrl'        => esc_url_raw( home_url() ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'allowedOrigins' => $this->get_allowed_origins(),
			// User context.
			'isLoggedIn'     => is_user_logged_in() ? '1' : '',
			'userFirstName'  => $user_first_name,
			// Branding.
			'displayName'    => get_option( 'def_core_display_name', get_bloginfo( 'name' ) ),
			'logoUrl'        => $this->get_logo_url_for_frontend(),
			'heroImageUrl'        => $this->get_hero_image_url_for_frontend( 'desktop' ),
			'heroImageMobileUrl'  => $this->get_hero_image_url_for_frontend( 'mobile' ),
			'logoShow'       => '0' !== get_option( 'def_core_logo_show_customer_chat', '1' ),
			'logoMaxHeight'  => (int) get_option( 'def_core_logo_max_height', 40 ),
			// Chat settings.
			'chatDisplayMode'      => get_option( 'def_core_chat_display_mode', 'modal' ),
			'chatDrawerWidth'      => (int) get_option( 'def_core_chat_drawer_width', 400 ),
			'chatSpotlightWidth'   => (int) get_option( 'def_core_chat_spotlight_width', 960 ),
			'chatSpotlightHeight'  => (int) get_option( 'def_core_chat_spotlight_height', 600 ),
			// Button appearance.
			'buttonPosition'  => get_option( 'def_core_chat_button_position', 'right' ),
			'buttonColor'     => get_option( 'def_core_chat_button_color', '#111827' ),
			'buttonHoverColor' => get_option( 'def_core_chat_button_hover_color', '' ),
			'buttonIcon'      => get_option( 'def_core_chat_button_icon', 'chat' ),
			'buttonIconUrl'   => $this->get_button_icon_url(),
			'buttonLabel'     => get_option( 'def_core_chat_button_label', 'Chat' ),
			'showFloatingButton' => '0' !== get_option( 'def_core_chat_show_floating', '1' ),
			// WooCommerce context.
			'wooActive'       => ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) ? '1' : '',
			// AI Disclosure Notice — master toggle + link fields (rendered
			// at the bottom of the chat panel). `aiNoticeEnabled` gates
			// the entire footer; when off, no footer renders regardless
			// of complianceText / privacyUrl / privacyLinkLabel.
			'aiNoticeEnabled'  => '0' !== get_option( 'def_core_chat_ai_notice', '0' ),
			'privacyUrl'       => get_option( 'def_core_chat_privacy_url', '' ),
			// Coalesce empty saved value to the same placeholder the admin
			// Preview uses, so a cleared field still renders sensibly on the
			// frontend instead of falling through to a different default.
			'privacyLinkLabel' => get_option( 'def_core_chat_privacy_link_label', '' ) ?: __( 'Terms & Conditions', 'digital-employees' ),
			// Welcome state polish (v2.7.0 chips + v2.9.0 per-chip intros).
			'welcomeChip1'        => get_option( 'def_core_chat_welcome_chip_1', '' ),
			'welcomeChip1Intro'   => get_option( 'def_core_chat_welcome_chip_1_intro', '' ),
			'welcomeChip2'        => get_option( 'def_core_chat_welcome_chip_2', '' ),
			'welcomeChip2Intro'   => get_option( 'def_core_chat_welcome_chip_2_intro', '' ),
			'welcomeChip3'        => get_option( 'def_core_chat_welcome_chip_3', '' ),
			'welcomeChip3Intro'   => get_option( 'def_core_chat_welcome_chip_3_intro', '' ),
			// AI Disclosure Notice — the lead sentence. The trailing link
			// words are in privacyLinkLabel and rendered separately.
			// Coalesce empty saved value to the placeholder default so a
			// cleared field still renders the footer (matches admin Preview)
			// instead of suppressing the whole footer at runtime.
			'complianceText'  => get_option( 'def_core_chat_compliance_text', '' ) ?: __( 'AI responses may be inaccurate. By using this assistant, you agree to our', 'digital-employees' ),
			// API URL for direct fetch.
			'apiBaseUrl'      => self::get_def_api_url(),
			// WP REST URL + nonce for same-origin calls (escalation send).
			'wpRestUrl'       => esc_url_raw( rest_url( DEF_CORE_API_NAME_SPACE . '/' ) ),
			'wpRestNonce'     => wp_create_nonce( 'wp_rest' ),
			// Bare /wp-json/ root, for cross-namespace REST calls (e.g.
			// WooCommerce Store API at wc/store/v1/cart/add-item).
			'wpRestRoot'      => esc_url_raw( rest_url( '' ) ),
			// WC Store API uses its own nonce action ("wc_store_api") and
			// requires a `Nonce` header on every write, including the first.
			// On successful responses the server rotates the nonce via the
			// `Nonce` response header — the widget tracks that and uses the
			// latest on subsequent calls. This is just the bootstrap value.
			'wcStoreApiNonce' => wp_create_nonce( 'wc_store_api' ),
			// Asset URLs for lazy loading (versioned for cache-busting).
			'chatModuleUrl'   => DEF_CORE_PLUGIN_URL . 'assets/js/def-core-customer-chat.js?ver=' . DEF_CORE_VERSION,
			'chatStyleUrl'    => DEF_CORE_PLUGIN_URL . 'assets/css/def-core-customer-chat.css?ver=' . DEF_CORE_VERSION,
			'markedUrl'       => DEF_CORE_PLUGIN_URL . 'assets/js/vendor/marked.min.js?ver=' . DEF_CORE_VERSION,
			'purifyUrl'       => DEF_CORE_PLUGIN_URL . 'assets/js/vendor/purify.min.js?ver=' . DEF_CORE_VERSION,
			// V1.2 Result Cards renderer — loaded after purify, before chat module.
			'productCardsScriptUrl' => DEF_CORE_PLUGIN_URL . 'assets/js/def-core-product-cards.js?ver=' . DEF_CORE_VERSION,
			'productCardsStyleUrl'  => DEF_CORE_PLUGIN_URL . 'assets/css/def-core-product-cards.css?ver=' . DEF_CORE_VERSION,
			// i18n strings.
			'strings'         => $this->get_chat_strings(),
		);

		// Enqueue native loader (replaces old bridge script).
		wp_localize_script( 'def-core-customer-chat-loader', 'DEFCore', $rest_data );

		// Page Context Build Plan V1.1 Sub-PR C: inject the page-context
		// payload as `window.DefCorePageContext`. JS overrides
		// `canonical_path` from `window.location.pathname` at mount time
		// and adds `referrer_path` at submit time, but every other field
		// is PHP-derived (page type, IDs, queried taxonomy, terms, title,
		// language).
		//
		// v3.4.1 fix: use wp_add_inline_script with wp_json_encode rather
		// than wp_localize_script. WP's localize routine casts every TOP-
		// level scalar value to a string before output (see
		// WP_Scripts::localize), which mangles integer fields like page_id
		// and product_id. The inline-script + json_encode path preserves
		// types end-to-end — integers stay integers, nulls stay nulls.
		// The page-context script (loaded BEFORE the customer-chat module
		// via its dependency entry) reads window.DefCorePageContext on
		// mount; nothing else needs to change.
		if ( class_exists( 'DEF_Core_Page_Context' ) ) {
			$payload = DEF_Core_Page_Context::build_payload();
			wp_add_inline_script(
				'def-core-page-context',
				'window.DefCorePageContext = ' . wp_json_encode( $payload ) . ';',
				'before'
			);
		}

		wp_enqueue_script( 'def-core-customer-chat-loader' );

		// Trigger-button styles for shortcode/hook-placed buttons. The CSS
		// uses var(--def-chat-btn-color) / var(--def-chat-btn-hover); the
		// inline-style below sets those variables from the same admin
		// options that drive the floating button.
		wp_enqueue_style( 'def-core-chat-trigger' );
		$btn_color = get_option( 'def_core_chat_button_color', '#111827' );
		$btn_hover = get_option( 'def_core_chat_button_hover_color', '' );
		if ( '' === $btn_hover ) {
			$btn_hover = $btn_color;
		}
		wp_add_inline_style(
			'def-core-chat-trigger',
			sprintf(
				':root{--def-chat-btn-color:%s;--def-chat-btn-hover:%s;}',
				esc_attr( $btn_color ),
				esc_attr( $btn_hover )
			)
		);

		// Cart sync (unchanged).
		if ( $this->should_enqueue_cart_sync() ) {
			wp_enqueue_script( 'def-core-cart-sync' );
		}
	}

	/**
	 * Check if cart sync script should be enqueued.
	 *
	 * Note: def-core-cart-sync.js is the iframe integration helper used
	 * for embedded-widget deployments where the chat lives in an iframe
	 * separate from the storefront. It listens for postMessage events
	 * from the parent page. The widget itself (def-core-customer-chat.js)
	 * no longer drives cart sync since v2.4.0 — Store API's Cart-Token
	 * handles guest sessions directly. Kept enqueued for any iframe-
	 * integrated tenants that still rely on the helper. Candidate for
	 * deletion in a follow-up if no live tenants are using it.
	 *
	 * @return bool True if WooCommerce is installed and active.
	 * @since 0.2.0
	 */
	private function should_enqueue_cart_sync(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
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
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'digital-employees' ) . '</a>';
		return $links;
	}

	/**
	 * Handle inline login AJAX request (Loop 6).
	 *
	 * Uses standard WordPress authentication via wp_signon().
	 * This respects any login plugins (2FA, reCAPTCHA, SSO).
	 *
	 * @since 0.3.0
	 */
	public function ajax_inline_login(): void {
		// Verify nonce.
		if ( ! check_ajax_referer( 'wp_rest', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'digital-employees' ) ) );
			return;
		}

		// Get credentials.
		$username = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '';
		$password = isset( $_POST['pwd'] ) ? $_POST['pwd'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $username ) || empty( $password ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter both username and password.', 'digital-employees' ) ) );
			return;
		}

		// Attempt login using WordPress standard authentication.
		$creds = array(
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => true,
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			// Login failed.
			$error_message = $user->get_error_message();
			// Sanitize error message - don't reveal too much.
			if ( strpos( $error_message, 'username' ) !== false || strpos( $error_message, 'password' ) !== false ) {
				$error_message = __( 'Login failed — please check your details and try again.', 'digital-employees' );
			}
			wp_send_json_error( array( 'message' => $error_message ) );
			return;
		}

		// Login successful - set the auth cookie.
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );

		// Generate a context token for the newly logged-in user.
		// We do this here because the auth cookie won't be available for
		// subsequent JS fetch calls until the browser processes this response.
		$claims = array(
			'sub'          => (string) $user->ID,
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'first_name'   => $user->user_firstname,
			'email'        => $user->user_email,
			'roles'        => array_values( (array) $user->roles ),
			'iss'          => get_site_url(),
			'aud'          => DEF_CORE_AUDIENCE,
		);
		$token = DEF_Core_JWT::issue_token( $claims, 300 ); // 5 minutes.

		wp_send_json_success(
			array(
				'user_id' => $user->ID,
				'token'   => $token,
			)
		);
	}

	// ─── Customer Chat: Shortcode + Action Hook ──────────────────

	/**
	 * Shortcode: [def_chat_button label="Chat with us" class="my-class" icon="sparkle"]
	 *
	 * Per-instance attributes override the corresponding Chat Settings
	 * options. When `label`, `icon` are omitted, the saved option values
	 * (`def_core_chat_button_label`, `def_core_chat_button_icon`,
	 * `def_core_chat_button_icon_id`) drive rendering — same surface as
	 * the floating button.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string Button HTML.
	 */
	public function shortcode_chat_button( $atts ): string {
		$atts = shortcode_atts( array(
			'label' => '',
			'class' => '',
			'icon'  => '',
		), $atts, 'def_chat_button' );

		// Resolve from the shortcode attr first, then fall back to the
		// Chat Settings option, then a sensible default.
		$label = '' !== $atts['label']
			? $atts['label']
			: get_option( 'def_core_chat_button_label', __( 'Chat', 'digital-employees' ) );
		$icon  = '' !== $atts['icon']
			? $atts['icon']
			: get_option( 'def_core_chat_button_icon', 'chat' );

		$classes = 'def-chat-trigger-btn';
		if ( ! empty( $atts['class'] ) ) {
			$classes .= ' ' . sanitize_html_class( $atts['class'], '' );
		}

		return sprintf(
			'<button type="button" class="%s" data-def-chat-trigger>%s<span class="def-chat-trigger-btn-label">%s</span></button>',
			esc_attr( $classes ),
			$this->render_chat_button_icon( $icon ),
			esc_html( $label )
		);
	}

	/**
	 * Render the icon markup for the trigger button. Mirrors the SVG paths
	 * the floating button uses (def-core-customer-chat-loader.js, see
	 * createTrigger / icon switch). Same icon set, same proportions, same
	 * sparkle hover animation.
	 *
	 * @param string $icon Icon key: 'chat', 'headset', 'sparkle', 'custom', 'none'.
	 * @return string Icon HTML (already escaped where needed). Empty for 'none'.
	 */
	private function render_chat_button_icon( string $icon ): string {
		switch ( $icon ) {
			case 'custom':
				$icon_id  = (int) get_option( 'def_core_chat_button_icon_id', 0 );
				$icon_url = $icon_id ? wp_get_attachment_image_url( $icon_id, 'thumbnail' ) : '';
				if ( ! $icon_url ) {
					return '';
				}
				return '<span class="def-chat-trigger-btn-icon">'
					. '<img src="' . esc_url( $icon_url ) . '" alt="" aria-hidden="true">'
					. '</span>';

			case 'headset':
				return '<span class="def-chat-trigger-btn-icon">'
					. '<svg viewBox="0 0 24 24" aria-hidden="true">'
					. '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/>'
					. '<path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>'
					. '</svg>'
					. '</span>';

			case 'sparkle':
				return '<span class="def-chat-trigger-btn-icon def-chat-trigger-btn-icon--sparkle">'
					. '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true">'
					. '<path d="M12 2C12.7 6.3 13.2 8.2 15 10C16.8 11.8 18.7 12.3 23 13C18.7 13.7 16.8 14.2 15 16C13.2 17.8 12.7 19.7 12 24C11.3 19.7 10.8 17.8 9 16C7.2 14.2 5.3 13.7 1 13C5.3 12.3 7.2 11.8 9 10C10.8 8.2 11.3 6.3 12 2Z"/>'
					. '<path d="M20 1C20.3 2.6 20.5 3.2 21 3.7C21.5 3.2 21.7 2.6 22 1C21.7 2.6 21.5 3.2 21 3.7C20.5 3.2 20.3 2.6 20 1Z"/>'
					. '<path d="M3 19C3.2 20 3.4 20.4 3.7 20.7C4 20.4 4.2 20 4.4 19C4.2 20 4 20.4 3.7 20.7C3.4 20.4 3.2 20 3 19Z"/>'
					. '</svg>'
					. '</span>';

			case 'none':
				return '';

			case 'chat':
			default:
				return '<span class="def-chat-trigger-btn-icon">'
					. '<svg viewBox="0 0 24 24" aria-hidden="true">'
					. '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'
					. '</svg>'
					. '</span>';
		}
	}

	/**
	 * Action hook: <?php do_action('def_core_chat_button', ['label' => 'Chat']); ?>
	 *
	 * @param array $args Button arguments.
	 */
	public function action_chat_button( $args = array() ): void {
		$args = wp_parse_args( $args, array(
			'label' => __( 'Chat', 'digital-employees' ),
			'class' => '',
			'icon'  => 'none',
		) );
		echo $this->shortcode_chat_button( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// ─── Customer Chat: Private Helpers ──────────────────────────

	/**
	 * Get logo URL for frontend (image URL, not HTML).
	 * Chain: def_core_logo_id → custom_logo → null.
	 *
	 * @return string|null Logo URL or null.
	 */
	private function get_logo_url_for_frontend(): ?string {
		$logo_id = (int) get_option( 'def_core_logo_id', 0 );
		if ( $logo_id ) {
			$url = wp_get_attachment_image_url( $logo_id, 'medium' );
			if ( $url ) {
				return $url;
			}
		}
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$url = wp_get_attachment_image_url( (int) $custom_logo_id, 'medium' );
			if ( $url ) {
				return $url;
			}
		}
		return null;
	}

	/**
	 * Get the welcome banner image URL for the customer chat (browser-side).
	 * Optional — empty string if no banner is configured.
	 *
	 * Two variants: desktop (~5:1 wide strip, up to 960px wide in Spotlight)
	 * and mobile (~2.7:1 chunkier banner, up to ~480px wide on phones).
	 * Bunnings uses two separate images for the two viewport classes —
	 * the desktop strip is too short on mobile, the mobile banner is too
	 * tall on desktop. The widget renders a `<picture>` element with both
	 * sources when both are configured.
	 *
	 * @param string $variant 'desktop' or 'mobile'.
	 * @return string Banner URL or empty string.
	 */
	private function get_hero_image_url_for_frontend( string $variant = 'desktop' ): string {
		$option_key = 'mobile' === $variant
			? 'def_core_chat_hero_image_mobile_id'
			: 'def_core_chat_hero_image_id';
		$banner_id  = (int) get_option( $option_key, 0 );
		if ( ! $banner_id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $banner_id, 'large' );
		if ( ! $url ) {
			$url = wp_get_attachment_image_url( $banner_id, 'full' );
		}
		return $url ? $url : '';
	}

	/**
	 * Get Python backend API URL for customer chat (browser-side).
	 *
	 * The stored def_core_staff_ai_api_url is for server-side PHP proxy calls
	 * and may use Docker internal hostnames (e.g. http://def-api:8000).
	 * Browser-facing channels (Customer Chat, Setup Assistant SSE) use
	 * direct fetch(), so they need a browser-accessible URL. Replace
	 * non-routable Docker hostnames with the current site's hostname.
	 *
	 * @return string API base URL or empty string.
	 */
	public static function get_def_api_url(): string {
		// Environment-aware: wp-config.php define takes priority,
		// then stored option, then default production URL.
		// Either source may carry a Docker-internal hostname (e.g. "def-api"),
		// so the rewrite below applies uniformly to both.
		if ( defined( 'DEF_API_URL' ) && DEF_API_URL ) {
			$url = rtrim( DEF_API_URL, '/' );
		} else {
			$url = get_option( 'def_core_staff_ai_api_url', '' );
			if ( empty( $url ) ) {
				return 'https://api.defho.ai';
			}
			$url = rtrim( $url, '/' );
		}

		// If the URL host is not browser-routable (Docker internal name),
		// replace it with the current site's hostname.
		$parsed = wp_parse_url( $url );
		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return $url;
		}

		$host = $parsed['host'];

		// Localhost, IPs, and real domains are browser-routable.
		if (
			'localhost' === $host ||
			'127.0.0.1' === $host ||
			filter_var( $host, FILTER_VALIDATE_IP ) ||
			preg_match( '/\.[a-z]{2,}$/i', $host )
		) {
			return $url;
		}

		// Docker internal hostname (e.g. "def-api") — swap with site host.
		$site_parsed = wp_parse_url( home_url() );
		$site_host   = $site_parsed['host'] ?? 'localhost';

		$scheme = $parsed['scheme'] ?? 'http';
		$port   = ! empty( $parsed['port'] ) ? ':' . $parsed['port'] : '';
		$path   = $parsed['path'] ?? '';

		return $scheme . '://' . $site_host . $port . $path;
	}

	/**
	 * Get the DEF API URL for server-side (PHP) proxy requests.
	 *
	 * Unlike get_def_api_url() which rewrites Docker hostnames for browser
	 * routing, this returns the raw stored URL — suitable for cURL calls
	 * from WordPress to DEF within the Docker network.
	 *
	 * @return string API base URL for server-side requests.
	 */
	public static function get_def_api_url_internal(): string {
		if ( defined( 'DEF_API_URL' ) && DEF_API_URL ) {
			return rtrim( DEF_API_URL, '/' );
		}

		$url = get_option( 'def_core_staff_ai_api_url', '' );
		if ( empty( $url ) ) {
			return 'https://api.defho.ai';
		}

		return rtrim( $url, '/' );
	}

	/**
	 * Get custom button icon URL from media library.
	 *
	 * @return string|null Icon URL or null.
	 */
	private function get_button_icon_url(): ?string {
		$icon = get_option( 'def_core_chat_button_icon', 'chat' );
		if ( 'custom' !== $icon ) {
			return null;
		}
		$id = (int) get_option( 'def_core_chat_button_icon_id', 0 );
		if ( $id <= 0 ) {
			return null;
		}
		$url = wp_get_attachment_image_url( $id, 'thumbnail' );
		return $url ?: null;
	}

	/**
	 * Get i18n strings for chat widget.
	 *
	 * @return array Translatable strings.
	 */
	private function get_chat_strings(): array {
		$strings = array(
			'clearChat'            => __( 'Clear conversation & start fresh', 'digital-employees' ),
			'clearConfirmTitle'    => __( 'Clear conversation?', 'digital-employees' ),
			'clearConfirmDesc'     => __( 'This will clear your current conversation. This action cannot be undone.', 'digital-employees' ),
			'clearConfirmYes'      => __( 'Clear & start fresh', 'digital-employees' ),
			'cancel'               => __( 'Cancel', 'digital-employees' ),
			'typePlaceholder'      => __( 'Type your message...', 'digital-employees' ),
			'greeting'             => __( 'Hello! How can I help you today?', 'digital-employees' ),
			'sending'              => __( 'Sending...', 'digital-employees' ),
			'login'                => __( 'Log in', 'digital-employees' ),
			'loginTitle'           => __( 'Log in to continue', 'digital-employees' ),
			'loginSubmit'          => __( 'Log in', 'digital-employees' ),
			'sessionExpired'       => __( 'Session expired — please log in again', 'digital-employees' ),
			'escalate'             => __( 'Request Human Support', 'digital-employees' ),
			'escalateSubmit'       => __( 'Send', 'digital-employees' ),
			'escalateSuccess'      => __( 'Your email has been sent.', 'digital-employees' ),
			'uploadFailed'         => __( 'Upload failed. Please try again.', 'digital-employees' ),
			'fileTooLarge'         => __( 'File too large — maximum 10MB', 'digital-employees' ),
			'fileTypeNotSupported' => __( 'File type not supported', 'digital-employees' ),
			'offlineTitle'         => __( 'Chat is currently unavailable', 'digital-employees' ),
			'offlineMessage'       => __( 'This feature is being set up. Please check back soon.', 'digital-employees' ),
			'privacyPolicy'        => __( 'Privacy Policy', 'digital-employees' ),
			'connectionError'      => __( 'Unable to connect. Please try again.', 'digital-employees' ),
			'connectionLost'       => __( 'Connection lost. Retrying...', 'digital-employees' ),
			'rateLimited'          => __( 'Please wait a moment before sending another message', 'digital-employees' ),
		);
		return apply_filters( 'def_core_chat_strings', $strings );
	}

	// ─── Theme Color Detection (delegates to DEF_Core_Theme_Colors) ─────

	/**
	 * Set button colors from the active theme on first activation.
	 */
	public static function maybe_set_theme_button_colors(): void {
		DEF_Core_Theme_Colors::maybe_set_defaults();
	}

	/**
	 * Detect button colors from the active WordPress theme.
	 */
	public static function detect_theme_button_colors(): array {
		return DEF_Core_Theme_Colors::detect();
	}
}

// Initialize the plugin.
DEF_Core::instance();
