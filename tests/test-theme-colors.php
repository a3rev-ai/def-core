<?php
/**
 * Theme Color Detection tests.
 *
 * Verifies:
 * - Block theme detection (wp_get_global_styles)
 * - Classic theme CSS parsing fallback
 * - CSS variable resolution from theme palette
 * - RGB to hex conversion
 * - Activation hook sets defaults only when not configured
 * - REST endpoint returns correct response
 * - Hex color validation for button color settings
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Theme-specific stubs ────────────────────────────────────────────────

global $_wp_test_global_styles, $_wp_test_global_settings;
global $_wp_test_theme_name, $_wp_test_stylesheet_dir;
$_wp_test_global_styles   = array();
$_wp_test_global_settings = array();
$_wp_test_theme_name      = 'Test Theme';
$_wp_test_stylesheet_dir  = __DIR__;

if ( ! function_exists( 'wp_get_global_styles' ) ) {
	function wp_get_global_styles(): array {
		global $_wp_test_global_styles;
		return $_wp_test_global_styles;
	}
}

if ( ! function_exists( 'wp_get_global_settings' ) ) {
	function wp_get_global_settings(): array {
		global $_wp_test_global_settings;
		return $_wp_test_global_settings;
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme() {
		global $_wp_test_theme_name;
		return new class( $_wp_test_theme_name ) {
			private $name;
			public function __construct( string $name ) {
				$this->name = $name;
			}
			public function get( string $key ): string {
				return $key === 'Name' ? $this->name : '';
			}
		};
	}
}

if ( ! function_exists( 'get_stylesheet_directory' ) ) {
	function get_stylesheet_directory(): string {
		global $_wp_test_stylesheet_dir;
		return $_wp_test_stylesheet_dir;
	}
}

// ── Setup Assistant stubs ───────────────────────────────────────────────

global $_wp_test_current_user, $_wp_test_user_caps, $_wp_test_users;
global $_wp_test_user_meta, $_wp_test_transients;
$_wp_test_current_user = null;
$_wp_test_user_caps    = array();
$_wp_test_users        = array();
$_wp_test_user_meta    = array();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public $headers = array();
		public function __construct( $data = null, int $status = 200 ) {
			$this->data = $data;
			$this->status = $status;
		}
		public function get_status(): int { return $this->status; }
		public function get_data() { return $this->data; }
		public function header( string $key, string $value ): void { $this->headers[ $key ] = $value; }
		public function get_headers(): array { return $this->headers; }
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params  = array();
		private $headers = array();
		private $body    = array();
		private $method  = 'GET';
		private $route   = '';
		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}
		public function set_param( string $key, $value ): void { $this->params[ $key ] = $value; }
		public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
		public function set_header( string $key, string $value ): void { $this->headers[ strtolower( $key ) ] = $value; }
		public function get_header( string $key ): ?string { return $this->headers[ strtolower( $key ) ] ?? null; }
		public function set_body( string $body ): void { $this->body = json_decode( $body, true ) ?: array(); }
		public function get_json_params(): array { return $this->body; }
		public function get_method(): string { return $this->method; }
		public function get_route(): string { return $this->route; }
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID;
		public $display_name = '';
		public $user_email   = '';
		public $user_login   = '';
		public $caps         = array();
		public $roles        = array();
		public function __construct( $id = 0 ) { $this->ID = (int) $id; }
		public function has_cap( string $cap ): bool {
			global $_wp_test_user_caps;
			return in_array( $cap, $_wp_test_user_caps, true ) || ! empty( $this->caps[ $cap ] );
		}
		public function add_cap( string $cap ): void { $this->caps[ $cap ] = true; }
		public function remove_cap( string $cap ): void { unset( $this->caps[ $cap ] ); }
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		global $_wp_test_current_user;
		return $_wp_test_current_user ? $_wp_test_current_user->ID : 0;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = '' ): bool { return $nonce === 'valid_nonce'; }
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $ns, $route, $args ) {}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show ): string { return 'Test Site'; }
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ): array { return array(); }
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, $value ) {
		global $_wp_test_users;
		foreach ( $_wp_test_users as $user ) {
			if ( $field === 'id' && $user->ID === (int) $value ) return $user;
			if ( $field === 'email' && $user->user_email === $value ) return $user;
		}
		return false;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( int $user_id, string $key = '', bool $single = false ) {
		global $_wp_test_user_meta;
		if ( ! isset( $_wp_test_user_meta[ $user_id ][ $key ] ) ) {
			return $single ? '' : array();
		}
		return $single ? $_wp_test_user_meta[ $user_id ][ $key ] : array( $_wp_test_user_meta[ $user_id ][ $key ] );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( int $user_id, string $key, $value ): bool {
		global $_wp_test_user_meta;
		$_wp_test_user_meta[ $user_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) { return new WP_Error( 'stub', 'Not implemented' ); }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) { return 200; }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) { return ''; }
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ) { return ''; }
}

if ( ! function_exists( 'get_avatar_url' ) ) {
	function get_avatar_url( $id ): string { return ''; }
}

// Load the standalone theme colors class (no plugin bootstrap needed).
require_once dirname( __DIR__ ) . '/includes/class-def-core-theme-colors.php';

// Load Admin API (for REST endpoint + validation tests).
require_once dirname( __DIR__ ) . '/includes/class-def-core-admin-api.php';

// ── Test infrastructure ─────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function assert_equals( $expected, $actual, string $msg ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
		echo "    Expected: " . var_export( $expected, true ) . "\n";
		echo "    Actual:   " . var_export( $actual, true ) . "\n";
	}
}

function assert_true( $value, string $msg ): void {
	global $pass, $fail;
	if ( $value ) { $pass++; } else { $fail++; echo "  FAIL: $msg\n"; }
}

function assert_false( $value, string $msg ): void {
	global $pass, $fail;
	if ( ! $value ) { $pass++; } else { $fail++; echo "  FAIL: $msg\n"; }
}

function reset_theme_state(): void {
	global $_wp_test_options, $_wp_test_global_styles, $_wp_test_global_settings;
	global $_wp_test_theme_name, $_wp_test_stylesheet_dir;
	global $_wp_test_current_user, $_wp_test_user_caps, $_wp_test_transients;

	$_wp_test_options         = array();
	$_wp_test_global_styles   = array();
	$_wp_test_global_settings = array();
	$_wp_test_theme_name      = 'Test Theme';
	$_wp_test_stylesheet_dir  = __DIR__;
	$_wp_test_current_user    = null;
	$_wp_test_user_caps       = array();
	$_wp_test_transients      = array();

	unset(
		$_SERVER['HTTP_X_WP_NONCE'],
		$_SERVER['HTTP_X_DEF_SIGNATURE'],
		$_SERVER['HTTP_X_DEF_TIMESTAMP'],
		$_SERVER['HTTP_X_DEF_USER'],
		$_SERVER['HTTP_X_DEF_BODY_HASH']
	);
}

function setup_admin(): void {
	global $_wp_test_current_user, $_wp_test_user_caps, $_wp_test_users;
	$user               = new WP_User( 1 );
	$user->display_name = 'Test Admin';
	$user->user_email   = 'admin@test.com';
	$user->caps         = array( 'def_admin_access' => true );
	$_wp_test_current_user = $user;
	$_wp_test_user_caps    = array( 'def_admin_access' );
	$_wp_test_users[1]     = $user;
	$_SERVER['HTTP_X_WP_NONCE'] = 'valid_nonce';
}

// ========================================================================
// TESTS
// ========================================================================

echo "=== Theme Color Detection Tests ===\n";

// ── 1. Block theme: hex color ──────────────────────────────────────────

echo "\n[1] Block theme — direct hex color\n";
reset_theme_state();
$_wp_test_theme_name    = 'Twenty Twenty-Five';
$_wp_test_global_styles = array(
	'elements' => array(
		'button' => array(
			'color' => array(
				'background' => '#0073aa',
			),
			':hover' => array(
				'color' => array(
					'background' => '#005177',
				),
			),
		),
	),
);

$colors = DEF_Core_Theme_Colors::detect();
assert_equals( '#0073aa', $colors['button_color'], 'detects block theme button color' );
assert_equals( '#005177', $colors['button_hover_color'], 'detects block theme hover color' );
assert_equals( 'block_theme', $colors['source'], 'source is block_theme' );
assert_equals( 'Twenty Twenty-Five', $colors['theme_name'], 'reports theme name' );

// ── 2. Block theme: CSS variable reference ─────────────────────────────

echo "\n[2] Block theme — CSS variable with palette resolution\n";
reset_theme_state();
$_wp_test_theme_name    = 'Flavor Theme';
$_wp_test_global_styles = array(
	'elements' => array(
		'button' => array(
			'color' => array(
				'background' => 'var(--wp--preset--color--primary)',
			),
		),
	),
);
$_wp_test_global_settings = array(
	'color' => array(
		'palette' => array(
			'theme' => array(
				array( 'slug' => 'primary', 'color' => '#e63946' ),
				array( 'slug' => 'secondary', 'color' => '#457b9d' ),
			),
		),
	),
);

$colors = DEF_Core_Theme_Colors::detect();
assert_equals( '#e63946', $colors['button_color'], 'resolves CSS variable to hex via palette' );
assert_equals( 'block_theme', $colors['source'], 'source is block_theme' );

// ── 3. Block theme: rgb() color ────────────────────────────────────────

echo "\n[3] Block theme — rgb() color conversion\n";
reset_theme_state();
$_wp_test_global_styles = array(
	'elements' => array(
		'button' => array(
			'color' => array(
				'background' => 'rgb(255, 87, 51)',
			),
		),
	),
);

$colors = DEF_Core_Theme_Colors::detect();
assert_equals( '#ff5733', $colors['button_color'], 'converts rgb() to hex' );
assert_equals( 'block_theme', $colors['source'], 'source is block_theme' );

// ── 4. Classic theme: CSS parsing ──────────────────────────────────────

echo "\n[4] Classic theme — CSS parsing fallback\n";
reset_theme_state();
$tmp_dir = sys_get_temp_dir() . '/def-test-theme-' . uniqid();
mkdir( $tmp_dir, 0755, true );
$_wp_test_stylesheet_dir = $tmp_dir;
$_wp_test_global_styles  = array(); // No block theme data.

file_put_contents( $tmp_dir . '/style.css', '
/* Theme: Test Classic */
body { background: #fff; }
.button {
	background-color: #28a745;
	color: #fff;
	padding: 10px 20px;
}
a { color: #333; }
');

$colors = DEF_Core_Theme_Colors::detect();
assert_equals( '#28a745', $colors['button_color'], 'extracts button color from classic theme CSS' );
assert_equals( 'classic_theme_css', $colors['source'], 'source is classic_theme_css' );
assert_equals( '', $colors['button_hover_color'], 'no hover color from CSS parsing' );

unlink( $tmp_dir . '/style.css' );
rmdir( $tmp_dir );

// ── 5. Classic theme: submit input pattern ─────────────────────────────

echo "\n[5] Classic theme — input[type=submit] selector\n";
reset_theme_state();
$tmp_dir = sys_get_temp_dir() . '/def-test-theme-' . uniqid();
mkdir( $tmp_dir, 0755, true );
$_wp_test_stylesheet_dir = $tmp_dir;
$_wp_test_global_styles  = array();

file_put_contents( $tmp_dir . '/style.css', '
input[type="submit"] {
	background-color: #dc3545;
	border: none;
}
');

$colors = DEF_Core_Theme_Colors::detect();
assert_equals( '#dc3545', $colors['button_color'], 'extracts from input[type=submit]' );
assert_equals( 'classic_theme_css', $colors['source'], 'source is classic_theme_css' );

unlink( $tmp_dir . '/style.css' );
rmdir( $tmp_dir );

// ── 6. No theme colors found ───────────────────────────────────────────

echo "\n[6] No theme button colors detected\n";
reset_theme_state();
$_wp_test_global_styles   = array();
$_wp_test_stylesheet_dir  = sys_get_temp_dir() . '/nonexistent-theme';

$colors = DEF_Core_Theme_Colors::detect();
assert_equals( '', $colors['button_color'], 'no color when theme has no button styles' );
assert_equals( 'none', $colors['source'], 'source is none' );

// ── 7. Activation sets defaults from theme ─────────────────────────────

echo "\n[7] Activation sets button colors from theme\n";
reset_theme_state();
$_wp_test_global_styles = array(
	'elements' => array(
		'button' => array(
			'color' => array(
				'background' => '#6366f1',
			),
			':hover' => array(
				'color' => array(
					'background' => '#4f46e5',
				),
			),
		),
	),
);

DEF_Core_Theme_Colors::maybe_set_defaults();
assert_equals( '#6366f1', get_option( 'def_core_chat_button_color' ), 'activation sets button color from theme' );
assert_equals( '#4f46e5', get_option( 'def_core_chat_button_hover_color' ), 'activation sets hover color from theme' );

// ── 8. Activation does NOT overwrite existing colors ───────────────────

echo "\n[8] Activation preserves existing button colors\n";
reset_theme_state();
update_option( 'def_core_chat_button_color', '#ff0000' );
$_wp_test_global_styles = array(
	'elements' => array(
		'button' => array(
			'color' => array(
				'background' => '#6366f1',
			),
		),
	),
);

DEF_Core_Theme_Colors::maybe_set_defaults();
assert_equals( '#ff0000', get_option( 'def_core_chat_button_color' ), 'does not overwrite existing color' );

// ── 9. REST endpoint returns correct data ──────────────────────────────

echo "\n[9] GET /setup/detect-theme-colors endpoint\n";
reset_theme_state();
setup_admin();
$_wp_test_theme_name    = 'Flavor Theme';
$_wp_test_global_styles = array(
	'elements' => array(
		'button' => array(
			'color' => array(
				'background' => '#0073aa',
			),
		),
	),
);
update_option( 'def_core_chat_button_color', '#111827' );
update_option( 'def_core_chat_button_hover_color', '' );

$sa       = new DEF_Core_Admin_API();
$request  = new WP_REST_Request( 'GET', '/def-core/v1/setup/detect-theme-colors' );
$response = $sa->rest_detect_theme_colors( $request );
$data     = $response->get_data();

assert_equals( 200, $response->get_status(), 'returns 200' );
assert_true( $data['success'], 'success is true' );
assert_equals( '#0073aa', $data['data']['button_color'], 'detected button color in response' );
assert_equals( 'block_theme', $data['data']['source'], 'source in response' );
assert_equals( 'Flavor Theme', $data['data']['theme_name'], 'theme name in response' );
assert_equals( '#111827', $data['data']['current_button_color'], 'includes current button color' );
assert_equals( '', $data['data']['current_button_hover_color'], 'includes current hover color' );

// ── 10. Hex color validation — valid values ────────────────────────────

echo "\n[10] Hex color validation — valid values\n";
reset_theme_state();
setup_admin();

$sa      = new DEF_Core_Admin_API();

// 6-digit hex.
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_chat_button_color' );
$request->set_param( 'key', 'def_core_chat_button_color' );
$request->set_body( json_encode( array( 'value' => '#FF5733' ) ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 200, $response->get_status(), 'accepts valid 6-digit hex color' );
assert_equals( '#FF5733', get_option( 'def_core_chat_button_color' ), 'saves 6-digit hex' );

// 3-digit hex.
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_chat_button_color' );
$request->set_param( 'key', 'def_core_chat_button_color' );
$request->set_body( json_encode( array( 'value' => '#F00' ) ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 200, $response->get_status(), 'accepts valid 3-digit hex color' );

// Empty value (clear).
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_chat_button_hover_color' );
$request->set_param( 'key', 'def_core_chat_button_hover_color' );
$request->set_body( json_encode( array( 'value' => '' ) ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 200, $response->get_status(), 'accepts empty value to clear color' );

// ── 11. Hex color validation — invalid values ──────────────────────────

echo "\n[11] Hex color validation — invalid values\n";
reset_theme_state();
setup_admin();
$sa = new DEF_Core_Admin_API();

// Named color.
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_chat_button_color' );
$request->set_param( 'key', 'def_core_chat_button_color' );
$request->set_body( json_encode( array( 'value' => 'red' ) ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 400, $response->get_status(), 'rejects named color' );
assert_equals( 'VALIDATION_ERROR', $response->get_data()['error']['code'], 'error code is VALIDATION_ERROR' );

// Missing hash.
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_chat_button_color' );
$request->set_param( 'key', 'def_core_chat_button_color' );
$request->set_body( json_encode( array( 'value' => 'FF5733' ) ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 400, $response->get_status(), 'rejects hex without # prefix' );

// Invalid hex characters.
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_chat_button_color' );
$request->set_param( 'key', 'def_core_chat_button_color' );
$request->set_body( json_encode( array( 'value' => '#GGGGGG' ) ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 400, $response->get_status(), 'rejects invalid hex characters' );

// 8-digit hex (rgba).
$request = new WP_REST_Request( 'POST', '/def-core/v1/setup/setting/def_core_chat_button_color' );
$request->set_param( 'key', 'def_core_chat_button_color' );
$request->set_body( json_encode( array( 'value' => '#FF573300' ) ) );
$response = $sa->rest_update_setting( $request );
assert_equals( 400, $response->get_status(), 'rejects 8-digit hex (rgba)' );

// ── 12. Unresolvable CSS variable ──────────────────────────────────────

echo "\n[12] Block theme — unresolvable CSS variable falls through\n";
reset_theme_state();
$_wp_test_global_styles = array(
	'elements' => array(
		'button' => array(
			'color' => array(
				'background' => 'var(--wp--preset--color--nonexistent)',
			),
		),
	),
);
$_wp_test_global_settings = array(
	'color' => array(
		'palette' => array(
			'theme' => array(
				array( 'slug' => 'primary', 'color' => '#e63946' ),
			),
		),
	),
);

$colors = DEF_Core_Theme_Colors::detect();
assert_equals( '', $colors['button_color'], 'unresolvable variable returns empty' );
assert_equals( 'none', $colors['source'], 'source is none when variable unresolvable' );

// ── 13. wp-element-button takes priority ───────────────────────────────

echo "\n[13] Classic theme — wp-element-button selector (highest priority)\n";
reset_theme_state();
$tmp_dir = sys_get_temp_dir() . '/def-test-theme-' . uniqid();
mkdir( $tmp_dir, 0755, true );
$_wp_test_stylesheet_dir = $tmp_dir;
$_wp_test_global_styles  = array();

file_put_contents( $tmp_dir . '/style.css', '
.wp-element-button {
	background-color: #1e40af;
}
.button {
	background-color: #28a745;
}
');

$colors = DEF_Core_Theme_Colors::detect();
assert_equals( '#1e40af', $colors['button_color'], 'wp-element-button takes priority over .button' );

unlink( $tmp_dir . '/style.css' );
rmdir( $tmp_dir );

// ── 14. Read button color via get_setting ──────────────────────────────

echo "\n[14] GET /setup/setting — read button color settings\n";
reset_theme_state();
setup_admin();
update_option( 'def_core_chat_button_color', '#FF5733' );

$sa      = new DEF_Core_Admin_API();
$request = new WP_REST_Request( 'GET', '/def-core/v1/setup/setting/def_core_chat_button_color' );
$request->set_param( 'key', 'def_core_chat_button_color' );
$response = $sa->rest_get_setting( $request );
$data     = $response->get_data();
assert_equals( 200, $response->get_status(), 'returns 200' );
assert_equals( '#FF5733', $data['data']['value'], 'returns button color value' );

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n=== Results ===\n";
echo "Passed: $pass\n";
echo "Failed: $fail\n";
if ( $fail === 0 ) {
	echo "All theme color detection tests passed.\n";
}
exit( $fail > 0 ? 1 : 0 );
