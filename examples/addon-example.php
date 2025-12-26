<?php
/**
 * Example Addon for Digital Employee WordPress Bridge
 *
 * This file demonstrates how to create an addon that registers additional API tools.
 *
 * @package digital-employee-wp-bridge-addon-example
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
 * Example: Using the Base Class
 */
class Example_Addon_Tool extends Digital_Employee_WP_Bridge_Tool_Base {

	/**
	 * Initialize the tool.
	 */
	protected function init(): void {
		// Namespace is automatically set to DE_WP_BRIDGE_API_NAME_SPACE.
		$this->name    = __( 'Example Addon Tool', 'digital-employee-wp-bridge' );
		$this->route   = '/tools/example/hello';
		$this->methods = array( 'GET' );
		$this->addon   = 'example-addon';
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
 */
add_action(
	'digital_employee_wp_bridge_register_tools',
	function () {
		// Method 1: Using the base class.
		$tool = new Example_Addon_Tool( 'example-addon' );
		$tool->register();

		// Method 2: Using the registry directly.
		$registry = Digital_Employee_WP_Bridge_API_Registry::instance();
		$registry->register_tool(
			'/tools/example/custom',
			__( 'Example Custom Tool', 'digital-employee-wp-bridge' ),
			array( 'GET' ),
			'example_addon_custom_tool',
			null, // Use default JWT auth check.
			array(),
			'example-addon'
		);
	}
);
