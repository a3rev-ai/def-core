<?php
/**
 * Class DEF_Core_Admin
 *
 * Admin settings page for the Digital Employee Framework - Core plugin.
 * Phase 7 D-I: Tabbed layout with 7 tabs, AJAX save, connection test.
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
		'connection'       => array(
			'def_core_staff_ai_api_url' => array(
				'type'     => 'url',
				'sanitize' => 'sanitize_staff_ai_api_url',
			),
			'def_core_api_key'          => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_api_key',
				'autoload' => false,
			),
			'def_core_allowed_origins'  => array(
				'type'     => 'origins',
				'sanitize' => 'sanitize_allowed_origins',
			),
			'def_core_external_jwks_url' => array(
				'type'     => 'url',
				'sanitize' => 'sanitize_external_jwks_url',
			),
			'def_core_external_issuer'  => array(
				'type'     => 'url',
				'sanitize' => 'sanitize_external_issuer',
			),
		),
		'employees-tools'  => array(
			'def_core_tools_status' => array(
				'type'     => 'tools_array',
				'sanitize' => 'sanitize_tools_status',
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
		add_action( 'wp_ajax_def_core_regenerate_service_secret', array( __CLASS__, 'ajax_regenerate_service_secret' ) );
	}

	/**
	 * Add the settings page under Settings menu.
	 */
	public static function add_settings_page(): void {
		add_options_page(
			__( 'Digital Employees', 'def-core' ),
			__( 'Digital Employees', 'def-core' ),
			'manage_options',
			'def-core',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register settings with WordPress.
	 * Kept for option whitelisting and sanitize callbacks.
	 */
	public static function register_settings(): void {
		register_setting( 'def_core_settings', DEF_CORE_OPTION_ALLOWED_ORIGINS, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize_allowed_origins' ),
			'default'           => array(),
			'show_in_rest'      => false,
		) );
		register_setting( 'def_core_settings', 'def_core_external_jwks_url', array(
			'type'              => 'string',
			'sanitize_callback' => array( __CLASS__, 'sanitize_external_jwks_url' ),
			'default'           => '',
			'show_in_rest'      => false,
		) );
		register_setting( 'def_core_settings', 'def_core_external_issuer', array(
			'type'              => 'string',
			'sanitize_callback' => array( __CLASS__, 'sanitize_external_issuer' ),
			'default'           => '',
			'show_in_rest'      => false,
		) );
		register_setting( 'def_core_settings', 'def_core_tools_status', array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize_tools_status' ),
			'default'           => array(),
			'show_in_rest'      => false,
		) );
		register_setting( 'def_core_settings', 'def_core_staff_ai_api_url', array(
			'type'              => 'string',
			'sanitize_callback' => array( __CLASS__, 'sanitize_staff_ai_api_url' ),
			'default'           => '',
			'show_in_rest'      => false,
		) );
	}

	// ─── Page Rendering ──────────────────────────────────────────────

	/**
	 * Render the settings page.
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Enqueue admin assets.
		wp_enqueue_style( 'def-core-admin' );
		wp_enqueue_script( 'def-core-admin' );

		// Localize script data for JS.
		$cached_connection = get_transient( 'def_core_connection_test' );
		wp_localize_script( 'def-core-admin', 'defCoreAdmin', array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'saveNonce'        => wp_create_nonce( 'def_core_save_settings' ),
			'testNonce'        => wp_create_nonce( 'def_core_test_connection' ),
			'secretNonce'      => wp_create_nonce( 'def_core_regenerate_service_secret' ),
			'cachedConnection' => $cached_connection ? $cached_connection : null,
		) );

		// Prepare template data.
		$settings = array(
			'api_url'          => get_option( 'def_core_staff_ai_api_url', '' ),
			'api_key'          => get_option( 'def_core_api_key', '' ),
			'allowed_origins'  => get_option( DEF_CORE_OPTION_ALLOWED_ORIGINS, array() ),
			'external_jwks'    => get_option( 'def_core_external_jwks_url', '' ),
			'external_issuer'  => get_option( 'def_core_external_issuer', '' ),
			'service_secret'   => DEF_Core_Escalation::get_service_secret(),
		);

		// Tool registry data.
		$registry     = DEF_Core_API_Registry::instance();
		$tools        = $registry->get_tools_with_status();
		$tools_status = get_option( 'def_core_tools_status', array() );
		if ( ! is_array( $tools_status ) ) {
			$tools_status = array();
		}

		// Endpoint URLs for reference section.
		$urls = array(
			'jwks'    => rest_url( DEF_CORE_API_NAME_SPACE . '/jwks' ),
			'issuer'  => rtrim( home_url(), '/' ),
			'token'   => rest_url( DEF_CORE_API_NAME_SPACE . '/context-token' ),
		);

		// SSO configuration status.
		$sso_configured = ! empty( $settings['external_jwks'] ) && ! empty( $settings['external_issuer'] );

		// Load template.
		include DEF_CORE_PLUGIN_DIR . 'templates/admin-settings.php';
	}

	// ─── AJAX: Save Settings ─────────────────────────────────────────

	/**
	 * AJAX handler for saving settings per-tab.
	 * Validates against per-tab allowlists — unknown keys rejected. (V1.1)
	 */
	public static function ajax_save_settings(): void {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'def_core_save_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'def-core' ) ), 403 );
		}

		// Verify capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'def-core' ) ), 403 );
		}

		$tab = isset( $_POST['tab'] ) ? sanitize_text_field( wp_unslash( $_POST['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid settings data.', 'def-core' ) ) );
		}

		// Get allowlist for this tab.
		if ( ! isset( self::$tab_allowlists[ $tab ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown tab.', 'def-core' ) ) );
		}

		$allowlist = self::$tab_allowlists[ $tab ];
		$errors    = array();
		$saved     = array();

		// Reject unknown keys.
		foreach ( $data as $key => $value ) {
			$key = sanitize_text_field( $key );
			if ( ! isset( $allowlist[ $key ] ) ) {
				$errors[] = sprintf( __( 'Unknown setting: %s', 'def-core' ), $key );
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
			'message' => __( 'Settings saved.', 'def-core' ),
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
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'def-core' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'def-core' ) ), 403 );
		}

		$api_url = get_option( 'def_core_staff_ai_api_url', '' );
		$api_key = get_option( 'def_core_api_key', '' );

		if ( empty( $api_url ) ) {
			$result = array(
				'status'    => 'error',
				'message'   => __( 'API URL is not configured.', 'def-core' ),
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
				'message'       => __( 'Connected', 'def-core' ),
				'http_code'     => $http_code,
				'response_time' => $elapsed,
				'timestamp'     => gmdate( 'c' ),
			);
		} else {
			$result = array(
				'status'        => 'error',
				'message'       => sprintf( __( 'HTTP %d response', 'def-core' ), $http_code ),
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

	// ─── AJAX: Service Secret Regeneration ───────────────────────────

	/**
	 * AJAX handler for regenerating the service auth secret.
	 */
	public static function ajax_regenerate_service_secret(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'def_core_regenerate_service_secret' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'def-core' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'def-core' ) ), 403 );
		}

		$new_secret = DEF_Core_Escalation::get_service_secret( true );

		wp_send_json_success( array(
			'secret'  => $new_secret,
			'message' => __( 'New secret generated. Update your Python app\'s .env file immediately.', 'def-core' ),
		) );
	}

	// ─── Sanitize Callbacks ──────────────────────────────────────────

	/**
	 * Sanitize allowed origins.
	 *
	 * @param string|array $value The value to sanitize.
	 * @return array The sanitized origins.
	 */
	public static function sanitize_allowed_origins( $value ) {
		if ( is_string( $value ) ) {
			$lines = preg_split( "/\r\n|\n|\r/", $value );
		} elseif ( is_array( $value ) ) {
			$lines = $value;
		} else {
			$lines = array();
		}
		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( 0 === strpos( $line, 'http://' ) || 0 === strpos( $line, 'https://' ) ) {
				$line  = rtrim( $line, '/' );
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize external JWKS URL.
	 *
	 * @param string $value The value to sanitize.
	 * @return string The sanitized URL.
	 */
	public static function sanitize_external_jwks_url( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( '' === $url ) {
			add_settings_error( 'def_core_external_jwks_url', 'invalid_url',
				__( 'External JWKS URL must be a valid HTTP/HTTPS URL.', 'def-core' )
			);
			return '';
		}
		delete_transient( 'def_core_external_jwks_' . md5( $url ) );
		return $url;
	}

	/**
	 * Sanitize external issuer URL.
	 *
	 * @param string $value The value to sanitize.
	 * @return string The sanitized URL.
	 */
	public static function sanitize_external_issuer( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( '' === $url ) {
			add_settings_error( 'def_core_external_issuer', 'invalid_url',
				__( 'External Issuer URL must be a valid HTTP/HTTPS URL.', 'def-core' )
			);
			return '';
		}
		return rtrim( $url, '/' );
	}

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

	/**
	 * Sanitize Staff AI API URL.
	 *
	 * @param string $value The value to sanitize.
	 * @return string The sanitized URL.
	 */
	public static function sanitize_staff_ai_api_url( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( '' === $url ) {
			add_settings_error( 'def_core_staff_ai_api_url', 'invalid_url',
				__( 'Staff AI API URL must be a valid HTTP/HTTPS URL.', 'def-core' )
			);
			return '';
		}
		return rtrim( $url, '/' );
	}

	/**
	 * Sanitize API key.
	 *
	 * @param string $value The value to sanitize.
	 * @return string The sanitized key.
	 */
	public static function sanitize_api_key( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		// Strip any whitespace/control characters.
		$value = preg_replace( '/\s+/', '', $value );
		// Length validation: 10–256 chars.
		if ( strlen( $value ) < 10 || strlen( $value ) > 256 ) {
			add_settings_error( 'def_core_api_key', 'invalid_length',
				__( 'API key must be between 10 and 256 characters.', 'def-core' )
			);
			return '';
		}
		return $value;
	}
}
