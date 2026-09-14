/*
 * The "‹ Back" control — behavioural harness (v7.9.16, corrected in v7.9.17).
 *
 * D-C3 gave the page shell one way back, and D-C4 relied on the browser's back
 * button and the phone's back gesture to be it. The installed app on an iPhone has
 * neither (Steve, 2026-09-14): a page there had no way off but the menu. 7.9.16
 * gave every page a "‹ Chat" that always returned to the chat — and from an open
 * document that skipped the Documents it was opened from (Steve's canary, the same
 * day). Now it is Back: a POP while the console stands on an entry it pushed
 * itself, the chat otherwise.
 *
 * Runs the SHIPPED page-shell block (sliced by marker, tests/browser/extract.js)
 * inside jsdom against the SHIPPED nav and three SHIPPED page sections out of
 * templates/staff-ai-shell.php — Projects, Documents and the document viewer.
 *
 *  1. The template: every page carries the control on the line above its head, a
 *     button in the kit's secondary style that says Back.
 *  2. Back: from a page opened from the chat (a POP); from the viewer opened from
 *     Documents — to DOCUMENTS, then to the chat, both pops, history never grown;
 *     from a page entered by ADDRESS (a reload, the installed app reopening) — to
 *     the chat by a push of the clean URL, never out of the console; and after the
 *     browser's own Back landed on a page, still the chat and still inside.
 *     Focus lands where the shell puts it (D-C6).
 *
 * 10 checks.
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
		hash: () => window.location.hash,
		len: () => window.history.length,
		chatVisible: () => messagesContainer.style.display !== 'none' && composerContainer.style.display !== 'none'
	};
}

const tick = (w, n) => new Promise(r => w.setTimeout(r, n === undefined ? 30 : n));
function click(w, el) {
	el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
}
// jsdom fires hashchange on a real timer after history.back(): wait for the STATE.
async function until(w, fn, ms) {
	const end = Date.now() + (ms || 1500);
	while (!fn()) {
		if (Date.now() > end) return false;
		await tick(w, 10);
	}
	return true;
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
		const backs = (TEMPLATE.match(/console-page-back"/g) || []).length;
		check('every shipped page carries the control — one per head, no head without',
			heads === 7 && backs === heads, 'heads=' + heads + ' backs=' + backs);
	}
	{
		const t = boot();
		const bad = ['projectsPane', 'documentsPane', 'documentPage'].filter(id => {
			const head = t.document.querySelector('#' + id + ' .console-page-head');
			const before = head && head.previousElementSibling;
			const column = head && head.firstElementChild;
			return !(before && before.classList.contains('console-page-back') &&
				column && column.firstElementChild && column.firstElementChild.classList.contains('console-page-title'));
		});
		check('it sits on its own line directly above the head, and the head still opens with the h1', bad.length === 0, bad.join(','));

		const b = t.back('documentsPane');
		check('it is a button in the kit\'s secondary style that says "‹ Back", the chevron hidden from a screen reader',
			!!b && b.tagName === 'BUTTON' && b.getAttribute('type') === 'button' &&
			b.classList.contains('modal-btn') && b.classList.contains('modal-btn-secondary') &&
			b.textContent.replace(/\s+/g, ' ').trim() === '‹ Back' &&
			b.querySelector('span').getAttribute('aria-hidden') === 'true',
			b && (b.className + ' ' + JSON.stringify(b.textContent)));
	}

	// ---- 2. Back ---------------------------------------------------------------
	{
		// From the chat: the console pushed the page entry, so Back POPS it.
		const t = boot();
		const base = t.len();
		t.api.showPage('documents'); await tick(t.window);
		const afterOpen = t.len();
		click(t.window, t.back('documentsPane'));
		const landed = await until(t.window, () => t.api.open() === null);
		check('from a page opened from the chat: back in the chat, address clean, the page entry popped — history not grown',
			landed && t.chatVisible() && t.hash() === '' && afterOpen === base + 1 && t.len() === afterOpen,
			'open=' + t.api.open() + ' hash=' + JSON.stringify(t.hash()) + ' len ' + base + '/' + afterOpen + '/' + t.len());
		check('focus lands on the sidebar entry of the page just left (D-C6)',
			t.document.activeElement === t.document.querySelector('.sidebar-nav-item[href="#documents"]'),
			t.document.activeElement && (t.document.activeElement.id || t.document.activeElement.outerHTML.slice(0, 60)));
	}
	{
		// The case Steve hit: a document opened from Documents. Back goes to DOCUMENTS,
		// not the chat; a second Back goes to the chat; both are pops.
		const t = boot();
		const base = t.len();
		t.api.showPage('documents'); await tick(t.window);
		t.api.showPage('document/abc'); await tick(t.window);
		const onViewer = t.api.open() === 'document' && t.hash() === '#document/abc' && t.len() === base + 2;
		click(t.window, t.back('documentPage'));
		const backOnDocuments = await until(t.window, () => t.api.open() === 'documents');
		check('from a document opened from Documents: Back returns to DOCUMENTS, by a pop, with the Documents title focused',
			onViewer && backOnDocuments && t.hash() === '#documents' && t.len() === base + 2 &&
			t.document.activeElement === t.document.getElementById('documentsTitle'),
			'onViewer=' + onViewer + ' open=' + t.api.open() + ' hash=' + JSON.stringify(t.hash()) + ' len=' + t.len() + ' focus=' + (t.document.activeElement && t.document.activeElement.id));
		click(t.window, t.back('documentsPane'));
		const backInChat = await until(t.window, () => t.api.open() === null);
		check('a second Back from there: the chat, again by a pop — history never grew',
			backInChat && t.chatVisible() && t.hash() === '' && t.len() === base + 2,
			'open=' + t.api.open() + ' hash=' + JSON.stringify(t.hash()) + ' len=' + t.len());
	}
	{
		// Entered by ADDRESS — the installed app reopened on a page, or a reload. The
		// console pushed nothing, so Back must PUSH the clean URL rather than walk out
		// of the console.
		const t = boot('https://e.test/staff-ai/#projects');
		t.api.applyRoute(); await tick(t.window);
		const onPage = t.api.open() === 'projects';
		const before = t.len();
		click(t.window, t.back('projectsPane')); await tick(t.window);
		check('from a page entered by address (reload, the installed app reopening): the chat by a push of the clean URL, never a Back out of the console',
			onPage && t.api.open() === null && t.chatVisible() && t.hash() === '' &&
			t.len() === before + 1 && /\/staff-ai\/$/.test(t.window.location.href),
			'onPage=' + onPage + ' open=' + t.api.open() + ' hash=' + JSON.stringify(t.hash()) + ' len ' + before + '/' + t.len());
	}
	{
		// The browser's own Back landed on a page: the console no longer owns the
		// entry, so the control takes the safe way — the chat, still inside. (A push
		// here replaces the forward entry, so history.length cannot tell it from a
		// pop; the outcome is what is pinned.)
		const t = boot();
		t.api.showPage('documents'); await tick(t.window);
		t.api.showPage('document/abc'); await tick(t.window);
		t.window.history.back();
		const onDocuments = await until(t.window, () => t.api.open() === 'documents');
		click(t.window, t.back('documentsPane')); await tick(t.window);
		check("after the browser's own Back landed on Documents: the control still leaves for the chat and stays inside the console",
			onDocuments && t.api.open() === null && t.chatVisible() && t.hash() === '' &&
			/\/staff-ai\/$/.test(t.window.location.href),
			'onDocuments=' + onDocuments + ' open=' + t.api.open() + ' hash=' + JSON.stringify(t.hash()) + ' href=' + t.window.location.href);
	}

	{
		// The bottom of the console's own stack is NOT always the chat (the #357
		// Code leg): the installed app reopened on Documents (an address entry),
		// then a document (push), then Projects (push), then Back (pop, onto our
		// own first push). Leaving for the chat from there — Escape, a conversation,
		// New chat — must SHOW the chat, not pop onto the Documents underneath.
		const t = boot('https://e.test/staff-ai/#documents');
		t.api.applyRoute(); await tick(t.window);
		t.api.showPage('document/abc'); await tick(t.window);
		t.api.showPage('projects'); await tick(t.window);
		click(t.window, t.back('projectsPane'));
		const backOnViewer = await until(t.window, () => t.api.open() === 'document');
		t.api.showChat(); await tick(t.window, 120);
		check('reopened on a page by address, two pages deep, one Back, then leave for the chat: the CHAT shows with a clean address — not the page underneath',
			backOnViewer && t.api.open() === null && t.chatVisible() && t.hash() === '' && /\/staff-ai\/$/.test(t.window.location.href),
			'backOnViewer=' + backOnViewer + ' open=' + t.api.open() + ' chat=' + t.chatVisible() + ' hash=' + JSON.stringify(t.hash()));
	}

	console.log('harness-page-back: ' + pass + ' passed, ' + fail + ' failed');
	results.forEach(r => console.log(r));
	process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
