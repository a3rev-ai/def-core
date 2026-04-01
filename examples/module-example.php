<?php
/**
 * Example Module for Digital Employee Framework - Core
 *
 * This file demonstrates how to create a separate module plugin that registers
 * additional API tools. Use this pattern for private or custom integrations
 * (e.g., proprietary systems, internal APIs) that don't belong in core.
 *
 * For common public plugin integrations (bbPress, WooCommerce extensions, etc.),
 * consider contributing a built-in tool to def-core instead — see
 * includes/tools/ for examples and MODULE_DEVELOPMENT.md for guidance.
 *
 * Follow the naming conventions: def-<integration>
 *
 * @package def-example
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
 * Tools extending DEF_Core_Tool_Base will automatically
 * register themselves when instantiated. No need to manually call register().
 */
class DEF_Example_Tool extends DEF_Core_Tool_Base {

	/**
	 * Initialize the tool.
	 */
	protected function init(): void {
		// Namespace is automatically set to DEF_CORE_API_NAME_SPACE.
		$this->name    = __( 'Example Module Tool', 'def-example' );
		$this->route   = '/tools/example/hello';
		$this->methods = array( 'GET' );
		$this->module  = 'example'; // Just the module name
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
function def_example_custom_tool(): \WP_REST_Response {
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
 * Register module tools.
 *
 * Method 1: Using the base class (automatic registration).
 * Tools extending DEF_Core_Tool_Base will automatically
 * register themselves when instantiated. No need to manually call register().
 */
add_action(
	'plugins_loaded',
	function () {
		// Just instantiate - registration happens automatically!
		// The base class handles all registration logic.
		new DEF_Example_Tool();
	},
	20 // Priority 20 to ensure main plugin is loaded first.
);

/**
 * Method 2: Using the registry directly (manual registration).
 * Use this if you need more control or aren't using the base class.
 *
 * Note: The namespace is automatically set to DEF_CORE_API_NAME_SPACE.
 * You don't need to specify it in register_tool().
 */
add_action(
	'def_core_register_tools',
	function () {
		$registry = DEF_Core_API_Registry::instance();
		$registry->register_tool(
			'/tools/example/custom',                              // Route (namespace is automatic)
			__( 'Example Custom Tool', 'def-example' ), // Name
			array( 'GET' ),                                       // HTTP methods
			'def_example_custom_tool',                          // Callback function
			null,                                                 // Permission callback (null = default JWT auth)
			array(),                                             // Route arguments
			'example'                                             // Module identifier
		);
	}
);
