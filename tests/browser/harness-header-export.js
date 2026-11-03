/*
 * The Staff AI header carries no Export (v9.1.5, W68 take-out S3 / D3).
 *
 * The header's Export button never had a backend: it shipped disabled, and the
 * overflow menu's Export only clicked it. Privacy Requests' take-out and the
 * memory-import page cover the case, so both go. Share stays.
 *
 * Runs the SHIPPED header markup (div.header-actions cut out of
 * templates/staff-ai-shell.php) and the SHIPPED overflow-menu block of
 * assets/js/staff-ai.js inside jsdom.
 *
 * 4 checks. Bite: on 9.1.4 checks 1 and 4 fail.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

// The two links' hrefs are WordPress URLs, not escaped strings; a stand-in keeps
// phpToHtml's "no other PHP" rule for everything else in the header.
const HEADER = extract.phpToHtml(
	extract.element(extract.templateSource(), 'div',
		l => l.includes('class="header-actions"'), 'div.header-actions')
		.replace(/<\?php echo esc_url\([\s\S]*?\?>/g, '#'),
	'div.header-actions');

const OVERFLOW = extract.slice('header overflow menu',
	l => l.includes('// Header overflow menu (mobile)'),
	l => l.includes('// ─── PWA Install'),
	['headerOverflowMenu', 'overflowShare', 'shareBtn.click()']);

const dom = new JSDOM('<!doctype html><html><body>' + HEADER + '</body></html>');
const document = dom.window.document;
const shareBtn = document.getElementById('shareBtn');
let shares = 0;
if (shareBtn) {
	shareBtn.disabled = false;   // the page enables it once a conversation is open
	shareBtn.addEventListener('click', () => { shares++; });
}
new dom.window.Function('document', 'shareBtn', 'toggleTheme', 'triggerPwaInstall', OVERFLOW)(
	document, shareBtn, () => {}, () => {});

let n = 0;
const menu = document.getElementById('headerOverflowMenu');
const labels = el => Array.from(el.querySelectorAll('button, a')).map(b => b.textContent.trim());

check(++n, 'the header renders no #exportBtn and no #overflowExport, and no control reads Export',
	!document.getElementById('exportBtn') && !document.getElementById('overflowExport') &&
	!labels(document.body).includes('Export'),
	'labels: ' + JSON.stringify(labels(document.body)));

check(++n, 'Share is still in the header and in the overflow menu',
	!!shareBtn && shareBtn.textContent.trim() === 'Share' &&
	!!menu && labels(menu).includes('Share'));

menu.classList.add('open');
document.getElementById('overflowShare').click();
check(++n, 'the overflow menu\'s Share clicks the header Share once and closes the menu',
	shares === 1 && !menu.classList.contains('open'), 'shares=' + shares);

check(++n, 'the overflow block wires nothing to an Export control',
	!/export/i.test(OVERFLOW));

console.log('harness-header-export: no Export in the Staff AI header (v9.1.5)');
results.forEach(r => console.log(r));
console.log(pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
