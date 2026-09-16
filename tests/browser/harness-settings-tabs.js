/*
 * Digital Employees → Settings: the tabs, the way to the Tenant Portal (v8.2.3),
 * and the Connection status line (v8.2.4).
 *
 * The Knowledge Base tab shipped in 2026-03 with a "Knowledge Base Sync Status"
 * card whose status box was filled by nothing — no JS, no PHP, anywhere in the
 * plugin — so every owner who opened it read "Loading sync status..." forever.
 * Content sync is the tenant portal's Knowledge page. The tab is gone; the one
 * thing on it that worked, the Open Tenant Portal button, moved to Connection,
 * which is where a site owner already goes for the portal — and is the only
 * place on the page the portal is linked at all.
 *
 * Read off the shipped template, so the checks bite by editing it.
 *
 * Check 6 is the one that outlives this PR: it names no tab — it ENUMERATES the
 * $tabs keys and insists each one has a panel to open, so the next tab removed
 * without its panel (or added without one) is caught here.
 *
 * Checks 7-9 are the status line beside the green dot. It read "Last sync: 2
 * months ago" off def_core_conn_last_sync_at — an option written only when the
 * connection is established (the OAuth callback, or a manual save) and deleted
 * on disconnect. Nothing about the content sync touches it, so the label named
 * the wrong event: it is the date the site was connected, and now says so.
 *
 * Check 9 is the cost of a bare "since": it has no sentence of its own, so it
 * reads off the label beside it. On a salt-rotated site that label is
 * "Credentials error — reconnect", which $is_connected alone does not exclude —
 * the line is held to the same condition $status_class is built from.
 *
 * 9 checks.
 */
const fs = require('fs');
const path = require('path');
const extract = require('./extract');

const TEMPLATE = 'templates/admin-settings.php';
const SRC = fs.readFileSync(path.join(extract.REPO, TEMPLATE), 'utf8');

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

// ── Reading the template ────────────────────────────────────────────────────
//
// The nav renders one button per $tabs key (id="tab-<key>", aria-controls
// "panel-<key>"), so the keys ARE the tabs.
const tabsBlock = /\$tabs\s*=\s*array\(([\s\S]*?)\);/.exec(SRC);
const tabIds = tabsBlock
	? Array.from(tabsBlock[1].matchAll(/'([a-z0-9-]+)'\s*=>/g)).map(m => m[1]) : [];

// One panel's markup: from its id to the next panel's, or the end of the file.
function panel(id) {
	const start = SRC.indexOf('id="panel-' + id + '"');
	if (start === -1) return '';
	const next = SRC.indexOf('id="panel-', start + 1);
	return SRC.slice(start, next === -1 ? SRC.length : next);
}

let n = 0;

// ── 1-3. The tab is gone, and so is the box that never filled ───────────────
check(++n, 'the tab list has no Knowledge Base entry',
	tabIds.length > 0 && !tabIds.includes('knowledge-base'),
	tabIds.length === 0 ? 'no $tabs array found at all' : 'tabs: ' + tabIds.join(', '));

check(++n, 'no Knowledge Base panel is left behind for a stale link to open',
	!/panel-knowledge-base|tab-knowledge-base/.test(SRC));

check(++n, 'the status box nothing ever filled is gone with it',
	!/def-core-kb-status|def-core-kb-loading|Loading sync status/.test(SRC));

// ── 4-5. The Open Tenant Portal button, on Connection ───────────────────────
//
// Held to the PANEL, not the file: the point of the move is where it renders.
const connection = panel('connection');

check(++n, 'the Connection panel carries the Open Tenant Portal link',
	/Open Tenant Portal/.test(connection),
	connection === '' ? 'no #panel-connection found at all'
		: 'the only mention in the file is outside the panel');

check(++n, 'the link is still the OAuth DEFHO URL, rendered only when there is one',
	/DEF_Core_OAuth::get_defho_url\(\)/.test(connection) &&
	/if \( ! empty\( \$defho_url \) \)/.test(connection) &&
	/href="<\?php echo esc_url\( \$defho_url \); \?>"/.test(connection));

// ── 6. Every tab still opens something ──────────────────────────────────────
const orphans = tabIds.filter(id => panel(id) === '');
check(++n, 'every tab in the list has a panel to open (' + tabIds.length + ' tabs)',
	tabIds.length > 0 && orphans.length === 0,
	tabIds.length === 0 ? 'no $tabs array found at all'
		: 'no panel for: ' + orphans.join(', '));

// ── 7-9. The status line says when the site was connected ───────────────────
check(++n, 'the status line says "since <date>", and nothing says "Last sync"',
	/esc_html__\( 'since %s', 'digital-employees' \)/.test(connection) &&
	!/Last sync/.test(SRC),
	/Last sync/.test(SRC) ? 'the old label is still in the template'
		: 'no "since %s" in the Connection panel');

check(++n, 'the date is the site\'s own date format, not a time-ago',
	/date_i18n\( get_option\( 'date_format' \), strtotime\( \$conn_last_sync \) \)/.test(connection) &&
	!/human_time_diff/.test(connection));

// A bare "since" has to attach to the word beside it, so the line renders only
// where that word is "Connected" — the SAME condition $status_class is built
// from, credential error and all, not just $is_connected.
check(++n, 'the line is held to the connected state $status_class is built from',
	/if \( \$is_connected && ! \$has_encryption_error && ! empty\( \$conn_last_sync \) \)/.test(connection) &&
	/\$status_class = \( \$is_connected && ! \$has_encryption_error \)/.test(SRC));

console.log('\n' + results.join('\n'));
console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
process.exit(fail > 0 ? 1 : 0);
