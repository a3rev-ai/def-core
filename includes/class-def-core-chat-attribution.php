<?php
/**
 * Class DEF_Core_Chat_Attribution
 *
 * Stamps the originating Customer Chat thread id (`_def_chat_id`) onto the
 * WooCommerce session and then onto the resulting order, so chat-driven sales
 * can be attributed later (DEF Chat-Triage Phase 2). The id is an INTERNAL
 * analytics marker — never shown to customers, never used for auth/authz.
 *
 * Two engagement entry points set the session value:
 *  - a product-card CLICK carries `?def_chat=<thread_id>`; it is read on the
 *    front-end load, stored in the WC session, then stripped via a redirect
 *    (read-once — keeps the id out of bookmarks, server logs, and referrers).
 *  - an in-chat add-to-cart sends an `X-DEF-Chat-ID` header; it is read on the
 *    WooCommerce add-to-cart action.
 * The order hooks then copy the session value onto the order meta.
 *
 * @package def-core
 * @since 3.6.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Chat_Attribution {

	const SESSION_KEY    = '_def_chat_id';
	const ORDER_META_KEY = '_def_chat_id';
	const QUERY_PARAM    = 'def_chat';
	const MAX_LEN        = 100;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'capture_from_url' ) );
		// The Store API /cart/add-item route delegates to WC()->cart->add_to_cart(),
		// which fires this same action — so the in-chat add-to-cart header is read here.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'capture_from_header' ), 10, 0 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'stamp_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'stamp_order_with_save' ), 10, 1 );
	}

	/**
	 * Sanitize a chat id to a short, printable token. Thread ids look like
	 * "orch-xxxxxxxx", so anything outside [A-Za-z0-9_-] is dropped.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function sanitize_chat_id( $raw ): string {
		$val = sanitize_text_field( (string) $raw );
		$val = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $val );
		return substr( (string) $val, 0, self::MAX_LEN );
	}

	/**
	 * Store the chat id in the current WC session (no-op without a session).
	 *
	 * @param string $chat_id Sanitized chat id.
	 * @return void
	 */
	private static function set_session( string $chat_id ): void {
		if ( '' === $chat_id || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		WC()->session->set( self::SESSION_KEY, $chat_id );
	}

	/**
	 * Product-card click path: read `?def_chat=`, store it, then redirect to the
	 * param-stripped URL so the id is read exactly once.
	 *
	 * @return void
	 */
	public static function capture_from_url(): void {
		if ( is_admin() || ! isset( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		self::set_session( self::sanitize_chat_id( wp_unslash( $_GET[ self::QUERY_PARAM ] ) ) );
		wp_safe_redirect( remove_query_arg( self::QUERY_PARAM ) );
		exit;
	}

	/**
	 * In-chat add-to-cart path: read the X-DEF-Chat-ID request header.
	 *
	 * @return void
	 */
	public static function capture_from_header(): void {
		if ( ! isset( $_SERVER['HTTP_X_DEF_CHAT_ID'] ) ) {
			return;
		}
		self::set_session( self::sanitize_chat_id( wp_unslash( $_SERVER['HTTP_X_DEF_CHAT_ID'] ) ) );
	}

	/**
	 * Classic checkout: copy the session id onto the order (saved by the flow).
	 *
	 * @param mixed $order WC_Order.
	 * @return void
	 */
	public static function stamp_order( $order ): void {
		self::copy_to_order( $order, false );
	}

	/**
	 * Store API / block checkout: copy the session id onto the order, then save
	 * (the order is already persisted at this hook).
	 *
	 * @param mixed $order WC_Order.
	 * @return void
	 */
	public static function stamp_order_with_save( $order ): void {
		self::copy_to_order( $order, true );
	}

	/**
	 * @param mixed $order WC_Order.
	 * @param bool  $save  Whether to persist the order after writing the meta.
	 * @return void
	 */
	private static function copy_to_order( $order, bool $save ): void {
		if ( ! is_a( $order, 'WC_Order' ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$chat_id = WC()->session->get( self::SESSION_KEY );
		if ( ! $chat_id ) {
			return;
		}
		$order->update_meta_data( self::ORDER_META_KEY, (string) $chat_id );
		if ( $save ) {
			$order->save();
		}
	}
}
