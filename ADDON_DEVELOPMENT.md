# Addon Development Guide

This guide explains how to create addons for the Digital Employee WordPress Bridge plugin that can register additional API tools.

## Overview

The Digital Employee WordPress Bridge plugin is now extensible, allowing addons to register their own API tools that can be called by the Python application. This enables you to add custom functionality without modifying the core plugin.

## Architecture

### Components

1. **API Registry** (`Digital_Employee_WP_Bridge_API_Registry`)
   - Central registry for all API tools
   - Handles registration and routing
   - Prevents duplicate registrations

2. **Tool Base Class** (`Digital_Employee_WP_Bridge_Tool_Base`)
   - Abstract base class for tool implementations
   - Provides helper methods and common functionality
   - Ensures consistency across tools

3. **Registration Hook** (`digital_employee_wp_bridge_register_tools`)
   - Action hook fired during REST API initialization
   - Addons hook into this to register their tools

## Creating an Addon

### Step 1: Create Your Addon Plugin

Create a new WordPress plugin file:

```php
<?php
/**
 * Plugin Name: My Custom Addon
 * Description: Adds custom API tools to Digital Employee WordPress Bridge
 * Version: 1.0.0
 * Requires Plugins: digital-employee-wp-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Check if main plugin is active
if ( ! class_exists( 'Digital_Employee_WP_Bridge' ) ) {
    return;
}

// Your addon code here
```

### Step 2: Create a Tool Class

Extend the base class to create your tool:

```php
<?php
class My_Custom_Tool extends Digital_Employee_WP_Bridge_Tool_Base {
    
    protected function init(): void {
        $this->namespace = 'a3-ai/v1';
        $this->route = '/tools/my-addon/my-tool';
        $this->methods = array( 'GET', 'POST' );
        $this->addon = 'my-addon';
    }
    
    public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
        $user = $this->get_current_user();
        if ( ! $user ) {
            return $this->error_response( 'Unauthorized', 401 );
        }
        
        // Your tool logic here
        $data = array(
            'message' => 'Hello from my addon!',
            'user_id' => $user->ID,
        );
        
        return $this->success_response( $data );
    }
}
```

### Step 3: Register Your Tool

Hook into the registration action:

```php
add_action( 'digital_employee_wp_bridge_register_tools', function() {
    $tool = new My_Custom_Tool( 'my-addon' );
    $tool->register();
} );
```

## Alternative: Direct Registry Registration

You can also register tools directly without extending the base class:

```php
add_action( 'digital_employee_wp_bridge_register_tools', function() {
    $registry = Digital_Employee_WP_Bridge_API_Registry::instance();
    
    $registry->register_tool(
        'a3-ai/v1',                    // Namespace
        '/tools/my-addon/my-tool',     // Route
        array( 'GET', 'POST' ),        // HTTP methods
        'my_callback_function',        // Callback function
        null,                          // Permission callback (null = default JWT auth)
        array(),                      // Route arguments
        'my-addon'                    // Addon identifier
    );
} );

function my_callback_function( \WP_REST_Request $request ): \WP_REST_Response {
    $user = wp_get_current_user();
    if ( ! $user || 0 === $user->ID ) {
        return new \WP_REST_Response(
            array( 'error' => true, 'message' => 'Unauthorized' ),
            401
        );
    }
    
    // Your tool logic here
    return new \WP_REST_Response( array( 'success' => true ), 200 );
}
```

## Helper Methods

The base class provides these helper methods:

### `get_current_user()`
Get the current WordPress user.

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

## Example: Complete Addon

See `examples/addon-example.php` for a complete example addon.

## API Reference

### `Digital_Employee_WP_Bridge_API_Registry::instance()`
Get the registry instance.

### `register_tool( $namespace, $route, $methods, $callback, $permission_callback, $args, $addon )`
Register a new tool.

**Parameters:**
- `$namespace` (string) - REST API namespace (e.g., 'a3-ai/v1')
- `$route` (string) - REST API route (e.g., '/tools/my-tool')
- `$methods` (array) - HTTP methods (e.g., ['GET', 'POST'])
- `$callback` (callable) - Callback function
- `$permission_callback` (callable|null) - Permission callback (null = default JWT auth)
- `$args` (array) - Route arguments
- `$addon` (string) - Addon identifier

**Returns:** `bool` - True on success, false on failure

### `get_tools( $addon = '' )`
Get all registered tools, optionally filtered by addon.

### `is_registered( $namespace, $route )`
Check if a tool is registered.

## Troubleshooting

### Tool Not Registering

1. Check that the main plugin is active
2. Verify the hook is being called: `digital_employee_wp_bridge_register_tools`
3. Check for PHP errors in debug log
4. Verify route doesn't conflict with existing routes

### Permission Denied

1. Ensure JWT token is being sent in Authorization header
2. Check that token is valid and not expired
3. Verify user exists in WordPress

### Route Conflicts

If your route conflicts with an existing route, the registry will log a warning and skip registration. Use a unique namespace or route prefix for your addon.

## Support

For questions or issues, please refer to the main plugin documentation or contact support.

