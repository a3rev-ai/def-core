/*
 * The "Finish connecting →" link survives a focus event — behavioural harness
 * (C3 follow-up / v7.8.4). Runs the SHIPPED initIntegrations block, extracted by
 * marker out of assets/js/staff-ai.js, inside jsdom against the SHIPPED
 * #connectionsPane markup sliced out of templates/staff-ai-shell.php. Nothing
 * here is a copy of the code or the markup under test.
 *
 * The defect it pins: `posting` goes false the moment the authorize POST
 * resolves — the moment the link is rendered — and the window focus handler
 * rebuilds the list on every focus event after that. Switching to the consent
 * tab and back IS a focus event, so the one gesture the link exists for was the
 * gesture that destroyed it. The fix carries the link across the rebuild rather
 * than suppressing the rebuild, so these checks assert BOTH halves: the reload
 * still happens, and the link is still there afterwards.
 *
 * Unlike harness-c3.js this one does not boot the page shell: initIntegrations
 * registers itself on consolePages, so the harness captures that entry and
 * drives onEnter / onLeave directly. What is under test is the block's own
 * state, not the shell's routing — harness-c3.js owns that.
 *
 * Bite check: put 7.8.3's initIntegrations in a scratch file and point the
 * INTEGRATIONS env var at it — the carry-across checks must go red.
 *   INTEGRATIONS=/tmp/old-integrations.js node tests/browser/harness-c3b.js
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const INTEGRATIONS = extract.integrations();

const HTML = `<!doctype html><html><body>
${extract.templatePage('connectionsPane')}
<textarea id="composerInput"></textarea>
</body></html>`;

const SLACK = { server_id: 's-slack', category: 'slack', authorized: false, has_grant: false };
const SLACK_CONNECTED = { server_id: 's-slack', category: 'slack', authorized: true, has_grant: true };
const GMAIL = { server_id: 's-gmail', category: 'gmail', authorized: true, has_grant: true };
const CONSENT = 'https://consent.e.test/go';

function boot(opts) {
	opts = opts || {};
	const dom = new JSDOM(HTML, { url: 'https://e.test/staff-ai/', pretendToBeVisual: true });
	const window = dom.window, document = window.document;
	const composerInput = document.getElementById('composerInput');

	// The fixture the REST seam answers from, MUTABLE between reloads: that is how
	// "the list reload finds the account connected" is staged.
	const world = {
		apps: opts.apps || [SLACK, GMAIL],
		mail: opts.mail || [{ id: 'a1', toolkit: 'gmail', address: 'me@e.test', primary: true }],
		consentUrl: opts.consentUrl || CONSENT,
		requests: []
	};
	async function apiRequest(url, init) {
		const method = (init && init.method) || 'GET';
		world.requests.push({ url: url, method: method });
		if (/^\/user\/integrations$/.test(url)) return { configured: true, apps: world.apps };
		if (/^\/mail-connections/.test(url)) return { connections: world.mail };
		if (/^\/primary-mailbox/.test(url)) return {};
		if (/disconnect$/.test(url)) return { requested: 1, failed: 0 };
		if (/authorize$|connect-another$/.test(url)) {
			return { status: 'pending', redirect_url: world.consentUrl };
		}
		return {};
	}

	const consolePages = [];
	const outer = [
		window, document, consolePages,
		function (key, def) { return def; },          // t
		apiRequest,
		'Sue',                                        // assistantName
		function (fn) { fn(); },                      // onAssistantName
		function () {},                               // clearActiveProject
		function () {},                               // resetToNewChat
		composerInput,
		function () {},                               // updateSendButton
		function () {},                               // sendMessage
		function (x) { return String(x || ''); },     // formatTime
		// jsdom ships no CSS.escape; the real page reads the global. Only the
		// mail-row lookup uses it, and the slugs it escapes are [a-z_].
		window.CSS && window.CSS.escape ? window.CSS
			: { escape: function (v) { return String(v).replace(/([^\w-])/g, '\\$1'); } }
	];
	const names = ['window', 'document', 'consolePages', 't', 'apiRequest', 'assistantName',
		'onAssistantName', 'clearActiveProject', 'resetToNewChat', 'composerInput',
		'updateSendButton', 'sendMessage', 'formatTime', 'CSS'];
	new window.Function(...names, INTEGRATIONS)(...outer);

	const page = consolePages.find(p => p.route === 'connections');
	if (!page) throw new Error('initIntegrations did not register the connections page');

	const q = sel => document.querySelector(sel);
	return {
		window, document, world, page,
		enter: () => page.onEnter(),
		leave: () => page.onLeave(),
		focus: () => window.dispatchEvent(new window.Event('focus')),
		status: () => document.getElementById('integrationsStatus').textContent,
		row: id => q('.integration-row[data-server-id="' + id + '"]'),
		connectBtn: id => q('.integration-row[data-server-id="' + id + '"] button.integration-btn-primary'),
		link: el => (el || document).querySelector('a.integration-btn-primary'),
		dismiss: el => (el || document).querySelector('.integration-btn-dismiss'),
		picker: () => q('.integration-primary'),
		another: () => [...document.querySelectorAll('.integration-primary button')]
			.find(b => b.textContent === 'Connect another account') || null,
		reloads: () => world.requests.filter(r => /^\/user\/integrations$/.test(r.url)).length,
		posts: re => world.requests.filter(r => re.test(r.url) && r.method === 'POST').length
	};
}

const tick = (w, n) => new Promise(r => w.setTimeout(r, n === undefined ? 30 : n));
// A missing target is a red check below, never a thrown harness: half of these
// controls do not exist in the code this is bite-checked against, and a harness
// that dies on the first one reports nothing about the other twenty.
function click(w, el) {
	if (!el) return false;
	el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
	return true;
}
// The link is well formed: https, a new tab, noopener, and the shipped label.
// What was true of the first render must be true of every carried-across one.
function wellFormed(link) {
	return !!link && link.href === CONSENT && link.target === '_blank'
		&& /noopener/.test(link.rel) && link.textContent === 'Finish connecting →';
}
const awaiting = t => /Click .Finish connecting./.test(t.status());

const results = [];
let pass = 0, fail = 0, n = 0;
function check(label, ok, detail) {
	n++;
	if (ok) { pass++; results.push('  ok   ' + n + '. ' + label); }
	else { fail++; results.push('  FAIL ' + n + '. ' + label + (detail ? '  [' + detail + ']' : '')); }
}

(async function () {

	// ── PROOF: connect() renders the link, and a FOCUS EVENT does not take it ──
	// The defect, stated as a test. The focus event must STILL reload — that is
	// how a finished consent becomes a Connected badge — and the link must still
	// be on the row when the rebuild is done.
	{
		const t = boot();
		t.enter();
		await tick(t.window);
		click(t.window, t.connectBtn('s-slack'));
		await tick(t.window);
		check('connect(): the consent URL becomes a "Finish connecting →" link in that row — https, '
			+ 'target=_blank, rel=noopener',
			wellFormed(t.link(t.row('s-slack'))) && t.posts(/authorize$/) === 1,
			'link=' + !!t.link(t.row('s-slack')) + ' posts=' + t.posts(/authorize$/));

		const before = t.reloads();
		t.focus();
		await tick(t.window);
		check('THE FIX: a window focus event still REBUILDS the list — and the link is still there '
			+ '(switching to the consent tab and back IS that event)',
			t.reloads() === before + 1 && wellFormed(t.link(t.row('s-slack'))),
			'reloads ' + before + '→' + t.reloads() + ' link=' + !!t.link(t.row('s-slack')));

		const carried = t.link(t.row('s-slack'));
		check('the carried-across link is the row\'s ONLY action — no Connect button beside it, the '
			+ 'way connect() left the cell',
			!!carried && carried.closest('.integration-row') === t.row('s-slack')
			&& !t.connectBtn('s-slack'),
			'link=' + !!carried + ' connectBtn=' + !!t.connectBtn('s-slack'));

		check('the sentence that goes with the link is restored by the rebuild too',
			awaiting(t), JSON.stringify(t.status()));

		for (let i = 0; i < 4; i++) { t.focus(); await tick(t.window); }
		check('FOUR more focus events (a phone switching apps) leave the link exactly where it was',
			wellFormed(t.link(t.row('s-slack'))), 'link=' + !!t.link(t.row('s-slack')));

		check('the pending link stays on ITS row — the connected row is untouched',
			!t.link(t.row('s-gmail')) && /Connected/.test(t.row('s-gmail').textContent),
			'gmail=' + JSON.stringify(t.row('s-gmail').textContent));
	}

	// ── PROOF: the reload that finds the account connected retires the link ────
	{
		const t = boot();
		t.enter();
		await tick(t.window);
		click(t.window, t.connectBtn('s-slack'));
		await tick(t.window);
		check('the link is up before consent is finished', !!t.link(t.row('s-slack')));

		// Consent finished in the other tab: the next read of the list says so.
		t.world.apps = [SLACK_CONNECTED, GMAIL];
		t.focus();
		await tick(t.window);
		const row = t.row('s-slack');
		check('THE FIRST EXIT: the reload finds the account CONNECTED — the link is gone, its × with '
			+ 'it, and the row shows Connected',
			!t.link(row) && !t.dismiss(row) && /Connected/.test(row.textContent),
			'link=' + !!t.link(row) + ' text=' + JSON.stringify(row.textContent));

		check('and the awaiting sentence goes with it — nothing is left telling the user to click a '
			+ 'link that is no longer there',
			!awaiting(t), JSON.stringify(t.status()));

		t.focus();
		await tick(t.window);
		check('a later focus event does not resurrect the retired link',
			!t.link(t.row('s-slack')), 'link=' + !!t.link(t.row('s-slack')));
	}

	// ── PROOF: an app removed while its link is pending takes the link with it ─
	// The only way a pending link outlives its row: an administrator drops the
	// integration while the consent tab is open. It must not come back with the app.
	{
		const t = boot();
		t.enter();
		await tick(t.window);
		click(t.window, t.connectBtn('s-slack'));
		await tick(t.window);
		t.world.apps = [GMAIL];
		t.focus();
		await tick(t.window);
		const gone = !t.row('s-slack');
		t.world.apps = [SLACK, GMAIL];
		t.focus();
		await tick(t.window);
		check('an app REMOVED while its link is pending takes the link with it — and if it comes '
			+ 'back it comes back as Connect, never as a stale consent URL, with nothing left on '
			+ 'the status line telling the user to click one',
			gone && !t.link(t.row('s-slack')) && !!t.connectBtn('s-slack') && !awaiting(t),
			'gone=' + gone + ' link=' + !!t.link(t.row('s-slack')) + ' status=' + JSON.stringify(t.status()));
	}

	// ── PROOF: the × is the user's own way out ────────────────────────────────
	{
		const t = boot();
		t.enter();
		await tick(t.window);
		click(t.window, t.connectBtn('s-slack'));
		await tick(t.window);
		const x = t.dismiss(t.row('s-slack'));
		check('the link carries a × with a label of its own (not a bare glyph for a screen reader)',
			!!x && x.textContent === '×'
			&& x.getAttribute('aria-label') === 'Dismiss “Finish connecting”',
			'x=' + !!x + ' label=' + (x && x.getAttribute('aria-label')));

		click(t.window, x);
		await tick(t.window);
		check('THE SECOND EXIT: the × drops the link and puts the row back as it was — Connect again, '
			+ 'the account still not connected',
			!t.link(t.row('s-slack')) && !!t.connectBtn('s-slack')
			&& t.connectBtn('s-slack').textContent === 'Connect',
			'link=' + !!t.link(t.row('s-slack')) + ' btn=' + !!t.connectBtn('s-slack'));

		t.focus();
		await tick(t.window);
		check('a focus event after the × does not bring the dismissed link back',
			!t.link(t.row('s-slack')) && !!t.connectBtn('s-slack'),
			'link=' + !!t.link(t.row('s-slack')));
	}

	// ── PROOF: Connect another account follows the same rule ──────────────────
	{
		const t = boot();
		t.enter();
		await tick(t.window, 40);
		check('connectAnother(): the mail row offers "Connect another account"', !!t.another());

		click(t.window, t.another());
		await tick(t.window);
		check('connectAnother(): the consent URL becomes the same link, in the picker, on that row',
			wellFormed(t.link(t.picker())) && t.posts(/connect-another$/) === 1
			&& t.link(t.picker()).closest('.integration-row') === t.row('s-gmail'),
			'link=' + !!t.link(t.picker()) + ' posts=' + t.posts(/connect-another$/));

		const before = t.reloads();
		t.focus();
		await tick(t.window, 40);
		check('THE FIX, on Connect another account: the focus event rebuilds and the link is still in '
			+ 'the picker',
			t.reloads() === before + 1 && wellFormed(t.link(t.picker())) && !t.another(),
			'reloads ' + before + '→' + t.reloads() + ' link=' + !!t.link(t.picker()));

		// The second account arrives. For an app that was ALREADY authorized, that —
		// not `authorized` flipping — is what "the reload finds it connected" means.
		t.world.mail = t.world.mail.concat(
			[{ id: 'a2', toolkit: 'gmail', address: 'other@e.test', primary: false }]);
		t.focus();
		await tick(t.window, 40);
		check('THE FIRST EXIT, on Connect another account: the reload finds a SECOND account — the '
			+ 'link is gone, the button is back, and both accounts have a radio',
			!t.link(t.picker()) && !!t.another()
			&& t.picker().querySelectorAll('input[type="radio"]').length === 2,
			'link=' + !!t.link(t.picker()) + ' another=' + !!t.another()
			+ ' radios=' + t.picker().querySelectorAll('input[type="radio"]').length);

		check('and its awaiting sentence is retired with it', !awaiting(t), JSON.stringify(t.status()));
	}
	{
		const t = boot();
		t.enter();
		await tick(t.window, 40);
		click(t.window, t.another());
		await tick(t.window);
		click(t.window, t.dismiss(t.picker()));
		await tick(t.window, 40);
		check('THE SECOND EXIT, on Connect another account: the × drops the link and restores the '
			+ 'button',
			!t.link(t.picker()) && !!t.another(), 'another=' + !!t.another());

		t.focus();
		await tick(t.window, 40);
		check('and a focus event after that does not bring it back',
			!t.link(t.picker()) && !!t.another(), 'link=' + !!t.link(t.picker()));
	}

	// ── PROOF: the scheme rule holds on the carried-across render too ─────────
	// A URL that is not https never becomes a link, and therefore never becomes a
	// pending one: the second render passes the same gate as the first.
	{
		const t = boot({ consentUrl: 'javascript:alert(1)' });
		t.enter();
		await tick(t.window);
		click(t.window, t.connectBtn('s-slack'));
		await tick(t.window);
		const noLink = !t.document.querySelector('a.integration-btn-primary');
		const said = /Could not start the connection/.test(t.status());
		t.focus();
		await tick(t.window);
		check('a non-https consent URL renders NO link, says so, and leaves nothing pending for a '
			+ 'focus event to render',
			noLink && said && !t.document.querySelector('a.integration-btn-primary'),
			'noLink=' + noLink + ' said=' + said);
	}

	// ── PROOF: leaving the page still disarms the focus re-check (C3's rule) ──
	// The link is state now rather than a node, so the page it belongs to must
	// still be able to stop firing loads when nobody is looking at it.
	{
		const t = boot();
		t.enter();
		await tick(t.window);
		click(t.window, t.connectBtn('s-slack'));
		await tick(t.window);
		t.leave();
		const before = t.reloads();
		t.focus();
		t.focus();
		await tick(t.window);
		const quiet = t.reloads() === before;
		t.enter();
		await tick(t.window);
		check('onLeave still DISARMS the re-check — two focus events off the page fire no load — and '
			+ 'the link is waiting on the row when the page is opened again',
			quiet && wellFormed(t.link(t.row('s-slack'))),
			'reloads ' + before + '→' + t.reloads() + ' link=' + !!t.link(t.row('s-slack')));
	}

	console.log(results.join('\n'));
	console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
	process.exit(fail ? 1 : 0);
})();
