<?php
/**
 * Class DEF_Core_SEO_Meta
 *
 * Internal DEF→def-core bridge for SEO-plugin metadata (Content Agent SEO-Quality
 * engine). The Content Agent's audit needs each item's **focus keyphrase** (the
 * human-set optimization target) and the apply path writes back the optimized
 * meta description / SEO title — both of which live in the active SEO plugin's
 * post meta, NOT in the wc/v3 product object. Plugin-aware (Yoast first).
 *
 * Auth mirrors the site-tools passthrough: HMAC-signed request + X-DEF-User, then
 * wp_set_current_user + a capability check in the handler (so a failed check
 * leaves no lingering user context). Reads require Staff-AI access; writes require
 * the user's own edit_post capability (same governance as the live content write).
 *
 * NEVER writes the focus keyphrase (human-owned strategy) or the slug (changing an
 * indexed URL breaks SEO).
 *
 * @package def-core
 * @since 4.2.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_SEO_Meta {

	/**
	 * Per-plugin meta keys. focus = focus keyphrase (read-only, human-owned);
	 * metadesc / title are read + written. Detection order matters (first active wins).
	 */
	private const PLUGINS = array(
		'yoast'     => array( 'focus' => '_yoast_wpseo_focuskw', 'metadesc' => '_yoast_wpseo_metadesc', 'title' => '_yoast_wpseo_title' ),
		'rank_math' => array( 'focus' => 'rank_math_focus_keyword', 'metadesc' => 'rank_math_description', 'title' => 'rank_math_title' ),
	);

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	public static function register_rest_routes(): void {
		register_rest_route( 'def-core/v1', '/content/seo-meta', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_read' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_write' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
			),
		) );
	}

	/**
	 * HMAC + X-DEF-User existence (mirrors site-tools; cap checks live in handlers).
	 *
	 * @return true|\WP_Error
	 */
	public static function permission_check( \WP_REST_Request $request ) {
		$hmac = \A3Rev\DefCore\DEF_Core_HMAC_Auth::verify_request( $request );
		if ( is_wp_error( $hmac ) ) {
			return $hmac;
		}
		$user_id = isset( $_SERVER['HTTP_X_DEF_USER'] )
			? intval( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_USER'] ) ) ) : 0;
		if ( $user_id < 1 ) {
			return new \WP_Error( 'rest_not_logged_in', 'User ID required.', array( 'status' => 401 ) );
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! $user->exists() ) {
			return new \WP_Error( 'rest_user_not_found', 'User not found.', array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Detect the active SEO plugin + its meta keys. Returns [name, keys] or
	 * ['none', []] when no supported plugin is active.
	 */
	private static function active_plugin(): array {
		if ( defined( 'WPSEO_VERSION' ) || function_exists( 'YoastSEO' ) ) {
			return array( 'yoast', self::PLUGINS['yoast'] );
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return array( 'rank_math', self::PLUGINS['rank_math'] );
		}
		return array( 'none', array() );
	}

	private static function set_user_or_forbid( int $user_id ) {
		wp_set_current_user( $user_id );
		if ( ! current_user_can( 'def_staff_access' ) && ! current_user_can( 'def_management_access' ) ) {
			return new \WP_Error( 'rest_forbidden', 'Insufficient DEF capabilities.', array( 'status' => 403 ) );
		}
		return true;
	}

	private static function req_user_id( \WP_REST_Request $request ): int {
		return isset( $_SERVER['HTTP_X_DEF_USER'] )
			? intval( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_USER'] ) ) ) : 0;
	}

	/**
	 * GET /content/seo-meta?item_id= — read the item's SEO meta (keyed by post id).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_read( \WP_REST_Request $request ) {
		$original = get_current_user_id();
		$gate     = self::set_user_or_forbid( self::req_user_id( $request ) );
		if ( is_wp_error( $gate ) ) {
			wp_set_current_user( $original );
			return $gate;
		}
		$item_id = (int) $request->get_param( 'item_id' );
		if ( $item_id < 1 || ! get_post_status( $item_id ) ) {
			wp_set_current_user( $original );
			return new \WP_Error( 'not_found', 'Item not found.', array( 'status' => 404 ) );
		}

		list( $plugin, $keys ) = self::active_plugin();
		$focus = $metadesc = $title = '';
		if ( $keys ) {
			$raw_focus = (string) get_post_meta( $item_id, $keys['focus'], true );
			// Rank Math allows comma-separated keywords; the first is primary.
			$focus    = 'rank_math' === $plugin ? trim( explode( ',', $raw_focus )[0] ) : trim( $raw_focus );
			$metadesc = (string) get_post_meta( $item_id, $keys['metadesc'], true );
			$title    = (string) get_post_meta( $item_id, $keys['title'], true );
		}

		$resp = array(
			'plugin'          => $plugin,
			'focus_keyphrase' => $focus,
			'meta_description' => $metadesc,
			'seo_title'       => $title,
			'slug'            => (string) get_post_field( 'post_name', $item_id ),
		);
		wp_set_current_user( $original );
		return new \WP_REST_Response( $resp, 200 );
	}

	/**
	 * POST /content/seo-meta — write meta_description / seo_title + the optimized
	 * stamp. Body: {item_id, meta_description?, seo_title?, optimized_keyphrase?}.
	 * NEVER writes the focus keyphrase or the slug.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_write( \WP_REST_Request $request ) {
		$original = get_current_user_id();
		$gate     = self::set_user_or_forbid( self::req_user_id( $request ) );
		if ( is_wp_error( $gate ) ) {
			wp_set_current_user( $original );
			return $gate;
		}
		$body    = $request->get_json_params();
		$item_id = isset( $body['item_id'] ) ? (int) $body['item_id'] : 0;
		if ( $item_id < 1 || ! get_post_status( $item_id ) ) {
			wp_set_current_user( $original );
			return new \WP_Error( 'not_found', 'Item not found.', array( 'status' => 404 ) );
		}
		// The write modifies content — gate on the user's own edit capability.
		if ( ! current_user_can( 'edit_post', $item_id ) ) {
			wp_set_current_user( $original );
			return new \WP_Error( 'rest_forbidden', 'Cannot edit this item.', array( 'status' => 403 ) );
		}

		list( $plugin, $keys ) = self::active_plugin();
		$written = array();
		if ( $keys ) {
			if ( isset( $body['meta_description'] ) && is_string( $body['meta_description'] ) ) {
				update_post_meta( $item_id, $keys['metadesc'], sanitize_textarea_field( $body['meta_description'] ) );
				$written[] = 'meta_description';
			}
			if ( isset( $body['seo_title'] ) && is_string( $body['seo_title'] ) ) {
				update_post_meta( $item_id, $keys['title'], sanitize_text_field( $body['seo_title'] ) );
				$written[] = 'seo_title';
			}
		}
		// Private optimized stamp (not a public taxonomy) — for the upcoming admin
		// column. Only stamp when something was actually optimized: SEO meta was
		// written, or the caller signalled the keyphrase it optimized for. A bare
		// no-op call (no plugin, no fields) must not claim the item is optimized.
		$has_keyphrase = isset( $body['optimized_keyphrase'] ) && is_string( $body['optimized_keyphrase'] ) && '' !== trim( $body['optimized_keyphrase'] );
		if ( $written || $has_keyphrase ) {
			update_post_meta( $item_id, '_def_content_optimized_at', gmdate( 'c' ) );
			if ( $has_keyphrase ) {
				update_post_meta( $item_id, '_def_optimized_keyphrase', sanitize_text_field( $body['optimized_keyphrase'] ) );
			}
		}

		wp_set_current_user( $original );
		return new \WP_REST_Response( array( 'plugin' => $plugin, 'written' => $written ), 200 );
	}
}
