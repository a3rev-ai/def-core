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
	}

	/**
	 * Permission callback for escalation routes.
	 * Uses the same JWT authentication as other def-core routes.
	 *
	 * @return bool True if authenticated, false otherwise.
	 * @since 1.1.0
	 * @version 1.1.0
	 */
	public static function permission_check(): bool {
		// Reuse existing def-core JWT authentication.
		return DEF_Core_Tools::permission_check();
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

		// Staff AI channel has allowed_recipients constraint.
		if ( 'staff_ai' === $channel ) {
			$settings['allowed_recipients'] = ! empty( $stored['allowed_recipients'] )
				? (array) $stored['allowed_recipients']
				: array( $admin_email );
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
	 * Send escalation email.
	 *
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
