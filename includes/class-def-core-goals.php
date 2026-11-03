<?php
/**
 * DEF Core — Goal outcome reports (W75 G2).
 *
 * Tells DEF when a chatting visitor reaches an outcome a tenant can set as a
 * Goal: a form submitted, a page reached, an order placed, a hand-off sent.
 * The chat reference rides the first-party `def_chat_ref` cookie the widget
 * mirrors from `a3rev_thread_id`, so no form is edited and nothing is saved to
 * an entry. A report carries no personal data: the type, a plugin/form id or a
 * path, the chat reference, when, and a salted key (runsheet §4).
 *
 * Every report is queued (wp_schedule_single_event) and sent from WP-Cron, so it
 * never blocks or fails the visitor's request. Best-effort: a failure is logged
 * and dropped.
 *
 * @package DEF_Core
 * @since 9.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues and sends Goal outcome events to DEF.
 */
final class DEF_Core_Goals {

	const CRON_HOOK        = 'def_core_goal_outcome';
	const COOKIE           = 'def_chat_ref';
	const CHAT_REF_PATTERN = '/^orch-[0-9a-f]{16}$/';
	const PAGE_RATE_LIMIT  = 30;
	const PAGE_PATH_MAX    = 500;
	const MAX_PENDING      = 1000;

	/**
	 * Register the queue worker, the page route and the outcome hooks.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'send' ), 10, 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Orders: classic checkout after the save; Store API after the stamp (10).
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'report_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'report_order' ), 20, 1 );

		// Forms.
		add_action( 'gform_after_submission', array( __CLASS__, 'on_gravityforms' ), 10, 2 );
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'on_contact_form_7' ), 10, 1 );
		add_action( 'wpforms_process_complete', array( __CLASS__, 'on_wpforms' ), 10, 3 );
		add_action( 'fluentform/submission_inserted', array( __CLASS__, 'on_fluentform' ), 10, 3 );
		add_action( 'fluentform_submission_inserted', array( __CLASS__, 'on_fluentform' ), 10, 3 ); // Legacy name; current versions fire both (deduped below).
		add_action( 'frm_after_create_entry', array( __CLASS__, 'on_formidable' ), 10, 3 );
		add_action( 'ninja_forms_after_submission', array( __CLASS__, 'on_ninja_forms' ), 10, 1 );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'on_elementor' ), 10, 1 );
	}

	/**
	 * The chat reference from the cookie, or '' when absent or not of DEF's shape.
	 */
	public static function chat_ref(): string {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return preg_match( self::CHAT_REF_PATTERN, $raw ) ? $raw : '';
	}

	/**
	 * Queue one outcome. Skipped for a chat reference not of DEF's shape, or
	 * when the site is not connected.
	 *
	 * @param string $type     form_submitted | page_reached | order_placed | handoff_sent.
	 * @param string $ref      '<plugin>:<form id>', a path, or ''.
	 * @param string $chat_ref The chat reference.
	 * @param string $key      What the idempotency key hashes (§4).
	 */
	public static function queue( string $type, string $ref, string $chat_ref, string $key ): void {
		if ( ! preg_match( self::CHAT_REF_PATTERN, $chat_ref ) || empty( DEF_Core_Encryption::get_secret( 'def_core_api_key' ) ) ) {
			return;
		}
		wp_schedule_single_event(
			time(),
			self::CRON_HOOK,
			array(
				array(
					'type'            => $type,
					'ref'             => $ref,
					'chat_ref'        => $chat_ref,
					'occurred_at'     => gmdate( 'c' ),
					'idempotency_key' => wp_hash( $key ),
				),
			)
		);
	}

	/**
	 * A form submission by a chatting visitor.
	 *
	 * @param string $plugin  The plugin's slug.
	 * @param mixed  $form_id The form's id.
	 */
	private static function form( string $plugin, $form_id ): void {
		if ( ! is_scalar( $form_id ) || '' === (string) $form_id ) {
			return;
		}
		$chat_ref = self::chat_ref();
		$ref      = $plugin . ':' . $form_id;
		self::queue( 'form_submitted', $ref, $chat_ref, 'form:' . $ref . ':' . $chat_ref );
	}

	/**
	 * Cron worker: POST one outcome to DEF. No-ops when not connected.
	 *
	 * @param mixed $payload The queued outcome.
	 */
	public static function send( $payload ): void {
		$api_key = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
		if ( empty( $api_key ) || ! is_array( $payload ) ) {
			return;
		}

		$response = wp_remote_post(
			DEF_Core::get_def_api_url_internal() . '/api/wp-sync/goals/outcome',
			array(
				'timeout' => 15,
				'headers' => array(
					'X-DEF-API-Key' => $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			DEF_Core_Logger::warning(
				DEF_Core_Logger::SOURCE_SYNC,
				'Goal outcome not delivered',
				array(
					'type'   => (string) ( $payload['type'] ?? '' ),
					'status' => $code,
				)
			);
		}
	}

	// ── Page leg ────────────────────────────────────────────────────────

	/**
	 * POST /wp-json/a3-ai/v1/goals/page — the widget reports the page a chatting
	 * visitor reached. REST is never page-cached, so a cached thank-you page still
	 * reports. No nonce: a cached page's nonce goes stale, and a forged report can
	 * only count a chat once per path (§4).
	 */
	public static function register_routes(): void {
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/goals/page',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_page' ),
			)
		);
	}

	/**
	 * Queue page_reached, and re-issue the cookie server-side (Safari caps a
	 * JS-set cookie at 7 days).
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public static function rest_page( WP_REST_Request $request ): WP_REST_Response {
		$chat_ref = self::chat_ref();
		if ( '' !== $chat_ref ) {
			if ( ! headers_sent() ) {
				setcookie(
					self::COOKIE,
					$chat_ref,
					array(
						'expires'  => time() + 30 * DAY_IN_SECONDS,
						'path'     => '/',
						'secure'   => true,
						'httponly' => false,
						'samesite' => 'Lax',
					)
				);
			}
			if ( ! DEF_Core_Escalation::check_rate_limit( 'goals_page:' . DEF_Core_Escalation::get_client_ip(), self::PAGE_RATE_LIMIT ) ) {
				return new WP_REST_Response( null, 429 );
			}
			// The path alone: a form plugin can put field values in the query string.
			$path = (string) wp_parse_url( (string) $request->get_param( 'path' ), PHP_URL_PATH );
			if ( '' !== $path && '/' === $path[0] && strlen( $path ) <= self::PAGE_PATH_MAX
				&& ! self::is_order_path( $path ) && self::pending() < self::MAX_PENDING ) {
				self::queue( 'page_reached', $path, $chat_ref, 'page:' . $chat_ref . ':' . $path );
			}
		}
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * A WooCommerce order-received, view-order or order-pay path carries the order
	 * number; the order leg reports the order instead.
	 *
	 * @param string $path The page path.
	 */
	private static function is_order_path( string $path ): bool {
		$endpoints = array(
			'woocommerce_checkout_order_received_endpoint' => 'order-received',
			'woocommerce_myaccount_view_order_endpoint'    => 'view-order',
			'woocommerce_checkout_pay_endpoint'            => 'order-pay',
		);
		foreach ( $endpoints as $option => $default ) {
			$slug = (string) get_option( $option, $default );
			if ( '' !== $slug && false !== strpos( $path, '/' . $slug . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reports still waiting in WP-Cron — a ceiling that per-IP limits cannot give
	 * when IPs rotate.
	 */
	private static function pending(): int {
		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			$count += count( $hooks[ self::CRON_HOOK ] ?? array() );
		}
		return $count;
	}

	// ── Order leg ───────────────────────────────────────────────────────

	/**
	 * An order counts when placed, before payment (G-7). The chat reference is
	 * the one DEF_Core_Chat_Attribution stamped on the order.
	 *
	 * @param mixed $order WC_Order, or the order id (classic checkout).
	 */
	public static function report_order( $order ): void {
		if ( ! is_a( $order, 'WC_Order' ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		$chat_ref = (string) $order->get_meta( DEF_Core_Chat_Attribution::ORDER_META_KEY );
		self::queue( 'order_placed', '', $chat_ref, 'order:' . $order->get_id() );
	}

	// ── Form leg: each plugin's own after-submission hook ──────────────

	/**
	 * Gravity Forms.
	 *
	 * @param mixed $entry The entry.
	 * @param mixed $form  The form.
	 */
	public static function on_gravityforms( $entry, $form ): void {
		if ( 'spam' === ( $entry['status'] ?? '' ) ) {
			return;
		}
		self::form( 'gravityforms', $form['id'] ?? '' );
	}

	/**
	 * Contact Form 7 (spam never reaches mail_sent).
	 *
	 * @param mixed $contact_form WPCF7_ContactForm.
	 */
	public static function on_contact_form_7( $contact_form ): void {
		if ( is_object( $contact_form ) && method_exists( $contact_form, 'id' ) ) {
			self::form( 'contact-form-7', $contact_form->id() );
		}
	}

	/**
	 * WPForms (a submission marked spam never reaches the hook).
	 *
	 * @param mixed $fields    The fields.
	 * @param mixed $entry     The entry.
	 * @param mixed $form_data The form data.
	 */
	public static function on_wpforms( $fields, $entry, $form_data ): void {
		self::form( 'wpforms', $form_data['id'] ?? '' );
	}

	/**
	 * Fluent Forms. Current versions fire the legacy hook and the new one back to
	 * back, so a submission is reported once; one marked spam still fires them.
	 *
	 * @param mixed $insert_id The submission id.
	 * @param mixed $form_data The submitted data.
	 * @param mixed $form      The form.
	 */
	public static function on_fluentform( $insert_id, $form_data, $form ): void {
		if ( '' === self::chat_ref() ) {
			return;
		}
		global $wpdb;
		static $seen = array();
		$insert_id   = (int) $insert_id;
		if ( isset( $seen[ $insert_id ] ) ) {
			return;
		}
		$seen[ $insert_id ] = true;
		$status             = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}fluentform_submissions WHERE id = %d", $insert_id ) );
		if ( 'spam' === $status ) {
			return;
		}
		self::form( 'fluentform', is_object( $form ) ? ( $form->id ?? '' ) : '' );
	}

	/**
	 * Formidable. The hook fires for drafts (is_draft 1), saved spam (4) and
	 * repeater child entries too; only a submitted parent entry (0) counts.
	 *
	 * @param mixed $entry_id The entry id.
	 * @param mixed $form_id  The form id.
	 * @param mixed $args     ['is_child' => bool].
	 */
	public static function on_formidable( $entry_id, $form_id, $args = array() ): void {
		if ( '' === self::chat_ref() ) {
			return;
		}
		if ( ! empty( $args['is_child'] ) || ! class_exists( 'FrmEntry' ) ) {
			return;
		}
		$entry = FrmEntry::getOne( $entry_id );
		if ( ! $entry || 0 !== (int) $entry->is_draft ) {
			return;
		}
		self::form( 'formidable', $form_id );
	}

	/**
	 * Ninja Forms (spam halts before the hook; a builder preview does not).
	 *
	 * @param mixed $form_data The submission.
	 */
	public static function on_ninja_forms( $form_data ): void {
		if ( ! empty( $form_data['settings']['is_preview'] ) ) {
			return;
		}
		self::form( 'ninja-forms', $form_data['form_id'] ?? '' );
	}

	/**
	 * Elementor Pro — the id is the form widget's id.
	 *
	 * @param mixed $record ElementorPro\Modules\Forms\Classes\Form_Record.
	 */
	public static function on_elementor( $record ): void {
		if ( is_object( $record ) && method_exists( $record, 'get_form_settings' ) ) {
			self::form( 'elementor', $record->get_form_settings( 'id' ) );
		}
	}
}
