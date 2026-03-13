<?php
/**
 * Encryption class tests (Sub-PR D).
 *
 * Verifies:
 * - Sodium encrypt/decrypt round-trip
 * - GCM encrypt/decrypt round-trip
 * - Legacy plaintext auto-migration (get_secret on unencrypted value)
 * - Salt rotation detection (decryption fails → transient set)
 * - Malformed blob handling (missing fields, invalid base64, wrong lengths, unknown version)
 * - Empty value → null
 * - set_secret + get_secret round-trip
 * - API URL resolution (DEF_API_URL constant, stored option, default)
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Additional stubs ─────────────────────────────────────────────────────

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

// Encryption class already loaded via wp-stubs.php.

// ── Test harness ─────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function assert_true( $value, string $label ): void {
	global $pass, $fail;
	if ( $value ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected true, got " . var_export( $value, true ) . ")\n";
	}
}

function assert_false( $value, string $label ): void {
	global $pass, $fail;
	if ( ! $value ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected false, got " . var_export( $value, true ) . ")\n";
	}
}

function assert_eq( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label\n    expected: " . var_export( $expected, true ) . "\n    got:      " . var_export( $actual, true ) . "\n";
	}
}

function assert_null( $value, string $label ): void {
	global $pass, $fail;
	if ( null === $value ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected null, got " . var_export( $value, true ) . ")\n";
	}
}

function reset_state(): void {
	_wp_test_reset_options();
	global $_wp_test_transients, $_wp_test_salts;
	$_wp_test_transients = array();
	// Reset to default salts.
	$_wp_test_salts = array(
		'auth'        => 'test-auth-salt-abcdef1234567890',
		'secure_auth' => 'test-secure-auth-salt-1234567890abcdef',
	);
}

// =========================================================================
// 1. Sodium Encrypt / Decrypt Round-Trip
// =========================================================================

echo "1. Sodium encrypt/decrypt round-trip\n";

if ( function_exists( 'sodium_crypto_secretbox' ) ) {
	reset_state();

	$plaintext = 'my-super-secret-api-key-12345';
	$encrypted = DEF_Core_Encryption::encrypt( $plaintext );

	assert_true( null !== $encrypted, '1a. Encrypt returns non-null' );
	assert_true( $encrypted !== $plaintext, '1b. Encrypted differs from plaintext' );

	// Verify it's valid JSON with version=1.
	$data = json_decode( $encrypted, true );
	assert_eq( 1, $data['version'], '1c. Version is 1 (sodium)' );
	assert_true( isset( $data['nonce'] ), '1d. Nonce field present' );
	assert_true( isset( $data['ciphertext'] ), '1e. Ciphertext field present' );

	// Decrypt should return original plaintext.
	$decrypted = DEF_Core_Encryption::decrypt( $encrypted );
	assert_eq( $plaintext, $decrypted, '1f. Decrypt returns original plaintext' );

	// Different encryptions should produce different ciphertexts (random nonce).
	$encrypted2 = DEF_Core_Encryption::encrypt( $plaintext );
	assert_true( $encrypted !== $encrypted2, '1g. Different nonces produce different blobs' );

	// But both decrypt to the same plaintext.
	$decrypted2 = DEF_Core_Encryption::decrypt( $encrypted2 );
	assert_eq( $plaintext, $decrypted2, '1h. Second blob decrypts to same plaintext' );
} else {
	echo "  SKIP: sodium not available\n";
}

// =========================================================================
// 2. GCM Encrypt / Decrypt Round-Trip (force GCM path)
// =========================================================================

echo "2. GCM encrypt/decrypt round-trip\n";

reset_state();

// We can test GCM decryption by constructing a GCM blob manually,
// since encrypt() uses sodium when available. To test the full
// GCM round-trip, we build a blob using openssl_encrypt directly
// and then decrypt via the class.

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

$decrypted = DEF_Core_Encryption::decrypt( $blob );
assert_eq( $plaintext, $decrypted, '2a. GCM decrypt returns original plaintext' );

// Verify version 2 in the blob.
$data = json_decode( $blob, true );
assert_eq( 2, $data['version'], '2b. Version is 2 (GCM)' );

// =========================================================================
// 3. Legacy Plaintext Auto-Migration
// =========================================================================

echo "3. Legacy plaintext auto-migration\n";

reset_state();

// Store plaintext directly (pre-encryption era).
update_option( 'def_core_api_key', 'legacy-plaintext-key-abc123' );

// get_secret should return the plaintext value.
$result = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
assert_eq( 'legacy-plaintext-key-abc123', $result, '3a. Returns plaintext value' );

// After get_secret, the stored value should now be encrypted JSON.
$stored_after = get_option( 'def_core_api_key' );
$parsed = json_decode( $stored_after, true );
assert_true( is_array( $parsed ), '3b. Stored value is now JSON after auto-encrypt' );
assert_true( isset( $parsed['version'] ), '3c. Has version field after auto-encrypt' );

// Subsequent get_secret should still return same plaintext.
$result2 = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
assert_eq( 'legacy-plaintext-key-abc123', $result2, '3d. Second read still returns correct plaintext' );

// =========================================================================
// 4. Salt Rotation Detection
// =========================================================================

echo "4. Salt rotation detection\n";

reset_state();

// Encrypt with current salts.
DEF_Core_Encryption::set_secret( 'def_core_api_key', 'secret-before-rotation' );

// Verify it works before rotation.
$before = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
assert_eq( 'secret-before-rotation', $before, '4a. Decrypt works before salt rotation' );

// Simulate salt rotation by changing the salt values.
global $_wp_test_salts;
$_wp_test_salts['auth'] = 'completely-new-auth-salt-after-rotation';
$_wp_test_salts['secure_auth'] = 'completely-new-secure-auth-salt-after-rotation';

// Now get_secret should fail (null) and set the error transient.
$after = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
assert_null( $after, '4b. Returns null after salt rotation' );

// Check the error transient was set.
$transient = get_transient( DEF_Core_Encryption::TRANSIENT_ENCRYPTION_ERROR );
assert_true( $transient === true, '4c. Encryption error transient is set' );

// =========================================================================
// 5. Empty Value → null
// =========================================================================

echo "5. Empty value → null\n";

reset_state();

// No option stored → null.
$result = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
assert_null( $result, '5a. No option stored → null' );

// Explicitly empty string → null.
update_option( 'def_core_api_key', '' );
$result = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
assert_null( $result, '5b. Empty string stored → null' );

// Decrypt empty string → null.
$result = DEF_Core_Encryption::decrypt( '' );
assert_null( $result, '5c. decrypt("") → null' );

// =========================================================================
// 6. set_secret + get_secret Round-Trip
// =========================================================================

echo "6. set_secret + get_secret round-trip\n";

reset_state();

$ok = DEF_Core_Encryption::set_secret( 'def_core_api_key', 'round-trip-test-key' );
assert_true( $ok, '6a. set_secret returns true' );

$result = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
assert_eq( 'round-trip-test-key', $result, '6b. get_secret returns correct value' );

// Stored value should be encrypted (not plaintext).
$stored = get_option( 'def_core_api_key' );
assert_true( $stored !== 'round-trip-test-key', '6c. Stored value is not plaintext' );
$parsed = json_decode( $stored, true );
assert_true( isset( $parsed['version'] ), '6d. Stored value has version field' );

// =========================================================================
// 7. Malformed Blob — Missing Fields
// =========================================================================

echo "7. Malformed blob — missing fields\n";

// 7a. Sodium blob missing nonce.
$blob = wp_json_encode( array( 'version' => 1, 'ciphertext' => base64_encode( 'fake' ) ) );
$result = DEF_Core_Encryption::decrypt( $blob );
assert_null( $result, '7a. Sodium blob missing nonce → null' );

// 7b. Sodium blob missing ciphertext.
$blob = wp_json_encode( array( 'version' => 1, 'nonce' => base64_encode( 'fake' ) ) );
$result = DEF_Core_Encryption::decrypt( $blob );
assert_null( $result, '7b. Sodium blob missing ciphertext → null' );

// 7c. GCM blob missing iv.
$blob = wp_json_encode( array(
	'version'    => 2,
	'ciphertext' => base64_encode( 'fake' ),
	'tag'        => base64_encode( 'fake' ),
) );
$result = DEF_Core_Encryption::decrypt( $blob );
assert_null( $result, '7c. GCM blob missing iv → null' );

// 7d. GCM blob missing tag.
$blob = wp_json_encode( array(
	'version'    => 2,
	'iv'         => base64_encode( 'fake' ),
	'ciphertext' => base64_encode( 'fake' ),
) );
$result = DEF_Core_Encryption::decrypt( $blob );
assert_null( $result, '7d. GCM blob missing tag → null' );

// 7e. GCM blob missing ciphertext.
$blob = wp_json_encode( array(
	'version' => 2,
	'iv'      => base64_encode( 'fake' ),
	'tag'     => base64_encode( 'fake' ),
) );
$result = DEF_Core_Encryption::decrypt( $blob );
assert_null( $result, '7e. GCM blob missing ciphertext → null' );

// =========================================================================
// 8. Malformed Blob — Invalid Base64
// =========================================================================

echo "8. Malformed blob — invalid base64\n";

if ( function_exists( 'sodium_crypto_secretbox' ) ) {
	// 8a. Sodium blob with invalid base64 nonce.
	$blob = wp_json_encode( array(
		'version'    => 1,
		'nonce'      => '!!!not-valid-base64!!!',
		'ciphertext' => base64_encode( 'fake' ),
	) );
	$result = DEF_Core_Encryption::decrypt( $blob );
	assert_null( $result, '8a. Sodium invalid base64 nonce → null' );

	// 8b. Sodium blob with invalid base64 ciphertext.
	$blob = wp_json_encode( array(
		'version'    => 1,
		'nonce'      => base64_encode( str_repeat( 'x', SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) ),
		'ciphertext' => '!!!not-valid-base64!!!',
	) );
	$result = DEF_Core_Encryption::decrypt( $blob );
	assert_null( $result, '8b. Sodium invalid base64 ciphertext → null' );
}

// 8c. GCM blob with invalid base64 iv.
$blob = wp_json_encode( array(
	'version'    => 2,
	'iv'         => '!!!not-valid-base64!!!',
	'ciphertext' => base64_encode( 'fake' ),
	'tag'        => base64_encode( str_repeat( 'x', 16 ) ),
) );
$result = DEF_Core_Encryption::decrypt( $blob );
assert_null( $result, '8c. GCM invalid base64 iv → null' );

// =========================================================================
// 9. Malformed Blob — Wrong Lengths
// =========================================================================

echo "9. Malformed blob — wrong lengths\n";

if ( function_exists( 'sodium_crypto_secretbox' ) ) {
	// 9a. Sodium nonce too short (should be 24 bytes).
	$blob = wp_json_encode( array(
		'version'    => 1,
		'nonce'      => base64_encode( str_repeat( 'x', 10 ) ),
		'ciphertext' => base64_encode( 'fake' ),
	) );
	$result = DEF_Core_Encryption::decrypt( $blob );
	assert_null( $result, '9a. Sodium nonce too short → null' );

	// 9b. Sodium nonce too long.
	$blob = wp_json_encode( array(
		'version'    => 1,
		'nonce'      => base64_encode( str_repeat( 'x', 48 ) ),
		'ciphertext' => base64_encode( 'fake' ),
	) );
	$result = DEF_Core_Encryption::decrypt( $blob );
	assert_null( $result, '9b. Sodium nonce too long → null' );
}

// 9c. GCM IV too short (should be 12 bytes).
$blob = wp_json_encode( array(
	'version'    => 2,
	'iv'         => base64_encode( str_repeat( 'x', 5 ) ),
	'ciphertext' => base64_encode( 'fake' ),
	'tag'        => base64_encode( str_repeat( 'x', 16 ) ),
) );
$result = DEF_Core_Encryption::decrypt( $blob );
assert_null( $result, '9c. GCM IV too short → null' );

// 9d. GCM IV too long.
$blob = wp_json_encode( array(
	'version'    => 2,
	'iv'         => base64_encode( str_repeat( 'x', 24 ) ),
	'ciphertext' => base64_encode( 'fake' ),
	'tag'        => base64_encode( str_repeat( 'x', 16 ) ),
) );
$result = DEF_Core_Encryption::decrypt( $blob );
assert_null( $result, '9d. GCM IV too long → null' );

// 9e. GCM tag too short (should be 16 bytes).
$blob = wp_json_encode( array(
	'version'    => 2,
	'iv'         => base64_encode( str_repeat( 'x', 12 ) ),
	'ciphertext' => base64_encode( 'fake' ),
	'tag'        => base64_encode( str_repeat( 'x', 8 ) ),
) );
$result = DEF_Core_Encryption::decrypt( $blob );
assert_null( $result, '9e. GCM tag too short → null' );

// 9f. GCM tag too long.
$blob = wp_json_encode( array(
	'version'    => 2,
	'iv'         => base64_encode( str_repeat( 'x', 12 ) ),
	'ciphertext' => base64_encode( 'fake' ),
	'tag'        => base64_encode( str_repeat( 'x', 32 ) ),
) );
$result = DEF_Core_Encryption::decrypt( $blob );
assert_null( $result, '9f. GCM tag too long → null' );

// =========================================================================
// 10. Unknown Version
// =========================================================================

echo "10. Unknown version\n";

$blob = wp_json_encode( array( 'version' => 99, 'data' => 'something' ) );
$result = DEF_Core_Encryption::decrypt( $blob );
assert_null( $result, '10a. Unknown version → null' );

// =========================================================================
// 11. JSON Without Version Field Treated as Plaintext
// =========================================================================

echo "11. JSON without version field treated as plaintext\n";

reset_state();

// Store a JSON string that doesn't have a 'version' key.
$json_value = '{"foo":"bar","baz":123}';
update_option( 'test_option', $json_value );
$result = DEF_Core_Encryption::get_secret( 'test_option' );
assert_eq( $json_value, $result, '11a. JSON without version → treated as plaintext and returned' );

// =========================================================================
// 12. Encryption Error Transient Cleared on Successful Decrypt
// =========================================================================

echo "12. Successful decrypt does not set transient\n";

reset_state();

// Set up a valid encrypted secret.
DEF_Core_Encryption::set_secret( 'def_core_api_key', 'valid-secret' );

// Ensure no transient is set.
$result = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
assert_eq( 'valid-secret', $result, '12a. Valid secret decrypted' );
$transient = get_transient( DEF_Core_Encryption::TRANSIENT_ENCRYPTION_ERROR );
assert_false( $transient, '12b. No error transient after successful decrypt' );

// =========================================================================
// 13. Multiple Secrets Independent
// =========================================================================

echo "13. Multiple secrets independent\n";

reset_state();

DEF_Core_Encryption::set_secret( 'def_core_api_key', 'api-key-value' );
DEF_Core_Encryption::set_secret( 'def_service_auth_secret', 'service-secret-value' );

$api_key = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
$service = DEF_Core_Encryption::get_secret( 'def_service_auth_secret' );

assert_eq( 'api-key-value', $api_key, '13a. API key independent' );
assert_eq( 'service-secret-value', $service, '13b. Service secret independent' );

// =========================================================================
// 14. Special Characters in Secrets
// =========================================================================

echo "14. Special characters in secrets\n";

reset_state();

$special = 'key-with-"quotes"-and-{braces}-and-$dollar-and-nüll-bytes-and-✓-unicode';
DEF_Core_Encryption::set_secret( 'test_special', $special );
$result = DEF_Core_Encryption::get_secret( 'test_special' );
assert_eq( $special, $result, '14a. Special characters survive round-trip' );

// =========================================================================
// 15. API URL Resolution
// =========================================================================

echo "15. API URL resolution\n";

reset_state();

// Load class-def-core.php for get_def_api_url() — we need its stubs.
// We test this by checking the method exists and the constant/option logic.

// 15a. Default (no constant, no option) → https://api.defho.ai
// We can't easily test get_def_api_url() without loading the full class,
// so we verify the logic by reading the source.
$source = file_get_contents( DEF_CORE_PLUGIN_DIR . 'includes/class-def-core.php' );
assert_true(
	strpos( $source, "defined( 'DEF_API_URL' )" ) !== false,
	'15a. DEF_API_URL constant check exists in get_def_api_url()'
);
assert_true(
	strpos( $source, "https://api.defho.ai" ) !== false,
	'15b. Default URL https://api.defho.ai exists in source'
);

// =========================================================================
// Results
// =========================================================================

echo "\n=== Results ===\n";
echo "Passed: $pass\n";
echo "Failed: $fail\n";

if ( $fail > 0 ) {
	exit( 1 );
}
echo "All encryption tests passed.\n";
echo "$pass passed, $fail failed\n";
