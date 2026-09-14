/*
 * A picture stays in the chat — behavioural harness (v8.0.0, DEF images runsheet I-2).
 *
 * "The image does not persist in the chat" (Steve's row 8 canary, 2026-09-14): the
 * console rendered a picture from the data: URL it held for the live turn and had
 * nothing to render on reload. From 8.0.0 the upload rail makes a small companion
 * of every picture and declares it at init; the server keeps it beside the
 * original; a stored turn comes back with its attachments; and the chat shows a
 * stored picture through the console's own proxy — the companion when the upload
 * kept one, else the original — with a tap opening the original.
 *
 * Runs the SHIPPED attachment render and the SHIPPED upload rail (both sliced by
 * marker, tests/browser/extract.js) inside jsdom, with the seams — the server,
 * the blob store, an iPhone or not — under each check's control. The companion
 * itself is drawn on a canvas, which jsdom has not; the rail is fed one.
 *
 *  1. The render: a stored picture with a companion shows the companion's proxy
 *     address; without one, the original's; a stored document is a chip with its
 *     name as TEXT; a tap opens the original in a new tab on a desktop and hands
 *     it to the share sheet on an iPhone; the live turn's data: URL still renders
 *     and opens as a blob; a stored attachment with no thread, or a type that is
 *     not a safe raster, is a chip; addresses are encoded; a picture landing after
 *     the reader reached the end keeps them there and leaves a reader who scrolled up.
 *  2. The rail: a picture with a companion declares it at init and PUTs it to the
 *     companion's link before commit; a refused declaration is retried without one
 *     and the picture uploads; a file with no companion inits exactly as before;
 *     a companion PUT that fails costs nothing; the original's PUT failing still
 *     fails the upload.
 *
 * 17 checks.
 */
const { JSDOM, VirtualConsole } = require('jsdom');
const quiet = new VirtualConsole();
quiet.on('jsdomError', function () {});
const extract = require('./extract');
const RENDER = extract.chatAttachments();
const RAIL = extract.uploadRail();

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + label); }
	else { fail++; results.push('  FAIL ' + label + (detail ? ' -- ' + detail : '')); }
}
function click(w, el) {
	return el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
}

// ── 1. the render ──────────────────────────────────────────────────────────

const HTML = '<!doctype html><html><body><div class="messages-container" id="messagesContainer"><div id="messagesList"></div></div></body></html>';

function bootRender(opts) {
	const dom = new JSDOM(HTML, { url: 'https://e.test/staff-ai/', pretendToBeVisual: true, virtualConsole: quiet });
	const window = dom.window, document = window.document;
	const container = document.getElementById('messagesContainer');
	const calls = { opened: [], shared: [], blobs: 0 };
	window.open = function (url, target) { calls.opened.push({ url: url, target: target }); return null; };
	const api = new window.Function(
		'window', 'document', 'StaffAIConfig', 'currentConversationId', 'messagesContainer',
		'isIOS', 'shareFile', 'dataUrlToBlob', 'URL',
		RENDER + '\nreturn { appendFileAttachments: appendFileAttachments, storedAttachment: storedAttachment, attachmentUrl: attachmentUrl };'
	)(
		window, document, { homeUrl: 'https://e.test/' }, opts.thread === undefined ? 'staff-1a2b' : opts.thread, container,
		function () { return !!opts.ios; },
		function (url, name) { calls.shared.push({ url: url, name: name }); },
		function () { return {}; },
		{ createObjectURL: function () { calls.blobs++; return 'blob:e.test/1'; }, revokeObjectURL: function () {} }
	);
	return { window, document, container, api, calls };
}

function render(t, atts) {
	const target = t.document.createElement('div');
	t.document.getElementById('messagesList').appendChild(target);
	t.api.appendFileAttachments(target, atts.map(t.api.storedAttachment));
	return target;
}

(function () {
	const t = bootRender({});
	const stored = { file_id: 'upload_0123abcd', kind: 'image', mime_type: 'image/png', filename: 'garden.png', has_thumbnail: true };

	let el = render(t, [stored]);
	let img = el.querySelector('img.message-image-thumb');
	check('a stored picture with a companion renders the companion through the proxy',
		img && img.getAttribute('src') === 'https://e.test/staff-ai-attachment/staff-1a2b/upload_0123abcd/thumbnail' && img.alt === 'garden.png',
		img ? img.getAttribute('src') : 'no img');

	el = render(t, [Object.assign({}, stored, { has_thumbnail: false })]);
	img = el.querySelector('img.message-image-thumb');
	check('a stored picture without a companion renders the original through the proxy',
		img && img.getAttribute('src') === 'https://e.test/staff-ai-attachment/staff-1a2b/upload_0123abcd', img ? img.getAttribute('src') : 'no img');

	el = render(t, [{ file_id: 'upload_9', kind: 'document', mime_type: 'application/pdf', filename: '<b>q</b>.pdf' }]);
	const chip = el.querySelector('.message-file-indicator');
	check('a stored document is a chip whose name is text, never markup',
		chip && !el.querySelector('img') && chip.textContent.indexOf('<b>q</b>.pdf') !== -1 && !chip.querySelector('b'),
		chip ? chip.innerHTML : 'no chip');

	el = render(t, [stored]);
	click(t.window, el.querySelector('img'));
	check('a tap on a desktop opens the original in a new tab',
		t.calls.opened.length === 1 && t.calls.opened[0].url === 'https://e.test/staff-ai-attachment/staff-1a2b/upload_0123abcd'
			&& t.calls.opened[0].target === '_blank' && t.calls.shared.length === 0,
		JSON.stringify(t.calls));

	// The live turn: the data: URL the console holds, opened as a blob as before.
	const live = t.document.createElement('div');
	t.api.appendFileAttachments(live, [{ name: 'garden.png', type: 'image/png', thumbnailUrl: 'data:image/png;base64,AAAA' }]);
	const liveImg = live.querySelector('img.message-image-thumb');
	click(t.window, liveImg);
	check('the live turn still renders its data: URL and opens it as a blob',
		liveImg && liveImg.getAttribute('src') === 'data:image/png;base64,AAAA' && t.calls.blobs === 1
			&& t.calls.opened.length === 2 && t.calls.opened[1].url === 'blob:e.test/1' && t.calls.shared.length === 0,
		JSON.stringify(t.calls));

	el = render(t, [Object.assign({}, stored, { mime_type: 'image/svg+xml', filename: 'logo.svg' })]);
	check('a stored attachment whose type is not a safe raster is a chip, not a picture',
		!el.querySelector('img') && el.querySelector('.message-file-indicator') && el.textContent.indexOf('logo.svg') !== -1);

	const t2 = bootRender({ thread: 'staff 1/x' });
	el = render(t2, [stored]);
	img = el.querySelector('img');
	check('the thread and file ids are encoded into the address',
		img && img.getAttribute('src') === 'https://e.test/staff-ai-attachment/staff%201%2Fx/upload_0123abcd/thumbnail', img ? img.getAttribute('src') : 'no img');

	const t3 = bootRender({ thread: null });
	el = render(t3, [stored]);
	check('a stored attachment with no thread to ask under is a chip',
		!el.querySelector('img') && el.querySelector('.message-file-indicator') && el.textContent.indexOf('garden.png') !== -1);
})();

(function () {
	const t = bootRender({ ios: true });
	const el = render(t, [{ file_id: 'upload_0123abcd', mime_type: 'image/jpeg', filename: 'IMG_0412.jpg', has_thumbnail: true }]);
	click(t.window, el.querySelector('img'));
	check('a tap on an iPhone hands the original to the share sheet with its name',
		t.calls.shared.length === 1 && t.calls.shared[0].url === 'https://e.test/staff-ai-attachment/staff-1a2b/upload_0123abcd'
			&& t.calls.shared[0].name === 'IMG_0412.jpg' && t.calls.opened.length === 0,
		JSON.stringify(t.calls));
})();

// A picture landing after the reader reached the end. jsdom lays nothing out, so
// the geometry is set by hand: a 1000px thread in a 400px view, a 200px picture.
(function () {
	function geometry(t, scrollTop) {
		Object.defineProperty(t.container, 'scrollHeight', { value: 1000, configurable: true });
		Object.defineProperty(t.container, 'clientHeight', { value: 400, configurable: true });
		t.container.scrollTop = scrollTop;
		const el = render(t, [{ file_id: 'upload_0123abcd', mime_type: 'image/png', filename: 'g.png', has_thumbnail: true }]);
		const img = el.querySelector('img');
		Object.defineProperty(img, 'clientHeight', { value: 200, configurable: true });
		img.dispatchEvent(new t.window.Event('load'));
		return t.container.scrollTop;
	}
	const t = bootRender({});
	check('a reader who was at the end when the picture landed is kept at the end', geometry(t, 400) === 1000);
	const t2 = bootRender({});
	check('a reader who had scrolled up is left where they are', geometry(t2, 100) === 100);
})();

// ── 2. the rail ────────────────────────────────────────────────────────────

function bootRail(opts) {
	const dom = new JSDOM(HTML, { url: 'https://e.test/staff-ai/', pretendToBeVisual: true, virtualConsole: quiet });
	const window = dom.window;
	const calls = { api: [], puts: [] };
	async function apiRequest(endpoint, options) {
		const body = options && options.body ? JSON.parse(options.body) : null;
		calls.api.push({ endpoint: endpoint, body: body });
		if (endpoint === '/uploads/init') {
			if (body.thumbnail && opts.refuseThumbnail) { const e = new Error('validation_failed'); e.status = 400; throw e; }
			const r = { file_id: 'upload_1', upload_url: 'https://blob/o' };
			if (body.thumbnail) r.thumbnail_upload_url = 'https://blob/t';
			return r;
		}
		return { status: 'ready' };
	}
	async function fetch(url, init) {
		calls.puts.push({ url: url, method: init.method, type: init.headers['Content-Type'], body: init.body });
		if (url === 'https://blob/t' && opts.thumbnailPutFails) throw new Error('network');
		return { ok: !(url === 'https://blob/o' && opts.originalPutFails), status: 200 };
	}
	const api = new window.Function(
		'window', 'document', 'apiRequest', 'fetch', 'renderStagedFiles', 'updateSendButton',
		'getMimeFromExtension', 'currentConversationId', 't', 'UPLOAD_BLOB_MAX_RETRIES', 'UPLOAD_BLOB_BACKOFF_MS',
		RAIL + '\nreturn { uploadSingleFile: uploadSingleFile };'
	)(
		window, window.document, apiRequest, fetch, function () {}, function () {},
		function (name) { return /\.png$/.test(name) ? 'image/png' : 'application/pdf'; }, 'staff-1a2b',
		function (k, d) { return d; }, 0, []
	);
	return { window, api, calls };
}

function entryFor(w, name, size, thumbnail) {
	return {
		file: { name: name, size: size, type: '', arrayBuffer: function () { return Promise.resolve(new w.ArrayBuffer(size)); } },
		status: 'staged', fileId: null, error: null, thumbnailUrl: null,
		thumbnailReady: thumbnail ? Promise.resolve(thumbnail) : null
	};
}

(async function () {
	let t = bootRail({});
	let thumb = new t.window.Blob([new t.window.Uint8Array(41000)], { type: 'image/jpeg' });
	let entry = entryFor(t.window, 'garden.png', 2600000, thumb);
	await t.api.uploadSingleFile(entry);
	const init = t.calls.api[0];
	check('a picture with a companion declares it at init',
		init && init.endpoint === '/uploads/init' && JSON.stringify(init.body.thumbnail) === '{"mime_type":"image/jpeg","size_bytes":41000}'
			&& init.body.filename === 'garden.png' && init.body.size_bytes === 2600000, JSON.stringify(init));
	check('the companion is PUT to its own link, after the original and before commit, then the upload commits',
		t.calls.puts.length === 2 && t.calls.puts[0].url === 'https://blob/o'
			&& t.calls.puts[1].url === 'https://blob/t' && t.calls.puts[1].method === 'PUT' && t.calls.puts[1].type === 'image/jpeg' && t.calls.puts[1].body === thumb
			&& t.calls.api.length === 2 && t.calls.api[1].endpoint === '/uploads/commit' && entry.status === 'uploaded',
		JSON.stringify({ puts: t.calls.puts.map(p => p.url), api: t.calls.api.map(a => a.endpoint), status: entry.status }));

	t = bootRail({ refuseThumbnail: true });
	thumb = new t.window.Blob([new t.window.Uint8Array(41000)], { type: 'image/jpeg' });
	entry = entryFor(t.window, 'garden.png', 2600000, thumb);
	await t.api.uploadSingleFile(entry);
	check('a refused declaration is retried without one and the picture uploads',
		t.calls.api.length === 3 && t.calls.api[0].body.thumbnail && !('thumbnail' in t.calls.api[1].body)
			&& t.calls.api[2].endpoint === '/uploads/commit' && t.calls.puts.length === 1 && entry.status === 'uploaded',
		JSON.stringify({ api: t.calls.api, puts: t.calls.puts.length, status: entry.status, error: entry.error }));

	t = bootRail({});
	entry = entryFor(t.window, 'brief.pdf', 5000, null);
	await t.api.uploadSingleFile(entry);
	check('a file with no companion inits exactly as before',
		t.calls.api.length === 2 && !('thumbnail' in t.calls.api[0].body) && t.calls.puts.length === 1 && entry.status === 'uploaded',
		JSON.stringify(t.calls.api[0]));

	t = bootRail({ thumbnailPutFails: true });
	thumb = new t.window.Blob([new t.window.Uint8Array(41000)], { type: 'image/jpeg' });
	entry = entryFor(t.window, 'garden.png', 2600000, thumb);
	await t.api.uploadSingleFile(entry);
	check('a companion PUT that fails costs nothing: the upload commits',
		t.calls.api.length === 2 && t.calls.api[1].endpoint === '/uploads/commit' && entry.status === 'uploaded', entry.error);

	t = bootRail({ originalPutFails: true });
	thumb = new t.window.Blob([new t.window.Uint8Array(41000)], { type: 'image/jpeg' });
	entry = entryFor(t.window, 'garden.png', 2600000, thumb);
	await t.api.uploadSingleFile(entry);
	check('the original\'s PUT failing still fails the upload, and no commit is attempted',
		entry.status === 'failed' && t.calls.api.length === 1, JSON.stringify({ status: entry.status, api: t.calls.api.length }));

	console.log('harness-chat-images: a picture stays in the chat (v8.0.0)');
	results.forEach(r => console.log(r));
	console.log(pass + ' passed, ' + fail + ' failed');
	process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
