<?php
/**
 * User Access save — the capability contract (S3).
 *
 * The screen was a checkbox grid with one column per vault role and is now one
 * row per person with the roles as chips. That is a change of presentation only:
 * what the browser submits, and what the server is willing to write, must be
 * exactly what it was. This file pins that contract so a later redesign of the
 * screen cannot quietly move it.
 *
 * Verifies:
 * - the Staff / Management access level round-trips, and is exactly one either
 *   way — choosing one removes the other
 * - a person who stores NEITHER level keeps neither, and a level can be cleared
 *   as well as switched. What makes that matter is on the SCREEN side, not here:
 *   a render that invents a level for such a row grants every DEF-Admin-only
 *   user a console seat (see DEF_Core_Staff_Roster: "DEF-Admin alone is NOT a
 *   roster row"). tests/browser/harness-user-access.js checks 5-8 are what catch
 *   that; this file proves only that the handler can persist the state at all —
 *   which it must, or there would be nothing for the screen to submit.
 * - selected catalog roles round-trip, added and removed
 * - DEF Admin round-trips
 * - a def_role_* capability the browser invents is NOT written: the writable set
 *   is built from the cached catalog server-side, never from the submitted keys
 * - the last-DEF-Admin lockout guard still refuses the save
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

// ── Test state ──────────────────────────────────────────────────────────

global $_test_users, $_test_cron;
$_test_users = array();   // id => array(display_name, caps[])
$_test_cron  = array();

/**
 * The tenant's role catalog for these tests — the shape get_roles_catalog()
 * returns, `[{slug, name}]`, and the only slugs the save may write.
 */
const TEST_CATALOG = array(
	array( 'slug' => 'finance', 'name' => 'Finance' ),
	array( 'slug' => 'hr', 'name' => 'HR' ),
	array( 'slug' => 'legal', 'name' => 'Legal' ),
);

/**
 * Seed one user with their STORED capabilities.
 */
function seed_user( int $id, string $name, array $caps ): void {
	global $_test_users;
	$_test_users[ $id ] = array( 'display_name' => $name, 'caps' => $caps );
}

function caps_of( int $id ): array {
	global $_test_users;
	$caps = $_test_users[ $id ]['caps'] ?? array();
	sort( $caps );
	return $caps;
}

// ── WordPress stubs ─────────────────────────────────────────────────────

class WP_User_Stub {
	public $ID;
	public $display_name;
	public $caps;

	public function __construct( int $id, array $row ) {
		$this->ID           = $id;
		$this->display_name = $row['display_name'];
		$this->caps         = $row['caps'];
	}

	/**
	 * LIES the way production does: map_def_capabilities() is filtered onto
	 * map_meta_cap and answers true for def_staff_access on any Management or
	 * DEF-Admin user. The save must not consult it to decide what to write.
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

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ): string {
		return is_string( $str ) ? trim( strip_tags( $str ) ) : '';
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

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string {
		return $text;
	}
}

/**
 * The writable capability set, server-side. This is the production funnel the
 * save reads: the fixed trio plus the CACHED catalog's def_role_* caps. A slug
 * the browser invents never reaches it, which is the whole point.
 */
if ( ! class_exists( 'DEF_Core_Tools' ) ) {
	class DEF_Core_Tools {
		public static function get_role_caps(): array {
			$caps = array();
			foreach ( TEST_CATALOG as $role ) {
				$caps[] = 'def_role_' . $role['slug'];
			}
			return $caps;
		}
	}
}

class DEF_Core_Staff_Roster {
	public static function schedule_push(): bool {
		global $_test_cron;
		$_test_cron[] = 'def_core_push_roster';
		return true;
	}
}

/**
 * wp_send_json_* halts the request in WordPress; throwing models that halt.
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

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-admin.php';

// ── Tiny assertion helper (house style) ─────────────────────────────────

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
		echo "  FAIL: $label\n";
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	}
}

/**
 * Submit one User Access save and return the halt it ended in.
 */
function save( array $roles ): AjaxHalt {
	$_POST = array( 'nonce' => 'x', 'roles' => $roles );
	try {
		DEF_Core_Admin::ajax_save_user_roles();
	} catch ( AjaxHalt $halt ) {
		return $halt;
	}
	throw new RuntimeException( 'the save did not halt' );
}

/**
 * The payload the SCREEN submits for one person: every writable capability,
 * '1' or '0', never a subset. That is what both the old checkbox grid and the
 * new chips send — `accessPayload()` walks every .def-core-role-cb on the page.
 */
function row( string $level, array $roles, bool $admin ): array {
	// $level of '' is a real submission: the screen renders neither pill selected
	// for a person who stores neither, and submits both as '0'.
	$caps = array(
		'def_staff_access'      => 'staff' === $level ? '1' : '0',
		'def_management_access' => 'management' === $level ? '1' : '0',
		'def_admin_access'      => $admin ? '1' : '0',
	);
	foreach ( TEST_CATALOG as $role ) {
		$caps[ 'def_role_' . $role['slug'] ] = in_array( $role['slug'], $roles, true ) ? '1' : '0';
	}
	return $caps;
}

echo "=== User Access save — the capability contract (S3) ===\n";

// ── 1. The access level round-trips, and is exactly one ─────────────────
echo "\n[1] Staff / Management round-trips as exactly one capability\n";

seed_user( 10, 'Keeper Kim', array( 'def_admin_access' ) );
seed_user( 11, 'Staffer Sam', array() );

$r = save( array(
	10 => row( 'staff', array(), true ),
	11 => row( 'staff', array(), false ),
) );
assert_true( $r->ok, 'the save succeeds' );
assert_equals( array( 'def_staff_access' ), caps_of( 11 ), 'Staff stores def_staff_access and nothing else' );

// Staff → Management. The upgrade must REMOVE Staff, not sit alongside it:
// two stored levels is the ambiguity the two-way control exists to prevent.
save( array(
	10 => row( 'staff', array(), true ),
	11 => row( 'management', array(), false ),
) );
assert_equals( array( 'def_management_access' ), caps_of( 11 ), 'Management replaces Staff — exactly one level is stored' );

// Management → Staff. has_cap('def_staff_access') already answers TRUE for a
// Management user, so a write guarded on it would remove Management and store
// nothing, locking the user out of the console entirely.
save( array(
	10 => row( 'staff', array(), true ),
	11 => row( 'staff', array(), false ),
) );
assert_equals( array( 'def_staff_access' ), caps_of( 11 ), 'the downgrade back to Staff is stored, and Management is gone' );

// Both submitted at once — a payload the new control cannot produce, kept
// refused because the wire is not the only thing that can reach this handler.
save( array(
	10 => row( 'staff', array(), true ),
	11 => array( 'def_staff_access' => '1', 'def_management_access' => '1' ),
) );
assert_equals( array( 'def_management_access' ), caps_of( 11 ), 'both levels submitted keeps Management only, never both' );

// ── 1b. Neither level is a level too ────────────────────────────────────
echo "
[1b] A person who holds neither level keeps neither
";

// A DEF Admin who administers the settings page and never uses the console is a
// supported setup, not a gap: DEF_Core_Staff_Roster::build_roster() says so in
// as many words ("DEF-Admin alone is NOT a roster row - it is an access grant,
// not a Staff-AI seat"). The screen must be able to SUBMIT that state, or every
// such person is handed a console login the first time anyone presses Save.
$_test_users = array();
seed_user( 10, 'Keeper Kim', array( 'def_admin_access' ) );
seed_user( 12, 'Admin Only Ali', array( 'def_admin_access' ) );

save( array(
	10 => row( 'staff', array(), true ),
	12 => row( '', array(), true ),
) );
assert_equals(
	array( 'def_admin_access' ),
	caps_of( 12 ),
	'a DEF Admin with no access level keeps no access level'
);

// And the reverse: choosing one for them stores exactly that one.
save( array(
	10 => row( 'staff', array(), true ),
	12 => row( 'management', array(), true ),
) );
assert_equals(
	array( 'def_admin_access', 'def_management_access' ),
	caps_of( 12 ),
	'and choosing a level for them stores exactly that one'
);

// ── 2. Catalog roles round-trip ─────────────────────────────────────────
echo "\n[2] Vault roles round-trip, added and removed\n";

$_test_users = array();
seed_user( 10, 'Keeper Kim', array( 'def_admin_access' ) );
seed_user( 20, 'Roleless Ray', array( 'def_staff_access' ) );

save( array(
	10 => row( 'staff', array(), true ),
	20 => row( 'staff', array( 'finance', 'legal' ), false ),
) );
assert_equals(
	array( 'def_role_finance', 'def_role_legal', 'def_staff_access' ),
	caps_of( 20 ),
	'two chips added store both def_role_* caps beside the access level'
);

// One chip removed, one kept, one added — the three transitions in one save.
save( array(
	10 => row( 'staff', array(), true ),
	20 => row( 'staff', array( 'legal', 'hr' ), false ),
) );
assert_equals(
	array( 'def_role_hr', 'def_role_legal', 'def_staff_access' ),
	caps_of( 20 ),
	'removing a chip removes its capability; the others are untouched'
);

save( array(
	10 => row( 'staff', array(), true ),
	20 => row( 'staff', array(), false ),
) );
assert_equals( array( 'def_staff_access' ), caps_of( 20 ), 'removing every chip leaves the access level alone' );

// A role and an access level are independent axes: changing one must not
// disturb the other, which is the claim the two separate columns make.
save( array(
	10 => row( 'staff', array(), true ),
	20 => row( 'management', array( 'finance' ), false ),
) );
assert_equals(
	array( 'def_management_access', 'def_role_finance' ),
	caps_of( 20 ),
	'the level and the roles are written independently in one save'
);

// ── 3. DEF Admin round-trips ────────────────────────────────────────────
echo "\n[3] DEF Admin round-trips\n";

$_test_users = array();
seed_user( 10, 'Keeper Kim', array( 'def_admin_access' ) );
seed_user( 30, 'Promoted Pat', array( 'def_staff_access' ) );

save( array(
	10 => row( 'staff', array(), true ),
	30 => row( 'staff', array( 'hr' ), true ),
) );
assert_equals(
	array( 'def_admin_access', 'def_role_hr', 'def_staff_access' ),
	caps_of( 30 ),
	'DEF Admin is stored beside the access level and the roles'
);

save( array(
	10 => row( 'staff', array(), true ),
	30 => row( 'staff', array( 'hr' ), false ),
) );
assert_equals(
	array( 'def_role_hr', 'def_staff_access' ),
	caps_of( 30 ),
	'clearing DEF Admin removes it and leaves everything else standing'
);

// The lockout guard: the screen may not save away the last DEF Admin.
$before = caps_of( 10 );
$r      = save( array(
	10 => row( 'staff', array(), false ),
	30 => row( 'staff', array( 'hr' ), false ),
) );
assert_true( ! $r->ok, 'dropping the last DEF Admin is refused' );
assert_equals( $before, caps_of( 10 ), 'and nothing is written — the refusal is before the writes' );

// ── 4. The catalog is authoritative, not the browser ────────────────────
echo "\n[4] A def_role_* the browser invents is never written\n";

$_test_users = array();
seed_user( 10, 'Keeper Kim', array( 'def_admin_access' ) );
seed_user( 40, 'Curious Cai', array( 'def_staff_access' ) );

// A hand-edited payload: two real slugs, and three that are not in the catalog
// — one invented, one a reserved slug the catalog sanitizer drops, and one that
// is a WordPress capability rather than a DEF role.
$r = save( array(
	10 => row( 'staff', array(), true ),
	40 => array(
		'def_staff_access'       => '1',
		'def_management_access'  => '0',
		'def_admin_access'       => '0',
		'def_role_finance'       => '1',
		'def_role_hr'            => '0',
		'def_role_superuser'     => '1',
		'def_role_admin'         => '1',
		'manage_options'         => '1',
	),
) );
assert_true( $r->ok, 'the save still succeeds — an unknown key is ignored, not an error' );
assert_equals(
	array( 'def_role_finance', 'def_staff_access' ),
	caps_of( 40 ),
	'only the catalog slug is written: def_role_superuser, def_role_admin and manage_options are not'
);

// And the reverse direction — the browser cannot REVOKE what it does not know
// about either, because the loop is over the catalog and not over the payload.
$_test_users = array();
seed_user( 10, 'Keeper Kim', array( 'def_admin_access' ) );
seed_user( 41, 'Legacy Lee', array( 'def_staff_access', 'def_role_finance', 'edit_posts' ) );

save( array(
	10 => row( 'staff', array(), true ),
	41 => array(
		'def_staff_access' => '1',
		'edit_posts'       => '0',
	),
) );
assert_true(
	in_array( 'edit_posts', $_test_users[41]['caps'], true ),
	'a WordPress capability submitted as 0 is not revoked — the save writes DEF caps only'
);
// def_role_finance IS in the catalog, so an omitted key is a cleared chip and
// the save removes it. That is the grid's contract: the screen submits every
// writable capability every time, so absent means off.
assert_true(
	! in_array( 'def_role_finance', $_test_users[41]['caps'], true ),
	'a catalog role the screen did not submit is cleared — absent means off, as before'
);

// ── 4b. Clearing a level ────────────────────────────────────────────────
echo "
[4b] A level can be cleared as well as switched
";

// The chips screen has no control that clears a level once one is chosen, so
// this arrives only from a hand-made payload or the Setup Assistant. It is the
// exact inverse of the grant, and the write loop must not treat "absent means
// off" differently for the access level than for a role.
$_test_users = array();
seed_user( 10, 'Keeper Kim', array( 'def_admin_access' ) );
seed_user( 60, 'Departing Dev', array( 'def_staff_access', 'def_role_finance' ) );

save( array(
	10 => row( 'staff', array(), true ),
	60 => row( '', array( 'finance' ), false ),
) );
assert_equals(
	array( 'def_role_finance' ),
	caps_of( 60 ),
	'clearing the level removes it and leaves the vault role standing'
);

// ── 5. The roster push still rides on the save ──────────────────────────
echo "\n[5] The save still queues the roster push\n";

$_test_users = array();
$_test_cron  = array();
seed_user( 10, 'Keeper Kim', array( 'def_admin_access' ) );
seed_user( 50, 'Fresh Fran', array() );
save( array(
	10 => row( 'staff', array(), true ),
	50 => row( 'management', array( 'legal' ), false ),
) );
assert_equals( 1, count( $_test_cron ), 'a save through the new screen queues exactly one roster push' );

echo "\n--- User Access Save Tests: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
