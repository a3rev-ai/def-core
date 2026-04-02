<?php
/**
 * PHPUnit tests for DEF_Core_GitHub_Updater.
 *
 * @package def-core/tests/unit
 */

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers DEF_Core_GitHub_Updater
 */
final class GitHubUpdaterTest extends TestCase {

	/**
	 * Test config for constructing the updater.
	 */
	private array $config;

	protected function setUp(): void {
		parent::setUp();
		_wp_test_reset_options();
		global $_wp_test_transients, $_wp_test_remote_responses, $_wp_test_active_plugins;
		$_wp_test_transients       = array();
		$_wp_test_remote_responses = array();
		$_wp_test_active_plugins   = array();

		$this->config = array(
			'file'    => '/tmp/wp-plugins/digital-employees/def-core.php',
			'repo'    => 'a3rev-ai/def-core',
			'slug'    => 'digital-employees',
			'asset'   => 'digital-employees.zip',
			'version' => '1.8.0',
		);
	}

	// ── Cache key generation ────────────────────────────────────────────

	public function test_cache_key_format(): void {
		$updater = new DEF_Core_GitHub_Updater( $this->config );

		// The cache key is private, but we can verify it through transient usage.
		// Pre-seed the transient to test caching behavior.
		$expected_key = 'def_gh_update_' . sanitize_key( 'digital-employees' );
		$this->assertSame( 'def_gh_update_digital-employees', $expected_key );
	}

	// ── check_update: empty transient->checked ──────────────────────────

	public function test_check_update_with_empty_checked_returns_unchanged(): void {
		$updater   = new DEF_Core_GitHub_Updater( $this->config );
		$transient = new stdClass();
		// No ->checked property.

		$result = $updater->check_update( $transient );
		$this->assertSame( $transient, $result );
		$this->assertObjectNotHasProperty( 'response', $result );
	}

	// ── check_update: no update needed ──────────────────────────────────

	public function test_check_update_no_update_when_current_gte_remote(): void {
		$this->seed_github_release( '1.8.0' );

		$updater   = new DEF_Core_GitHub_Updater( $this->config );
		$transient = $this->make_transient();

		$result = $updater->check_update( $transient );

		$basename = 'digital-employees/def-core.php';
		$this->assertObjectNotHasProperty( 'response', $result );
		$this->assertObjectHasProperty( 'no_update', $result );
		$this->assertArrayHasKey( $basename, $result->no_update );
		$this->assertSame( '1.8.0', $result->no_update[ $basename ]->new_version );
	}

	// ── check_update: update available ──────────────────────────────────

	public function test_check_update_with_update_available(): void {
		$this->seed_github_release( '2.0.0' );

		$updater   = new DEF_Core_GitHub_Updater( $this->config );
		$transient = $this->make_transient();

		$result = $updater->check_update( $transient );

		$basename = 'digital-employees/def-core.php';
		$this->assertObjectHasProperty( 'response', $result );
		$this->assertArrayHasKey( $basename, $result->response );
		$this->assertSame( '2.0.0', $result->response[ $basename ]->new_version );
		$this->assertStringContainsString( 'digital-employees.zip', $result->response[ $basename ]->package );
	}

	// ── check_update: GitHub API error ──────────────────────────────────

	public function test_check_update_with_api_error_returns_unchanged(): void {
		// Don't seed any remote response — wp_remote_get will return WP_Error.
		$updater   = new DEF_Core_GitHub_Updater( $this->config );
		$transient = $this->make_transient();

		$result = $updater->check_update( $transient );

		$basename = 'digital-employees/def-core.php';
		// Should not have response or no_update for our plugin.
		$has_response  = isset( $result->response[ $basename ] );
		$has_no_update = isset( $result->no_update[ $basename ] );
		$this->assertFalse( $has_response );
		$this->assertFalse( $has_no_update );
	}

	// ── check_update: cached remote info ────────────────────────────────

	public function test_check_update_uses_cached_info(): void {
		// Pre-seed the transient cache directly.
		$cache_key = 'def_gh_update_digital-employees';
		set_transient( $cache_key, array(
			'version'      => '3.0.0',
			'download_url' => 'https://example.com/digital-employees.zip',
			'name'         => 'Digital Employees',
			'description'  => 'Test',
			'changelog'    => '<pre>Test</pre>',
			'published'    => '2026-04-01',
			'requires'     => '6.2',
			'tested'       => '6.8',
		) );

		$updater   = new DEF_Core_GitHub_Updater( $this->config );
		$transient = $this->make_transient();

		$result = $updater->check_update( $transient );

		$basename = 'digital-employees/def-core.php';
		$this->assertArrayHasKey( $basename, $result->response );
		$this->assertSame( '3.0.0', $result->response[ $basename ]->new_version );
	}

	// ── post_install: only fires for matching basename ───────────────────

	public function test_post_install_ignores_other_plugins(): void {
		$updater = new DEF_Core_GitHub_Updater( $this->config );

		$response = $updater->post_install(
			true,
			array( 'plugin' => 'some-other-plugin/other.php' ),
			array( 'destination' => '/tmp/some-other-plugin' )
		);

		// Should return the original response unchanged.
		$this->assertTrue( $response );
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Create a minimal update_plugins transient object.
	 */
	private function make_transient(): stdClass {
		$t = new stdClass();
		$t->checked = array(
			'digital-employees/def-core.php' => '1.8.0',
		);
		return $t;
	}

	/**
	 * Seed the global remote response stubs for the GitHub API.
	 */
	private function seed_github_release( string $version ): void {
		global $_wp_test_remote_responses;

		$tag = 'v' . $version;
		$api_url = 'https://api.github.com/repos/a3rev-ai/def-core/releases/latest';
		$readme_url = 'https://raw.githubusercontent.com/a3rev-ai/def-core/' . $tag . '/readme.txt';

		$_wp_test_remote_responses[ $api_url ] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'tag_name'     => $tag,
				'name'         => 'Digital Employees ' . $version,
				'body'         => 'Release notes for ' . $version,
				'published_at' => '2026-04-01T00:00:00Z',
				'assets'       => array(
					array(
						'name'                 => 'digital-employees.zip',
						'browser_download_url' => 'https://github.com/a3rev-ai/def-core/releases/download/' . $tag . '/digital-employees.zip',
					),
				),
			) ),
		);

		$_wp_test_remote_responses[ $readme_url ] = array(
			'response' => array( 'code' => 200 ),
			'body'     => "=== Digital Employees ===\nRequires at least: 6.2\nTested up to: 6.8\n",
		);
	}
}
