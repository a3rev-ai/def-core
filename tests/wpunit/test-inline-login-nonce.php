<?php
/**
 * Signing in from inside the chat hands back a REST nonce that works on the next request.
 * The page's own nonce was minted for the visitor, and WordPress refuses it once they
 * hold a login cookie.
 *
 * @package def-core
 */

declare(strict_types=1);

class Test_Inline_Login_Nonce extends WP_Ajax_UnitTestCase {

	public function test_the_nonce_is_good_for_the_session_the_browser_now_holds(): void {
		add_filter( 'send_auth_cookies', '__return_false' );
		$cookie = '';
		add_action(
			'set_logged_in_cookie',
			static function ( $value ) use ( &$cookie ) {
				$cookie = $value;
			},
			99
		);

		$user_id  = self::factory()->user->create(
			array(
				'user_login' => 'pat',
				'user_pass'  => 'a-long-secret-pass',
			)
		);
		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'wp_rest' ),
			'log'      => 'pat',
			'pwd'      => 'a-long-secret-pass',
		);
		$_REQUEST = $_POST;

		ob_start();
		try {
			do_action( 'wp_ajax_nopriv_def_core_inline_login' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		// The harness's die handler closes the buffer into _last_response.
		$response = json_decode( $this->_last_response, true );

		$this->assertTrue( $response['success'] );
		// The next request: this user, holding only the cookie the browser was sent last.
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		$this->assertSame( $user_id, get_current_user_id() );
		$this->assertNotFalse( wp_verify_nonce( $response['data']['nonce'], 'wp_rest' ) );
	}
}
