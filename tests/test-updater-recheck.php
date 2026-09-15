<?php
/**
 * The update row keeps up with the release (8.2.2).
 *
 * def-core's LIVE updater is YahnisElsts' plugin-update-checker, built in
 * def-core.php. Its scheduler checks every 12 hours and only once an hour on the
 * Plugins screen, and its own upgrader_process_complete handler returns unless
 * the upgrade named the BULK 'plugins' list — so a single "Update now" and every
 * background auto-update left the row offering the version just installed.
 *
 * Verifies:
 *  - The Plugins screen re-checks at most once a minute: two loads inside the
 *    window make ONE call; a load after it expires makes another.
 *  - A single/auto update OF THIS PLUGIN resets the stored state and re-checks.
 *  - A bulk update is left to PUC's own handler, and another plugin's update
 *    (or a payload with no plugin at all) touches nothing.
 *
 * Runs the SHIPPED lines, sliced out of def-core.php by the markers the block
 * carries, rather than a copy that can drift — the same rule
 * tests/browser/extract.js follows for the console's JS. def-core.php cannot be
 * required here: it boots the whole plugin.
 *
 * Runs standalone with WP stubs (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

// A transient store with a CLOCK, declared before the stubs so these win: the
// throttle is a expiry rule, and a store that never expires cannot show it.
$GLOBALS['now']        = 1000000;
$GLOBALS['transients'] = array();

function get_transient( string $key ) {
	$entry = $GLOBALS['transients'][ $key ] ?? null;
	if ( null === $entry || ( $entry['expires'] > 0 && $entry['expires'] <= $GLOBALS['now'] ) ) {
		return false;
	}
	return $entry['value'];
}
function set_transient( string $key, $value, int $expiration = 0 ): bool {
	$GLOBALS['transients'][ $key ] = array(
		'value'   => $value,
		'expires' => $expiration > 0 ? $GLOBALS['now'] + $expiration : 0,
	);
	return true;
}
function delete_transient( string $key ): bool {
	unset( $GLOBALS['transients'][ $key ] );
	return true;
}

require_once __DIR__ . '/wp-stubs.php';

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DEF_CORE_PLUGIN_NAME' ) ) {
	define( 'DEF_CORE_PLUGIN_NAME', 'digital-employees/def-core.php' );
}

// Capture the two callbacks instead of registering them.
$hooks = array();
function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['hooks'][ $hook ] = $callback;
}

/** Stands in for PUC's update checker: counts what the block asks it to do. */
class PUC_Spy {
	public int $checks = 0;
	public int $resets = 0;

	public function checkForUpdates() {
		$this->checks++;
	}
	public function resetUpdateState(): void {
		$this->resets++;
	}
}

$def_core_update_checker = new PUC_Spy();

// ── Slice the shipped block and run it ──────────────────────────────────

$src   = (string) file_get_contents( dirname( __DIR__ ) . '/def-core.php' );
$start = strpos( $src, '// ── The update row keeps up with the release' );
$end   = strpos( $src, '// Main plugin class.' );
if ( false === $start || false === $end || $end <= $start ) {
	echo "  FAIL: def-core.php: the update-recheck block's markers were not found\n";
	echo "\n0 passed, 1 failed\n";
	exit( 1 );
}
eval( substr( $src, $start, $end - $start ) );

// ── Tiny assertion harness ──────────────────────────────────────────────

$pass = 0;
$fail = 0;

function assert_same( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
		echo "[$pass] $label\n";
	} else {
		$fail++;
		echo '  FAIL: ' . $label . ' (expected ' . var_export( $expected, true )
			. ', got ' . var_export( $actual, true ) . ")\n";
	}
}

assert_same( true, isset( $hooks['load-plugins.php'] ), 'the Plugins screen carries a re-check' );
assert_same( true, isset( $hooks['upgrader_process_complete'] ), 'a completed upgrade is watched' );

$on_plugins_screen = $hooks['load-plugins.php'];
$on_upgrade        = $hooks['upgrader_process_complete'];

// ── The Plugins screen: at most one check a minute ──────────────────────

$on_plugins_screen();
assert_same( 1, $def_core_update_checker->checks, 'a first load of the Plugins screen checks for a new release' );

$GLOBALS['now'] += 30;
$on_plugins_screen();
assert_same( 1, $def_core_update_checker->checks, 'a second load 30 seconds later does not check again' );

$GLOBALS['now'] += 31;
$on_plugins_screen();
assert_same( 2, $def_core_update_checker->checks, 'a load after the minute is up checks again' );

// ── The single / auto update path PUC skips ─────────────────────────────

$def_core_update_checker = new PUC_Spy();
$hooks                   = array();
eval( substr( $src, $start, $end - $start ) );
$on_upgrade = $hooks['upgrader_process_complete'];

$on_upgrade( null, array( 'action' => 'update', 'type' => 'plugin', 'plugin' => DEF_CORE_PLUGIN_NAME ) );
assert_same( 1, $def_core_update_checker->resets, 'updating this plugin on its own forgets the stored update' );
assert_same( 1, $def_core_update_checker->checks, 'and asks GitHub again, so the row stops offering what was just installed' );

$on_upgrade( null, array( 'action' => 'update', 'type' => 'plugin', 'plugin' => 'akismet/akismet.php' ) );
assert_same( 1, $def_core_update_checker->resets, 'another plugin updating on its own resets nothing' );
assert_same( 1, $def_core_update_checker->checks, 'and triggers no check' );

$on_upgrade( null, array(
	'action'  => 'update',
	'type'    => 'plugin',
	'bulk'    => true,
	'plugins' => array( DEF_CORE_PLUGIN_NAME ),
) );
assert_same( 1, $def_core_update_checker->resets, 'a bulk update is left to PUC\'s own handler' );

$on_upgrade( null, array( 'action' => 'install', 'type' => 'plugin' ) );
assert_same( 1, $def_core_update_checker->resets, 'an install names no plugin and resets nothing' );

// ── The release asset is REQUIRED, not preferred ────────────────────────
// A source pin, not a behaviour test: the call that matters is PUC's own, and
// what we own is the argument. With PREFER (the library default) an asset-less
// release still yields a reference carrying GitHub's source zipball, and the
// tag/branch strategies hand back the same archive when the release yields none
// — so both lines have to hold together.

assert_same(
	true,
	(bool) preg_match(
		'/enableReleaseAssets\(\s*null,\s*\$def_core_vcs_api::REQUIRE_RELEASE_ASSETS\s*\)/',
		$src
	),
	'the updater REQUIRES the release asset, so a release with no zip is not an update'
);

assert_same(
	true,
	(bool) preg_match(
		'/array_intersect_key\(\s*\$strategies,\s*array\(\s*\$def_core_vcs_api::STRATEGY_LATEST_RELEASE\s*=>\s*true\s*\)\s*\)/',
		$src
	),
	'and it looks ONLY at the latest release — no tag or branch archive behind it'
);

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
