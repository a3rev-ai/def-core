/*
 * The document viewer becomes a page (C4 / v7.9.1) — behavioural harness.
 * Runs the SHIPPED page-shell block AND the SHIPPED initDocumentViewer block
 * (both extracted by marker) inside jsdom, against the #documentPage section
 * sliced out of templates/staff-ai-shell.php. Nothing here is a copy of the
 * code or the markup under test.
 *
 * The viewer is the first page whose route carries an id, so most of what is
 * checked here is the difference between a box something opens FOR you and a
 * place you can arrive at cold: a reload, a link, a back button.
 *
 * 26 checks.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const REPO = path.resolve(__dirname, '..', '..');
const SHELL = extract.pageShell();
const VIEWER = extract.documentViewer();

// #documentsPane stands in for "the page the user came from" — the checks are
// about the shell and the viewer, so nothing asserts against its insides.
const HTML = `<!doctype html><html><body>
${extract.templateNav()}
<div id="conversationList"></div>
<div class="messages-container" id="messagesContainer"><div id="messagesList"></div></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
<section class="console-page" id="documentsPane" hidden>
  <h1 class="console-page-title" id="documentsTitle" tabindex="-1">My documents</h1>
</section>
${extract.templatePage('documentPage')}
</body></html>`;

const SHELL_TAIL = `
	consolePages.push({ route: 'documents', el: document.getElementById('documentsPane'),
		title: document.getElementById('documentsTitle') });
	return { showPage: showPage, showChat: showChat, applyRoute: applyRoute,
		consolePages: consolePages,
		open: function () { return openPage ? openPage.route : null; } };`;

const VIEWER_TAIL = `
	return { open: openDocumentViewer };`;

// Every document answers with its OWN title, text and download link, so a check
// can tell whose content is on screen — which is the whole subject of one page
// serving many documents.
const docFor = (id) => ({
	document_id: id,
	title: 'Title of ' + id,
	file_type: 'md',
	version: 3,
	download_url: 'https://e.test/staff-ai-download/tenant-1/' + id + '.md'
});
// The text also looks like markup: the <pre> takes it via textContent, so it
// must stay literal (D-P7 — a document is untrusted content).
const textFor = (id) => 'Body of ' + id + ' <b>not markup</b>';
const TAIL_TEXT = ' — second half';

function boot(startUrl, opts) {
	opts = opts || {};
	const dom = new JSDOM(HTML, { url: startUrl || 'https://e.test/staff-ai/', pretendToBeVisual: true });
	const window = dom.window, document = window.document, location = window.location;
	const requests = [];

	const shellFactory = new window.Function('window', 'document', 'location',
		'messagesContainer', 'composerContainer', 'conversationList', 'calls',
		SHELL + SHELL_TAIL);
	const api = shellFactory(window, document, location,
		document.getElementById('messagesContainer'),
		document.getElementById('composerContainer'),
		document.getElementById('conversationList'), []);

	// A held request is one still in flight when the reader moves on — the case
	// that decides whether a late read can write into the document now on screen.
	const held = [];
	async function apiRequest(url) {
		requests.push(url);
		if (opts.fail) throw new Error('DEF said no');
		const id = (url.match(/documents\/([^/?]+)/) || [])[1] || '';
		const second = /offset=/.test(url);
		const body = {
			document: Object.assign(docFor(id), opts.doc || {}),
			content: second ? TAIL_TEXT : textFor(id),
			offset: second ? textFor(id).length : 0,
			total_chars: textFor(id).length + TAIL_TEXT.length,
			truncated: !second,
			next_offset: second ? null : textFor(id).length
		};
		if (opts.hold && opts.hold(url)) {
			return new Promise(function (resolve) { held.push(function () { resolve(body); }); });
		}
		return body;
	}

	const names = ['window', 'document', 'consolePages', 'showPage', 't', 'apiRequest',
		'apiBase', 'safeHttpHref', 'openDocumentViewer'];
	const viewer = new window.Function(...names, VIEWER + VIEWER_TAIL)(
		window, document, api.consolePages, api.showPage,
		function (key, def) { return def; },
		apiRequest,
		'/wp-json/a3-ai/v1/staff-ai',
		function (url) {                               // safeHttpHref, as shipped
			if (typeof url !== 'string') return '';
			try {
				const u = new window.URL(url, 'https://e.test');
				return (u.protocol === 'http:' || u.protocol === 'https:') ? u.href : '';
			} catch (e) { return ''; }
		},
		null);

	const $ = (id) => document.getElementById(id);
	return {
		window, document, location, api, requests,
		openFromCard: viewer.open,
		release: () => { held.splice(0).forEach((fn) => fn()); },
		page: () => $('documentPage'),
		title: () => $('documentPageTitle').textContent,
		status: () => $('documentViewerStatus').textContent,
		text: () => $('documentViewerText').textContent,
		html: () => $('documentViewerText').innerHTML,
		more: () => $('documentViewerMore'),
		download: () => $('documentViewerDownload'),
		shown: (id) => !$(id).hidden,
		click: (el) => el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }))
	};
}

const tick = () => new Promise(r => setImmediate(r));
// jsdom dispatches hashchange/popstate on a real timer, so Back cannot be waited
// for with a resolved promise. Waiting for the STATE rather than for a fixed
// number of milliseconds: a fixed wait is a flake on a loaded CI box, and a
// check that fails at random is worse than one that does not exist.
async function until(w, fn, ms) {
	const deadline = Date.now() + (ms || 2000);
	while (!fn() && Date.now() < deadline) {
		await new Promise(r => w.setTimeout(r, 5));
	}
	return fn();
}

let pass = 0, fail = 0;
const results = [];
function check(label, ok, detail) {
	if (ok) { pass++; results.push('  ok   ' + label); }
	else { fail++; results.push('  FAIL ' + label + (detail ? ' — ' + detail : '')); }
}

(async function () {
	// ── Opened from a card, as it always was ───────────────────────────────
	{
		const t = boot();
		t.api.showPage('documents');
		t.openFromCard('doc-1', 'Runsheet (as the card knew it)');
		check('the id rides the address, so the page can be linked to and reloaded',
			t.location.hash === '#document/doc-1', t.location.hash);
		check('the viewer is showing and the page it was opened from is not',
			t.shown('documentPage') && !t.shown('documentsPane'));
		check('the title the card already knew shows while the document loads',
			t.title() === 'Runsheet (as the card knew it)', t.title());
		check('focus is on the page title, as on every other page (D-C6)',
			t.document.activeElement === t.document.getElementById('documentPageTitle'));

		await tick();
		check('the document\'s own title replaces it once loaded',
			t.title() === docFor('doc-1').title, t.title());
		check('the status line names the type, the version and the size',
			t.status() === 'MD · v3 · %s characters'.replace('%s',
				String(textFor('doc-1').length + TAIL_TEXT.length)), t.status());
		check('the request asked for the id in the address',
			t.requests[0] === '/documents/doc-1/content', t.requests[0]);
		check('the text is TEXT — markup inside a document stays literal',
			t.text() === textFor('doc-1') && t.html().indexOf('<b>') === -1, t.html());
		check('Download points at the link the DOCUMENT carried, not one an opener passed',
			t.download().style.display === '' && t.download().href === docFor('doc-1').download_url,
			t.download().href);

		// ── Show more ──────────────────────────────────────────────────────
		check('Show more is offered while there is more', t.more().style.display === '');
		t.click(t.more());
		await tick();
		check('it appends the rest rather than replacing what was read',
			t.text() === textFor('doc-1') + TAIL_TEXT, t.text());
		check('and goes away at the end of the document', t.more().style.display === 'none');
	}

	// ── Back returns to the opener ─────────────────────────────────────────
	{
		const t = boot();
		t.api.showPage('documents');
		t.openFromCard('doc-1', 'Runsheet');
		await tick();
		t.window.history.back();                 // the button, and the phone's gesture
		await until(t.window, () => t.api.open() === 'documents');
		check('back from the viewer returns to the page it was opened from',
			t.api.open() === 'documents' && t.shown('documentsPane') && !t.shown('documentPage'),
			'open=' + t.api.open() + ' hash=' + JSON.stringify(t.location.hash));
	}

	// ── D-C5: the chat is hidden, never reset ──────────────────────────────
	{
		const t = boot();
		const composer = t.document.getElementById('composerInput');
		composer.value = 'half a question';
		t.openFromCard('doc-1', 'Runsheet');
		await tick();
		check('the chat is hidden while the document is open',
			t.document.getElementById('messagesContainer').style.display === 'none');
		t.api.showChat();
		check('and a half-typed message is still there on return',
			composer.value === 'half a question' &&
			t.document.getElementById('messagesContainer').style.display === '', composer.value);
	}

	// ── Arriving COLD: a reload, or a link Sue sent ────────────────────────
	{
		const t = boot('https://e.test/staff-ai/#document/doc-1');
		t.api.applyRoute();
		check('a reload of the route opens the viewer with no opener to help it',
			t.shown('documentPage'));
		await tick();
		check('and the title, the status and the download link all come from the load',
			t.title() === docFor('doc-1').title
			&& t.download().href === docFor('doc-1').download_url, t.title());
	}

	// ── Routes that name no document ───────────────────────────────────────
	{
		const t = boot('https://e.test/staff-ai/#document');
		t.api.applyRoute();
		check('a bare #document names no document and lands in the chat, not an error',
			t.api.open() === null && !t.shown('documentPage'));
	}
	{
		const t = boot('https://e.test/staff-ai/#document/');
		t.api.applyRoute();
		check('an empty id does the same', t.api.open() === null && !t.shown('documentPage'));
	}
	{
		const t = boot('https://e.test/staff-ai/#document/%E0%A4%A');   // malformed
		t.api.applyRoute();
		await tick();
		check('a malformed id says it cannot be read instead of asking for `//content`',
			t.requests.length === 0 && /Could not read/.test(t.status()), t.status());
	}

	// ── Leaving the page ───────────────────────────────────────────────────
	{
		const t = boot();
		t.openFromCard('doc-1', 'Runsheet');
		// Settled, not just ticked: leaving a page in the same millisecond it was
		// opened races the hash echo, which is a different subject (see the PR).
		await until(t.window, () => t.more().style.display === '');
		const before = t.requests.length;
		t.api.showChat();
		t.click(t.more());
		await tick();
		check('Show more after leaving appends nothing — no other document can be grown',
			t.requests.length === before, 'before=' + before + ' after=' + JSON.stringify(t.requests));
	}

	// ── One page, many documents: a read that lands late ───────────────────
	{
		// Documents → View A → Show more on a big one → Back → View B, and A's
		// read only lands now. Nothing of A may appear under B.
		const t = boot(null, { hold: (url) => /doc-a/.test(url) });
		t.openFromCard('doc-a', 'Doc A');
		await tick();
		t.openFromCard('doc-b', 'Doc B');
		await until(t.window, () => t.title() === docFor('doc-b').title);
		t.release();
		await tick();
		check('a read that lands after the reader moved on writes nothing into the document now open',
			t.text() === textFor('doc-b') && t.title() === docFor('doc-b').title
			&& t.download().href === docFor('doc-b').download_url,
			'text=' + JSON.stringify(t.text()) + ' title=' + JSON.stringify(t.title()));
		t.click(t.more());
		await tick();
		check('and it does not hand its own next offset to that document either',
			t.requests[t.requests.length - 1].indexOf('/documents/doc-b/content?offset=') === 0,
			t.requests[t.requests.length - 1]);
	}

	// ── Leaving the viewer, which has no sidebar entry of its own ──────────
	{
		const t = boot();
		t.openFromCard('doc-1', 'Runsheet');
		await tick();
		t.api.showChat();
		check('leaving puts focus in the chat, never on the hidden title (D-C6)',
			t.document.activeElement === t.document.querySelector('#composerContainer textarea'),
			t.document.activeElement && t.document.activeElement.id);
	}

	// ── A download link that is not a link ─────────────────────────────────
	{
		const t = boot(null, { doc: { download_url: 'javascript:alert(1)' } });
		t.openFromCard('doc-1', 'Runsheet');
		await tick();
		check('a download_url that is not http(s) is refused at the sink',
			t.download().style.display === 'none', t.download().getAttribute('href'));
	}

	// ── The failure the reader sees ────────────────────────────────────────
	{
		const t = boot(null, { fail: true });
		t.openFromCard('doc-1', 'Runsheet');
		await tick();
		check('a read that fails says so on the page and offers no download',
			/Could not read|DEF said no/.test(t.status()) && t.download().style.display === 'none',
			t.status());
	}

	console.log('C4 — the document viewer becomes a page');
	results.forEach(line => console.log(line));
	console.log('  ' + pass + ' passed, ' + fail + ' failed');
	process.exit(fail ? 1 : 0);
})();
