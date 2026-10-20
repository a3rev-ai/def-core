<?php
/**
 * W74: the stream token a widget streams straight from DEF on.
 *
 * POST /chat/stream-token {channel, thread}: Customer Chat's follows the chat proxy's
 * nonce rule and carries an anonymous `anon:` sub or the user id + display name; Staff
 * AI's takes the staff stream's permission and carries what build_proxy_headers(true)
 * sends. Ten minutes, no-store, never the API key.
 *
 * @package def-core
 * @group permissions
 */

declare(strict_types=1);

class Test_Stream_Token extends WP_UnitTestCase {

	private const API_KEY = 'def_key_must_never_reach_a_browser';

	private $server;

	public function set_up(): void {
		parent::set_up();
		$this->server = rest_get_server();
		DEF_Core_Encryption::set_secret( 'def_core_api_key', self::API_KEY );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.44';
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	private function mint( string $channel, string $thread = '', ?string $nonce = null ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/a3-ai/v1/chat/stream-token' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'channel' => $channel, 'thread' => $thread ) ) );
		if ( null !== $nonce ) {
			$request->set_header( 'X-WP-Nonce', $nonce );
		}
		return $this->server->dispatch( $request );
	}

	private function claims( WP_REST_Response $response ): array {
		$token = $response->get_data()['token'];
		$this->assertIsArray( DEF_Core_JWT::verify_token( $token ), 'the token verifies on the plugin\'s own key' );
		$part = strtr( explode( '.', $token )[1], '-_', '+/' );
		return json_decode( base64_decode( $part . str_repeat( '=', ( 4 - strlen( $part ) % 4 ) % 4 ) ), true );
	}

	/** What the proxy's headers carry, as claim names (X-DEF-User → sub, …). */
	private function proxy_fields( bool $staff ): array {
		$method = new ReflectionMethod( 'DEF_Core_Tools', 'build_proxy_headers' );
		$method->setAccessible( true );
		$names  = array(
			'X-DEF-User'              => 'sub',
			'X-DEF-User-Display-Name' => 'display_name',
			'X-DEF-Client-IP'         => 'ip',
			'X-DEF-User-Capabilities' => 'capabilities',
			'X-DEF-User-Email'        => 'email',
			'X-DEF-User-Roles'        => 'roles',
			'X-DEF-Site-Name'         => 'site_name',
		);
		$fields = array();
		foreach ( $method->invoke( null, $staff ) as $header ) {
			list( $name, $value ) = explode( ': ', $header, 2 );
			if ( isset( $names[ $name ] ) ) {
				$value                    = rawurldecode( $value );
				$fields[ $names[ $name ] ] = in_array( $names[ $name ], array( 'capabilities', 'roles' ), true ) ? explode( ',', $value ) : $value;
			}
		}
		ksort( $fields );
		return $fields;
	}

	private function identity( array $claims ): array {
		$identity = array_diff_key( $claims, array_flip( array( 'iss', 'aud', 'scope', 'channel', 'thread', 'iat', 'exp', 'nbf', 'jti' ) ) );
		ksort( $identity );
		return $identity;
	}

	public function test_anonymous_customer_mint_carries_an_anon_sub_and_no_identity(): void {
		$response = $this->mint( 'customer_chat', 'thr-1' );
		$this->assertSame( 200, $response->get_status() );
		$claims = $this->claims( $response );

		$this->assertMatchesRegularExpression( '/^anon:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $claims['sub'] );
		$this->assertSame( array( 'ip' => '203.0.113.44', 'sub' => $claims['sub'] ), $this->identity( $claims ), 'only the sub and the visitor IP' );
		$this->assertSame( 'stream', $claims['scope'] );
		$this->assertSame( 'customer_chat', $claims['channel'] );
		$this->assertSame( 'thr-1', $claims['thread'] );
		$this->assertSame( get_site_url(), $claims['iss'] );
		$this->assertSame( DEF_CORE_AUDIENCE, $claims['aud'] );
		$this->assertNotSame( $claims['sub'], $this->claims( $this->mint( 'customer_chat' ) )['sub'], 'a fresh anon sub per mint' );
	}

	public function test_the_token_lives_ten_minutes_and_is_never_cached(): void {
		$response = $this->mint( 'customer_chat' );
		$claims   = $this->claims( $response );
		$this->assertSame( 600, $claims['exp'] - $claims['iat'] );
		$this->assertSame( '', $claims['thread'], 'a new conversation is the empty thread' );
		$this->assertEqualsWithDelta( time() + 600, $response->get_data()['exp'], 2 );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	public function test_the_api_key_is_never_in_the_response(): void {
		$staff_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $staff_id )->add_cap( 'def_staff_access' );
		wp_set_current_user( $staff_id );
		foreach ( array( 'customer_chat', 'staff_ai' ) as $channel ) {
			$response = $this->mint( $channel, '', wp_create_nonce( 'wp_rest' ) );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( array( 'token', 'exp', 'stream_url' ), array_keys( $response->get_data() ) );
			$this->assertStringNotContainsString( self::API_KEY, wp_json_encode( $response->get_data() ) . wp_json_encode( $this->claims( $response ) ) );
		}
	}

	public function test_stream_url_is_the_platform_stream_for_the_channel(): void {
		$this->assertSame( DEF_Core::get_def_api_url() . '/api/chat/stream', $this->mint( 'customer_chat' )->get_data()['stream_url'] );
		$staff_id = self::factory()->user->create();
		get_user_by( 'id', $staff_id )->add_cap( 'def_staff_access' );
		wp_set_current_user( $staff_id );
		$this->assertSame( DEF_Core::get_def_api_url() . '/api/staff-ai/chat/stream', $this->mint( 'staff_ai' )->get_data()['stream_url'] );
	}

	public function test_logged_in_customer_with_a_bad_nonce_is_refused(): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, $this->mint( 'customer_chat' )->get_status(), 'no nonce' );
		$this->assertSame( 403, $this->mint( 'customer_chat', '', 'bad-nonce' )->get_status(), 'a bad nonce' );
	}

	public function test_logged_in_customer_claims_are_what_the_customer_proxy_sends(): void {
		wp_set_current_user( self::factory()->user->create( array( 'display_name' => 'Zoë O\'Brien', 'role' => 'subscriber' ) ) );
		$claims = $this->claims( $this->mint( 'customer_chat', 'thr-1', wp_create_nonce( 'wp_rest' ) ) );
		$this->assertSame( $this->proxy_fields( false ), $this->identity( $claims ) );
		$this->assertSame( array( 'display_name', 'ip', 'sub' ), array_keys( $this->identity( $claims ) ), 'no email, roles or capabilities' );
	}

	public function test_staff_mint_refuses_anonymous_and_uncapped(): void {
		$this->assertSame( 401, $this->mint( 'staff_ai' )->get_status(), 'anonymous' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->mint( 'staff_ai' )->get_status(), 'logged in without staff access' );
	}

	public function test_staff_claims_are_what_the_staff_proxy_sends(): void {
		$staff_id = self::factory()->user->create( array( 'role' => 'editor', 'display_name' => 'Sue Staff', 'user_email' => 'sue@example.com' ) );
		get_user_by( 'id', $staff_id )->add_cap( 'def_staff_access' );
		wp_set_current_user( $staff_id );
		update_option( 'blogname', 'Widrow & Co' );

		$claims = $this->claims( $this->mint( 'staff_ai', 'thr-9', wp_create_nonce( 'wp_rest' ) ) );
		$this->assertSame( $this->proxy_fields( true ), $this->identity( $claims ) );
		$this->assertSame( (string) $staff_id, $claims['sub'] );
		$this->assertSame( 'staff_ai', $claims['channel'] );
		$this->assertSame( 'thr-9', $claims['thread'] );
	}

	public function test_an_unknown_channel_is_refused(): void {
		$this->assertSame( 400, $this->mint( 'setup_assistant' )->get_status() );
	}
}
