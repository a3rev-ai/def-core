<?php
/**
 * Class DEF_Core_Encryption
 *
 * Authenticated encryption at rest for connection secrets.
 * Uses sodium_crypto_secretbox (XSalsa20-Poly1305) as primary,
 * with OpenSSL AES-256-GCM fallback for environments without sodium.
 *
 * Key derived via HKDF-SHA256 from WordPress salt material.
 * See: https://www.php.net/manual/en/function.sodium-crypto-secretbox.php
 *
 * Sub-PR D: Secure Key Storage in def-core
 *
 * @package def-core
 * @since 2.3.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Encryption {

	/**
	 * HKDF info string for key derivation.
	 */
	private const HKDF_INFO = 'def_core_secret_encryption';

	/**
	 * HKDF salt string for key derivation.
	 */
	private const HKDF_SALT = 'def-core-encryption-v1';

	/**
	 * Version identifier for sodium encryption.
	 */
	private const VERSION_SODIUM = 1;

	/**
	 * Version identifier for OpenSSL GCM encryption.
	 */
	private const VERSION_GCM = 2;

	/**
	 * GCM IV length in bytes (96-bit per NIST recommendation).
	 */
	private const GCM_IV_LENGTH = 12;

	/**
	 * GCM authentication tag length in bytes (128-bit).
	 */
	private const GCM_TAG_LENGTH = 16;

	/**
	 * Transient key set when decryption fails (salt rotation).
	 */
	public const TRANSIENT_ENCRYPTION_ERROR = 'def_core_encryption_error';

	/**
	 * Encrypt a plaintext secret.
	 *
	 * Uses sodium if available, otherwise falls back to OpenSSL GCM.
	 *
	 * @param string $plaintext The secret to encrypt.
	 * @return string|null JSON-encoded encrypted blob, or null on failure.
	 */
	public static function encrypt( string $plaintext ): ?string {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			return self::encrypt_sodium( $plaintext );
		}
		return self::encrypt_gcm( $plaintext );
	}

	/**
	 * Decrypt an encrypted blob or detect legacy plaintext.
	 *
	 * @param string $stored The stored value (encrypted JSON or legacy plaintext).
	 * @return string|null Decrypted plaintext, legacy plaintext, or null on failure.
	 */
	public static function decrypt( string $stored ): ?string {
		if ( empty( $stored ) ) {
			return null;
		}

		$data = json_decode( $stored, true );
		if ( ! is_array( $data ) || ! isset( $data['version'] ) ) {
			// Not encrypted — legacy plaintext.
			return $stored;
		}

		if ( self::VERSION_SODIUM === $data['version'] ) {
			return self::decrypt_sodium( $data );
		}

		if ( self::VERSION_GCM === $data['version'] ) {
			return self::decrypt_gcm( $data );
		}

		// Unknown version.
		return null;
	}

	/**
	 * Get a secret from the database, auto-encrypting legacy plaintext.
	 *
	 * This is the main entry point for reading connection secrets.
	 * Handles: empty → null, legacy plaintext → auto-encrypt, encrypted → decrypt.
	 * On decryption failure (salt rotation): returns null, sets error transient.
	 *
	 * @param string $option_name The WordPress option name.
	 * @return string|null The decrypted secret, or null if unavailable.
	 */
	public static function get_secret( string $option_name ): ?string {
		$stored = get_option( $option_name, '' );
		if ( empty( $stored ) ) {
			return null;
		}

		$data = json_decode( $stored, true );
		if ( ! is_array( $data ) || ! isset( $data['version'] ) ) {
			// Legacy plaintext — auto-encrypt and write back.
			$encrypted = self::encrypt( $stored );
			if ( null !== $encrypted ) {
				update_option( $option_name, $encrypted, false );
			}
			return $stored;
		}

		$plaintext = self::decrypt( $stored );
		if ( null === $plaintext ) {
			// Decryption failed — likely salt rotation.
			set_transient( self::TRANSIENT_ENCRYPTION_ERROR, true, DAY_IN_SECONDS );
		}
		return $plaintext;
	}

	/**
	 * Encrypt and store a secret in the database.
	 *
	 * @param string $option_name The WordPress option name.
	 * @param string $plaintext   The secret to store.
	 * @return bool True on success, false on failure.
	 */
	public static function set_secret( string $option_name, string $plaintext ): bool {
		$encrypted = self::encrypt( $plaintext );
		if ( null === $encrypted ) {
			return false;
		}
		return update_option( $option_name, $encrypted, false );
	}

	// ─── Sodium (Primary) ──────────────────────────────────────────

	/**
	 * Derive encryption key from WordPress salts for sodium.
	 *
	 * @return string 32-byte key.
	 */
	private static function derive_key_sodium(): string {
		$ikm = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return hash_hkdf( 'sha256', $ikm, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::HKDF_INFO, self::HKDF_SALT );
	}

	/**
	 * Encrypt using sodium_crypto_secretbox (XSalsa20-Poly1305).
	 *
	 * @param string $plaintext The secret to encrypt.
	 * @return string|null JSON-encoded encrypted blob.
	 */
	private static function encrypt_sodium( string $plaintext ): ?string {
		$key   = self::derive_key_sodium();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		sodium_memzero( $key );

		return wp_json_encode( array(
			'version'    => self::VERSION_SODIUM,
			'nonce'      => base64_encode( $nonce ),
			'ciphertext' => base64_encode( $ciphertext ),
		) );
	}

	/**
	 * Decrypt a sodium-encrypted blob.
	 *
	 * @param array $data Parsed JSON with version, nonce, ciphertext.
	 * @return string|null Decrypted plaintext or null on failure.
	 */
	private static function decrypt_sodium( array $data ): ?string {
		if ( ! isset( $data['nonce'], $data['ciphertext'] ) ) {
			return null; // Malformed blob — missing required fields.
		}

		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null; // Sodium not available.
		}

		$nonce      = base64_decode( $data['nonce'], true );
		$ciphertext = base64_decode( $data['ciphertext'], true );

		if ( false === $nonce || false === $ciphertext ) {
			return null; // Malformed blob — invalid base64.
		}

		if ( strlen( $nonce ) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null; // Malformed blob — wrong nonce length.
		}

		$key       = self::derive_key_sodium();
		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
		sodium_memzero( $key );

		if ( false === $plaintext ) {
			return null; // Decryption failed — key changed (salt rotation).
		}

		return $plaintext;
	}

	// ─── OpenSSL GCM (Fallback) ────────────────────────────────────

	/**
	 * Derive encryption key from WordPress salts for GCM.
	 *
	 * @return string 32-byte key for AES-256.
	 */
	private static function derive_key_gcm(): string {
		$ikm = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return hash_hkdf( 'sha256', $ikm, 32, self::HKDF_INFO, self::HKDF_SALT );
	}

	/**
	 * Encrypt using OpenSSL AES-256-GCM.
	 *
	 * @param string $plaintext The secret to encrypt.
	 * @return string|null JSON-encoded encrypted blob.
	 */
	private static function encrypt_gcm( string $plaintext ): ?string {
		$key = self::derive_key_gcm();
		$iv  = random_bytes( self::GCM_IV_LENGTH );
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::GCM_TAG_LENGTH
		);

		if ( false === $ciphertext ) {
			return null;
		}

		return wp_json_encode( array(
			'version'    => self::VERSION_GCM,
			'iv'         => base64_encode( $iv ),
			'ciphertext' => base64_encode( $ciphertext ),
			'tag'        => base64_encode( $tag ),
		) );
	}

	/**
	 * Decrypt an OpenSSL GCM-encrypted blob.
	 *
	 * @param array $data Parsed JSON with version, iv, ciphertext, tag.
	 * @return string|null Decrypted plaintext or null on failure.
	 */
	private static function decrypt_gcm( array $data ): ?string {
		if ( ! isset( $data['iv'], $data['ciphertext'], $data['tag'] ) ) {
			return null; // Malformed blob — missing required fields.
		}

		$iv         = base64_decode( $data['iv'], true );
		$ciphertext = base64_decode( $data['ciphertext'], true );
		$tag        = base64_decode( $data['tag'], true );

		if ( false === $iv || false === $ciphertext || false === $tag ) {
			return null; // Malformed blob — invalid base64.
		}

		if ( strlen( $iv ) !== self::GCM_IV_LENGTH ) {
			return null; // Malformed blob — wrong IV length.
		}

		if ( strlen( $tag ) !== self::GCM_TAG_LENGTH ) {
			return null; // Malformed blob — wrong tag length.
		}

		$key = self::derive_key_gcm();

		$plaintext = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return false === $plaintext ? null : $plaintext;
	}
}
