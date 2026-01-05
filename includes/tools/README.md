# API Tools Directory

This directory contains tool implementations and base classes for the Digital Employee Framework - Core plugin.

## Structure

- `class-def-core-tool-base.php` - Abstract base class for tool implementations

## For Module Developers

### Using the Base Class

Extend `DEF_Core_Tool_Base` to create your own tool:

```php
<?php
class My_Module_Tool extends DEF_Core_Tool_Base {
    
    protected function init(): void {
        $this->namespace = 'a3-ai/v1';
        $this->route     = '/tools/my-module/my-tool';
        $this->methods   = array( 'GET', 'POST' );
        $this->module    = 'my-module';
    }
    
    public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
        $user = $this->get_current_user();
        if ( ! $user ) {
            return $this->error_response( 'Unauthorized', 401 );
        }
        
        // Your tool logic here
        $data = array(
            'message' => 'Hello from my module!',
            'user_id' => $user->ID,
        );
        
        return $this->success_response( $data );
    }
}

// Register the tool
add_action( 'def_core_register_tools', function() {
    $tool = new My_Module_Tool( 'my-module' );
    $tool->register();
} );
```

### Using the Registry Directly

You can also register tools directly using the registry:

```php
<?php
add_action( 'def_core_register_tools', function() {
    $registry = DEF_Core_API_Registry::instance();
    
    $registry->register_tool(
        'a3-ai/v1',                    // Namespace
        '/tools/my-module/my-tool',     // Route
        array( 'GET', 'POST' ),        // Methods
        'my_callback_function',         // Callback
        null,                           // Permission callback (null = default JWT auth)
        array(),                        // Args
        'my-module'                     // Module identifier
    );
} );

function my_callback_function( \WP_REST_Request $request ): \WP_REST_Response {
    // Your tool logic here
    return new \WP_REST_Response( array( 'success' => true ), 200 );
}
```

## Best Practices

1. **Use the base class** for consistency and helper methods
2. **Set a unique module identifier** to track your tools
3. **Use proper permission callbacks** - default is JWT auth check
4. **Follow REST API conventions** - use appropriate HTTP methods
5. **Return proper responses** - use `success_response()` and `error_response()` helpers
6. **Handle errors gracefully** - always check for user authentication

## Available Helpers

The base class provides these helper methods:

- `get_current_user()` - Get current WordPress user
- `verify_and_get_user()` - Verify JWT and get user
- `success_response( $data, $status )` - Create success response
- `error_response( $message, $status )` - Create error response

