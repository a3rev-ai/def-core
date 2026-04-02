<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * Loads WordPress stubs and source classes under test
 * WITHOUT bootstrapping a full WordPress environment.
 *
 * @package def-core/tests/unit
 */

declare(strict_types=1);

// ── Yoast PHPUnit Polyfills ─────────────────────────────────────────────
require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// ── WordPress function stubs (comprehensive) ────────────────────────────
require_once dirname( __DIR__ ) . '/wp-stubs.php';

// ── Time constants ──────────────────────────────────────────────────────
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/tmp/wp-plugins' );
}

// ── Additional WP function stubs ────────────────────────────────────────

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = array() ): void {}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		// Return relative path from plugins dir.
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ): string {
		return 'test_nonce_' . md5( (string) $action );
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
		return 1;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		global $_wp_test_remote_responses;
		if ( isset( $_wp_test_remote_responses[ $url ] ) ) {
			return $_wp_test_remote_responses[ $url ];
		}
		return new WP_Error( 'http_request_failed', 'No stub response for ' . $url );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
			return (int) $response['response']['code'];
		}
		return 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		if ( is_array( $response ) && isset( $response['body'] ) ) {
			return $response['body'];
		}
		return '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( string $plugin ): bool {
		global $_wp_test_active_plugins;
		return in_array( $plugin, $_wp_test_active_plugins ?? array(), true );
	}
}

if ( ! function_exists( 'activate_plugin' ) ) {
	function activate_plugin( string $plugin ): void {
		global $_wp_test_active_plugins;
		if ( ! is_array( $_wp_test_active_plugins ) ) {
			$_wp_test_active_plugins = array();
		}
		$_wp_test_active_plugins[] = $plugin;
	}
}

// ── Stub classes ────────────────────────────────────────────────────────

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		protected $code;
		protected $message;
		protected $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public $headers = array();

		public function __construct( $data = null, int $status = 200, array $headers = array() ) {
			$this->data    = $data;
			$this->status  = $status;
			$this->headers = $headers;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function set_status( int $status ): void {
			$this->status = $status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = array();
		private $method = 'GET';
		private $route  = '';
		private $body   = '';

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

		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_params(): array {
			return $this->params;
		}

		public function set_body( string $body ): void {
			$this->body = $body;
		}

		public function get_body(): string {
			return $this->body;
		}

		public function get_json_params(): array {
			$decoded = json_decode( $this->body, true );
			return is_array( $decoded ) ? $decoded : array();
		}
	}
}

// ── Global test state ───────────────────────────────────────────────────
global $_wp_test_remote_responses, $_wp_test_active_plugins, $_wp_test_deleted_transients;
$_wp_test_remote_responses   = array();
$_wp_test_active_plugins     = array();
$_wp_test_deleted_transients = array();

// ── Load source classes under test ──────────────────────────────────────
// Note: Encryption is already loaded by wp-stubs.php.
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-jwt.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-cache.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-api-registry.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-github-updater.php';
