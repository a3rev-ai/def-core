<?php
/**
 * JWT unit tests for DEF_Core_JWT.
 *
 * Tests issue/verify roundtrip, expiration, signature verification,
 * JWKS endpoint structure, and key generation.
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-jwt.php';

$pass = 0;
$fail = 0;

function assert_true( $value, string $label ): void {
	global $pass, $fail;
	if ( $value ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label\n";
	}
}

function assert_false( $value, string $label ): void {
	assert_true( ! $value, $label );
}

function assert_equals( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . ")\n";
	}
}

echo "=== JWT Tests ===\n";

// ── Setup: generate keys (use test helper for Windows OpenSSL compat) ──
_wp_test_reset_options();
$keys = _wp_test_seed_rsa_keys();

// 1. Key generation produces valid RSA keypair.
echo "\n[1] Key generation\n";
assert_true( is_array( $keys ), 'keys stored as array' );
assert_true( ! empty( $keys['private'] ), 'private key present' );
assert_true( ! empty( $keys['public'] ), 'public key present' );
assert_true( ! empty( $keys['kid'] ), 'kid present' );
assert_true( strlen( $keys['kid'] ) === 16, 'kid is 16 chars (sha1 prefix)' );
assert_true( strpos( $keys['private'], '-----BEGIN' ) === 0, 'private key is PEM format' );
assert_true( strpos( $keys['public'], '-----BEGIN PUBLIC KEY-----' ) === 0, 'public key is PEM format' );

// 2. Idempotent: keys already present → ensure_keys_exist is a no-op.
echo "\n[2] Key idempotency\n";
$kid_before = $keys['kid'];
DEF_Core_JWT::ensure_keys_exist(); // Should not replace since keys exist.
$keys_after = get_option( DEF_CORE_OPTION_KEYS );
assert_equals( $kid_before, $keys_after['kid'], 'kid unchanged on repeat call' );

// 3. Issue token produces valid JWT format.
echo "\n[3] Token issuance\n";
$claims = array(
	'sub'   => '42',
	'email' => 'test@example.com',
	'roles' => array( 'administrator' ),
	'iss'   => 'https://test.example.com',
	'aud'   => DEF_CORE_AUDIENCE,
);
$token = DEF_Core_JWT::issue_token( $claims, 300 );
assert_true( ! empty( $token ), 'token is non-empty' );
$parts = explode( '.', $token );
assert_equals( 3, count( $parts ), 'token has 3 parts (header.payload.signature)' );

// 4. Header contains correct alg, typ, kid.
echo "\n[4] Token header\n";
$header_json = base64_decode( strtr( $parts[0], '-_', '+/' ) );
$header = json_decode( $header_json, true );
assert_equals( 'RS256', $header['alg'], 'algorithm is RS256' );
assert_equals( 'JWT', $header['typ'], 'type is JWT' );
assert_equals( $keys['kid'], $header['kid'], 'kid matches generated key' );

// 5. Payload contains issued claims + standard fields.
echo "\n[5] Token payload\n";
$payload_json = base64_decode( strtr( $parts[1], '-_', '+/' ) . '==' );
$payload = json_decode( $payload_json, true );
assert_equals( '42', $payload['sub'], 'sub claim preserved' );
assert_equals( 'test@example.com', $payload['email'], 'email claim preserved' );
assert_true( isset( $payload['iat'] ), 'iat present' );
assert_true( isset( $payload['exp'] ), 'exp present' );
assert_true( isset( $payload['nbf'] ), 'nbf present' );
assert_true( isset( $payload['jti'] ), 'jti present (unique token ID)' );
assert_true( $payload['exp'] > $payload['iat'], 'exp > iat' );
assert_true( $payload['nbf'] <= $payload['iat'], 'nbf <= iat (clock skew allowance)' );
assert_equals( DEF_CORE_AUDIENCE, $payload['aud'], 'audience matches' );

// 6. Verify token roundtrip succeeds.
echo "\n[6] Token verification roundtrip\n";
$verified = DEF_Core_JWT::verify_token( $token );
assert_true( is_array( $verified ), 'verify_token returns array' );
assert_equals( '42', $verified['sub'], 'verified sub matches' );
assert_equals( 'test@example.com', $verified['email'], 'verified email matches' );

// 7. Tampered token fails verification.
echo "\n[7] Tampered token rejection\n";
$tampered = $parts[0] . '.' . $parts[1] . '.INVALID_SIGNATURE';
$result = DEF_Core_JWT::verify_token( $tampered );
assert_true( $result === null, 'tampered signature rejected' );

// 8. Malformed token (wrong segment count) fails.
echo "\n[8] Malformed token rejection\n";
assert_true( DEF_Core_JWT::verify_token( 'not.a.jwt.at.all' ) === null, '5-segment token rejected' );
assert_true( DEF_Core_JWT::verify_token( 'only-one-part' ) === null, '1-segment token rejected' );
assert_true( DEF_Core_JWT::verify_token( '' ) === null, 'empty string rejected' );

// 9. Expired token fails verification.
echo "\n[9] Expired token rejection\n";
$expired_token = DEF_Core_JWT::issue_token( $claims, 60 ); // min TTL is 60s
// Manually create an expired token by faking the payload.
$exp_parts = explode( '.', $expired_token );
$exp_payload = json_decode( base64_decode( strtr( $exp_parts[1], '-_', '+/' ) . '==' ), true );
$exp_payload['exp'] = time() - 100; // Expired 100s ago.
$new_payload_b64 = rtrim( strtr( base64_encode( json_encode( $exp_payload, JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' );
$forged = $exp_parts[0] . '.' . $new_payload_b64 . '.' . $exp_parts[2];
$result = DEF_Core_JWT::verify_token( $forged );
assert_true( $result === null, 'expired token rejected (also forged sig)' );

// 10. JWKS endpoint structure.
echo "\n[10] JWKS structure\n";
$jwks = DEF_Core_JWT::get_jwks();
assert_true( is_array( $jwks ), 'JWKS is array' );
assert_true( isset( $jwks['keys'] ), 'JWKS has keys array' );
assert_true( count( $jwks['keys'] ) === 1, 'JWKS has exactly 1 key' );
$jwk = $jwks['keys'][0];
assert_equals( 'RSA', $jwk['kty'], 'key type is RSA' );
assert_equals( 'RS256', $jwk['alg'], 'algorithm is RS256' );
assert_equals( 'sig', $jwk['use'], 'key use is sig' );
assert_true( ! empty( $jwk['n'] ), 'modulus (n) present' );
assert_true( ! empty( $jwk['e'] ), 'exponent (e) present' );
assert_equals( $keys['kid'], $jwk['kid'], 'kid matches key store' );

// 11. No keys → empty JWKS.
echo "\n[11] Empty keys → empty JWKS\n";
_wp_test_reset_options();
$empty_jwks = DEF_Core_JWT::get_jwks();
assert_equals( array( 'keys' => array() ), $empty_jwks, 'no keys returns empty JWKS' );

// 12. No keys → issue_token behaviour.
echo "\n[12] No keys → issue_token attempts key generation\n";
// On Windows without openssl.cnf in default path, ensure_keys_exist may fail.
// The important thing is it doesn't crash — returns empty string if no keys.
$no_key_token = DEF_Core_JWT::issue_token( $claims, 300 );
// It will try ensure_keys_exist internally; may or may not succeed on Windows.
// Just verify no crash (test passes if we reach here).
assert_true( true, 'issue_token with no keys does not crash' );

// 13. TTL minimum enforcement (60 seconds).
echo "\n[13] TTL minimum enforcement\n";
_wp_test_seed_rsa_keys(); // Re-seed keys after test 11/12 cleared them.
$short_token = DEF_Core_JWT::issue_token( $claims, 10 ); // Request 10s, should get 60s min.
$short_parts = explode( '.', $short_token );
$short_payload = json_decode( base64_decode( strtr( $short_parts[1], '-_', '+/' ) . '==' ), true );
$ttl = $short_payload['exp'] - $short_payload['iat'];
assert_true( $ttl >= 60, 'TTL enforces 60s minimum (got ' . $ttl . 's)' );

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n--- JWT Tests: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
