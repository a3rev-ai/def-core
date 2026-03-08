<?php
/**
 * Class DEF_Core_Admin
 *
 * Admin settings page for the Digital Employee Framework - Core plugin.
 * Phase 7 D-I: Tabbed layout with 6 tabs, AJAX save, connection status test.
 *
 * @package def-core
 * @since 2.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Admin {

	/**
	 * Per-tab field allowlists with sanitizers.
	 * Keys accepted per tab — unknown keys are rejected. (V1.1)
	 *
	 * @var array<string, array<string, array>>
	 */
	private static $tab_allowlists = array(
		'branding'         => array(
			'def_core_logo_id'                 => array(
				'type'     => 'int',
				'sanitize' => 'sanitize_logo_id',
			),
			'def_core_display_name'            => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_display_name',
			),
			'def_core_logo_show_staff_ai'      => array(
				'type'     => 'bool',
				'sanitize' => 'sanitize_bool_setting',
			),
			'def_core_logo_show_customer_chat' => array(
				'type'     => 'bool',
				'sanitize' => 'sanitize_bool_setting',
			),
			'def_core_logo_max_height'         => array(
				'type'     => 'int',
				'sanitize' => 'sanitize_logo_max_height',
			),
			'def_core_app_icon_id'             => array(
				'type'     => 'int',
				'sanitize' => 'sanitize_logo_id',
			),
		),
		'chat-settings'    => array(
			'def_core_chat_display_mode' => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_chat_display_mode',
			),
			'def_core_chat_drawer_width' => array(
				'type'     => 'int',
				'sanitize' => 'sanitize_drawer_width',
			),
			'def_core_chat_button_position' => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_button_position',
			),
			'def_core_chat_button_color' => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_hex_color',
			),
			'def_core_chat_button_hover_color' => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_hex_color',
			),
			'def_core_chat_button_icon' => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_button_icon',
			),
			'def_core_chat_button_label' => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_button_label',
			),
			'def_core_chat_button_icon_id' => array(
				'type'     => 'int',
				'sanitize' => 'sanitize_logo_id',
			),
			'def_core_chat_show_floating' => array(
				'type'     => 'bool',
				'sanitize' => 'sanitize_bool_setting',
			),
			'def_core_chat_ai_notice' => array(
				'type'     => 'bool',
				'sanitize' => 'sanitize_bool_setting',
			),
			'def_core_chat_privacy_url' => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_privacy_url',
			),
		),
	);

	/**
	 * Initialize the admin functionality.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_def_core_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_def_core_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_def_core_save_user_roles', array( __CLASS__, 'ajax_save_user_roles' ) );
		add_action( 'wp_ajax_def_core_search_users', array( __CLASS__, 'ajax_search_users' ) );
		add_action( 'wp_ajax_def_core_remove_user_roles', array( __CLASS__, 'ajax_remove_user_roles' ) );
		add_action( 'wp_ajax_def_core_test_escalation_email', array( __CLASS__, 'ajax_test_escalation_email' ) );
	}

	/**
	 * Add the settings page under Settings menu.
	 */
	public static function add_settings_page(): void {
		add_menu_page(
			__( 'Digital Employees', 'digital-employees' ),
			__( 'Digital Employees', 'digital-employees' ),
			'def_admin_access',
			'def-core',
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-groups',
			81
		);

		// Rename the auto-created first submenu from "Digital Employees" to "Settings".
		add_submenu_page(
			'def-core',
			__( 'Settings', 'digital-employees' ),
			__( 'Settings', 'digital-employees' ),
			'def_admin_access',
			'def-core'
		);

		// Add "Open Staff AI" link — opens in new tab via JS (see admin_footer hook).
		add_submenu_page(
			'def-core',
			__( 'Staff AI', 'digital-employees' ),
			__( 'Open Staff AI', 'digital-employees' ),
			'def_staff_access',
			'def-core-staff-ai',
			'__return_null'
		);

		// Redirect the Staff AI submenu slug to the actual /staff-ai frontend URL.
		add_action( 'admin_footer', array( __CLASS__, 'staff_ai_submenu_redirect' ) );
	}

	/**
	 * Output inline JS to make the "Open Staff AI" submenu link open /staff-ai in a new tab.
	 */
	public static function staff_ai_submenu_redirect(): void {
		$staff_ai_url = home_url( '/staff-ai/' );
		?>
		<script>
		(function() {
			var link = document.querySelector('a[href="admin.php?page=def-core-staff-ai"]');
			if (link) {
				link.href = <?php echo wp_json_encode( $staff_ai_url ); ?>;
				link.target = '_blank';
				link.rel = 'noopener';
			}
		})();
		</script>
		<?php
	}

	/**
	 * Register settings with WordPress.
	 * Kept for option whitelisting and sanitize callbacks.
	 */
	public static function register_settings(): void {
		register_setting( 'def_core_settings', 'def_core_tools_status', array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize_tools_status' ),
			'default'           => array(),
			'show_in_rest'      => false,
		) );
	}

	// ─── Page Rendering ──────────────────────────────────────────────

	/**
	 * Render the settings page.
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'def_admin_access' ) ) {
			return;
		}

		// Enqueue admin assets.
		wp_enqueue_style( 'def-core-admin' );
		wp_enqueue_script( 'def-core-admin' );

		// Menu capability already gates access; this is a safety check.
		// No additional permission block needed — user must have def_admin_access to see this page.

		// D-II: Enqueue media uploader for branding tab.
		wp_enqueue_media();

		// Localize script data for JS.
		$cached_connection = get_transient( 'def_core_connection_test' );
		wp_localize_script( 'def-core-admin', 'defCoreAdmin', array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'saveNonce'        => wp_create_nonce( 'def_core_save_settings' ),
			'testNonce'        => wp_create_nonce( 'def_core_test_connection' ),
			'rolesNonce'       => wp_create_nonce( 'def_core_save_user_roles' ),
			'searchUsersNonce' => wp_create_nonce( 'def_core_search_users' ),
			'testEmailNonce'   => wp_create_nonce( 'def_core_test_escalation_email' ),
			'cachedConnection' => $cached_connection ? $cached_connection : null,
		) );

		// Connection status data (for status indicator).
		$conn_api_url  = get_option( 'def_core_staff_ai_api_url', '' );
		$conn_revision = (int) get_option( 'def_core_conn_config_revision', 0 );
		$conn_last_sync = get_option( 'def_core_conn_last_sync_at', '' );

		// Tool registry data.
		// D-II: Branding data.
		$branding = array(
			'logo_id'              => (int) get_option( 'def_core_logo_id', 0 ),
			'display_name'         => get_option( 'def_core_display_name', get_bloginfo( 'name' ) ),
			'logo_show_staff_ai'   => '0' !== get_option( 'def_core_logo_show_staff_ai', '1' ),
			'logo_show_customer_chat' => '0' !== get_option( 'def_core_logo_show_customer_chat', '1' ),
			'logo_max_height'      => (int) get_option( 'def_core_logo_max_height', 40 ),
			'app_icon_id'          => (int) get_option( 'def_core_app_icon_id', 0 ),
			'app_icon_url'         => '',
		);

		// App icon preview URL.
		if ( $branding['app_icon_id'] ) {
			$branding['app_icon_url'] = wp_get_attachment_image_url( $branding['app_icon_id'], 'medium' );
		}

		// D-II: Logo preview URL.
		$logo_url = '';
		if ( $branding['logo_id'] ) {
			$logo_url = wp_get_attachment_image_url( $branding['logo_id'], 'medium' );
		}

		// D-II: Escalation emails (the 'to' address per channel).
		$escalation = array();
		foreach ( array( 'customer', 'setup_assistant' ) as $channel ) {
			$stored = get_option( 'def_core_escalation_' . $channel, array() );
			$escalation[ $channel ] = ! empty( $stored['to'] ) ? implode( ', ', (array) $stored['to'] ) : '';
		}

		// D-II: User roles data — only users with at least one DEF capability.
		$def_capabilities = array( 'def_staff_access', 'def_management_access', 'def_admin_access' );
		$def_user_ids     = array();
		foreach ( $def_capabilities as $cap ) {
			$ids = get_users( array(
				'capability' => $cap,
				'fields'     => 'ids',
			) );
			$def_user_ids = array_merge( $def_user_ids, $ids );
		}
		$def_user_ids = array_unique( array_map( 'intval', $def_user_ids ) );

		$def_users = array();
		if ( ! empty( $def_user_ids ) ) {
			$query = new \WP_User_Query( array(
				'include' => $def_user_ids,
				'orderby' => 'display_name',
				'order'   => 'ASC',
			) );
			$def_users = $query->get_results();
		}

		$def_admin_ids   = get_users( array(
			'capability' => 'def_admin_access',
			'fields'     => 'ids',
		) );
		$def_admin_count = count( $def_admin_ids );

		// D-II: Chat settings.
		$chat_settings = array(
			'display_mode' => get_option( 'def_core_chat_display_mode', 'modal' ),
			'drawer_width' => (int) get_option( 'def_core_chat_drawer_width', 400 ),
		);

		// AI consent notice settings.
		$chat_settings['ai_notice']   = '0' !== get_option( 'def_core_chat_ai_notice', '0' );
		$chat_settings['privacy_url'] = get_option( 'def_core_chat_privacy_url', '' );

		// Button appearance settings.
		$button_settings = array(
			'position'      => get_option( 'def_core_chat_button_position', 'right' ),
			'color'         => get_option( 'def_core_chat_button_color', '#111827' ),
			'hover_color'   => get_option( 'def_core_chat_button_hover_color', '' ),
			'icon'          => get_option( 'def_core_chat_button_icon', 'chat' ),
			'label'         => get_option( 'def_core_chat_button_label', 'Chat' ),
			'icon_id'       => (int) get_option( 'def_core_chat_button_icon_id', 0 ),
			'show_floating' => '0' !== get_option( 'def_core_chat_show_floating', '1' ),
		);

		// Icon preview URL for admin.
		$button_icon_url = '';
		if ( $button_settings['icon_id'] ) {
			$button_icon_url = wp_get_attachment_image_url( $button_settings['icon_id'], 'thumbnail' );
		}

		// Setup Assistant drawer assets.
		wp_enqueue_style( 'def-core-setup-assistant' );
		wp_enqueue_script( 'def-core-setup-assistant' );
		wp_localize_script( 'def-core-setup-assistant', 'defSetupAssistant', array(
			'restUrl'    => esc_url_raw( rest_url( 'def-core/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'apiBaseUrl' => DEF_Core::get_def_api_url(),
			'tokenUrl'   => esc_url_raw( rest_url( DEF_CORE_API_NAME_SPACE . '/context-token' ) ),
		) );

		// Load template.
		include DEF_CORE_PLUGIN_DIR . 'templates/admin-settings.php';
		include DEF_CORE_PLUGIN_DIR . 'templates/setup-assistant-drawer.php';
	}

	// ─── AJAX: Save Settings ─────────────────────────────────────────

	/**
	 * AJAX handler for saving settings per-tab.
	 * Validates against per-tab allowlists — unknown keys rejected. (V1.1)
	 */
	public static function ajax_save_settings(): void {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'def_core_save_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'digital-employees' ) ), 403 );
		}

		// Verify capability.
		if ( ! current_user_can( 'def_admin_access' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied. DEF Admin access required.', 'digital-employees' ) ), 403 );
		}

		$tab = isset( $_POST['tab'] ) ? sanitize_text_field( wp_unslash( $_POST['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid settings data.', 'digital-employees' ) ) );
		}

		// Special handling for escalation tab (structured options).
		if ( 'escalation' === $tab ) {
			self::save_escalation_tab( $data );
			return;
		}

		// Get allowlist for this tab.
		if ( ! isset( self::$tab_allowlists[ $tab ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown tab.', 'digital-employees' ) ) );
		}

		$allowlist = self::$tab_allowlists[ $tab ];
		$errors    = array();
		$saved     = array();

		// Reject unknown keys.
		foreach ( $data as $key => $value ) {
			$key = sanitize_text_field( $key );
			if ( ! isset( $allowlist[ $key ] ) ) {
				$errors[] = sprintf( __( 'Unknown setting: %s', 'digital-employees' ), $key );
				continue;
			}

			$field_def = $allowlist[ $key ];
			$sanitize  = $field_def['sanitize'];
			$autoload  = isset( $field_def['autoload'] ) ? $field_def['autoload'] : true;

			// Call the sanitize method.
			$sanitized = call_user_func( array( __CLASS__, $sanitize ), $value );

			// Check for WP settings errors added by sanitize callbacks.
			$wp_errors = get_settings_errors();
			if ( ! empty( $wp_errors ) ) {
				foreach ( $wp_errors as $err ) {
					if ( 'error' === $err['type'] ) {
						$errors[] = $err['message'];
					}
				}
				// Clear settings errors so they don't persist.
				global $wp_settings_errors;
				$wp_settings_errors = array();
				continue;
			}

			update_option( $key, $sanitized, $autoload );
			$saved[] = $key;
		}

		if ( ! empty( $errors ) ) {
			wp_send_json_error( array(
				'message' => implode( ' ', $errors ),
				'errors'  => $errors,
				'saved'   => $saved,
			) );
		}

		wp_send_json_success( array(
			'message' => __( 'Settings saved.', 'digital-employees' ),
			'saved'   => $saved,
		) );
	}

	// ─── AJAX: Connection Test ───────────────────────────────────────

	/**
	 * AJAX handler for testing the DEF API connection.
	 * Stores sanitized result in transient (V1.1: no keys, no raw headers/bodies).
	 */
	public static function ajax_test_connection(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'def_core_test_connection' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'digital-employees' ) ), 403 );
		}

		if ( ! current_user_can( 'def_admin_access' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied. DEF Admin access required.', 'digital-employees' ) ), 403 );
		}

		$api_url = get_option( 'def_core_staff_ai_api_url', '' );
		$api_key = get_option( 'def_core_api_key', '' );

		if ( empty( $api_url ) ) {
			$result = array(
				'status'    => 'error',
				'message'   => __( 'API URL is not configured.', 'digital-employees' ),
				'http_code' => 0,
				'timestamp' => gmdate( 'c' ),
			);
			set_transient( 'def_core_connection_test', $result, 300 );
			wp_send_json_error( $result );
		}

		$headers = array( 'Accept' => 'application/json' );
		if ( ! empty( $api_key ) ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$start    = microtime( true );
		$response = wp_remote_get( rtrim( $api_url, '/' ) . '/health', array(
			'headers' => $headers,
			'timeout' => 10,
		) );
		$elapsed  = round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$error_msg = sanitize_text_field( substr( $response->get_error_message(), 0, 200 ) );
			$result    = array(
				'status'        => 'error',
				'message'       => $error_msg,
				'http_code'     => 0,
				'response_time' => $elapsed,
				'timestamp'     => gmdate( 'c' ),
			);
			set_transient( 'def_core_connection_test', $result, 300 );
			wp_send_json_error( $result );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code >= 200 && $http_code < 300 ) {
			$result = array(
				'status'        => 'ok',
				'message'       => __( 'Connected', 'digital-employees' ),
				'http_code'     => $http_code,
				'response_time' => $elapsed,
				'timestamp'     => gmdate( 'c' ),
			);
		} else {
			$result = array(
				'status'        => 'error',
				'message'       => sprintf( __( 'HTTP %d response', 'digital-employees' ), $http_code ),
				'http_code'     => $http_code,
				'response_time' => $elapsed,
				'timestamp'     => gmdate( 'c' ),
			);
		}

		// Store sanitized result in transient (V1.1: never store keys or raw bodies).
		set_transient( 'def_core_connection_test', $result, 300 );

		if ( 'ok' === $result['status'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	// ─── Sanitize Callbacks ──────────────────────────────────────────

	/**
	 * Sanitize tools status array.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return array<string, int> Sanitized tool status map.
	 */
	public static function sanitize_tools_status( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$sanitized = array();
		foreach ( $value as $key => $status ) {
			$key = sanitize_text_field( (string) $key );
			if ( ! empty( $key ) ) {
				$sanitized[ $key ] = (int) $status;
			}
		}
		return $sanitized;
	}

	// ─── D-II Sanitize Callbacks ────────────────────────────────────

	/**
	 * Sanitize logo attachment ID.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return int Valid attachment ID or 0.
	 */
	public static function sanitize_logo_id( $value ): int {
		$id = (int) $value;
		if ( $id > 0 && ! wp_attachment_is_image( $id ) ) {
			add_settings_error( 'def_core_logo_id', 'invalid_image',
				__( 'Selected file is not a valid image.', 'digital-employees' )
			);
			return 0;
		}
		return $id;
	}

	/**
	 * Sanitize display name.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return string Sanitized name (max 100 chars).
	 */
	public static function sanitize_display_name( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return substr( $value, 0, 100 );
	}

	/**
	 * Sanitize boolean setting (stored as '1' or '0').
	 *
	 * @param mixed $value The value to sanitize.
	 * @return string '1' or '0'.
	 */
	public static function sanitize_bool_setting( $value ): string {
		return ( $value && '0' !== $value ) ? '1' : '0';
	}

	/**
	 * Sanitize logo max height (24–120 px).
	 *
	 * @param mixed $value The value to sanitize.
	 * @return int Clamped height value.
	 */
	public static function sanitize_logo_max_height( $value ): int {
		$value = (int) $value;
		return max( 24, min( 120, $value ) );
	}

	/**
	 * Sanitize chat display mode.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return string 'modal' or 'drawer'.
	 */
	public static function sanitize_chat_display_mode( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, array( 'modal', 'drawer' ), true ) ? $value : 'modal';
	}

	/**
	 * Sanitize drawer width (300–600 px).
	 *
	 * @param mixed $value The value to sanitize.
	 * @return int Clamped width value.
	 */
	public static function sanitize_drawer_width( $value ): int {
		$value = (int) $value;
		return max( 300, min( 600, $value ) );
	}

	/**
	 * Sanitize button position ('right' or 'left').
	 *
	 * @param mixed $value The value to sanitize.
	 * @return string 'right' or 'left'.
	 */
	public static function sanitize_button_position( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, array( 'right', 'left' ), true ) ? $value : 'right';
	}

	/**
	 * Sanitize hex color string.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return string Valid 6-digit hex color or default.
	 */
	public static function sanitize_hex_color( $value ): string {
		$value = sanitize_text_field( (string) $value );
		if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
			return $value;
		}
		return '#111827';
	}

	/**
	 * Sanitize button icon type.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return string 'chat', 'headset', or 'custom'.
	 */
	public static function sanitize_button_icon( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, array( 'chat', 'headset', 'sparkle', 'custom' ), true ) ? $value : 'chat';
	}

	public static function sanitize_button_label( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, array( 'Chat', 'AI' ), true ) ? $value : 'Chat';
	}

	/**
	 * Sanitize privacy policy URL.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return string Valid URL or empty string.
	 */
	public static function sanitize_privacy_url( $value ): string {
		$value = esc_url_raw( trim( (string) $value ) );
		return $value;
	}

	// ─── D-II: Escalation Tab Save ──────────────────────────────────

	/**
	 * Save escalation tab data.
	 * Escalation uses structured options, not flat key-value.
	 *
	 * @param array $data Submitted data.
	 */
	private static function save_escalation_tab( array $data ): void {
		$channels_map = array(
			'escalation_customer'        => 'customer',
			'escalation_setup_assistant' => 'setup_assistant',
		);

		$saved = array();
		foreach ( $data as $key => $email ) {
			$key = sanitize_text_field( (string) $key );
			if ( ! isset( $channels_map[ $key ] ) ) {
				continue;
			}
			$channel = $channels_map[ $key ];
			$email   = sanitize_email( (string) $email );

			// Get existing settings to preserve other fields (cc, bcc, etc.).
			$current = get_option( 'def_core_escalation_' . $channel, array() );
			if ( ! is_array( $current ) ) {
				$current = array();
			}
			$current['to'] = ! empty( $email ) ? array( $email ) : array();
			DEF_Core_Escalation::save_channel_settings( $channel, $current );
			$saved[] = $key;
		}

		wp_send_json_success( array(
			'message' => __( 'Settings saved.', 'digital-employees' ),
			'saved'   => $saved,
		) );
	}

	// ─── D-II: AJAX User Roles ──────────────────────────────────────

	/**
	 * AJAX handler for saving user DEF capabilities.
	 * Requires def_admin_access capability.
	 */
	public static function ajax_save_user_roles(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'def_core_save_user_roles' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'digital-employees' ) ), 403 );
		}

		if ( ! current_user_can( 'def_admin_access' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied. DEF Admin access required.', 'digital-employees' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$roles_data = isset( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array();
		if ( ! is_array( $roles_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'digital-employees' ) ) );
		}

		$capabilities   = array( 'def_staff_access', 'def_management_access', 'def_admin_access' );
		$submitted_ids  = array_map( 'intval', array_keys( $roles_data ) );

		// Lockout prevention: ensure at least one user keeps def_admin_access.
		$admin_count = 0;

		// Count submitted users who would keep def_admin_access.
		foreach ( $roles_data as $uid => $caps ) {
			if ( ! empty( $caps['def_admin_access'] ) ) {
				$admin_count++;
			}
		}

		// Count existing def_admin_access users NOT in the submitted data.
		$existing_admins = get_users( array(
			'capability' => 'def_admin_access',
			'fields'     => 'ids',
			'exclude'    => $submitted_ids,
		) );
		$admin_count += count( $existing_admins );

		if ( $admin_count < 1 ) {
			wp_send_json_error( array(
				'message' => __( 'Cannot save — at least one user must have DEF Admin access.', 'digital-employees' ),
			) );
			return;
		}

		// Apply capability changes.
		foreach ( $roles_data as $user_id => $caps ) {
			$user_id = (int) $user_id;
			$user    = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			foreach ( $capabilities as $cap ) {
				$should_have = ! empty( $caps[ $cap ] );
				if ( $should_have && ! $user->has_cap( $cap ) ) {
					$user->add_cap( $cap );
				} elseif ( ! $should_have && $user->has_cap( $cap ) ) {
					$user->remove_cap( $cap );
				}
			}
		}

		wp_send_json_success( array(
			'message' => __( 'User roles updated.', 'digital-employees' ),
		) );
	}

	// ─── D-II: AJAX Search Users ────────────────────────────────────

	/**
	 * AJAX handler for searching WordPress users by email or display name.
	 * Returns users not already in the DEF user roles table.
	 */
	public static function ajax_search_users(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'def_core_search_users' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'digital-employees' ) ), 403 );
		}

		if ( ! current_user_can( 'def_admin_access' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'digital-employees' ) ), 403 );
		}

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'users' => array() ) );
			return;
		}

		// Search by email, display name, or user login.
		$core_query = new \WP_User_Query( array(
			'search'         => '*' . $term . '*',
			'search_columns' => array( 'user_email', 'display_name', 'user_login' ),
			'number'         => 10,
			'orderby'        => 'display_name',
			'order'          => 'ASC',
		) );

		// Also search by first_name / last_name (user meta).
		$meta_query = new \WP_User_Query( array(
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key'     => 'first_name',
					'value'   => $term,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'last_name',
					'value'   => $term,
					'compare' => 'LIKE',
				),
			),
			'number'  => 10,
			'orderby' => 'display_name',
			'order'   => 'ASC',
		) );

		// Merge and deduplicate.
		$seen    = array();
		$results = array();
		$all_users = array_merge( $core_query->get_results(), $meta_query->get_results() );
		foreach ( $all_users as $user ) {
			if ( isset( $seen[ $user->ID ] ) ) {
				continue;
			}
			$seen[ $user->ID ] = true;

			$first = get_user_meta( $user->ID, 'first_name', true );
			$last  = get_user_meta( $user->ID, 'last_name', true );
			$full  = trim( $first . ' ' . $last );

			$results[] = array(
				'id'           => $user->ID,
				'display_name' => $full ? $full : $user->display_name,
				'user_login'   => $user->user_login,
				'email'        => $user->user_email,
				'role'         => implode( ', ', array_map( 'ucfirst', $user->roles ) ),
				'avatar'       => get_avatar_url( $user->ID, array( 'size' => 24 ) ),
				'has_staff'    => $user->has_cap( 'def_staff_access' ),
				'has_mgmt'     => $user->has_cap( 'def_management_access' ),
				'has_admin'    => $user->has_cap( 'def_admin_access' ),
			);
			if ( count( $results ) >= 10 ) {
				break;
			}
		}

		wp_send_json_success( array( 'users' => $results ) );
	}

	// ─── D-II: AJAX Remove User Roles ───────────────────────────────

	/**
	 * AJAX handler for removing all DEF capabilities from a user.
	 */
	public static function ajax_remove_user_roles(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'def_core_save_user_roles' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'digital-employees' ) ), 403 );
		}

		if ( ! current_user_can( 'def_admin_access' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'digital-employees' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid user.', 'digital-employees' ) ) );
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'User not found.', 'digital-employees' ) ) );
			return;
		}

		// Lockout prevention: cannot remove the last DEF Admin.
		if ( $user->has_cap( 'def_admin_access' ) ) {
			$admin_ids = get_users( array(
				'capability' => 'def_admin_access',
				'fields'     => 'ids',
			) );
			if ( count( $admin_ids ) <= 1 && in_array( $user_id, array_map( 'intval', $admin_ids ), true ) ) {
				wp_send_json_error( array(
					'message' => __( 'Cannot remove the last DEF Admin. At least one user must have DEF Admin access.', 'digital-employees' ),
				) );
				return;
			}
		}

		$capabilities = array( 'def_staff_access', 'def_management_access', 'def_admin_access' );
		foreach ( $capabilities as $cap ) {
			if ( $user->has_cap( $cap ) ) {
				$user->remove_cap( $cap );
			}
		}

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %s: user display name */
				__( '%s removed from DEF access.', 'digital-employees' ),
				$user->display_name
			),
		) );
	}

	// ─── D-II: AJAX Test Escalation Email ───────────────────────────

	/**
	 * AJAX handler for sending a test escalation email.
	 */
	public static function ajax_test_escalation_email(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'def_core_test_escalation_email' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'digital-employees' ) ), 403 );
		}

		if ( ! current_user_can( 'def_admin_access' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied. DEF Admin access required.', 'digital-employees' ) ), 403 );
		}

		$channel         = isset( $_POST['channel'] ) ? sanitize_text_field( wp_unslash( $_POST['channel'] ) ) : '';
		$valid_channels  = array( 'customer', 'staff_ai', 'setup_assistant' );
		if ( ! in_array( $channel, $valid_channels, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid channel.', 'digital-employees' ) ) );
		}

		// Rate limit: 30-second cooldown per channel per user.
		$user_id       = get_current_user_id();
		$transient_key = 'def_test_email_' . $user_id . '_' . $channel;
		if ( get_transient( $transient_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please wait 30 seconds before sending another test email.', 'digital-employees' ) ) );
			return;
		}

		// Get the configured email for this channel, re-validate on retrieval.
		$stored    = get_option( 'def_core_escalation_' . $channel, array() );
		$raw_emails = ! empty( $stored['to'] ) ? (array) $stored['to'] : array( get_option( 'admin_email' ) );
		$to_emails  = array();
		foreach ( $raw_emails as $email ) {
			$clean = sanitize_email( $email );
			if ( is_email( $clean ) ) {
				$to_emails[] = $clean;
			}
		}
		$to = implode( ', ', $to_emails );

		if ( empty( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid email address configured for this channel.', 'digital-employees' ) ) );
		}

		$channel_label = ucwords( str_replace( '_', ' ', $channel ) );
		$subject       = sprintf( '[DEF Test] Escalation test — %s', $channel_label );
		$body          = __( 'This is a test escalation email from Digital Employee Framework. If you received this, escalation is working correctly.', 'digital-employees' );

		set_transient( $transient_key, 1, 30 );
		$sent = wp_mail( $to, $subject, $body );

		if ( $sent ) {
			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: %s: email address */
					__( 'Test email sent to %s', 'digital-employees' ),
					$to
				),
			) );
		} else {
			wp_send_json_error( array(
				'message' => __( 'Failed to send test email. Check your WordPress email configuration.', 'digital-employees' ),
			) );
		}
	}

	// ─── D-II: Logo Helper ──────────────────────────────────────────

	/**
	 * Get logo HTML with fallback chain.
	 * Chain: def_core_logo_id → custom_logo theme mod → display name text.
	 *
	 * @param int $max_height Maximum height in pixels. 0 = use saved setting.
	 * @return string Logo HTML.
	 */
	public static function get_logo_html( int $max_height = 0 ): string {
		if ( $max_height <= 0 ) {
			$max_height = (int) get_option( 'def_core_logo_max_height', 40 );
		}

		// 1. DEF Core logo.
		$logo_id = (int) get_option( 'def_core_logo_id', 0 );
		if ( $logo_id ) {
			$html = wp_get_attachment_image( $logo_id, 'full', false, array(
				'class' => 'header-logo-img',
				'style' => 'max-height: ' . $max_height . 'px; width: auto;',
			) );
			if ( $html ) {
				return $html;
			}
		}

		// 2. WordPress custom logo (theme mod).
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$html = wp_get_attachment_image( (int) $custom_logo_id, 'full', false, array(
				'class' => 'header-logo-img',
				'style' => 'max-height: ' . $max_height . 'px; width: auto;',
			) );
			if ( $html ) {
				return $html;
			}
		}

		// 3. Fallback: display name text.
		$display_name = get_option( 'def_core_display_name', get_bloginfo( 'name' ) );
		return '<span class="header-logo-text">' . esc_html( $display_name ) . '</span>';
	}
}
