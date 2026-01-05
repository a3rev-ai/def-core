<?php
/**
 * Plugin Name: Digital Employee Framework - Core
 * Description: Issues a short-lived Signed Context Token (JWT) for authenticated WP users and securely bridges identity to an external app (e.g., Azure app in an iframe). Also exposes a JWKS endpoint for public key verification. Extensible main plugin that supports modules to register additional API tools.
 * Version: 1.0.0
 * Author: a3rev
 * Author URI: https://a3rev.com/
 * Text Domain: def-core
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Update URI: def-core
 * License: This software is under commercial license and copyright to A3 Revolution Software Development team
 *
 * @package def-core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants for upgrade and metadata.
if ( ! defined( 'DEF_CORE_PLUGIN_NAME' ) ) {
	define( 'DEF_CORE_PLUGIN_NAME', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'DEF_CORE_KEY' ) ) {
	define( 'DEF_CORE_KEY', 'def-core' );
}
if ( ! defined( 'DEF_CORE_VERSION' ) ) {
	define( 'DEF_CORE_VERSION', '1.0.0' );
}

define( 'DEF_CORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DEF_CORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DEF_CORE_OPTION_KEYS', 'def_core_keys' );
define( 'DEF_CORE_OPTION_ALLOWED_ORIGINS', 'def_core_allowed_origins' );
define( 'DEF_CORE_API_NAME_SPACE', 'a3-ai/v1' );
define( 'DEF_CORE_AUDIENCE', 'digital-employee-framework' );

// Include upgrade handler (CloudFront-based auto-update like other premium plugins).
if ( file_exists( __DIR__ . '/upgrade/class-def-core-upgrade.php' ) ) {
	require_once __DIR__ . '/upgrade/class-def-core-upgrade.php';
}

// Require a3rev Dashboard requirement (for auto-updates & support).
if ( ! class_exists( 'a3rev_Dashboard_Plugin_Requirement' ) ) {
	require_once __DIR__ . '/a3rev-dashboard-requirement.php';
}

// Main plugin class.
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core.php';
