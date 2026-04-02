<?php
/**
 * PHPUnit tests for DEF_Core_JWT.
 *
 * Converted from tests/test-jwt.php — all original test cases preserved.
 *
 * @package def-core/tests/unit
 */

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers DEF_Core_JWT
 */
final class JwtTest extends TestCase {

	/**
	 * RSA keys seeded for each test.
	 *
	 * @var array
	 */
	private array $keys;

	protected function setUp(): void {
		parent::setUp();
		_wp_test_reset_options();
		$this->keys = _wp_test_seed_rsa_keys();
	}

	// ── 1. Key generation ───────────────────────────────────────────────

	public function test_key_generation_produces_valid_array(): void {
		$this->assertIsArray( $this->keys );
		$this->assertNotEmpty( $this->keys['private'] );
		$this->assertNotEmpty( $this->keys['public'] );
		$this->assertNotEmpty( $this->keys['kid'] );
	}

	public function test_kid_is_16_char_sha1_prefix(): void {
		$this->assertSame( 16, strlen( $this->keys['kid'] ) );
	}

	public function test_private_key_is_pem(): void {
		$this->assertStringStartsWith( '-----BEGIN', $this->keys['private'] );
	}

	public function test_public_key_is_pem(): void {
		$this->assertStringStartsWith( '-----BEGIN PUBLIC KEY-----', $this->keys['public'] );
	}

	// ── 2. Key idempotency ──────────────────────────────────────────────

	public function test_ensure_keys_exist_is_idempotent(): void {
		$kid_before = $this->keys['kid'];
		DEF_Core_JWT::ensure_keys_exist();
		$keys_after = get_option( DEF_CORE_OPTION_KEYS );
		$this->assertSame( $kid_before, $keys_after['kid'] );
	}

	// ── 3. Token issuance ───────────────────────────────────────────────

	private function sample_claims(): array {
		return array(
			'sub'   => '42',
			'email' => 'test@example.com',
			'roles' => array( 'administrator' ),
			'iss'   => 'https://test.example.com',
			'aud'   => DEF_CORE_AUDIENCE,
		);
	}

	public function test_issue_token_returns_nonempty_string(): void {
		$token = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		$this->assertNotEmpty( $token );
	}

	public function test_token_has_three_parts(): void {
		$token = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		$parts = explode( '.', $token );
		$this->assertCount( 3, $parts );
	}

	// ── 4. Token header ─────────────────────────────────────────────────

	public function test_header_algorithm_is_rs256(): void {
		$token  = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		$header = $this->decode_part( $token, 0 );
		$this->assertSame( 'RS256', $header['alg'] );
	}

	public function test_header_type_is_jwt(): void {
		$token  = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		$header = $this->decode_part( $token, 0 );
		$this->assertSame( 'JWT', $header['typ'] );
	}

	public function test_header_kid_matches_generated_key(): void {
		$token  = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		$header = $this->decode_part( $token, 0 );
		$this->assertSame( $this->keys['kid'], $header['kid'] );
	}

	// ── 5. Token payload ────────────────────────────────────────────────

	public function test_payload_preserves_sub_claim(): void {
		$token   = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		$payload = $this->decode_part( $token, 1 );
		$this->assertSame( '42', $payload['sub'] );
	}

	public function test_payload_preserves_email_claim(): void {
		$token   = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		$payload = $this->decode_part( $token, 1 );
		$this->assertSame( 'test@example.com', $payload['email'] );
	}

	public function test_payload_has_standard_time_fields(): void {
		$token   = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		$payload = $this->decode_part( $token, 1 );
		$this->assertArrayHasKey( 'iat', $payload );
		$this->assertArrayHasKey( 'exp', $payload );
		$this->assertArrayHasKey( 'nbf', $payload );
		$this->assertArrayHasKey( 'jti', $payload );
	}

	public function test_payload_exp_greater_than_iat(): void {
		$token   = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		$payload = $this->decode_part( $token, 1 );
		$this->assertGreaterThan( $payload['iat'], $payload['exp'] );
	}

	public function test_payload_nbf_lte_iat(): void {
		$token   = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		$payload = $this->decode_part( $token, 1 );
		$this->assertLessThanOrEqual( $payload['iat'], $payload['nbf'] );
	}

	public function test_payload_audience_matches(): void {
		$token   = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		$payload = $this->decode_part( $token, 1 );
		$this->assertSame( DEF_CORE_AUDIENCE, $payload['aud'] );
	}

	// ── 6. Verify roundtrip ─────────────────────────────────────────────

	public function test_verify_token_roundtrip(): void {
		$token    = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		$verified = DEF_Core_JWT::verify_token( $token );
		$this->assertIsArray( $verified );
		$this->assertSame( '42', $verified['sub'] );
		$this->assertSame( 'test@example.com', $verified['email'] );
	}

	// ── 7. Tampered token ───────────────────────────────────────────────

	public function test_tampered_signature_rejected(): void {
		$token   = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		$parts   = explode( '.', $token );
		$tampered = $parts[0] . '.' . $parts[1] . '.INVALID_SIGNATURE';
		$this->assertNull( DEF_Core_JWT::verify_token( $tampered ) );
	}

	// ── 8. Malformed tokens ─────────────────────────────────────────────

	public function test_five_segment_token_rejected(): void {
		$this->assertNull( DEF_Core_JWT::verify_token( 'not.a.jwt.at.all' ) );
	}

	public function test_one_segment_token_rejected(): void {
		$this->assertNull( DEF_Core_JWT::verify_token( 'only-one-part' ) );
	}

	public function test_empty_string_rejected(): void {
		$this->assertNull( DEF_Core_JWT::verify_token( '' ) );
	}

	// ── 9. Expired token ────────────────────────────────────────────────

	public function test_expired_token_rejected(): void {
		$token     = DEF_Core_JWT::issue_token( $this->sample_claims(), 60 );
		$parts     = explode( '.', $token );
		$payload   = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) . '==' ), true );
		$payload['exp'] = time() - 100;
		$new_b64   = rtrim( strtr( base64_encode( json_encode( $payload, JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' );
		$forged    = $parts[0] . '.' . $new_b64 . '.' . $parts[2];
		$this->assertNull( DEF_Core_JWT::verify_token( $forged ) );
	}

	// ── 10. JWKS structure ──────────────────────────────────────────────

	public function test_jwks_structure(): void {
		$jwks = DEF_Core_JWT::get_jwks();
		$this->assertIsArray( $jwks );
		$this->assertArrayHasKey( 'keys', $jwks );
		$this->assertCount( 1, $jwks['keys'] );

		$jwk = $jwks['keys'][0];
		$this->assertSame( 'RSA', $jwk['kty'] );
		$this->assertSame( 'RS256', $jwk['alg'] );
		$this->assertSame( 'sig', $jwk['use'] );
		$this->assertNotEmpty( $jwk['n'] );
		$this->assertNotEmpty( $jwk['e'] );
		$this->assertSame( $this->keys['kid'], $jwk['kid'] );
	}

	// ── 11. Empty keys → empty JWKS ─────────────────────────────────────

	public function test_no_keys_returns_empty_jwks(): void {
		_wp_test_reset_options();
		$empty_jwks = DEF_Core_JWT::get_jwks();
		$this->assertSame( array( 'keys' => array() ), $empty_jwks );
	}

	// ── 12. No keys → issue_token does not crash ────────────────────────

	public function test_issue_token_without_keys_does_not_crash(): void {
		_wp_test_reset_options();
		$token = DEF_Core_JWT::issue_token( $this->sample_claims(), 300 );
		// May return empty string on Windows if OpenSSL can't generate keys.
		// The test verifies it doesn't throw/crash.
		$this->assertTrue( true );
	}

	// ── 13. TTL minimum enforcement ─────────────────────────────────────

	public function test_ttl_minimum_enforcement_60s(): void {
		$token   = DEF_Core_JWT::issue_token( $this->sample_claims(), 10 );
		$payload = $this->decode_part( $token, 1 );
		$ttl     = $payload['exp'] - $payload['iat'];
		$this->assertGreaterThanOrEqual( 60, $ttl );
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Decode a JWT part (0=header, 1=payload).
	 */
	private function decode_part( string $token, int $index ): array {
		$parts = explode( '.', $token );
		$json  = base64_decode( strtr( $parts[ $index ], '-_', '+/' ) . '==' );
		return json_decode( $json, true );
	}
}
