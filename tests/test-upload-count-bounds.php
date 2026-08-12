<?php
/**
 * INVERTED pins for the 5.8.1 upload count-bound deletions.
 *
 * The doctrine (2026-08-10): a cap protects the SYSTEM or it rations a USER.
 * The per-message file-count bounds rationed: staff `maxFiles: 5` duplicated
 * server governance (per-tenant upload rate limiter, size/type validation at
 * init, billing-gated commit, vision hard-cap at consumption), and customer
 * `maxFilesPerMessage: 3` bit only on the file-picker path — paste and
 * drag/drop never checked it — and bit SILENTLY (bare return/break, no
 * message). Both deleted in 5.8.1. Deletions get inverted tests, never
 * deleted ones: these pins fail if any bound grows back.
 *
 * Runs standalone (no WordPress bootstrap) — JS and template pins are source
 * scans, the instrument available to this suite for browser-side code.
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

$root         = dirname( __DIR__ );
$shell_src    = file_get_contents( $root . '/templates/staff-ai-shell.php' );
$staff_js     = file_get_contents( $root . '/assets/js/staff-ai.js' );
$customer_js  = file_get_contents( $root . '/assets/js/def-core-customer-chat.js' );

// ── Staff widget: the maxFiles chain is gone ─────────────────────────────────

assert_test(
	false === strpos( $shell_src, 'maxFiles' ),
	'shell template: no maxFiles key in the localized upload config'
);
assert_test(
	false === strpos( $shell_src, 'tooManyFiles' ),
	'shell template: the tooManyFiles i18n string is gone'
);
assert_test(
	1 === preg_match( '/upload:\s*\{\s*\n\s*allowedExtensions:/', $shell_src ),
	'positive anchor: the upload config still opens with allowedExtensions — config intact, count key gone'
);

assert_test(
	false === strpos( $staff_js, 'UPLOAD_MAX_FILES' ),
	'staff-ai.js: UPLOAD_MAX_FILES is gone'
);
assert_test(
	false === strpos( $staff_js, 'tooManyFiles' ),
	'staff-ai.js: no count refusal string'
);
assert_test(
	1 === preg_match( '/unsupportedType/', $staff_js ),
	'positive anchor: validateFile still refuses unsupported extensions'
);
assert_test(
	1 === preg_match( '/file-count bound \(5\.8\.1\)/', $staff_js ),
	'block anchor: the deletion is documented where the bound lived'
);

// ── Customer widget: maxFilesPerMessage and both silent gates are gone ──────

assert_test(
	false === strpos( $customer_js, 'maxFilesPerMessage' ),
	'customer-chat.js: maxFilesPerMessage is gone — config and both checks'
);
assert_test(
	1 === preg_match(
		'/function handleFileSelect\(e\)\s*\{\s*\n\s*var files = e\.target\.files;\s*\n\s*if \(!files \|\| files\.length === 0\) return;\s*\n\s*\n\s*for \(var i = 0; i < files\.length; i\+\+\) \{\s*\n\s*stageFile\(files\[i\]\);/',
		$customer_js
	),
	'block anchor: handleFileSelect stages EVERY selected file — no silent break in the loop'
);
assert_test(
	1 === preg_match(
		'/function handleAttachClick\(\)\s*\{\s*\n\s*if \(!uploadEligible \|\| isComposerDisabled\) return;\s*\n\s*els\.fileInput\.value/',
		$customer_js
	),
	'block anchor: handleAttachClick goes straight from eligibility to the picker — no silent count no-op'
);

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
