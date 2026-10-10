<?php
/**
 * Setup Assistant escalation: the sender gets a copy for their records (Steve, 2026-10-10).
 *
 * rest_setup_assistant_send() turns on send_escalation_email()'s existing user-copy option
 * ("Copy: <subject>") for the signed-in admin's own account email — never an address typed
 * into the form. The partner's email itself is unchanged (Reply-To the admin).
 *
 * Runs standalone (no WordPress bootstrap), same harness as test-staff-ai-share-attachments.php.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

global $_wp_test_current_user, $_wp_test_can, $_wp_test_mail_calls;
$_wp_test_current_user = null;
$_wp_test_can          = true;
$_wp_test_mail_calls   = array();

if ( ! defined( 'DEF_CORE_API_NAME_SPACE' ) ) {
	define( 'DEF_CORE_API_NAME_SPACE', 'a3-ai/v1' );
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID = 7;
		public $user_email = 'owner@site.example';
		public $display_name = 'Site Owner';
		public $first_name = '';
		public $last_name = '';
		public $user_login = 'owner';
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): WP_User {
		global $_wp_test_current_user;
		return $_wp_test_current_user ?? new WP_User();
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		global $_wp_test_can;
		return $_wp_test_can;
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) { return 'good' === $nonce ? 1 : false; }
}
if ( ! function_exists( '__' ) ) {
	function __( string $t, string $d = 'default' ): string { return $t; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $k = '' ): string { return 'Test Site'; }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool { return true; }
}
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $body, $headers = array(), $attachments = array() ): bool {
		global $_wp_test_mail_calls;
		$_wp_test_mail_calls[] = array( 'to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers );
		return true;
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $body = '';
		private $params = array();
		private $headers = array();
		public function __construct( string $method = 'GET', string $route = '' ) {}
		public function set_header( string $k, string $v ): void { $this->headers[ strtolower( $k ) ] = $v; }
		public function get_header( string $k ) { return $this->headers[ strtolower( $k ) ] ?? null; }
		public function set_body( string $body ): void { $this->body = $body; }
		public function set_param( string $k, $v ): void { $this->params[ $k ] = $v; }
		public function get_param( string $k ) { return $this->params[ $k ] ?? null; }
		public function get_json_params() { return json_decode( $this->body, true ); }
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data;
		private $status;
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
	}
}

require_once dirname( __DIR__ ) . '/includes/class-def-core-escalation.php';

update_option( 'admin_email', 'admin@site.example' );
update_option( 'def_core_escalation_setup_assistant', array( 'to' => array( 'partner@agency.example' ) ) );

$pass = 0;
$fail = 0;

function check( bool $cond, string $label ): void {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  ✓ $label\n";
	} else {
		$fail++;
		echo "  ✗ $label\n";
	}
}

function escalate( array $body, string $nonce = 'good' ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/a3-ai/v1/setup-assistant/send-escalation-email' );
	$request->set_header( 'X-WP-Nonce', $nonce );
	$request->set_body( (string) wp_json_encode( $body ) );
	return DEF_Core_Escalation::rest_setup_assistant_send( $request );
}

echo "Setup Assistant escalation — a copy to the sender\n";

$_wp_test_mail_calls = array();
$response = escalate( array( 'subject' => 'Need help with the sync', 'body' => 'It stopped yesterday.' ) );
check( 200 === $response->get_status(), 'the request is sent' );
check( 2 === count( $_wp_test_mail_calls ), 'two emails: the request and the copy' );
check( array( 'partner@agency.example' ) === (array) $_wp_test_mail_calls[0]['to'], 'the request goes to the configured partner address' );
check( in_array( 'Reply-To: owner@site.example', $_wp_test_mail_calls[0]['headers'], true ), 'the partner can reply straight to the admin' );
check( 'owner@site.example' === $_wp_test_mail_calls[1]['to'], 'the copy goes to the signed-in admin' );
check( 'Copy: Need help with the sync' === $_wp_test_mail_calls[1]['subject'], 'the copy is marked "Copy:"' );
check( $_wp_test_mail_calls[0]['body'] === $_wp_test_mail_calls[1]['body'], 'the copy is exactly what was sent' );

// The client can't redirect the copy: the address comes from the session only.
$_wp_test_mail_calls = array();
escalate( array( 'subject' => 'x', 'body' => 'y', 'user_copy_email' => 'victim@else.example', 'send_copy_to_user' => true ) );
check( 'owner@site.example' === $_wp_test_mail_calls[1]['to'], 'a client-supplied copy address is ignored' );

// No admin access → nothing is sent, copy included.
$_wp_test_can        = false;
$_wp_test_mail_calls = array();
$response            = escalate( array( 'subject' => 'x', 'body' => 'y' ) );
check( 403 === $response->get_status() && array() === $_wp_test_mail_calls, 'a user without DEF admin access sends nothing' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
