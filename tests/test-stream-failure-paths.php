<?php
/**
 * Pins for the 5.8.4 stream-failure slice: NO FAILURE MAY EAT THE SESSION
 * OR LIE ABOUT WHY — both widgets plus the proxy producer.
 *
 * The repairs pinned (brief decided 2026-08-12, decisions final):
 *  1. Customer pump completion is unconditional: a stream ending without a
 *     done/error event used to leave the composer disabled forever. Now a
 *     completion handler re-enables ALWAYS and renders localized honest
 *     copy. No auto-retry, no streaming behaviour changes.
 *  2. Staff sinks are string-safe: a DEF refusal object used to render
 *     "[object Object]" in the chat-stream banner, and apiRequest collapsed
 *     DEF's {detail:{message, retry_after}} to "Request failed".
 *  3. The staff escapeHtml escapes quotes, and the chip filename tooltip is
 *     assigned via the el.title PROPERTY — a crafted filename can no longer
 *     break out of an attribute (the quote-blind escapeHtml + title="…"
 *     interpolation pair).
 *  4. The proxy producers keep curl detail SERVER-side: full text to
 *     DEF_Core_Logger, generic message to every client.
 *
 * Source scans — the suite's instrument for browser-side code; each repair
 * is proven by a verified-applied mutation before the PR.
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
$tools_php   = file_get_contents( $root . '/includes/class-def-core-tools.php' );
$core_php    = file_get_contents( $root . '/includes/class-def-core.php' );

// ── 1. Customer pump completion — unconditional, honest, localized ──────────

assert_test(
	2 === preg_match_all( '/streamTerminated = true;/', $customer_js, $m ),
	'customer: BOTH terminal events (done, error) mark the stream terminated'
);
assert_test(
	1 === preg_match(
		'/return pump\(\);\s*\n\s*\}\)\s*\n\s*\.then\(function \(\) \{[\s\S]{0,2000}?if \(streamTerminated\) return;/',
		$customer_js
	),
	'customer: a completion handler follows the pump — clean EOF without done/error is handled'
);
assert_test(
	1 === preg_match(
		'/eventQueue\.length === 0 && !processing\) \|\| waitedMs >= 10000/',
		$customer_js
	),
	'customer: the handler DRAINS the paced event queue first (bounded) — a queued done event cannot be misread as truncation'
);
assert_test(
	1 === preg_match(
		'/if \(streamTerminated\) return;[\s\S]{0,900}?appendMessage\(\'assistant\', t\(\'streamIncomplete\'\)\);\s*\n\s*setComposerDisabled\(false\);/',
		$customer_js
	),
	'customer: the truncation handler renders the localized incomplete-reply bubble and ALWAYS re-enables the composer'
);
assert_test(
	strpos( $core_php, "'streamIncomplete'" ) !== false &&
	1 === preg_match( '/streamIncomplete:\s*\n?\s*\'Connection lost/', $customer_js ),
	'customer: streamIncomplete copy goes through the text domain (PHP) with a JS default fallback'
);

// ── 2. Staff sinks are string-safe ───────────────────────────────────────────

assert_test(
	1 === preg_match( '/function extractServerMessage\(data\) \{[\s\S]{0,400}?typeof detail\.message === \'string\'[\s\S]{0,200}?typeof detail === \'string\'/', $staff_js ),
	'staff: extractServerMessage returns strings only — an object can never reach a banner'
);
assert_test(
	1 !== preg_match( '/\(await response\.json\(\)\)\.detail \|\| \'\'/', $staff_js ),
	'staff: the chat-stream sink no longer takes .detail raw (the [object Object] shape is gone)'
);
assert_test(
	1 === preg_match( '/errText = extractServerMessage\(errData\)/', $staff_js ),
	'staff: the chat-stream sink extracts via the string-safe helper'
);
assert_test(
	1 === preg_match( '/var detailMsg = extractServerMessage\(data\);/', $staff_js ) &&
	1 === preg_match( '/response\.status === 429 && detail && typeof detail\.retry_after === \'number\'/', $staff_js ),
	'staff: apiRequest renders the server copy and appends the retry window on 429'
);

// ── 3. The XSS pair — quote-escaping utility + property-assigned tooltip ────

$esc_body = substr( $staff_js, strpos( $staff_js, 'function escapeHtml(' ), 500 );
assert_test(
	strpos( $esc_body, "replace(/\"/g, '&quot;')" ) !== false &&
	strpos( $esc_body, "replace(/'/g, '&#039;')" ) !== false,
	'staff: escapeHtml escapes both quote characters — the utility is attribute-safe'
);
assert_test(
	1 !== preg_match( '/upload-chip-name" title=/', $staff_js ),
	'staff: the chip filename is never interpolated into a title attribute'
);
assert_test(
	1 === preg_match( '/nameEl\.title = f\.file\.name;/', $staff_js ),
	'staff: the tooltip is assigned via the el.title property — the value never meets the HTML parser'
);

// ── 4. Producer trim — curl detail stays server-side ────────────────────────

assert_test(
	1 !== preg_match( '/WP_Error\( \'proxy_error\', \'Backend connection failed: \'/', $tools_php ),
	'producer: no WP_Error message carries curl text'
);
assert_test(
	2 === preg_match_all( '/WP_Error\( \'proxy_error\', \'Backend connection failed\.\'/', $tools_php, $m2 ),
	'producer: both proxy sites return the static generic message'
);
assert_test(
	2 === preg_match_all( '/DEF_Core_Logger::error\( \'proxy\', \'Backend connection failed: \' \. curl_error\( \$ch \)/', $tools_php, $m3 ),
	'producer: both proxy sites log the full curl detail server-side'
);

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
