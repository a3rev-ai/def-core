<?php
/**
 * DEF role-capability assertion gate tests (Slice 3d-iii).
 *
 * Verifies DEF_Core_Tools::get_user_def_capabilities():
 * - Default (option unset / '1') asserts the user's full DEF grant.
 * - Flipped off (option '0') withholds the ROLE caps (def_staff_access / def_management_access)
 *   so DEF stops deriving roles from the WP grant, while KEEPING def_admin_access.
 * - Re-enabling ('1') restores the full grant (reversible).
 *
 * Runs standalone (no WordPress bootstrap); uses the shared stubs.
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

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $key ): bool {
		global $_wp_test_options;
		unset( $_wp_test_options[ $key ] );
		return true;
	}
}

// A WP_User whose has_cap() consults a settable capability set.
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID       = 0;
		public $roles    = array();
		public $test_caps = array();

		public function __construct( int $id = 0 ) {
			$this->ID = $id;
		}

		public function has_cap( string $cap ): bool {
			return in_array( $cap, $this->test_caps, true );
		}
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-tools.php';

$pass = 0;
$fail = 0;

function assert_same( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
	} else {
		$fail++;
		echo '  FAIL: ' . $label . ' (expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) . ")\n";
	}
}

$OPT = 'def_core_assert_role_capabilities';

echo "=== DEF role-capability assertion gate (Slice 3d-iii) ===\n";

// A manager: has the admin cap + both role caps.
$user            = new WP_User( 7 );
$user->test_caps = array( 'def_admin_access', 'def_staff_access', 'def_management_access' );

// [1] Default (option unset) → assert the full grant (migration bridge).
delete_option( $OPT );
assert_same(
	array( 'def_admin_access', 'def_staff_access', 'def_management_access' ),
	\DEF_Core_Tools::get_user_def_capabilities( $user ),
	'default asserts full grant'
);

// [2] Explicit '1' → same.
update_option( $OPT, '1' );
assert_same(
	array( 'def_admin_access', 'def_staff_access', 'def_management_access' ),
	\DEF_Core_Tools::get_user_def_capabilities( $user ),
	"'1' asserts full grant"
);

// [3] Flipped off ('0') → role caps withheld, def_admin_access kept.
update_option( $OPT, '0' );
assert_same(
	array( 'def_admin_access' ),
	\DEF_Core_Tools::get_user_def_capabilities( $user ),
	"'0' withholds role caps, keeps def_admin_access"
);

// [4] Flipped off, staff-only user (no admin) → empty (no role source at all).
$staff            = new WP_User( 8 );
$staff->test_caps = array( 'def_staff_access' );
assert_same(
	array(),
	\DEF_Core_Tools::get_user_def_capabilities( $staff ),
	"'0' + staff-only → no asserted caps"
);

// [5] Re-enabling restores the full grant (reversible).
update_option( $OPT, '1' );
assert_same(
	array( 'def_admin_access', 'def_staff_access', 'def_management_access' ),
	\DEF_Core_Tools::get_user_def_capabilities( $user ),
	're-enabling restores the full grant'
);

// [6] Fails SAFE: a malformed/garbage value (anything but '0') keeps asserting, never silently denies.
update_option( $OPT, 'yes' );
assert_same(
	array( 'def_admin_access', 'def_staff_access', 'def_management_access' ),
	\DEF_Core_Tools::get_user_def_capabilities( $user ),
	'garbage option value still asserts (fail-safe toward keeping access)'
);

// Restore.
delete_option( $OPT );

echo "\n--- Role-capability assertion gate: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
