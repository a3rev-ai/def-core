<?php
/**
 * INVERTED pins for the 5.8.5 customer size-ceiling deletion — the staff
 * widget's 5.7.11 parity.
 *
 * The customer widget carried a 10MB client-side ceiling (config key, the
 * validateFilePreflight size check, the fileTooLarge string in JS and PHP)
 * duplicating DEF's env-tunable UPLOAD_MAX_FILE_MB. A JS twin of an env
 * dial can only ever drift, and raising the platform ceiling must never
 * require a plugin release. Deleted whole-chain; the server's refusal is
 * an allowlisted validation_failed literal, so the visitor sees the
 * server's own message. The zero-size check STAYS — malformed input, not
 * a cap.
 *
 * WHAT THE NEGATIVE PIN IS AND IS NOT
 * ----------------------------------
 * The `.size >` scan is a SINGLE-SHAPE TRIPWIRE, not proof of absence. It
 * catches the shape a re-grown ceiling actually takes — a greater-than
 * comparison against the File API's own property, at any call site in
 * either widget — because that is how every instance this campaign deleted
 * was written. It does NOT catch a ceiling expressed some other way
 * (a helper reading `f.file.size` into a local first, a `<`-flipped
 * operand order, a byte count derived from a FileReader result). Claiming
 * otherwise would make the pin read as coverage it does not have. What it
 * buys is that the cheap, obvious restoration goes red at the moment
 * someone writes it — the one moment they can still answer the doctrine's
 * question.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;

function assert_test( $cond, string $label ): void {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "[PASS] $label\n";
	} else {
		$fail++;
		echo "  ✗ FAILED: $label\n";
	}
}

$root        = dirname( __DIR__ );
$customer_js = file_get_contents( $root . '/assets/js/def-core-customer-chat.js' );
$staff_js    = file_get_contents( $root . '/assets/js/staff-ai.js' );
$core_php    = file_get_contents( $root . '/includes/class-def-core.php' );

/**
 * Strip `//` comment lines so the negative pin measures CODE, not prose.
 * Without this a comment naming the deleted shape (this file's own subject)
 * would trip the tripwire and read as a defect.
 *
 * @param string $js JavaScript source.
 * @return string Source with whole-line comments removed.
 */
function strip_comment_lines( string $js ): string {
	$lines = preg_split( '/\r\n|\r|\n/', $js );
	$kept  = array_filter(
		$lines,
		static function ( $line ) {
			return 1 !== preg_match( '/^\s*(?:\/\/|\*|\/\*)/', $line );
		}
	);
	return implode( "\n", $kept );
}

assert_test(
	false === strpos( $customer_js, 'maxSizeBytes' ),
	'customer-chat.js: the maxSizeBytes config key is gone'
);
assert_test(
	false === strpos( $customer_js, 'fileTooLarge' ) &&
	false === strpos( $core_php, 'fileTooLarge' ),
	'the fileTooLarge string is gone from JS defaults AND the PHP strings array'
);
assert_test(
	1 === preg_match(
		'/function validateFilePreflight\(file\) \{\s*\n\s*if \(!file \|\| file\.size === 0\) \{\s*\n\s*return \'File is empty\';\s*\n\s*\}\s*\n\s*(?:\/\/[^\n]*\n\s*)*var ext = getFileExtension\(file\.name\);/',
		$customer_js
	),
	'block anchor: preflight is zero-size then type — no size comparison can live between them'
);
assert_test(
	1 === preg_match( '/fileTypeNotSupported/', $customer_js ) &&
	1 === preg_match( '/File is empty/', $customer_js ),
	'positive anchors: the zero-size refusal (malformed input) and the type refusal both stay'
);
// Negative tripwire, BOTH widgets — this file is named parity and its
// sibling pins both, so pinning one widget would leave the staff half of
// the same class unwatched. Staff passes today (its 5.7.11 deletion), so
// the pin locks a property that already holds rather than asserting a
// future one. Comment lines excluded — see strip_comment_lines().
foreach (
	array(
		'customer-chat.js' => $customer_js,
		'staff-ai.js'      => $staff_js,
	) as $label => $src
) {
	assert_test(
		0 === preg_match( '/\.size\s*>=?\s/', strip_comment_lines( $src ) ),
		"negative tripwire ($label): no greater-than size comparison in code — the cheap restoration at ANY call site goes red (what remains: === 0 and the < 20MB thumbnail render guard)"
	);
}

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
