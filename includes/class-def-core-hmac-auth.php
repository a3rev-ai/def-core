<?php
/**
 * Class DEF_Core_HMAC_Auth
 *
 * Shared HMAC authentication verifier for all DEF → WordPress machine-to-machine
 * calls. Used by both a3-ai/v1 (admin/tool API) and def-core/v1 (export/knowledge
 * API) route families.
 *
 * One auth contract, one verifier, one test matrix.
 *
 * Protocol:
 * - DEF signs: {METHOD}:{rest_route}:{timestamp}:{user_id}:{body_hash}
 * - Headers: X-DEF-Signature, X-DEF-Timestamp, X-DEF-User, X-DEF-Body-Hash
 * - Verifier: constant-time comparison, timestamp freshness, body hash match
 *
 * @package def-core
 * @since 1.6.1
 */

declare(strict_types=1);

namespace A3Rev\DefCore;

class DEF_Core_HMAC_Auth {

	/**
	 * Maximum allowed age of HMAC timestamp (seconds).
	 */
	private const TIMESTAMP_TOLERANCE = 300; // 5 minutes

	/**
	 * Verify HMAC signature on a REST request.
	 *
	 * Validates: all headers present, timestamp fresh, body hash matches,
	 * signature correct via constant-time comparison.
	 *
	 * Does NOT validate WordPress user identity — machine-to-machine calls
	 * may use user_id="system" which has no WordPress user account.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return true|\WP_Error True if valid, WP_Error on failure.
	 */
	public static function verify_request( \WP_REST_Request $request ) {
		$signature = isset( $_SERVER['HTTP_X_DEF_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_SIGNATURE'] ) )
			: '';
		$timestamp = isset( $_SERVER['HTTP_X_DEF_TIMESTAMP'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_TIMESTAMP'] ) )
			: '';
		$user_id = isset( $_SERVER['HTTP_X_DEF_USER'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_USER'] ) )
			: '';
		$body_hash = isset( $_SERVER['HTTP_X_DEF_BODY_HASH'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_BODY_HASH'] ) )
			: '';

		// All 4 HMAC headers required.
		if ( empty( $signature ) || empty( $timestamp ) || empty( $user_id ) || empty( $body_hash ) ) {
			return new \WP_Error(
				'HMAC_MISSING_HEADERS',
				'Missing required HMAC authentication headers.',
				array( 'status' => 401 )
			);
		}

		// Timestamp freshness.
		$ts = intval( $timestamp );
		if ( abs( time() - $ts ) > self::TIMESTAMP_TOLERANCE ) {
			return new \WP_Error(
				'HMAC_EXPIRED',
				'HMAC timestamp expired.',
				array( 'status' => 401 )
			);
		}

		// Body hash verification.
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
		$api_key = \DEF_Core_Encryption::get_secret( 'def_core_api_key' );
		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'HMAC_NO_KEY',
				'API key not configured.',
				array( 'status' => 500 )
			);
		}

		// Build canonical payload and verify signature.
		// Includes sorted query params for tamper protection.
		$method = $request->get_method();
		$path   = $request->get_route();

		// Canonicalize query params: sorted key=value, URL-encoded.
		// Must match DEF's canonical form: urlencode(sorted(params.items()))
		$query_params = $request->get_query_params();
		ksort( $query_params );
		$canonical_qs = http_build_query( $query_params, '', '&', PHP_QUERY_RFC3986 );
		$signed_route = $canonical_qs ? "{$path}?{$canonical_qs}" : $path;

		$payload      = "{$method}:{$signed_route}:{$timestamp}:{$user_id}:{$body_hash}";
		$expected_sig = hash_hmac( 'sha256', $payload, $api_key );

		if ( ! hash_equals( $expected_sig, $signature ) ) {
			return new \WP_Error(
				'HMAC_INVALID_SIGNATURE',
				'Invalid HMAC signature.',
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for machine-to-machine routes (HMAC only).
	 *
	 * Use this for export/knowledge endpoints where the caller is always
	 * the DEF backend, not a browser user.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return true|\WP_Error
	 */
	public static function permission_check_machine( \WP_REST_Request $request ) {
		return self::verify_request( $request );
	}

	/**
	 * Permission callback for admin routes (HMAC with WordPress user validation).
	 *
	 * Use this for admin/tool endpoints where the caller identifies a
	 * WordPress user who must have def_admin_access capability.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return true|\WP_Error
	 */
	public static function permission_check_admin( \WP_REST_Request $request ) {
		$result = self::verify_request( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Validate WordPress user from X-DEF-User header.
		$user_id_header = isset( $_SERVER['HTTP_X_DEF_USER'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_USER'] ) )
			: '';

		$wp_user_id = intval( $user_id_header );
		$user       = get_user_by( 'id', $wp_user_id );

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
}
