<?php
/**
 * D5: JWT Integration Tests
 *
 * Tests JWT functionality in a real WordPress environment:
 * key generation, token roundtrip, JWKS endpoint structure,
 * and context token issuance for authenticated users.
 *
 * @package def-core
 * @group jwt
 */

declare(strict_types=1);

class Test_JWT_Integration extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Clear any existing keys so each test starts fresh.
		delete_option( DEF_CORE_OPTION_KEYS );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test: Key generation produces valid RSA keypair in real WP environment.
	 */
	public function test_key_generation_produces_valid_keypair(): void {
		DEF_Core_JWT::ensure_keys_exist();

		$keys = get_option( DEF_CORE_OPTION_KEYS );
		$this->assertIsArray( $keys, 'Keys option should be an array' );
		$this->assertArrayHasKey( 'private', $keys, 'Keys should have private key' );
		$this->assertArrayHasKey( 'public', $keys, 'Keys should have public key' );
		$this->assertArrayHasKey( 'kid', $keys, 'Keys should have kid' );
		$this->assertArrayHasKey( 'created', $keys, 'Keys should have creation timestamp' );

		// Verify PEM format.
		$this->assertStringContainsString( '-----BEGIN', $keys['private'], 'Private key should be PEM format' );
		$this->assertStringContainsString( '-----BEGIN PUBLIC KEY-----', $keys['public'], 'Public key should be PEM format' );

		// Verify kid is a hex string.
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $keys['kid'], 'kid should be 16 hex chars' );

		// Verify the private key is actually usable.
		$private = openssl_pkey_get_private( $keys['private'] );
		$this->assertNotFalse( $private, 'Private key should be loadable by OpenSSL' );

		// Verify the public key is actually usable.
		$public = openssl_pkey_get_public( $keys['public'] );
		$this->assertNotFalse( $public, 'Public key should be loadable by OpenSSL' );
	}

	/**
	 * Test: issue_token / verify_token roundtrip succeeds.
	 */
	public function test_issue_verify_roundtrip(): void {
		DEF_Core_JWT::ensure_keys_exist();

		$claims = array(
			'sub'   => '42',
			'email' => 'test@example.com',
			'roles' => array( 'subscriber' ),
			'iss'   => get_site_url(),
			'aud'   => DEF_CORE_AUDIENCE,
		);

		$token = DEF_Core_JWT::issue_token( $claims, 300 );
		$this->assertNotEmpty( $token, 'Token should not be empty' );

		// Token should have 3 parts (header.payload.signature).
		$parts = explode( '.', $token );
		$this->assertCount( 3, $parts, 'JWT should have 3 parts' );

		// Verify the token.
		$payload = DEF_Core_JWT::verify_token( $token );
		$this->assertIsArray( $payload, 'Verified payload should be an array' );
		$this->assertEquals( '42', $payload['sub'], 'Subject claim should match' );
		$this->assertEquals( 'test@example.com', $payload['email'], 'Email claim should match' );
		$this->assertEquals( DEF_CORE_AUDIENCE, $payload['aud'], 'Audience claim should match' );

		// Standard claims should be present.
		$this->assertArrayHasKey( 'iat', $payload, 'iat claim should exist' );
		$this->assertArrayHasKey( 'exp', $payload, 'exp claim should exist' );
		$this->assertArrayHasKey( 'nbf', $payload, 'nbf claim should exist' );
		$this->assertArrayHasKey( 'jti', $payload, 'jti claim should exist' );
	}

	/**
	 * Test: JWKS endpoint returns structurally valid response.
	 */
	public function test_jwks_endpoint_structurally_valid(): void {
		DEF_Core_JWT::ensure_keys_exist();

		$server   = rest_get_server();
		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/jwks' );
		$response = $server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'keys', $data );
		$this->assertNotEmpty( $data['keys'] );

		$key = $data['keys'][0];

		// Match the kid from stored keys.
		$stored_keys = get_option( DEF_CORE_OPTION_KEYS );
		$this->assertEquals( $stored_keys['kid'], $key['kid'], 'JWKS kid should match stored kid' );

		// Verify modulus and exponent are base64url-encoded (no padding, no +/).
		$this->assertDoesNotMatchRegularExpression( '/[+\/=]/', $key['n'], 'Modulus should be base64url (no +/=)' );
		$this->assertDoesNotMatchRegularExpression( '/[+\/=]/', $key['e'], 'Exponent should be base64url (no +/=)' );
	}

	/**
	 * Test: Context token issuance for authenticated user returns valid JWT.
	 */
	public function test_context_token_for_authenticated_user(): void {
		DEF_Core_JWT::ensure_keys_exist();

		$user_id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'testuser',
				'user_email' => 'testuser@example.com',
			)
		);
		wp_set_current_user( $user_id );

		$server   = rest_get_server();
		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token' );
		$response = $server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'token', $data, 'Response should contain token' );
		$this->assertArrayHasKey( 'exp', $data, 'Response should contain exp' );

		// Verify the issued token is actually valid.
		$payload = DEF_Core_JWT::verify_token( $data['token'] );
		$this->assertIsArray( $payload, 'Context token should verify successfully' );
		$this->assertEquals( (string) $user_id, $payload['sub'], 'Token sub should match user ID' );
		$this->assertEquals( 'testuser@example.com', $payload['email'], 'Token email should match' );
		$this->assertEquals( DEF_CORE_AUDIENCE, $payload['aud'], 'Token aud should match plugin audience' );
	}
}
