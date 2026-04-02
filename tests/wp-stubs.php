<?php
/**
 * Minimal WordPress function stubs for unit testing def-core classes
 * without a full WordPress bootstrap.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

// Prevent double-definition.
if ( defined( 'DEF_CORE_TEST_STUBS_LOADED' ) ) {
	return;
}
define( 'DEF_CORE_TEST_STUBS_LOADED', true );

// Fix OpenSSL config path on Windows (XAMPP).
// PHP's openssl functions need the config file location on Windows.
if ( PHP_OS_FAMILY === 'Windows' ) {
	$_wp_test_openssl_cnf = '';
	$php_dir = dirname( PHP_BINARY );
	$cnf     = $php_dir . '/extras/ssl/openssl.cnf';
	if ( file_exists( $cnf ) ) {
		$_wp_test_openssl_cnf = $cnf;
		putenv( 'OPENSSL_CONF=' . $cnf );
	}
}

/**
 * Generate RSA keypair and seed into options (works on Windows).
 * Call this instead of DEF_Core_JWT::ensure_keys_exist() in tests.
 */
function _wp_test_seed_rsa_keys(): array {
	global $_wp_test_openssl_cnf;
	$config = array(
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
	);
	if ( ! empty( $_wp_test_openssl_cnf ) ) {
		$config['config'] = $_wp_test_openssl_cnf;
	}
	$res = openssl_pkey_new( $config );
	if ( ! $res ) {
		echo "FATAL: openssl_pkey_new failed: " . openssl_error_string() . "\n";
		exit( 1 );
	}
	$priv = '';
	openssl_pkey_export( $res, $priv, null, $config );
	$details = openssl_pkey_get_details( $res );
	$pub     = $details['key'] ?? '';
	$kid     = substr( sha1( $pub ), 0, 16 );
	$data    = array(
		'private' => $priv,
		'public'  => $pub,
		'kid'     => $kid,
		'created' => time(),
	);
	update_option( DEF_CORE_OPTION_KEYS, $data );
	return $data;
}

// WordPress constants required by plugin files.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Plugin constants (mirroring def-core.php).
if ( ! defined( 'DEF_CORE_VERSION' ) ) {
	define( 'DEF_CORE_VERSION', '1.0.0' );
}
if ( ! defined( 'DEF_CORE_OPTION_KEYS' ) ) {
	define( 'DEF_CORE_OPTION_KEYS', 'def_core_keys' );
}
if ( ! defined( 'DEF_CORE_OPTION_ALLOWED_ORIGINS' ) ) {
	define( 'DEF_CORE_OPTION_ALLOWED_ORIGINS', 'def_core_allowed_origins' );
}
if ( ! defined( 'DEF_CORE_API_NAME_SPACE' ) ) {
	define( 'DEF_CORE_API_NAME_SPACE', 'a3-ai/v1' );
}
if ( ! defined( 'DEF_CORE_AUDIENCE' ) ) {
	define( 'DEF_CORE_AUDIENCE', 'digital-employee-framework' );
}
if ( ! defined( 'DEF_CORE_PLUGIN_DIR' ) ) {
	define( 'DEF_CORE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// ── In-memory option store ──────────────────────────────────────────────
global $_wp_test_options;
$_wp_test_options = array();

/**
 * Reset all options between tests.
 */
function _wp_test_reset_options(): void {
	global $_wp_test_options;
	$_wp_test_options = array();
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		global $_wp_test_options;
		return array_key_exists( $key, $_wp_test_options ) ? $_wp_test_options[ $key ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, $value, $autoload = null ): bool {
		global $_wp_test_options;
		$_wp_test_options[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $key, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
		global $_wp_test_options;
		if ( ! array_key_exists( $key, $_wp_test_options ) ) {
			$_wp_test_options[ $key ] = $value;
			return true;
		}
		return false;
	}
}

// ── Minimal WP stubs ────────────────────────────────────────────────────

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url(): string {
		return 'https://test.example.com';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return is_string( $str ) ? trim( strip_tags( $str ) ) : '';
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return is_string( $str ) ? trim( $str ) : '';
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( string $email ): string {
		return filter_var( $email, FILTER_SANITIZE_EMAIL ) ?: '';
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( string $email ) {
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( intval( $value ) );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $filename ): string {
		return preg_replace( '/[^a-zA-Z0-9._-]/', '', $filename );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		global $_wp_test_current_user;
		return $_wp_test_current_user ? $_wp_test_current_user->ID : 0;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://test.example.com' . $path;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ) {
		if ( 'timestamp' === $type ) {
			return time();
		}
		return gmdate( $type );
	}
}

// ── Transient stubs ─────────────────────────────────────────────────────
global $_wp_test_transients;
$_wp_test_transients = array();

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		global $_wp_test_transients;
		return $_wp_test_transients[ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		global $_wp_test_transients;
		$_wp_test_transients[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		global $_wp_test_transients, $_wp_test_deleted_transients;
		if ( is_array( $_wp_test_deleted_transients ) ) {
			$_wp_test_deleted_transients[] = $key;
		}
		unset( $_wp_test_transients[ $key ] );
		return true;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url, $protocols = null ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		// Basic validation: must start with http:// or https://.
		if ( $protocols !== null ) {
			$valid = false;
			foreach ( (array) $protocols as $proto ) {
				if ( strpos( $url, $proto . '://' ) === 0 ) {
					$valid = true;
					break;
				}
			}
			if ( ! $valid ) {
				return '';
			}
		}
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'get_file_data' ) ) {
	function get_file_data( string $file, array $headers ): array {
		$result = array();
		foreach ( $headers as $key => $header ) {
			$result[ $key ] = '';
		}
		if ( ! file_exists( $file ) ) {
			return $result;
		}
		$content = file_get_contents( $file, false, null, 0, 8192 );
		foreach ( $headers as $key => $header ) {
			if ( preg_match( '/^[\s\*#@]*' . preg_quote( $header, '/' ) . ':\s*(.+)$/mi', $content, $m ) ) {
				$result[ $key ] = trim( $m[1] );
			}
		}
		return $result;
	}
}

// ── Encryption stubs ─────────────────────────────────────────────────────

// Controllable salt for testing (e.g. salt rotation tests can override).
global $_wp_test_salts;
if ( ! isset( $_wp_test_salts ) ) {
	$_wp_test_salts = array(
		'auth'        => 'test-auth-salt-abcdef1234567890',
		'secure_auth' => 'test-secure-auth-salt-1234567890abcdef',
	);
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		global $_wp_test_salts;
		return $_wp_test_salts[ $scheme ] ?? 'default-salt-' . $scheme;
	}
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// Load encryption class — plugin-level infrastructure used by all secret-reading classes.
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-encryption.php';
