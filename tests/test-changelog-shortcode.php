<?php
/**
 * [def_changelog] tests — the bundled changelog parsed and rendered.
 *
 * Runs standalone (no WordPress bootstrap): a small readme fixture for the
 * parser and the renderer, then the plugin's real readme.txt to pin that the
 * shipped version has a changelog entry at the top.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'DEF_CORE_PLUGIN_DIR' ) ) {
	define( 'DEF_CORE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/wp-stubs.php';

if ( ! defined( 'DEF_CORE_VERSION' ) ) {
	define( 'DEF_CORE_VERSION', 'test' );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( array $pairs, $atts, string $shortcode = '' ): array {
		$atts = (array) $atts;
		$out  = array();
		foreach ( $pairs as $key => $default ) {
			$out[ $key ] = array_key_exists( $key, $atts ) ? $atts[ $key ] : $default;
		}
		return $out;
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, $timestamp = null, $timezone = null ): string {
		// WordPress formats in the given timezone; the shortcode passes UTC.
		return gmdate( $format, (int) $timestamp );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-changelog.php';

$pass = 0;
$fail = 0;

function assert_same( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
	}
}

function seed_releases( array $releases ): void {
	global $_wp_test_transients;
	$_wp_test_transients = array();
	set_transient( 'def_core_changelog_' . DEF_CORE_VERSION, $releases, WEEK_IN_SECONDS );
}

$fixture = "=== Digital Employees ===\nStable tag: 9.9.9\n\n== Description ==\nWords.\n\n== Changelog ==\n\n"
	. "= 9.9.9 - 2026-09-22 =\n* New Feature - Something new. <script>alert(1)</script>\n* Fix - Something fixed.\n\n"
	. "= 9.9.8 =\n* Tweak - No date on this one.\n\n"
	. "= 9.9.7 - 2026-09-01 =\n* Fix - The oldest.\n\n"
	. "== Upgrade Notice ==\n\n= 9.9.9 =\nNot a changelog entry.\n";

echo "=== [def_changelog] Tests ===\n";

echo "\n[1] Parse: three releases, newest first, the Upgrade Notice ignored\n";
$releases = DEF_Core_Changelog::parse( $fixture );
assert_same( 3, count( $releases ), 'three releases' );
assert_same( array( '9.9.9', '9.9.8', '9.9.7' ), array_column( $releases, 'version' ), 'file order kept' );
assert_same( '2026-09-22', $releases[0]['date'], 'date read' );
assert_same( '', $releases[1]['date'], 'dateless version has no date' );
assert_same( 2, count( $releases[0]['entries'] ), 'two entries on the first' );
assert_same( 'Fix - The oldest.', $releases[2]['entries'][0], 'entry text without the bullet' );

echo "\n[2] Render: headings with the date, the dateless one bare, everything escaped\n";
seed_releases( $releases );
$html = DEF_Core_Changelog::render( array() );
assert_same( 3, substr_count( $html, '<h3>' ), 'three headings' );
assert_same( true, strpos( $html, '<h3>9.9.9 — 22 September 2026</h3>' ) !== false, 'version and date in the heading' );
assert_same( true, strpos( $html, '<h3>9.9.8</h3>' ) !== false, 'dateless version renders version only' );
assert_same( true, strpos( $html, '9.9.9' ) < strpos( $html, '9.9.7' ), 'newest first' );
assert_same( false, strpos( $html, '<script>' ), 'no raw script tag' );
assert_same( true, strpos( $html, '&lt;script&gt;' ) !== false, 'the script text is escaped' );
assert_same( true, strpos( $html, '<div class="def-changelog">' ) === 0, 'wrapped in the changelog div' );

echo "\n[2b] The day never shifts with the server's timezone\n";
date_default_timezone_set( 'America/Los_Angeles' );
assert_same( true, strpos( DEF_Core_Changelog::render( array() ), '9.9.9 — 22 September 2026' ) !== false, 'west of UTC: same day' );
date_default_timezone_set( 'Pacific/Auckland' );
assert_same( true, strpos( DEF_Core_Changelog::render( array() ), '9.9.9 — 22 September 2026' ) !== false, 'east of UTC: same day' );
date_default_timezone_set( 'UTC' );

echo "\n[3] The versions attribute limits and clamps\n";
assert_same( 1, substr_count( DEF_Core_Changelog::render( array( 'versions' => '1' ) ), '<h3>' ), 'versions=1 shows one' );
assert_same( 1, substr_count( DEF_Core_Changelog::render( array( 'versions' => '0' ) ), '<h3>' ), 'versions=0 clamps to one' );
assert_same( 3, substr_count( DEF_Core_Changelog::render( array( 'versions' => '500' ) ), '<h3>' ), 'more than exist shows all' );

echo "\n[4] No changelog section renders nothing\n";
assert_same( array(), DEF_Core_Changelog::parse( "== Description ==\nNo changelog here.\n" ), 'no section parses to nothing' );
seed_releases( array() );
assert_same( '', DEF_Core_Changelog::render( array() ), 'empty releases render an empty string' );

echo "\n[5] The bundled readme.txt: the shipped version is the top entry\n";
$readme = (string) file_get_contents( DEF_CORE_PLUGIN_DIR . 'readme.txt' );
$real   = DEF_Core_Changelog::parse( $readme );
preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $stable );
assert_same( true, count( $real ) >= 200, 'the full changelog parses (' . count( $real ) . ' versions)' );
assert_same( $stable[1] ?? '', $real[0]['version'] ?? '', 'the top entry is the Stable tag version' );
assert_same( true, '' !== ( $real[0]['date'] ?? '' ), 'the top entry is dated' );
assert_same( true, count( $real[0]['entries'] ?? array() ) >= 1, 'the top entry says what changed' );

echo "\n=== $pass passed, $fail failed ===\n";
exit( $fail > 0 ? 1 : 0 );
