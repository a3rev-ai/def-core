# Addon Development Guide

This guide explains how to create addons for the Digital Employee WordPress Bridge plugin that can register additional API tools.

## Overview

The Digital Employee WordPress Bridge plugin is extensible, allowing addons to register their own API tools that can be called by the Python application. This enables you to add custom functionality without modifying the core plugin.

## Naming Conventions

### Folder Structure
- **Folder Name**: `digital-employee-addon-<integration>`
  - Example: `digital-employee-addon-bbpress`
  - Example: `digital-employee-addon-woocommerce`

### Plugin Header
- **Plugin Name**: `Digital Employee Add-on: <Integration>`
  - Example: `Digital Employee Add-on: bbPress`
  - Example: `Digital Employee Add-on: WooCommerce`

### Code Naming
- **Text Domain**: `digital-employee-addon-<integration>` (lowercase, hyphens)
- **Constants**: `DE_ADDON_<INTEGRATION>_*` (uppercase, underscores)
  - Example: `DE_ADDON_BBPRESS_VERSION`
  - Example: `DE_ADDON_BBPRESS_PLUGIN_DIR`
- **Class Names**: `Digital_Employee_Addon_<Integration>_*` (PascalCase)
  - Example: `Digital_Employee_Addon_BbPress_Tools`
  - Example: `Digital_Employee_Addon_BbPress_Cache`
- **Function Names**: `digital_employee_addon_<integration>_*` (snake_case)
  - Example: `digital_employee_addon_bbpress_load_addons`
- **Addon Identifier**: Just the integration name (e.g., `'bbpress'`, `'woocommerce'`)

## Architecture

### Components

1. **API Registry** (`Digital_Employee_WP_Bridge_API_Registry`)
   - Central registry for all API tools
   - Handles registration and routing
   - Prevents duplicate registrations
   - Uses `DE_WP_BRIDGE_API_NAME_SPACE` constant for namespace

2. **Tool Base Class** (`Digital_Employee_WP_Bridge_Tool_Base`)
   - Abstract base class for tool implementations
   - Provides helper methods and common functionality
   - Ensures consistency across tools
   - **Auto-registers tools** when instantiated (no manual registration needed)

3. **Registration Hook** (`digital_employee_wp_bridge_register_tools`)
   - Action hook fired during tool registration
   - Addons can hook into this for manual registration (if not using base class)

## Creating an Addon

### Step 1: Create Your Addon Plugin Structure

Create a new WordPress plugin with the following structure:

```
digital-employee-addon-<integration>/
├── digital-employee-addon-<integration>.php  # Main plugin file
├── README.md                                  # Documentation
└── includes/
    ├── class-digital-employee-addon-<integration>-tools.php
    └── class-digital-employee-addon-<integration>-cache.php (optional)
```

### Step 2: Create the Main Plugin File

Create the main plugin file following the naming convention:

```php
<?php
/**
 * Plugin Name: Digital Employee Add-on: <Integration>
 * Description: <Integration> addon for Digital Employee Framework WordPress Bridge. Provides <integration> API tools.
 * Version: 0.1.0
 * Author: a3rev
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: digital-employee-wp-bridge
 *
 * @package digital-employee-addon-<integration>
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if main plugin is active.
add_action(
	'admin_notices',
	function () {
		if ( class_exists( 'Digital_Employee_WP_Bridge' ) ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Digital Employee Add-on: <Integration> requires Digital Employee Framework — WordPress Bridge to be installed and activated.', 'digital-employee-addon-<integration>' ); ?></p>
		</div>
		<?php
	}
);

define( 'DE_ADDON_<INTEGRATION>_VERSION', '0.1.0' );
define( 'DE_ADDON_<INTEGRATION>_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DE_ADDON_<INTEGRATION>_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action( 'digital_employee_wp_bridge_inited', 'digital_employee_addon_<integration>_load_addons', 10, 0 );

/**
 * Load addon files.
 */
function digital_employee_addon_<integration>_load_addons(): void {
	// Load includes.
	require_once DE_ADDON_<INTEGRATION>_PLUGIN_DIR . 'includes/class-digital-employee-addon-<integration>-tools.php';
	
	// Load cache class if needed.
	if ( file_exists( DE_ADDON_<INTEGRATION>_PLUGIN_DIR . 'includes/class-digital-employee-addon-<integration>-cache.php' ) ) {
		require_once DE_ADDON_<INTEGRATION>_PLUGIN_DIR . 'includes/class-digital-employee-addon-<integration>-cache.php';
		Digital_Employee_Addon_<Integration>_Cache::init();
	}
}
```

### Step 3: Create a Tool Class

Extend the base class to create your tool. The tool will **automatically register** when instantiated:

```php
<?php
/**
 * Class Digital_Employee_Addon_<Integration>_Tools
 *
 * The <integration> addon tools for Digital Employee Framework WordPress Bridge.
 *
 * @package digital-employee-addon-<integration>
 * @since 0.1.0
 * @version 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Digital_Employee_Addon_<Integration>_Tool
 *
 * Extends the base tool class to provide <integration> functionality.
 *
 * @package digital-employee-addon-<integration>
 * @since 0.1.0
 * @version 0.1.0
 */
class Digital_Employee_Addon_<Integration>_Tools extends Digital_Employee_WP_Bridge_Tool_Base {

	/**
	 * Initialize the tool.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	protected function init(): void {
		$this->name    = __( '<Integration> Tool Name', 'digital-employee-addon-<integration>' );
		$this->route   = '/tools/<integration>/endpoint';
		$this->methods = array( 'GET' );
		$this->addon   = '<integration>'; // Just the integration name
	}

	/**
	 * Check if the tool should be registered.
	 * Override this method for conditional registration.
	 *
	 * @return bool True if tool should be registered, false otherwise.
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	protected function should_register(): bool {
		// Example: Only register if <integration> is active.
		// return function_exists( '<integration>_function' );
		return true; // Default: always register
	}

	/**
	 * Handle the request.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		$user = $this->get_current_user();
		if ( ! $user ) {
			return $this->error_response( 'Unauthorized', 401 );
		}

		// Your tool logic here
		$data = array(
			'message' => 'Hello from <integration> addon!',
			'user_id' => $user->ID,
		);

		return $this->success_response( $data );
	}
}

/**
 * Initialize <integration> tools.
 *
 * Tools will automatically register themselves when instantiated.
 * The should_register() method handles conditional registration.
 *
 * @since 0.2.0
 * @version 0.2.0
 */
add_action(
	'plugins_loaded',
	function () {
		// Instantiate the tool - it will auto-register via base class.
		new Digital_Employee_Addon_<Integration>_Tools();
	},
	20 // Priority 20 to ensure main plugin is loaded first.
);
```

**Important Notes:**
- The base class **automatically registers** the tool when instantiated
- No need to manually call `register()` or use `add_action( 'digital_employee_wp_bridge_register_tools' )`
- Override `should_register()` for conditional registration (e.g., only if a plugin is active)
- The namespace is automatically set to `DE_WP_BRIDGE_API_NAME_SPACE` (you don't need to set it)

### Step 4: Load the Base Class (Required)

The addon needs to load the base class from the main plugin. Add this to your main plugin file before loading your tool classes:

```php
// Load the base tool class from main plugin.
if ( ! class_exists( 'Digital_Employee_WP_Bridge_Tool_Base' ) ) {
	// Try to find the main plugin directory.
	$main_plugin_path = '';
	
	// Method 1: Check if DE_WP_BRIDGE_PLUGIN_DIR is defined.
	if ( defined( 'DE_WP_BRIDGE_PLUGIN_DIR' ) ) {
		$main_plugin_path = DE_WP_BRIDGE_PLUGIN_DIR;
	} else {
		// Method 2: Try to find it relative to this plugin.
		$possible_paths = array(
			plugin_dir_path( dirname( __FILE__ ) ) . 'digital-employee-wp-bridge/',
			WP_PLUGIN_DIR . '/digital-employee-wp-bridge/',
		);
		
		foreach ( $possible_paths as $path ) {
			if ( file_exists( $path . 'digital-employee-wp-bridge.php' ) ) {
				$main_plugin_path = $path;
				break;
			}
		}
	}
	
	if ( ! empty( $main_plugin_path ) ) {
		$base_class_path = $main_plugin_path . 'includes/tools/class-digital-employee-wp-bridge-tool-base.php';
		if ( file_exists( $base_class_path ) ) {
			require_once $base_class_path;
		}
	}
	
	// If still not loaded, show error.
	if ( ! class_exists( 'Digital_Employee_WP_Bridge_Tool_Base' ) ) {
		add_action(
			'admin_notices',
			function () {
				?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Digital Employee Add-on: <Integration> could not load base tool class from main plugin. Please ensure Digital Employee Framework — WordPress Bridge is installed and activated.', 'digital-employee-addon-<integration>' ); ?></p>
				</div>
				<?php
			}
		);
		return;
	}
}
```

## Alternative: Direct Registry Registration

You can also register tools directly without extending the base class:

```php
add_action(
	'digital_employee_wp_bridge_register_tools',
	function () {
		$registry = Digital_Employee_WP_Bridge_API_Registry::instance();
		
		$registry->register_tool(
			'/tools/my-addon/my-tool',     // Route (namespace is automatic)
			__( 'My Custom Tool', 'digital-employee-addon-<integration>' ), // Name
			array( 'GET', 'POST' ),        // HTTP methods
			'my_callback_function',        // Callback function
			null,                          // Permission callback (null = default JWT auth)
			array(),                       // Route arguments
			'<integration>'                // Addon identifier
		);
	}
);

function my_callback_function( \WP_REST_Request $request ): \WP_REST_Response {
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
	
	// Your tool logic here
	return new \WP_REST_Response( array( 'success' => true ), 200 );
}
```

**Note:** When using direct registry registration, the namespace is automatically set to `DE_WP_BRIDGE_API_NAME_SPACE`. You don't need to specify it.

## Helper Methods

The base class provides these helper methods:

### `get_current_user()`
Get the current WordPress user (from JWT token).

```php
$user = $this->get_current_user();
if ( ! $user ) {
	return $this->error_response( 'Unauthorized', 401 );
}
```

### `verify_and_get_user()`
Verify JWT token and get user (for custom auth checks).

```php
$user = $this->verify_and_get_user();
```

### `success_response( $data, $status = 200 )`
Create a success response.

```php
return $this->success_response( array( 'data' => $result ), 200 );
```

### `error_response( $message, $status = 400 )`
Create an error response.

```php
return $this->error_response( 'Something went wrong', 500 );
```

### `should_register()`
Override this method for conditional registration.

```php
protected function should_register(): bool {
	// Only register if required plugin is active.
	return function_exists( 'required_plugin_function' );
}
```

## Best Practices

### 1. Always Check Authentication

```php
$user = $this->get_current_user();
if ( ! $user ) {
	return $this->error_response( 'Unauthorized', 401 );
}
```

### 2. Sanitize Input

```php
$param = sanitize_text_field( $request->get_param( 'param' ) );
$number = intval( $request->get_param( 'number' ) );
```

### 3. Use Proper HTTP Methods

- `GET` for read operations
- `POST` for create operations
- `PUT` for update operations
- `DELETE` for delete operations

### 4. Return Consistent Response Format

```php
// Success
return $this->success_response( array(
	'data' => $result,
	'meta' => array( 'count' => count( $result ) ),
) );

// Error
return $this->error_response( 'Error message', 400 );
```

### 5. Use Caching When Appropriate

```php
$data = Digital_Employee_WP_Bridge_Cache::get_or_set(
	'my_cache_key',
	$user->ID,
	3600, // 1 hour
	function() use ( $user ) {
		// Expensive operation
		return expensive_operation();
	}
);
```

### 6. Conditional Registration

Use `should_register()` to conditionally register tools based on plugin availability:

```php
protected function should_register(): bool {
	// Only register if WooCommerce is active.
	return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
}
```

## Example: Complete Addon

See `examples/addon-example.php` for a complete example addon, or check the `digital-employee-addon-bbpress` plugin for a real-world implementation.

## API Reference

### `Digital_Employee_WP_Bridge_API_Registry::instance()`
Get the registry instance.

### `register_tool( $route, $name, $methods, $callback, $permission_callback, $args, $addon )`
Register a new tool.

**Parameters:**
- `$route` (string) - REST API route (e.g., '/tools/my-tool')
- `$name` (string) - Display name for the tool
- `$methods` (array) - HTTP methods (e.g., ['GET', 'POST'])
- `$callback` (callable) - Callback function
- `$permission_callback` (callable|null) - Permission callback (null = default JWT auth)
- `$args` (array) - Route arguments
- `$addon` (string) - Addon identifier

**Returns:** `bool` - True on success, false on failure

**Note:** The namespace is automatically set to `DE_WP_BRIDGE_API_NAME_SPACE`. You don't need to specify it.

### `get_tools( $addon = '' )`
Get all registered tools, optionally filtered by addon.

### `is_registered( $route )`
Check if a tool is registered (by route).

### `is_tool_enabled( $route )`
Check if a tool is enabled in admin settings.

## Troubleshooting

### Tool Not Registering

1. Check that the main plugin is active
2. Verify the base class is loaded before your tool class
3. Check for PHP errors in debug log
4. Verify route doesn't conflict with existing routes
5. Ensure `should_register()` returns `true` if using conditional registration

### Permission Denied

1. Ensure JWT token is being sent in Authorization header
2. Check that token is valid and not expired
3. Verify user exists in WordPress

### Route Conflicts

If your route conflicts with an existing route, the registry will log a warning and skip registration. Use a unique route prefix for your addon.

### Base Class Not Found

1. Ensure the main plugin is installed and activated
2. Check that `DE_WP_BRIDGE_PLUGIN_DIR` constant is defined
3. Verify the base class file exists in the main plugin

## Support

For questions or issues, please refer to the main plugin documentation or contact support.
