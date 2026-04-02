<?php
/**
 * PHPUnit tests for DEF_Core_API_Registry.
 *
 * @package def-core/tests/unit
 */

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers DEF_Core_API_Registry
 */
final class ApiRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		_wp_test_reset_options();

		// Reset the singleton via reflection.
		$ref  = new ReflectionClass( DEF_Core_API_Registry::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	public function dummy_callback(): void {}

	// ── register_tool ───────────────────────────────────────────────────

	public function test_register_tool_success(): void {
		$registry = DEF_Core_API_Registry::instance();
		$result   = $registry->register_tool(
			'/tools/test',
			'Test Tool',
			array( 'GET' ),
			array( $this, 'dummy_callback' )
		);
		$this->assertTrue( $result );
		$this->assertTrue( $registry->is_registered( '/tools/test' ) );
	}

	public function test_reject_empty_route(): void {
		$registry = DEF_Core_API_Registry::instance();
		$result   = $registry->register_tool(
			'',
			'Test Tool',
			array( 'GET' ),
			array( $this, 'dummy_callback' )
		);
		$this->assertFalse( $result );
	}

	public function test_reject_empty_name(): void {
		$registry = DEF_Core_API_Registry::instance();
		$result   = $registry->register_tool(
			'/tools/test',
			'',
			array( 'GET' ),
			array( $this, 'dummy_callback' )
		);
		$this->assertFalse( $result );
	}

	public function test_duplicate_route_different_module_rejected(): void {
		$registry = DEF_Core_API_Registry::instance();
		$registry->register_tool(
			'/tools/test',
			'Test Tool',
			array( 'GET' ),
			array( $this, 'dummy_callback' ),
			null,
			array(),
			'module_a'
		);
		$result = $registry->register_tool(
			'/tools/test',
			'Test Tool 2',
			array( 'POST' ),
			array( $this, 'dummy_callback' ),
			null,
			array(),
			'module_b'
		);
		$this->assertFalse( $result );
	}

	public function test_duplicate_route_same_module_allowed(): void {
		$registry = DEF_Core_API_Registry::instance();
		$registry->register_tool(
			'/tools/test',
			'Test Tool',
			array( 'GET' ),
			array( $this, 'dummy_callback' ),
			null,
			array(),
			'module_a'
		);
		$result = $registry->register_tool(
			'/tools/test',
			'Test Tool Updated',
			array( 'POST' ),
			array( $this, 'dummy_callback' ),
			null,
			array(),
			'module_a'
		);
		$this->assertTrue( $result );
	}

	// ── unregister_tool ─────────────────────────────────────────────────

	public function test_unregister_tool(): void {
		$registry = DEF_Core_API_Registry::instance();
		$registry->register_tool( '/tools/test', 'Test', array( 'GET' ), array( $this, 'dummy_callback' ) );
		$this->assertTrue( $registry->unregister_tool( '/tools/test' ) );
		$this->assertFalse( $registry->is_registered( '/tools/test' ) );
	}

	public function test_unregister_nonexistent_returns_false(): void {
		$registry = DEF_Core_API_Registry::instance();
		$this->assertFalse( $registry->unregister_tool( '/tools/does-not-exist' ) );
	}

	// ── get_tools ───────────────────────────────────────────────────────

	public function test_get_tools_returns_all(): void {
		$registry = DEF_Core_API_Registry::instance();
		$registry->register_tool( '/tools/a', 'A', array( 'GET' ), array( $this, 'dummy_callback' ), null, array(), 'mod1' );
		$registry->register_tool( '/tools/b', 'B', array( 'POST' ), array( $this, 'dummy_callback' ), null, array(), 'mod2' );

		$tools = $registry->get_tools();
		$this->assertCount( 2, $tools );
		$this->assertArrayHasKey( '/tools/a', $tools );
		$this->assertArrayHasKey( '/tools/b', $tools );
	}

	public function test_get_tools_filters_by_module(): void {
		$registry = DEF_Core_API_Registry::instance();
		$registry->register_tool( '/tools/a', 'A', array( 'GET' ), array( $this, 'dummy_callback' ), null, array(), 'mod1' );
		$registry->register_tool( '/tools/b', 'B', array( 'POST' ), array( $this, 'dummy_callback' ), null, array(), 'mod2' );

		$tools = $registry->get_tools( 'mod1' );
		$this->assertCount( 1, $tools );
		$this->assertArrayHasKey( '/tools/a', $tools );
	}

	// ── is_registered ───────────────────────────────────────────────────

	public function test_is_registered_false_for_unknown(): void {
		$registry = DEF_Core_API_Registry::instance();
		$this->assertFalse( $registry->is_registered( '/tools/nonexistent' ) );
	}

	// ── is_tool_enabled ─────────────────────────────────────────────────

	public function test_is_tool_enabled_defaults_true(): void {
		$registry = DEF_Core_API_Registry::instance();
		$this->assertTrue( $registry->is_tool_enabled( '/tools/any' ) );
	}

	public function test_is_tool_enabled_reads_option(): void {
		update_option( 'def_core_tools_status', array( '/tools/cart' => 0 ) );
		$registry = DEF_Core_API_Registry::instance();
		$this->assertFalse( $registry->is_tool_enabled( '/tools/cart' ) );
	}

	public function test_is_tool_enabled_returns_true_when_option_is_1(): void {
		update_option( 'def_core_tools_status', array( '/tools/cart' => 1 ) );
		$registry = DEF_Core_API_Registry::instance();
		$this->assertTrue( $registry->is_tool_enabled( '/tools/cart' ) );
	}

	// ── Core routes always enabled ──────────────────────────────────────

	public function test_core_routes_always_enabled_in_status(): void {
		$registry = DEF_Core_API_Registry::instance();
		$registry->register_tool( '/context-token', 'Context Token', array( 'POST' ), array( $this, 'dummy_callback' ) );
		$registry->register_tool( '/jwks', 'JWKS', array( 'GET' ), array( $this, 'dummy_callback' ) );

		// Disable all tools via option.
		update_option( 'def_core_tools_status', array(
			'/context-token' => 0,
			'/jwks'          => 0,
		) );

		$with_status = $registry->get_tools_with_status();
		$this->assertTrue( $with_status['/context-token']['enabled'] );
		$this->assertTrue( $with_status['/context-token']['is_core'] );
		$this->assertTrue( $with_status['/jwks']['enabled'] );
		$this->assertTrue( $with_status['/jwks']['is_core'] );
	}
}
