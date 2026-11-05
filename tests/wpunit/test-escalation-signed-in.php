<?php
/**
 * A signed-in customer's hand-off email says who it is from: the form hides the contact
 * fields from them, so their name and address come from their account, server-side.
 *
 * @package def-core
 */

declare(strict_types=1);

class Test_Escalation_Signed_In extends WP_UnitTestCase {

	private array $mail = array();

	public function set_up(): void {
		parent::set_up();
		$this->mail = array();
		add_filter(
			'pre_wp_mail',
			function ( $sent, $atts ) {
				$this->mail[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	private function send( array $fields ): int {
		$request = new WP_REST_Request( 'POST', '/a3-ai/v1/customer-chat/send-escalation-email' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $fields + array( 'subject' => 'A question' ) ) );
		return rest_get_server()->dispatch( $request )->get_status();
	}

	private function reply_to(): string {
		foreach ( (array) $this->mail[0]['headers'] as $header ) {
			if ( 0 === stripos( $header, 'Reply-To:' ) ) {
				return trim( substr( $header, strlen( 'Reply-To:' ) ) );
			}
		}
		return '';
	}

	private function sign_in( string $name = 'Pat Lee' ): void {
		wp_set_current_user(
			self::factory()->user->create(
				array(
					'display_name' => $name,
					'user_email'   => 'pat@example.com',
				)
			)
		);
	}

	public function test_a_signed_in_customer_can_be_replied_to_and_is_named(): void {
		$this->sign_in();
		$this->assertSame( 200, $this->send( array( 'body' => 'Please call me back' ) ) );
		$this->assertSame( 'pat@example.com', $this->reply_to() );
		$this->assertStringStartsWith( "From: Pat Lee\nEmail: pat@example.com\n\n", $this->mail[0]['message'] );
		$this->assertStringContainsString( 'Please call me back', $this->mail[0]['message'] );
	}

	public function test_a_name_reads_as_typed(): void {
		$this->sign_in( 'Ben & Co' );
		$this->send( array( 'body' => 'Hello' ) );
		$this->assertStringStartsWith( 'From: Ben & Co', $this->mail[0]['message'] );
	}

	public function test_a_companion_is_not_handed_a_customers_address(): void {
		$seen = null;
		add_filter(
			'def_core_customer_chat_handoff_body',
			function ( $body, $safe_body ) use ( &$seen ) {
				$seen = $safe_body;
				return $body;
			},
			10,
			2
		);
		$this->sign_in();
		$this->send( array( 'body' => 'Hello' ) );
		$this->assertArrayNotHasKey( 'reply_to', $seen );
		$this->assertSame( 'pat@example.com', $this->reply_to() );
	}

	public function test_details_typed_into_the_form_are_not_repeated(): void {
		$this->sign_in();
		$body = "From: Sam Kay\nEmail: sam@example.com\n\nHello";
		$this->assertSame( 200, $this->send( array( 'body' => $body, 'reply_to' => 'sam@example.com' ) ) );
		$this->assertSame( 'sam@example.com', $this->reply_to() );
		$this->assertStringNotContainsString( 'pat@example.com', $this->mail[0]['message'] );
	}

	public function test_an_empty_request_stays_empty(): void {
		$this->sign_in();
		$this->send( array( 'body' => '' ) );
		$this->assertSame( array(), $this->mail );
	}

	public function test_an_anonymous_visitor_gains_no_account_details(): void {
		$this->assertSame( 200, $this->send( array( 'body' => 'Hello' ) ) );
		$this->assertSame( '', $this->reply_to() );
		$this->assertStringStartsWith( 'Hello', $this->mail[0]['message'] );
	}
}
