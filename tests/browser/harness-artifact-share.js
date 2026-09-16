/*
 * Artifacts A-3 (the share link, DEF #1255 / D-A7, D-A4) — behavioural harness.
 * Runs the SHIPPED page shell, the SHIPPED document viewer, the SHIPPED Documents
 * block, the SHIPPED Artifacts block and the SHIPPED module-level artifact block
 * (each sliced by marker) inside jsdom, against the page sections cut out of
 * templates/staff-ai-shell.php. Nothing here is a copy of the code or the markup.
 *
 * What is pinned: the list forwards `share`; Share calls the POST and shows the
 * link built from the origin PHP handed the console; Stop sharing calls the
 * DELETE and the page goes back to offering Share; the globe and the lock follow
 * `share` on both lists; DEF's 409 sentence reaches the reader; and neither the
 * token nor a title ever goes in as markup.
 *
 * 28 checks. Bite: `DOCVIEWER=…` a pre-A-3 copy of the viewer block turns 12 of
 * the 24 viewer-side checks red (the override in extract.js returns the file
 * before the `needs` assertion, so the extraction itself still succeeds — it is
 * the checks that bite, not the slicer); dropping the `share` field from either
 * BFF handler fails the first check.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const SHELL = extract.pageShell();
const VIEWER = extract.documentViewer();
const MENU = extract.consoleMenu();
const DOCUMENTS = extract.documents();
const ARTIFACTS = extract.artifacts();
const FRAME = extract.artifactFrame();
const JS = require('fs').readFileSync(extract.JS_PATH, 'utf8');
const CSS = require('fs').readFileSync(
	require('path').join(extract.REPO, 'assets', 'css', 'staff-ai.css'), 'utf8');
const PHP = require('fs').readFileSync(
	require('path').join(extract.REPO, 'includes', 'class-def-core-staff-ai.php'), 'utf8');
const TEMPLATE = extract.templateSource();

// The origin PHP decides and localises; the console never holds a host of its own.
const ORIGIN = 'https://a3rev.defho.test';
const TOKEN = 'Kf3xQ9zL2mNpR7sTvW1yZa';   // 22 url-safe chars, DEF's shape
const SHARE = { token: TOKEN, created_at: '2026-09-16T04:00:00Z' };

const HTML = `<!doctype html><html><body>
${extract.templateNav()}
<div id="conversationList"></div>
<div class="messages-container" id="messagesContainer"><div id="messagesList"></div></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
${extract.templatePage('documentsPane')}
${extract.templatePage('artifactsPane')}
${extract.templatePage('documentPage')}
</body></html>`;

const SHELL_TAIL = `
	return { showPage: showPage, consolePages: consolePages };`;
const FRAME_TAIL = `
	return { ARTIFACT_TYPE: ARTIFACT_TYPE, artifactDocument: artifactDocument,
		artifactShareMark: artifactShareMark };`;
const VIEWER_TAIL = `
	return { open: openDocumentViewer };`;

// A title that would be markup if anything ever set it as markup.
const EVIL_TITLE = '<img src=x onerror="window.pwned=1">Style kit';
const DOCS = () => [
	{ document_id: 'art-1', title: EVIL_TITLE, file_type: 'html', version: 2, size_bytes: 9000,
		created_at: '2026-09-15T09:00:00Z', share: null },
	{ document_id: 'art-2', title: 'Shared mock-up', file_type: 'html', version: 1, size_bytes: 14000,
		created_at: '2026-09-14T10:00:00Z', share: SHARE },
	{ document_id: 'doc-1', title: 'Runsheet', file_type: 'md', version: 3, size_bytes: 1200,
		created_at: '2026-09-15T08:00:00Z', share: null }
];

function boot(opts) {
	opts = opts || {};
	const dom = new JSDOM(HTML, { url: 'https://e.test/staff-ai/', pretendToBeVisual: true });
	const window = dom.window, document = window.document;
	const docs = opts.docs || DOCS();
	const calls = [];          // { url, method }
	const copied = [];

	const api = new window.Function('window', 'document', 'location',
		'messagesContainer', 'composerContainer', 'conversationList', 'calls', SHELL + SHELL_TAIL)(
		window, document, window.location,
		document.getElementById('messagesContainer'),
		document.getElementById('composerContainer'),
		document.getElementById('conversationList'), []);

	const t = function (key, def) { return def; };
	const frame = new window.Function('document', 't', FRAME + FRAME_TAIL)(document, t);

	async function apiRequest(url, init) {
		const method = (init && init.method) || 'GET';
		calls.push({ url: url, method: method });
		if (/^\/projects/.test(url)) return { projects: [] };
		if (/^\/documents(\?|$)/.test(url)) return { documents: docs };
		const share = url.match(/documents\/([^/?]+)\/share$/);
		if (share) {
			const doc = docs.find(d => d.document_id === share[1]);
			if (opts.refuse) { const e = new Error(opts.refuse); e.status = 409; throw e; }
			doc.share = method === 'POST' ? SHARE : null;
			return { success: true, share: doc.share };
		}
		const id = (url.match(/documents\/([^/?]+)\/content/) || [])[1] || '';
		const doc = docs.find(d => d.document_id === id);
		if (!doc) throw new Error('no such document ' + id);
		return { document: doc, content: '<h1>page</h1>', offset: 0,
			total_chars: 13, truncated: false, next_offset: null };
	}

	const safeHttpHref = function (url) {
		if (typeof url !== 'string') return '';
		try { const u = new window.URL(url, 'https://e.test'); return (u.protocol === 'http:' || u.protocol === 'https:') ? u.href : ''; }
		catch (e) { return ''; }
	};
	// The clipboard the Copy button reaches for — absent when opts.noClipboard, so
	// the fallback is the path under test.
	if (!opts.noClipboard) {
		window.navigator.clipboard = { writeText: function (text) { copied.push(text); return Promise.resolve(); } };
	}

	const viewer = new window.Function('window', 'document', 'consolePages', 'showPage', 't', 'apiRequest',
		'apiBase', 'safeHttpHref', 'openDocumentViewer', 'isIOS', 'shareFile', 'ARTIFACT_TYPE', 'artifactDocument',
		'shareOrigin', 'navigator',
		VIEWER + VIEWER_TAIL)(
		window, document, api.consolePages, api.showPage, t, apiRequest, '/def/v1', safeHttpHref, null,
		function () { return false; }, function () {}, frame.ARTIFACT_TYPE, frame.artifactDocument,
		opts.origin === undefined ? ORIGIN : opts.origin, window.navigator);

	const IMAGE_EXTENSIONS = new Function('return ' + /const IMAGE_EXTENSIONS = (\[[^\]]*\]);/.exec(JS)[1])();
	const VIEWABLE_TYPES = new Function('IMAGE_EXTENSIONS', 'return ' + /const VIEWABLE_TYPES = (.+);\r?$/m.exec(JS)[1])(IMAGE_EXTENSIONS);

	new window.Function(
		'window', 'document', 'consolePages', 't', 'apiRequest', 'apiBase', 'projectsCache',
		'formatTime', 'safeHttpHref', 'openDocumentViewer', 'VIEWABLE_TYPES',
		'openDocumentsForProject', 'showPage', 'askEntry', 'isPlainClick', 'isIOS', 'shareFile',
		'ARTIFACT_TYPE', 'artifactShareMark',
		MENU + '\n' + DOCUMENTS
	)(
		window, document, api.consolePages, t, apiRequest, '/def/v1', [],
		function (iso) { return iso ? iso.slice(11, 16) : ''; }, safeHttpHref, viewer.open,
		VIEWABLE_TYPES, null, api.showPage,
		extract.buildAskEntry(window, { composerInput: document.getElementById('composerInput') }),
		function (e) { return e.button === 0; }, function () { return false; }, function () {},
		frame.ARTIFACT_TYPE, frame.artifactShareMark
	);

	new window.Function('window', 'document', 'consolePages', 't', 'apiRequest',
		'openDocumentViewer', 'openDocumentsForProject', 'askEntry', 'ARTIFACT_TYPE', 'artifactShareMark',
		ARTIFACTS)(
		window, document, api.consolePages, t, apiRequest, viewer.open, function () {},
		extract.buildAskEntry(window, { composerInput: document.getElementById('composerInput') }),
		frame.ARTIFACT_TYPE, frame.artifactShareMark);

	const $ = (id) => document.getElementById(id);
	return {
		window, document, api, calls, copied, docs,
		open: viewer.open,
		shareBtn: () => $('documentViewerShare'),
		shareRow: () => $('documentViewerShareRow'),
		linkEl: () => $('documentViewerShareLink'),
		copyBtn: () => $('documentViewerShareCopy'),
		stopBtn: () => $('documentViewerShareStop'),
		status: () => $('documentViewerStatus').textContent,
		cards: (grid) => Array.from($(grid).querySelectorAll('.console-card')),
		marks: (grid) => Array.from($(grid).querySelectorAll('.document-share-mark')).map(m => m.textContent),
		click: (el) => el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }))
	};
}

async function settle(w) { for (let i = 0; i < 8; i++) await new Promise(r => w.setTimeout(r, 5)); }

let pass = 0, fail = 0;
const results = [];
function check(label, ok, detail) {
	if (ok) { pass++; results.push('  ok   ' + label); }
	else { fail++; results.push('  FAIL ' + label + (detail ? ' — ' + detail : '')); }
}

(async function () {
	// ── The wire, read off the shipped PHP, template and script ───────────
	{
		check('the BFF forwards `share` on the list and on the document page, from one charset-checked reader',
			/'share'\s*=> self::document_share\( \$doc \)/.test(PHP)
			&& (PHP.match(/'share'\s*=> self::document_share\( \$doc \)/g) || []).length === 2
			&& /\^\[A-Za-z0-9_-\]\{22\}\$/.test(PHP));
		check('both share routes register on the sibling document routes\' permission callback and id pattern',
			/'\/staff-ai\/documents\/\(\?P<id>\[a-zA-Z0-9-\]\+\)\/share'/.test(PHP)
			&& /'callback'\s*=> array\(__CLASS__, 'rest_share_document'\)/.test(PHP)
			&& /'callback'\s*=> array\(__CLASS__, 'rest_unshare_document'\)/.test(PHP));
		check('the console is handed the origin by PHP — no host is written into the JS',
			/shareOrigin: <\?php echo wp_json_encode\( DEF_Core_OAuth::get_defho_url\(\) \); \?>,/.test(TEMPLATE)
			&& /const shareOrigin = StaffAIConfig\.shareOrigin \|\| '';/.test(JS)
			&& !/defho\.ai/.test(JS), 'defho.ai appears in the JS');
		check('the viewer writes no markup: the token and the title reach the DOM as a value or as text only',
			!/\.innerHTML\s*=/.test(VIEWER) && /shareLinkEl\.value = href/.test(VIEWER));
		check('the link is built from the origin and the token, nowhere else',
			/shareOrigin \+ '\/a\/' \+ encodeURIComponent\(token\)/.test(VIEWER));
		check('the mark is drawn as text, never as markup',
			/mark\.textContent = shared \? '🌐' : '🔒'/.test(FRAME) && !/innerHTML/.test(FRAME));

		// The link field is the one thing on the page the reader has to READ, and a
		// token this sheet never declares falls back silently while the colour beside
		// it does not — which is how it ended up ~1:1 under .dark-theme on round 1.
		// So: every var(--x) the rule names must be declared IN this stylesheet, and
		// in BOTH themes.
		const rule = /\.document-share-link\s*\{([\s\S]*?)\}/.exec(CSS);
		const tokens = rule ? Array.from(new Set((rule[1].match(/var\(\s*(--[A-Za-z0-9-]+)/g) || [])
			.map(v => v.replace(/var\(\s*/, '')))) : [];
		const declaredIn = (block, name) => new RegExp('^\\s*' + name + '\\s*:', 'm').test(block);
		const light = /(^|\n):root\s*\{([\s\S]*?)\n\}/.exec(CSS);
		const dark = /(^|\n)\.dark-theme\s*\{([\s\S]*?)\n\}/.exec(CSS);
		const undeclared = tokens.filter(nm => !light || !dark || !declaredIn(light[2], nm) || !declaredIn(dark[2], nm));
		check('the share link field paints from tokens this stylesheet declares in BOTH themes — no silent fallback',
			tokens.length > 0 && undeclared.length === 0,
			'names ' + tokens.join(', ') + ' / undeclared in one or both themes: ' + (undeclared.join(', ') || 'none'));
		check('and it sets its own colour rather than inheriting one the ground may not suit',
			!!rule && /color:\s*var\(--/.test(rule[1]) && !/color:\s*inherit/.test(rule[1]));
	}

	// ── The artifact page: Share, the link, Stop sharing ──────────────────
	{
		const t = boot();
		t.open('art-1', 'Style kit');
		await settle(t.window);
		check('an unshared artifact opens offering Share, with no link on the page',
			t.shareBtn().style.display === '' && t.shareRow().style.display === 'none'
			&& t.linkEl().value === '', t.linkEl().value);

		t.click(t.shareBtn());
		await settle(t.window);
		const posted = t.calls.filter(c => /art-1\/share$/.test(c.url));
		check('Share POSTs to this document\'s share route, once',
			posted.length === 1 && posted[0].method === 'POST', JSON.stringify(posted));
		check('the link the page shows is the origin PHP handed it, with DEF\'s token',
			t.linkEl().value === ORIGIN + '/a/' + TOKEN, t.linkEl().value);
		check('the page swaps to the link state: the field and its buttons show, Share does not',
			t.shareRow().style.display === '' && t.shareBtn().style.display === 'none'
			&& t.linkEl().readOnly === true);

		t.click(t.copyBtn());
		await settle(t.window);
		check('Copy puts the link on the clipboard', t.copied.length === 1 && t.copied[0] === ORIGIN + '/a/' + TOKEN,
			t.copied.join(','));
		// A clipboard write leaves nothing on screen; on a phone there is no paste
		// target in sight, so silence reads as a dead button.
		check('and says so — the button becomes "Copied!" and goes back on its own',
			t.copyBtn().textContent === 'Copied!', t.copyBtn().textContent);
		await new Promise(r => t.window.setTimeout(r, 2100));
		check('the label comes back to the template\'s own, not to whatever it said when clicked',
			t.copyBtn().textContent === 'Copy', t.copyBtn().textContent);

		t.click(t.stopBtn());
		await settle(t.window);
		const deleted = t.calls.filter(c => /art-1\/share$/.test(c.url) && c.method === 'DELETE');
		check('Stop sharing DELETEs the share', deleted.length === 1);
		check('and the page goes back to offering Share, with the link gone',
			t.shareBtn().style.display === '' && t.shareRow().style.display === 'none'
			&& t.linkEl().value === '');
	}

	// ── Opening one that is already shared, and one that is not an artifact ─
	{
		const t = boot();
		t.open('art-2', 'Shared mock-up');
		await settle(t.window);
		check('an artifact that is already shared opens on the link, with no Share to press',
			t.linkEl().value === ORIGIN + '/a/' + TOKEN && t.shareRow().style.display === ''
			&& t.shareBtn().style.display === 'none', t.linkEl().value);

		t.open('doc-1', 'Runsheet');
		await settle(t.window);
		check('an md document after it: no Share, no link — the last artifact\'s link does not follow it',
			t.shareBtn().style.display === 'none' && t.shareRow().style.display === 'none'
			&& t.linkEl().value === '');
	}

	// ── A refused Share puts the error where the reader is looking ────────
	// Read this for what it is: the harness throws PAST the real apiRequest, so the
	// message arrives raw. In the browser apiRequest wraps it — a 409 whose body
	// carries no `detail` renders as "[staff_ai_http_409] The assistant service
	// declined this request (HTTP 409): <DEF's sentence>". What is pinned here is
	// that the thrown message reaches the status line unedited and that no link
	// appears; the console's standard refusal frame around it is apiRequest's, and
	// this is a backstop either way — Share is only offered on an artifact.
	{
		const REFUSAL = 'Only an artifact can be shared by link. Other documents are files — send one as an attachment instead.';
		const t = boot({ refuse: REFUSAL });
		t.open('art-1', 'Style kit');
		await settle(t.window);
		t.click(t.shareBtn());
		await settle(t.window);
		check('a refused Share puts the refusal on the status line unedited, and no link appears',
			t.status() === REFUSAL && t.linkEl().value === '' && t.shareRow().style.display === 'none', t.status());
		check('Share is live again after the refusal — the button is not left disabled',
			t.shareBtn().disabled === false && t.stopBtn().disabled === false);
	}

	// ── A site with no DEFHO origin ───────────────────────────────────────
	{
		const t = boot({ origin: '' });
		t.open('art-1', 'Style kit');
		await settle(t.window);
		check('with no origin to build a link on, the artifact is offered no Share at all',
			t.shareBtn().style.display === 'none' && t.shareRow().style.display === 'none');
	}

	// ── The clipboard fallback ────────────────────────────────────────────
	{
		const t = boot({ noClipboard: true });
		t.open('art-2', 'Shared mock-up');
		await settle(t.window);
		let selected = false;
		t.linkEl().select = function () { selected = true; };
		t.click(t.copyBtn());
		await settle(t.window);
		check('with no clipboard the link is selected instead, so it can be copied by hand',
			selected === true && t.copied.length === 0);
	}

	// ── The marks on the two lists (D-A4) ─────────────────────────────────
	{
		const t = boot();
		t.api.showPage('artifacts');
		await settle(t.window);
		check('the Artifacts list marks the shared artifact with a globe and the other with a lock',
			t.marks('artifactsGrid').join('') === '🔒🌐', t.marks('artifactsGrid').join(''));

		t.api.showPage('documents');
		await settle(t.window);
		check('Documents marks its artifacts the same way, and marks nothing else — an md row carries no lock',
			t.marks('documentsGrid').join('') === '🔒🌐' && t.cards('documentsGrid').length === 3,
			t.marks('documentsGrid').join('') + ' over ' + t.cards('documentsGrid').length + ' card(s)');

		const art = t.cards('documentsGrid').find(c => c.querySelector('.document-name').textContent === EVIL_TITLE);
		check('a title that looks like markup stays text on the marked card, and nothing in it ran',
			!!art && art.querySelector('.document-name').textContent === EVIL_TITLE
			&& art.querySelector('.document-name').children.length === 0
			&& t.window.pwned === undefined);
		const mark = art.querySelector('.document-share-mark');
		// role="img" is what makes the label count: ARIA prohibits aria-label on a
		// bare <span>, and a screen reader discards it there.
		check('the mark says in words what it means, for a reader who cannot see the glyph',
			mark.getAttribute('aria-label') === 'Not shared' && mark.title === 'Not shared'
			&& mark.getAttribute('role') === 'img',
			mark.getAttribute('role') + ' / ' + mark.getAttribute('aria-label'));
		check('the token is nowhere in the list\'s DOM — the mark says shared, not which link',
			t.document.getElementById('documentsGrid').innerHTML.indexOf(TOKEN) === -1);
	}

	console.log('harness-artifact-share (A-3: Share / Stop sharing, the link, the globe and the lock)');
	results.forEach(r => console.log(r));
	console.log(pass + ' passed, ' + fail + ' failed');
	process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
