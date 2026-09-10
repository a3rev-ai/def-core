/*
 * The installed app keeps up with the release (C3b / v7.9.0) — behavioural harness.
 * Runs the SHIPPED release-check block (extracted by marker) inside jsdom and drives it
 * through the moments that matter: the same version is not news, a newer one waits for
 * the stream, the composer and the user's staged files to be quiet, a rollback is taken
 * too, a server stuck on a version this page will never become reloads ONCE, and the
 * notice names the version only when the reload really landed. Nothing here is a copy of
 * the code under test.
 *
 * `location`, `fetch`, `navigator` and `sessionStorage` are declared in the HEAD scaffold
 * rather than left to the window: jsdom implements neither reload nor fetch, a Function
 * body sees no window globals, and a reload that really navigated would take the checks
 * with it.
 *
 * 25 checks.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const REPO = path.resolve(__dirname, '..', '..');
const RELEASE = extract.release();
const RUNNING = '7.8.9';

const HTML = `<!doctype html><html><body>
<div id="infoBanner"></div>
<textarea id="composerInput"></textarea>
</body></html>`;

// Everything the block reads from the surrounding closure.
const HEAD = `
	var sessionStorage = window.sessionStorage;   // the window's own, kept across a "reload"
	var composerInput = document.getElementById('composerInput');
	var _isStreaming = false, isLoading = false, conversationOn = false, staged = false;
	var _streamAbort = null, _eventsSeen = 0, RESUME_GRACE_MS = 0;
	var location = { reload: function () { reloads.push(1); } };
	function hasActiveFiles() { return staged; }
	function endConversation() {}
	function t(k, fb) { return fb; }
	function showInfo(msg) { notices.push(msg); }
	var fetch = function (url, init) {
		fetches.push({ url: url, cache: init && init.cache });
		if (server.offline) return Promise.reject(new Error('offline'));
		return Promise.resolve({
			ok: server.ok !== false,
			json: function () { return Promise.resolve({ version: server.version }); }
		});
	};
`;

const TAIL = `
	return {
		reloads: function () { return reloads.length; },
		fetches: function () { return fetches; },
		notices: function () { return notices; },
		streaming: function (on) { _isStreaming = on; },
		handsFree: function (on) { conversationOn = on; },
		stage: function (on) { staged = on; },
		type: function (text) { composerInput.value = text; composerInput.className = ''; },
		suggest: function (text) {
			composerInput.value = text;
			composerInput.className = 'staff-ai-suggestion-text';
		}
	};
`;

/*
 * One window per case. `boot(server, window, running)` reruns the block in the SAME
 * window, which is what a reload is: a fresh script over the same sessionStorage — and
 * `running` is the version the server handed back with that page, so a reload that did
 * NOT take is spelled by passing the old version again.
 */
function boot(server, window, running) {
	const dom = window ? null : new JSDOM(HTML, { url: 'https://e.test/staff-ai/', pretendToBeVisual: true });
	const w = window || dom.window;
	const config = { version: running || RUNNING, homeUrl: 'https://e.test/' };
	const net = { onLine: !server.offline };
	const state = { reloads: [], fetches: [], notices: [] };
	const factory = new w.Function('window', 'document', 'StaffAIConfig', 'server', 'navigator',
		'reloads', 'fetches', 'notices', HEAD + RELEASE + TAIL);
	const api = factory(w, w.document, config, server, net,
		state.reloads, state.fetches, state.notices);
	api.window = w;
	api.offline = function (on) { net.onLine = !on; };
	api.foreground = function () { visibility(w, 'visible'); };
	return api;
}

function visibility(w, value) {
	Object.defineProperty(w.document, 'visibilityState', { value: value, configurable: true });
	w.document.dispatchEvent(new w.Event('visibilitychange'));
}

const tick = () => new Promise(r => setImmediate(r));

let pass = 0, fail = 0;
const results = [];
function check(label, ok, detail) {
	if (ok) { pass++; results.push('  ok   ' + label); }
	else { fail++; results.push('  FAIL ' + label + (detail ? ' — ' + detail : '')); }
}

(async function () {
	// ── The same version is not news ───────────────────────────────────────
	{
		const t = boot({ version: RUNNING });
		await tick();
		check('the app asks the server once on launch, past any cache',
			t.fetches().length === 1 && t.fetches()[0].url === 'https://e.test/staff-ai/manifest.json'
			&& t.fetches()[0].cache === 'no-store', JSON.stringify(t.fetches()));
		check('a server on the same version reloads nothing', t.reloads() === 0);

		t.foreground();
		await tick();
		check('coming back asks again — the release it is waiting for may have landed since',
			t.fetches().length === 2, String(t.fetches().length));
	}

	// ── A newer version, at a quiet moment ─────────────────────────────────
	{
		const t = boot({ version: '7.9.0' });
		await tick();
		check('a release the app is not running reloads it', t.reloads() === 1, String(t.reloads()));
	}

	// ── …and never over the user's words ───────────────────────────────────
	{
		const t = boot({ version: '7.9.0' });
		t.streaming(true);
		await tick();
		check('a reply still streaming holds the reload back', t.reloads() === 0);

		t.streaming(false);
		t.foreground();
		await tick();
		check('and the next foreground takes it, without asking the server again',
			t.reloads() === 1 && t.fetches().length === 1,
			t.reloads() + ' reloads / ' + t.fetches().length + ' fetches');
	}
	{
		const t = boot({ version: '7.9.0' });
		t.type('half a question');
		await tick();
		check('a half-typed message holds the reload back', t.reloads() === 0);

		t.foreground();
		await tick();
		check('and coming back with it still there does not take it either', t.reloads() === 0);

		t.type('');
		t.foreground();
		await tick();
		check('once the composer is empty the next foreground takes it', t.reloads() === 1);
		check('and the waiting app never asks the server twice for news it already has',
			t.fetches().length === 1, String(t.fetches().length));
	}
	{
		const t = boot({ version: '7.9.0' });
		t.stage(true);
		await tick();
		check('a staged attachment holds the reload back — the composer is text OR files',
			t.reloads() === 0);

		t.stage(false);
		t.foreground();
		await tick();
		check('and the reload follows once the file is gone', t.reloads() === 1);
	}
	{
		// DEF sends `suggestions` AFTER done, and the client writes it into the composer.
		// That is the app's text, not the user's, or the app would never update again
		// after a completed turn — which is where it spends most of its life.
		const t = boot({ version: '7.9.0' });
		t.suggest('Shall I book it?');
		await tick();
		check('the suggestion the server wrote into the composer does NOT hold it back',
			t.reloads() === 1, String(t.reloads()));
	}
	{
		const t = boot({ version: '7.9.0' });
		t.handsFree(true);
		await tick();
		check('a hands-free conversation holds the reload back mid-sentence', t.reloads() === 0);
	}

	// ── A rollback is a release too ────────────────────────────────────────
	{
		const t = boot({ version: '7.8.7' });
		await tick();
		check('a server that has ROLLED BACK is taken as well — different, not newer',
			t.reloads() === 1, String(t.reloads()));
	}

	// ── Offline: old code beats a dead app ─────────────────────────────────
	{
		const t = boot({ version: '7.9.0' });
		t.streaming(true);
		await tick();
		t.streaming(false);
		t.offline(true);
		t.foreground();
		await tick();
		check('with no network the reload is refused — the worker caches nothing to come back to',
			t.reloads() === 0, String(t.reloads()));

		t.offline(false);
		t.foreground();
		await tick();
		check('and it is taken as soon as the network is back', t.reloads() === 1);
	}
	{
		const t = boot({ offline: true });
		await tick();
		check('a failed check reloads nothing and throws nothing', t.reloads() === 0);

		t.foreground();
		await tick();
		check('and the next foreground asks again — the guard is not left stuck on',
			t.fetches().length === 2, String(t.fetches().length));
	}

	// ── A server stuck on a version this page will never become ────────────
	{
		const t = boot({ version: '9.9.9' });
		await tick();
		check('the app reloads for it once', t.reloads() === 1);

		const stuck = boot({ version: '9.9.9' }, t.window, RUNNING);   // came back UNCHANGED
		await tick();
		check('and never again — one reload per target, not a loop at page speed',
			stuck.reloads() === 0, String(stuck.reloads()));
		check('and it does not claim an update that did not happen',
			stuck.notices().length === 0, JSON.stringify(stuck.notices()));
	}

	// ── The notice, on the other side of a reload that landed ──────────────
	{
		const t = boot({ version: '7.9.0' });
		await tick();
		const back = boot({ version: '7.9.0' }, t.window, '7.9.0');   // the reload took
		check('the app says which version it came back on, once',
			back.notices().length === 1 && back.notices()[0] === 'Updated to 7.9.0',
			JSON.stringify(back.notices()));

		const again = boot({ version: '7.9.0' }, t.window, '7.9.0');
		check('and does not say it again on the next launch', again.notices().length === 0,
			JSON.stringify(again.notices()));
	}

	// ── Both halves wired, not just the block ──────────────────────────────
	{
		const shell = fs.readFileSync(path.join(REPO, 'templates/staff-ai-shell.php'), 'utf8');
		const php = fs.readFileSync(path.join(REPO, 'includes/class-def-core-staff-ai.php'), 'utf8');
		check('the page carries the version it was SERVED with, and the manifest the live one',
			/version:\s*<\?php echo wp_json_encode\( DEF_CORE_VERSION \)/.test(shell)
			&& /'version'\s*=>\s*DEF_CORE_VERSION,/.test(php));
	}

	console.log('C3b — the installed app keeps up with the release');
	results.forEach(line => console.log(line));
	console.log('  ' + pass + ' passed, ' + fail + ' failed');
	process.exit(fail ? 1 : 0);
})();
