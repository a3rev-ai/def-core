/*
 * The Connection Logs table fits a phone (S1) — the stylesheet and the shipped markup.
 *
 * The table kept its desktop width on a phone and pushed the DOCUMENT wider than
 * the screen. That is not only a sideways scroll: every position:fixed box on the
 * page, the Setup Assistant drawer included, anchors to the widened document,
 * which is how the drawer's close button ended up off the right-hand edge in
 * landscape. Under the inline table-layout: fixed it also left Message narrower
 * than a word, so the message read one letter per line.
 *
 * The fix is one wrapper, .def-core-table-scroll, and a floor under the table and
 * under Message, so a phone scrolls the TABLE rather than the page. This file
 * holds both halves of that to the shipped files:
 *
 *  - the rule, read out of assets/css/def-core-admin.css through extract.cssRules;
 *  - the containment, read out of includes/class-def-core-logs-page.php by slicing
 *    the wrapper element with extract.element, which counts nesting. Nothing here
 *    is a copy of the markup: move the table out of the wrapper and the slice no
 *    longer contains it.
 *
 * The User Access grid on Settings → User Roles has the same disease and is NOT
 * treated here — that table is S3's, and nothing in this PR touches it.
 *
 * Check 7 is the one that outlives this PR. It names no table — it ENUMERATES
 * from the stylesheet every .def-core-*-table given a min-width and insists each
 * one is wrapped somewhere in the shipped markup. The next table floored without
 * a wrapper is the exact bug this PR fixes, and it fails here without anyone
 * remembering to add a case for it. The settings template stays in SOURCES for
 * that reason, so the enumeration holds across both pages and not just this one —
 * which is what will catch S3 if it floors the roles grid and forgets the wrapper.
 *
 * Checks 10-11 were re-pointed by S4 (the column collapses to a rail): the close
 * button is no longer hidden above 783px, it is the Collapse control, so the check
 * that used to insist the dock was the only thing hiding it now insists nothing
 * hides it at all. 11 asserts the column it collapses from is still 380px wide.
 *
 * 12 checks.
 */
const fs = require('fs');
const path = require('path');
const extract = require('./extract');

const ADMIN_CSS = fs.readFileSync(path.join(extract.REPO, 'assets/css/def-core-admin.css'), 'utf8');
const DRAWER_CSS = fs.readFileSync(path.join(extract.REPO, 'assets/css/setup-assistant-drawer.css'), 'utf8');
const INDEX = extract.cssRules(ADMIN_CSS);

const LOGS_FILE = 'includes/class-def-core-logs-page.php';

// The shipped files that render an admin table. Only the log table is wrapped in
// this PR; the settings template is listed so check 6's enumeration covers it too.
const SOURCES = {
	'templates/admin-settings.php':
		fs.readFileSync(path.join(extract.REPO, 'templates/admin-settings.php'), 'utf8'),
	[LOGS_FILE]: fs.readFileSync(path.join(extract.REPO, LOGS_FILE), 'utf8')
};

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

// ── Reading the stylesheet ──────────────────────────────────────────────────
const rulesOf = cls => Array.from(INDEX.byClass.get(cls) || []).map(id => INDEX.rules[id]);
const declsOf = rule => rule.body.split(';').map(d => d.replace(/\s+/g, ' ').trim()).filter(Boolean);

// Every declaration of `prop` on any rule this class takes part in.
function declared(cls, prop) {
	const out = [];
	rulesOf(cls).forEach(r => declsOf(r).forEach(d => {
		const m = new RegExp('^' + prop + ':\\s*(.+)$').exec(d);
		if (m) out.push(m[1].trim());
	}));
	return out;
}
const px = v => { const m = /^(\d+(?:\.\d+)?)px$/.exec(v); return m ? Number(m[1]) : null; };

let n = 0;

// ── 1-3. The wrapper ────────────────────────────────────────────────────────
//
// overflow-x is the whole mechanism; max-width is what stops the wrapper itself
// growing to its content and re-widening the page it was added to contain.
check(++n, '.def-core-table-scroll scrolls sideways (overflow-x: auto)',
	declared('def-core-table-scroll', 'overflow-x').includes('auto'),
	'got: ' + JSON.stringify(declared('def-core-table-scroll', 'overflow-x')));

check(++n, '.def-core-table-scroll cannot itself outgrow the page (max-width: 100%)',
	declared('def-core-table-scroll', 'max-width').includes('100%'),
	'got: ' + JSON.stringify(declared('def-core-table-scroll', 'max-width')));

check(++n, '.def-core-table-scroll keeps momentum scrolling on older iOS',
	declared('def-core-table-scroll', '-webkit-overflow-scrolling').includes('touch'));

// ── 4-5. The floors ─────────────────────────────────────────────────────────
//
// A floor is what turns "the table shrinks until it is unreadable" into "the
// table stays readable and the wrapper scrolls". Held to a number, not merely to
// being declared: a 1px min-width would satisfy `has a min-width` and fix nothing.
const READABLE = 400;

const logsFloor = declared('def-core-logs-table', 'min-width').map(px).filter(Boolean);
check(++n, 'the Connection Logs table has a readable floor, so a phone scrolls the TABLE not the page',
	logsFloor.some(v => v >= READABLE),
	'min-width: ' + JSON.stringify(declared('def-core-logs-table', 'min-width')));

// The column that broke: under table-layout:fixed it was left narrower than a
// word and read one letter per line. Its own floor, wide enough for a sentence.
const msgFloor = declared('def-core-log-message', 'min-width').map(px).filter(Boolean);
check(++n, 'the Message column has a floor wide enough to read as words (>= 200px)',
	msgFloor.some(v => v >= 200),
	'min-width: ' + JSON.stringify(declared('def-core-log-message', 'min-width')));

// ── 6. Containment, out of the shipped markup ───────────────────────────────
//
// extract.element slices the wrapper by counting <div> nesting, so what comes
// back is the wrapper's real extent. A table that sits after the wrapper's
// closing tag is simply not in the slice.
function scrollers(file) {
	const lines = SOURCES[file].split(/\r?\n/);
	const out = [];
	lines.forEach(function (line, i) {
		if (!/<div class="def-core-table-scroll">/.test(line)) return;
		// Sliced from THIS opener, so a second wrapper in the same file is its own
		// element rather than being swallowed by the first one's slice.
		out.push(extract.element(lines.slice(i).join('\n'), 'div',
			(l, j) => j === 0, file + ' .def-core-table-scroll #' + out.length));
	});
	return out;
}
const wraps = cls => chunk => new RegExp('<table class="[^"]*\\b' + cls + '\\b').test(chunk);

check(++n, 'the Connection Logs table is INSIDE a .def-core-table-scroll in ' + LOGS_FILE,
	scrollers(LOGS_FILE).some(wraps('def-core-logs-table')));

// ── 7. The rule that outlives this PR ───────────────────────────────────────
//
// Enumerated from the stylesheet, not listed here: any .def-core-*-table given a
// floor has to be wrapped somewhere in the shipped markup, or that floor is just
// a wider page. (A table with no floor is not in scope and is not asked to be
// wrapped — .def-core-tools-table stacks its cells on a phone instead, and
// .def-core-roles-table is S3's to deal with.)
const floored = [];
INDEX.rules.forEach(function (r) {
	if (!/(?:^|;)\s*min-width:/.test(r.body)) return;
	const m = /^\.(def-core-[a-z0-9-]*table)$/.exec(r.selector.trim());
	if (m) floored.push(m[1]);
});
const allScrollers = Object.keys(SOURCES).reduce((a, f) => a.concat(scrollers(f)), []);
const unwrapped = Array.from(new Set(floored)).filter(cls => !allScrollers.some(wraps(cls)));
check(++n, 'every .def-core-*-table given a min-width is wrapped in a scroller (' +
	Array.from(new Set(floored)).join(', ') + ')',
	floored.length > 0 && unwrapped.length === 0,
	floored.length === 0 ? 'no floored tables found at all — the floor has gone'
		: 'unwrapped: ' + unwrapped.join(', '));

// ── 8-11. The drawer, after the page stops being wider than the screen ──────
//
// S1 scope 2 is a verification, and this is the half of it that is readable off
// the CSS: with the document no longer wider than the viewport, the drawer's own
// rules never take it past the screen edge. In portrait it is the full width of
// the viewport and no more; the container is pinned to all four edges rather than
// given a width of its own, so it is the size of whatever it anchors to — which is
// exactly why the widened document dragged it off-screen, and why nothing in the
// drawer's stylesheet needed to change once the document fits.

// A media block by brace depth, so the rules inside it can be told from the rules
// outside it — which is the whole question for the close button below. Both this
// and ruleBody moved into extract.js with S4, which reads the same stylesheet for
// the collapse rules: one reader, so the two files cannot disagree about it.
const { mediaBlock, ruleBody, RULE_START } = extract;

const PHONE = mediaBlock(DRAWER_CSS, /@media \(max-width: 600px\)/);
const phonePanel = ruleBody(PHONE.text, '.def-sa-panel');
check(++n, 'in portrait the drawer panel is exactly the width of the screen, never more',
	!!phonePanel && /(?:^|;)\s*width:\s*100%/.test(phonePanel),
	'got: ' + String(phonePanel).replace(/\s+/g, ' ').trim());

const inset = ruleBody(DRAWER_CSS, '.def-sa-drawer') || '';
check(++n, 'the drawer container takes its size from its four edges, not from a width of its own',
	/position:\s*fixed/.test(inset) && ['top', 'right', 'bottom', 'left']
		.every(e => new RegExp('(?:^|;)\\s*' + e + ':\\s*0').test(inset)) &&
		!/(?:^|;)\s*width:/.test(inset),
	'got: ' + inset.replace(/\s+/g, ' ').trim());

// The close button. It used to be display: none above 783px — the docked column
// had nothing to collapse to, so the desktop deliberately had no ×, and this check
// held that the dock was the ONLY thing hiding it, because below the breakpoint the
// × must be reachable (a band a phone reaches in landscape).
//
// S4 gave the column a rail to collapse to, so that rule is gone and the same button
// is the desktop Collapse control. The line this check exists to hold is unchanged and
// now simply stricter: NOTHING may hide it, at any width. A future breakpoint that
// reaches for display: none on it fails here whichever side of 783px it sits.
const DOCK = mediaBlock(DRAWER_CSS, /@media \(min-width: 783px\) \{/);
const hides = [];
// String.raw, not a quoted string: '\.def-sa-close\s*\{' is silently the same
// as 'sdef-sa-closes*{' once JS has read the escapes, which compiles to a regex
// that matches nothing and leaves this check passing on an empty result set.
const CLOSE_RULE = new RegExp(RULE_START + String.raw`\.def-sa-close\s*\{([^}]*)\}`, 'g');
let cm;
while ((cm = CLOSE_RULE.exec(DRAWER_CSS)) !== null) {
	if (/(?:^|;)\s*display:\s*none/.test(cm[1])) hides.push(cm.index);
}
check(++n, 'nothing in the drawer stylesheet hides the close button at any width',
	hides.length === 0,
	'hidden at offsets: ' + hides.join(', ') +
		' (S4 made this button the desktop Collapse control — it is shown, not hidden)');

// And the dock is still the dock: the column the rail collapses FROM.
check(++n, 'the desktop column still reserves its width at 783px and up',
	/(?:^|;)\s*margin-right:\s*380px/.test(ruleBody(DOCK.text, '#wpcontent') || ''),
	'got: ' + String(ruleBody(DOCK.text, '#wpcontent')).replace(/\s+/g, ' ').trim());

// And the drawer is actually reachable from this page at all — the trigger the
// close button belongs to. Read off the shipped renderer, not the stylesheet.
check(++n, 'the Connection Logs page renders the drawer trigger the close button pairs with',
	/id="def-setup-assistant-trigger"/.test(SOURCES[LOGS_FILE]) &&
	/setup-assistant-drawer\.php/.test(SOURCES[LOGS_FILE]));

console.log('\n' + results.join('\n'));
console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
process.exit(fail > 0 ? 1 : 0);
