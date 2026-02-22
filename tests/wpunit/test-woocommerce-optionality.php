<?php
/**
 * D4: WooCommerce Optionality Tests
 *
 * Verifies that def-core works correctly when WooCommerce is NOT installed:
 * plugin activates, core classes exist, core routes work, WC routes absent.
 *
 * @package def-core
 * @group woocommerce-optionality
 */

declare(strict_types=1);

class Test_WooCommerce_Optionality extends WP_UnitTestCase {

	/**
	 * Test: Plugin activates and core classes exist without WooCommerce.
	 */
	public function test_plugin_activates_core_classes_exist(): void {
		// WooCommerce should NOT be present in this test environment.
		$this->assertFalse(
			class_exists( 'WooCommerce' ),
			'WooCommerce should not be loaded in this test environment'
		);

		// Core plugin classes must exist.
		$this->assertTrue( class_exists( 'DEF_Core' ), 'DEF_Core class should exist' );
		$this->assertTrue( class_exists( 'DEF_Core_JWT' ), 'DEF_Core_JWT class should exist' );
		$this->assertTrue( class_exists( 'DEF_Core_Routes' ), 'DEF_Core_Routes class should exist' );
		$this->assertTrue( class_exists( 'DEF_Core_Tools' ), 'DEF_Core_Tools class should exist' );
		$this->assertTrue( class_exists( 'DEF_Core_Staff_AI' ), 'DEF_Core_Staff_AI class should exist' );
		$this->assertTrue( class_exists( 'DEF_Core_Escalation' ), 'DEF_Core_Escalation class should exist' );
		$this->assertTrue( class_exists( 'DEF_Core_Admin' ), 'DEF_Core_Admin class should exist' );
		$this->assertTrue( class_exists( 'DEF_Core_Cache' ), 'DEF_Core_Cache class should exist' );
		$this->assertTrue( class_exists( 'DEF_Core_API_Registry' ), 'DEF_Core_API_Registry class should exist' );
	}

	/**
	 * Test: Core routes work without WooCommerce.
	 */
	public function test_core_routes_work_without_woocommerce(): void {
		$server = rest_get_server();

		// JWKS (public) should work.
		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/jwks' );
		$response = $server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status(), 'JWKS should work without WooCommerce' );

		// context-token should work for logged-in user.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/a3-ai/v1/context-token' );
		$response = $server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status(), 'context-token should work without WooCommerce' );

		wp_set_current_user( 0 );
	}

	/**
	 * Test: WooCommerce routes are NOT registered.
	 */
	public function test_wc_routes_not_registered(): void {
		$server = rest_get_server();
		$routes = $server->get_routes( 'a3-ai/v1' );

		$wc_routes = array_filter(
			array_keys( $routes ),
			function ( $route ) {
				return strpos( $route, '/a3-ai/v1/tools/wc/' ) === 0;
			}
		);

		$this->assertEmpty(
			$wc_routes,
			'No WC routes should be registered: ' . implode( ', ', $wc_routes )
		);
	}
}
