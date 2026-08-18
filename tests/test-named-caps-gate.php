<?php
/**
 * The NAMED-constant caps gate — def-core port of DEF's
 * test_caps_inventory_guard.py (DEF #859), slice B of the caps sweep.
 *
 * The inline gate (test-inline-caps-guard.php) watches literal bounds written
 * in expressions. This file watches the other half: cap-shaped `const`
 * declarations — every one classified, one line of justification per KEEP,
 * the registry frozen by test. A new cap-shaped constant fails CI until its
 * author answers the doctrine's question:
 *
 *   Does this protect the SYSTEM, or ration a USER?
 *   A cap may REFUSE, DEFER or REPORT. It may never silently drop.
 *
 * Classifications:
 * - system          — what breaks if absent is stated beside it. KEEP.
 * - ui_chrome       — presentation-side bound; nothing breaks. KEEP, flagged:
 *                     the 0c class, reviewed when its surface is.
 * - delete_required — RATIONS A USER. The gate flags it; the deletion lands
 *                     in the queued bounds-deletion slice. The doctrine was
 *                     decided ONCE (Steve, 2026-08-10) — every instance it
 *                     covers is pre-decided; nothing goes back for
 *                     per-instance blessing.
 *
 * THE REGISTRY IS NOT A BLESSING. `system` entries carry their one-line
 * reason and can be re-litigated any time; `delete_required` entries are
 * DEBT ON THE RECORD, not accepted state.
 *
 * KNOWN BLIND SPOTS (stated, not solved — the gate shrinks where caps hide,
 * it does not eliminate hiding places):
 * - variable/filtered bounds (`apply_filters( ..., 100 )`, option-driven
 *   values) — no literal constant to see. Carried; the roadmap item is a
 *   value-source audit, not a regex.
 * - `define()`-style constants — measured zero cap-shaped defines on
 *   2026-08-10, so scanning class consts covers today's whole surface; if a
 *   cap-shaped define ever lands, the scan-scope pin below is where the gap
 *   will be visible.
 * - the inline gate's line-based nature (multi-line calls, hash-co-occurring
 *   lines) — carried there, stated here for completeness; see the PR body of
 *   slice B for the carry/close ruling.
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

// Keyword match is PER UNDERSCORE TOKEN, never substring — CAPABILITIES must
// not false-positive on CAP, MAXIMUM must not match MAX. TTL, PAGE and LEN
// join DEF's set: they are this codebase's idioms for a timing window, a
// pagination size and a length bound.
const CAP_KEYWORDS = array(
	'MAX', 'LIMIT', 'LIMITS', 'CAP', 'CAPS', 'CEILING', 'WINDOW', 'THRESHOLD',
	'BUDGET', 'QUOTA', 'TOKENS', 'BYTES', 'ROUNDS', 'TIMEOUT', 'TTL', 'PAGE', 'LEN',
);

// A class constant with an INTEGER literal value. Array/string consts are not
// bounds (PAGE_TYPES is a list of names, not a cap — the value shape excludes
// it before the token rule even runs).
const CONST_PATTERN = '/^\s*(?:private\s+|public\s+|protected\s+)?const\s+([A-Z][A-Z0-9_]*)\s*=\s*(\d+)\s*;/';

function is_cap_shaped( string $name ): bool {
	foreach ( explode( '_', $name ) as $token ) {
		if ( in_array( $token, CAP_KEYWORDS, true ) ) {
			return true;
		}
	}
	return false;
}

function scan_named_caps(): array {
	$root  = dirname( __DIR__ ) . '/includes';
	$found = array();
	$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
	foreach ( $iter as $file ) {
		if ( $file->isDir() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$rel = 'includes' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) );
		foreach ( file( $file->getPathname(), FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( preg_match( CONST_PATTERN, $line, $m ) && is_cap_shaped( $m[1] ) ) {
				$found[ $rel . '::' . $m[1] ] = (int) $m[2];
			}
		}
	}
	return $found;
}

// ── The registry — every cap classified, measured 2026-08-10 ─────────────────
// 'class' => system | ui_chrome | delete_required; 'why' => the one line.

const CAPS_REGISTRY = array(
	'includes/class-def-core-partner-attribution.php::FALLBACK_WINDOW_DAYS' => array(
		'class' => 'system',
		'why'   => 'AD-2 fallback if DEFHO\'s validate-slug response omits window_days; the response value always wins — prevents a zero-length cookie, never rations.',
	),
	'includes/class-def-core-admin-api.php::AUDIT_LOG_MAX' => array(
		'class' => 'system',
		'why'   => 'FIFO retention on the settings audit trail — an unbounded wp_options row wedges the DB.',
	),
	'includes/class-def-core-admin-api.php::RATE_LIMIT_MAX' => array(
		'class' => 'system',
		'why'   => 'Write-flood ceiling on settings writes; refuses visibly with retry_after.',
	),
	'includes/class-def-core-admin-api.php::RATE_LIMIT_WINDOW' => array(
		'class' => 'system',
		'why'   => 'The window the write-flood ceiling counts in.',
	),
	'includes/class-def-core-admin-api.php::RATE_LIMIT_TTL' => array(
		'class' => 'system',
		'why'   => 'Transient TTL for the rate counter — must outlive the window or the count resets early.',
	),
	'includes/class-def-core-chat-attribution.php::MAX_LEN' => array(
		'class' => 'system',
		'why'   => 'Bound on an ID-shaped token already charset-scrubbed; a longer chat id is never legitimate.',
	),
	'includes/class-def-core-connection-config.php::ROTATION_WINDOW_SECONDS' => array(
		'class' => 'system',
		'why'   => 'Dual-secret rotation overlap — the security timing window itself.',
	),
	'includes/class-def-core-escalation.php::RATE_LIMIT_WINDOW_SECONDS' => array(
		'class' => 'system',
		'why'   => 'Anonymous visitors can trigger wp_mail; this is the abuse window.',
	),
	'includes/class-def-core-escalation.php::RATE_LIMIT_MAX_REQUESTS' => array(
		'class' => 'system',
		'why'   => 'The abuse ceiling inside that window.',
	),
	'includes/class-def-core-logger.php::MAX_MESSAGE_LENGTH' => array(
		'class' => 'system',
		'why'   => 'Bounded log line — logs are the sanctioned truncation target, not user data.',
	),
	'includes/class-def-core-logger.php::MAX_CONTEXT_LENGTH' => array(
		'class' => 'system',
		'why'   => 'Bounded log context blob, same class as MAX_MESSAGE_LENGTH.',
	),
	'includes/class-def-core-logger.php::MAX_SQL_LENGTH' => array(
		'class' => 'system',
		'why'   => 'Bounded SQL echo in debug context, same class.',
	),
	'includes/class-def-core-logger.php::DEFAULT_MAX_ENTRIES' => array(
		'class' => 'system',
		'why'   => 'Log table retention — unbounded growth is a disk/DB problem, and rotation is reporting-safe.',
	),
	'includes/class-def-core-logs-page.php::PER_PAGE' => array(
		'class' => 'ui_chrome',
		'why'   => 'Admin log pagination size; nothing breaks at any value.',
	),
	'includes/class-def-core-media.php::MAX_BYTES' => array(
		'class' => 'system',
		'why'   => 'Acceptance bound on the inbound base64 image payload — refuses pre-decode with a stated reason; not a cap on stored content.',
	),
	'includes/class-def-core-oauth.php::PKCE_TTL' => array(
		'class' => 'system',
		'why'   => 'PKCE verifier lifetime — a security timing window.',
	),
	'includes/class-def-core-page-context.php::TERMS_CAP' => array(
		'class' => 'system',
		'why'   => 'Per-pageview context payload bound on the anonymous hot path. Honest note: it stops collecting silently — inherent to context assembly, there is no caller to refuse.',
	),
	'includes/class-def-core-site-tools.php::MAX_RESPONSE_BYTES' => array(
		'class' => 'system',
		'why'   => 'Tool-response bound protecting the LLM context window; oversize is reported in the payload.',
	),
	'includes/class-def-core-staff-ai.php::SHARE_MAX_ATTACHMENT_COUNT' => array(
		'class' => 'system',
		'why'   => 'Email transport ceiling on share attachments; refuses visibly and is documented (5.7.0).',
	),
	'includes/class-def-core-staff-ai.php::SHARE_MAX_ATTACHMENT_BYTES' => array(
		'class' => 'system',
		'why'   => 'The byte half of the same transport ceiling (~SMTP message limits).',
	),
	'includes/class-def-core-staff-ai.php::CREATE_MAX_REFERENCE_FILE_BYTES' => array(
		'class' => 'system',
		'why'   => 'Acceptance bound on the total DECODED size of reference files — refuses over-size, never slices.',
	),
	'includes/class-def-core-staff-ai.php::CREATE_MAX_REFERENCE_TEXT' => array(
		'class' => 'system',
		'why'   => 'Prompt-size bound on pasted reference text — LLM context protection.',
	),
);

// ── [1] Every cap-shaped constant is classified ──────────────────────────────
echo "\n[1] No new cap-shaped constant lands unclassified\n";

$found        = scan_named_caps();
$unclassified = array_diff( array_keys( $found ), array_keys( CAPS_REGISTRY ) );
assert_test(
	empty( $unclassified ),
	empty( $unclassified )
		? 'every cap-shaped constant is classified'
		: "UNCLASSIFIED cap-shaped constant(s):\n    " . implode( "\n    ", $unclassified ) .
		  "\n    Add each to CAPS_REGISTRY with a class and a one-line why. First answer the" .
		  "\n    doctrine's question: does this protect the SYSTEM, or ration a USER? A cap may" .
		  "\n    REFUSE, DEFER or REPORT — never silently drop. If it rations a user the class" .
		  "\n    is delete_required and the deletion is its own decided slice."
);

// ── [2] The registry carries no stale entries ────────────────────────────────
echo "\n[2] Registry shrinks with the surface — no stale entries\n";

$stale = array_diff( array_keys( CAPS_REGISTRY ), array_keys( $found ) );
assert_test(
	empty( $stale ),
	empty( $stale )
		? 'registry matches the surface'
		: "STALE registry entr(ies) — the constant no longer exists, delete the entry:\n    " . implode( "\n    ", $stale )
);

// ── [3] The token-split rule is exact — pinned against keyword additions ────
echo "\n[3] Scanner token rule: per-token, never substring\n";

assert_test( ! is_cap_shaped( 'DEF_CAPABILITIES' ), 'CAPABILITIES does not false-positive on CAP/CAPS' );
assert_test( ! is_cap_shaped( 'MAXIMUM_RETRIES' ), 'MAXIMUM does not false-positive on MAX' );
assert_test( ! is_cap_shaped( 'ENDPOINT_SLUG' ), 'an unrelated name stays out' );
assert_test( is_cap_shaped( 'PER_PAGE' ), 'PER_PAGE is cap-shaped (pagination size)' );
assert_test( is_cap_shaped( 'MAX_LEN' ), 'MAX_LEN is cap-shaped' );
assert_test( is_cap_shaped( 'PKCE_TTL' ), 'PKCE_TTL is cap-shaped (timing window)' );

// ── [4] The scan root is pinned and bites ────────────────────────────────────
echo "\n[4] Scan root pinned — the gate cannot degrade silently\n";

assert_test( is_dir( dirname( __DIR__ ) . '/includes' ), 'includes/ exists' );
assert_test( count( $found ) >= 20, 'the scan still contributes (>= 20 caps found — a near-empty scan means the gate is pointed at nothing)' );

// ── [5] Every registry class is a legal value ────────────────────────────────
echo "\n[5] Registry hygiene — classes legal, whys present\n";

$bad = array();
foreach ( CAPS_REGISTRY as $key => $entry ) {
	if ( ! in_array( $entry['class'] ?? '', array( 'system', 'ui_chrome', 'delete_required' ), true )
		|| '' === trim( $entry['why'] ?? '' ) ) {
		$bad[] = $key;
	}
}
assert_test( empty( $bad ), empty( $bad ) ? 'every entry carries a legal class and a why' : 'malformed entr(ies): ' . implode( ', ', $bad ) );

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
