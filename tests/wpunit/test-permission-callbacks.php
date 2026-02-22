<?php
/**
 * D2: Permission Callback Tests
 *
 * Tests auth gates using WP factory users (admin, subscriber, custom-cap user).
 * Verifies that public endpoints are accessible, protected endpoints reject
 * anonymous users, and capability gates work correctly.
 *
 * @package def-core
 * @group permissions
 */

declare(strict_types=1);

class Test_Permission_Callbacks extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Staff-capability user ID.
	 *
	 * @var int
	 */
	private $staff_user_id;

	public function set_up(): void {
		parent::set_up();

		$this->server = rest_get_server();

		// Create test users.
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Create a user with staff AI capability.
		$this->staff_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$staff_user          = get_user_by( 'id', $this->staff_user_id );
		$staff_user->add_cap( 'def_staff_access' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test: JWKS is accessible anonymously (public endpoint).
	 */
	public function test_jwks_accessible_anonymously(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/jwks' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status(), 'JWKS should be publicly accessible' );
	}

	/**
	 * Test: context-token requires login (anon gets 401).
	 */
	public function test_context_token_rejects_anonymous(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status(), 'context-token should reject anonymous users' );
	}

	/**
	 * Test: context-token succeeds for logged-in subscriber.
	 */
	public function test_context_token_succeeds_for_subscriber(): void {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status(), 'context-token should succeed for logged-in subscriber' );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'token', $data, 'Response should contain a token' );
		$this->assertNotEmpty( $data['token'], 'Token should not be empty' );
	}

	/**
	 * Test: Staff AI conversations rejects anonymous (401).
	 */
	public function test_staff_ai_rejects_anonymous(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/staff-ai/conversations' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status(), 'Staff AI should reject anonymous users with 401' );
	}

	/**
	 * Test: Staff AI conversations rejects subscriber without cap (403).
	 */
	public function test_staff_ai_rejects_subscriber_without_cap(): void {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/staff-ai/conversations' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status(), 'Staff AI should reject subscriber without def_staff_access with 403' );
	}

	/**
	 * Test: Staff AI conversations passes for user with def_staff_access cap.
	 *
	 * Note: The actual backend call will fail (no Python backend), but
	 * permission check should pass — we just verify the response is NOT 401/403.
	 */
	public function test_staff_ai_passes_for_staff_user(): void {
		wp_set_current_user( $this->staff_user_id );

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/staff-ai/conversations' );
		$response = $this->server->dispatch( $request );

		// Permission passed if we don't get 401 or 403.
		$this->assertNotEquals( 401, $response->get_status(), 'Staff user should not get 401' );
		$this->assertNotEquals( 403, $response->get_status(), 'Staff user should not get 403' );
	}

	/**
	 * Test: Staff AI status endpoint — admin (manage_options) passes.
	 */
	public function test_status_passes_for_admin(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/staff-ai/status' );
		$response = $this->server->dispatch( $request );

		// Admin should get past permission check (not 401/403).
		$this->assertNotEquals( 401, $response->get_status(), 'Admin should not get 401 on status' );
		$this->assertNotEquals( 403, $response->get_status(), 'Admin should not get 403 on status' );
	}

	/**
	 * Test: Staff AI status endpoint — subscriber without cap gets 403.
	 */
	public function test_status_rejects_subscriber(): void {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/staff-ai/status' );
		$response = $this->server->dispatch( $request );

		$this->assertContains(
			$response->get_status(),
			array( 401, 403 ),
			'Subscriber should get 401 or 403 on status endpoint'
		);
	}

	/**
	 * Test: tools/me rejects request without Bearer token (anonymous).
	 */
	public function test_tools_me_rejects_without_bearer(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/tools/me' );
		$response = $this->server->dispatch( $request );

		$this->assertContains(
			$response->get_status(),
			array( 401, 403 ),
			'tools/me should reject unauthenticated requests'
		);
	}

	/**
	 * Test: Escalation rejects anonymous (no JWT, no X-DEF-AUTH).
	 */
	public function test_escalation_rejects_anonymous(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/settings/escalation' );
		$request->set_param( 'channel', 'customer' );
		$response = $this->server->dispatch( $request );

		$this->assertContains(
			$response->get_status(),
			array( 401, 403 ),
			'Escalation should reject anonymous requests without X-DEF-AUTH'
		);
	}
}
