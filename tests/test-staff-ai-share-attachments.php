<?php
/**
 * Staff-AI share attachments tests (doclib slice 5).
 *
 * Pins the behaviour contract of rest_share_send() + document_ids:
 * - id validation (charset, count cap) refuses before any backend call
 * - unknown id → plain 404, nothing sent
 * - total size over the cap → polite 400, no file fetches
 * - happy path: files fetched via the BFF-auth'd download route, passed to
 *   wp_mail as real paths that EXIST at send time, and are deleted (with
 *   their temp dir) after the handler returns — success or failure
 * - fetch failure → 502, wp_mail never called, temp state cleaned
 * - the escalation route never sees document_ids or any attachment field
 *   (paths travel as a PHP argument only — never client-suppliable)
 * - download_url filename segment is normalized (encoded or raw both work)
 *
 * Runs standalone (no WordPress bootstrap), same harness pattern as
 * test-staff-ai-download-auth.php.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Controllable state ───────────────────────────────────────────────────

global $_wp_test_current_user, $_wp_test_user_caps;
global $_wp_test_remote_responses, $_wp_test_remote_calls, $_wp_test_mail_calls;
$_wp_test_current_user     = null;
$_wp_test_user_caps        = array();
$_wp_test_remote_responses = array();
$_wp_test_remote_calls     = array();
$_wp_test_mail_calls       = array();

// ── Stubs ────────────────────────────────────────────────────────────────

if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1048576 );
}
if ( ! defined( 'DEF_CORE_API_NAME_SPACE' ) ) {
	define( 'DEF_CORE_API_NAME_SPACE', 'a3-ai/v1' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID = 0;
		public $user_email = 'sharer@example.com';
		public $display_name = 'Sharer';
		public $roles = array( 'editor' );
		public function __construct( int $id = 0 ) { $this->ID = $id; }
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): WP_User {
		global $_wp_test_current_user;
		return $_wp_test_current_user ?? new WP_User( 0 );
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		global $_wp_test_current_user;
		return $_wp_test_current_user !== null && $_wp_test_current_user->ID > 0;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool { return true; }
}
if ( ! function_exists( '__' ) ) {
	function __( string $t, string $d = 'default' ): string { return $t; }
}
foreach ( array( 'add_action', 'add_filter', 'add_rewrite_rule', 'register_rest_route' ) as $noop ) {
	if ( ! function_exists( $noop ) ) {
		eval( "function $noop( ...\$a ): void {}" );
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $k = '' ): string { return 'Test Site'; }
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $s ): string { return rtrim( $s, '/\\' ) . '/'; }
}
if ( ! function_exists( 'get_temp_dir' ) ) {
	function get_temp_dir(): string { return sys_get_temp_dir(); }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string { return 'https://site.example' . $path; }
}
// Staff/management roster for escalation's allowed_recipients derivation —
// must contain the test recipient or the staff_ai allowlist 400s the share.
if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ): array {
		$colleague             = new WP_User( 9 );
		$colleague->user_email = 'colleague@example.com';
		$colleague->display_name = 'Colleague';
		return array( $colleague );
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		global $_wp_test_remote_calls, $_wp_test_remote_responses;
		$_wp_test_remote_calls[] = array( 'url' => $url, 'args' => $args );
		if ( ! empty( $_wp_test_remote_responses ) ) {
			return array_shift( $_wp_test_remote_responses );
		}
		return new WP_Error( 'http_request_failed', 'Connection timed out' );
	}
}
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( string $url, array $args = array() ) {
		return wp_remote_get( $url, $args );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

// wp_mail capture — snapshots whether each attachment EXISTED at send time,
// because the handler must clean them up after (so a later file_exists lies).
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $body, $headers = array(), $attachments = array() ): bool {
		global $_wp_test_mail_calls;
		$existed = array();
		foreach ( (array) $attachments as $path ) {
			$existed[ $path ] = file_exists( $path );
		}
		$_wp_test_mail_calls[] = array(
			'to'          => $to,
			'subject'     => $subject,
			'body'        => $body,
			'headers'     => $headers,
			'attachments' => (array) $attachments,
			'existed'     => $existed,
		);
		return true;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $body = '';
		private $params = array();
		public function __construct( string $method = 'GET', string $route = '' ) {}
		public function set_header( string $k, string $v ): void {}
		public function set_body( string $body ): void { $this->body = $body; }
		public function set_param( string $k, $v ): void { $this->params[ $k ] = $v; }
		public function get_param( string $k ) { return $this->params[ $k ] ?? null; }
		public function get_json_params() {
			$decoded = json_decode( $this->body, true );
			return is_array( $decoded ) ? $decoded : array();
		}
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
	}
}

if ( ! class_exists( 'DEF_Core' ) ) {
	class DEF_Core {
		public static function get_def_api_url_internal(): ?string {
			$url = get_option( 'def_core_staff_ai_api_url', '' );
			return ! empty( $url ) ? rtrim( $url, '/' ) : null;
		}
	}
}
if ( ! class_exists( 'DEF_Core_Tools' ) ) {
	class DEF_Core_Tools {
		public static function get_user_def_capabilities( $user ): array {
			global $_wp_test_user_caps;
			return $_wp_test_user_caps;
		}
	}
}
if ( ! class_exists( 'DEF_Core_Encryption' ) ) {
	class DEF_Core_Encryption {
		public static function get_secret( string $key ) {
			return get_option( $key, '' );
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-def-core-staff-ai.php';
require_once dirname( __DIR__ ) . '/includes/class-def-core-escalation.php';

update_option( 'def_core_staff_ai_api_url', 'http://backend:8000' );
update_option( 'def_core_api_key', 'test-api-key-share' );
update_option( 'admin_email', 'admin@example.com' );

// ── Helpers ──────────────────────────────────────────────────────────────

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

function reset_state(): void {
	global $_wp_test_current_user, $_wp_test_user_caps;
	global $_wp_test_remote_responses, $_wp_test_remote_calls, $_wp_test_mail_calls;
	$_wp_test_current_user     = new WP_User( 7 );
	$_wp_test_user_caps        = array( 'staff_knowledge_assistant' );
	$_wp_test_remote_responses = array();
	$_wp_test_remote_calls     = array();
	$_wp_test_mail_calls       = array();
}

function share_request( array $body ): WP_REST_Request {
	$request = new WP_REST_Request( 'POST', '/a3-ai/v1/staff-ai/share-send' );
	$request->set_body( (string) wp_json_encode( $body ) );
	return $request;
}

function documents_list_response( array $documents ) {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => (string) wp_json_encode( array( 'success' => true, 'documents' => $documents ) ),
	);
}

function file_response( string $bytes ) {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => $bytes,
	);
}

$doc_report = array(
	'document_id'  => 'doc-report-1',
	'title'        => 'Q3 Report',
	'size_bytes'   => 11,
	'download_url' => '/api/files/tenant-a/My%20Report.md', // pre-encoded (post-#836 DEF)
);
$doc_plan = array(
	'document_id'  => 'doc-plan-2',
	'title'        => 'Plan B',
	'size_bytes'   => 9,
	'download_url' => '/api/files/tenant-a/Plan B.xlsx', // raw (pre-#836 DEF)
);

$base_share_body = array(
	'to'      => array( 'colleague@example.com' ),
	'subject' => 'Q3 numbers',
	'body'    => 'Here are the documents we discussed.',
);

// ── Tests ────────────────────────────────────────────────────────────────

echo "Invalid document id refuses before any backend call\n";
reset_state();
$resp = DEF_Core_Staff_AI::rest_share_send( share_request( $base_share_body + array( 'document_ids' => array( '../etc/passwd' ) ) ) );
check( 400 === $resp->get_status(), 'status 400' );
check( 0 === count( $GLOBALS['_wp_test_remote_calls'] ), 'no backend call made' );
check( 0 === count( $GLOBALS['_wp_test_mail_calls'] ), 'no mail sent' );

echo "Too many ids refuses before any backend call\n";
reset_state();
$ids = array();
for ( $i = 0; $i < 11; $i++ ) {
	$ids[] = 'doc-' . $i;
}
$resp = DEF_Core_Staff_AI::rest_share_send( share_request( $base_share_body + array( 'document_ids' => $ids ) ) );
check( 400 === $resp->get_status(), 'status 400' );
check( 0 === count( $GLOBALS['_wp_test_remote_calls'] ), 'no backend call made' );

echo "Unknown id is a plain 404, nothing sent\n";
reset_state();
$GLOBALS['_wp_test_remote_responses'][] = documents_list_response( array( $doc_report ) );
$resp = DEF_Core_Staff_AI::rest_share_send( share_request( $base_share_body + array( 'document_ids' => array( 'doc-not-mine' ) ) ) );
check( 404 === $resp->get_status(), 'status 404' );
check( 'Document not found.' === ( $resp->get_data()['message'] ?? '' ), 'plain copy, no oracle' );
check( 1 === count( $GLOBALS['_wp_test_remote_calls'] ), 'only the list was fetched' );
check( 0 === count( $GLOBALS['_wp_test_mail_calls'] ), 'no mail sent' );

echo "Over the total size cap refuses politely, no file fetches\n";
reset_state();
$big = $doc_report;
$big['size_bytes'] = DEF_Core_Staff_AI::SHARE_MAX_ATTACHMENT_BYTES + 1;
$GLOBALS['_wp_test_remote_responses'][] = documents_list_response( array( $big ) );
$resp = DEF_Core_Staff_AI::rest_share_send( share_request( $base_share_body + array( 'document_ids' => array( 'doc-report-1' ) ) ) );
check( 400 === $resp->get_status(), 'status 400' );
check( false !== strpos( (string) ( $resp->get_data()['message'] ?? '' ), 'Nothing was sent' ), 'polite refusal copy' );
check( 1 === count( $GLOBALS['_wp_test_remote_calls'] ), 'no file fetch after the list' );
check( 0 === count( $GLOBALS['_wp_test_mail_calls'] ), 'no mail sent' );

echo "Happy path: two documents fetched, attached, sent, cleaned up\n";
reset_state();
$GLOBALS['_wp_test_remote_responses'][] = documents_list_response( array( $doc_report, $doc_plan ) );
$GLOBALS['_wp_test_remote_responses'][] = file_response( '# Q3 body' );
$GLOBALS['_wp_test_remote_responses'][] = file_response( 'plan-bytes' );
$resp = DEF_Core_Staff_AI::rest_share_send( share_request( $base_share_body + array( 'document_ids' => array( 'doc-report-1', 'doc-plan-2' ) ) ) );
check( 200 === $resp->get_status(), 'status 200' );
check( 'sent' === ( $resp->get_data()['status'] ?? '' ), 'reported sent' );
check( 1 === count( $GLOBALS['_wp_test_mail_calls'] ), 'exactly one mail' );
$mail = $GLOBALS['_wp_test_mail_calls'][0] ?? array( 'attachments' => array(), 'existed' => array(), 'headers' => array(), 'body' => '' );
check( 2 === count( $mail['attachments'] ), 'two attachments passed to wp_mail' );
check( ! in_array( false, $mail['existed'], true ) && 2 === count( $mail['existed'] ), 'attachment files existed at send time' );
$names = array_map( 'basename', $mail['attachments'] );
sort( $names );
// Exact sanitizer output varies (real WP dashes spaces; the stub strips them)
// — the contract is: derived from the TITLE, carrying the stored extension.
check( 2 === count( $names )
	&& 1 === preg_match( '/^Plan.?B\.xlsx$/', $names[0] )
	&& 1 === preg_match( '/^Q3.?Report\.md$/', $names[1] ),
	'recipient-friendly names (title + real extension), got: ' . implode( ', ', $names ) );
$gone = true;
foreach ( $mail['attachments'] as $path ) {
	if ( file_exists( $path ) || is_dir( dirname( $path ) ) ) {
		$gone = false;
	}
}
check( $gone, 'temp files and directory removed after send' );

echo "Both download_url forms fetch single-encoded (normalize-then-encode)\n";
$file_urls = array_slice( array_column( $GLOBALS['_wp_test_remote_calls'], 'url' ), 1 );
check( in_array( 'http://backend:8000/api/files/tenant-a/My%20Report.md', $file_urls, true ), 'pre-encoded emission fetched single-encoded' );
check( in_array( 'http://backend:8000/api/files/tenant-a/Plan%20B.xlsx', $file_urls, true ), 'raw emission fetched single-encoded' );

echo "Escalation route never sees document_ids or attachment fields\n";
check( false === strpos( (string) $mail['body'], 'doc-report-1' ), 'mail body carries no ids' );
$header_blob = implode( '|', (array) $mail['headers'] );
check( false === stripos( $header_blob, 'attach' ) && false === stripos( $header_blob, 'document' ), 'no attachment/document headers' );

echo "Injected escalation fields are still stripped alongside document_ids\n";
reset_state();
$GLOBALS['_wp_test_remote_responses'][] = documents_list_response( array( $doc_report ) );
$GLOBALS['_wp_test_remote_responses'][] = file_response( 'bytes' );
$resp = DEF_Core_Staff_AI::rest_share_send( share_request( $base_share_body + array(
	'document_ids' => array( 'doc-report-1' ),
	'bcc'          => array( 'exfil@evil.example' ),
	'send_copy_to_user' => true,
	'user_copy_email'   => 'exfil@evil.example',
) ) );
check( 200 === $resp->get_status(), 'share still sends' );
check( 1 === count( $GLOBALS['_wp_test_mail_calls'] ), 'no user-copy mail (field stripped)' );
$header_blob = implode( '|', (array) ( $GLOBALS['_wp_test_mail_calls'][0]['headers'] ?? array() ) );
check( false === stripos( $header_blob, 'evil.example' ), 'no injected Bcc header' );

echo "Same-title documents attach with DISTINCT names (dedupe loop)\n";
reset_state();
$report_a = array(
	'document_id'  => 'doc-same-a',
	'title'        => 'Report',
	'size_bytes'   => 6,
	'download_url' => '/api/files/tenant-a/20260805_a_Report.md',
);
$report_b = array(
	'document_id'  => 'doc-same-b',
	'title'        => 'Report',
	'size_bytes'   => 6,
	'download_url' => '/api/files/tenant-a/20260805_b_Report.md',
);
$GLOBALS['_wp_test_remote_responses'][] = documents_list_response( array( $report_a, $report_b ) );
$GLOBALS['_wp_test_remote_responses'][] = file_response( 'body-a' );
$GLOBALS['_wp_test_remote_responses'][] = file_response( 'body-b' );
$resp = DEF_Core_Staff_AI::rest_share_send( share_request( $base_share_body + array( 'document_ids' => array( 'doc-same-a', 'doc-same-b' ) ) ) );
check( 200 === $resp->get_status(), 'status 200' );
$mail = $GLOBALS['_wp_test_mail_calls'][0] ?? array( 'attachments' => array(), 'existed' => array() );
check( 2 === count( $mail['attachments'] ), 'two attachments' );
$names = array_map( 'basename', $mail['attachments'] );
check( 2 === count( array_unique( $names ) ), 'distinct basenames (no silent overwrite), got: ' . implode( ', ', $names ) );
check( ! in_array( false, $mail['existed'], true ), 'both files existed at send time' );

echo "A duplicated id counts once against the cap and attaches once\n";
reset_state();
$half_cap = $doc_report;
$half_cap['size_bytes'] = (int) ( DEF_Core_Staff_AI::SHARE_MAX_ATTACHMENT_BYTES / 2 ) + 100;
$GLOBALS['_wp_test_remote_responses'][] = documents_list_response( array( $half_cap ) );
$GLOBALS['_wp_test_remote_responses'][] = file_response( 'once' );
$resp = DEF_Core_Staff_AI::rest_share_send( share_request( $base_share_body + array( 'document_ids' => array( 'doc-report-1', 'doc-report-1' ) ) ) );
check( 200 === $resp->get_status(), 'not double-counted into a false 400' );
check( 1 === count( $GLOBALS['_wp_test_mail_calls'][0]['attachments'] ?? array() ), 'attached exactly once' );

echo "A failed file fetch refuses the whole share and cleans up\n";
reset_state();
$GLOBALS['_wp_test_remote_responses'][] = documents_list_response( array( $doc_report, $doc_plan ) );
$GLOBALS['_wp_test_remote_responses'][] = file_response( 'first-doc-bytes' );
$GLOBALS['_wp_test_remote_responses'][] = array( 'response' => array( 'code' => 404 ), 'body' => 'nope' );
$resp = DEF_Core_Staff_AI::rest_share_send( share_request( $base_share_body + array( 'document_ids' => array( 'doc-report-1', 'doc-plan-2' ) ) ) );
check( 502 === $resp->get_status(), 'status 502' );
check( false !== strpos( (string) ( $resp->get_data()['message'] ?? '' ), 'Nothing was sent' ), 'polite refusal copy' );
check( 0 === count( $GLOBALS['_wp_test_mail_calls'] ), 'wp_mail never called' );

echo "Share without document_ids is untouched (regression)\n";
reset_state();
$resp = DEF_Core_Staff_AI::rest_share_send( share_request( $base_share_body ) );
check( 200 === $resp->get_status(), 'status 200' );
check( 0 === count( $GLOBALS['_wp_test_remote_calls'] ), 'no backend calls at all' );
check( 1 === count( $GLOBALS['_wp_test_mail_calls'] ), 'mail sent' );
check( array() === $GLOBALS['_wp_test_mail_calls'][0]['attachments'], 'no attachments' );

// ── Result ───────────────────────────────────────────────────────────────

echo "\n";
echo $fail === 0 ? "ALL {$pass} CHECKS PASSED\n" : "{$fail} CHECK(S) FAILED ({$pass} passed)\n";
exit( $fail === 0 ? 0 : 1 );
