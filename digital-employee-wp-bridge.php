<?php
/**
 * Plugin Name: Digital Employee Framework - WordPress Bridge
 * Description: Issues a short-lived Signed Context Token (JWT) for authenticated WP users and securely bridges identity to an external app (e.g., Azure app in an iframe). Also exposes a JWKS endpoint for public key verification. Extensible main plugin that supports addons to register additional API tools.
 * Version: 1.0.1
 * Author: a3rev
 * Author URI: https://a3rev.com/
 * Text Domain: digital-employee-wp-bridge
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Update URI: digital-employee-wp-bridge
 * License: This software is under commercial license and copyright to A3 Revolution Software Development team
 *
 * @package digital-employee-wp-bridge
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants for upgrade and metadata.
if ( ! defined( 'DE_WP_BRIDGE_PLUGIN_NAME' ) ) {
	define( 'DE_WP_BRIDGE_PLUGIN_NAME', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'DE_WP_BRIDGE_KEY' ) ) {
	define( 'DE_WP_BRIDGE_KEY', 'digital-employee-wp-bridge' );
}
if ( ! defined( 'DE_WP_BRIDGE_VERSION' ) ) {
	define( 'DE_WP_BRIDGE_VERSION', '1.0.1' );
}

define( 'DE_WP_BRIDGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DE_WP_BRIDGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DE_WP_BRIDGE_OPTION_KEYS', 'de_wp_bridge_keys' );
define( 'DE_WP_BRIDGE_OPTION_ALLOWED_ORIGINS', 'de_wp_bridge_allowed_origins' );
define( 'DE_WP_BRIDGE_API_NAME_SPACE', 'a3-ai/v1' );
define( 'DE_WP_BRIDGE_AUDIENCE', 'digital-employee-framework' );

// Include upgrade handler (CloudFront-based auto-update like other premium plugins).
if ( file_exists( __DIR__ . '/upgrade/class-digital-employee-wp-bridge-upgrade.php' ) ) {
	require_once __DIR__ . '/upgrade/class-digital-employee-wp-bridge-upgrade.php';
}

// Require a3rev Dashboard requirement (for auto-updates & support).
if ( ! class_exists( 'a3rev_Dashboard_Plugin_Requirement' ) ) {
	require_once __DIR__ . '/a3rev-dashboard-requirement.php';
}

// Main plugin class.
require_once DE_WP_BRIDGE_PLUGIN_DIR . 'includes/class-digital-employee-wp-bridge.php';
