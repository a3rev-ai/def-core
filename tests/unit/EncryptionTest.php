<?php
/**
 * PHPUnit tests for DEF_Core_Encryption.
 *
 * Converted from tests/test-encryption.php — all original test cases preserved.
 *
 * @package def-core/tests/unit
 */

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers DEF_Core_Encryption
 */
final class EncryptionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->reset_state();
	}

	private function reset_state(): void {
		_wp_test_reset_options();
		global $_wp_test_transients, $_wp_test_salts;
		$_wp_test_transients = array();
		$_wp_test_salts = array(
			'auth'        => 'test-auth-salt-abcdef1234567890',
			'secure_auth' => 'test-secure-auth-salt-1234567890abcdef',
		);
	}

	// ── 1. Sodium encrypt/decrypt round-trip ────────────────────────────

	public function test_sodium_encrypt_returns_non_null(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'sodium not available' );
		}
		$encrypted = DEF_Core_Encryption::encrypt( 'my-super-secret-api-key-12345' );
		$this->assertNotNull( $encrypted );
	}

	public function test_sodium_encrypted_differs_from_plaintext(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'sodium not available' );
		}
		$plaintext = 'my-super-secret-api-key-12345';
		$encrypted = DEF_Core_Encryption::encrypt( $plaintext );
		$this->assertNotSame( $plaintext, $encrypted );
	}

	public function test_sodium_blob_has_version_1(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'sodium not available' );
		}
		$encrypted = DEF_Core_Encryption::encrypt( 'my-super-secret-api-key-12345' );
		$data = json_decode( $encrypted, true );
		$this->assertSame( 1, $data['version'] );
		$this->assertArrayHasKey( 'nonce', $data );
		$this->assertArrayHasKey( 'ciphertext', $data );
	}

	public function test_sodium_decrypt_roundtrip(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'sodium not available' );
		}
		$plaintext = 'my-super-secret-api-key-12345';
		$encrypted = DEF_Core_Encryption::encrypt( $plaintext );
		$this->assertSame( $plaintext, DEF_Core_Encryption::decrypt( $encrypted ) );
	}

	public function test_sodium_different_nonces_produce_different_blobs(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'sodium not available' );
		}
		$plaintext  = 'my-super-secret-api-key-12345';
		$encrypted1 = DEF_Core_Encryption::encrypt( $plaintext );
		$encrypted2 = DEF_Core_Encryption::encrypt( $plaintext );
		$this->assertNotSame( $encrypted1, $encrypted2 );
	}

	public function test_sodium_both_blobs_decrypt_same(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'sodium not available' );
		}
		$plaintext  = 'my-super-secret-api-key-12345';
		$encrypted1 = DEF_Core_Encryption::encrypt( $plaintext );
		$encrypted2 = DEF_Core_Encryption::encrypt( $plaintext );
		$this->assertSame( $plaintext, DEF_Core_Encryption::decrypt( $encrypted1 ) );
		$this->assertSame( $plaintext, DEF_Core_Encryption::decrypt( $encrypted2 ) );
	}

	// ── 2. GCM encrypt/decrypt round-trip ───────────────────────────────

	public function test_gcm_decrypt_roundtrip(): void {
		$plaintext = 'test-gcm-secret-value';
		$ikm       = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		$key       = hash_hkdf( 'sha256', $ikm, 32, 'def_core_secret_encryption', 'def-core-encryption-v1' );
		$iv        = random_bytes( 12 );
		$tag       = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );

		$blob = wp_json_encode( array(
			'version'    => 2,
			'iv'         => base64_encode( $iv ),
			'ciphertext' => base64_encode( $ciphertext ),
			'tag'        => base64_encode( $tag ),
		) );

		$this->assertSame( $plaintext, DEF_Core_Encryption::decrypt( $blob ) );
	}

	public function test_gcm_blob_has_version_2(): void {
		$iv  = random_bytes( 12 );
		$tag = '';
		$ikm = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		$key = hash_hkdf( 'sha256', $ikm, 32, 'def_core_secret_encryption', 'def-core-encryption-v1' );
		openssl_encrypt( 'x', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );

		$blob = wp_json_encode( array(
			'version'    => 2,
			'iv'         => base64_encode( $iv ),
			'ciphertext' => base64_encode( 'fake' ),
			'tag'        => base64_encode( $tag ),
		) );
		$data = json_decode( $blob, true );
		$this->assertSame( 2, $data['version'] );
	}

	// ── 3. Legacy plaintext auto-migration ──────────────────────────────

	public function test_legacy_plaintext_returned_and_encrypted(): void {
		update_option( 'def_core_api_key', 'legacy-plaintext-key-abc123' );

		$result = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
		$this->assertSame( 'legacy-plaintext-key-abc123', $result );

		// After auto-migration, stored value should be encrypted JSON.
		$stored = get_option( 'def_core_api_key' );
		$parsed = json_decode( $stored, true );
		$this->assertIsArray( $parsed );
		$this->assertArrayHasKey( 'version', $parsed );

		// Second read still correct.
		$this->assertSame( 'legacy-plaintext-key-abc123', DEF_Core_Encryption::get_secret( 'def_core_api_key' ) );
	}

	// ── 4. Salt rotation detection ──────────────────────────────────────

	public function test_salt_rotation_returns_null_and_sets_transient(): void {
		DEF_Core_Encryption::set_secret( 'def_core_api_key', 'secret-before-rotation' );
		$this->assertSame( 'secret-before-rotation', DEF_Core_Encryption::get_secret( 'def_core_api_key' ) );

		// Rotate salts.
		global $_wp_test_salts;
		$_wp_test_salts['auth']        = 'completely-new-auth-salt-after-rotation';
		$_wp_test_salts['secure_auth'] = 'completely-new-secure-auth-salt-after-rotation';

		$this->assertNull( DEF_Core_Encryption::get_secret( 'def_core_api_key' ) );
		$this->assertTrue( get_transient( DEF_Core_Encryption::TRANSIENT_ENCRYPTION_ERROR ) );
	}

	// ── 5. Empty value → null ───────────────────────────────────────────

	public function test_no_option_stored_returns_null(): void {
		$this->assertNull( DEF_Core_Encryption::get_secret( 'def_core_api_key' ) );
	}

	public function test_empty_string_stored_returns_null(): void {
		update_option( 'def_core_api_key', '' );
		$this->assertNull( DEF_Core_Encryption::get_secret( 'def_core_api_key' ) );
	}

	public function test_decrypt_empty_string_returns_null(): void {
		$this->assertNull( DEF_Core_Encryption::decrypt( '' ) );
	}

	// ── 6. set_secret + get_secret round-trip ───────────────────────────

	public function test_set_get_secret_roundtrip(): void {
		$ok = DEF_Core_Encryption::set_secret( 'def_core_api_key', 'round-trip-test-key' );
		$this->assertTrue( $ok );
		$this->assertSame( 'round-trip-test-key', DEF_Core_Encryption::get_secret( 'def_core_api_key' ) );

		$stored = get_option( 'def_core_api_key' );
		$this->assertNotSame( 'round-trip-test-key', $stored );
		$parsed = json_decode( $stored, true );
		$this->assertArrayHasKey( 'version', $parsed );
	}

	// ── 7. Malformed blob — missing fields ──────────────────────────────

	public function test_sodium_blob_missing_nonce_returns_null(): void {
		$blob = wp_json_encode( array( 'version' => 1, 'ciphertext' => base64_encode( 'fake' ) ) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	public function test_sodium_blob_missing_ciphertext_returns_null(): void {
		$blob = wp_json_encode( array( 'version' => 1, 'nonce' => base64_encode( 'fake' ) ) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	public function test_gcm_blob_missing_iv_returns_null(): void {
		$blob = wp_json_encode( array(
			'version'    => 2,
			'ciphertext' => base64_encode( 'fake' ),
			'tag'        => base64_encode( 'fake' ),
		) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	public function test_gcm_blob_missing_tag_returns_null(): void {
		$blob = wp_json_encode( array(
			'version'    => 2,
			'iv'         => base64_encode( 'fake' ),
			'ciphertext' => base64_encode( 'fake' ),
		) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	public function test_gcm_blob_missing_ciphertext_returns_null(): void {
		$blob = wp_json_encode( array(
			'version' => 2,
			'iv'      => base64_encode( 'fake' ),
			'tag'     => base64_encode( 'fake' ),
		) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	// ── 8. Malformed blob — invalid base64 ──────────────────────────────

	public function test_sodium_invalid_base64_nonce_returns_null(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'sodium not available' );
		}
		$blob = wp_json_encode( array(
			'version'    => 1,
			'nonce'      => '!!!not-valid-base64!!!',
			'ciphertext' => base64_encode( 'fake' ),
		) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	public function test_sodium_invalid_base64_ciphertext_returns_null(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'sodium not available' );
		}
		$blob = wp_json_encode( array(
			'version'    => 1,
			'nonce'      => base64_encode( str_repeat( 'x', SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) ),
			'ciphertext' => '!!!not-valid-base64!!!',
		) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	public function test_gcm_invalid_base64_iv_returns_null(): void {
		$blob = wp_json_encode( array(
			'version'    => 2,
			'iv'         => '!!!not-valid-base64!!!',
			'ciphertext' => base64_encode( 'fake' ),
			'tag'        => base64_encode( str_repeat( 'x', 16 ) ),
		) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	// ── 9. Malformed blob — wrong lengths ───────────────────────────────

	public function test_sodium_nonce_too_short_returns_null(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'sodium not available' );
		}
		$blob = wp_json_encode( array(
			'version'    => 1,
			'nonce'      => base64_encode( str_repeat( 'x', 10 ) ),
			'ciphertext' => base64_encode( 'fake' ),
		) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	public function test_sodium_nonce_too_long_returns_null(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'sodium not available' );
		}
		$blob = wp_json_encode( array(
			'version'    => 1,
			'nonce'      => base64_encode( str_repeat( 'x', 48 ) ),
			'ciphertext' => base64_encode( 'fake' ),
		) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	public function test_gcm_iv_too_short_returns_null(): void {
		$blob = wp_json_encode( array(
			'version'    => 2,
			'iv'         => base64_encode( str_repeat( 'x', 5 ) ),
			'ciphertext' => base64_encode( 'fake' ),
			'tag'        => base64_encode( str_repeat( 'x', 16 ) ),
		) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	public function test_gcm_iv_too_long_returns_null(): void {
		$blob = wp_json_encode( array(
			'version'    => 2,
			'iv'         => base64_encode( str_repeat( 'x', 24 ) ),
			'ciphertext' => base64_encode( 'fake' ),
			'tag'        => base64_encode( str_repeat( 'x', 16 ) ),
		) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	public function test_gcm_tag_too_short_returns_null(): void {
		$blob = wp_json_encode( array(
			'version'    => 2,
			'iv'         => base64_encode( str_repeat( 'x', 12 ) ),
			'ciphertext' => base64_encode( 'fake' ),
			'tag'        => base64_encode( str_repeat( 'x', 8 ) ),
		) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	public function test_gcm_tag_too_long_returns_null(): void {
		$blob = wp_json_encode( array(
			'version'    => 2,
			'iv'         => base64_encode( str_repeat( 'x', 12 ) ),
			'ciphertext' => base64_encode( 'fake' ),
			'tag'        => base64_encode( str_repeat( 'x', 32 ) ),
		) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	// ── 10. Unknown version ─────────────────────────────────────────────

	public function test_unknown_version_returns_null(): void {
		$blob = wp_json_encode( array( 'version' => 99, 'data' => 'something' ) );
		$this->assertNull( DEF_Core_Encryption::decrypt( $blob ) );
	}

	// ── 11. JSON without version → plaintext ────────────────────────────

	public function test_json_without_version_treated_as_plaintext(): void {
		$json_value = '{"foo":"bar","baz":123}';
		update_option( 'test_option', $json_value );
		$this->assertSame( $json_value, DEF_Core_Encryption::get_secret( 'test_option' ) );
	}

	// ── 12. Successful decrypt does not set error transient ─────────────

	public function test_successful_decrypt_no_error_transient(): void {
		DEF_Core_Encryption::set_secret( 'def_core_api_key', 'valid-secret' );
		$this->assertSame( 'valid-secret', DEF_Core_Encryption::get_secret( 'def_core_api_key' ) );
		$this->assertFalse( get_transient( DEF_Core_Encryption::TRANSIENT_ENCRYPTION_ERROR ) );
	}

	// ── 13. Multiple secrets independent ────────────────────────────────

	public function test_multiple_secrets_independent(): void {
		DEF_Core_Encryption::set_secret( 'def_core_api_key', 'api-key-value' );
		DEF_Core_Encryption::set_secret( 'def_service_auth_secret', 'service-secret-value' );

		$this->assertSame( 'api-key-value', DEF_Core_Encryption::get_secret( 'def_core_api_key' ) );
		$this->assertSame( 'service-secret-value', DEF_Core_Encryption::get_secret( 'def_service_auth_secret' ) );
	}

	// ── 14. Special characters ──────────────────────────────────────────

	public function test_special_characters_survive_roundtrip(): void {
		$special = 'key-with-"quotes"-and-{braces}-and-$dollar-and-nüll-bytes-and-✓-unicode';
		DEF_Core_Encryption::set_secret( 'test_special', $special );
		$this->assertSame( $special, DEF_Core_Encryption::get_secret( 'test_special' ) );
	}

}
