<?php
/**
 * W75 G2 — the hand-off leg: Customer Chat's send-escalation-email queues
 * handoff_sent only once the hand-off has actually been sent.
 *
 * @package def-core
 */

declare(strict_types=1);

class Test_Goals_Handoff extends WP_UnitTestCase {

	const REF = 'orch-0123456789abcdef';

	public function set_up(): void {
		parent::set_up();
		update_option( 'def_core_api_key', 'tenant-key' );
		_set_cron_array( array() );
	}

	private function send( bool $mail_sent ): int {
		add_filter( 'pre_wp_mail', $mail_sent ? '__return_true' : '__return_false' );
		$request = new WP_REST_Request( 'POST', '/a3-ai/v1/customer-chat/send-escalation-email' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'subject' => 'A question', 'body' => 'Hello', 'thread_id' => self::REF ) ) );
		return rest_get_server()->dispatch( $request )->get_status();
	}

	private function queued(): array {
		$out = array();
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( $hooks[ DEF_Core_Goals::CRON_HOOK ] ?? array() as $event ) {
				$out[] = $event['args'][0];
			}
		}
		return $out;
	}

	public function test_a_sent_handoff_is_queued_once_keyed_per_chat(): void {
		$this->assertSame( 200, $this->send( true ) );
		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( 'handoff_sent', $queued[0]['type'] );
		$this->assertSame( self::REF, $queued[0]['chat_ref'] );
		$this->assertSame( wp_hash( 'handoff:' . self::REF ), $queued[0]['idempotency_key'] );
	}

	public function test_a_handoff_that_failed_to_send_is_not_queued(): void {
		$this->assertSame( 500, $this->send( false ) );
		$this->assertSame( array(), $this->queued() );
	}
}
