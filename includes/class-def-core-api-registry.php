<?php
/**
 * Class DEF_Core_API_Registry
 *
 * Registry system for API tools. Allows addons to register their own tools.
 *
 * @package def-core
 * @since 0.2.0
 * @version 0.2.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DEF_Core_API_Registry
 *
 * Registry system for API tools. Allows addons to register their own tools.
 *
 * @package def-core
 * @since 0.2.0
 * @version 0.2.0
 */
final class DEF_Core_API_Registry {

	/**
	 * Registry instance.
	 *
	 * @var DEF_Core_API_Registry
	 */
	private static $instance;

	/**
	 * Registered API tools.
	 *
	 * @var array<string, array{
	 *     route: string,
	 *     name: string,
	 *     methods: string|array<string>,
	 *     permission_callback: callable,
	 *     callback: callable,
	 *     args?: array,
	 *     version?: string,
	 *     addon?: string
	 * }>
	 */
	private $registered_tools = array();

	/**
	 * Get the registry instance.
	 *
	 * @return DEF_Core_API_Registry
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	private function __construct() {
		// Registry initialization - no hooks here, we'll call register_all_tools() directly.
	}

	/**
	 * Register an API tool.
	 *
	 * @param string   $route REST API route (e.g., '/tools/my-tool').
	 * @param string   $name Display name for the tool (e.g., 'User Profile').
	 * @param string[] $methods HTTP methods (e.g., ['GET', 'POST']).
	 * @param callable $callback Callback function to handle the request.
	 * @param callable $permission_callback Permission callback (defaults to JWT auth check).
	 * @param array    $args Optional. Additional route arguments.
	 * @param string   $addon Optional. Addon identifier (for tracking).
	 * @return bool True on success, false on failure.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function register_tool(
		string $route,
		string $name,
		array $methods,
		callable $callback,
		?callable $permission_callback = null,
		array $args = array(),
		string $addon = ''
	): bool {
		// Validate route.
		if ( empty( $route ) || ! is_string( $route ) ) {
			return false;
		}

		// Validate name.
		if ( empty( $name ) || ! is_string( $name ) ) {
			return false;
		}

		// Validate methods.
		if ( empty( $methods ) || ! is_array( $methods ) ) {
			return false;
		}

		// Validate callback.
		if ( ! is_callable( $callback ) ) {
			return false;
		}

		// Default permission callback to JWT auth check.
		if ( null === $permission_callback ) {
			$permission_callback = array( 'DEF_Core_Tools', 'permission_check' );
		}

		// Check if already registered.
		if ( isset( $this->registered_tools[ $route ] ) ) {
			// Allow override if same addon, otherwise log warning.
			$is_same_addon = ! empty( $addon ) && isset( $this->registered_tools[ $route ]['addon'] ) && $this->registered_tools[ $route ]['addon'] === $addon;
			if ( ! $is_same_addon ) {
				error_log( sprintf( 'Digital Employee Framework - Core: Tool %s already registered. Skipping duplicate registration.', $route ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return false;
			}
			// Same addon overriding - allowed, continue registration.
		}

		// Register the tool.
		$this->registered_tools[ $route ] = array(
			'route'               => $route,
			'name'                => $name,
			'methods'             => $methods,
			'callback'            => $callback,
			'permission_callback' => $permission_callback,
			'args'                => $args,
			'addon'               => $addon,
			'version'             => $args['version'] ?? '1.0',
		);

		return true;
	}

	/**
	 * Unregister a tool.
	 *
	 * @param string $route REST API route.
	 * @return bool True on success, false on failure.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function unregister_tool( string $route ): bool {
		if ( isset( $this->registered_tools[ $route ] ) ) {
			unset( $this->registered_tools[ $route ] );
			return true;
		}
		return false;
	}

	/**
	 * Get all registered tools.
	 *
	 * @param string $addon Optional. Filter by addon identifier.
	 * @return array Registered tools.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function get_tools( string $addon = '' ): array {
		if ( empty( $addon ) ) {
			return $this->registered_tools;
		}
		return array_filter(
			$this->registered_tools,
			function ( $tool ) use ( $addon ) {
				return isset( $tool['addon'] ) && $tool['addon'] === $addon;
			}
		);
	}

	/**
	 * Register all tools with WordPress REST API.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function register_all_tools(): void {
		foreach ( $this->registered_tools as $route => $tool ) {
			// Skip if tool is disabled (core routes are always enabled).
			if ( ! $this->is_core_route( $tool['route'] ) && ! $this->is_tool_enabled( $tool['route'] ) ) {
				continue;
			}
			register_rest_route(
				DEF_CORE_API_NAME_SPACE,
				$tool['route'],
				array(
					'methods'             => $tool['methods'],
					'permission_callback' => $tool['permission_callback'],
					'callback'            => $tool['callback'],
					'args'                => $tool['args'],
				)
			);
		}
	}

	/**
	 * Check if a route is a core route (always enabled).
	 *
	 * @param string $route Route to check.
	 * @return bool True if core route, false otherwise.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	private function is_core_route( string $route ): bool {
		$core_routes = array( '/context-token', '/jwks' );
		return in_array( $route, $core_routes, true );
	}

	/**
	 * Get tools status from options.
	 *
	 * @return array<string, int> Array of tool keys with status (1=enabled, 0=disabled).
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	private function get_tools_status(): array {
		$status = get_option( 'def_core_tools_status', array() );
		if ( ! is_array( $status ) ) {
			return array();
		}
		return $status;
	}

	/**
	 * Check if a tool is enabled.
	 *
	 * @param string $route Tool route (e.g., '/tools/wc/add-to-cart').
	 * @return bool True if enabled, false if disabled. Defaults to true if not in list.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function is_tool_enabled( string $route ): bool {
		$status = $this->get_tools_status();
		// If tool is not in the list, default to enabled (1).
		if ( ! isset( $status[ $route ] ) ) {
			return true;
		}
		// Return true if status is 1, false if 0.
		return 1 === (int) $status[ $route ];
	}

	/**
	 * Get all registered tools with their enabled status.
	 *
	 * @return array<string, array{
	 *     route: string,
	 *     name: string,
	 *     methods: string|array<string>,
	 *     enabled: bool,
	 *     is_core: bool,
	 *     addon?: string
	 * }> Registered tools with enabled status. Keys are routes.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function get_tools_with_status(): array {
		$tools_with_status = array();
		foreach ( $this->registered_tools as $route => $tool ) {
			$is_core = $this->is_core_route( $tool['route'] );

			// Key is already the route.
			$tools_with_status[ $route ] = array(
				'route'   => $tool['route'],
				'name'    => $tool['name'],
				'methods' => $tool['methods'],
				'enabled' => $is_core || $this->is_tool_enabled( $tool['route'] ),
				'is_core' => $is_core,
				'addon'   => $tool['addon'] ?? '',
			);
		}
		return $tools_with_status;
	}

	/**
	 * Check if a tool is registered.
	 *
	 * @param string $route REST API route.
	 * @return bool True if registered, false otherwise.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function is_registered( string $route ): bool {
		return isset( $this->registered_tools[ $route ] );
	}
}
