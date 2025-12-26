<?php
/**
 * Plugin Name: Digital Employee Framework — WordPress Bridge
 * Description: Issues a short-lived Signed Context Token (JWT) for authenticated WP users and securely bridges identity to an external app (e.g., Azure app in an iframe). Also exposes a JWKS endpoint for public key verification. Extensible main plugin that supports addons to register additional API tools.
 * Version: 0.2.0
 * Author: a3rev
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package digital-employee-wp-bridge
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DE_WP_BRIDGE_VERSION', '0.2.0' );
define( 'DE_WP_BRIDGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DE_WP_BRIDGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DE_WP_BRIDGE_OPTION_KEYS', 'de_wp_bridge_keys' );
define( 'DE_WP_BRIDGE_OPTION_ALLOWED_ORIGINS', 'de_wp_bridge_allowed_origins' );
define( 'DE_WP_BRIDGE_API_NAME_SPACE', 'a3-ai/v1' );
define( 'DE_WP_BRIDGE_AUDIENCE', 'digital-employee-framework' );

// Main plugin class.
require_once DE_WP_BRIDGE_PLUGIN_DIR . 'includes/class-digital-employee-wp-bridge.php';
