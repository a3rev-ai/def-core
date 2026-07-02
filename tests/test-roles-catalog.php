<?php
/**
 * Custom Roles Catalog Tests (custom-roles Phase 2, R4)
 *
 * Verifies:
 * - get_roles_catalog() returns the cached option (and [] for a malformed cache)
 * - get_roles_catalog(true) fetches from DEF, sanitizes entries (bad slugs dropped,
 *   names text-sanitized, extra keys dropped) and updates the cache
 * - fetch failure (transport error / non-200 / bad body) keeps the cached copy
 * - get_role_caps() maps the catalog to def_role_<slug> capability names
 * - get_user_def_capabilities() asserts trio + CATALOG-validated def_role_* caps only
 *   (a stale cap for a role DEFHO deleted is NOT asserted)
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

// ── Stubs specific to the catalog fetch ─────────────────────────────────

global $_rc_remote_response, $_rc_current_user;
$_rc_remote_response = null; // null = WP_Error; else ['code' => int, 'body' => string]
$_rc_current_user    = null;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->message = $message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		global $_rc_remote_response;
		if ( null === $_rc_remote_response ) {
			return new WP_Error( 'http_failure', 'no route' );
		}
		return $_rc_remote_response;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) && isset( $response['code'] ) ? $response['code'] : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID    = 0;
		public $caps  = array();
		public $roles = array();

		public function __construct( int $id = 0, array $caps = array() ) {
			$this->ID   = $id;
			$this->caps = $caps;
		}

		public function has_cap( string $cap ): bool {
			return ! empty( $this->caps[ $cap ] );
		}
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		global $_rc_current_user;
		return $_rc_current_user ? $_rc_current_user : new WP_User( 0 );
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-tools.php';

// ── Tiny assertion helper (house style) ─────────────────────────────────

$pass = 0;
$fail = 0;

function check( bool $cond, string $label ): void {
	global $pass, $fail;
	static $n = 0;
	$n++;
	if ( $cond ) {
		$pass++;
		echo "[$n] $label\n";
	} else {
		$fail++;
		echo "[$n] FAIL: $label\n";
	}
}

// ── Tests ────────────────────────────────────────────────────────────────

global $_wp_test_options, $_rc_remote_response, $_rc_current_user;

// 1-2: cached reads
$_wp_test_options['def_core_roles_catalog'] = array( array( 'slug' => 'finance', 'name' => 'Finance' ) );
check( DEF_Core_Tools::get_roles_catalog() === array( array( 'slug' => 'finance', 'name' => 'Finance' ) ),
	'Cached catalog returned without a fetch' );
$_wp_test_options['def_core_roles_catalog'] = 'garbage-string';
check( DEF_Core_Tools::get_roles_catalog() === array(), 'Malformed cache degrades to []' );

// 3: refresh with no connection config keeps cache (no api url/key)
$_wp_test_options['def_core_roles_catalog'] = array( array( 'slug' => 'hr', 'name' => 'HR' ) );
unset( $_wp_test_options['def_core_staff_ai_api_url'] );
check( DEF_Core_Tools::get_roles_catalog( true ) === array( array( 'slug' => 'hr', 'name' => 'HR' ) ),
	'Refresh without connection config returns the cached copy' );

// Configure the connection for fetch tests.
$_wp_test_options['def_core_staff_ai_api_url'] = 'https://def.example';
\DEF_Core_Encryption::set_secret( 'def_core_api_key', 'k' );
$_rc_current_user = new WP_User( 7, array( 'def_admin_access' => true ) );

// 4: successful fetch sanitizes + caches
$_rc_remote_response = array( 'code' => 200, 'body' => wp_json_encode( array( 'roles' => array(
	array( 'slug' => 'finance', 'name' => "Finance <script>x</script>", 'extra' => 'dropped' ),
	array( 'slug' => 'BAD SLUG', 'name' => 'Nope' ),
	array( 'slug' => 'x,def_management_access', 'name' => 'Nope' ),
	array( 'slug' => 'ops', 'name' => 'Ops' ),
) ) ) );
$fetched = DEF_Core_Tools::get_roles_catalog( true );
check( array( 'finance', 'ops' ) === array_column( $fetched, 'slug' ),
	'Fetch keeps valid slugs, drops malformed + comma-smuggling slugs' );
check( false === strpos( $fetched[0]['name'], '<' ), 'Names are text-sanitized' );
check( ! isset( $fetched[0]['extra'] ), 'Extra keys dropped on store' );
check( $_wp_test_options['def_core_roles_catalog'] === $fetched, 'Fetched catalog cached in the option' );

// 5: non-200 / transport error / bad body keep the cache
$_rc_remote_response = array( 'code' => 403, 'body' => '{}' );
check( DEF_Core_Tools::get_roles_catalog( true ) === $fetched, 'Non-200 keeps the cached copy' );
$_rc_remote_response = null; // WP_Error
check( DEF_Core_Tools::get_roles_catalog( true ) === $fetched, 'Transport error keeps the cached copy' );
$_rc_remote_response = array( 'code' => 200, 'body' => 'not-json' );
check( DEF_Core_Tools::get_roles_catalog( true ) === $fetched, 'Unparseable body keeps the cached copy' );

// 6: get_role_caps maps to def_role_*
check( DEF_Core_Tools::get_role_caps() === array( 'def_role_finance', 'def_role_ops' ),
	'get_role_caps maps catalog slugs to def_role_* names' );

// 7: assertion funnel — trio + catalog-validated custom caps only
$user = new WP_User( 9, array(
	'def_staff_access' => true,
	'def_role_finance' => true,   // in catalog → asserted
	'def_role_ghost'   => true,   // NOT in catalog (deleted role) → NOT asserted
) );
$caps = DEF_Core_Tools::get_user_def_capabilities( $user );
check( in_array( 'def_staff_access', $caps, true ), 'Trio caps still asserted' );
check( in_array( 'def_role_finance', $caps, true ), 'Catalog-backed custom-role cap asserted' );
check( ! in_array( 'def_role_ghost', $caps, true ), 'Stale cap for a deleted role NOT asserted' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
