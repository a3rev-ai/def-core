<?php
/**
 * Class DEF_Core_Admin_API
 *
 * REST API controller for the wp-admin settings panel.
 * Provides settings read/write, user management, connection testing,
 * chat proxy, and thread persistence for the admin drawer UI.
 *
 * Endpoints use the 'def-core/v1' namespace (plugin management, not tool API).
 *
 * Authentication modes:
 * - Mode A (Browser): WP nonce + def_admin_access capability
 * - Mode B (HMAC): Server-to-server with signed request
 *
 * @package def-core
 * @since 2.0.0
 * @version 2.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin REST API controller (settings, users, connection, chat proxy).
 *
 * @package def-core
 * @since 2.0.0
 */
final class DEF_Core_Admin_API {

	/**
	 * REST namespace for Setup Assistant endpoints.
	 *
	 * @var string
	 */
	private const REST_NAMESPACE = 'def-core/v1';

	/**
	 * Audit log option key.
	 *
	 * @var string
	 */
	private const AUDIT_LOG_OPTION = 'def_core_setup_audit_log';

	/**
	 * Maximum audit log entries (FIFO).
	 *
	 * @var int
	 */
	private const AUDIT_LOG_MAX = 100;

	/**
	 * Rate limit: max writes per window.
	 *
	 * @var int
	 */
	private const RATE_LIMIT_MAX = 30;

	/**
	 * Rate limit: window size in seconds.
	 *
	 * @var int
	 */
	private const RATE_LIMIT_WINDOW = 60;

	/**
	 * Rate limit: transient TTL in seconds.
	 *
	 * @var int
	 */
	private const RATE_LIMIT_TTL = 120;

	/**
	 * All DEF capabilities — single source of truth for user queries.
	 *
	 * @var array
	 */
	private const DEF_CAPABILITIES = array(
		'def_staff_access',
		'def_management_access',
		'def_admin_access',
		'def_ap_access',
	);

	/**
	 * HMAC timestamp tolerance in seconds.
	 *
	 * @var int
	 */
	private const HMAC_TIMESTAMP_TOLERANCE = 300;

	/**
	 * Thread ID max length.
	 *
	 * @var int
	 */
	private const THREAD_ID_MAX_LENGTH = 200;

	/**
	 * Setting allowlist with type, validation, and read mode.
	 *
	 * @var array<string, array{type: string, validate: string, read_mode: string, redact?: bool}>
	 */
	private static $setting_allowlist = array(
		'def_core_staff_ai_api_url' => array(
			'type'      => 'url',
			'validate'  => 'validate_url',
			'read_mode' => 'value',
			'readonly'  => true,
		),
		'def_core_api_key' => array(
			'type'      => 'string',
			'validate'  => 'validate_api_key',
			'read_mode' => 'configured_only',
			'redact'    => true,
			'readonly'  => true,
		),
		'def_core_display_name' => array(
			'type'      => 'string',
			'validate'  => 'validate_display_name',
			'read_mode' => 'value',
		),
		'def_core_logo_id' => array(
			'type'      => 'integer',
			'validate'  => 'validate_logo_id',
			'read_mode' => 'value',
		),
		'def_core_escalation_customer' => array(
			'type'      => 'email',
			'validate'  => 'validate_email_setting',
			'read_mode' => 'value',
		),
		'def_core_escalation_setup_assistant' => array(
			'type'      => 'email',
			'validate'  => 'validate_email_setting',
			'read_mode' => 'value',
		),
		'def_core_chat_display_mode' => array(
			'type'      => 'enum',
			'validate'  => 'validate_display_mode',
			'read_mode' => 'value',
		),
		'def_core_chat_drawer_width' => array(
			'type'      => 'integer',
			'validate'  => 'validate_drawer_width',
			'read_mode' => 'value',
		),
		'def_core_chat_button_color' => array(
			'type'      => 'string',
			'validate'  => 'validate_hex_color',
			'read_mode' => 'value',
		),
		'def_core_chat_button_hover_color' => array(
			'type'      => 'string',
			'validate'  => 'validate_hex_color',
			'read_mode' => 'value',
		),
		'def_core_chat_button_icon' => array(
			'type'      => 'enum',
			'validate'  => 'validate_button_icon',
			'read_mode' => 'value',
		),
		'def_core_chat_button_label' => array(
			'type'      => 'enum',
			'validate'  => 'validate_button_label',
			'read_mode' => 'value',
		),
	);

	/**
	 * Initialize the Setup Assistant routes.
	 *
	 * @since 2.0.0
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( new self(), 'register_rest_routes' ) );
	}

	// ─── Route Registration ─────────────────────────────────────────────

	/**
	 * Register all Setup Assistant REST routes.
	 *
	 * @since 2.0.0
	 */
	public function register_rest_routes(): void {
		// GET /setup/status
		register_rest_route( self::REST_NAMESPACE, '/setup/status', array(
			'methods'             => 'GET',
			'permission_callback' => array( $this, 'permission_check' ),
			'callback'            => array( $this, 'rest_get_status' ),
		) );

		// GET /setup/setting/{key}
		register_rest_route( self::REST_NAMESPACE, '/setup/setting/(?P<key>[a-z_]+)', array(
			'methods'             => 'GET',
			'permission_callback' => array( $this, 'permission_check' ),
			'callback'            => array( $this, 'rest_get_setting' ),
		) );

		// POST /setup/setting/{key}
		register_rest_route( self::REST_NAMESPACE, '/setup/setting/(?P<key>[a-z_]+)', array(
			'methods'             => 'POST',
			'permission_callback' => array( $this, 'permission_check' ),
			'callback'            => array( $this, 'rest_update_setting' ),
		) );

		// GET /setup/test-connection
		register_rest_route( self::REST_NAMESPACE, '/setup/test-connection', array(
			'methods'             => 'GET',
			'permission_callback' => array( $this, 'permission_check' ),
			'callback'            => array( $this, 'rest_test_connection' ),
		) );

		// GET /setup/users
		register_rest_route( self::REST_NAMESPACE, '/setup/users', array(
			'methods'             => 'GET',
			'permission_callback' => array( $this, 'permission_check' ),
			'callback'            => array( $this, 'rest_get_users' ),
		) );

		// GET /setup/users/search?term=...
		register_rest_route( self::REST_NAMESPACE, '/setup/users/search', array(
			'methods'             => 'GET',
			'permission_callback' => array( $this, 'permission_check' ),
			'callback'            => array( $this, 'rest_search_users' ),
		) );

		// POST /setup/user-role
		register_rest_route( self::REST_NAMESPACE, '/setup/user-role', array(
			'methods'             => 'POST',
			'permission_callback' => array( $this, 'permission_check' ),
			'callback'            => array( $this, 'rest_update_user_role' ),
		) );

		// GET /setup/thread
		register_rest_route( self::REST_NAMESPACE, '/setup/thread', array(
			'methods'             => 'GET',
			'permission_callback' => array( $this, 'permission_check' ),
			'callback'            => array( $this, 'rest_get_thread' ),
		) );

		// POST /setup/thread
		register_rest_route( self::REST_NAMESPACE, '/setup/thread', array(
			'methods'             => 'POST',
			'permission_callback' => array( $this, 'permission_check' ),
			'callback'            => array( $this, 'rest_save_thread' ),
		) );

		// DELETE /setup/thread
		register_rest_route( self::REST_NAMESPACE, '/setup/thread', array(
			'methods'             => 'DELETE',
			'permission_callback' => array( $this, 'permission_check' ),
			'callback'            => array( $this, 'rest_delete_thread' ),
		) );

		// GET /setup/detect-theme-colors
		register_rest_route( self::REST_NAMESPACE, '/setup/detect-theme-colors', array(
			'methods'             => 'GET',
			'permission_callback' => array( $this, 'permission_check' ),
			'callback'            => array( $this, 'rest_detect_theme_colors' ),
		) );

		// GET + POST /setup/seen (first-visit detection)
		register_rest_route( self::REST_NAMESPACE, '/setup/seen', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_seen' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_set_seen' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
		) );
	}

	// ─── Authentication ─────────────────────────────────────────────────

	/**
	 * Dual-mode permission check (Mode A: nonce, Mode B: HMAC).
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool|\WP_Error True if authorized, WP_Error otherwise.
	 * @since 2.0.0
	 */
	public function permission_check( \WP_REST_Request $request ) {
		$has_nonce = ! empty( $_SERVER['HTTP_X_WP_NONCE'] );
		$has_hmac  = ! empty( $_SERVER['HTTP_X_DEF_SIGNATURE'] );

		// Mixed-mode rejection.
		if ( $has_nonce && $has_hmac ) {
			return new \WP_Error(
				'INVALID_AUTH_MODE',
				'Cannot use both nonce and HMAC authentication simultaneously.',
				array( 'status' => 400 )
			);
		}

		// Mode A: Browser nonce auth.
		if ( $has_nonce ) {
			return $this->check_nonce_auth();
		}

		// Mode B: HMAC server-to-server auth.
		if ( $has_hmac ) {
			return $this->check_hmac_auth( $request );
		}

		return new \WP_Error(
			'UNAUTHORIZED',
			'Authentication required.',
			array( 'status' => 401 )
		);
	}

	/**
	 * Verify nonce + def_admin_access capability (Mode A).
	 *
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 * @since 2.0.0
	 */
	private function check_nonce_auth() {
		$nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) );

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'INVALID_NONCE',
				'Invalid or expired nonce.',
				array( 'status' => 403 )
			);
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new \WP_Error(
				'UNAUTHORIZED',
				'Not logged in.',
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'def_admin_access' ) ) {
			return new \WP_Error(
				'FORBIDDEN',
				'DEF Admin access required.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate HMAC server-to-server auth (Mode B).
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 * @since 2.0.0
	 */
	private function check_hmac_auth( \WP_REST_Request $request ) {
		$signature = isset( $_SERVER['HTTP_X_DEF_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_SIGNATURE'] ) )
			: '';
		$timestamp = isset( $_SERVER['HTTP_X_DEF_TIMESTAMP'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_TIMESTAMP'] ) )
			: '';
		$user_id_header = isset( $_SERVER['HTTP_X_DEF_USER'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_USER'] ) )
			: '';
		$body_hash = isset( $_SERVER['HTTP_X_DEF_BODY_HASH'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_BODY_HASH'] ) )
			: '';

		// All 4 HMAC headers required.
		if ( empty( $signature ) || empty( $timestamp ) || empty( $user_id_header ) || empty( $body_hash ) ) {
			return new \WP_Error(
				'HMAC_MISSING_HEADERS',
				'Missing required HMAC authentication headers.',
				array( 'status' => 401 )
			);
		}

		// Timestamp freshness.
		$ts = intval( $timestamp );
		if ( abs( time() - $ts ) > self::HMAC_TIMESTAMP_TOLERANCE ) {
			return new \WP_Error(
				'HMAC_EXPIRED',
				'HMAC timestamp expired.',
				array( 'status' => 401 )
			);
		}

		// Body hash verification.
		// DEF sends canonical JSON (sorted keys) as raw body bytes.
		// We hash php://input (the raw bytes) and compare to X-DEF-Body-Hash.
		$raw_body = file_get_contents( 'php://input' );
		if ( $raw_body === false ) {
			$raw_body = '';
		}
		$expected_body_hash = hash( 'sha256', $raw_body );
		if ( ! hash_equals( $expected_body_hash, $body_hash ) ) {
			return new \WP_Error(
				'HMAC_BODY_MISMATCH',
				'Body hash does not match.',
				array( 'status' => 401 )
			);
		}

		// Get API key for signature verification.
		$api_key = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'HMAC_NO_KEY',
				'API key not configured.',
				array( 'status' => 500 )
			);
		}

		// Build canonical payload and verify signature.
		$method = $request->get_method();
		$path   = $request->get_route();

		$payload      = "{$method}:{$path}:{$timestamp}:{$user_id_header}:{$body_hash}";
		$expected_sig = hash_hmac( 'sha256', $payload, $api_key );

		if ( ! hash_equals( $expected_sig, $signature ) ) {
			return new \WP_Error(
				'HMAC_INVALID_SIGNATURE',
				'Invalid HMAC signature.',
				array( 'status' => 401 )
			);
		}

		// Verify user exists and has def_admin_access.
		$user_id = intval( $user_id_header );
		$user    = get_user_by( 'id', $user_id );
		if ( ! $user || ! $user->exists() ) {
			return new \WP_Error(
				'HMAC_INVALID_USER',
				'User specified in HMAC does not exist.',
				array( 'status' => 401 )
			);
		}

		if ( ! $user->has_cap( 'def_admin_access' ) ) {
			return new \WP_Error(
				'FORBIDDEN',
				'DEF Admin access required.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	// ─── Response Helpers ───────────────────────────────────────────────

	/**
	 * Build a success response envelope.
	 *
	 * @param mixed $data       The response data.
	 * @param array $ui_actions Optional UI action directives.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	private function success_response( $data, array $ui_actions = array() ): \WP_REST_Response {
		return new \WP_REST_Response( array(
			'success'    => true,
			'data'       => $data,
			'error'      => null,
			'ui_actions' => $ui_actions,
		), 200 );
	}

	/**
	 * Build an error response envelope.
	 *
	 * @param string $code       Error code.
	 * @param string $message    Human-readable message.
	 * @param int    $status     HTTP status code.
	 * @param array  $ui_actions Optional UI action directives.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	private function error_response( string $code, string $message, int $status, array $ui_actions = array() ): \WP_REST_Response {
		return new \WP_REST_Response( array(
			'success'    => false,
			'data'       => null,
			'error'      => array(
				'code'    => $code,
				'message' => $message,
			),
			'ui_actions' => $ui_actions,
		), $status );
	}

	/**
	 * Build a 429 rate-limited response with Retry-After.
	 *
	 * @param int $retry_after Seconds until rate limit resets.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	private function rate_limited_response( int $retry_after ): \WP_REST_Response {
		$response = new \WP_REST_Response( array(
			'success'    => false,
			'data'       => null,
			'error'      => array(
				'code'        => 'RATE_LIMITED',
				'message'     => 'Too many write requests. Try again later.',
				'retry_after' => $retry_after,
			),
			'ui_actions' => array(),
		), 429 );
		$response->header( 'Retry-After', (string) $retry_after );
		return $response;
	}

	// ─── GET /setup/status ──────────────────────────────────────────────

	/**
	 * Get full configuration status checklist.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	public function rest_get_status( \WP_REST_Request $request ): \WP_REST_Response {
		$checkpoints = array();

		// 1. API URL configured.
		$api_url = get_option( 'def_core_staff_ai_api_url', '' );
		$checkpoints['api_url'] = array(
			'label'  => 'API URL configured',
			'passed' => ! empty( $api_url ),
		);

		// 2. API Key configured.
		$api_key = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
		$checkpoints['api_key'] = array(
			'label'  => 'API Key configured',
			'passed' => ! empty( $api_key ),
		);

		// 3. Connection healthy.
		$connection = get_transient( 'def_core_connection_test' );
		$checkpoints['connection'] = array(
			'label'  => 'Connection healthy',
			'passed' => is_array( $connection ) && ( $connection['status'] ?? '' ) === 'ok',
		);

		// 4. At least one escalation email.
		$esc_customer = get_option( 'def_core_escalation_customer', '' );
		$esc_setup    = get_option( 'def_core_escalation_setup_assistant', '' );
		$has_escalation = ! empty( $esc_customer ) || ! empty( $esc_setup );
		$checkpoints['escalation'] = array(
			'label'  => 'At least one escalation email configured',
			'passed' => $has_escalation,
		);

		// 5. Logo or display name set.
		$logo_id      = get_option( 'def_core_logo_id', 0 );
		$display_name = get_option( 'def_core_display_name', '' );
		$checkpoints['branding'] = array(
			'label'  => 'Logo or display name set',
			'passed' => ! empty( $logo_id ) || ! empty( $display_name ),
		);

		// 6. At least one Staff user.
		$staff_users = get_users( array(
			'capability' => 'def_staff_access',
			'fields'     => 'ids',
		) );
		$checkpoints['staff_user'] = array(
			'label'  => 'At least one Staff user',
			'passed' => ! empty( $staff_users ),
		);

		// 7. At least one Management user.
		$mgmt_users = get_users( array(
			'capability' => 'def_management_access',
			'fields'     => 'ids',
		) );
		$checkpoints['management_user'] = array(
			'label'  => 'At least one Management user',
			'passed' => ! empty( $mgmt_users ),
		);

		// 8. At least one tool enabled.
		$has_tool = false;
		if ( class_exists( 'DEF_Core_API_Registry' ) ) {
			$tools = DEF_Core_API_Registry::instance()->get_tools_with_status();
			foreach ( $tools as $tool ) {
				if ( ! empty( $tool['enabled'] ) ) {
					$has_tool = true;
					break;
				}
			}
		}
		$checkpoints['tools'] = array(
			'label'  => 'At least one tool enabled',
			'passed' => $has_tool,
		);

		// Compute completion percentage.
		$passed = 0;
		$total  = count( $checkpoints );
		foreach ( $checkpoints as $cp ) {
			if ( $cp['passed'] ) {
				$passed++;
			}
		}
		$completion = $total > 0 ? round( ( $passed / $total ) * 100, 1 ) : 0;

		// Find next recommended action (first failing checkpoint).
		$next_action = null;
		foreach ( $checkpoints as $key => $cp ) {
			if ( ! $cp['passed'] ) {
				$next_action = $cp['label'];
				break;
			}
		}

		return $this->success_response( array(
			'checkpoints'             => $checkpoints,
			'completion_percentage'   => $completion,
			'next_recommended_action' => $next_action,
		) );
	}

	// ─── GET /setup/setting/{key} ───────────────────────────────────────

	/**
	 * Get a single setting value.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	public function rest_get_setting( \WP_REST_Request $request ): \WP_REST_Response {
		$key = sanitize_text_field( $request->get_param( 'key' ) );

		if ( ! isset( self::$setting_allowlist[ $key ] ) ) {
			return $this->error_response( 'UNKNOWN_SETTING', "Setting '{$key}' is not recognized.", 400 );
		}

		$config = self::$setting_allowlist[ $key ];

		// Configured-only mode: never return raw value.
		if ( $config['read_mode'] === 'configured_only' ) {
			$value = get_option( $key, '' );
			return $this->success_response( array(
				'key'        => $key,
				'configured' => ! empty( $value ),
			) );
		}

		// Value mode: return actual value.
		$value = get_option( $key, '' );
		return $this->success_response( array(
			'key'   => $key,
			'value' => $value,
		) );
	}

	// ─── POST /setup/setting/{key} ──────────────────────────────────────

	/**
	 * Update a setting value.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	public function rest_update_setting( \WP_REST_Request $request ): \WP_REST_Response {
		// Get acting user ID (nonce auth → current user; HMAC → header).
		$user_id = $this->get_acting_user_id( $request );

		// Rate limit check.
		$rate_check = $this->check_write_rate_limit( $user_id );
		if ( is_array( $rate_check ) ) {
			return $this->rate_limited_response( $rate_check['retry_after'] );
		}

		$key = sanitize_text_field( $request->get_param( 'key' ) );

		if ( ! isset( self::$setting_allowlist[ $key ] ) ) {
			return $this->error_response( 'UNKNOWN_SETTING', "Setting '{$key}' is not recognized.", 400 );
		}

		// Connection settings are managed by DEFHO — read-only via Setup Assistant.
		if ( ! empty( self::$setting_allowlist[ $key ]['readonly'] ) ) {
			return $this->error_response( 'READONLY_SETTING', "Setting '{$key}' is managed by the DEFHO platform and cannot be changed here.", 403 );
		}

		$body  = $request->get_json_params();
		$value = $body['value'] ?? null;

		if ( $value === null ) {
			return $this->error_response( 'MISSING_VALUE', 'Request body must include a "value" field.', 400 );
		}

		// Run validation.
		$config          = self::$setting_allowlist[ $key ];
		$validate_method = $config['validate'];
		$validation      = $this->$validate_method( $value );

		if ( $validation !== true ) {
			return $this->error_response( 'VALIDATION_ERROR', $validation, 400 );
		}

		// Save.
		$old_value = get_option( $key, '' );
		$autoload  = ! empty( $config['redact'] ) ? false : null;
		if ( $autoload === false ) {
			update_option( $key, $value, false );
		} else {
			update_option( $key, $value );
		}

		// Audit log entry.
		$this->write_audit_log( $user_id, 'update_setting', $key, $old_value, $value );

		// Build UI actions.
		$ui_actions = array();
		$tab_map    = array(
			'def_core_display_name'              => 'branding',
			'def_core_logo_id'                   => 'branding',
			'def_core_escalation_customer'       => 'escalation',
			'def_core_escalation_setup_assistant' => 'escalation',
			'def_core_chat_display_mode'         => 'chat-settings',
			'def_core_chat_drawer_width'         => 'chat-settings',
			'def_core_chat_button_color'         => 'chat-settings',
			'def_core_chat_button_hover_color'   => 'chat-settings',
			'def_core_chat_button_icon'          => 'chat-settings',
			'def_core_chat_button_label'         => 'chat-settings',
		);
		if ( isset( $tab_map[ $key ] ) ) {
			$ui_actions[] = array(
				'action' => 'highlight_tab',
				'tab'    => $tab_map[ $key ],
			);
		}

		// Update the field in the DOM (use short key for FIELD_MAP lookup).
		$short_key = preg_replace( '/^def_core_/', '', $key );
		$ui_actions[] = array(
			'action' => 'update_field',
			'field'  => $short_key,
			'value'  => $value,
		);

		return $this->success_response( array(
			'key'   => $key,
			'saved' => true,
		), $ui_actions );
	}

	// ─── GET /setup/test-connection ─────────────────────────────────────

	/**
	 * Test connection to the DEF API backend.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	public function rest_test_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$api_url = DEF_Core::get_def_api_url();
		$api_key = DEF_Core_Encryption::get_secret( 'def_core_api_key' );

		if ( empty( $api_url ) ) {
			$result = array(
				'status'    => 'error',
				'message'   => 'API URL is not configured.',
				'http_code' => 0,
				'timestamp' => gmdate( 'c' ),
			);
			set_transient( 'def_core_connection_test', $result, 300 );
			return $this->success_response( $result );
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
			return $this->success_response( $result );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code >= 200 && $http_code < 300 ) {
			$result = array(
				'status'        => 'ok',
				'message'       => 'Connected',
				'http_code'     => $http_code,
				'response_time' => $elapsed,
				'timestamp'     => gmdate( 'c' ),
			);
		} else {
			$result = array(
				'status'        => 'error',
				'message'       => sprintf( 'HTTP %d response', $http_code ),
				'http_code'     => $http_code,
				'response_time' => $elapsed,
				'timestamp'     => gmdate( 'c' ),
			);
		}

		set_transient( 'def_core_connection_test', $result, 300 );
		return $this->success_response( $result );
	}

	// ─── GET /setup/detect-theme-colors ─────────────────────────────────

	/**
	 * Detect button colors from the active WordPress theme.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 2.1.0
	 */
	public function rest_detect_theme_colors( \WP_REST_Request $request ): \WP_REST_Response {
		$colors = DEF_Core_Theme_Colors::detect();
		$colors['current_button_color']       = get_option( 'def_core_chat_button_color', '#111827' );
		$colors['current_button_hover_color'] = get_option( 'def_core_chat_button_hover_color', '' );
		return $this->success_response( $colors );
	}

	// ─── GET /setup/users ───────────────────────────────────────────────

	/**
	 * List WordPress users with any DEF capability.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	public function rest_get_users( \WP_REST_Request $request ): \WP_REST_Response {
		$def_capabilities = self::DEF_CAPABILITIES;
		$user_ids         = array();

		// Collect unique user IDs across all capabilities.
		foreach ( $def_capabilities as $cap ) {
			$ids = get_users( array(
				'capability' => $cap,
				'fields'     => 'ids',
			) );
			foreach ( $ids as $id ) {
				$user_ids[ $id ] = true;
			}
		}

		$users = array();
		foreach ( array_keys( $user_ids ) as $uid ) {
			$user = get_user_by( 'id', $uid );
			if ( ! $user ) {
				continue;
			}

			$caps = array();
			foreach ( $def_capabilities as $cap ) {
				if ( $user->has_cap( $cap ) ) {
					$caps[] = $cap;
				}
			}

			$users[] = array(
				'user_id'      => $user->ID,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'capabilities' => $caps,
			);
		}

		return $this->success_response( array( 'users' => $users ) );
	}

	// ─── GET /setup/users/search ────────────────────────────────────────

	/**
	 * Search WordPress users by name/email/login and/or WordPress role.
	 *
	 * Accepts optional `term` (min 2 chars) and optional `role` params.
	 * At least one must be provided. Both can be combined to filter
	 * e.g. "subscribers named john".
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	public function rest_search_users( \WP_REST_Request $request ): \WP_REST_Response {
		$term = sanitize_text_field( $request->get_param( 'term' ) );
		$role = sanitize_text_field( $request->get_param( 'role' ) );

		$has_term = strlen( $term ) >= 2;
		$has_role = strlen( $role ) >= 1;

		if ( ! $has_term && ! $has_role ) {
			return $this->success_response( array( 'users' => array() ) );
		}

		$query_args = array(
			'number'  => 20,
			'orderby' => 'display_name',
			'order'   => 'ASC',
		);

		if ( $has_term ) {
			$query_args['search']         = '*' . $term . '*';
			$query_args['search_columns'] = array( 'user_email', 'display_name', 'user_login' );
		}

		if ( $has_role ) {
			$query_args['role'] = $role;
		}

		$query   = new \WP_User_Query( $query_args );
		$results = array();

		foreach ( $query->get_results() as $user ) {
			$caps = array();
			foreach ( self::DEF_CAPABILITIES as $cap ) {
				$caps[ $cap ] = $user->has_cap( $cap );
			}

			$results[] = array(
				'user_id'      => $user->ID,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'wp_role'      => implode( ', ', array_map( 'ucfirst', $user->roles ) ),
				'capabilities' => $caps,
			);
		}

		return $this->success_response( array( 'users' => $results ) );
	}

	// ─── POST /setup/user-role ──────────────────────────────────────────

	/**
	 * Add or remove a DEF capability from a user.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	public function rest_update_user_role( \WP_REST_Request $request ): \WP_REST_Response {
		$acting_user_id = $this->get_acting_user_id( $request );

		// Rate limit check.
		$rate_check = $this->check_write_rate_limit( $acting_user_id );
		if ( is_array( $rate_check ) ) {
			return $this->rate_limited_response( $rate_check['retry_after'] );
		}

		$body = $request->get_json_params();

		$wp_user_id = isset( $body['wp_user_id'] ) ? intval( $body['wp_user_id'] ) : 0;
		$capability = isset( $body['capability'] ) ? sanitize_text_field( $body['capability'] ) : '';
		$action     = isset( $body['action'] ) ? sanitize_text_field( $body['action'] ) : '';

		// Validate params.
		if ( ! in_array( $capability, self::DEF_CAPABILITIES, true ) ) {
			return $this->error_response( 'INVALID_CAPABILITY', 'Invalid capability.', 400 );
		}

		if ( ! in_array( $action, array( 'add', 'remove' ), true ) ) {
			return $this->error_response( 'INVALID_ACTION', 'Action must be "add" or "remove".', 400 );
		}

		if ( ! $wp_user_id ) {
			return $this->error_response( 'INVALID_USER', 'wp_user_id is required.', 400 );
		}

		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user || ! $user->exists() ) {
			return $this->error_response( 'USER_NOT_FOUND', 'User not found.', 404 );
		}

		// Lockout prevention: cannot remove the last DEF Admin.
		if ( $action === 'remove' && $capability === 'def_admin_access' ) {
			$admin_ids = get_users( array(
				'capability' => 'def_admin_access',
				'fields'     => 'ids',
			) );
			if ( count( $admin_ids ) <= 1 && in_array( $wp_user_id, array_map( 'intval', $admin_ids ), true ) ) {
				return $this->error_response(
					'LOCKOUT_PREVENTED',
					'Cannot remove the last DEF Admin. At least one user must have DEF Admin access.',
					409
				);
			}
		}

		// Staff and Management are mutually exclusive.
		// Adding one removes the other server-side.
		if ( $action === 'add' && 'def_staff_access' === $capability ) {
			$user->remove_cap( 'def_management_access' );
		} elseif ( $action === 'add' && 'def_management_access' === $capability ) {
			$user->remove_cap( 'def_staff_access' );
		}

		// Apply capability change.
		if ( $action === 'add' ) {
			$user->add_cap( $capability );
		} else {
			$user->remove_cap( $capability );
		}

		// Audit log entry.
		$this->write_audit_log(
			$acting_user_id,
			$action . '_capability',
			$capability,
			null,
			null,
			$wp_user_id
		);

		// Build current capabilities for this user.
		$user_caps = array();
		foreach ( self::DEF_CAPABILITIES as $cap ) {
			$user_caps[ $cap ] = $user->has_cap( $cap );
		}

		$ui_actions = array(
			array(
				'action' => 'highlight_tab',
				'tab'    => 'user-roles',
			),
			array(
				'action'       => 'update_user_row',
				'wp_user_id'   => $wp_user_id,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'wp_role'      => implode( ', ', array_values( (array) $user->roles ) ),
				'avatar'       => get_avatar_url( $wp_user_id, array( 'size' => 48 ) ),
				'capabilities' => $user_caps,
			),
		);

		return $this->success_response( array(
			'wp_user_id' => $wp_user_id,
			'capability' => $capability,
			'action'     => $action,
			'applied'    => true,
		), $ui_actions );
	}

	// ─── Thread CRUD ────────────────────────────────────────────────────

	/**
	 * Get stored thread ID for current user.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	public function rest_get_thread( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id   = $this->get_acting_user_id( $request );
		$thread_id = get_user_meta( $user_id, 'setup_assistant_thread_id', true );

		return $this->success_response( array(
			'thread_id' => ! empty( $thread_id ) ? $thread_id : null,
		) );
	}

	/**
	 * Save thread ID for current user.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	public function rest_save_thread( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = $this->get_acting_user_id( $request );
		$body    = $request->get_json_params();

		$thread_id = isset( $body['thread_id'] ) ? sanitize_text_field( $body['thread_id'] ) : '';

		if ( empty( $thread_id ) ) {
			return $this->error_response( 'MISSING_THREAD_ID', 'thread_id is required.', 400 );
		}

		if ( strlen( $thread_id ) > self::THREAD_ID_MAX_LENGTH ) {
			return $this->error_response(
				'THREAD_ID_TOO_LONG',
				'thread_id must be ' . self::THREAD_ID_MAX_LENGTH . ' characters or fewer.',
				400
			);
		}

		update_user_meta( $user_id, 'setup_assistant_thread_id', $thread_id );

		return $this->success_response( array(
			'thread_id' => $thread_id,
			'saved'     => true,
		) );
	}

	/**
	 * Delete thread ID for current user.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	public function rest_delete_thread( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = $this->get_acting_user_id( $request );
		delete_user_meta( $user_id, 'setup_assistant_thread_id' );

		return $this->success_response( array(
			'deleted' => true,
		) );
	}

	// ─── GET /setup/seen ────────────────────────────────────────────────

	/**
	 * Check if the current user has seen the Setup Assistant drawer.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	public function rest_get_seen( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = $this->get_acting_user_id( $request );
		$seen    = get_user_meta( $user_id, 'def_sa_drawer_seen', true );

		return $this->success_response( array(
			'seen' => ! empty( $seen ),
		) );
	}

	// ─── POST /setup/seen ───────────────────────────────────────────────

	/**
	 * Mark the Setup Assistant drawer as seen for the current user.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 2.0.0
	 */
	public function rest_set_seen( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = $this->get_acting_user_id( $request );
		update_user_meta( $user_id, 'def_sa_drawer_seen', '1' );

		return $this->success_response( array(
			'seen' => true,
		) );
	}

	// ─── Setting Validation ─────────────────────────────────────────────

	/**
	 * Validate URL setting (HTTPS required unless WP_DEBUG).
	 *
	 * @param mixed $value The value to validate.
	 * @return true|string True if valid, error message otherwise.
	 */
	private function validate_url( $value ) {
		if ( ! is_string( $value ) || empty( $value ) ) {
			return 'URL is required.';
		}

		if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return 'Invalid URL format.';
		}

		$scheme = wp_parse_url( $value, PHP_URL_SCHEME );
		if ( $scheme !== 'https' && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return 'HTTPS is required for API URL.';
		}

		return true;
	}

	/**
	 * Validate API key setting.
	 *
	 * @param mixed $value The value to validate.
	 * @return true|string True if valid, error message otherwise.
	 */
	private function validate_api_key( $value ) {
		if ( ! is_string( $value ) ) {
			return 'API key must be a string.';
		}
		// Allow empty to clear, or non-empty string.
		return true;
	}

	/**
	 * Validate display name setting.
	 *
	 * @param mixed $value The value to validate.
	 * @return true|string True if valid, error message otherwise.
	 */
	private function validate_display_name( $value ) {
		if ( ! is_string( $value ) ) {
			return 'Display name must be a string.';
		}
		if ( strlen( $value ) > 100 ) {
			return 'Display name must be 100 characters or fewer.';
		}
		return true;
	}

	/**
	 * Validate logo attachment ID.
	 *
	 * @param mixed $value The value to validate.
	 * @return true|string True if valid, error message otherwise.
	 */
	private function validate_logo_id( $value ) {
		$id = intval( $value );
		if ( $id < 0 ) {
			return 'Logo ID must be a non-negative integer.';
		}
		// Allow 0 to clear.
		if ( $id === 0 ) {
			return true;
		}
		if ( function_exists( 'wp_attachment_is_image' ) && ! wp_attachment_is_image( $id ) ) {
			return 'Attachment is not a valid image.';
		}
		return true;
	}

	/**
	 * Validate email setting.
	 *
	 * @param mixed $value The value to validate.
	 * @return true|string True if valid, error message otherwise.
	 */
	private function validate_email_setting( $value ) {
		if ( ! is_string( $value ) ) {
			return 'Email must be a string.';
		}
		// Allow empty to clear.
		if ( empty( $value ) ) {
			return true;
		}
		if ( ! is_email( $value ) ) {
			return 'Invalid email address.';
		}
		return true;
	}

	/**
	 * Validate chat display mode.
	 *
	 * @param mixed $value The value to validate.
	 * @return true|string True if valid, error message otherwise.
	 */
	private function validate_display_mode( $value ) {
		if ( ! in_array( $value, array( 'modal', 'drawer' ), true ) ) {
			return 'Display mode must be "modal" or "drawer".';
		}
		return true;
	}

	/**
	 * Validate drawer width setting.
	 *
	 * @param mixed $value The value to validate.
	 * @return true|string True if valid, error message otherwise.
	 */
	private function validate_drawer_width( $value ) {
		$width = intval( $value );
		if ( $width < 300 || $width > 600 ) {
			return 'Drawer width must be between 300 and 600 pixels.';
		}
		return true;
	}

	/**
	 * Validate a hex color value.
	 *
	 * @param mixed $value The value to validate.
	 * @return true|string True if valid, error message if not.
	 * @since 2.1.0
	 */
	private function validate_hex_color( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return true; // Allow empty to clear.
		}
		if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ) {
			return 'Value must be a valid hex color (e.g., #FF5733 or #F00).';
		}
		return true;
	}

	/**
	 * Validate button icon value.
	 *
	 * @param mixed $value The value to validate.
	 * @return true|string True if valid, error message otherwise.
	 * @since 2.2.0
	 */
	private function validate_button_icon( $value ) {
		if ( ! in_array( $value, array( 'chat', 'headset', 'sparkle', 'custom' ), true ) ) {
			return 'Button icon must be "chat", "headset", "sparkle", or "custom".';
		}
		return true;
	}

	/**
	 * Validate button label value.
	 *
	 * @param mixed $value The value to validate.
	 * @return true|string True if valid, error message otherwise.
	 * @since 2.2.0
	 */
	private function validate_button_label( $value ) {
		if ( ! in_array( $value, array( 'Chat', 'AI' ), true ) ) {
			return 'Button label must be "Chat" or "AI".';
		}
		return true;
	}

	// ─── Rate Limiting ──────────────────────────────────────────────────

	/**
	 * Check write rate limit for a user.
	 *
	 * @param int $user_id The acting user ID.
	 * @return true|array True if allowed, array with retry_after if limited.
	 * @since 2.0.0
	 */
	private function check_write_rate_limit( int $user_id ) {
		$key  = 'def_sa_rate_' . $user_id;
		$data = get_transient( $key );

		$now = time();

		if ( ! is_array( $data ) || ( $now - ( $data['window_start'] ?? 0 ) ) > self::RATE_LIMIT_WINDOW ) {
			// Start new window.
			set_transient( $key, array(
				'count'        => 1,
				'window_start' => $now,
			), self::RATE_LIMIT_TTL );
			return true;
		}

		if ( $data['count'] >= self::RATE_LIMIT_MAX ) {
			$retry_after = self::RATE_LIMIT_WINDOW - ( $now - $data['window_start'] );
			return array( 'retry_after' => max( 1, $retry_after ) );
		}

		// Increment count.
		$data['count']++;
		set_transient( $key, $data, self::RATE_LIMIT_TTL );
		return true;
	}

	// ─── Audit Log ──────────────────────────────────────────────────────

	/**
	 * Write an audit log entry.
	 *
	 * @param int         $user_id        The acting WP user ID.
	 * @param string      $action         The action performed.
	 * @param string|null $setting_key    The setting key, if applicable.
	 * @param mixed       $old_value      The old value.
	 * @param mixed       $new_value      The new value.
	 * @param int|null    $target_user_id The target user ID, if applicable.
	 * @since 2.0.0
	 */
	private function write_audit_log(
		int $user_id,
		string $action,
		?string $setting_key = null,
		$old_value = null,
		$new_value = null,
		?int $target_user_id = null
	): void {
		$log = get_option( self::AUDIT_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		// Get user display name.
		$user      = get_user_by( 'id', $user_id );
		$user_name = $user ? $user->display_name : 'Unknown';

		// Redact sensitive values.
		if ( $setting_key === 'def_core_api_key' ) {
			$old_value = '[redacted]';
			$new_value = '[redacted]';
		}

		$entry = array(
			'timestamp'          => gmdate( 'c' ),
			'acting_wp_user_id'  => $user_id,
			'acting_wp_user_name' => $user_name,
			'action'             => $action,
		);

		if ( $setting_key !== null ) {
			$entry['setting_key'] = $setting_key;
		}
		if ( $old_value !== null ) {
			$entry['old_value'] = $old_value;
		}
		if ( $new_value !== null ) {
			$entry['new_value'] = $new_value;
		}
		if ( $target_user_id !== null ) {
			$entry['target_wp_user_id'] = $target_user_id;
		}

		// FIFO: keep last N entries.
		$log[] = $entry;
		if ( count( $log ) > self::AUDIT_LOG_MAX ) {
			$log = array_slice( $log, -self::AUDIT_LOG_MAX );
		}

		update_option( self::AUDIT_LOG_OPTION, $log, false );
	}

	// ─── Helpers ────────────────────────────────────────────────────────

	/**
	 * Get the acting user ID from the request context.
	 * Mode A (nonce) → get_current_user_id()
	 * Mode B (HMAC)  → X-DEF-User header
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return int The acting user ID.
	 * @since 2.0.0
	 */
	private function get_acting_user_id( \WP_REST_Request $request ): int {
		// If HMAC auth was used, get user from header.
		if ( ! empty( $_SERVER['HTTP_X_DEF_SIGNATURE'] ) ) {
			return intval( $_SERVER['HTTP_X_DEF_USER'] ?? 0 );
		}

		// Otherwise, nonce auth → current WordPress user.
		return get_current_user_id();
	}

	/**
	 * Get the setting allowlist (for testing).
	 *
	 * @return array The allowlist.
	 * @since 2.0.0
	 */
	public static function get_setting_allowlist(): array {
		return self::$setting_allowlist;
	}
}
