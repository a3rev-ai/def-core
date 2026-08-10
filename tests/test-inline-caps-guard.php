<?php
/**
 * The INLINE caps guard — def-core port of DEF's test_inline_caps_guard.py,
 * plus the CURLOPT streaming pin.
 *
 * The named-constant gate (slice B) scans `private const` declarations. This
 * file covers the half that gate cannot see: literal bounds written inline —
 * `mb_substr( $x, 0, 80 )`, `mb_strlen( $x ) > 500`, `count( $x ) >= 10`,
 * `array_slice( $x, 0, 10 )`. That blind spot is not academic: BOTH caps bugs
 * this repo shipped in the 2026-08-06/08 sweep were inline literals — the
 * compliance-notice 500 cut and the display-name byte cut (removed in 5.7.5,
 * PR #271) — and both survived every earlier review because nothing watched
 * the class.
 *
 * WHY THIS IS A DELTA GATE, NOT AN INVENTORY
 * ------------------------------------------
 * THE BASELINE BELOW IS EXPLICITLY UNREVIEWED. Presence in it means "this
 * existed on 2026-08-10", never "this is fine", and it must not be cited as
 * justification for anything. A classification written to make a test pass
 * reads as approval ever after (Steve, 2026-08-06: a cap "flagged, not
 * justified" is honest; a cap classified in bulk is laundered). What the gate
 * enforces is that the class stops GROWING: a new inline cap fails here at
 * the moment someone writes it — the one moment the author actually knows why
 * it is there and can answer the doctrine's question: does this protect the
 * SYSTEM, or ration a USER? It may REFUSE, DEFER or REPORT. It may never
 * silently drop.
 *
 * Scope: `includes/` — the plugin's server-side production code. Templates
 * and JS carry UI chrome bounds (maxlength attributes) owned by their own
 * review; vendor/ is not ours.
 *
 * Runs standalone (no WordPress bootstrap).
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

// ── The scanner ──────────────────────────────────────────────────────────────

// A truncation-from-start, a length compared against a literal (plain or Yoda,
// per WPCS), a count compared against a literal, or a slice-from-start. Two
// digits minimum: `substr( $x, 0, 1 )` and `count( $x ) > 0` are logic, not caps.
const INLINE_CAP_PATTERN =
	'/(?:mb_)?substr\s*\([^;]*,\s*0\s*,\s*\d{2,}' .
	'|(?:mb_)?strlen\s*\([^)]*\)\s*[><]=?\s*\d{2,}' .
	'|\d{2,}\s*[><]=?\s*(?:mb_)?strlen\s*\(' .
	'|count\s*\([^)]*\)\s*[><]=?\s*\d{2,}' .
	'|\d{2,}\s*[><]=?\s*count\s*\(' .
	'|array_slice\s*\([^;]*,\s*0\s*,\s*\d{2,}/';

// Comment lines, log/notice lines, and hash/ID prefixes are not caps on
// anybody's data. The backtick rules out PROSE — docstrings that discuss a cap
// are how DEF's own named pin first failed, against a comment describing the
// bug rather than the bug.
const NOT_A_CAP_PATTERN =
	'~^\s*(//|\*|/\*|#)' .
	'|error_log|_doing_it_wrong' .
	'|md5|sha1|sha256|hash\(|wp_hash|uniqid|wp_generate' .
	'|`~';

function scan_inline_caps(): array {
	$root  = dirname( __DIR__ ) . '/includes';
	$found = array();
	$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
	foreach ( $iter as $file ) {
		if ( $file->isDir() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$rel   = 'includes' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) );
		$lines = file( $file->getPathname(), FILE_IGNORE_NEW_LINES );
		foreach ( $lines as $line ) {
			if ( preg_match( NOT_A_CAP_PATTERN, $line ) ) {
				continue;
			}
			if ( preg_match( INLINE_CAP_PATTERN, $line ) ) {
				$key = $rel . '::' . trim( $line );
				$found[ $key ] = ( $found[ $key ] ?? 0 ) + 1;
			}
		}
	}
	return $found;
}

// ── The baseline — EXPLICITLY UNREVIEWED, measured 2026-08-10 ────────────────

const BASELINE = array(
	'includes/class-def-core-admin-api.php::$error_msg = sanitize_text_field( substr( $response->get_error_message(), 0, 200 ) );' => 1,
	'includes/class-def-core-admin-api.php::if ( mb_strlen( $str ) > 1000 ) {' => 1,
	'includes/class-def-core-admin-api.php::if ( mb_strlen( $str ) > 50 ) {' => 1,
	'includes/class-def-core-admin-api.php::if ( mb_strlen( $str ) > 80 ) {' => 1,
	'includes/class-def-core-admin-api.php::if ( mb_strlen( $value ) > 100 ) {' => 1,
	'includes/class-def-core-admin-api.php::if ( mb_strlen( $value ) > 200 ) {' => 1,
	'includes/class-def-core-admin-api.php::if ( mb_strlen( $value ) > 30 ) {' => 1,
	'includes/class-def-core-admin.php::$error_msg = sanitize_text_field( substr( $response->get_error_message(), 0, 200 ) );' => 1,
	'includes/class-def-core-admin.php::if ( count( $results ) >= 10 ) {' => 1,
	'includes/class-def-core-admin.php::if ( mb_strlen( $value ) > 2048 ) {' => 1,
	'includes/class-def-core-export.php::$context[\'sql\'] = substr( $query->request, 0, 2000 );' => 1,
	'includes/class-def-core-knowledge-export.php::if ( is_string( $value ) && strlen( $value ) > 500 ) {' => 1,
	'includes/class-def-core-logger.php::$request_id = substr( (string) $context[\'request_id\'], 0, 36 );' => 1,
	'includes/class-def-core-logger.php::\'source\'     => substr( $source, 0, 20 ),' => 1,
	'includes/class-def-core-logs-page.php::$context_display = esc_html( mb_substr( $row->context, 0, 200 ) );' => 1,
	'includes/class-def-core-logs-page.php::$v = mb_substr( (string) $v, 0, 120 ) . ( mb_strlen( (string) $v ) > 120 ? \'...\' : \'\' );' => 1,
	'includes/class-def-core-page-context.php::return (string) mb_substr( $title, 0, 200 );' => 1,
	'includes/class-def-core-page-context.php::return substr( $title, 0, 200 );' => 1,
	'includes/class-def-core-page-context.php::return substr( $value, 0, 100 );' => 1,
	'includes/class-def-core-page-context.php::return substr( (string) $value, 0, 16 );' => 1,
	'includes/class-def-core-site-tools.php::$truncated = array_slice( $data, 0, 10 );' => 1,
	'includes/class-def-core-staff-ai.php::$error_msg .= \' (HTTP \' . intval( $status_code ) . \': \' . esc_html( substr( $error_body, 0, 200 ) ) . \')\';' => 1,
	'includes/class-def-core-staff-ai.php::if ( mb_strlen( $notes ) > 2000 ) {' => 1,
	'includes/class-def-core-staff-ai.php::if (count($events) >= 100) {' => 1,
	'includes/class-def-core-tools.php::&& strlen( $cart_token ) <= 4096' => 1,
);

// ── [1] A NEW inline cap fails at the moment it is written ───────────────────
echo "\n[1] No new inline cap without a decision\n";

$found = scan_inline_caps();
$new   = array();
foreach ( $found as $key => $n ) {
	if ( $n > ( BASELINE[ $key ] ?? 0 ) ) {
		$new[] = $key;
	}
}
assert_test(
	empty( $new ),
	empty( $new )
		? 'no new inline caps'
		: "NEW inline cap(s) — a literal bound on somebody's data:\n    " . implode( "\n    ", $new ) .
		  "\n    Before adding to BASELINE answer the doctrine's question: what breaks if it is absent?" .
		  "\n    If nothing breaks it is not protecting the system and should not exist. If something" .
		  "\n    does, say so in ONE line beside the entry — and make it REFUSE, DEFER or REPORT." .
		  "\n    It may never silently drop."
);

// ── [2] The baseline shrinks only ────────────────────────────────────────────
echo "\n[2] Baseline shrinks-only — deleted caps leave the baseline\n";

$stale = array();
foreach ( BASELINE as $key => $n ) {
	if ( ( $found[ $key ] ?? 0 ) < $n ) {
		$stale[] = $key;
	}
}
assert_test(
	empty( $stale ),
	empty( $stale )
		? 'baseline matches the surface'
		: "BASELINE lists inline caps that no longer exist — delete these lines:\n    " . implode( "\n    ", $stale )
);

// ── [3] The two that shipped bugs never come back — named, not generic ───────
echo "\n[3] Named inverted pins for the two that reached production (PR #271)\n";

$compliance_cut = array();
$display_cut    = array();
foreach ( $found as $key => $n ) {
	if ( strpos( $key, 'class-def-core-admin.php' ) !== false && preg_match( '/(?:mb_)?substr\s*\(\s*\$value\s*,\s*0\s*,\s*500/', $key ) ) {
		$compliance_cut[] = $key;
	}
	if ( strpos( $key, 'class-def-core-admin.php' ) !== false && preg_match( '/substr\s*\(\s*\$value\s*,\s*0\s*,\s*100/', $key ) ) {
		$display_cut[] = $key;
	}
}
assert_test( empty( $compliance_cut ), 'the compliance-notice 500 cut is not back' );
assert_test( empty( $display_cut ), 'the display-name 100 cut is not back' );

// ── [4] The CURLOPT streaming pin ────────────────────────────────────────────
// CURLOPT_TIMEOUT => 0 on the SSE stream is DELIBERATE, not a missing bound:
// an assistant stream legitimately outlives any fixed timeout, and the real
// watchdog is the low-speed pair — kill the transfer after 30s below
// 1 byte/sec. The two likeliest "fixes" both break production: giving the
// stream a finite CURLOPT_TIMEOUT kills long responses mid-answer; removing
// the low-speed pair leaves a dead connection holding a PHP worker forever.
// The non-streaming request keeps its finite 30s timeout — that one is the
// system protection for a call that should never be long.
echo "\n[4] CURLOPT streaming pin — the watchdog shape is load-bearing\n";

$tools_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-def-core-tools.php' );

assert_test(
	1 === preg_match( '/CURLOPT_TIMEOUT\s*=>\s*0\s*,/', $tools_src ),
	'streaming: CURLOPT_TIMEOUT is 0 (a stream outlives any fixed timeout)'
);
assert_test(
	1 === preg_match( '/CURLOPT_LOW_SPEED_LIMIT\s*=>\s*1\s*,/', $tools_src ),
	'streaming: CURLOPT_LOW_SPEED_LIMIT is 1 byte/sec — the real watchdog, half 1'
);
assert_test(
	1 === preg_match( '/CURLOPT_LOW_SPEED_TIME\s*=>\s*30\s*,/', $tools_src ),
	'streaming: CURLOPT_LOW_SPEED_TIME is 30s — the real watchdog, half 2'
);
assert_test(
	1 === preg_match( '/CURLOPT_TIMEOUT\s*=>\s*30\s*,/', $tools_src ),
	'non-streaming: CURLOPT_TIMEOUT stays finite at 30s'
);

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
