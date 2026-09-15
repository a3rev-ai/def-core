/*
 * Artifacts A-2 (8.2.x, DEF docs/staff-ai-artifacts-runsheet.md) — behavioural
 * harness. Runs the SHIPPED page shell, the SHIPPED document viewer, the SHIPPED
 * Documents block and the SHIPPED Artifacts block (each extracted by marker)
 * inside jsdom, against the sidebar nav and the three page sections sliced out
 * of templates/staff-ai-shell.php. Nothing here is a copy of the code or the
 * markup under test.
 *
 * What is pinned is the WIRE the runsheet names (D-A3, D-A4): the frame carries
 * exactly sandbox="allow-scripts"; the CSP meta is the D-A3 string verbatim; the
 * frame's srcdoc is built from the content route's JSON, read to the end, and
 * never from a download address; no path puts an artifact's text into the
 * console's own DOM; the Artifacts page lists html documents only; the Documents
 * card says ARTIFACT and Open for html, View for the rest.
 *
 * 26 checks.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const SHELL = extract.pageShell();
const VIEWER = extract.documentViewer();
const MENU = extract.consoleMenu();
const DOCUMENTS = extract.documents();
const ARTIFACTS = extract.artifacts();
const FRAME = extract.artifactFrame();
const TEMPLATE = extract.templateSource();
const JS = require('fs').readFileSync(extract.JS_PATH, 'utf8');

// D-A3, verbatim. A change to the shipped string must be matched here on purpose.
const CSP = "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com data:; img-src data: blob:; media-src data:";
const META = '<meta http-equiv="Content-Security-Policy" content="' + CSP + '">';

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
	return { showPage: showPage, showChat: showChat, consolePages: consolePages,
		open: function () { return openPage ? openPage.route : null; } };`;
const FRAME_TAIL = `
	return { ARTIFACT_TYPE: ARTIFACT_TYPE, ARTIFACT_CSP: ARTIFACT_CSP, artifactDocument: artifactDocument };`;
const VIEWER_TAIL = `
	return { open: openDocumentViewer };`;

const DOWNLOAD = (id, ext) => 'https://e.test/staff-ai-download/tenant-1/' + id + '.' + ext + '?staff_ai_save=1';
// The artifact's two halves: the split lands INSIDE a <style> block, which is why a
// half page must never reach the frame (an unclosed style swallows the body).
const PAGE_HEAD = '<!doctype html>\n<html lang="en"><head><meta charset="utf-8"><title>Kit</title><style>body{margin:0;';
const PAGE_TAIL = 'color:#123}</style></head><body><h1>Smith Lane</h1><script>document.title="x"</script></body></html>';
const DOCS = [
	{ document_id: 'art-1', title: 'Smith Lane style kit', file_type: 'html', version: 2, size_bytes: 9000,
		created_at: '2026-09-15T09:00:00Z', project_id: 'p1', project_name: 'Smith Lane', download_url: DOWNLOAD('art-1', 'html') },
	{ document_id: 'doc-1', title: 'Runsheet', file_type: 'md', version: 3, size_bytes: 1200,
		created_at: '2026-09-15T08:00:00Z', download_url: DOWNLOAD('doc-1', 'md') },
	{ document_id: 'art-2', title: 'User Access mock-up', file_type: 'html', version: 1, size_bytes: 14000,
		created_at: '2026-09-14T10:00:00Z', download_url: DOWNLOAD('art-2', 'html') }
];

function boot(opts) {
	opts = opts || {};
	const dom = new JSDOM(HTML, { url: 'https://e.test/staff-ai/', pretendToBeVisual: true });
	const window = dom.window, document = window.document, location = window.location;
	const requests = [];

	const api = new window.Function('window', 'document', 'location',
		'messagesContainer', 'composerContainer', 'conversationList', 'calls', SHELL + SHELL_TAIL)(
		window, document, location,
		document.getElementById('messagesContainer'),
		document.getElementById('composerContainer'),
		document.getElementById('conversationList'), []);

	const frame = new window.Function(FRAME + FRAME_TAIL)();

	async function apiRequest(url, init) {
		requests.push(url);
		if (/^\/projects/.test(url)) return { projects: [] };
		if (/^\/documents(\?|$)/.test(url)) return { documents: opts.docs || DOCS };
		const id = (url.match(/documents\/([^/?]+)\/content/) || [])[1] || '';
		const doc = (opts.docs || DOCS).find(d => d.document_id === id);
		if (!doc) throw new Error('no such document ' + id);
		const second = /offset=/.test(url);
		if (doc.file_type === 'html') {
			return { document: doc, content: second ? PAGE_TAIL : PAGE_HEAD,
				offset: second ? PAGE_HEAD.length : 0, total_chars: PAGE_HEAD.length + PAGE_TAIL.length,
				truncated: !second, next_offset: second ? null : PAGE_HEAD.length };
		}
		return { document: doc, content: 'Body of ' + id + ' <b>not markup</b>', offset: 0,
			total_chars: 30, truncated: false, next_offset: null };
	}
	const t = function (key, def) { return def; };
	const safeHttpHref = function (url) {
		if (typeof url !== 'string') return '';
		try { const u = new window.URL(url, 'https://e.test'); return (u.protocol === 'http:' || u.protocol === 'https:') ? u.href : ''; }
		catch (e) { return ''; }
	};
	const formatTime = function (iso) { return iso ? iso.slice(11, 16) : ''; };  // Documents' card only

	const viewer = new window.Function('window', 'document', 'consolePages', 'showPage', 't', 'apiRequest',
		'apiBase', 'safeHttpHref', 'openDocumentViewer', 'isIOS', 'shareFile', 'ARTIFACT_TYPE', 'artifactDocument',
		VIEWER + VIEWER_TAIL)(
		window, document, api.consolePages, api.showPage, t, apiRequest, '/def/v1', safeHttpHref, null,
		function () { return false; }, function () {}, frame.ARTIFACT_TYPE, frame.artifactDocument);

	const opened = [];
	const openSpy = function (id, title) { opened.push({ id: id, title: title }); viewer.open(id, title); };
	const IMAGE_EXTENSIONS = new Function('return ' + /const IMAGE_EXTENSIONS = (\[[^\]]*\]);/.exec(JS)[1])();
	const VIEWABLE_TYPES = new Function('IMAGE_EXTENSIONS', 'return ' + /const VIEWABLE_TYPES = (.+);\r?$/m.exec(JS)[1])(IMAGE_EXTENSIONS);

	new window.Function(
		'window', 'document', 'consolePages', 't', 'apiRequest', 'apiBase', 'projectsCache',
		'formatTime', 'safeHttpHref', 'openDocumentViewer', 'VIEWABLE_TYPES',
		'openDocumentsForProject', 'showPage', 'askEntry', 'isPlainClick', 'isIOS', 'shareFile', 'ARTIFACT_TYPE',
		MENU + '\n' + DOCUMENTS
	)(
		window, document, api.consolePages, t, apiRequest, '/def/v1', [], formatTime, safeHttpHref, openSpy,
		VIEWABLE_TYPES, null, api.showPage,
		extract.buildAskEntry(window, { composerInput: document.getElementById('composerInput') }),
		function (e) { return e.button === 0; }, function () { return false; }, function () {}, frame.ARTIFACT_TYPE
	);

	const filtered = [];
	new window.Function('window', 'document', 'consolePages', 't', 'apiRequest',
		'openDocumentViewer', 'openDocumentsForProject', 'askEntry', 'ARTIFACT_TYPE', ARTIFACTS)(
		window, document, api.consolePages, t, apiRequest, openSpy,
		function (projectId) { filtered.push(projectId); },
		extract.buildAskEntry(window, { composerInput: document.getElementById('composerInput') }),
		frame.ARTIFACT_TYPE);

	const $ = (id) => document.getElementById(id);
	return {
		window, document, api, frame, requests, opened, filtered,
		open: viewer.open,
		iframe: () => $('documentViewerFrame'),
		srcdoc: () => $('documentViewerFrame').getAttribute('srcdoc'),
		text: () => $('documentViewerText'),
		status: () => $('documentViewerStatus').textContent,
		more: () => $('documentViewerMore'),
		cards: (grid) => Array.from($(grid).querySelectorAll('.console-card')),
		headings: (grid) => Array.from($(grid).querySelectorAll('h2')).map(h => h.textContent),
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
	// ── The wire, read off the shipped markup and script ──────────────────
	{
		const tag = /<iframe[^>]*id="documentViewerFrame"[^>]*>/.exec(TEMPLATE);
		check('the frame carries exactly sandbox="allow-scripts" (D-A3)',
			!!tag && /\ssandbox="allow-scripts"/.test(tag[0]), tag ? tag[0] : 'no iframe');
		check('no sandbox attribute in the template grants an origin, and the script never sets one (the markup is the only source)',
			!/sandbox="[^"]*allow-same-origin/.test(TEMPLATE) && !/setAttribute\(\s*['"]sandbox|\.sandbox\b/.test(JS));
		const ids = Array.from(extract.templateNav().matchAll(/id="(nav\w+)"/g)).map(m => m[1]);
		check('the Artifacts entry sits between Documents and Scheduled (D-A4)',
			ids.indexOf('navArtifacts') === ids.indexOf('navDocuments') + 1
			&& ids.indexOf('navScheduled') === ids.indexOf('navArtifacts') + 1, ids.join(','));
		check('the viewer never writes markup into the console DOM: no innerHTML assignment in the block',
			!/\.innerHTML\s*=/.test(VIEWER));
		const srcdocs = VIEWER.match(/\.srcdoc\s*=/g) || [];
		check('srcdoc is assigned once, from the frame builder over the text the content route returned',
			srcdocs.length === 1 && /frameEl\.srcdoc = artifactDocument\(current\.html\)/.test(VIEWER), srcdocs.length + ' assignment(s)');
	}

	// ── The frame's document ──────────────────────────────────────────────
	{
		const t = boot();
		check('the shipped CSP is the D-A3 string, verbatim', t.frame.ARTIFACT_CSP === CSP, t.frame.ARTIFACT_CSP);
		const built = t.frame.artifactDocument('<!doctype html>\n<html><head><meta charset="utf-8"><title>K</title></head><body>b</body></html>');
		const at = built.indexOf(META);
		check('the policy goes right after the doctype — before the page\'s own head, charset, title, styles and scripts',
			at === '<!doctype html>'.length && built.startsWith('<!doctype html>') && at < built.indexOf('<html>'), built.slice(0, 120));
		check('links are inert: <base target="_blank"> follows the meta, and the sandbox refuses the window it asks for',
			built.indexOf('<base target="_blank">') === at + META.length);
		const bare = t.frame.artifactDocument('<h1>fragment</h1>');
		check('a page with no doctype gets the policy in front', bare.indexOf(META) === 0 && bare.endsWith('<h1>fragment</h1>'));
		// Panel round 2: a comment carrying "<head>" ahead of the real head moved a
		// head-anchored insert into the comment, and the page ran with no policy.
		const tricked = t.frame.artifactDocument('<!--<head>--><!doctype html><html><head><script>x()</script></head></html>');
		check('nothing the page contains can move the policy: a comment holding "<head>" leaves the meta first, ahead of every script',
			tricked.indexOf(META) === 0 && tricked.indexOf(META) < tricked.indexOf('<script>'), tricked.slice(0, 100));
		const noHead = t.frame.artifactDocument('<!DOCTYPE html><html lang="en"><body>b</body></html>');
		check('a page with a doctype and no head keeps its doctype first (standards mode) and the policy next',
			noHead.startsWith('<!DOCTYPE html>' + META), noHead.slice(0, 80));
		check('the console page refuses the frame\'s own navigation at the request: its CSP carries frame-src \'self\'',
			/Content-Security-Policy: frame-ancestors 'self'; frame-src 'self';/.test(
				require('fs').readFileSync(require('path').join(extract.REPO, 'includes', 'class-def-core-staff-ai.php'), 'utf8')));
	}

	// ── Opening an artifact ───────────────────────────────────────────────
	{
		const t = boot();
		t.api.showPage('documents');
		t.open('art-1', 'Smith Lane style kit');
		await settle(t.window);
		const src = t.srcdoc() || '';
		check('the artifact is read to the END before the frame sees it: both halves joined, then framed, one page',
			src === t.frame.artifactDocument(PAGE_HEAD + PAGE_TAIL)
			&& t.requests.filter(u => /art-1\/content/.test(u)).length === 2, src.slice(0, 80));
		check('the frame\'s document carries the CSP meta', src.indexOf(META) !== -1);
		check('srcdoc came from the content route, never from a download address',
			src.indexOf('staff-ai-download') === -1 && !t.requests.some(u => /staff-ai-download/.test(u)));
		check('the text well stays empty and hidden; the frame shows; Show more is not offered',
			t.text().textContent === '' && t.text().style.display === 'none'
			&& t.iframe().style.display === '' && t.more().style.display === 'none');
		check('the status names it an artifact, not an HTML file', /^ARTIFACT/.test(t.status()), t.status());

		// The watchdog: a load the viewer did not ask for is the page inside navigating.
		t.iframe().dispatchEvent(new t.window.Event('load'));
		t.iframe().dispatchEvent(new t.window.Event('load'));
		check('a second load of the frame closes the artifact and says so',
			t.srcdoc() === null && t.iframe().style.display === 'none' && /closed/.test(t.status()), t.status());

		t.open('doc-1', 'Runsheet');
		await settle(t.window);
		check('an md document after it: the frame is put away with its srcdoc, the text well shows the text',
			t.iframe().style.display === 'none' && t.srcdoc() === null
			&& t.text().style.display === '' && /Body of doc-1/.test(t.text().textContent));
	}

	// ── The Artifacts page and the Documents card ─────────────────────────
	{
		const t = boot();
		t.api.showPage('artifacts');
		await settle(t.window);
		const cards = t.cards('artifactsGrid');
		check('the Artifacts page lists html documents only, by day, opening the artifact',
			cards.length === 2 && t.headings('artifactsGrid').length === 2
			&& cards[0].querySelector('.document-name').textContent === 'Smith Lane style kit'
			&& cards[0].querySelector('.document-project-chip').textContent === 'Smith Lane'
			&& cards[0].querySelector('.document-view-btn').textContent === 'Open',
			cards.length + ' card(s), ' + t.headings('artifactsGrid').length + ' heading(s)');
		check('the card carries the time of day (the day is the heading)',
			/\d{1,2}:\d{2}/.test(cards[0].querySelector('.document-meta').textContent), cards[0].querySelector('.document-meta').textContent);
		t.click(cards[0].querySelector('.document-view-btn'));
		check('Open opens the artifact', t.opened.length === 1 && t.opened[0].id === 'art-1');
		const chip = cards[0].querySelector('.document-project-chip');
		t.click(chip);
		check('the project chip is a button, and it opens Documents filtered to the project',
			chip.tagName === 'BUTTON' && t.filtered.length === 1 && t.filtered[0] === 'p1');
		check('with artifacts listed, the empty state stays put away',
			t.document.getElementById('artifactsEmptyState').style.display === 'none');

		t.api.showPage('documents');
		await settle(t.window);
		const docs = t.cards('documentsGrid');
		const byName = (n) => docs.find(c => c.querySelector('.document-name').textContent === n);
		const art = byName('Smith Lane style kit'), md = byName('Runsheet');
		check('on Documents the artifact says ARTIFACT where the type is named and its action is Open; the rest still View',
			!!art && /^ARTIFACT/.test(art.querySelector('.document-meta').textContent)
			&& art.querySelector('.document-view-btn').textContent === 'Open'
			&& !!md && /^MD/.test(md.querySelector('.document-meta').textContent)
			&& md.querySelector('.document-view-btn').textContent === 'View');
	}
	{
		const t = boot({ docs: DOCS.filter(d => d.file_type !== 'html') });
		t.api.showPage('artifacts');
		await settle(t.window);
		check('no artifacts: the page says so, shows no card, and offers the Ask button — the entry point',
			t.cards('artifactsGrid').length === 0 && /No artifacts yet/.test(t.document.getElementById('artifactsStatus').textContent)
			&& t.document.getElementById('artifactsEmptyState').style.display === ''
			&& /make an artifact/.test(t.document.getElementById('artifactsAskAssistant').textContent));
	}

	console.log('harness-artifacts (A-2: the sandboxed frame, the Artifacts page, ARTIFACT + Open)');
	results.forEach(r => console.log(r));
	console.log(pass + ' passed, ' + fail + ' failed');
	process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
