<?php
/**
 * Unit tests for DEF_Core_Goals — the W75 G2 outcome emitters.
 *
 * What a queued report holds (the §4 contract: no personal data, the key shapes),
 * which submissions are skipped (spam, no chat, not connected), and the send.
 *
 * @package def-core/tests/unit
 */

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

if ( ! function_exists( 'wp_hash' ) ) {
	function wp_hash( $data, $scheme = 'auth' ) {
		return hash_hmac( 'md5', (string) $data, wp_salt( $scheme ) );
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		$GLOBALS['_wp_test_posts'][] = array( 'url' => $url, 'args' => $args );
		return array( 'response' => array( 'code' => 202 ), 'body' => '{}' );
	}
}
if ( ! class_exists( 'DEF_Core' ) ) {
	class DEF_Core {
		public static function get_def_api_url_internal(): string {
			return 'https://def.test';
		}
	}
}
if ( ! class_exists( 'DEF_Core_Logger' ) ) {
	class DEF_Core_Logger {
		const SOURCE_SYNC = 'sync';
		public static function warning( string $source, string $message, array $context = array() ): void {}
	}
}
if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		private $id;
		private $meta;
		public function __construct( int $id, array $meta ) {
			$this->id   = $id;
			$this->meta = $meta;
		}
		public function get_id(): int {
			return $this->id;
		}
		public function get_meta( string $key ) {
			return $this->meta[ $key ] ?? '';
		}
		public function update_meta_data( $k, $v ) {
			$this->meta[ $k ] = $v;
		}
		public function save() {}
	}
}
if ( ! function_exists( '_get_cron_array' ) ) {
	function _get_cron_array() {
		return $GLOBALS['_wp_test_cron_array'] ?? array();
	}
}

if ( ! class_exists( 'FrmEntry' ) ) {
	class FrmEntry {
		public static function getOne( $id ) {
			return $GLOBALS['_wp_test_frm_entries'][ $id ] ?? null;
		}
	}
}
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-escalation.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-chat-attribution.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-goals.php';

/**
 * @covers DEF_Core_Goals
 */
final class GoalsTest extends TestCase {

	const REF = 'orch-0123456789abcdef';

	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		// Fluent Forms' submission status, read straight from its table.
		$this->wpdb       = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class() {
			public $prefix = 'wp_';
			public function prepare( $query, ...$args ) {
				return array( $query, $args );
			}
			public function get_var( $prepared ) {
				return $GLOBALS['_wp_test_fluent_status'][ $prepared[1][0] ] ?? 'unread';
			}
		};
		_wp_test_reset_options();
		update_option( 'def_core_api_key', 'tenant-key' ); // Connected.
		$GLOBALS['_wp_test_cron_scheduled'] = array();
		$GLOBALS['_wp_test_transients']     = array();
		$GLOBALS['_wp_test_posts']          = array();
		$GLOBALS['_wp_test_cron_array']     = array();
		$_COOKIE                            = array( DEF_Core_Goals::COOKIE => self::REF );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb;
		parent::tearDown();
	}

	/** @return array<int, array<string, string>> The queued payloads. */
	private function queued(): array {
		return array_map(
			static function ( $e ) {
				return $e['args'][0];
			},
			$GLOBALS['_wp_test_cron_scheduled']
		);
	}

	private function assert_wire_shape( array $p ): void {
		$this->assertSame( array( 'type', 'ref', 'chat_ref', 'occurred_at', 'idempotency_key' ), array_keys( $p ) );
		$this->assertSame( self::REF, $p['chat_ref'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $p['idempotency_key'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $p['occurred_at'] );
	}

	// ── Form leg ────────────────────────────────────────────────────────

	public function test_a_form_reports_plugin_and_id_and_none_of_its_fields(): void {
		$entry = array( 'id' => 77, 'status' => 'active', '1' => 'ann@example.com', '2' => 'Ann Smith' );
		DEF_Core_Goals::on_gravityforms( $entry, array( 'id' => 3, 'title' => 'Opportunity Review' ) );

		$q = $this->queued();
		$this->assertCount( 1, $q );
		$this->assert_wire_shape( $q[0] );
		$this->assertSame( 'form_submitted', $q[0]['type'] );
		$this->assertSame( 'gravityforms:3', $q[0]['ref'] );
		$this->assertSame( wp_hash( 'form:gravityforms:3:' . self::REF ), $q[0]['idempotency_key'] );
		$json = wp_json_encode( $q[0] );
		foreach ( array( 'ann@example.com', 'Ann Smith', 'Opportunity Review', '77' ) as $pii ) {
			$this->assertStringNotContainsString( $pii, $json );
		}
	}

	public function test_each_plugin_reports_its_own_ref(): void {
		$cf7 = new class() {
			public function id() {
				return 12;
			}
		};
		$elementor = new class() {
			public function get_form_settings( $key ) {
				return 'id' === $key ? 'a1b2c3d' : 'Contact';
			}
		};
		DEF_Core_Goals::on_contact_form_7( $cf7 );
		DEF_Core_Goals::on_wpforms( array(), array(), array( 'id' => 5 ) );
		DEF_Core_Goals::on_fluentform( 9, array( 'email' => 'x@y.z' ), (object) array( 'id' => 4 ) );
		$GLOBALS['_wp_test_frm_entries'][30] = (object) array( 'is_draft' => 0 );
		DEF_Core_Goals::on_formidable( 30, 6, array( 'is_child' => false ) );
		DEF_Core_Goals::on_ninja_forms( array( 'form_id' => '2' ) );
		DEF_Core_Goals::on_elementor( $elementor );

		$this->assertSame(
			array( 'contact-form-7:12', 'wpforms:5', 'fluentform:4', 'formidable:6', 'ninja-forms:2', 'elementor:a1b2c3d' ),
			array_column( $this->queued(), 'ref' )
		);
	}

	public function test_gravity_forms_spam_is_not_reported(): void {
		DEF_Core_Goals::on_gravityforms( array( 'status' => 'spam' ), array( 'id' => 3 ) );
		$this->assertSame( array(), $this->queued() );
	}

	public function test_other_spam_drafts_previews_and_child_entries_are_not_reported(): void {
		$GLOBALS['_wp_test_fluent_status'][40] = 'spam';
		DEF_Core_Goals::on_fluentform( 40, array(), (object) array( 'id' => 4 ) );
		$GLOBALS['_wp_test_frm_entries'][31] = (object) array( 'is_draft' => 1 );
		$GLOBALS['_wp_test_frm_entries'][32] = (object) array( 'is_draft' => 4 );
		$GLOBALS['_wp_test_frm_entries'][33] = (object) array( 'is_draft' => 0 );
		DEF_Core_Goals::on_formidable( 31, 6, array() );
		DEF_Core_Goals::on_formidable( 32, 6, array() );
		DEF_Core_Goals::on_formidable( 33, 6, array( 'is_child' => true ) );
		DEF_Core_Goals::on_ninja_forms( array( 'form_id' => 2, 'settings' => array( 'is_preview' => true ) ) );
		$this->assertSame( array(), $this->queued() );
	}

	public function test_fluent_forms_both_hooks_report_a_submission_once(): void {
		DEF_Core_Goals::on_fluentform( 41, array(), (object) array( 'id' => 4 ) );
		DEF_Core_Goals::on_fluentform( 41, array(), (object) array( 'id' => 4 ) );
		$this->assertCount( 1, $this->queued() );
	}

	public function test_no_chat_no_report(): void {
		$_COOKIE = array();
		DEF_Core_Goals::on_gravityforms( array(), array( 'id' => 3 ) );
		$_COOKIE = array( DEF_Core_Goals::COOKIE => 'orch-NOTHEX0123456789' );
		DEF_Core_Goals::on_gravityforms( array(), array( 'id' => 3 ) );
		$this->assertSame( array(), $this->queued() );
	}

	public function test_not_connected_queues_and_sends_nothing(): void {
		_wp_test_reset_options();
		DEF_Core_Goals::on_gravityforms( array(), array( 'id' => 3 ) );
		DEF_Core_Goals::send( array( 'type' => 'form_submitted' ) );
		$this->assertSame( array(), $this->queued() );
		$this->assertSame( array(), $GLOBALS['_wp_test_posts'] );
	}

	// ── Page leg ────────────────────────────────────────────────────────

	public function test_a_page_reports_its_path_without_query_or_fragment(): void {
		$request = new WP_REST_Request( 'POST', '/a3-ai/v1/goals/page' );
		$request->set_param( 'path', '/thank-you/?email=ann%40example.com&name=Ann#done' );
		$response = DEF_Core_Goals::rest_page( $request );

		$this->assertSame( 204, $response->get_status() );
		$q = $this->queued();
		$this->assertCount( 1, $q );
		$this->assert_wire_shape( $q[0] );
		$this->assertSame( 'page_reached', $q[0]['type'] );
		$this->assertSame( '/thank-you/', $q[0]['ref'] );
		$this->assertSame( wp_hash( 'page:' . self::REF . ':/thank-you/' ), $q[0]['idempotency_key'] );
	}

	private function page( string $path ): void {
		$request = new WP_REST_Request( 'POST', '/a3-ai/v1/goals/page' );
		$request->set_param( 'path', $path );
		DEF_Core_Goals::rest_page( $request );
	}

	public function test_an_over_long_path_is_not_queued(): void {
		$this->page( '/' . str_repeat( 'a', DEF_Core_Goals::PAGE_PATH_MAX ) );
		$this->assertSame( array(), $this->queued() );
		$this->page( '/' . str_repeat( 'a', DEF_Core_Goals::PAGE_PATH_MAX - 1 ) );
		$this->assertCount( 1, $this->queued() );
	}

	public function test_no_page_report_is_queued_at_the_pending_ceiling(): void {
		$GLOBALS['_wp_test_cron_array'] = array(
			time() => array( DEF_Core_Goals::CRON_HOOK => array_fill( 0, DEF_Core_Goals::MAX_PENDING, array() ) ),
		);
		$this->page( '/pricing/' );
		$this->assertSame( array(), $this->queued() );
	}

	public function test_an_order_number_path_is_not_queued(): void {
		update_option( 'woocommerce_checkout_pay_endpoint', 'pay-now' );
		$this->page( '/checkout/order-received/1234/' );
		$this->page( '/my-account/view-order/1234/' );
		$this->page( '/checkout/pay-now/1234/' );
		$this->assertSame( array(), $this->queued() );
		$this->page( '/checkout/order-pay/1234/' ); // Not this site's slug.
		$this->assertCount( 1, $this->queued() );
	}

	public function test_a_page_report_past_the_ip_limit_is_refused(): void {
		$request = new WP_REST_Request( 'POST', '/a3-ai/v1/goals/page' );
		$request->set_param( 'path', '/pricing/' );
		for ( $i = 0; $i < DEF_Core_Goals::PAGE_RATE_LIMIT; $i++ ) {
			DEF_Core_Goals::rest_page( $request );
		}
		$this->assertSame( 429, DEF_Core_Goals::rest_page( $request )->get_status() );
		$this->assertCount( DEF_Core_Goals::PAGE_RATE_LIMIT, $this->queued() );
	}

	// ── Order + hand-off legs ───────────────────────────────────────────

	public function test_an_order_reports_a_salted_key_never_its_number(): void {
		DEF_Core_Goals::report_order( new WC_Order( 987654, array( '_def_chat_id' => self::REF ) ) );

		$q = $this->queued();
		$this->assertCount( 1, $q );
		$this->assert_wire_shape( $q[0] );
		$this->assertSame( 'order_placed', $q[0]['type'] );
		$this->assertSame( '', $q[0]['ref'] );
		$this->assertSame( wp_hash( 'order:987654' ), $q[0]['idempotency_key'] );
		$this->assertStringNotContainsString( '987654', wp_json_encode( $q[0] ) );
	}

	public function test_an_order_without_a_chat_is_not_reported(): void {
		DEF_Core_Goals::report_order( new WC_Order( 5, array() ) );
		$this->assertSame( array(), $this->queued() );
	}

	public function test_the_order_stamp_takes_the_cookie_and_the_order_is_reported_with_it(): void {
		$order = new WC_Order( 55, array() );
		DEF_Core_Chat_Attribution::stamp_order( $order );
		DEF_Core_Goals::report_order( $order );

		$q = $this->queued();
		$this->assertCount( 1, $q );
		$this->assertSame( 'order_placed', $q[0]['type'] );
		$this->assertSame( self::REF, $q[0]['chat_ref'] );
	}

	// ── Send ────────────────────────────────────────────────────────────

	public function test_send_posts_the_payload_with_the_tenant_key(): void {
		$payload = array( 'type' => 'page_reached', 'ref' => '/x/', 'chat_ref' => self::REF );
		DEF_Core_Goals::send( $payload );

		$this->assertCount( 1, $GLOBALS['_wp_test_posts'] );
		$post = $GLOBALS['_wp_test_posts'][0];
		$this->assertSame( 'https://def.test/api/wp-sync/goals/outcome', $post['url'] );
		$this->assertSame( 'tenant-key', $post['args']['headers']['X-DEF-API-Key'] );
		$this->assertSame( $payload, json_decode( $post['args']['body'], true ) );
	}
}
