<?php
/**
 * Plugin Name: Digital Employees
 * Description: AI-powered Digital Employees for your WordPress site. Customer Chat for visitors, Staff AI for your team, and an intelligent Setup Assistant — all connected to the Digital Employee Framework.
 * Version: 8.2.2
 * Author: a3rev
 * Author URI: https://a3rev.com/
 * Text Domain: digital-employees
 * Domain Path: /languages
 * Requires at least: 6.2
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
	define( 'DEF_CORE_VERSION', '8.2.2' );
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

// Inject plugin icon into update/plugin-info screens.
$def_core_update_checker->addResultFilter( function ( $plugin_info ) {
	$icon_base = plugins_url( 'assets/images/', __FILE__ );
	$plugin_info->icons = array(
		'2x' => $icon_base . 'icon-256x256.png',
		'1x' => $icon_base . 'icon-128x128.png',
	);
	return $plugin_info;
} );

// ── The update row keeps up with the release (8.2.2) ────────────────────
// PUC's scheduler checks every 12 hours, and only ONCE AN HOUR on the Plugins
// screen (Scheduler::getEffectiveCheckPeriod gives load-plugins.php 3600s, while
// Dashboard → Updates already gets 60s). On 2026-09-15 four releases shipped in
// one day: the 21:35 update to 8.2.0 ran a check, 8.2.1 published ten minutes
// later, and both production sites were throttled for the rest of that hour —
// the zip went up by hand. These two put the Plugins screen on the same
// 60-second ceiling PUC already accepts for Dashboard → Updates, and close the
// single-update path PUC's own handler skips.
add_action( 'load-plugins.php', function () use ( $def_core_update_checker ) {
	if ( get_transient( 'def_core_puc_recheck' ) ) {
		return;
	}
	set_transient( 'def_core_puc_recheck', 1, MINUTE_IN_SECONDS );
	$def_core_update_checker->checkForUpdates();
} );

// Scheduler::upgraderProcessComplete returns unless $hook_extra carries the BULK
// 'plugins' list, so a single "Update now" — and every background auto-update —
// left the row still offering the version that had just been installed.
add_action( 'upgrader_process_complete', function ( $upgrader, $hook_extra ) use ( $def_core_update_checker ) {
	if ( ! is_array( $hook_extra ) || isset( $hook_extra['plugins'] )
		|| DEF_CORE_PLUGIN_NAME !== ( $hook_extra['plugin'] ?? '' ) ) {
		return;
	}
	$def_core_update_checker->resetUpdateState();
	$def_core_update_checker->checkForUpdates();
}, 10, 2 );

// Main plugin class.
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core.php';
