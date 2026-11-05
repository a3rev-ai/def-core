<?php
/**
 * The acknowledgement's {first-name} and {transcript}. Its address can be one a stranger
 * typed, so nothing that could carry a message reaches it: a first name is one word of
 * letters, and the transcript goes only to the address on the person's own account.
 *
 * @package def-core
 */

declare(strict_types=1);

class Test_Escalation_Ack_Tags extends WP_UnitTestCase {

	const BODY = "Please call me\n\n---\nConversation Transcript:\n\nUser: visit http://spam.example now\n";

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
		update_option( 'def_core_chat_escalation_ack_enabled', '1' );
		update_option( 'def_core_chat_escalation_ack_subject', 'Thanks {first-name}' );
		update_option( 'def_core_chat_escalation_ack_body', "Hello {first-name},\n\n{transcript}\n\nThe team" );
	}

	private function send( array $fields ): array {
		$request = new WP_REST_Request( 'POST', '/a3-ai/v1/customer-chat/send-escalation-email' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $fields + array( 'subject' => 'A question', 'body' => self::BODY ) ) );
		return (array) rest_get_server()->dispatch( $request )->get_data();
	}

	private function sign_in(): void {
		wp_set_current_user(
			self::factory()->user->create(
				array(
					'first_name' => 'Pat',
					'user_email' => 'pat@example.com',
				)
			)
		);
	}

	public function test_a_visitor_is_greeted_and_gets_no_transcript(): void {
		$data = $this->send( array( 'reply_to' => 'sam@example.com', 'contact_name' => 'Sam Kay' ) );
		$this->assertSame( 'sam@example.com', $this->mail[1]['to'] );
		$this->assertSame( 'Thanks Sam', $this->mail[1]['subject'] );
		$this->assertSame( "Hello Sam,\n\nThe team", $this->mail[1]['message'] );
		$this->assertTrue( $data['ack_sent'] );
		$this->assertSame( 'sent', $data['status'] );
	}

	public function test_a_typed_name_cannot_carry_a_link(): void {
		$this->send( array( 'reply_to' => 'sam@example.com', 'contact_name' => 'http://spam.example/x now' ) );
		$this->assertStringNotContainsString( 'spam.example', $this->mail[1]['message'] );
		$this->assertStringNotContainsString( '://', $this->mail[1]['subject'] );
		$this->assertStringNotContainsString( 'now', $this->mail[1]['message'] );
	}

	public function test_a_visitor_with_no_name_is_greeted_plainly(): void {
		$this->send( array( 'reply_to' => 'sam@example.com' ) );
		$this->assertStringStartsWith( 'Hello there,', $this->mail[1]['message'] );
	}

	public function test_a_signed_in_customer_gets_their_transcript_at_their_own_address(): void {
		$this->sign_in();
		$this->send( array() );
		$this->assertSame( 'pat@example.com', $this->mail[1]['to'] );
		$this->assertStringStartsWith( 'Hello Pat,', $this->mail[1]['message'] );
		$this->assertStringContainsString( 'User: visit http://spam.example now', $this->mail[1]['message'] );
	}

	public function test_signing_in_does_not_open_the_transcript_to_a_typed_address(): void {
		$this->sign_in();
		$this->send( array( 'reply_to' => 'victim@example.com' ) );
		$this->assertSame( 'victim@example.com', $this->mail[1]['to'] );
		$this->assertStringNotContainsString( 'spam.example', $this->mail[1]['message'] );
	}

	public function test_a_cooldown_claims_no_confirmation(): void {
		$this->send( array( 'reply_to' => 'sam@example.com' ) );
		$data = $this->send( array( 'reply_to' => 'sam@example.com' ) );
		$this->assertArrayNotHasKey( 'ack_sent', $data );
	}

	public function test_no_acknowledgement_claims_no_confirmation(): void {
		update_option( 'def_core_chat_escalation_ack_enabled', '0' );
		$this->assertArrayNotHasKey( 'ack_sent', $this->send( array( 'reply_to' => 'sam@example.com' ) ) );
	}
}
