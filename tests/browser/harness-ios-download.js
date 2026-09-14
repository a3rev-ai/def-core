/*
 * Download on an iPhone or iPad — behavioural harness (v7.9.15, widened in v7.9.19).
 *
 * The installed app has no download manager: a link to an attachment navigates the
 * app's own page to the file, and iOS shows its document-preview sheet with no way
 * into Photos and no way back (Steve's iPhone, 2026-09-14). Safari has a download
 * manager, and it puts an image in a Files folder the reader may never find ("I do
 * not know how to access downloads on an iPhone", the same canary). On either, the
 * share sheet — Save Image to Photos, Save to Files — is the answer. The chat's
 * download card has made that hand-off in the installed app since 7.6.8; the
 * Documents menu and the viewer's Download joined it in 7.9.15, and 7.9.19 widened
 * the gate from "installed" to "an iPhone or iPad".
 *
 * Runs the SHIPPED initDocuments block, the SHIPPED viewer block and the SHIPPED
 * share helper (all sliced by marker, tests/browser/extract.js) inside jsdom, with
 * the seam — an iPhone/iPad or not, share sheet or not — under each check's control.
 *
 *  1. The Documents ⋯ menu and its touch sheet: on an iPhone/iPad with a share
 *     sheet, Download is a button that hands the file over; otherwise the link it was.
 *  2. The viewer's Download: the same two shapes on one anchor.
 *  3. The helper: the file carries the proxy's name when the caller has none — decoded
 *     from the header's bytes, so an em dash in a version stamp survives — and the
 *     caller's when it has one; and what counts as an iPhone or iPad: an iPhone in
 *     Safari or installed, an iPad that calls itself a Mac but has touch; not a Mac,
 *     not Android.
 *
 * 14 checks.
 */
const { JSDOM, VirtualConsole } = require('jsdom');
// A link the check clicks would navigate; jsdom reports that it cannot. Expected.
const quiet = new VirtualConsole();
quiet.on('jsdomError', function () {});
const extract = require('./extract');
const SHELL = extract.pageShell();
const MENU = extract.consoleMenu();
const DOCUMENTS = extract.documents();
const VIEWER = extract.documentViewer();
const SHARE = extract.installedShare();

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + label); }
	else { fail++; results.push('  FAIL ' + label + (detail ? ' -- ' + detail : '')); }
}

const DOWNLOAD_URL = 'https://e.test/staff-ai-download/tenant-1/garden.png?staff_ai_save=1';
const tick = (w, n) => new Promise(r => w.setTimeout(r, n === undefined ? 40 : n));
function click(w, el) {
	return el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
}

// The seam, set per boot: whether the page believes it is on an iPhone/iPad, and
// whether the browser offers a share sheet at all.
function seam(window, opts) {
	if (opts.share) {
		Object.defineProperty(window.navigator, 'share', { value: function () { return Promise.resolve(); }, configurable: true });
	}
	const shared = [];
	return {
		shared,
		isIOS: function () { return !!opts.ios; },
		shareFile: function (url, name) { shared.push({ url: url, name: name }); }
	};
}

// ── 1. the Documents page (harness-c6a's boot, the seam under our control) ──

const DOC_HTML = `<!doctype html><html><body>
${extract.templateNav()}
<div id="conversationList"></div>
<div class="messages-container" id="messagesContainer"><div id="messageList"></div></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
${extract.templatePage('documentsPane')}
</body></html>`;

function bootDocuments(opts) {
	const dom = new JSDOM(DOC_HTML, { url: 'https://e.test/staff-ai/', pretendToBeVisual: true, virtualConsole: quiet });
	const window = dom.window, document = window.document;
	const api = new window.Function(
		'window', 'document', 'location', 'messagesContainer', 'composerContainer', 'conversationList',
		SHELL + '\nreturn { showPage: showPage, consolePages: consolePages };'
	)(window, document, window.location, document.getElementById('messagesContainer'),
		document.getElementById('composerContainer'), document.getElementById('conversationList'));
	const s = seam(window, opts);
	async function apiRequest(url) {
		if (/^\/projects/.test(url)) return { projects: [] };
		return { documents: [{ document_id: 'd1', title: 'Garden photo', file_type: 'png', size_bytes: 2600000,
			created_at: '2026-09-14T08:48:00Z', download_url: DOWNLOAD_URL }] };
	}
	// The blocks read the bare global `navigator`: inject the page's, as every other global is.
	new window.Function(
		'window', 'document', 'consolePages', 't', 'apiRequest', 'apiBase', 'projectsCache',
		'formatTime', 'safeHttpHref', 'openDocumentViewer', 'VIEWABLE_TYPES',
		'openDocumentsForProject', 'showPage', 'askEntry', 'isPlainClick',
		'isIOS', 'shareFile', 'navigator',
		MENU + '\n' + DOCUMENTS
	)(
		window, document, api.consolePages, function (key, def) { return def; }, apiRequest, '/def/v1', [],
		function (x) { return String(x || ''); },
		function (u) { return /^https?:\/\//i.test(u || '') ? u : ''; },
		function () {}, ['md', 'txt'], null, api.showPage,
		extract.buildAskEntry(window, { composerInput: document.getElementById('composerInput') }),
		function (e) { return e.button === 0; },
		s.isIOS, s.shareFile, window.navigator
	);
	return { window, document, api, shared: s.shared };
}

async function openMenu(t) {
	t.api.showPage('documents'); await tick(t.window, 80);
	const card = t.document.querySelector('#documentsGrid .console-card');
	click(t.window, card.querySelector('.console-menu-btn')); await tick(t.window);
	const menu = t.document.querySelector('.console-menu-drop');
	const sheet = card.querySelector('.console-menu-sheet-list');
	const find = root => root ? Array.from(root.querySelectorAll('.console-menu-item')).find(el => el.textContent === 'Download') || null : null;
	return { menu: find(menu), sheet: find(sheet) };
}
const shape = el => !el ? 'missing' : el.tagName.toLowerCase() + (el.getAttribute('href') ? '[href=' + el.getAttribute('href') + ']' : '');

// ── 2. the viewer (harness-c4's boot, the seam under our control) ──────────

const VIEWER_HTML = `<!doctype html><html><body>
${extract.templateNav()}
<div id="conversationList"></div>
<div class="messages-container" id="messagesContainer"><div id="messagesList"></div></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
<section class="console-page" id="documentsPane" hidden>
  <h1 class="console-page-title" id="documentsTitle" tabindex="-1">My documents</h1>
</section>
${extract.templatePage('documentPage')}
</body></html>`;

function bootViewer(opts) {
	const dom = new JSDOM(VIEWER_HTML, { url: 'https://e.test/staff-ai/', pretendToBeVisual: true, virtualConsole: quiet });
	const window = dom.window, document = window.document;
	const api = new window.Function('window', 'document', 'location',
		'messagesContainer', 'composerContainer', 'conversationList',
		SHELL + '\n\tconsolePages.push({ route: \'documents\', el: document.getElementById(\'documentsPane\'),'
		+ ' title: document.getElementById(\'documentsTitle\') });'
		+ '\n\treturn { showPage: showPage, consolePages: consolePages };'
	)(window, document, window.location, document.getElementById('messagesContainer'),
		document.getElementById('composerContainer'), document.getElementById('conversationList'));
	const s = seam(window, opts);
	async function apiRequest() {
		return { document: { document_id: 'doc-1', title: 'Garden photo', file_type: 'md', version: 1, download_url: DOWNLOAD_URL },
			content: 'Body', offset: 0, total_chars: 4, truncated: false, next_offset: null };
	}
	const viewer = new window.Function('window', 'document', 'consolePages', 'showPage', 't', 'apiRequest',
		'apiBase', 'safeHttpHref', 'openDocumentViewer', 'isIOS', 'shareFile', 'navigator',
		VIEWER + '\n\treturn { open: openDocumentViewer };'
	)(window, document, api.consolePages, api.showPage, function (key, def) { return def; }, apiRequest,
		'/def/v1', function (u) { return /^https?:\/\//i.test(u || '') ? u : ''; }, null,
		s.isIOS, s.shareFile, window.navigator);
	return { window, document, open: viewer.open, shared: s.shared, link: () => document.getElementById('documentViewerDownload') };
}

// ── 3. the helper itself ───────────────────────────────────────────────────

const UA = {
	iphoneSafari: 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1',
	ipadAsMac: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15',
	mac: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15',
	android: 'Mozilla/5.0 (Linux; Android 16; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36'
};

function bootShare(opts) {
	// Headers.get() hands back one code unit per BYTE (a ByteString): the proxy writes
	// the title's UTF-8 bytes into the header, so the stub returns them the same way.
	const wireName = Buffer.from(opts.filename || 'Garden photo.png', 'utf8').toString('latin1');
	const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://e.test/staff-ai/' });
	const window = dom.window;
	// jsdom's own navigator: the user agent and touch count are set on the instance
	// (the constructor's `userAgent` option is not honoured by jsdom 29).
	Object.defineProperty(window.navigator, 'userAgent', { value: opts.userAgent || UA.iphoneSafari, configurable: true });
	Object.defineProperty(window.navigator, 'maxTouchPoints', { value: opts.touch || 0, configurable: true });
	if (opts.standalone !== undefined) {
		Object.defineProperty(window.navigator, 'standalone', { value: opts.standalone, configurable: true });
	}
	const shared = [];
	Object.defineProperty(window.navigator, 'share', {
		value: function (data) { shared.push(data); return Promise.resolve(); }, configurable: true
	});
	Object.defineProperty(window.navigator, 'canShare', { value: function () { return true; }, configurable: true });
	async function fetch() {
		return {
			ok: true,
			headers: { get: function (k) { return k === 'content-disposition' ? 'attachment; filename="' + wireName + '"' : null; } },
			blob: async function () { return new window.Blob(['x'], { type: 'image/png' }); }
		};
	}
	const helper = new window.Function('window', 'navigator', 'fetch', 'File',
		SHARE + '\n\treturn { isIOS: isIOS, shareFile: shareFile };'
	)(window, window.navigator, fetch, window.File);
	return { helper, shared };
}

(async () => {
	// ---- 1. the Documents ⋯ menu and its touch sheet -------------------------
	{
		const t = bootDocuments({ ios: true, share: true });
		const d = await openMenu(t);
		click(t.window, d.menu); await tick(t.window);
		check('on an iPhone/iPad with a share sheet: the menu\'s Download is a button, and picking it hands the file to the share helper by the proxy\'s address, unnamed',
			shape(d.menu) === 'button' && t.shared.length === 1 && t.shared[0].url === DOWNLOAD_URL && t.shared[0].name === undefined,
			shape(d.menu) + ' shared=' + JSON.stringify(t.shared));
		check('the touch sheet carries the same button',
			shape(d.sheet) === 'button', shape(d.sheet));
	}
	{
		const t = bootDocuments({ ios: false, share: true });
		const d = await openMenu(t);
		click(t.window, d.menu); await tick(t.window);
		check('not an iPhone/iPad: Download is the link it was, to the proxy\'s save address, and nothing is handed over',
			shape(d.menu) === 'a[href=' + DOWNLOAD_URL + ']' && t.shared.length === 0,
			shape(d.menu) + ' shared=' + t.shared.length);
	}
	{
		const t = bootDocuments({ ios: true, share: false });
		const d = await openMenu(t);
		check('an iPhone/iPad but no share sheet on offer: Download stays the link',
			shape(d.menu) === 'a[href=' + DOWNLOAD_URL + ']' && shape(d.sheet) === 'a[href=' + DOWNLOAD_URL + ']',
			shape(d.menu) + ' / ' + shape(d.sheet));
	}

	// ---- 2. the viewer's Download ---------------------------------------------
	{
		const t = bootViewer({ ios: true, share: true });
		t.open('doc-1', 'Garden photo'); await tick(t.window, 80);
		const link = t.link();
		const proceeded = click(t.window, link); await tick(t.window);
		check('viewer, an iPhone/iPad with a share sheet: Download\'s click is taken over and the file goes to the helper by the link\'s own address',
			link.style.display === '' && proceeded === false && t.shared.length === 1 && t.shared[0].url === link.href && t.shared[0].name === undefined,
			'display=' + link.style.display + ' proceeded=' + proceeded + ' shared=' + JSON.stringify(t.shared));
	}
	{
		const t = bootViewer({ ios: false, share: true });
		t.open('doc-1', 'Garden photo'); await tick(t.window, 80);
		const link = t.link();
		const proceeded = click(t.window, link); await tick(t.window);
		check('viewer, not an iPhone/iPad: the click is the browser\'s and nothing is handed over',
			proceeded === true && t.shared.length === 0, 'proceeded=' + proceeded + ' shared=' + t.shared.length);
	}
	{
		const t = bootViewer({ ios: true, share: false });
		t.open('doc-1', 'Garden photo'); await tick(t.window, 80);
		const proceeded = click(t.window, t.link()); await tick(t.window);
		check('viewer, an iPhone/iPad but no share sheet: the click is the browser\'s',
			proceeded === true && t.shared.length === 0, 'proceeded=' + proceeded);
	}

	// ---- 3. the helper --------------------------------------------------------
	{
		const t = bootShare({});
		await t.helper.shareFile(DOWNLOAD_URL);
		const f = t.shared[0] && t.shared[0].files && t.shared[0].files[0];
		check('a caller with no name of its own: the file takes the proxy\'s name from Content-Disposition',
			!!f && f.name === 'Garden photo.png' && f.type === 'image/png', f && (f.name + ' ' + f.type));
	}
	{
		// DEF stamps every re-edited document "(v2 — date)": the name is non-ASCII by
		// construction, and the wire carries its UTF-8 bytes.
		const title = 'Q3 Report (v2 — 2026-09-14).md';
		const t = bootShare({ filename: title });
		await t.helper.shareFile(DOWNLOAD_URL);
		const f = t.shared[0] && t.shared[0].files && t.shared[0].files[0];
		check('a non-ASCII name comes back as the title, not as the header\'s bytes read as characters',
			!!f && f.name === title, f && f.name);
	}
	{
		const t = bootShare({});
		await t.helper.shareFile(DOWNLOAD_URL, 'card-name.png');
		const f = t.shared[0] && t.shared[0].files && t.shared[0].files[0];
		check('a caller with a name keeps it (the chat card\'s file_name)', !!f && f.name === 'card-name.png', f && f.name);
	}
	{
		// What counts as an iPhone or iPad — Safari and the installed app alike.
		check('an iPhone in Safari counts (7.9.19 — until then only the installed app did)',
			bootShare({ userAgent: UA.iphoneSafari, standalone: false }).helper.isIOS() === true);
		check('an iPhone with the console installed still counts',
			bootShare({ userAgent: UA.iphoneSafari, standalone: true }).helper.isIOS() === true);
		check('an iPad that calls itself a Mac counts by its touch screen; a Mac does not',
			bootShare({ userAgent: UA.ipadAsMac, touch: 5 }).helper.isIOS() === true &&
			bootShare({ userAgent: UA.mac, touch: 0 }).helper.isIOS() === false);
		check('Android does not count — Chrome\'s download manager is what its users expect',
			bootShare({ userAgent: UA.android, touch: 5 }).helper.isIOS() === false);
	}

	console.log('harness-ios-download: ' + pass + ' passed, ' + fail + ' failed');
	results.forEach(r => console.log(r));
	process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
