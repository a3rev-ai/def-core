# Module Development Guide

This guide explains how to create **module plugins** for the Digital Employee Framework - Core plugin that register additional API tools.

## When to Build a Module vs a Built-in Tool

def-core includes **built-in tools** that conditionally register when their dependencies are detected (e.g., WooCommerce, bbPress). These are part of the core plugin and require no additional installation.

Build a **separate module plugin** when:

- The integration is **private or custom to your business** (e.g., a proprietary license system, an internal API)
- The tool depends on **non-public or commercial plugins** that most def-core users won't have (e.g., WooCommerce Subscriptions)
- You need to **distribute the tool independently** from def-core (e.g., sold separately, different release cycle)

For a real-world example of a module plugin, see [def-wc-subscriptions](https://github.com/a3rev-ai/def-wc-subscriptions) — a WooCommerce Subscriptions integration built as a separate module because WC Subscriptions is a commercial plugin.

If your integration is for a **widely-used free/open-source plugin** (bbPress, etc.), consider contributing a built-in tool to def-core instead — submit a PR at [github.com/a3rev-ai/def-core](https://github.com/a3rev-ai/def-core). Built-in tools use the same `DEF_Core_Tool_Base` class with `should_register()` for conditional activation.

## Overview

The Digital Employee Framework - Core plugin is extensible, allowing modules to register their own API tools that can be called by the Python application. This enables you to add custom functionality without modifying the core plugin.

## Naming Conventions

### Folder Structure
- **Folder Name**: `def-<integration>`
  - Example: `def-bbpress`
  - Example: `def-woocommerce`

### Plugin Header
- **Plugin Name**: `Digital Employee - <Integration>`
  - Example: `Digital Employee - bbPress`
  - Example: `Digital Employee - WooCommerce`

### Code Naming
- **Text Domain**: `def-<integration>` (lowercase, hyphens)
- **Constants**: `DEF_MODULE_<INTEGRATION>_*` (uppercase, underscores)
  - Example: `DEF_MODULE_BBPRESS_VERSION`
  - Example: `DEF_MODULE_BBPRESS_PLUGIN_DIR`
- **Class Names**: `DEF_<Integration>_*` (PascalCase)
  - Example: `DEF_BbPress_Tool`
  - Example: `DEF_BbPress_Cache`
- **Function Names**: `def_module_<integration>_*` (snake_case)
  - Example: `def_module_bbpress_load`
- **Module Identifier**: Just the integration name (e.g., `'bbpress'`, `'woocommerce'`)

## Architecture

### Components

1. **API Registry** (`DEF_Core_API_Registry`)
   - Central registry for all API tools
   - Handles registration and routing
   - Prevents duplicate registrations
   - Uses `DEF_CORE_API_NAME_SPACE` constant for namespace

2. **Tool Base Class** (`DEF_Core_Tool_Base`)
   - Abstract base class for tool implementations
   - Provides helper methods and common functionality
   - Ensures consistency across tools
   - **Auto-registers tools** when instantiated (no manual registration needed)

3. **Registration Hook** (`def_core_register_tools`)
   - Action hook fired during tool registration
   - Modules can hook into this for manual registration (if not using base class)

## Creating an Module

### Step 1: Create Your Module Plugin Structure

Create a new WordPress plugin with the following structure:

```
def-<integration>/
├── def-<integration>.php  # Main plugin file
├── README.md                                  # Documentation
└── includes/
    ├── class-def-<integration>-tool.php
    └── class-def-<integration>-cache.php (optional)
```

### Step 2: Create the Main Plugin File

Create the main plugin file following the naming convention:

```php
<?php
/**
 * Plugin Name: Digital Employees - <Integration>
 * Description: <Integration> module for Digital Employee Framework - Core. Provides <integration> API tools.
 * Version: 0.1.0
 * Author: a3rev
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: def-core
 *
 * @package def-<integration>
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if main plugin is active.
add_action(
	'admin_notices',
	function () {
		if ( class_exists( 'DEF_Core' ) ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Digital Employees - <Integration> requires Digital Employee Framework - Core to be installed and activated.', 'def-<integration>' ); ?></p>
		</div>
		<?php
	}
);

define( 'DEF_MODULE_<INTEGRATION>_VERSION', '0.1.0' );
define( 'DEF_MODULE_<INTEGRATION>_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DEF_MODULE_<INTEGRATION>_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action( 'def_core_inited', 'def_module_<integration>_load', 10, 0 );

/**
 * Load module files.
 */
function def_module_<integration>_load(): void {
	// Load includes.
	require_once DEF_MODULE_<INTEGRATION>_PLUGIN_DIR . 'includes/class-def-<integration>-tool.php';
	
	// Load cache class if needed.
	if ( file_exists( DEF_MODULE_<INTEGRATION>_PLUGIN_DIR . 'includes/class-def-<integration>-cache.php' ) ) {
		require_once DEF_MODULE_<INTEGRATION>_PLUGIN_DIR . 'includes/class-def-<integration>-cache.php';
		DEF_<Integration>_Cache::init();
	}
}
```

### Step 3: Create a Tool Class

Extend the base class to create your tool. The tool will **automatically register** when instantiated:

```php
<?php
/**
 * Class DEF_<Integration>_Tool
 *
 * The <integration> module tools for Digital Employee Framework - Core.
 *
 * @package def-<integration>
 * @since 0.1.0
 * @version 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DEF_<Integration>_Tool
 *
 * Extends the base tool class to provide <integration> functionality.
 *
 * @package def-<integration>
 * @since 0.1.0
 * @version 0.1.0
 */
class DEF_<Integration>_Tool extends DEF_Core_Tool_Base {

	/**
	 * Initialize the tool.
	 *
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	protected function init(): void {
		$this->name    = __( '<Integration> Tool Name', 'def-<integration>' );
		$this->route   = '/tools/<integration>/endpoint';
		$this->methods = array( 'GET' );
		$this->module  = '<integration>'; // Just the integration name
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
			'message' => 'Hello from <integration> module!',
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
		new DEF_<Integration>_Tool();
	},
	20 // Priority 20 to ensure main plugin is loaded first.
);
```

**Important Notes:**
- The base class **automatically registers** the tool when instantiated
- No need to manually call `register()` or use `add_action( 'def_core_register_tools' )`
- Override `should_register()` for conditional registration (e.g., only if a plugin is active)
- The namespace is automatically set to `DEF_CORE_API_NAME_SPACE` (you don't need to set it)

### Step 4: Load the Base Class (Required)

The module needs to load the base class from the main plugin. Add this to your main plugin file before loading your tool classes:

```php
// Check if main plugin is active and load required files.
add_action(
	'admin_notices',
	function () {
		if ( class_exists( 'DEF_Core' ) ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Digital Employee - <Integration> requires Digital Employee Framework - Core to be installed and activated.', 'def-<integration>' ); ?></p>
		</div>
		<?php
	}
);
```

## Alternative: Direct Registry Registration

You can also register tools directly without extending the base class:

```php
add_action(
	'def_core_register_tools',
	function () {
		$registry = DEF_Core_API_Registry::instance();
		
		$registry->register_tool(
			'/tools/my-module/my-tool',     // Route (namespace is automatic)
			__( 'My Custom Tool', 'def-<integration>' ), // Name
			array( 'GET', 'POST' ),        // HTTP methods
			'my_callback_function',        // Callback function
			null,                          // Permission callback (null = default JWT auth)
			array(),                       // Route arguments
			'<integration>'                // Module identifier
		);
	}
);

function my_callback_function(): \WP_REST_Response {
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

**Note:** When using direct registry registration, the namespace is automatically set to `DEF_CORE_API_NAME_SPACE`. You don't need to specify it.

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
$data = DEF_Core_Cache::get_or_set(
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

## Example: Complete Module

See [def-wc-subscriptions](https://github.com/a3rev-ai/def-wc-subscriptions) for a complete real-world module plugin example.

## API Reference

### `DEF_Core_API_Registry::instance()`
Get the registry instance.

### `register_tool( $route, $name, $methods, $callback, $permission_callback, $args, $module )`
Register a new tool.

**Parameters:**
- `$route` (string) - REST API route (e.g., '/tools/my-tool')
- `$name` (string) - Display name for the tool
- `$methods` (array) - HTTP methods (e.g., ['GET', 'POST'])
- `$callback` (callable) - Callback function
- `$permission_callback` (callable|null) - Permission callback (null = default JWT auth)
- `$args` (array) - Route arguments
- `$module` (string) - Module identifier

**Returns:** `bool` - True on success, false on failure

**Note:** The namespace is automatically set to `DEF_CORE_API_NAME_SPACE`. You don't need to specify it.

### `get_tools( $module = '' )`
Get all registered tools, optionally filtered by module.

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

If your route conflicts with an existing route, the registry will log a warning and skip registration. Use a unique route prefix for your module.

### Base Class Not Found

1. Ensure the main plugin is installed and activated
2. Check that `DEF_CORE_PLUGIN_DIR` constant is defined
3. Verify the base class file exists in the main plugin

## Support

For questions or issues, please refer to the main plugin documentation or contact support.
