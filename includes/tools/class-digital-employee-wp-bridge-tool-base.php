<?php
/**
 * Abstract Base Class for API Tools
 *
 * Provides a base class for tool implementations that addons can extend.
 *
 * @package digital-employee-wp-bridge
 * @since 0.2.0
 * @version 0.2.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract Class Digital_Employee_WP_Bridge_Tool_Base
 *
 * Base class for API tool implementations.
 *
 * @package digital-employee-wp-bridge
 * @since 0.2.0
 * @version 0.2.0
 */
abstract class Digital_Employee_WP_Bridge_Tool_Base {

	/**
	 * Tool namespace (informational only, not used for registration).
	 * All tools are registered under DE_WP_BRIDGE_API_NAME_SPACE.
	 *
	 * @var string
	 */
	protected $namespace = DE_WP_BRIDGE_API_NAME_SPACE;

	/**
	 * Tool display name.
	 *
	 * @var string
	 */
	protected $name = '';

	/**
	 * Tool route.
	 *
	 * @var string
	 */
	protected $route = '';

	/**
	 * HTTP methods supported.
	 *
	 * @var string[]
	 */
	protected $methods = array( 'GET' );

	/**
	 * Permission callback.
	 *
	 * @var callable|null
	 */
	protected $permission_callback = null;

	/**
	 * Route arguments.
	 *
	 * @var array
	 */
	protected $args = array();

	/**
	 * Addon identifier.
	 *
	 * @var string
	 */
	protected $addon = '';

	/**
	 * Whether this tool has been registered.
	 *
	 * @var bool
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	private $registered = false;

	/**
	 * Constructor.
	 *
	 * @param string $addon Addon identifier.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function __construct( string $addon = '' ) {
		$this->addon = $addon;
		$this->init();
		$this->auto_register();
	}

	/**
	 * Check if the tool should be registered.
	 * Override this method in child classes to add conditional registration logic.
	 *
	 * @return bool True if tool should be registered, false otherwise.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	protected function should_register(): bool {
		return true;
	}

	/**
	 * Automatically register the tool.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	private function auto_register(): void {
		// Prevent double registration.
		if ( $this->registered ) {
			return;
		}

		// Hook into the registration action.
		add_action(
			'digital_employee_wp_bridge_register_tools',
			function () {
				if ( ! $this->registered && $this->should_register() ) {
					$this->register();
					$this->registered = true;
				}
			}
		);

		// If the hook has already fired, register immediately.
		if ( did_action( 'digital_employee_wp_bridge_register_tools' ) ) {
			if ( $this->should_register() ) {
				$this->register();
				$this->registered = true;
			}
		}
	}

	/**
	 * Initialize the tool.
	 * Override this method in child classes to set up the tool.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	abstract protected function init(): void;

	/**
	 * Handle the request.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The response object.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	abstract public function handle_request( \WP_REST_Request $request ): \WP_REST_Response;

	/**
	 * Register the tool with the API registry.
	 *
	 * @return bool True on success, false on failure.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function register(): bool {
		if ( empty( $this->route ) ) {
			return false;
		}
		if ( empty( $this->name ) ) {
			return false;
		}

		return Digital_Employee_WP_Bridge_API_Registry::instance()->register_tool(
			$this->route,
			$this->name,
			$this->methods,
			array( $this, 'handle_request' ),
			$this->permission_callback,
			$this->args,
			$this->addon
		);
	}

	/**
	 * Get the current user.
	 *
	 * @return \WP_User|null The user object or null if not found.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	protected function get_current_user(): ?\WP_User {
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return null;
		}
		return $user;
	}

	/**
	 * Verify JWT and get user.
	 *
	 * @return \WP_User|null The user object or null if not authenticated.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	protected function verify_and_get_user(): ?\WP_User {
		return Digital_Employee_WP_Bridge_Tools::verify_and_get_user();
	}

	/**
	 * Create a success response.
	 *
	 * @param mixed $data Response data.
	 * @param int   $status HTTP status code.
	 * @return \WP_REST_Response The response object.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	protected function success_response( $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status );
	}

	/**
	 * Create an error response.
	 *
	 * @param string $message Error message.
	 * @param int    $status HTTP status code.
	 * @return \WP_REST_Response The response object.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	protected function error_response( string $message, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'error'   => true,
				'message' => $message,
			),
			$status
		);
	}
}
