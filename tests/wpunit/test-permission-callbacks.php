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

	/**
	 * Setup-Assistant-capability (def_admin_access) user ID.
	 *
	 * @var int
	 */
	private $admin_cap_user_id;

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

		// Create a user with the Setup Assistant capability.
		$this->admin_cap_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin_cap_user          = get_user_by( 'id', $this->admin_cap_user_id );
		$admin_cap_user->add_cap( 'def_admin_access' );
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
	 * Chat Options: the ⋮ menu's write verbs reject anonymous (401) and a
	 * subscriber without the capability (403) - all three routes.
	 */
	public function test_chat_options_rejects_anonymous_and_uncapped(): void {
		$calls = array(
			array( 'PATCH', '/a3-ai/v1/staff-ai/conversations/abc123' ),
			array( 'DELETE', '/a3-ai/v1/staff-ai/conversations/abc123' ),
			array( 'PUT', '/a3-ai/v1/staff-ai/conversations/abc123/project' ),
		);
		foreach ( $calls as $call ) {
			wp_set_current_user( 0 );
			$response = $this->server->dispatch( new WP_REST_Request( $call[0], $call[1] ) );
			$this->assertEquals( 401, $response->get_status(), $call[0] . ' should 401 anonymously' );

			wp_set_current_user( $this->subscriber_id );
			$response = $this->server->dispatch( new WP_REST_Request( $call[0], $call[1] ) );
			$this->assertEquals( 403, $response->get_status(), $call[0] . ' should 403 without the capability' );
		}
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
	 * Test: Staff AI status — an admin PASSES, via the ratified built-in model.
	 *
	 * Access is built in for def_admin_access / def_management_access holders:
	 * map_def_capabilities (map_meta_cap, 9130808) grants def_staff_access
	 * through the capability system, NOT through the admin role itself. On a
	 * real site ensure_def_admin_capability() gives every administrator
	 * def_admin_access; the factory user has no such grant, so the fixture
	 * makes it explicit — real WP's map_meta_cap then does the passing, which
	 * is exactly what this test pins (see the unhook mutation in the PR).
	 */
	public function test_status_passes_for_admin(): void {
		$admin = get_user_by( 'id', $this->admin_id );
		$admin->add_cap( 'def_admin_access' );
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/staff-ai/status' );
		$response = $this->server->dispatch( $request );

		$this->assertNotEquals( 401, $response->get_status(), 'Admin with def_admin_access should not get 401 on status' );
		$this->assertNotEquals( 403, $response->get_status(), 'Admin with def_admin_access should not get 403 on status' );
	}

	/**
	 * Test: the boundary nobody had named — manage_options ALONE stays refused.
	 *
	 * Built-in Staff AI access rides def_admin_access / def_management_access,
	 * never the bare administrator role. The refusal carries the named code
	 * def_staff_access_required (5.7.7) precisely so this assertion can tell
	 * the gate from every other 401/403 — including an absent backend.
	 */
	public function test_status_refuses_manage_options_alone(): void {
		wp_set_current_user( $this->admin_id ); // administrator role, NO def caps

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/staff-ai/status' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status(), 'manage_options alone is refused' );
		$data = $response->get_data();
		$this->assertEquals(
			'def_staff_access_required',
			$data['code'] ?? '',
			'the refusal is the GATE (named code), not a backend accident'
		);
	}

	/**
	 * Test: a user with no DEF caps gets the named 403 — the inverse pin.
	 */
	public function test_status_refuses_no_caps_user_with_named_code(): void {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/staff-ai/status' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status(), 'no-caps user is refused with 403' );
		$data = $response->get_data();
		$this->assertEquals( 'def_staff_access_required', $data['code'] ?? '', 'and the code names the gate' );
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
	 * Test: Setup Assistant active-thread + clear reject anonymous (401).
	 */
	public function test_setup_assistant_thread_routes_reject_anonymous(): void {
		wp_set_current_user( 0 );

		$get = $this->server->dispatch( new WP_REST_Request( 'GET', '/a3-ai/v1/setup-assistant/active-thread' ) );
		$this->assertEquals( 401, $get->get_status(), 'active-thread should reject anonymous with 401' );

		$post = $this->server->dispatch( new WP_REST_Request( 'POST', '/a3-ai/v1/setup-assistant/clear' ) );
		$this->assertEquals( 401, $post->get_status(), 'clear should reject anonymous with 401' );
	}

	/**
	 * Test: Setup Assistant routes reject a subscriber without def_admin_access (403).
	 */
	public function test_setup_assistant_thread_routes_reject_subscriber_without_cap(): void {
		wp_set_current_user( $this->subscriber_id );

		$get = $this->server->dispatch( new WP_REST_Request( 'GET', '/a3-ai/v1/setup-assistant/active-thread' ) );
		$this->assertEquals( 403, $get->get_status(), 'active-thread should reject subscriber without def_admin_access with 403' );

		$post = $this->server->dispatch( new WP_REST_Request( 'POST', '/a3-ai/v1/setup-assistant/clear' ) );
		$this->assertEquals( 403, $post->get_status(), 'clear should reject subscriber without def_admin_access with 403' );
	}

	/**
	 * Fetch a registered route's permission callback from the live server.
	 *
	 * @param string $route Full route path.
	 * @return callable The route's permission_callback.
	 */
	private function permission_callback_for( string $route ): callable {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( $route, $routes, "route registered: $route" );
		$cb = $routes[ $route ][0]['permission_callback'] ?? null;
		$this->assertIsCallable( $cb, "permission_callback present on $route" );
		return $cb;
	}

	/**
	 * Test: Setup Assistant routes pass the permission GATE for a
	 * def_admin_access user — asserted on the callback DIRECTLY.
	 *
	 * The old version dispatched through the route and asserted the status was
	 * not 401/403 — but the proxied backend also produces 401s, so the test's
	 * outcome depended on whether anything answered at the backend URL during
	 * the run (observed flipping with a crash-looping local def-api; a CI
	 * runner has no backend at all). Invoking the captured callback tests the
	 * gate and only the gate, in any environment.
	 */
	public function test_setup_assistant_thread_routes_pass_for_admin_cap_user(): void {
		wp_set_current_user( $this->admin_cap_user_id );

		$get = $this->permission_callback_for( '/a3-ai/v1/setup-assistant/active-thread' );
		$this->assertTrue( true === call_user_func( $get, new WP_REST_Request() ), 'active-thread gate passes def_admin_access' );

		$post = $this->permission_callback_for( '/a3-ai/v1/setup-assistant/clear' );
		$this->assertTrue( true === call_user_func( $post, new WP_REST_Request() ), 'clear gate passes def_admin_access' );

		// And the same callbacks REFUSE without the capability — the pair that
		// proves we captured a real gate, not a passthrough.
		wp_set_current_user( $this->subscriber_id );
		$this->assertNotTrue( call_user_func( $get, new WP_REST_Request() ), 'active-thread gate refuses a no-caps user' );
		$this->assertNotTrue( call_user_func( $post, new WP_REST_Request() ), 'clear gate refuses a no-caps user' );
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
