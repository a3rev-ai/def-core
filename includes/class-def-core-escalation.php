<?php
/**
 * Class DEF_Core_Escalation
 *
 * Handles escalation settings and email sending for the Digital Employee Framework.
 *
 * Implements:
 * - GET /wp-json/a3-ai/v1/settings/escalation?channel=<channel_id>
 * - POST /wp-json/a3-ai/v1/escalation/send-email
 *
 * Reference: ESCALATION_EMAIL_BRIDGE_API_CONTRACT.md
 *
 * @package def-core
 * @since 1.1.0
 * @version 1.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DEF_Core_Escalation
 *
 * Escalation email bridge for the Digital Employee Framework.
 *
 * @package def-core
 * @since 1.1.0
 * @version 1.1.0
 */
final class DEF_Core_Escalation {

	/**
	 * Valid channel IDs.
	 *
	 * @var array<string>
	 */
	private const VALID_CHANNELS = array( 'customer', 'staff_ai', 'setup_assistant' );

	/**
	 * Option key prefix for escalation settings.
	 *
	 * @var string
	 */
	private const OPTION_PREFIX = 'def_core_escalation_';

	/**
	 * Option key for service auth secret.
	 *
	 * @var string
	 */
	private const SERVICE_SECRET_OPTION = 'def_service_auth_secret';

	/**
	 * Rate-limit window length in seconds for anonymous escalation endpoints.
	 *
	 * @var int
	 */
	private const RATE_LIMIT_WINDOW_SECONDS = 60;

	/**
	 * Maximum escalation send requests allowed per rate-limit window per IP.
	 *
	 * @var int
	 */
	private const RATE_LIMIT_MAX_REQUESTS = 5;

	/**
	 * Header name for service auth.
	 *
	 * @var string
	 */
	private const SERVICE_AUTH_HEADER = 'X-DEF-AUTH';

	/**
	 * Initialize the escalation routes.
	 *
	 * @since 1.1.0
	 * @version 1.1.0
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Register REST routes for escalation.
	 *
	 * @since 1.1.0
	 * @version 1.1.0
	 */
	public static function register_rest_routes(): void {
		// GET /wp-json/a3-ai/v1/settings/escalation?channel=<channel_id>
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/settings/escalation',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'callback'            => array( __CLASS__, 'get_escalation_settings' ),
				'args'                => array(
					'channel' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( __CLASS__, 'validate_channel' ),
					),
				),
			)
		);

		// POST /wp-json/a3-ai/v1/escalation/send-email
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/escalation/send-email',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'callback'            => array( __CLASS__, 'send_escalation_email' ),
			)
		);

		// POST /wp-json/a3-ai/v1/customer-chat/send-escalation-email
		// Direct browser → def-core send path for Customer Chat escalation.
		// Mirrors the Staff AI /share-send pattern: whitelist subject + body,
		// force channel=customer server-side, delegate to send_escalation_email.
		// Auth is the WP REST nonce (same-origin), which works for both
		// logged-in users and anonymous frontend visitors.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/customer-chat/send-escalation-email',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_customer_chat_send' ),
			)
		);

		// POST /wp-json/a3-ai/v1/setup-assistant/send-escalation-email
		// Direct browser → def-core send path for Setup Assistant escalation.
		// Same pattern as customer-chat/send-escalation-email but forces
		// channel=setup_assistant. Setup Assistant only runs in wp-admin, so
		// the user is always logged in — we read their name + email from
		// wp_get_current_user() server-side (never trust the client).
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/setup-assistant/send-escalation-email',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_setup_assistant_send' ),
			)
		);
	}

	/**
	 * Permission callback for escalation routes.
	 * Accepts EITHER:
	 * - JWT authentication (logged-in user)
	 * - Service auth header (X-DEF-AUTH) for service-to-service calls
	 *
	 * @return bool True if authenticated, false otherwise.
	 * @since 1.1.0
	 * @version 1.2.0
	 */
	public static function permission_check(): bool {
		// First, try service-to-service auth (for anonymous escalation support).
		if ( self::validate_service_auth() ) {
			return true;
		}

		// Fall back to existing JWT authentication for logged-in users.
		return DEF_Core_Tools::permission_check();
	}

	/**
	 * Get or generate the service auth secret.
	 *
	 * Uses alphanumeric-only characters (hex) to avoid issues with
	 * HTTP header sanitization (sanitize_text_field, wp_unslash).
	 *
	 * @param bool $force_regenerate If true, generate a new secret even if one exists.
	 * @return string The service auth secret.
	 * @since 1.2.0
	 * @version 1.2.1
	 */
	public static function get_service_secret( bool $force_regenerate = false ): string {
		$secret = DEF_Core_Encryption::get_secret( self::SERVICE_SECRET_OPTION );

		if ( empty( $secret ) || $force_regenerate ) {
			// Generate a strong random secret using hex characters only.
			// 32 random bytes = 64 hex characters (alphanumeric, HTTP-header safe).
			// This avoids issues with special characters being altered by
			// sanitize_text_field() or wp_unslash() during validation.
			$secret = bin2hex( random_bytes( 32 ) );
			DEF_Core_Encryption::set_secret( self::SERVICE_SECRET_OPTION, $secret );
		}

		return $secret;
	}

	/**
	 * Validate service auth header.
	 * Checks X-DEF-AUTH header against stored secret.
	 *
	 * @return bool True if valid service auth, false otherwise.
	 * @since 1.2.0
	 * @version 1.2.0
	 */
	private static function validate_service_auth(): bool {
		// Get the header from various sources (Apache, nginx, etc.).
		$auth_header = '';

		// Try standard header.
		if ( ! empty( $_SERVER['HTTP_X_DEF_AUTH'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_AUTH'] ) );
		}

		// No header provided.
		if ( empty( $auth_header ) ) {
			return false;
		}

		// Get stored secret (decrypted).
		$stored_secret = DEF_Core_Encryption::get_secret( self::SERVICE_SECRET_OPTION );

		// Secret not configured.
		if ( empty( $stored_secret ) ) {
			return false;
		}

		// Constant-time comparison to prevent timing attacks.
		return hash_equals( $stored_secret, $auth_header );
	}

	/**
	 * Validate channel parameter.
	 *
	 * @param string $channel The channel value.
	 * @return bool True if valid.
	 * @since 1.1.0
	 * @version 1.1.0
	 */
	public static function validate_channel( string $channel ): bool {
		return in_array( $channel, self::VALID_CHANNELS, true );
	}

	/**
	 * Resolve the client IP for rate limiting.
	 *
	 * REMOTE_ADDR only — X-Forwarded-For is an untrusted client-supplied
	 * header and honouring it without a known-trusted proxy chain allows
	 * a spammer to trivially rotate IPs and bypass the rate limit. If the
	 * site runs behind a reverse proxy, a standard REMOTE_ADDR-rewriting
	 * plugin or server config should be used so the real client IP lands
	 * in REMOTE_ADDR before this code runs.
	 *
	 * @return string Sanitized client IP, or "unknown" if unavailable.
	 * @since 2.1.7
	 */
	private static function get_client_ip(): string {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return 'unknown';
		}
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}

	/**
	 * Coarse-grained per-IP rate limit for anonymous escalation endpoints.
	 *
	 * Fixed-window counter backed by WordPress transients, hashed so IPs
	 * are not stored cleartext in wp_options. Not atomic — two near-
	 * simultaneous requests can both increment past the limit — but this
	 * is intentional: the goal is to blunt scripted spam, not to enforce
	 * a precise cap. A small margin of error is acceptable.
	 *
	 * @param string $bucket Unique per-endpoint bucket key (e.g. "customer_chat_escalation:1.2.3.4").
	 * @return bool True if the request is within the limit, false if exceeded.
	 * @since 2.1.7
	 */
	private static function check_rate_limit( string $bucket ): bool {
		$transient_key = 'def_core_rl_' . md5( $bucket );
		$count         = (int) get_transient( $transient_key );
		if ( $count >= self::RATE_LIMIT_MAX_REQUESTS ) {
			return false;
		}
		set_transient( $transient_key, $count + 1, self::RATE_LIMIT_WINDOW_SECONDS );
		return true;
	}

	/**
	 * Get escalation settings for a channel.
	 *
	 * GET /wp-json/a3-ai/v1/settings/escalation?channel=<channel_id>
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The response object.
	 * @since 1.1.0
	 * @version 1.1.0
	 */
	public static function get_escalation_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$channel = sanitize_text_field( $request->get_param( 'channel' ) );

		// Validate channel (should be validated by args, but double-check).
		if ( ! in_array( $channel, self::VALID_CHANNELS, true ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'INVALID_CHANNEL',
					'message' => 'Invalid channel value.',
				),
				400
			);
		}

		// Get stored settings for this channel.
		$settings = self::get_channel_settings( $channel );

		return new \WP_REST_Response( $settings, 200 );
	}

	/**
	 * Get settings for a specific channel.
	 *
	 * @param string $channel The channel ID.
	 * @return array The settings array.
	 * @since 1.1.0
	 * @version 1.1.0
	 */
	private static function get_channel_settings( string $channel ): array {
		$option_key = self::OPTION_PREFIX . $channel;
		$stored     = get_option( $option_key, array() );
		$admin_email = get_option( 'admin_email' );

		// Build response with defaults per API contract.
		$settings = array(
			'channel'            => $channel,
			'to'                 => ! empty( $stored['to'] ) ? (array) $stored['to'] : array( $admin_email ),
			'cc'                 => ! empty( $stored['cc'] ) ? (array) $stored['cc'] : array(),
			'bcc'                => ! empty( $stored['bcc'] ) ? (array) $stored['bcc'] : array(),
			'sender_email'       => ! empty( $stored['sender_email'] ) ? $stored['sender_email'] : self::get_default_sender_email(),
			'send_copy_to_user'  => ! empty( $stored['send_copy_to_user'] ),
			'include_transcript' => ! empty( $stored['include_transcript'] ),
			'reply_to_mode'      => 'customer' === $channel ? 'user_email' : ( $stored['reply_to_mode'] ?? 'none' ),
			'allowed_recipients' => null,
		);

		// Staff AI channel: allowed_recipients from stored config, or auto-discover
		// all users with Staff/Management access capabilities.
		if ( 'staff_ai' === $channel ) {
			if ( ! empty( $stored['allowed_recipients'] ) ) {
				$settings['allowed_recipients'] = (array) $stored['allowed_recipients'];
			} else {
				$result = self::get_staff_management_recipients();
				$settings['allowed_recipients'] = $result['emails'];
			}
		}

		return $settings;
	}

	/**
	 * Get default sender email.
	 *
	 * @return string The default sender email.
	 * @since 1.1.0
	 * @version 1.1.0
	 */
	private static function get_default_sender_email(): string {
		$admin_email = get_option( 'admin_email' );
		$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );

		// Prefer no-reply@ on the site domain.
		if ( $site_domain ) {
			return 'no-reply@' . $site_domain;
		}

		return $admin_email;
	}

	/**
	 * Get staff/management users for Share recipient picker.
	 *
	 * Queries all users with def_staff_access or def_management_access capabilities
	 * using WordPress capability__in (WP 5.9+, multisite-safe). Returns both a
	 * canonical email list (for policy validation) and display objects (for UI).
	 *
	 * @param int  $exclude_user_id    Optional user ID to exclude (e.g., current user).
	 * @param bool $allow_admin_fallback Whether to fall back to admin_email when no users found.
	 *                                   Use true for validation paths (always need a recipient list),
	 *                                   false for UI picker paths (empty list is preferable to self).
	 * @return array { 'emails' => string[], 'recipients' => array[] }
	 * @since 1.2.7
	 */
	private static function get_staff_management_recipients( int $exclude_user_id = 0, bool $allow_admin_fallback = true ): array {
		$args = array(
			'capability__in' => array( 'def_staff_access', 'def_management_access' ),
			'fields'         => array( 'ID', 'user_email', 'display_name', 'user_login' ),
			'orderby'        => 'display_name',
			'order'          => 'ASC',
		);

		// Exclude current user from results (self-exclusion for Share UI).
		if ( $exclude_user_id > 0 ) {
			$args['exclude'] = array( $exclude_user_id );
		}

		$users = get_users( $args );

		$emails     = array();
		$recipients = array();
		$seen       = array();

		foreach ( $users as $user ) {
			// Skip users with empty email (rare, but possible with imports).
			if ( empty( $user->user_email ) ) {
				continue;
			}

			$lower = strtolower( $user->user_email );

			// Deduplicate by email (capability__in may return users with both caps).
			if ( isset( $seen[ $lower ] ) ) {
				continue;
			}
			$seen[ $lower ] = true;

			$emails[] = $lower;
			$recipients[] = array(
				'email' => $lower,
				'name'  => ! empty( $user->display_name ) ? $user->display_name : $user->user_login,
			);
		}

		// Fallback to admin_email if no staff/management users found.
		// Suppressed for UI picker paths where self-exclusion is active —
		// an empty list is preferable to reintroducing the excluded user.
		if ( empty( $emails ) && $allow_admin_fallback ) {
			$admin_email = strtolower( get_option( 'admin_email' ) );
			$admin_user  = get_user_by( 'email', get_option( 'admin_email' ) );
			$emails[]     = $admin_email;
			$recipients[] = array(
				'email' => $admin_email,
				'name'  => $admin_user ? ( $admin_user->display_name ?: $admin_user->user_login ) : $admin_email,
			);
		}

		return array(
			'emails'     => $emails,
			'recipients' => $recipients,
		);
	}

	/**
	 * Public accessor for staff/management recipients (used by Staff AI share).
	 *
	 * @param int  $exclude_user_id    Optional user ID to exclude.
	 * @param bool $allow_admin_fallback Whether to fall back to admin_email when empty.
	 * @return array { 'emails' => string[], 'recipients' => array[] }
	 * @since 1.2.7
	 */
	public static function get_staff_management_recipients_public( int $exclude_user_id = 0, bool $allow_admin_fallback = true ): array {
		return self::get_staff_management_recipients( $exclude_user_id, $allow_admin_fallback );
	}

	/**
	 * Send escalation email.
	 *
	 * Customer Chat browser → def-core escalation send.
	 *
	 * Mirrors Staff AI's rest_share_send pattern. Whitelists subject + body
	 * from the client, forces channel=customer server-side, and delegates
	 * to send_escalation_email() which handles recipient lookup from the
	 * def_core_escalation_customer option, wp_mail(), etc.
	 *
	 * Auth: WP REST nonce (X-WP-Nonce header), validated via check_ajax_referer.
	 * This works for both logged-in WordPress users and anonymous frontend
	 * visitors — the nonce is emitted at page-load time by wp_create_nonce
	 * and provides same-origin CSRF protection without requiring a login.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The response object.
	 * @since 2.1.4
	 */
	public static function rest_customer_chat_send( \WP_REST_Request $request ): \WP_REST_Response {
		// Validate WP REST nonce (CSRF protection).
		if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'INVALID_NONCE', 'message' => 'Invalid or missing nonce.' ),
				403
			);
		}

		// Per-IP rate limit. The Customer Chat endpoint is intentionally
		// reachable by anonymous visitors, so the nonce alone isn't enough
		// to prevent a scripted attacker from scraping one and looping
		// sends to spam the partner escalation inbox. 5 requests per 60s
		// per IP is well above any legitimate "user sends a follow-up
		// correction" pattern but tight enough to blunt sustained abuse.
		$ip = self::get_client_ip();
		if ( ! self::check_rate_limit( 'customer_chat_escalation:' . $ip ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'RATE_LIMITED',
					'message' => 'Too many escalation requests. Please wait a minute and try again.',
				),
				429
			);
		}

		$body = $request->get_json_params();

		// Whitelist allowed client fields — prevent customers from injecting
		// bcc, sender_email, user_copy_email, or other escalation fields.
		$allowed_keys = array( 'subject', 'body', 'reply_to' );
		$safe_body = array();
		foreach ( $allowed_keys as $key ) {
			if ( isset( $body[ $key ] ) ) {
				$safe_body[ $key ] = $body[ $key ];
			}
		}

		// Force channel=customer server-side. Never allow the client to drive
		// a different escalation channel through this route.
		$safe_body['channel'] = 'customer';

		// Delegate to the shared escalation send-email handler.
		$inner_request = new \WP_REST_Request( 'POST', '/' . DEF_CORE_API_NAME_SPACE . '/escalation/send-email' );
		$inner_request->set_header( 'Content-Type', 'application/json' );
		$inner_request->set_body( wp_json_encode( $safe_body ) );
		foreach ( $safe_body as $key => $value ) {
			$inner_request->set_param( $key, $value );
		}

		return self::send_escalation_email( $inner_request );
	}

	/**
	 * Setup Assistant browser → def-core escalation send.
	 *
	 * Mirrors rest_customer_chat_send but forces channel=setup_assistant and
	 * requires the caller to be a logged-in wp-admin user (Setup Assistant
	 * only runs in wp-admin). User name + email are read from
	 * wp_get_current_user() server-side and injected into the email body +
	 * Reply-To header — never trusted from the client.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The response object.
	 * @since 2.1.5
	 */
	public static function rest_setup_assistant_send( \WP_REST_Request $request ): \WP_REST_Response {
		// Validate WP REST nonce (CSRF protection).
		if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'INVALID_NONCE', 'message' => 'Invalid or missing nonce.' ),
				403
			);
		}

		// Setup Assistant is wp-admin only — require an authenticated user
		// with DEF admin access. Without this capability check, any logged-in
		// user (including subscribers) with a valid wp_rest nonce could POST
		// to this endpoint and spam the partner escalation email.
		if ( ! current_user_can( 'def_admin_access' ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'FORBIDDEN', 'message' => 'DEF admin access required.' ),
				403
			);
		}

		$current_user = wp_get_current_user();

		$body = $request->get_json_params();

		// Whitelist allowed client fields.
		$subject = isset( $body['subject'] ) ? (string) $body['subject'] : '';
		$message = isset( $body['body'] ) ? (string) $body['body'] : '';

		// Prepend the authenticated user's identity to the body (server-side,
		// never client-supplied). This gives the partner recipient a clear
		// "from whom" line without trusting anything in the POST payload.
		$display_name = trim( $current_user->display_name ?: ( $current_user->first_name . ' ' . $current_user->last_name ) );
		if ( '' === $display_name ) {
			$display_name = $current_user->user_login;
		}
		$from_line = 'From: ' . $display_name . ' <' . $current_user->user_email . '>';
		$site_line = 'Site: ' . home_url();
		$full_body = $from_line . "\n" . $site_line . "\n\n" . $message;

		$safe_body = array(
			'subject'  => $subject,
			'body'     => $full_body,
			'reply_to' => $current_user->user_email,
			'channel'  => 'setup_assistant',
		);

		// Delegate to the shared escalation send-email handler.
		$inner_request = new \WP_REST_Request( 'POST', '/' . DEF_CORE_API_NAME_SPACE . '/escalation/send-email' );
		$inner_request->set_header( 'Content-Type', 'application/json' );
		$inner_request->set_body( wp_json_encode( $safe_body ) );
		foreach ( $safe_body as $key => $value ) {
			$inner_request->set_param( $key, $value );
		}

		return self::send_escalation_email( $inner_request );
	}

	/**
	 * POST /wp-json/a3-ai/v1/escalation/send-email
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The response object.
	 * @since 1.1.0
	 * @version 1.1.0
	 */
	public static function send_escalation_email( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();

		// Validate required fields.
		$channel = sanitize_text_field( $body['channel'] ?? '' );
		$subject = sanitize_text_field( $body['subject'] ?? '' );
		// For plain text emails, sanitize without HTML processing to preserve newlines.
		// wp_kses_post() is for HTML content and can mangle plain text formatting.
		$email_body = sanitize_textarea_field( $body['body'] ?? '' );

		if ( empty( $channel ) || ! in_array( $channel, self::VALID_CHANNELS, true ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'VALIDATION_ERROR',
					'message' => 'Invalid or missing channel.',
				),
				400
			);
		}

		if ( empty( $subject ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'VALIDATION_ERROR',
					'message' => 'Missing subject.',
				),
				400
			);
		}

		if ( empty( $email_body ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'VALIDATION_ERROR',
					'message' => 'Missing body.',
				),
				400
			);
		}

		// Get stored settings for fallback values.
		$settings = self::get_channel_settings( $channel );

		// Determine recipients (from request or settings).
		$to = ! empty( $body['to'] ) ? array_map( 'sanitize_email', (array) $body['to'] ) : $settings['to'];
		$cc = ! empty( $body['cc'] ) ? array_map( 'sanitize_email', (array) $body['cc'] ) : $settings['cc'];
		$bcc = ! empty( $body['bcc'] ) ? array_map( 'sanitize_email', (array) $body['bcc'] ) : $settings['bcc'];
		$sender_email = ! empty( $body['sender_email'] ) ? sanitize_email( $body['sender_email'] ) : $settings['sender_email'];
		$reply_to = ! empty( $body['reply_to'] ) ? sanitize_email( $body['reply_to'] ) : '';

		// Validate recipients.
		$to = array_filter( $to, 'is_email' );
		if ( empty( $to ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'VALIDATION_ERROR',
					'message' => 'No valid recipient email addresses.',
				),
				400
			);
		}

		// For staff_ai channel, validate against allowed_recipients.
		if ( 'staff_ai' === $channel && ! empty( $settings['allowed_recipients'] ) ) {
			$allowed = array_map( 'strtolower', $settings['allowed_recipients'] );
			$to_lower = array_map( 'strtolower', $to );
			$invalid = array_diff( $to_lower, $allowed );
			if ( ! empty( $invalid ) ) {
				return new \WP_REST_Response(
					array(
						'error'   => 'VALIDATION_ERROR',
						'message' => 'Recipient not in allowed_recipients list for staff_ai channel.',
					),
					400
				);
			}
		}

		// Build email headers.
		$headers = array();
		$headers[] = 'Content-Type: text/plain; charset=UTF-8';

		if ( ! empty( $sender_email ) && is_email( $sender_email ) ) {
			$headers[] = 'From: ' . $sender_email;
		}

		if ( ! empty( $reply_to ) && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		if ( ! empty( $cc ) ) {
			foreach ( $cc as $cc_email ) {
				if ( is_email( $cc_email ) ) {
					$headers[] = 'Cc: ' . $cc_email;
				}
			}
		}

		if ( ! empty( $bcc ) ) {
			foreach ( $bcc as $bcc_email ) {
				if ( is_email( $bcc_email ) ) {
					$headers[] = 'Bcc: ' . $bcc_email;
				}
			}
		}

		// Send primary email.
		$primary_sent = wp_mail( $to, $subject, $email_body, $headers );

		if ( ! $primary_sent ) {
			return new \WP_REST_Response(
				array(
					'status' => 'failed',
					'error'  => 'wp_mail failed',
				),
				500
			);
		}

		// Send user copy if requested.
		$send_copy_to_user = ! empty( $body['send_copy_to_user'] );
		$user_copy_email = ! empty( $body['user_copy_email'] ) ? sanitize_email( $body['user_copy_email'] ) : '';

		if ( $send_copy_to_user && ! empty( $user_copy_email ) && is_email( $user_copy_email ) ) {
			$copy_subject = 'Copy: ' . $subject;
			$copy_headers = array();
			$copy_headers[] = 'Content-Type: text/plain; charset=UTF-8';

			if ( ! empty( $sender_email ) && is_email( $sender_email ) ) {
				$copy_headers[] = 'From: ' . $sender_email;
			}

			// Send copy (do not fail the whole request if copy fails).
			wp_mail( $user_copy_email, $copy_subject, $email_body, $copy_headers );
		}

		return new \WP_REST_Response(
			array( 'status' => 'sent' ),
			200
		);
	}

	/**
	 * Save escalation settings for a channel.
	 * Used by admin settings page.
	 *
	 * @param string $channel The channel ID.
	 * @param array  $settings The settings to save.
	 * @return bool True on success.
	 * @since 1.1.0
	 * @version 1.1.0
	 */
	public static function save_channel_settings( string $channel, array $settings ): bool {
		if ( ! in_array( $channel, self::VALID_CHANNELS, true ) ) {
			return false;
		}

		$option_key = self::OPTION_PREFIX . $channel;

		// Sanitize settings.
		$sanitized = array(
			'to'                 => ! empty( $settings['to'] ) ? array_map( 'sanitize_email', (array) $settings['to'] ) : array(),
			'cc'                 => ! empty( $settings['cc'] ) ? array_map( 'sanitize_email', (array) $settings['cc'] ) : array(),
			'bcc'                => ! empty( $settings['bcc'] ) ? array_map( 'sanitize_email', (array) $settings['bcc'] ) : array(),
			'sender_email'       => ! empty( $settings['sender_email'] ) ? sanitize_email( $settings['sender_email'] ) : '',
			'send_copy_to_user'  => ! empty( $settings['send_copy_to_user'] ),
			'include_transcript' => ! empty( $settings['include_transcript'] ),
			'reply_to_mode'      => ! empty( $settings['reply_to_mode'] ) ? sanitize_text_field( $settings['reply_to_mode'] ) : 'none',
		);

		// Staff AI has allowed_recipients.
		if ( 'staff_ai' === $channel && ! empty( $settings['allowed_recipients'] ) ) {
			$sanitized['allowed_recipients'] = array_map( 'sanitize_email', (array) $settings['allowed_recipients'] );
		}

		return update_option( $option_key, $sanitized );
	}
}
