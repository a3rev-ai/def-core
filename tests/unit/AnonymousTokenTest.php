<?php
/**
 * PHPUnit tests for the anonymous context token endpoint.
 *
 * Customer Chat Bug #1 — Step 11.
 *
 * @package def-core/tests/unit
 */

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers DEF_Core_Tools::rest_issue_anonymous_context_token
 */
final class AnonymousTokenTest extends TestCase {

	/**
	 * RSA keys seeded for each test.
	 *
	 * @var array
	 */
	private array $keys;

	protected function setUp(): void {
		parent::setUp();
		_wp_test_reset_options();
		global $_wp_test_transients;
		$_wp_test_transients = array();
		$this->keys = _wp_test_seed_rsa_keys();

		// Reset $_SERVER for IP detection.
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'] );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	// ── Happy path ─────────────────────────────────────────────────────

	public function test_anonymous_token_returns_200_with_jwt(): void {
		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token-anonymous' );
		$response = DEF_Core_Tools::rest_issue_anonymous_context_token( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'token', $data );
		$this->assertArrayHasKey( 'exp', $data );
		$this->assertArrayHasKey( 'sid', $data );
		$this->assertNotEmpty( $data['token'] );
	}

	public function test_anonymous_token_contains_correct_claims(): void {
		$request = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token-anonymous' );
		$request->set_param( 'sid', 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d' );
		$response = DEF_Core_Tools::rest_issue_anonymous_context_token( $request );
		$data     = $response->get_data();

		// Decode the JWT payload.
		$parts   = explode( '.', $data['token'] );
		$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );

		$this->assertSame( 'anon:a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d', $payload['sub'] );
		$this->assertSame( 'customer_chat', $payload['channel'] );
		$this->assertSame( get_site_url(), $payload['iss'] );
		$this->assertSame( DEF_CORE_AUDIENCE, $payload['aud'] );
	}

	public function test_anonymous_token_no_user_claims(): void {
		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token-anonymous' );
		$response = DEF_Core_Tools::rest_issue_anonymous_context_token( $request );
		$data     = $response->get_data();

		$parts   = explode( '.', $data['token'] );
		$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );

		$this->assertArrayNotHasKey( 'username', $payload );
		$this->assertArrayNotHasKey( 'email', $payload );
		$this->assertArrayNotHasKey( 'roles', $payload );
		$this->assertArrayNotHasKey( 'capabilities', $payload );
		$this->assertArrayNotHasKey( 'display_name', $payload );
		$this->assertArrayNotHasKey( 'first_name', $payload );
	}

	public function test_response_has_no_store_header(): void {
		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token-anonymous' );
		$response = DEF_Core_Tools::rest_issue_anonymous_context_token( $request );
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
	}

	// ── SID validation ─────────────────────────────────────────────────

	public function test_sid_valid_uuid_accepted(): void {
		$valid_sid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
		$request   = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token-anonymous' );
		$request->set_param( 'sid', $valid_sid );
		$response = DEF_Core_Tools::rest_issue_anonymous_context_token( $request );
		$data     = $response->get_data();

		$this->assertSame( $valid_sid, $data['sid'] );
	}

	public function test_sid_invalid_format_regenerated(): void {
		$request = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token-anonymous' );
		$request->set_param( 'sid', 'not-a-valid-uuid' );
		$response = DEF_Core_Tools::rest_issue_anonymous_context_token( $request );
		$data     = $response->get_data();

		// Server should generate a fresh UUID.
		$this->assertNotSame( 'not-a-valid-uuid', $data['sid'] );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$data['sid']
		);
	}

	public function test_sid_empty_generates_new(): void {
		$request = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token-anonymous' );
		$request->set_param( 'sid', '' );
		$response = DEF_Core_Tools::rest_issue_anonymous_context_token( $request );
		$data     = $response->get_data();

		$this->assertNotEmpty( $data['sid'] );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$data['sid']
		);
	}

	public function test_sid_overlength_rejected(): void {
		$long_sid = str_repeat( 'a', 101 );
		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token-anonymous' );
		$request->set_param( 'sid', $long_sid );
		$response = DEF_Core_Tools::rest_issue_anonymous_context_token( $request );
		$data     = $response->get_data();

		// Server should reject and regenerate.
		$this->assertNotSame( $long_sid, $data['sid'] );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$data['sid']
		);
	}

	public function test_sid_case_normalized(): void {
		$upper_sid = 'A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C5D';
		$request   = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token-anonymous' );
		$request->set_param( 'sid', $upper_sid );
		$response = DEF_Core_Tools::rest_issue_anonymous_context_token( $request );
		$data     = $response->get_data();

		$this->assertSame( strtolower( $upper_sid ), $data['sid'] );
	}

	// ── Rate limiting ──────────────────────────────────────────────────

	public function test_rate_limit_429_on_exceed(): void {
		$request = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token-anonymous' );

		// Make 30 successful requests.
		for ( $i = 0; $i < 30; $i++ ) {
			$response = DEF_Core_Tools::rest_issue_anonymous_context_token( $request );
			$this->assertSame( 200, $response->get_status(), "Request $i should succeed" );
		}

		// 31st request should be rate-limited.
		$response = DEF_Core_Tools::rest_issue_anonymous_context_token( $request );
		$this->assertSame( 429, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'rate_limited', $data['error'] );
	}

	// ── Expiry ─────────────────────────────────────────────────────────

	public function test_token_exp_is_5_minutes_from_now(): void {
		$before   = time();
		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token-anonymous' );
		$response = DEF_Core_Tools::rest_issue_anonymous_context_token( $request );
		$after    = time();
		$data     = $response->get_data();

		// exp should be ~300 seconds from now.
		$this->assertGreaterThanOrEqual( $before + 300, $data['exp'] );
		$this->assertLessThanOrEqual( $after + 300, $data['exp'] );
	}
}
