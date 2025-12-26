<?php
/**
 * Class Digital_Employee_WP_Bridge_JWT
 *
 * JWT functionality for the Digital Employee WordPress Bridge plugin.
 *
 * @package digital-employee-wp-bridge
 * @since 0.1.0
 * @version 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Digital_Employee_WP_Bridge_JWT
 *
 * JWT functionality for the Digital Employee WordPress Bridge plugin.
 *
 * @package digital-employee-wp-bridge
 * @since 0.1.0
 * @version 0.1.0
 */
final class Digital_Employee_WP_Bridge_JWT {
	/**
	 * Ensure RSA keypair exists. Generate if missing.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function ensure_keys_exist(): void {
		$keys = get_option( DE_WP_BRIDGE_OPTION_KEYS );
		if ( is_array( $keys ) && ! empty( $keys['private'] ) && ! empty( $keys['public'] ) && ! empty( $keys['kid'] ) ) {
			return;
		}
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			// Fallback: store marker so we don't regen each hit; token issuing will fail without keys.
			add_option( DE_WP_BRIDGE_OPTION_KEYS, array( 'error' => 'openssl_missing' ), '', false );
			return;
		}
		$config = array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		);
		$res    = openssl_pkey_new( $config );
		if ( ! $res ) {
			return;
		}
		$priv = '';
		openssl_pkey_export( $res, $priv );
		$details = openssl_pkey_get_details( $res );
		$pub     = $details['key'] ?? '';
		$kid     = substr( sha1( $pub ), 0, 16 );
		$data    = array(
			'private' => $priv,
			'public'  => $pub,
			'kid'     => $kid,
			'created' => time(),
		);
		if ( get_option( DE_WP_BRIDGE_OPTION_KEYS ) === false ) {
			add_option( DE_WP_BRIDGE_OPTION_KEYS, $data, '', false );
		} else {
			update_option( DE_WP_BRIDGE_OPTION_KEYS, $data, false );
		}
	}

	/**
	 * Base64 URL encode.
	 *
	 * @param string $data The data to encode.
	 * @return string The encoded data.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	private static function b64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Base64 URL decode.
	 *
	 * @param string $data The data to decode.
	 * @return string The decoded data.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	private static function b64url_decode( string $data ): string {
		$remainder = strlen( $data ) % 4;
		if ( $remainder > 0 ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}
		return base64_decode( strtr( $data, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Issue a JWT token.
	 *
	 * @param array $claims The claims to include in the token.
	 * @param int   $ttl_seconds The time to live in seconds.
	 * @return string The token.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function issue_token( array $claims, int $ttl_seconds = 300 ): string {
		$keys = get_option( DE_WP_BRIDGE_OPTION_KEYS );
		if ( ! is_array( $keys ) || empty( $keys['private'] ) ) {
			self::ensure_keys_exist();
			$keys = get_option( DE_WP_BRIDGE_OPTION_KEYS );
		}
		if ( empty( $keys['private'] ) ) {
			return '';
		}
		$now     = time();
		$payload = array_merge(
			$claims,
			array(
				'iat' => $now,
				'exp' => $now + max( 60, $ttl_seconds ),
				'nbf' => $now - 30,
				'jti' => wp_generate_uuid4(),
			)
		);
		$header  = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
			'kid' => $keys['kid'] ?? '',
		);

		$header_json    = wp_json_encode( $header );
		$encoded_header = self::b64url_encode( $header_json ? $header_json : '{}' );
		$payload_json   = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		$encoded_body   = self::b64url_encode( $payload_json ? $payload_json : '{}' );
		$signing_input  = $encoded_header . '.' . $encoded_body;
		$private_key    = openssl_pkey_get_private( $keys['private'] );
		$signature      = '';
		openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
		$encoded_sig = self::b64url_encode( $signature );
		return $signing_input . '.' . $encoded_sig;
	}

	/**
	 * Get the JWKS.
	 *
	 * @return array The JWKS.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function get_jwks(): array {
		$keys = get_option( DE_WP_BRIDGE_OPTION_KEYS );
		if ( ! is_array( $keys ) || empty( $keys['public'] ) ) {
			return array( 'keys' => array() );
		}
		$pub = openssl_pkey_get_public( $keys['public'] );
		if ( ! $pub ) {
			return array( 'keys' => array() );
		}
		$details = openssl_pkey_get_details( $pub );
		if ( ! $details || empty( $details['rsa']['n'] ) || empty( $details['rsa']['e'] ) ) {
			return array( 'keys' => array() );
		}
		$n = rtrim( strtr( base64_encode( $details['rsa']['n'] ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$e = rtrim( strtr( base64_encode( $details['rsa']['e'] ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return array(
			'keys' => array(
				array(
					'kty' => 'RSA',
					'alg' => 'RS256',
					'use' => 'sig',
					'kid' => $keys['kid'] ?? '',
					'n'   => $n,
					'e'   => $e,
				),
			),
		);
	}

	/**
	 * Fetch and cache external JWKS.
	 *
	 * @param string $jwks_url The JWKS URL.
	 * @return array|null The JWKS data or null.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	private static function fetch_external_jwks( string $jwks_url ) {
		// Cache key based on URL.
		$cache_key = 'de_wp_bridge_external_jwks_' . md5( $jwks_url );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// Fetch from external URL.
		$response = wp_remote_get(
			$jwks_url,
			array(
				'timeout'     => 5,
				'httpversion' => '1.1',
				'user-agent'  => 'digital-employee-wp-bridge/' . DE_WP_BRIDGE_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['keys'] ) || ! is_array( $data['keys'] ) ) {
			return null;
		}

		// Cache for 10 minutes.
		set_transient( $cache_key, $data, 600 );
		return $data;
	}

	/**
	 * Convert JWK to PEM format public key.
	 *
	 * @param array $jwk The JWK data.
	 * @return resource|false The public key resource or false.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	private static function jwk_to_pem( array $jwk ) {
		if ( ! isset( $jwk['kty'] ) || 'RSA' !== $jwk['kty'] ) {
			return false;
		}

		if ( empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
			return false;
		}

		$n = self::b64url_decode( $jwk['n'] );
		$e = self::b64url_decode( $jwk['e'] );

		if ( false === $n || false === $e ) {
			return false;
		}

		// Create DER encoding for RSA public key.
		$der = self::create_rsa_der( $n, $e );
		if ( false === $der ) {
			return false;
		}

		// Convert to PEM format.
		$pem = "-----BEGIN PUBLIC KEY-----\n" .
				chunk_split( base64_encode( $der ), 64, "\n" ) . // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				"-----END PUBLIC KEY-----\n";

		return openssl_pkey_get_public( $pem );
	}

	/**
	 * Create RSA DER encoding.
	 *
	 * @param string $n Modulus.
	 * @param string $e Exponent.
	 * @return string|false DER encoded key or false on error.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	private static function create_rsa_der( string $n, string $e ) {
		try {
			$modulus    = pack( 'Ca*a*', 2, self::encode_length( strlen( $n ) ), $n );
			$exponent   = pack( 'Ca*a*', 2, self::encode_length( strlen( $e ) ), $e );
			$sequence   = pack( 'Ca*a*a*', 48, self::encode_length( strlen( $modulus ) + strlen( $exponent ) ), $modulus, $exponent );
			$bit_string = pack( 'Ca*a*', 3, self::encode_length( strlen( $sequence ) + 1 ), chr( 0 ) . $sequence );

			// RSA OID: 1.2.840.113549.1.1.1.
			$rsa_oid = pack( 'H*', '300d06092a864886f70d0101010500' );

			return pack( 'Ca*a*a*', 48, self::encode_length( strlen( $rsa_oid ) + strlen( $bit_string ) ), $rsa_oid, $bit_string );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Encode length for DER format.
	 *
	 * @param int $length The length to encode.
	 * @return string The encoded length.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	private static function encode_length( int $length ): string {
		if ( $length <= 127 ) {
			return chr( $length );
		}

		$temp = ltrim( pack( 'N', $length ), chr( 0 ) );
		return pack( 'Ca*', 128 | strlen( $temp ), $temp );
	}

	/**
	 * Verify token with external JWKS (for Single Sign-On).
	 *
	 * @param string $jwt The JWT to verify.
	 * @return array|null The payload or null if verification fails.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	private static function verify_token_with_external_jwks( string $jwt ) {
		// Check if external JWKS is configured.
		$external_jwks_url = get_option( 'de_wp_bridge_external_jwks_url', '' );
		$external_issuer   = get_option( 'de_wp_bridge_external_issuer', '' );

		// If not configured, return null (will fall back to local verification).
		if ( empty( $external_jwks_url ) ) {
			return null;
		}

		// Validate JWT format.
		$parts = explode( '.', $jwt );
		if ( count( $parts ) !== 3 ) {
			return null;
		}
		list( $h, $p, $s ) = $parts;

		// Decode header to get kid (key ID).
		$header_json = self::b64url_decode( $h );
		$header      = json_decode( $header_json, true );
		if ( ! is_array( $header ) ) {
			return null;
		}

		$kid = isset( $header['kid'] ) ? $header['kid'] : null;

		// Fetch external JWKS.
		$jwks = self::fetch_external_jwks( $external_jwks_url );
		if ( null === $jwks ) {
			return null;
		}

		// Find matching key by kid.
		$jwk = null;
		foreach ( $jwks['keys'] as $key ) {
			if ( ! is_array( $key ) ) {
				continue;
			}
			// Match by kid if provided, otherwise use first RSA key.
			if ( null === $kid || ( isset( $key['kid'] ) && $key['kid'] === $kid ) ) {
				$jwk = $key;
				break;
			}
		}

		if ( null === $jwk ) {
			return null;
		}

		// Convert JWK to PEM public key.
		$pub = self::jwk_to_pem( $jwk );
		if ( false === $pub ) {
			return null;
		}

		// Verify signature.
		$signing_input = $h . '.' . $p;
		$signature     = self::b64url_decode( $s );
		$ok            = openssl_verify( $signing_input, $signature, $pub, OPENSSL_ALGO_SHA256 );

		if ( 1 !== $ok ) {
			return null;
		}

		// Decode payload.
		$payload_json = self::b64url_decode( $p );
		$payload      = json_decode( $payload_json, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		// Verify issuer if configured (security check).
		if ( ! empty( $external_issuer ) ) {
			$token_issuer = isset( $payload['iss'] ) ? $payload['iss'] : '';
			// Normalize both URLs (remove trailing slash).
			$expected_issuer = rtrim( $external_issuer, '/' );
			$actual_issuer   = rtrim( $token_issuer, '/' );

			if ( $actual_issuer !== $expected_issuer ) {
				// Issuer mismatch - reject token for security.
				return null;
			}
		}

		// Verify expiration.
		$now = time();
		$exp = isset( $payload['exp'] ) ? intval( $payload['exp'] ) : 0;
		if ( $exp && $exp < $now ) {
			return null;
		}

		return $payload;
	}

	/**
	 * Verify RS256 JWT issued by this plugin or external site (SSO).
	 *
	 * @param string $jwt The JWT to verify.
	 * @return array|null The payload or null if the token is invalid.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function verify_token( string $jwt ) {
		// SECURITY: Try external JWKS first (for tokens from trusted external sites).
		// This enables Single Sign-On across WordPress sites.
		$payload = self::verify_token_with_external_jwks( $jwt );
		if ( null !== $payload ) {
			return $payload;
		}

		// Fall back to local verification (for context tokens issued by this site).
		$parts = explode( '.', $jwt );
		if ( count( $parts ) !== 3 ) {
			return null;
		}
		list( $h, $p, $s ) = $parts;
		$signing_input     = $h . '.' . $p;

		// Get local keys.
		$keys = get_option( DE_WP_BRIDGE_OPTION_KEYS );
		if ( ! is_array( $keys ) || empty( $keys['public'] ) ) {
			return null;
		}

		$pub = openssl_pkey_get_public( $keys['public'] );
		if ( ! $pub ) {
			return null;
		}

		// Verify signature with local key.
		$signature = self::b64url_decode( $s );
		$ok        = openssl_verify( $signing_input, $signature, $pub, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $ok ) {
			return null;
		}

		// Decode payload.
		$payload_json = self::b64url_decode( $p );
		$payload      = json_decode( $payload_json, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		// Verify expiration.
		$now = time();
		$exp = isset( $payload['exp'] ) ? intval( $payload['exp'] ) : 0;
		if ( $exp && $exp < $now ) {
			return null;
		}

		return $payload;
	}
}
