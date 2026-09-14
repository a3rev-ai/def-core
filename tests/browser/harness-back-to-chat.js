/*
 * The "‹ Chat" control — behavioural harness (v7.9.16, row 8 canary).
 *
 * D-C3 gave the page shell one way back to the chat, and D-C4 relied on the
 * browser's back button and the phone's back gesture to be it. The installed app
 * on an iPhone has neither (Steve, 2026-09-14): a page there had no way off but
 * the menu. Every head now carries a "‹ Chat" control, and the shell leaves the
 * page for it exactly as it does for Escape.
 *
 * Runs the SHIPPED page-shell block (sliced by marker, tests/browser/extract.js)
 * inside jsdom against the SHIPPED nav and three SHIPPED page sections out of
 * templates/staff-ai-shell.php — Projects, Documents and the document viewer.
 *
 *  1. The template: every page head has the control on the line above it, and
 *     it is a button that says what it does.
 *  2. Leaving: from a page opened from the chat (a POP, history not grown), from
 *     the viewer reached page → page, and from a page entered by ADDRESS (a reload
 *     in the installed app — a push of the clean URL, never a Back out of the
 *     console). Focus lands where Escape's would (D-C6).
 *
 * 7 checks.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');
const BLOCK = extract.pageShell();
const TEMPLATE = extract.templateSource();

const HTML = `<!doctype html><html><body>
${extract.templateNav()}
<div id="conversationList"></div>
<div class="messages-container" id="messagesContainer"><div id="messageList"></div></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
${extract.templatePage('projectsPane')}
${extract.templatePage('documentsPane')}
${extract.templatePage('documentPage')}
</body></html>`;

const REG = `
	consolePages.push({ route: 'projects', el: document.getElementById('projectsPane'),
		title: document.getElementById('projectsTitle') });
	consolePages.push({ route: 'documents', el: document.getElementById('documentsPane'),
		title: document.getElementById('documentsTitle') });
	consolePages.push({ route: 'document', param: true, el: document.getElementById('documentPage'),
		title: document.getElementById('documentPageTitle') });
	return { showPage: showPage, showChat: showChat, applyRoute: applyRoute,
		open: function () { return openPage ? openPage.route : null; } };`;

function boot(startUrl) {
	const dom = new JSDOM(HTML, { url: startUrl || 'https://e.test/staff-ai/', pretendToBeVisual: true });
	const window = dom.window, document = window.document;
	const messagesContainer = document.getElementById('messagesContainer');
	const composerContainer = document.getElementById('composerContainer');
	const api = new window.Function(
		'window', 'document', 'location', 'messagesContainer', 'composerContainer', 'conversationList',
		BLOCK + REG
	)(window, document, window.location, messagesContainer, composerContainer,
		document.getElementById('conversationList'));
	return {
		window, document, api,
		back: id => document.querySelector('#' + id + ' .console-page-back'),
		chatVisible: () => messagesContainer.style.display !== 'none' && composerContainer.style.display !== 'none'
	};
}

const tick = (w, n) => new Promise(r => w.setTimeout(r, n === undefined ? 30 : n));
function click(w, el) {
	el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
}

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + label); }
	else { fail++; results.push('  FAIL ' + label + (detail ? ' -- ' + detail : '')); }
}

(async () => {
	// ---- 1. the template -------------------------------------------------------
	{
		const heads = (TEMPLATE.match(/class="console-page-head"/g) || []).length;
		const backs = (TEMPLATE.match(/class="console-page-back"/g) || []).length;
		check('every shipped page head carries the control — one per head, no head without',
			heads === 7 && backs === heads, 'heads=' + heads + ' backs=' + backs);
	}
	{
		const t = boot();
		// Above the head, not inside it: the head top-aligns its actions with its
		// first line, and the control there put the title under the buttons.
		const bad = ['projectsPane', 'documentsPane', 'documentPage'].filter(id => {
			const head = t.document.querySelector('#' + id + ' .console-page-head');
			const before = head && head.previousElementSibling;
			const column = head && head.firstElementChild;
			return !(before && before.classList.contains('console-page-back') &&
				column && column.firstElementChild && column.firstElementChild.classList.contains('console-page-title'));
		});
		check('it sits on its own line directly above the head, and the head still opens with the h1', bad.length === 0, bad.join(','));

		const b = t.back('documentsPane');
		check('it is a button that says what it does: "‹ Chat" to the eye, "Back to the chat" to a screen reader',
			!!b && b.tagName === 'BUTTON' && b.getAttribute('type') === 'button' &&
			b.textContent.replace(/\s+/g, ' ').trim() === '‹ Chat' &&
			b.getAttribute('aria-label') === 'Back to the chat' &&
			b.querySelector('span').getAttribute('aria-hidden') === 'true',
			b && (b.tagName + ' ' + JSON.stringify(b.textContent) + ' ' + b.getAttribute('aria-label')));
	}

	// ---- 2. leaving ------------------------------------------------------------
	{
		// From the chat: the console pushed the page entry, so leaving POPS it —
		// the history model harness-c1 check 3 pins for Escape, held here for the control.
		const t = boot();
		const base = t.window.history.length;
		t.api.showPage('documents'); await tick(t.window);
		const afterOpen = t.window.history.length;
		click(t.window, t.back('documentsPane')); await tick(t.window);
		check('from a page opened from the chat: back in the chat, address clean, the page entry popped — history not grown',
			t.api.open() === null && t.chatVisible() && t.window.location.hash === '' &&
			afterOpen === base + 1 && t.window.history.length === afterOpen,
			'open=' + t.api.open() + ' hash=' + JSON.stringify(t.window.location.hash) + ' len ' + base + '/' + afterOpen + '/' + t.window.history.length);
		check('focus lands on the sidebar entry of the page just left (D-C6), as it does after Escape',
			t.document.activeElement === t.document.querySelector('.sidebar-nav-item[href="#documents"]'),
			t.document.activeElement && (t.document.activeElement.id || t.document.activeElement.outerHTML.slice(0, 60)));
	}
	{
		// Page → page: the viewer opened from Documents.
		const t = boot();
		t.api.showPage('documents'); await tick(t.window);
		t.api.showPage('document/abc'); await tick(t.window);
		const onViewer = t.api.open() === 'document' && t.window.location.hash === '#document/abc';
		click(t.window, t.back('documentPage')); await tick(t.window);
		check('from the viewer reached page → page: back in the chat with a clean address',
			onViewer && t.api.open() === null && t.chatVisible() && t.window.location.hash === '',
			'onViewer=' + onViewer + ' open=' + t.api.open() + ' hash=' + JSON.stringify(t.window.location.hash));
	}
	{
		// Entered by ADDRESS — the installed app reopened on a page, or a reload. The
		// console pushed nothing, so leaving must PUSH the clean URL rather than Back
		// out of the console.
		const t = boot('https://e.test/staff-ai/#projects');
		t.api.applyRoute(); await tick(t.window);
		const onPage = t.api.open() === 'projects';
		const before = t.window.history.length;
		click(t.window, t.back('projectsPane')); await tick(t.window);
		check('from a page entered by address (reload, the installed app reopening): back in the chat by a push of the clean URL, never a Back out of the console',
			onPage && t.api.open() === null && t.chatVisible() && t.window.location.hash === '' &&
			t.window.history.length === before + 1 && /\/staff-ai\/$/.test(t.window.location.href),
			'onPage=' + onPage + ' open=' + t.api.open() + ' hash=' + JSON.stringify(t.window.location.hash) + ' len ' + before + '/' + t.window.history.length);
	}

	console.log('harness-back-to-chat: ' + pass + ' passed, ' + fail + ' failed');
	results.forEach(r => console.log(r));
	process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
