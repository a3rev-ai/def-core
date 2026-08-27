<?php
/**
 * Staff-AI roster push tests (Usage & Budgets D-U10).
 *
 * Verifies:
 * - The payload builder: ticks → rows, Management wins over Staff, subs are
 *   STRINGS, display_name optional, DEF-Admin alone is not a seat
 * - No email ever appears anywhere in the payload (data minimization)
 * - The builder reads STORED capabilities, not has_cap() — which the
 *   map_meta_cap filter makes answer true for def_staff_access on every
 *   Management/Admin user
 * - The push fires on User Access save, on the × remove, and on upgrade
 * - A failing push never breaks the save, and logs the response code
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'DEF_CORE_PLUGIN_DIR' ) ) {
	define( 'DEF_CORE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/wp-stubs.php';

// ── Test state ──────────────────────────────────────────────────────────

global $_test_users, $_test_cron, $_test_http, $_test_logs;
$_test_users = array();   // id => array(display_name, user_email, caps[])
$_test_cron  = array();   // scheduled hook names
$_test_http  = array();   // captured wp_remote_post calls
$_test_logs  = array();   // captured logger calls

/**
 * Seed one user. `caps` are the STORED capabilities.
 */
function seed_user( int $id, string $name, string $email, array $caps ): void {
	global $_test_users;
	$_test_users[ $id ] = array(
		'display_name' => $name,
		'user_email'   => $email,
		'caps'         => $caps,
	);
}

// ── WordPress stubs ─────────────────────────────────────────────────────

class WP_User_Stub {
	public $ID;
	public $display_name;
	public $user_email;
	public $caps;

	public function __construct( int $id, array $row ) {
		$this->ID           = $id;
		$this->display_name = $row['display_name'];
		$this->user_email   = $row['user_email'];
		$this->caps         = $row['caps'];
	}

	/**
	 * Deliberately LIES the way production does: DEF_Core_Admin::map_def_capabilities()
	 * is filtered onto map_meta_cap and answers true for def_staff_access on any
	 * Management or DEF-Admin user. A builder that reads has_cap() would put a
	 * DEF-Admin-only user on the roster as "staff"; these tests would catch it.
	 */
	public function has_cap( string $cap ): bool {
		if ( 'def_staff_access' === $cap
			&& ( in_array( 'def_management_access', $this->caps, true )
				|| in_array( 'def_admin_access', $this->caps, true ) ) ) {
			return true;
		}
		return in_array( $cap, $this->caps, true );
	}

	public function add_cap( string $cap ): void {
		if ( ! in_array( $cap, $this->caps, true ) ) {
			$this->caps[] = $cap;
		}
		$this->persist();
	}

	public function remove_cap( string $cap ): void {
		$this->caps = array_values( array_diff( $this->caps, array( $cap ) ) );
		$this->persist();
	}

	/**
	 * Write through, so a later get_userdata() sees the change the way the
	 * database would — otherwise the save handler's mutations vanish and the
	 * roster built afterwards would be the OLD one.
	 */
	private function persist(): void {
		global $_test_users;
		$_test_users[ $this->ID ]['caps'] = $this->caps;
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ) {
		global $_test_users;
		$cap = $args['capability'] ?? '';
		$ids = array();
		foreach ( $_test_users as $id => $row ) {
			// The capability query hits STORED meta — unaffected by map_meta_cap.
			if ( in_array( $cap, $row['caps'], true ) ) {
				$ids[] = $id;
			}
		}
		if ( ! empty( $args['exclude'] ) ) {
			$ids = array_values( array_diff( $ids, array_map( 'intval', $args['exclude'] ) ) );
		}
		return $ids;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $id ) {
		global $_test_users;
		return isset( $_test_users[ $id ] ) ? new WP_User_Stub( $id, $_test_users[ $id ] ) : false;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook ) {
		global $_test_cron;
		return in_array( $hook, $_test_cron, true ) ? time() + 30 : false;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $when, string $hook ) {
		global $_test_cron;
		if ( ! empty( $GLOBALS['_test_cron_fails'] ) ) {
			return false; // What WordPress does when the event cannot be queued.
		}
		$_test_cron[] = $hook;
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
		return true;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		global $_test_http;
		$_test_http[] = array( 'url' => $url, 'args' => $args );
		if ( isset( $GLOBALS['_test_http_error'] ) && $GLOBALS['_test_http_error'] ) {
			return new WP_Error( 'http_request_failed', 'Connection refused' );
		}
		return array( 'response' => array( 'code' => $GLOBALS['_test_http_code'] ?? 200 ) );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
	}
}

// The real DEF_Core_Encryption is loaded by wp-stubs.php; the key is set the
// way production sets it, through the option it reads.
update_option( 'def_core_api_key', 'test-api-key' );

class DEF_Core_Logger {
	const SOURCE_SYNC = 'sync';
	public static function warning( string $source, string $message, array $context = array() ): void {
		global $_test_logs;
		$_test_logs[] = array( 'level' => 'warning', 'message' => $message, 'context' => $context );
	}
	public static function info( string $source, string $message, array $context = array() ): void {
		global $_test_logs;
		$_test_logs[] = array( 'level' => 'info', 'message' => $message, 'context' => $context );
	}
}

// class-def-core.php ends in DEF_Core::instance(), which boots the entire
// plugin — so it cannot be loaded here. The upgrade backfill is asserted
// against its source instead (section 6), the way this suite already checks
// code it cannot execute standalone (see test-stream-failure-paths.php).
class DEF_Core {
	public static function get_def_api_url_internal(): string {
		return 'https://def-api.test';
	}
}

if ( ! class_exists( 'DEF_Core_Tools' ) ) {
	class DEF_Core_Tools {
		public static function get_role_caps(): array {
			return array();
		}
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action = '' ) {
		return 1;
	}
}

/**
 * The ajax handlers end in wp_send_json_*, which in WordPress halts the
 * request. Throwing models that halt so the test can assert what the admin
 * would have received.
 */
class AjaxHalt extends Exception {
	public $payload;
	public $ok;
	public function __construct( $payload, bool $ok ) {
		parent::__construct( 'halt' );
		$this->payload = $payload;
		$this->ok      = $ok;
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, int $status = 200 ) {
		throw new AjaxHalt( $data, true );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, int $status = 400 ) {
		throw new AjaxHalt( $data, false );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-staff-roster.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-admin.php';

// ── Test helpers ────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function assert_true( $value, string $label ): void {
	global $pass, $fail;
	if ( $value ) {
		$pass++;
		echo "  ok: $label\n";
	} else {
		$fail++;
		echo "  FAIL: $label\n";
	}
}

function assert_equals( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
		echo "  ok: $label\n";
	} else {
		$fail++;
		echo "  FAIL: $label (expected " . var_export( $expected, true )
			. ", got " . var_export( $actual, true ) . ")\n";
	}
}

/**
 * Find a roster row by its sub.
 */
function row_for( array $roster, string $sub ) {
	foreach ( $roster as $row ) {
		if ( ( $row['sub'] ?? '' ) === $sub ) {
			return $row;
		}
	}
	return null;
}

/**
 * Recursively collect every array key in a structure.
 */
function all_keys( array $data ): array {
	$keys = array();
	foreach ( $data as $key => $value ) {
		if ( is_string( $key ) ) {
			$keys[] = $key;
		}
		if ( is_array( $value ) ) {
			$keys = array_merge( $keys, all_keys( $value ) );
		}
	}
	return $keys;
}

echo "=== Staff Roster Tests (D-U10) ===\n";

// ── 1. The payload builder ──────────────────────────────────────────────
echo "\n[1] Roster payload builder\n";

seed_user( 11, 'Staff Sally', 'sally@example.com', array( 'def_staff_access' ) );
seed_user( 22, 'Manager Mo', 'mo@example.com', array( 'def_management_access' ) );
seed_user( 33, 'Both Bob', 'bob@example.com', array( 'def_staff_access', 'def_management_access' ) );
seed_user( 44, 'Admin Ada', 'ada@example.com', array( 'def_admin_access' ) );
seed_user( 55, '', 'nameless@example.com', array( 'def_staff_access' ) );
seed_user( 66, 'Outsider Olive', 'olive@example.com', array() );

$roster = DEF_Core_Staff_Roster::build_roster();

assert_equals( 4, count( $roster ), 'only Staff/Management ticks make the roster' );
assert_equals( 'staff', row_for( $roster, '11' )['access_level'] ?? '', 'a Staff tick maps to staff' );
assert_equals( 'management', row_for( $roster, '22' )['access_level'] ?? '', 'a Management tick maps to management' );
assert_equals( 'management', row_for( $roster, '33' )['access_level'] ?? '', 'Management WINS when a user holds both' );
assert_true( null === row_for( $roster, '44' ), 'DEF-Admin alone is an access grant, not a Staff-AI seat' );
assert_true( null === row_for( $roster, '66' ), 'a user with no DEF tick is absent' );

// The map_meta_cap trap: has_cap('def_staff_access') is true for Ada, yet she
// must not appear. This is the assertion that pins the stored-caps read.
assert_true(
	( new WP_User_Stub( 44, $GLOBALS['_test_users'][44] ) )->has_cap( 'def_staff_access' ),
	'precondition: has_cap() lies about def_staff_access for a DEF-Admin user'
);
assert_true( null === row_for( $roster, '44' ), 'the builder reads STORED caps, so the lie does not reach DEF' );

echo "\n[2] Wire shape\n";

assert_true( is_string( row_for( $roster, '11' )['sub'] ), 'sub is a STRING, not an int' );
assert_equals( 'Staff Sally', row_for( $roster, '11' )['display_name'] ?? '', 'display_name rides along' );
assert_true(
	! array_key_exists( 'display_name', row_for( $roster, '55' ) ),
	'an empty display_name is OMITTED, not sent as ""'
);

$keys = all_keys( array( 'users' => $roster ) );
assert_true( ! in_array( 'email', $keys, true ), 'no `email` key anywhere in the payload' );
assert_true( ! in_array( 'user_email', $keys, true ), 'no `user_email` key anywhere in the payload' );
assert_equals(
	array( 'access_level', 'display_name', 'sub' ),
	( function () use ( $roster ) {
		$k = array_unique( all_keys( $roster ) );
		sort( $k );
		return $k;
	} )(),
	'rows carry exactly sub / display_name / access_level'
);

// Nothing that looks like an address survives the build, whatever the key name.
$encoded = wp_json_encode( $roster );
assert_true( false === strpos( (string) $encoded, '@example.com' ), 'no email address appears in the encoded payload' );

// ── 3. Debounce ─────────────────────────────────────────────────────────
echo "\n[3] Debounce\n";

$_test_cron = array();
DEF_Core_Staff_Roster::schedule_push();
assert_equals( 1, count( $_test_cron ), 'a save queues one push' );
DEF_Core_Staff_Roster::schedule_push();
assert_equals( 1, count( $_test_cron ), 'a second save folds into the pending push (no storm)' );

// ── 4. The push ─────────────────────────────────────────────────────────
echo "\n[4] Push transport\n";

$_test_http = array();
$_test_logs = array();
DEF_Core_Staff_Roster::send_roster();

assert_equals( 1, count( $_test_http ), 'send_roster makes one call' );
assert_equals( 'https://def-api.test/api/staff-ai/roster', $_test_http[0]['url'], 'posts to the DEF roster endpoint' );
assert_equals(
	'test-api-key',
	$_test_http[0]['args']['headers']['X-DEF-API-Key'] ?? '',
	'authenticates with the per-tenant API key (tenant comes from the key, never the body)'
);
$sent = json_decode( $_test_http[0]['args']['body'], true );
assert_equals( 4, count( $sent['users'] ?? array() ), 'the FULL roster is sent (full desired state)' );
assert_true( ! isset( $sent['tenant_id'] ), 'no tenant id in the body — the key is the authority' );

// Not connected → nothing goes out.
$_test_http = array();
update_option( 'def_core_api_key', '' );
DEF_Core_Staff_Roster::send_roster();
assert_equals( 0, count( $_test_http ), 'no API key configured = no call' );
update_option( 'def_core_api_key', 'test-api-key' );

// ── 5. Failure handling ─────────────────────────────────────────────────
echo "\n[5] Failure is logged, never thrown\n";

$_test_logs                 = array();
$GLOBALS['_test_http_error'] = true;
DEF_Core_Staff_Roster::send_roster();
assert_equals( 'warning', $_test_logs[0]['level'] ?? '', 'a transport failure logs a warning' );
$GLOBALS['_test_http_error'] = false;

// DEF refuses >2000 users with 413 and a bad row with 422 — the code is the
// actionable half, so it must reach the log.
foreach ( array( 413, 422, 503 ) as $code ) {
	$_test_logs               = array();
	$GLOBALS['_test_http_code'] = $code;
	DEF_Core_Staff_Roster::send_roster();
	assert_equals( $code, $_test_logs[0]['context']['status'] ?? 0, "a $code refusal logs its response code" );
}
$GLOBALS['_test_http_code'] = 200;

// ── 6. The push fires where access actually changes ─────────────────────
echo "\n[6] Save + upgrade queue a push\n";

// User Access save.
$_test_cron       = array();
$_POST            = array(
	'nonce' => 'x',
	'roles' => array( 11 => array( 'def_staff_access' => '1', 'def_admin_access' => '1' ) ),
);
$saved            = null;
try {
	DEF_Core_Admin::ajax_save_user_roles();
} catch ( AjaxHalt $halt ) {
	$saved = $halt;
}
assert_true( $saved && $saved->ok, 'the User Access save succeeds' );
assert_equals( 1, count( $_test_cron ), 'saving the User Access grid queues a roster push' );

// The × "remove all DEF access" button.
$_test_cron = array();
$_POST      = array( 'nonce' => 'x', 'user_id' => 11 );
$removed    = null;
try {
	DEF_Core_Admin::ajax_remove_user_roles();
} catch ( AjaxHalt $halt ) {
	$removed = $halt;
}
assert_true( $removed && $removed->ok, 'the × remove succeeds' );
assert_equals( 1, count( $_test_cron ), 'revoking a user queues a roster push so the removal propagates' );

// Plugin upgrade backfill: an existing install populates without anyone
// opening User Access. maybe_upgrade() lives in class-def-core.php, which
// boots the plugin on load, so its wiring is asserted against the source.
$core_php = file_get_contents( DEF_CORE_PLUGIN_DIR . 'includes/class-def-core.php' );
assert_true(
	1 === preg_match(
		'/version_compare\( \$current, \'6\.11\.0\', \'<\' \) \)\s*\{.*?'
		. 'DEF_Core_Staff_Roster::schedule_push\(\);.*?'
		. 'update_option\( \'def_core_db_version\', \'6\.11\.0\' \);/s',
		(string) $core_php
	),
	'maybe_upgrade() queues the full-roster backfill once, then advances the marker'
);
// The marker must advance whether or not the push lands: gating the bump on
// delivery would re-push on every admin pageview forever.
assert_true(
	false === strpos( (string) $core_php, 'if ( DEF_Core_Staff_Roster::schedule_push() )' ),
	'the upgrade marker is not gated on push success'
);
assert_true(
	1 === preg_match( '/DEF_Core_Staff_Roster::init\(\);/', (string) $core_php ),
	'the roster cron worker is registered at boot'
);

echo "\n[7] A failing push never breaks the save\n";

// The structural guarantee: the save performs NO outbound I/O. Everything that
// could fail — DNS, TLS, a 413, a timeout — happens later in the cron worker,
// after the admin already has their answer. This is what makes "fire and log"
// true rather than merely intended.
$_test_cron = array();
$_test_http = array();
$_POST      = array(
	'nonce' => 'x',
	'roles' => array( 22 => array( 'def_management_access' => '1', 'def_admin_access' => '1' ) ),
);
$result     = null;
try {
	DEF_Core_Admin::ajax_save_user_roles();
} catch ( AjaxHalt $halt ) {
	$result = $halt;
}
assert_true( $result instanceof AjaxHalt && $result->ok, 'the save succeeds' );
assert_equals( 0, count( $_test_http ), 'the save makes NO outbound call — the push is deferred, never inline' );
assert_equals( 1, count( $_test_cron ), 'the save queued the push instead' );

// And when the queue itself refuses, the save is still unaffected.
$_test_cron               = array();
$GLOBALS['_test_cron_fails'] = true;
$_POST                    = array(
	'nonce' => 'x',
	'roles' => array( 22 => array( 'def_management_access' => '1', 'def_admin_access' => '1' ) ),
);
$result                   = null;
try {
	DEF_Core_Admin::ajax_save_user_roles();
} catch ( AjaxHalt $halt ) {
	$result = $halt;
}
assert_true( $result instanceof AjaxHalt && $result->ok, 'the save still succeeds when the push cannot be queued at all' );
$GLOBALS['_test_cron_fails'] = false;

// And the user's ticks were written either way — the save is not rolled back.
assert_true(
	in_array( 'def_management_access', $GLOBALS['_test_users'][22]['caps'], true ),
	'the capability change persisted regardless of the push'
);

echo "\n--- Staff Roster Tests: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
