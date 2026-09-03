<?php
/**
 * The Staff-AI console must deny framing.
 *
 * Pins the header LINES the console emits, the headers_sent() guard in front
 * of them, and the two render paths that send them — so a future refactor
 * cannot silently drop the protection.
 *
 * Why a spy rather than headers_list(): PHPUnit has written its own banner
 * before any test body runs, so headers_sent() is already true here. A real
 * header() call would both no-op AND raise the warning this config converts
 * into a failure, and headers_list() stays empty under the CLI SAPI — so an
 * assertion on it would pass whether or not anything was ever sent.
 *
 * @package def-core
 * @group security
 */

declare(strict_types=1);

class Test_Console_Frame_Headers extends WP_UnitTestCase {

	/**
	 * Invoke the private sender with a spy in place of header().
	 *
	 * @return array<int, array{0: string, 1: bool}> Emitted [ line, replace ].
	 */
	private function emitted_headers(): array {
		$calls  = array();
		$method = new ReflectionMethod( 'DEF_Core_Staff_AI', 'send_console_frame_headers' );
		$method->setAccessible( true );
		$method->invoke(
			null,
			function ( string $line, bool $replace = true ) use ( &$calls ): void {
				$calls[] = array( $line, $replace );
			}
		);
		return $calls;
	}

	/**
	 * Exactly the two frame-denial headers go out, and the CSP is APPENDED.
	 *
	 * Appending matters as much as the value: replacing would wipe a security
	 * plugin's whole policy on the one page that renders the console and its
	 * REST nonce. Drop either emit, or flip that flag, and this fails.
	 */
	public function test_frame_denial_headers_are_emitted_and_csp_is_appended(): void {
		$this->assertSame(
			array(
				array( 'X-Frame-Options: SAMEORIGIN', true ),
				array( "Content-Security-Policy: frame-ancestors 'self';", false ),
			),
			$this->emitted_headers()
		);
	}

	/**
	 * With no spy, the real path must return quietly when output has already
	 * begun — which, in this suite, it always has.
	 *
	 * Remove the headers_sent() guard and header() raises E_WARNING, which
	 * phpunit.xml.dist's convertWarningsToExceptions turns into a failure. So
	 * this pins the guard by being the case that would break without it.
	 */
	public function test_real_path_is_silent_once_output_has_begun(): void {
		$this->assertTrue( headers_sent(), 'Precondition: this suite runs with output already begun.' );

		$method = new ReflectionMethod( 'DEF_Core_Staff_AI', 'send_console_frame_headers' );
		$method->setAccessible( true );
		$method->invoke( null );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Both render paths of /staff-ai send them.
	 *
	 * Read from each method's own source lines: they emit whole HTML documents
	 * and need a logged-in user, and the headers are unreadable under CLI
	 * anyway. Line comments are stripped first, so commenting a call out fails
	 * this too. getStartLine() excludes the docblock, so a mention in prose
	 * cannot false-pass.
	 *
	 * The access-denied branch matters as much as the shell: if only one of
	 * them denied framing, the difference would be readable cross-site as
	 * "does this visitor hold console access".
	 *
	 * @dataProvider render_paths
	 *
	 * @param string $method Method name on DEF_Core_Staff_AI.
	 */
	public function test_render_paths_send_them( string $method ): void {
		$reflected = new ReflectionMethod( 'DEF_Core_Staff_AI', $method );
		$source    = file( $reflected->getFileName() );
		$body      = implode(
			'',
			array_slice(
				$source,
				$reflected->getStartLine() - 1,
				$reflected->getEndLine() - $reflected->getStartLine() + 1
			)
		);
		$body = preg_replace( '~//.*$~m', '', $body );

		$this->assertStringContainsString(
			'self::send_console_frame_headers()',
			$body,
			$method . '() must send the console frame-denial headers.'
		);
	}

	/**
	 * The two /staff-ai responses that render a document.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function render_paths(): array {
		return array(
			'console shell'  => array( 'render_shell' ),
			'access denied'  => array( 'render_access_denied' ),
		);
	}
}
