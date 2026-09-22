<?php
/**
 * [def_changelog] tests — the bundled changelog parsed and rendered.
 *
 * Runs standalone (no WordPress bootstrap): a small readme fixture for the
 * parser and the renderer, then the plugin's real readme.txt through the
 * shortcode itself, which pins that the shipped version has a changelog entry
 * at the top.
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
		// Like WordPress: the given timezone, else the site's (here: PHP's default).
		$date = new DateTime( '@' . (int) $timestamp );
		$date->setTimezone( $timezone ? $timezone : new DateTimeZone( date_default_timezone_get() ) );
		return $date->format( $format );
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

$fixture = "=== Digital Employees ===\nStable tag: 9.9.9\n\n== Description ==\nWords.\n\n== Changelog ==\n\n"
	. "= 9.9.9 - 2026-09-22 =\n* New Feature - Something new. <script>alert(1)</script>\n* Fix - Something fixed \xE2\x9C\x85 with a check mark whose last byte is 0x85.\n\n"
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
assert_same( true, str_ends_with( $releases[0]['entries'][1], 'last byte is 0x85.' ), 'a line is never cut inside a UTF-8 character' );
assert_same( true, mb_check_encoding( $releases[0]['entries'][1], 'UTF-8' ), 'the entry is still valid UTF-8' );

echo "\n[2] Render: headings with the date, the dateless one bare, everything escaped\n";
$html = DEF_Core_Changelog::html( $releases, 12 );
assert_same( 3, substr_count( $html, '<h3>' ), 'three headings' );
assert_same( true, strpos( $html, '<h3>9.9.9 — 22 September 2026</h3>' ) !== false, 'version and date in the heading' );
assert_same( true, strpos( $html, '<h3>9.9.8</h3>' ) !== false, 'dateless version renders version only' );
assert_same( true, strpos( $html, '9.9.9' ) < strpos( $html, '9.9.7' ), 'newest first' );
assert_same( false, strpos( $html, '<script>' ), 'no raw script tag' );
assert_same( true, strpos( $html, '&lt;script&gt;' ) !== false, 'the script text is escaped' );
assert_same( true, strpos( $html, '<div class="def-changelog">' ) === 0, 'wrapped in the changelog div' );

echo "\n[2b] The day never shifts with the site's timezone\n";
// East of UTC is the side that catches it: without the UTC pin, midnight on the
// 22nd in Auckland is the 21st in UTC, and a site there would read the 21st.
date_default_timezone_set( 'Pacific/Auckland' );
assert_same( true, strpos( DEF_Core_Changelog::html( $releases, 12 ), '9.9.9 — 22 September 2026' ) !== false, 'an Auckland site still reads the 22nd' );
date_default_timezone_set( 'UTC' );

echo "\n[3] The versions count limits and clamps\n";
assert_same( 1, substr_count( DEF_Core_Changelog::html( $releases, 1 ), '<h3>' ), 'one shows one' );
assert_same( 1, substr_count( DEF_Core_Changelog::html( $releases, 0 ), '<h3>' ), 'zero clamps to one' );
assert_same( 3, substr_count( DEF_Core_Changelog::html( $releases, 500 ), '<h3>' ), 'more than exist shows all' );

echo "\n[4] No changelog renders nothing\n";
assert_same( array(), DEF_Core_Changelog::parse( "== Description ==\nNo changelog here.\n" ), 'no section parses to nothing' );
assert_same( '', DEF_Core_Changelog::html( array(), 12 ), 'no releases render an empty string' );

echo "\n[5] The shortcode on the bundled readme.txt: the shipped version is the top entry\n";
$readme = (string) file_get_contents( DEF_CORE_PLUGIN_DIR . 'readme.txt' );
$real   = DEF_Core_Changelog::parse( $readme );
preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $stable );
assert_same( true, count( $real ) >= 200, 'the full changelog parses (' . count( $real ) . ' versions)' );
assert_same( 0, count( array_filter( $real, fn( $r ) => empty( $r['entries'] ) ) ), 'every release has at least one entry' );
assert_same( $stable[1] ?? '', $real[0]['version'] ?? '', 'the top entry is the Stable tag version' );
assert_same( true, '' !== ( $real[0]['date'] ?? '' ), 'the top entry is dated' );
$rendered = DEF_Core_Changelog::render( array( 'versions' => '3' ) );
assert_same( 3, substr_count( $rendered, '<h3>' ), 'the shortcode reads the bundled readme and shows three' );
assert_same( true, strpos( $rendered, '<h3>' . ( $stable[1] ?? '?' ) ) !== false, 'the shortcode shows the shipped version first' );
assert_same( 1, substr_count( DEF_Core_Changelog::render( array( 'versions' => 'abc' ) ), '<h3>' ), 'a non-numeric versions clamps to one' );

echo "\n=== $pass passed, $fail failed ===\n";
exit( $fail > 0 ? 1 : 0 );
