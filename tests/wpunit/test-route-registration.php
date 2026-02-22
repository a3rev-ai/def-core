<?php
/**
 * D1: Route Registration Tests
 *
 * Verifies that all expected REST routes are registered with
 * correct HTTP methods and permission callbacks.
 *
 * @package def-core
 * @group routes
 */

declare(strict_types=1);

class Test_Route_Registration extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * All registered routes (namespace-filtered).
	 *
	 * @var array
	 */
	private $routes;

	public function set_up(): void {
		parent::set_up();

		// Ensure REST server is initialized and routes are registered.
		global $wp_rest_server;
		$this->server = rest_get_server();
		$this->routes = $this->server->get_routes( 'a3-ai/v1' );
	}

	/**
	 * Test that core routes are registered.
	 */
	public function test_core_routes_registered(): void {
		$this->assertArrayHasKey( '/a3-ai/v1/context-token', $this->routes, 'context-token route missing' );
		$this->assertArrayHasKey( '/a3-ai/v1/jwks', $this->routes, 'jwks route missing' );
		$this->assertArrayHasKey( '/a3-ai/v1/tools/me', $this->routes, 'tools/me route missing' );
	}

	/**
	 * Test that Staff AI routes are registered.
	 */
	public function test_staff_ai_routes_registered(): void {
		$expected = array(
			'/a3-ai/v1/staff-ai/conversations',
			'/a3-ai/v1/staff-ai/chat',
			'/a3-ai/v1/staff-ai/status',
			'/a3-ai/v1/staff-ai/tools',
			'/a3-ai/v1/staff-ai/tools/invoke',
		);
		foreach ( $expected as $route ) {
			$this->assertArrayHasKey( $route, $this->routes, "Staff AI route missing: $route" );
		}
	}

	/**
	 * Test that escalation routes are registered.
	 */
	public function test_escalation_routes_registered(): void {
		$this->assertArrayHasKey( '/a3-ai/v1/settings/escalation', $this->routes, 'settings/escalation route missing' );
		$this->assertArrayHasKey( '/a3-ai/v1/escalation/send-email', $this->routes, 'escalation/send-email route missing' );
	}

	/**
	 * Test that WooCommerce routes are NOT registered when WC is absent.
	 */
	public function test_wc_routes_not_registered_without_woocommerce(): void {
		$wc_routes = array_filter(
			array_keys( $this->routes ),
			function ( $route ) {
				return strpos( $route, '/a3-ai/v1/tools/wc/' ) === 0;
			}
		);
		$this->assertEmpty( $wc_routes, 'WC routes should not be registered without WooCommerce: ' . implode( ', ', $wc_routes ) );
	}

	/**
	 * Test that all routes under our namespace have permission callbacks.
	 */
	public function test_all_routes_have_permission_callbacks(): void {
		foreach ( $this->routes as $route_path => $handlers ) {
			// Skip the auto-generated namespace index route.
			if ( '/a3-ai/v1' === $route_path ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				if ( ! is_array( $handler ) || ! isset( $handler['callback'] ) ) {
					continue;
				}
				$this->assertArrayHasKey(
					'permission_callback',
					$handler,
					"Route $route_path is missing permission_callback key"
				);
				$this->assertNotEmpty(
					$handler['permission_callback'],
					"Route $route_path has empty permission_callback"
				);
			}
		}
	}

	/**
	 * Test that routes use correct HTTP methods.
	 */
	public function test_correct_http_methods(): void {
		// GET-only routes.
		$get_routes = array(
			'/a3-ai/v1/context-token',
			'/a3-ai/v1/jwks',
			'/a3-ai/v1/tools/me',
			'/a3-ai/v1/staff-ai/conversations',
			'/a3-ai/v1/staff-ai/status',
			'/a3-ai/v1/staff-ai/tools',
			'/a3-ai/v1/settings/escalation',
		);
		foreach ( $get_routes as $route_path ) {
			if ( ! isset( $this->routes[ $route_path ] ) ) {
				continue; // Already tested in other methods.
			}
			$methods = $this->get_route_methods( $route_path );
			$this->assertContains( 'GET', $methods, "$route_path should accept GET" );
		}

		// POST-only routes.
		$post_routes = array(
			'/a3-ai/v1/staff-ai/chat',
			'/a3-ai/v1/staff-ai/tools/invoke',
			'/a3-ai/v1/escalation/send-email',
		);
		foreach ( $post_routes as $route_path ) {
			if ( ! isset( $this->routes[ $route_path ] ) ) {
				continue;
			}
			$methods = $this->get_route_methods( $route_path );
			$this->assertContains( 'POST', $methods, "$route_path should accept POST" );
		}
	}

	/**
	 * Get the HTTP methods for a given route.
	 *
	 * @param string $route_path The route path.
	 * @return array HTTP methods.
	 */
	private function get_route_methods( string $route_path ): array {
		$methods = array();
		if ( isset( $this->routes[ $route_path ] ) ) {
			foreach ( $this->routes[ $route_path ] as $handler ) {
				if ( is_array( $handler ) && isset( $handler['methods'] ) ) {
					$methods = array_merge( $methods, array_keys( array_filter( $handler['methods'] ) ) );
				}
			}
		}
		return array_unique( $methods );
	}
}
