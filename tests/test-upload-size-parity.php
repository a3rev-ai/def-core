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
$core_php    = file_get_contents( $root . '/includes/class-def-core.php' );

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
assert_test(
	0 === preg_match( '/\.size\s*>=?\s/', $customer_js ),
	'negative pin: NO greater-than size comparison anywhere in the widget — a ceiling re-grown at ANY call site goes red (the only size checks left are === 0 and the < 20MB thumbnail render guard)'
);

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
