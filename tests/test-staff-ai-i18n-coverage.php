<?php
/**
 * Staff-AI console i18n coverage (D-C10) tests.
 *
 * Verifies:
 *  - Every t( 'key', 'default' ) the console's JS uses has an entry in the PHP
 *    i18n map, so no console string ships English-only past a language file.
 *  - Every map entry keeps the wp_json_encode( __( …, 'digital-employees' ) )
 *    shape, which is what puts it in the .pot file in the first place.
 *  - No key is declared twice, where the later entry would silently win.
 *
 * A fallback-only key is invisible: the JS renders its English default and no
 * translator ever sees it. 98 of them had accumulated by 7.8.0; this test is
 * what stops the gap reopening one commit at a time.
 *
 * Ten map entries (`usageLoadFailed`, `taskDeleted`, …) word their text
 * differently from the JS default on purpose and pre-date this test: the map is
 * the shipped string, so they must not be "corrected" back to the JS fallback.
 *
 * Runs standalone (no WordPress bootstrap) — it reads the two files as text.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

$js_path  = dirname( __DIR__ ) . '/assets/js/staff-ai.js';
$php_path = dirname( __DIR__ ) . '/templates/staff-ai-shell.php';

// ── Tiny assertion harness ──────────────────────────────────────────────

$pass = 0;
$fail = 0;

function assert_true( $value, string $label ): void {
	global $pass, $fail;
	if ( $value ) { $pass++; } else { $fail++; echo "  FAIL: $label\n"; }
}
function assert_same( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) { $pass++; } else {
		$fail++;
		echo "  FAIL: $label (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
	}
}

// ── The two sources ─────────────────────────────────────────────────────

assert_true( is_readable( $js_path ), 'assets/js/staff-ai.js is readable' );
assert_true( is_readable( $php_path ), 'templates/staff-ai-shell.php is readable' );
if ( ! is_readable( $js_path ) || ! is_readable( $php_path ) ) {
	echo "\n$pass passed, $fail failed\n";
	exit( 1 );
}

$js  = (string) file_get_contents( $js_path );
$php = (string) file_get_contents( $php_path );

/**
 * Keys the JS asks for, however each call writes its default.
 *
 * The leading character class keeps `t(` from matching the tail of another
 * identifier (`format(`, `parseInt(`), which a bare \b would not. The key's
 * quotes are a class so a future double-quoted t( "key" ) cannot slip the scan.
 */
$js_keys = array();
preg_match_all( "/[^A-Za-z0-9_.\\$]t\\(\\s*['\"]([A-Za-z0-9_]+)['\"]/", $js, $m );
foreach ( $m[1] as $key ) {
	$js_keys[ $key ] = true;
}

/**
 * The seven "Ask X how this works" entries build their keys from a base
 * (`askEntry( askBtn, 'usage', … )` asks for usageAskNamed, usageAsk and
 * usageAskPrompt). A scan for a literal t( 'key' ) cannot see those, so the
 * bases are expanded here — otherwise collapsing the seven near-identical
 * blocks onto one helper (C5) would have quietly dropped 21 keys out of this
 * check, which is the one thing standing between a console string and English
 * for ever.
 */
preg_match_all( "/askEntry\(\s*[^,]+,\s*'([A-Za-z0-9_]+)'/", $js, $ak );
assert_same( 7, count( $ak[1] ), 'the console wires seven Ask entries through askEntry()' );
foreach ( $ak[1] as $base ) {
	foreach ( array( 'AskNamed', 'Ask', 'AskPrompt' ) as $suffix ) {
		$js_keys[ $base . $suffix ] = true;
	}
}

$js_keys = array_keys( $js_keys );
sort( $js_keys );

assert_true( count( $js_keys ) > 200, 'the JS scan found the console\'s t() keys (' . count( $js_keys ) . ')' );

/**
 * The map's body: from `i18n: {` to the line that closes it. Bounding the scan
 * this way keeps an unrelated `key: value` elsewhere in StaffAIConfig out.
 */
$start = strpos( $php, "\n\t\ti18n: {" );
assert_true( false !== $start, 'the i18n map is where the shell declares StaffAIConfig' );
if ( false === $start ) {
	echo "\n$pass passed, $fail failed\n";
	exit( 1 );
}
$end = strpos( $php, "\n\t\t}", $start );
assert_true( false !== $end, 'the i18n map closes at its own indent level' );
$map_src = substr( $php, $start, ( false === $end ? strlen( $php ) : $end ) - $start );

$map_keys = array();
// `(.*?)\r?$` and not `(.*)`: a Windows checkout (core.autocrlf) leaves a carriage
// return on the end of every line, which PCRE keeps in the capture and the shape
// check below then rejects — all 250-odd entries red on this laptop, every one of
// them green in CI. The gate has to bite on the same laptop the console is built on.
preg_match_all( '/^\t{3}([A-Za-z0-9_]+): (.*?)\r?$/m', $map_src, $mm, PREG_SET_ORDER );
foreach ( $mm as $entry ) {
	$map_keys[ $entry[1] ][] = $entry[2];
}

assert_true( count( $map_keys ) > 200, 'the map scan found its entries (' . count( $map_keys ) . ')' );

// ── D-C10: the map covers every key the console uses ────────────────────

$missing = array();
foreach ( $js_keys as $key ) {
	if ( ! isset( $map_keys[ $key ] ) ) {
		$missing[] = $key;
	}
}
if ( $missing ) {
	echo '  ' . count( $missing ) . " key(s) with no entry in the i18n map:\n";
	foreach ( $missing as $key ) {
		echo "    - $key\n";
	}
	echo "  Add each to templates/staff-ai-shell.php as:\n";
	echo "    <key>: <?php echo wp_json_encode( __( '<the JS default>', 'digital-employees' ) ); ?>,\n";
}
assert_same( array(), $missing, 'every t() key the console uses has an i18n map entry' );

// ── A second entry for the same key would silently win ──────────────────

$duplicates = array();
foreach ( $map_keys as $key => $entries ) {
	if ( count( $entries ) > 1 ) {
		$duplicates[] = $key;
	}
}
assert_same( array(), $duplicates, 'no key is declared twice in the i18n map' );

// ── The shape is what makes a string translatable ───────────────────────

$wrong_shape = array();
foreach ( $map_keys as $key => $entries ) {
	foreach ( $entries as $value ) {
		if ( ! preg_match( "/^<\\?php (?:\\/\\*.*?\\*\\/ )?echo wp_json_encode\\( __\\( .+, 'digital-employees' \\) \\); \\?>,?$/", $value ) ) {
			$wrong_shape[] = $key;
		}
	}
}
if ( $wrong_shape ) {
	echo '  entries not wrapped in wp_json_encode( __( …, \'digital-employees\' ) ): ' . implode( ', ', $wrong_shape ) . "\n";
}
assert_same( array(), $wrong_shape, 'every map entry is a translated, JSON-encoded string' );

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
