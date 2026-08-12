<?php
/**
 * Pins for the 5.8.3 failure-path fixes: FAILURE PATHS MUST NOT LOSE USER
 * CONTENT SILENTLY — one class, both widgets.
 *
 * The three shapes pinned (decided 2026-08-12):
 *  1. Failed chips BLOCK the send with a visible reason. Staff used to send
 *     the text FILELESS when every chip had failed (hasActiveFiles()=false
 *     skipped the upload block, clearStagedFiles() wiped the evidence);
 *     customer used to refuse with a bare silent return.
 *  2. The server's own refusal (message + retry_after) reaches the visitor.
 *     Init/commit failures used to throw bare 'Init failed'/'Commit failed',
 *     discarding DEF's 429 copy; the aggregate rejection was a bare
 *     'Upload failed'.
 *  3. Typed text is never cleared before the upload path has succeeded.
 *
 * Source scans — the instrument this suite has for browser-side code. Pins
 * anchor the sites the defects lived at; the shapes are also proven by
 * mutation before the PR (re-adding each old behaviour goes red).
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
$staff_js    = file_get_contents( $root . '/assets/js/staff-ai.js' );
$customer_js = file_get_contents( $root . '/assets/js/def-core-customer-chat.js' );

// ── 1. Failed chips block send, loudly ───────────────────────────────────────

// Staff: the refusal lives in sendMessage, BEFORE the upload block runs.
assert_test(
	1 === preg_match(
		'/var failedChips = stagedFiles\.filter\(function\(f\) \{ return f\.status === \'failed\'; \}\);\s*\n\s*if \(failedChips\.length > 0\) \{\s*\n\s*showError\(failedChips\[0\]\.error \|\|/',
		$staff_js
	),
	'staff: any failed chip refuses the send via showError with the chip\'s own reason'
);
assert_test(
	strpos( $staff_js, 'var failedChips' ) !== false &&
	strpos( $staff_js, 'var failedChips' ) < strpos( $staff_js, 'await uploadAllStagedFiles()' ),
	'staff: the refusal sits BEFORE the upload block — the fileless-send hole cannot be re-reached'
);

// Customer: the bare silent block is gone; the branch surfaces the reason.
assert_test(
	1 !== preg_match( '/if \(hasFailedFiles\) return;/', $customer_js ),
	'customer: the bare silent hasFailedFiles return is gone'
);
assert_test(
	1 === preg_match(
		'/if \(hasFailedFiles\) \{[\s\S]{0,700}?appendMessage\(\'assistant\', reason\);[\s\S]{0,50}?\}\s*\n\s*return;/',
		$customer_js
	),
	'customer: a send with failed chips surfaces the first chip\'s reason in the transcript'
);

// ── 2. The server's refusal reaches the user ────────────────────────────────

assert_test(
	false === strpos( $customer_js, "throw new Error('Init failed')" ) &&
	false === strpos( $customer_js, "throw new Error('Commit failed')" ),
	'customer: bare Init failed / Commit failed throws are gone'
);
assert_test(
	1 === preg_match( '/if \(!res\.ok\) return refusalError\(res, \'Init failed\'\);/', $customer_js ) &&
	1 === preg_match( '/if \(!commitRes\.ok\) return refusalError\(commitRes, \'Commit failed\'\);/', $customer_js ),
	'customer: init AND commit failures parse the server body via refusalError'
);
assert_test(
	1 === preg_match( '/function refusalError\(res, fallback\) \{[\s\S]{0,900}?err\.status = res\.status;/', $customer_js ),
	'customer: refusalError attaches the HTTP status to the error'
);
assert_test(
	1 === preg_match( '/if \(res\.status === 429 && retryAfter\) \{/', $customer_js ),
	'customer: a 429 refusal appends the retry window from detail.retry_after'
);
assert_test(
	1 === preg_match( '/data\.code !== \'proxy_error\' && data\.message/', $customer_js ),
	'customer: proxy_error curl text (backend hostname) never renders — static fallback instead'
);
assert_test(
	1 !== preg_match( '/Promise\.reject\(new Error\(\'Upload failed\'\)\)/', $customer_js ) &&
	1 === preg_match( '/aggErr\.isUploadFailure = true;/', $customer_js ),
	'customer: the aggregate rejection carries the first failure\'s reason, not a bare Upload failed'
);
assert_test(
	1 === preg_match( '/err\.isUploadFailure && err\.message/', $customer_js ),
	'customer: handleChatError shows the upload refusal\'s own copy'
);

// ── 3. Typed text survives failure ───────────────────────────────────────────

$submit_body = substr(
	$customer_js,
	strpos( $customer_js, 'function handleSubmit(' ),
	strpos( $customer_js, 'function sendMessageSync(' ) - strpos( $customer_js, 'function handleSubmit(' )
);
assert_test(
	strpos( $submit_body, 'function handleSubmit(' ) === 0 && strlen( $submit_body ) > 200,
	'pin setup: handleSubmit body extracted'
);
assert_test(
	1 !== preg_match( '/setComposerDisabled\(true\);\s*\n\s*els\.input\.value = \'\';/', $submit_body ),
	'customer: the input is NOT cleared at send time before the upload'
);
assert_test(
	1 === preg_match( '/\.then\(function \(fileIds\) \{[\s\S]{0,400}?els\.input\.value = \'\';/', $submit_body ),
	'customer: the input clears only inside the upload success path'
);

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
