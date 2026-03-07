<?php
/**
 * Class DEF_Core_Connection_Config
 *
 * Handles receiving pushed connection config from DEFHO and providing
 * connection status for the admin UI and external health monitoring.
 *
 * Endpoints:
 * - POST /wp-json/def-core/v1/internal/connection-config — receive pushed config
 * - GET  /wp-json/def-core/v1/connection-status — connection health status
 *
 * @package def-core
 * @since 2.2.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Connection_Config {

	/**
	 * Option key for stored connection config revision.
	 */
	private const OPTION_REVISION = 'def_core_conn_config_revision';

	/**
	 * Option key for last sync timestamp.
	 */
	private const OPTION_LAST_SYNC = 'def_core_conn_last_sync_at';

	/**
	 * Option key for the previous service auth secret (dual-key rotation).
	 */
	private const OPTION_PREVIOUS_SECRET = 'def_core_conn_previous_service_secret';

	/**
	 * Option key for the previous API key (dual-key rotation).
	 */
	private const OPTION_PREVIOUS_API_KEY = 'def_core_conn_previous_api_key';

	/**
	 * Dual-key rotation window in seconds (5 minutes).
	 */
	private const ROTATION_WINDOW_SECONDS = 300;

	/**
	 * Option key for rotation window expiry timestamp.
	 */
	private const OPTION_ROTATION_EXPIRES = 'def_core_conn_rotation_expires';

	/**
	 * Initialize the connection config routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_rest_routes(): void {
		// POST /wp-json/def-core/v1/internal/connection-config
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/internal/connection-config',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'callback'            => array( __CLASS__, 'receive_connection_config' ),
			)
		);

		// GET /wp-json/def-core/v1/connection-status
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/connection-status',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'get_connection_status' ),
			)
		);
	}

	/**
	 * Permission callback — service auth only (X-DEF-AUTH header).
	 *
	 * @return bool|\WP_Error True if authenticated, WP_Error otherwise.
	 */
	public static function permission_check() {
		$auth_header = '';

		if ( ! empty( $_SERVER['HTTP_X_DEF_AUTH'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_AUTH'] ) );
		}

		if ( empty( $auth_header ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Service authentication required.', 'def-core' ),
				array( 'status' => 401 )
			);
		}

		$stored_secret = get_option( 'def_service_auth_secret', '' );

		if ( empty( $stored_secret ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Service auth secret not configured.', 'def-core' ),
				array( 'status' => 401 )
			);
		}

		// Check current secret.
		if ( hash_equals( $stored_secret, $auth_header ) ) {
			return true;
		}

		// Check previous secret during dual-key rotation window.
		$rotation_expires = (int) get_option( self::OPTION_ROTATION_EXPIRES, 0 );
		if ( $rotation_expires > time() ) {
			$previous_secret = get_option( self::OPTION_PREVIOUS_SECRET, '' );
			if ( ! empty( $previous_secret ) && hash_equals( $previous_secret, $auth_header ) ) {
				return true;
			}
		}

		return new \WP_Error(
			'rest_forbidden',
			__( 'Invalid service auth credentials.', 'def-core' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Receive pushed connection config from DEFHO.
	 *
	 * POST /wp-json/def-core/v1/internal/connection-config
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The response.
	 */
	public static function receive_connection_config( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();

		// Validate required fields.
		$required = array( 'config_revision', 'api_key', 'service_auth_secret' );
		foreach ( $required as $field ) {
			if ( empty( $body[ $field ] ) && 0 !== ( $body[ $field ] ?? null ) ) {
				return new \WP_REST_Response(
					array(
						'status'  => 'error',
						'message' => sprintf( 'Missing required field: %s', $field ),
					),
					422
				);
			}
		}

		$incoming_revision = (int) $body['config_revision'];
		if ( $incoming_revision < 1 ) {
			return new \WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'config_revision must be >= 1',
				),
				422
			);
		}

		// Check revision — reject stale pushes.
		$current_revision = (int) get_option( self::OPTION_REVISION, 0 );
		if ( $incoming_revision < $current_revision ) {
			return new \WP_REST_Response(
				array(
					'status'  => 'rejected',
					'message' => sprintf(
						'Stale push rejected. Incoming revision %d < current %d.',
						$incoming_revision,
						$current_revision
					),
				),
				409
			);
		}

		// Idempotent: same revision, no-op.
		if ( $incoming_revision === $current_revision && $current_revision > 0 ) {
			return new \WP_REST_Response(
				array(
					'status'          => 'applied',
					'message'         => 'Connection config already at this revision.',
					'config_revision' => $current_revision,
				),
				200
			);
		}

		// Apply connection config to WordPress options.
		if ( isset( $body['api_url'] ) ) {
			update_option( 'def_core_staff_ai_api_url', esc_url_raw( $body['api_url'] ) );
		}

		update_option( 'def_core_api_key', sanitize_text_field( $body['api_key'] ), false );

		if ( isset( $body['allowed_origins'] ) && is_array( $body['allowed_origins'] ) ) {
			$origins = array();
			foreach ( $body['allowed_origins'] as $origin ) {
				$origin = trim( (string) $origin );
				if ( '' !== $origin ) {
					$origins[] = esc_url_raw( $origin );
				}
			}
			update_option( DEF_CORE_OPTION_ALLOWED_ORIGINS, $origins, false );
		}

		if ( isset( $body['external_jwks_url'] ) ) {
			$jwks_url = esc_url_raw( $body['external_jwks_url'], array( 'http', 'https' ) );
			update_option( 'def_core_external_jwks_url', $jwks_url );
			// Clear cached JWKS when URL changes.
			delete_transient( 'def_core_external_jwks_' . md5( $jwks_url ) );
		}

		if ( isset( $body['external_issuer'] ) ) {
			update_option( 'def_core_external_issuer', esc_url_raw( $body['external_issuer'] ) );
		}

		// Handle service auth secret update.
		$current_secret = get_option( 'def_service_auth_secret', '' );
		$new_secret     = sanitize_text_field( $body['service_auth_secret'] );
		if ( $new_secret !== $current_secret ) {
			// Store previous secret for dual-key rotation window.
			if ( ! empty( $current_secret ) ) {
				update_option( self::OPTION_PREVIOUS_SECRET, $current_secret, false );
				update_option( self::OPTION_ROTATION_EXPIRES, time() + self::ROTATION_WINDOW_SECONDS, false );
			}
			update_option( 'def_service_auth_secret', $new_secret, false );
		}

		// Handle previous API key for dual-key rotation.
		if ( ! empty( $body['previous_api_key'] ) ) {
			update_option( self::OPTION_PREVIOUS_API_KEY, sanitize_text_field( $body['previous_api_key'] ), false );
			// Set rotation expiry if not already set by secret rotation.
			$rotation_expires = (int) get_option( self::OPTION_ROTATION_EXPIRES, 0 );
			if ( $rotation_expires < time() ) {
				update_option( self::OPTION_ROTATION_EXPIRES, time() + self::ROTATION_WINDOW_SECONDS, false );
			}
		}

		// Handle previous service auth secret for dual-key rotation.
		if ( ! empty( $body['previous_service_auth_secret'] ) ) {
			update_option( self::OPTION_PREVIOUS_SECRET, sanitize_text_field( $body['previous_service_auth_secret'] ), false );
			$rotation_expires = (int) get_option( self::OPTION_ROTATION_EXPIRES, 0 );
			if ( $rotation_expires < time() ) {
				update_option( self::OPTION_ROTATION_EXPIRES, time() + self::ROTATION_WINDOW_SECONDS, false );
			}
		}

		// Update revision and timestamp.
		update_option( self::OPTION_REVISION, $incoming_revision );
		update_option( self::OPTION_LAST_SYNC, current_time( 'Y-m-d H:i:s' ) );

		// Clear cached connection test result.
		delete_transient( 'def_core_connection_test' );

		return new \WP_REST_Response(
			array(
				'status'          => 'applied',
				'message'         => 'Connection config applied successfully.',
				'config_revision' => $incoming_revision,
			),
			200
		);
	}

	/**
	 * Get connection status for admin UI and external health monitoring.
	 *
	 * GET /wp-json/def-core/v1/connection-status
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The response.
	 */
	public static function get_connection_status( \WP_REST_Request $request ): \WP_REST_Response {
		$api_url         = get_option( 'def_core_staff_ai_api_url', '' );
		$api_key         = get_option( 'def_core_api_key', '' );
		$config_revision = (int) get_option( self::OPTION_REVISION, 0 );
		$last_sync       = get_option( self::OPTION_LAST_SYNC, '' );

		// Determine connection status.
		$def_connected = ! empty( $api_url ) && ! empty( $api_key ) && $config_revision > 0;

		// Get plugin version.
		$plugin_data   = get_file_data( DEF_CORE_PLUGIN_DIR . 'def-core.php', array( 'Version' => 'Version' ) );
		$plugin_version = $plugin_data['Version'] ?? '0.0.0';

		return new \WP_REST_Response(
			array(
				'plugin_version'      => $plugin_version,
				'def_connected'       => $def_connected,
				'last_config_revision' => $config_revision,
				'last_sync_at'        => $last_sync ?: null,
			),
			200
		);
	}
}
