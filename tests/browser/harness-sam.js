/*
 * The Setup Assistant is Sam (v8.2.4).
 *
 * Every other Digital Employee introduces itself by name — Joe in the Customer
 * Chat says "Hi Steve! I'm Joe, your AI Assistant" — while the drawer's own
 * assistant said "I'm your Setup Assistant" under a header that read only
 * "Setup Assistant". His name is fixed at Sam (the tenant portal offers renames
 * for Customer Chat and Staff-AI only), so it is a literal here, through i18n.
 *
 * The header takes the house "Name - Role" form; the greeting names him. What
 * names the PANEL rather than the speaker is deliberately left alone — the two
 * aria-labels, and the escalation subject line — and check 2 holds that line, so
 * the next sweep does not rename the dialog out from under a screen reader.
 *
 * Read off the shipped template, the shipped drawer JS and the PHP that
 * localizes it, so the checks bite by editing those three files.
 *
 * 5 checks.
 */
const fs = require('fs');
const path = require('path');
const extract = require('./extract');

const read = f => fs.readFileSync(path.join(extract.REPO, f), 'utf8');
const TPL = read('templates/setup-assistant-drawer.php');
const JS = read('assets/js/setup-assistant-drawer.js');
const PHP = read('includes/class-def-core-admin.php');

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

let n = 0;

// ── 1-2. The header, and what stays the role ────────────────────────────────
const title = /<h2 class="def-sa-title">(.*?)<\/h2>/.exec(TPL);
check(++n, 'the drawer header is "Sam - Setup Assistant"',
	!!title && /esc_html_e\( 'Sam - Setup Assistant', 'digital-employees' \)/.test(title[1]),
	title ? 'header renders: ' + title[1] : 'no .def-sa-title h2 found at all');

check(++n, 'the panel\'s aria-labels still name the role, not the speaker',
	/aria-label="<\?php esc_attr_e\( 'Setup Assistant', 'digital-employees' \); \?>"/.test(TPL) &&
	/esc_attr_e\( 'Close Setup Assistant', 'digital-employees' \)/.test(TPL));

// ── 3-4. The greeting ───────────────────────────────────────────────────────
// showWelcome's body: from its own name to the next method on the prototype.
const wStart = JS.indexOf('showWelcome = function');
const wEnd = JS.indexOf('.prototype.', wStart + 1);
const welcome = wStart === -1 ? '' : JS.slice(wStart, wEnd === -1 ? JS.length : wEnd);

check(++n, 'the greeting introduces Sam, named and unnamed',
	welcome !== '' &&
	/config\.welcomeNamed \|\| 'Hi %s! I\\'m Sam, your Setup Assistant\.'/.test(welcome) &&
	/config\.welcomeAnon \|\| 'Hi there! I\\'m Sam, your Setup Assistant\.'/.test(welcome),
	welcome === '' ? 'no showWelcome found at all' : 'greeting text has changed shape');

check(++n, 'nothing in the drawer still says "I\'m your Setup Assistant"',
	!/I\\'m your Setup Assistant/.test(JS) && !/I'm your Setup Assistant/.test(TPL));

// ── 5. Both strings reach a translator ──────────────────────────────────────
//
// A string only lands in the .pot from __( …, 'digital-employees' ) in PHP —
// the drawer's JS carries the English as a fallback, not as the shipped text.
const localized = PHP.slice(PHP.indexOf("'defSetupAssistant'"), PHP.indexOf("'defSetupAssistant'") + 1200);
check(++n, 'both greetings ship through __() on defSetupAssistant',
	/'welcomeNamed'\s*=> __\( "Hi %s! I'm Sam, your Setup Assistant\.", 'digital-employees' \)/.test(localized) &&
	/'welcomeAnon'\s*=> __\( "Hi there! I'm Sam, your Setup Assistant\.", 'digital-employees' \)/.test(localized));

console.log('\n' + results.join('\n'));
console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
process.exit(fail > 0 ? 1 : 0);
