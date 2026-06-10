<?php
/**
 * Class DEF_Core_Media
 *
 * Media sideload bridge for the Content Agent (Engine 2, Wave 2 — images).
 *
 * DEF generates post images (tenant BYO image-model key) and pushes the BYTES
 * here; WordPress owns the assets (DEF stores nothing). Two routes:
 *   POST /content/media              → decode + validate + sideload into the
 *        Media Library as an UNATTACHED attachment, stamped `_def_created` so
 *        later cleanup can never touch a human-uploaded asset.
 *   POST /content/media/{id}/delete  → dismiss-cleanup. Refuses (404, no info
 *        leak) unless the attachment exists, is DEF-created AND still
 *        unattached — an attachment adopted by a created post is permanent.
 *
 * The payload is hostile binary from outside WordPress: the claimed MIME is
 * never trusted (finfo sniffs the real bytes, then wp_check_filetype_and_ext
 * re-validates the temp file), only png/jpeg/webp pass (never SVG — script
 * vector), size is capped, and the filename goes through sanitize_file_name
 * with a forced extension (no traversal, no double-extension smuggling).
 *
 * Also registers the `def-social` 1200×630 hard-crop image size so every
 * DEF-sideloaded image automatically gets the exact social-share rendition
 * (Yoast og:image / twitter:image are written from it at create-post time).
 * Theme featured sizes need nothing here: WP's upload machinery generates all
 * theme-registered subsizes on sideload from the high-res source DEF sends.
 *
 * Auth mirrors the blocks/SEO-meta bridges: HMAC-signed request + X-DEF-User,
 * then wp_set_current_user + capability checks in the handler (def_staff_access
 * plus the user's own upload_files / delete_post — same governance as a human
 * doing it in wp-admin).
 *
 * @package def-core
 * @since 4.7.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Media {

	/**
	 * Image types DEF may sideload: real raster formats only. SVG is excluded
	 * deliberately (XML — script/XXE vector), as is everything else.
	 */
	private const ALLOWED_TYPES = array(
		'image/png'  => 'png',
		'image/jpeg' => 'jpg',
		'image/webp' => 'webp',
	);

	/** Decoded-bytes size cap (10MB) — matches the chat upload limit. */
	private const MAX_BYTES = 10485760;

	/** Meta flag scoping later deletion to DEF-created attachments only. */
	public const CREATED_META = '_def_created';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		// Exact-crop social rendition (1200×630) for every upload from now on —
		// og:image / twitter:image want this precise size, themes rarely register
		// it. after_setup_theme: same hook themes use for their own sizes.
		add_action( 'after_setup_theme', array( __CLASS__, 'register_social_image_size' ), 11 );
	}

	public static function register_social_image_size(): void {
		add_image_size( 'def-social', 1200, 630, true );
	}

	public static function register_rest_routes(): void {
		register_rest_route( 'def-core/v1', '/content/media', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_sideload' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );
		register_rest_route( 'def-core/v1', '/content/media/(?P<id>\d+)/delete', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_delete' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );
	}

	// ---------------------------------------------------------------- auth ----

	/**
	 * HMAC + X-DEF-User existence (mirrors the blocks/SEO-meta bridges; the
	 * capability checks + user context are set in the handlers, not here).
	 *
	 * @return true|\WP_Error
	 */
	public static function permission_check( \WP_REST_Request $request ) {
		$hmac = \A3Rev\DefCore\DEF_Core_HMAC_Auth::verify_request( $request );
		if ( is_wp_error( $hmac ) ) {
			return $hmac;
		}
		$user_id = self::req_user_id( $request );
		if ( $user_id < 1 ) {
			return new \WP_Error( 'rest_not_logged_in', 'User ID required.', array( 'status' => 401 ) );
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! $user->exists() ) {
			return new \WP_Error( 'rest_user_not_found', 'User not found.', array( 'status' => 401 ) );
		}
		return true;
	}

	private static function req_user_id( \WP_REST_Request $request ): int {
		return isset( $_SERVER['HTTP_X_DEF_USER'] )
			? intval( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_USER'] ) ) ) : 0;
	}

	/**
	 * Set the acting user and require a DEF capability. Returns true|\WP_Error.
	 */
	private static function set_user_or_forbid( int $user_id, string $cap ) {
		wp_set_current_user( $user_id );
		if ( ! current_user_can( $cap ) ) {
			return new \WP_Error( 'rest_forbidden', 'Insufficient capability.', array( 'status' => 403 ) );
		}
		return true;
	}

	// ------------------------------------------------------- POST sideload ----

	/**
	 * POST /content/media — sideload one DEF-generated image.
	 *
	 * Body: { image_b64, mime: image/png|image/jpeg|image/webp, filename, alt_text }
	 *
	 * Success: { attachment_id, url }. Validation failures return the bridge's
	 * soft-error shape ({status:'invalid'|'sideload_failed', reason}, HTTP 200)
	 * so DEF distinguishes them from transport/auth errors; auth/capability
	 * failures are hard WP_Errors like every other bridge.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_sideload( \WP_REST_Request $request ) {
		$original = get_current_user_id();
		$gate     = self::set_user_or_forbid( self::req_user_id( $request ), 'def_staff_access' );
		if ( is_wp_error( $gate ) ) {
			wp_set_current_user( $original );
			return $gate;
		}
		// Media Library write → the user's own upload capability (same governance
		// as uploading in wp-admin).
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_set_current_user( $original );
			return new \WP_Error( 'rest_forbidden', 'Cannot upload files.', array( 'status' => 403 ) );
		}

		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		$mime = ( isset( $body['mime'] ) && is_string( $body['mime'] ) ) ? strtolower( trim( $body['mime'] ) ) : '';
		if ( ! isset( self::ALLOWED_TYPES[ $mime ] ) ) {
			wp_set_current_user( $original );
			return new \WP_REST_Response( array( 'status' => 'invalid', 'reason' => 'unsupported mime type' ), 200 );
		}

		$b64 = ( isset( $body['image_b64'] ) && is_string( $body['image_b64'] ) ) ? $body['image_b64'] : '';
		// Cheap pre-decode gate: an over-cap payload is rejected on string length
		// (base64 inflates ~4/3, + slack for padding/whitespace) before spending
		// memory on decoding it.
		if ( strlen( $b64 ) > self::MAX_BYTES * 4 / 3 + 1024 ) {
			wp_set_current_user( $original );
			return new \WP_REST_Response( array( 'status' => 'invalid', 'reason' => 'image exceeds 10MB limit' ), 200 );
		}
		// Strict decode: any non-base64 character (or padding garbage) fails —
		// no silent salvage of a corrupted payload.
		$bytes = base64_decode( preg_replace( '/\s+/', '', $b64 ), true );
		if ( false === $bytes || '' === $bytes ) {
			wp_set_current_user( $original );
			return new \WP_REST_Response( array( 'status' => 'invalid', 'reason' => 'invalid base64 image data' ), 200 );
		}
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			wp_set_current_user( $original );
			return new \WP_REST_Response( array( 'status' => 'invalid', 'reason' => 'image exceeds 10MB limit' ), 200 );
		}

		// Sniff the REAL bytes — the claimed mime is just a cross-check. A PHP
		// script, SVG, or polyglot renamed to .png dies here.
		$sniffed = self::sniff_mime( $bytes );
		if ( $sniffed !== $mime ) {
			wp_set_current_user( $original );
			return new \WP_REST_Response( array( 'status' => 'invalid', 'reason' => 'image bytes do not match declared mime type' ), 200 );
		}

		$filename = self::safe_filename(
			( isset( $body['filename'] ) && is_string( $body['filename'] ) ) ? $body['filename'] : '',
			self::ALLOWED_TYPES[ $mime ]
		);

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$tmp = wp_tempnam( $filename );
		// A partial write (disk full, quota) must fail too — a truncated file
		// could pass type validation while sideloading a corrupt image.
		if ( ! $tmp || strlen( $bytes ) !== file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( $tmp ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			wp_set_current_user( $original );
			return new \WP_REST_Response( array( 'status' => 'sideload_failed', 'reason' => 'could not write temp file' ), 200 );
		}

		// WP's own content-vs-extension validation on the actual file — second,
		// independent gate (and the one the rest of core trusts).
		$check = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $check['type'] ) || $check['type'] !== $mime ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_set_current_user( $original );
			return new \WP_REST_Response( array( 'status' => 'invalid', 'reason' => 'file content failed WordPress type validation' ), 200 );
		}
		if ( ! empty( $check['proper_filename'] ) ) {
			$filename = $check['proper_filename'];
		}

		// Unattached (post_parent 0) — create-post adopts the ones it uses.
		$attachment_id = media_handle_sideload(
			array( 'name' => $filename, 'tmp_name' => $tmp ),
			0
		);
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_set_current_user( $original );
			return new \WP_REST_Response( array( 'status' => 'sideload_failed', 'reason' => $attachment_id->get_error_code() ), 200 );
		}
		$attachment_id = (int) $attachment_id;

		$alt = ( isset( $body['alt_text'] ) && is_string( $body['alt_text'] ) ) ? sanitize_text_field( $body['alt_text'] ) : '';
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}
		// Scope marker: dismiss-cleanup may ONLY ever delete attachments carrying
		// this stamp — a human's Media Library is untouchable through this bridge.
		update_post_meta( $attachment_id, self::CREATED_META, 1 );

		$url = wp_get_attachment_url( $attachment_id );
		wp_set_current_user( $original );
		return new \WP_REST_Response( array(
			'attachment_id' => $attachment_id,
			'url'           => $url ? $url : '',
		), 200 );
	}

	/** finfo MIME sniff over the raw bytes; '' when unavailable/unknown. */
	private static function sniff_mime( string $bytes ): string {
		if ( ! class_exists( 'finfo' ) ) {
			return ''; // fileinfo missing → fail closed (never matches an allowlisted mime)
		}
		$finfo = new \finfo( FILEINFO_MIME_TYPE );
		$mime  = $finfo->buffer( $bytes );
		return is_string( $mime ) ? strtolower( $mime ) : '';
	}

	/**
	 * Sanitize the client filename and force the extension to match the
	 * validated type — basename + sanitize_file_name kill traversal, the forced
	 * extension kills double-extension smuggling (image.php.png stays .png,
	 * image.png.php becomes image.png_php.png… harmless either way).
	 */
	private static function safe_filename( string $filename, string $ext ): string {
		$filename = sanitize_file_name( basename( $filename ) );
		$base     = pathinfo( $filename, PATHINFO_FILENAME );
		if ( '' === $base ) {
			$base = 'def-image';
		}
		return $base . '.' . $ext;
	}

	// --------------------------------------------------------- POST delete ----

	/**
	 * POST /content/media/{id}/delete — dismiss-cleanup of a DEF-created image.
	 *
	 * One uniform 404 for "doesn't exist", "not an attachment", "not
	 * DEF-created" and "already attached to a post" — the route confirms
	 * nothing about assets it won't delete. Only after eligibility does the
	 * per-user delete_post capability gate (403).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_delete( \WP_REST_Request $request ) {
		$original = get_current_user_id();
		$gate     = self::set_user_or_forbid( self::req_user_id( $request ), 'def_staff_access' );
		if ( is_wp_error( $gate ) ) {
			wp_set_current_user( $original );
			return $gate;
		}
		$id   = (int) $request->get_param( 'id' );
		$post = $id > 0 ? get_post( $id ) : null;
		$eligible = $post
			&& 'attachment' === $post->post_type
			&& 0 === (int) $post->post_parent
			&& get_post_meta( $id, self::CREATED_META, true );
		if ( ! $eligible ) {
			wp_set_current_user( $original );
			return new \WP_Error( 'not_found', 'Item not found.', array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			wp_set_current_user( $original );
			return new \WP_Error( 'rest_forbidden', 'Cannot delete this item.', array( 'status' => 403 ) );
		}

		$deleted = wp_delete_attachment( $id, true );
		wp_set_current_user( $original );
		if ( ! $deleted ) {
			return new \WP_REST_Response( array( 'status' => 'delete_failed' ), 200 );
		}
		return new \WP_REST_Response( array( 'status' => 'deleted', 'attachment_id' => $id ), 200 );
	}
}
