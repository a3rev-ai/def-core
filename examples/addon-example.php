<?php
/**
 * Example Addon for Digital Employee WordPress Bridge
 *
 * This file demonstrates how to create an addon that registers additional API tools.
 * Follow the naming conventions: digital-employee-addon-<integration>
 *
 * @package digital-employee-addon-example
 * @version 1.0.0
 *
 * @phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName
 * @phpcs:ignoreFile Generic.Files.OneObjectStructurePerFile.MultipleFound
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Example: Using the Base Class (Recommended)
 *
 * Tools extending Digital_Employee_WP_Bridge_Tool_Base will automatically
 * register themselves when instantiated. No need to manually call register().
 */
class Example_Addon_Tool extends Digital_Employee_WP_Bridge_Tool_Base {

	/**
	 * Initialize the tool.
	 */
	protected function init(): void {
		// Namespace is automatically set to DE_WP_BRIDGE_API_NAME_SPACE.
		$this->name    = __( 'Example Addon Tool', 'digital-employee-addon-example' );
		$this->route   = '/tools/example/hello';
		$this->methods = array( 'GET' );
		$this->addon   = 'example'; // Just the integration name
	}

	/**
	 * Check if the tool should be registered.
	 * Override this method for conditional registration.
	 *
	 * @return bool True if tool should be registered, false otherwise.
	 */
	protected function should_register(): bool {
		// Example: Only register if a required plugin is active.
		// return class_exists( 'Required_Plugin' );
		return true; // Default: always register
	}

	/**
	 * Handle the request.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The response object.
	 */
	public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		$user = $this->get_current_user();
		if ( ! $user ) {
			return $this->error_response( 'Unauthorized', 401 );
		}

		$name = $request->get_param( 'name' ) ?? 'World';

		return $this->success_response(
			array(
				'message' => sprintf( 'Hello, %s!', sanitize_text_field( $name ) ),
				'user'    => array(
					'id'    => $user->ID,
					'name'  => $user->display_name,
					'email' => $user->user_email,
				),
			)
		);
	}
}

/**
 * Example: Using the Registry Directly
 *
 * Use this if you need more control or aren't using the base class.
 *
 * @param \WP_REST_Request $request The request object.
 * @return \WP_REST_Response The response object.
 */
function example_addon_custom_tool( \WP_REST_Request $request ): \WP_REST_Response {
	$user = wp_get_current_user();
	if ( ! $user || 0 === $user->ID ) {
		return new \WP_REST_Response(
			array(
				'error'   => true,
				'message' => 'Unauthorized',
			),
			401
		);
	}

	$data = array(
		'timestamp' => current_time( 'mysql' ),
		'user_id'   => $user->ID,
		'message'   => 'This is a custom tool registered directly via the registry',
	);

	return new \WP_REST_Response( $data, 200 );
}

/**
 * Register addon tools.
 *
 * Method 1: Using the base class (automatic registration).
 * Tools extending Digital_Employee_WP_Bridge_Tool_Base will automatically
 * register themselves when instantiated. No need to manually call register().
 */
add_action(
	'plugins_loaded',
	function () {
		// Just instantiate - registration happens automatically!
		// The base class handles all registration logic.
		new Example_Addon_Tool();
	},
	20 // Priority 20 to ensure main plugin is loaded first.
);

/**
 * Method 2: Using the registry directly (manual registration).
 * Use this if you need more control or aren't using the base class.
 *
 * Note: The namespace is automatically set to DE_WP_BRIDGE_API_NAME_SPACE.
 * You don't need to specify it in register_tool().
 */
add_action(
	'digital_employee_wp_bridge_register_tools',
	function () {
		$registry = Digital_Employee_WP_Bridge_API_Registry::instance();
		$registry->register_tool(
			'/tools/example/custom',                              // Route (namespace is automatic)
			__( 'Example Custom Tool', 'digital-employee-addon-example' ), // Name
			array( 'GET' ),                                       // HTTP methods
			'example_addon_custom_tool',                          // Callback function
			null,                                                 // Permission callback (null = default JWT auth)
			array(),                                             // Route arguments
			'example'                                             // Addon identifier
		);
	}
);
