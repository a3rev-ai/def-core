<?php
/**
 * Machine roster route tests (custom-roles M1).
 *
 * Verifies:
 * - GET /users/def-roles is registered with the plain machine-HMAC permission
 *   callback (permission_check_machine — NOT the /setup dual-mode check)
 * - Auth mode: no HMAC headers rejected; valid machine HMAC (user "system",
 *   no WordPress account) accepted
 * - Cap→slug mapping: def_staff_access→staff, def_management_access→management,
 *   def_role_<slug>→<slug>; admin-only users appear with empty roles
 * - Users with zero DEF caps (or only a non-catalog def_role_* cap) excluded
 * - Response trimmed to {user_id, display_name, roles} — no email
 * - Empty tenant → users: []
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

// ── REST + user stubs (mirrors test-admin-api.php) ──────────────────────

global $_wp_test_rest_routes, $_wp_test_users;
$_wp_test_rest_routes = array();
$_wp_test_users       = array();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
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

		public function get_status(): int {
			return $this->status;
		}

		public function get_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $method = 'GET';
		private $route  = '';

		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}

		public function get_query_params(): array {
			return array();
		}
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args ): void {
		global $_wp_test_rest_routes;
		$key = $namespace . $route;
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			foreach ( $args as $sub_args ) {
				$method = $sub_args['methods'] ?? 'GET';
				$_wp_test_rest_routes[ $key . '::' . $method ] = $sub_args;
			}
		} else {
			$method = $args['methods'] ?? 'GET';
			$_wp_test_rest_routes[ $key . '::' . $method ] = $args;
		}
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID           = 0;
		public $display_name = '';
		public $user_email   = '';
		public $caps         = array();

		public function __construct( int $id = 0, array $caps = array() ) {
			$this->ID   = $id;
			$this->caps = $caps;
		}

		public function exists(): bool {
			return $this->ID > 0;
		}

		public function has_cap( string $cap ): bool {
			return ! empty( $this->caps[ $cap ] );
		}
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, $value ) {
		global $_wp_test_users;
		if ( $field === 'id' ) {
			return $_wp_test_users[ intval( $value ) ] ?? null;
		}
		return null;
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ) {
		global $_wp_test_users;
		$cap    = $args['capability'] ?? '';
		$fields = $args['fields'] ?? '';
		$result = array();
		foreach ( $_wp_test_users as $user ) {
			if ( ! empty( $cap ) && ! $user->has_cap( $cap ) ) {
				continue;
			}
			$result[] = ( $fields === 'ids' ) ? $user->ID : $user;
		}
		return $result;
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-hmac-auth.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-tools.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-admin-api.php';

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

global $_wp_test_options, $_wp_test_rest_routes, $_wp_test_users;

$api = new DEF_Core_Admin_API();

// 1-3: registration — machine permission callback (not the dual-mode check) + handler wiring
$api->register_rest_routes();
$route = $_wp_test_rest_routes['def-core/v1/users/def-roles::GET'] ?? null;
check( null !== $route, 'GET /users/def-roles registered' );
check(
	array( \A3Rev\DefCore\DEF_Core_HMAC_Auth::class, 'permission_check_machine' ) === ( $route['permission_callback'] ?? null ),
	'Permission callback is the plain machine-HMAC check'
);
check(
	array( $api, 'rest_get_users_def_roles' ) === ( $route['callback'] ?? null ),
	'Callback wired to rest_get_users_def_roles'
);

// 3: auth — no HMAC headers rejected
unset(
	$_SERVER['HTTP_X_DEF_SIGNATURE'],
	$_SERVER['HTTP_X_DEF_TIMESTAMP'],
	$_SERVER['HTTP_X_DEF_USER'],
	$_SERVER['HTTP_X_DEF_BODY_HASH']
);
$request = new WP_REST_Request( 'GET', '/def-core/v1/users/def-roles' );
$result  = \A3Rev\DefCore\DEF_Core_HMAC_Auth::permission_check_machine( $request );
check( is_wp_error( $result ) && 'HMAC_MISSING_HEADERS' === $result->get_error_code(),
	'No HMAC headers rejected (browser/nonce mode cannot reach this route)' );

// 4: auth — valid machine HMAC with user "system" (no WordPress account) accepted
\DEF_Core_Encryption::set_secret( 'def_core_api_key', 'test_machine_key' );
$timestamp = (string) time();
$body_hash = hash( 'sha256', '' );
$payload   = "GET:/def-core/v1/users/def-roles:{$timestamp}:system:{$body_hash}";

$_SERVER['HTTP_X_DEF_SIGNATURE'] = hash_hmac( 'sha256', $payload, 'test_machine_key' );
$_SERVER['HTTP_X_DEF_TIMESTAMP'] = $timestamp;
$_SERVER['HTTP_X_DEF_USER']      = 'system';
$_SERVER['HTTP_X_DEF_BODY_HASH'] = $body_hash;

check( true === \A3Rev\DefCore\DEF_Core_HMAC_Auth::permission_check_machine( $request ),
	'Valid machine HMAC (user "system") accepted' );

// 5: empty tenant → users: []
$_wp_test_options['def_core_roles_catalog'] = array( array( 'slug' => 'finance', 'name' => 'Finance' ) );
$_wp_test_users = array();
$data = $api->rest_get_users_def_roles( $request )->get_data();
check( true === $data['success'] && array() === $data['data']['users'], 'Empty tenant returns users: []' );

// Seed the roster for the mapping tests.
$mk = function ( int $id, string $name, array $caps ): WP_User {
	$user               = new WP_User( $id, $caps );
	$user->display_name = $name;
	$user->user_email   = $name . '@test.example';
	return $user;
};
$_wp_test_users = array(
	1 => $mk( 1, 'Staff Only', array( 'def_staff_access' => true ) ),
	2 => $mk( 2, 'Management Only', array( 'def_management_access' => true ) ),
	3 => $mk( 3, 'Finance Staffer', array( 'def_staff_access' => true, 'def_role_finance' => true ) ),
	4 => $mk( 4, 'Admin Only', array( 'def_admin_access' => true ) ),
	5 => $mk( 5, 'Ghost Role', array( 'def_role_ghost' => true ) ), // cap not in catalog
	6 => $mk( 6, 'No Caps', array() ),
);

$data  = $api->rest_get_users_def_roles( $request )->get_data();
$users = $data['data']['users'];
$by_id = array_column( $users, null, 'user_id' );

// 6-9: cap→slug mapping
check( array( 'staff' ) === ( $by_id[1]['roles'] ?? null ), 'def_staff_access maps to staff' );
check( array( 'management' ) === ( $by_id[2]['roles'] ?? null ), 'def_management_access maps to management' );
check( array( 'staff', 'finance' ) === ( $by_id[3]['roles'] ?? null ),
	'Catalog-backed def_role_finance maps to finance (alongside staff)' );
check( array() === ( $by_id[4]['roles'] ?? null ), 'def_admin-only user included with empty roles (admin is not a role)' );

// 10-11: exclusions
check( ! isset( $by_id[5] ), 'User with only a non-catalog def_role_* cap excluded' );
check( ! isset( $by_id[6] ), 'User with zero DEF caps excluded' );

// 12-13: response shape trimmed
check( 'Finance Staffer' === $by_id[3]['display_name'], 'display_name present' );
check( ! array_key_exists( 'email', $by_id[3] ) && ! array_key_exists( 'capabilities', $by_id[3] ),
	'No email / raw capabilities in the response' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
