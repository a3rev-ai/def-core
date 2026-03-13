<?php
/**
 * Class DEF_Core_OAuth
 *
 * OAuth 2.0 client for one-click DEFHO connection.
 * Implements Authorization Code + PKCE (S256) flow.
 *
 * Flow:
 * 1. Admin clicks "Connect to DEFHO" → generates PKCE verifier + state, stores in transient
 * 2. Redirects to DEFHO /oauth/authorize with client_id=site_url, PKCE challenge, state
 * 3. Partner authorizes on DEFHO consent screen → DEFHO redirects to /wp-json/a3-ai/v1/oauth/callback
 * 4. Callback exchanges authorization code for connection config via /oauth/token
 * 5. Stores connection config (api_key, service_auth_secret, etc.) and redirects to admin
 *
 * Sub-PR E: OAuth 2.0 One-Click Connect
 *
 * @package def-core
 * @since 2.4.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_OAuth {

	/**
	 * DEFHO frontend URL — used for browser redirects (consent screen).
	 * Overridable via DEF_DEFHO_URL constant in wp-config.php.
	 */
	private const DEFAULT_DEFHO_URL = 'https://defho.ai';

	/**
	 * DEFHO API URL — used for server-to-server calls (token exchange).
	 * Overridable via DEF_DEFHO_API_URL constant in wp-config.php.
	 */
	private const DEFAULT_DEFHO_API_URL = 'https://api.defho.ai';

	/**
	 * Transient prefix for PKCE state storage.
	 */
	private const TRANSIENT_PREFIX = 'def_oauth_pkce_';

	/**
	 * PKCE verifier + state TTL in seconds (5 minutes).
	 */
	private const PKCE_TTL = 300;

	/**
	 * Option key for the connected DEFHO site URL.
	 */
	public const OPTION_DEFHO_SITE_URL = 'def_core_oauth_defho_url';

	/**
	 * Initialize the OAuth client.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'wp_ajax_def_core_oauth_start', array( __CLASS__, 'ajax_start_oauth' ) );
		add_action( 'wp_ajax_def_core_oauth_disconnect', array( __CLASS__, 'ajax_disconnect' ) );
	}

	/**
	 * Register the OAuth callback REST endpoint.
	 */
	public static function register_rest_routes(): void {
		// GET /wp-json/a3-ai/v1/oauth/callback — receives authorization redirect from DEFHO.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/oauth/callback',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'handle_callback' ),
			)
		);
	}

	/**
	 * Get the DEFHO frontend URL (browser redirects — consent screen).
	 *
	 * @return string DEFHO frontend URL (no trailing slash).
	 */
	public static function get_defho_url(): string {
		if ( defined( 'DEF_DEFHO_URL' ) && DEF_DEFHO_URL ) {
			return rtrim( DEF_DEFHO_URL, '/' );
		}
		return self::DEFAULT_DEFHO_URL;
	}

	/**
	 * Get the DEFHO API URL (server-to-server calls — token exchange).
	 *
	 * In production: frontend is defho.ai, API is api.defho.ai.
	 * In dev: both may be on the same host (override via DEF_DEFHO_API_URL).
	 *
	 * @return string DEFHO API URL (no trailing slash).
	 */
	public static function get_defho_api_url(): string {
		if ( defined( 'DEF_DEFHO_API_URL' ) && DEF_DEFHO_API_URL ) {
			return rtrim( DEF_DEFHO_API_URL, '/' );
		}
		return self::DEFAULT_DEFHO_API_URL;
	}

	/**
	 * Get the OAuth callback URL for this site.
	 *
	 * @return string Full callback URL.
	 */
	private static function get_callback_url(): string {
		return rest_url( DEF_CORE_API_NAME_SPACE . '/oauth/callback' );
	}

	/**
	 * Generate a cryptographically secure random string.
	 *
	 * @param int $bytes Number of random bytes.
	 * @return string URL-safe base64-encoded string.
	 */
	private static function generate_random_string( int $bytes = 48 ): string {
		$random = random_bytes( $bytes );
		return rtrim( strtr( base64_encode( $random ), '+/', '-_' ), '=' );
	}

	/**
	 * Generate PKCE code challenge from verifier (S256).
	 *
	 * @param string $verifier The code verifier.
	 * @return string Base64url-encoded SHA256 hash.
	 */
	private static function generate_code_challenge( string $verifier ): string {
		$hash = hash( 'sha256', $verifier, true );
		return rtrim( strtr( base64_encode( $hash ), '+/', '-_' ), '=' );
	}

	// ─── AJAX: Start OAuth Flow ─────────────────────────────────────

	/**
	 * AJAX handler to initiate the OAuth flow.
	 * Generates PKCE verifier + state, stores in transient, returns redirect URL.
	 */
	public static function ajax_start_oauth(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'def_core_oauth_start' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'digital-employees' ) ), 403 );
		}

		if ( ! current_user_can( 'def_admin_access' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied. DEF Admin access required.', 'digital-employees' ) ), 403 );
		}

		// Generate PKCE values.
		$code_verifier = self::generate_random_string( 48 );
		$code_challenge = self::generate_code_challenge( $code_verifier );
		$state = self::generate_random_string( 32 );

		// Store verifier + metadata in transient, keyed by state.
		$transient_key = self::TRANSIENT_PREFIX . hash( 'sha256', $state );
		set_transient( $transient_key, array(
			'code_verifier' => $code_verifier,
			'user_id'       => get_current_user_id(),
			'created_at'    => time(),
		), self::PKCE_TTL );

		// Build DEFHO authorization URL.
		$defho_url = self::get_defho_url();
		$params = array(
			'response_type'         => 'code',
			'client_id'             => home_url(),
			'redirect_uri'          => self::get_callback_url(),
			'state'                 => $state,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => 'S256',
		);

		$authorize_url = $defho_url . '/oauth/authorize?' . http_build_query( $params );

		wp_send_json_success( array(
			'redirect_url' => $authorize_url,
		) );
	}

	// ─── REST: OAuth Callback ───────────────────────────────────────

	/**
	 * Handle the OAuth callback from DEFHO.
	 *
	 * GET /wp-json/a3-ai/v1/oauth/callback?code=...&state=...
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error Redirect response or error.
	 */
	public static function handle_callback( \WP_REST_Request $request ) {
		$code  = $request->get_param( 'code' );
		$state = $request->get_param( 'state' );
		$error = $request->get_param( 'error' );

		$admin_url = admin_url( 'admin.php?page=def-core' );

		// Handle error response from DEFHO (user cancelled, etc.).
		if ( ! empty( $error ) ) {
			$error_desc = $request->get_param( 'error_description' ) ?: $error;
			$redirect = add_query_arg( array(
				'def_oauth' => 'error',
				'def_msg'   => sanitize_text_field( $error_desc ),
			), $admin_url );
			return self::redirect_response( $redirect );
		}

		// Validate required params.
		if ( empty( $code ) || empty( $state ) ) {
			$redirect = add_query_arg( array(
				'def_oauth' => 'error',
				'def_msg'   => 'Missing authorization code or state parameter.',
			), $admin_url );
			return self::redirect_response( $redirect );
		}

		// Verify the callback is being handled by a logged-in admin.
		// Note: REST API cookie auth requires a nonce, but this is a browser redirect
		// from DEFHO so no nonce is present. Validate the auth cookie directly.
		$cookie_user_id = wp_validate_auth_cookie( '', 'logged_in' );
		if ( ! $cookie_user_id ) {
			$redirect = add_query_arg( array(
				'def_oauth' => 'error',
				'def_msg'   => 'You must be logged in as a DEF admin to complete the connection.',
			), $admin_url );
			return self::redirect_response( $redirect );
		}

		$cookie_user = get_userdata( $cookie_user_id );
		if ( ! $cookie_user || ! $cookie_user->has_cap( 'def_admin_access' ) ) {
			$redirect = add_query_arg( array(
				'def_oauth' => 'error',
				'def_msg'   => 'You must be logged in as a DEF admin to complete the connection.',
			), $admin_url );
			return self::redirect_response( $redirect );
		}

		// Look up stored PKCE data by state.
		$transient_key = self::TRANSIENT_PREFIX . hash( 'sha256', $state );
		$pkce_data = get_transient( $transient_key );

		if ( empty( $pkce_data ) || ! is_array( $pkce_data ) ) {
			$redirect = add_query_arg( array(
				'def_oauth' => 'error',
				'def_msg'   => 'OAuth session expired or invalid. Please try again.',
			), $admin_url );
			return self::redirect_response( $redirect );
		}

		// Verify the current user is the same admin who initiated the flow.
		if ( $cookie_user_id !== (int) $pkce_data['user_id'] ) {
			$redirect = add_query_arg( array(
				'def_oauth' => 'error',
				'def_msg'   => 'OAuth session was started by a different user. Please try again.',
			), $admin_url );
			return self::redirect_response( $redirect );
		}

		// Consume the transient immediately (single-use).
		delete_transient( $transient_key );

		// Exchange the authorization code for connection config.
		$result = self::exchange_code(
			$code,
			$pkce_data['code_verifier'],
			$state
		);

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( array(
				'def_oauth' => 'error',
				'def_msg'   => $result->get_error_message(),
			), $admin_url );
			return self::redirect_response( $redirect );
		}

		// Apply the connection config.
		$apply_result = self::apply_connection_config( $result );
		if ( is_wp_error( $apply_result ) ) {
			$redirect = add_query_arg( array(
				'def_oauth' => 'error',
				'def_msg'   => $apply_result->get_error_message(),
			), $admin_url );
			return self::redirect_response( $redirect );
		}

		// Success — redirect to admin with success message.
		$redirect = add_query_arg( array(
			'def_oauth' => 'success',
		), $admin_url );
		return self::redirect_response( $redirect );
	}

	/**
	 * Exchange authorization code for connection config at DEFHO /oauth/token.
	 *
	 * @param string $code          The authorization code.
	 * @param string $code_verifier The PKCE code verifier.
	 * @param string $state         The state parameter.
	 * @return array|\WP_Error Connection config array or WP_Error.
	 */
	private static function exchange_code( string $code, string $code_verifier, string $state ) {
		$token_url = self::get_defho_api_url() . '/oauth/token';

		$response = wp_remote_post( $token_url, array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body' => wp_json_encode( array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => home_url(),
				'redirect_uri'  => self::get_callback_url(),
				'code_verifier' => $code_verifier,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'oauth_exchange_failed',
				sprintf( 'Failed to connect to DEFHO: %s', $response->get_error_message() )
			);
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $http_code < 200 || $http_code >= 300 ) {
			$error_msg = ! empty( $body['detail'] ) ? $body['detail'] : "HTTP $http_code from DEFHO";
			return new \WP_Error( 'oauth_exchange_failed', $error_msg );
		}

		if ( empty( $body ) || ! is_array( $body ) ) {
			return new \WP_Error( 'oauth_exchange_failed', 'Invalid response from DEFHO token endpoint.' );
		}

		return $body;
	}

	/**
	 * Apply connection config received from DEFHO OAuth token exchange.
	 *
	 * @param array $config Connection config from DEFHO.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	private static function apply_connection_config( array $config ) {
		// Validate required fields.
		$required = array( 'api_key', 'service_auth_secret', 'config_revision' );
		foreach ( $required as $field ) {
			if ( empty( $config[ $field ] ) && 0 !== ( $config[ $field ] ?? null ) ) {
				return new \WP_Error(
					'oauth_config_invalid',
					sprintf( 'Missing required field in connection config: %s', $field )
				);
			}
		}

		// Store API URL.
		if ( ! empty( $config['api_url'] ) ) {
			update_option( 'def_core_staff_ai_api_url', esc_url_raw( $config['api_url'] ) );
		} else {
			// Default to production API URL.
			$api_url = DEF_Core::get_def_api_url();
			if ( empty( $api_url ) ) {
				$api_url = 'https://api.defho.ai';
			}
			update_option( 'def_core_staff_ai_api_url', $api_url );
		}

		// Store secrets via encryption layer.
		DEF_Core_Encryption::set_secret( 'def_core_api_key', sanitize_text_field( $config['api_key'] ) );
		DEF_Core_Encryption::set_secret( 'def_service_auth_secret', sanitize_text_field( $config['service_auth_secret'] ) );

		// Store allowed origins if provided.
		if ( isset( $config['allowed_origins'] ) && is_array( $config['allowed_origins'] ) ) {
			$origins = array();
			foreach ( $config['allowed_origins'] as $origin ) {
				$origin = trim( (string) $origin );
				if ( '' !== $origin ) {
					$origins[] = esc_url_raw( $origin );
				}
			}
			update_option( DEF_CORE_OPTION_ALLOWED_ORIGINS, $origins, false );
		}

		// Store JWKS URL and issuer if provided.
		if ( ! empty( $config['external_jwks_url'] ) ) {
			$jwks_url = esc_url_raw( $config['external_jwks_url'], array( 'http', 'https' ) );
			update_option( 'def_core_external_jwks_url', $jwks_url );
			delete_transient( 'def_core_external_jwks_' . md5( $jwks_url ) );
		}

		if ( ! empty( $config['external_issuer'] ) ) {
			update_option( 'def_core_external_issuer', esc_url_raw( $config['external_issuer'] ) );
		}

		// Store revision and timestamp.
		update_option( 'def_core_conn_config_revision', (int) $config['config_revision'] );
		update_option( 'def_core_conn_last_sync_at', current_time( 'Y-m-d H:i:s' ) );

		// Store the DEFHO URL used for this connection (for disconnect).
		update_option( self::OPTION_DEFHO_SITE_URL, self::get_defho_url() );

		// Clear cached connection test result.
		delete_transient( 'def_core_connection_test' );

		return true;
	}

	// ─── AJAX: Disconnect ───────────────────────────────────────────

	/**
	 * AJAX handler to disconnect from DEFHO.
	 * Clears local connection config. Remote revoke is a follow-up feature.
	 */
	public static function ajax_disconnect(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'def_core_oauth_disconnect' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'digital-employees' ) ), 403 );
		}

		if ( ! current_user_can( 'def_admin_access' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied. DEF Admin access required.', 'digital-employees' ) ), 403 );
		}

		// Disconnect is local-only in this release.
		// TODO: Add remote revoke call when DEFHO /api/oauth/disconnect endpoint is implemented.

		// Clear local connection config.
		delete_option( 'def_core_staff_ai_api_url' );
		delete_option( 'def_core_api_key' );
		delete_option( 'def_service_auth_secret' );
		delete_option( 'def_core_conn_config_revision' );
		delete_option( 'def_core_conn_last_sync_at' );
		delete_option( self::OPTION_DEFHO_SITE_URL );
		delete_option( DEF_CORE_OPTION_ALLOWED_ORIGINS );
		delete_option( 'def_core_external_jwks_url' );
		delete_option( 'def_core_external_issuer' );

		// Clear cached data.
		delete_transient( 'def_core_connection_test' );
		delete_transient( DEF_Core_Encryption::TRANSIENT_ENCRYPTION_ERROR );

		wp_send_json_success( array(
			'message' => __( 'Disconnected from DEFHO. You can reconnect at any time.', 'digital-employees' ),
		) );
	}

	// ─── Helpers ────────────────────────────────────────────────────

	/**
	 * Create a redirect WP_REST_Response (302).
	 *
	 * @param string $url Redirect target URL.
	 * @return \WP_REST_Response Response with Location header.
	 */
	private static function redirect_response( string $url ): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );
		return $response;
	}

	/**
	 * Check if the site is currently connected via OAuth.
	 *
	 * @return bool True if connected via OAuth (DEFHO URL stored).
	 */
	public static function is_oauth_connected(): bool {
		return ! empty( get_option( self::OPTION_DEFHO_SITE_URL, '' ) );
	}
}
