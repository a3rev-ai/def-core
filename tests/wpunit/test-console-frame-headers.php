<?php
/**
 * The Staff-AI console must deny framing.
 *
 * Pins both headers AND the fact that the shell render path sends them, so a
 * future refactor of render_shell cannot silently drop the protection.
 *
 * Why this does not read headers_list(): header() is a no-op under the CLI
 * SAPI that runs this suite, so headers_list() is always empty here — a test
 * asserting on it would pass whether the headers were sent or not. So the
 * sender returns the pair it guarantees, and that is what is pinned.
 *
 * @package def-core
 * @group security
 */

declare(strict_types=1);

class Test_Console_Frame_Headers extends WP_UnitTestCase {

	/**
	 * The sender returns both frame-denial headers, with the values that
	 * actually deny cross-origin framing.
	 */
	public function test_sender_returns_both_frame_denial_headers(): void {
		$method = new ReflectionMethod( 'DEF_Core_Staff_AI', 'send_console_frame_headers' );
		$method->setAccessible( true );
		$sent = $method->invoke( null );

		$this->assertSame(
			array( 'X-Frame-Options', 'Content-Security-Policy' ),
			array_keys( $sent ),
			'Both frame-denial headers must be accounted for.'
		);
		$this->assertSame( 'SAMEORIGIN', $sent['X-Frame-Options'] );
		$this->assertStringContainsString(
			"frame-ancestors 'self'",
			$sent['Content-Security-Policy'],
			"The CSP must restrict frame-ancestors to 'self'."
		);
	}

	/**
	 * The shell render path calls the sender.
	 *
	 * Asserted against render_shell's own source lines rather than by running
	 * it: render_shell emits a whole HTML document and requires a logged-in
	 * user with console access, and the headers it sends are unreadable under
	 * CLI anyway. Reading the method body is what makes "cannot silently drop
	 * them" enforceable — delete the call and this fails.
	 */
	public function test_render_shell_sends_them(): void {
		$render = new ReflectionMethod( 'DEF_Core_Staff_AI', 'render_shell' );
		$source = file( $render->getFileName() );
		$body   = implode(
			'',
			array_slice(
				$source,
				$render->getStartLine() - 1,
				$render->getEndLine() - $render->getStartLine() + 1
			)
		);

		$this->assertStringContainsString(
			'send_console_frame_headers()',
			$body,
			'render_shell must send the console frame-denial headers.'
		);
	}

	/**
	 * Customer Chat is out of scope, deliberately: embedded-widget deployments
	 * frame it on purpose. If someone later applies frame denial globally, this
	 * fails and forces the conversation.
	 */
	public function test_frame_denial_is_not_applied_globally(): void {
		$this->assertFalse(
			has_action( 'send_headers', 'send_frame_options_header' ),
			'Frame denial belongs to the console render, not to every front-end response.'
		);
	}
}
