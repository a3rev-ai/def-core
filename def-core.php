<?php
/**
 * Plugin Name: Digital Employees
 * Description: AI-powered Digital Employees for your WordPress site. Customer Chat for visitors, Staff AI for your team, and an intelligent Setup Assistant — all connected to the Digital Employee Framework.
 * Version: 1.2.3
 * Author: a3rev
 * Author URI: https://a3rev.com/
 * Text Domain: digital-employees
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package digital-employees
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
	define( 'DEF_CORE_VERSION', '1.2.3' );
}

define( 'DEF_CORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DEF_CORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DEF_CORE_OPTION_KEYS', 'def_core_keys' );
define( 'DEF_CORE_OPTION_ALLOWED_ORIGINS', 'def_core_allowed_origins' );
define( 'DEF_CORE_API_NAME_SPACE', 'a3-ai/v1' );
define( 'DEF_CORE_AUDIENCE', 'digital-employee-framework' );

// Interim auto-updater: GitHub Releases.
// Remove this block once plugin is live on WordPress.org.
require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$def_core_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/a3rev-ai/def-core/',
	__FILE__,
	'digital-employees'
);

$def_core_update_checker->getVcsApi()->enableReleaseAssets();

// Main plugin class.
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core.php';
