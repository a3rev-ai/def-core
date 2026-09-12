/*
 * Memories, Usage and Connections on the console shell — behavioural harness
 * (C3 / v7.8.3). Runs the SHIPPED page-shell block AND the SHIPPED
 * initMemories / initUsage / initIntegrations blocks (all extracted by marker)
 * inside jsdom, against the sidebar nav and the three page sections sliced out
 * of templates/staff-ai-shell.php. Nothing here is a copy of the code or the
 * markup under test.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const SHELL = extract.pageShell();
const MEMORIES = extract.memories();
const USAGE = extract.usage();
const INTEGRATIONS = extract.integrations();

// The fixture is the SHIPPED markup, not a copy of it: the sidebar nav and the
// three page sections are sliced out of templates/staff-ai-shell.php the same
// way the blocks above are sliced out of the JS. A hand-written mirror would
// mean every behaviour check below passed against the mirror while the template
// drifted underneath it — a renamed id, a changed description, the wrong button
// class. #projectsPane is the one hand-written node, and only as a stand-in for
// "some other page" in the page → page checks; nothing asserts against it.
const HTML = `<!doctype html><html><body>
${extract.templateNav()}
<div id="conversationList"><div class="conversation-item" id="conv1">A chat</div></div>
<div class="messages-container" id="messagesContainer"><div id="messagesList"></div></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
<section class="console-page" id="projectsPane" hidden>
  <h1 class="console-page-title" id="projectsTitle" tabindex="-1">Projects</h1>
</section>
${extract.templatePage('memoriesPane')}
${extract.templatePage('usagePane')}
${extract.templatePage('connectionsPane')}
</body></html>`;

// Registered by the harness, so the page → page checks depend on nothing that
// initProjects does.
const SHELL_TAIL = `
    consolePages.push({ route: 'projects', el: document.getElementById('projectsPane'),
      title: document.getElementById('projectsTitle') });
    return { showPage: showPage, showChat: showChat, applyRoute: applyRoute,
             consolePages: consolePages,
             open: function () { return openPage ? openPage.route : null; } };`;

const DEFAULT_MEMORIES = [
	{ entry_id: 'm1', content: 'Prefers short answers', category: 'preferences', created_at: '2026-09-01T10:00:00Z' },
	{ entry_id: 'm2', content: 'Runs the console track', category: 'role', created_at: '2026-08-20T10:00:00Z' }
];
const DEFAULT_USAGE = {
	resets_at: '2026-09-14T00:00:00Z', budget_known: true, budget_tokens: 1000000,
	family_top_model: 'claude-opus-5',
	current_week: { total: 400000, per_model: { 'claude-opus-5': 300000, 'claude-sonnet-5': 100000 } }
};
const DEFAULT_APPS = [
	{ server_id: 's-gmail', category: 'gmail', authorized: true, has_grant: true },
	{ server_id: 's-slack', category: 'slack', authorized: false, has_grant: false }
];
const DEFAULT_MAIL = [
	{ id: 'a1', toolkit: 'gmail', address: 'me@e.test', primary: true },
	{ id: 'a2', toolkit: 'gmail', address: 'other@e.test', primary: false }
];

function boot(startUrl, opts) {
	opts = opts || {};
	const dom = new JSDOM(HTML, { url: startUrl || 'https://e.test/staff-ai/', pretendToBeVisual: true });
	const window = dom.window, document = window.document, location = window.location;
	const messagesContainer = document.getElementById('messagesContainer');
	const composerContainer = document.getElementById('composerContainer');
	const composerInput = document.getElementById('composerInput');
	const conversationList = document.getElementById('conversationList');
	const calls = [];
	const requests = [];

	const shellFactory = new window.Function(
		'window', 'document', 'location', 'messagesContainer', 'composerContainer',
		'conversationList', 'calls', SHELL + SHELL_TAIL
	);
	const api = shellFactory(window, document, location, messagesContainer, composerContainer,
		conversationList, calls);

	// ---- the seam: every outer binding the three blocks read ---------------
	const state = {
		sent: [], newChats: 0, clearedProject: 0,
		confirms: [], confirmAnswer: opts.confirmAnswer !== false,
		onConfirm: opts.onConfirm || null
	};
	async function apiRequest(url, init) {
		const method = (init && init.method) || 'GET';
		requests.push({ url: url, method: method, body: init && init.body });
		if (opts.fail && opts.fail(url, init)) throw new Error('DEF said no');
		if (/^\/memories/.test(url)) {
			if (method === 'DELETE') return {};
			return { memories: opts.memories || DEFAULT_MEMORIES };
		}
		if (/^\/usage/.test(url)) return opts.usage || DEFAULT_USAGE;
		if (/^\/user\/integrations$/.test(url)) {
			return { configured: true, apps: opts.apps || DEFAULT_APPS };
		}
		if (/^\/mail-connections/.test(url)) return { connections: opts.mail || DEFAULT_MAIL };
		if (/^\/primary-mailbox/.test(url)) return {};
		if (/disconnect$/.test(url)) return { requested: 1, failed: 0 };
		if (/authorize$|connect-another$/.test(url)) {
			return { status: 'pending', redirect_url: 'https://consent.e.test/go' };
		}
		return {};
	}
	window.confirm = function (msg) {
		state.confirms.push(msg);
		if (state.onConfirm) state.onConfirm(msg);
		return state.confirmAnswer;
	};

	const outer = [
		window, document, api.consolePages,
		function (key, def) { return def; },        // t
		apiRequest,
		'Sue',                                       // assistantName
		function (fn) { fn(); },                     // onAssistantName
		function () { state.clearedProject++; },     // clearActiveProject
		function () { api.showChat({ focus: false }); state.newChats++; },  // resetToNewChat
		composerInput,
		function () {},                              // updateSendButton
		function () { state.sent.push(composerInput.value); },              // sendMessage
		function (x) { return String(x || ''); },    // formatTime
		// jsdom ships no CSS.escape; the real page reads the global. Only the
		// mail-row lookup uses it, and the slugs it escapes are [a-z_].
		window.CSS && window.CSS.escape ? window.CSS
			: { escape: function (v) { return String(v).replace(/([^\w-])/g, '\$1'); } }
	];
	const names = ['window', 'document', 'consolePages', 't', 'apiRequest', 'assistantName',
		'onAssistantName', 'clearActiveProject', 'resetToNewChat', 'composerInput',
		'updateSendButton', 'sendMessage', 'formatTime', 'CSS'];

	// C5: all three pages reach their Ask entry through the SHARED helper.
	extract.pushAskEntry(window, names, outer);

	new window.Function(...names, MEMORIES)(...outer);
	new window.Function(...names, USAGE)(...outer);
	new window.Function(...names, INTEGRATIONS)(...outer);

	return {
		dom, window, document, location, api, calls, requests, state,
		chatVisible: () => messagesContainer.style.display !== 'none' && composerContainer.style.display !== 'none',
		reqs: (re) => requests.filter(r => re.test(r.url))
	};
}

const tick = (w, n) => new Promise(r => w.setTimeout(r, n === undefined ? 30 : n));
function click(w, el, opts) {
	const init = Object.assign({ bubbles: true, cancelable: true, button: 0,
		ctrlKey: false, metaKey: false, shiftKey: false, altKey: false }, opts || {});
	const ev = new w.MouseEvent('click', init);
	el.dispatchEvent(ev);
	return ev;
}
const clickNav = (t, id, opts) => click(t.window, t.document.getElementById(id), opts);
function key(w, target, k) {
	target.dispatchEvent(new w.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
}
// Boot with a page BEFORE the console in this session's history, so a Back out
// of the console has somewhere real to land.
async function bootWithPrevious(opts) {
	const t = boot('https://e.test/before/', opts);
	t.window.history.pushState({}, '', '/staff-ai/');
	await tick(t.window);
	return t;
}

const PAGES = [
	{ route: 'memories', nav: 'navMemories', pane: 'memoriesPane', title: 'memoriesTitle', api: /^\/memories/ },
	{ route: 'usage', nav: 'navUsage', pane: 'usagePane', title: 'usageTitle', api: /^\/usage/ },
	{ route: 'connections', nav: 'navConnections', pane: 'connectionsPane', title: 'connectionsTitle', api: /^\/user\/integrations$/ }
];

const results = [];
let pass = 0, fail = 0, n = 0;
function check(label, ok, detail) {
	n++;
	if (ok) { pass++; results.push('  ok   ' + n + '. ' + label); }
	else { fail++; results.push('  FAIL ' + n + '. ' + label + (detail ? '  [' + detail + ']' : '')); }
}

(async function () {

	// ── The sidebar tells the truth (D-C7) ─────────────────────────────────
	// Against the SHIPPED nav: the entry's tag, its href and whether it still
	// claims to open a dialog are read off the markup the page renders.
	{
		const t = boot();
		for (const p of PAGES) {
			const item = t.document.getElementById(p.nav);
			check(p.route + ': the sidebar entry is an <a> with href="#' + p.route + '"',
				item.tagName === 'A' && item.getAttribute('href') === '#' + p.route
					&& !item.hasAttribute('aria-haspopup'),
				'tag=' + item.tagName + ' href=' + item.getAttribute('href'));
		}
	}

	// ── Each page opens on its route, with everything else agreeing ────────
	for (const p of PAGES) {
		const t = boot();
		const ev = clickNav(t, p.nav);
		await tick(t.window);
		const pane = t.document.getElementById(p.pane);
		const item = t.document.getElementById(p.nav);
		check(p.route + ': clicking the sidebar entry opens the page, hides the chat, sets the hash, '
			+ 'marks the entry current and focuses the title',
			ev.defaultPrevented === true && t.api.open() === p.route && pane.hidden === false
			&& !t.chatVisible() && t.location.hash === '#' + p.route
			&& item.getAttribute('aria-current') === 'page'
			&& item.classList.contains('sidebar-nav-item-current')
			&& t.document.activeElement === t.document.getElementById(p.title),
			'open=' + t.api.open() + ' hash=' + t.location.hash + ' hidden=' + pane.hidden
			+ ' focus=' + (t.document.activeElement && t.document.activeElement.id));
	}

	// ── PROOF: a reload on the route opens that page ───────────────────────
	for (const p of PAGES) {
		const t = boot('https://e.test/staff-ai/#' + p.route);
		t.api.applyRoute();
		await tick(t.window);
		check(p.route + ': a RELOAD on #' + p.route + ' opens that page (the console booted on the hash)',
			t.api.open() === p.route && t.document.getElementById(p.pane).hidden === false
			&& !t.chatVisible() && t.reqs(p.api).length === 1,
			'open=' + t.api.open() + ' loads=' + t.reqs(p.api).length);
	}

	// ── PROOF: Back returns to the chat ────────────────────────────────────
	for (const p of PAGES) {
		const t = await bootWithPrevious();
		clickNav(t, p.nav);
		await tick(t.window);
		t.window.history.back();
		await tick(t.window, 60);
		check(p.route + ': the browser BACK button returns to the chat (the phone gesture is the same event)',
			t.api.open() === null && t.chatVisible() && t.location.hash === ''
			&& t.document.getElementById(p.pane).hidden === true,
			'open=' + t.api.open() + ' hash=' + JSON.stringify(t.location.hash));
	}

	// ── PROOF: five open/leave cycles leave history.length unchanged ───────
	for (const p of PAGES) {
		const t = await bootWithPrevious();
		const base = t.window.history.length;
		const lens = [];
		for (let i = 0; i < 5; i++) {
			clickNav(t, p.nav);
			await tick(t.window);
			key(t.window, t.document.body, 'Escape');
			await tick(t.window, 60);
			lens.push(t.window.history.length);
		}
		// history.length counts the forward entry a back() leaves behind, in jsdom
		// as in a browser, so the number to hold is the SAME one every cycle — the
		// console must not stack an entry per open/leave and strand the back gesture.
		check(p.route + ': FIVE open/leave cycles leave history.length unchanged from cycle to cycle '
			+ '(leaving pops, never pushes)',
			lens.every(l => l === lens[0]) && lens[0] <= base + 1
			&& t.api.open() === null && t.chatVisible() && t.location.hash === '',
			'base=' + base + ' lens=[' + lens + '] open=' + t.api.open());
	}

	// ── PROOF: Escape returns to the chat, focus lands on the entry ────────
	for (const p of PAGES) {
		const t = boot();
		clickNav(t, p.nav);
		await tick(t.window);
		key(t.window, t.document.body, 'Escape');
		await tick(t.window);
		check(p.route + ': Escape returns to the chat and focus lands back on the sidebar entry',
			t.api.open() === null && t.chatVisible()
			&& t.document.activeElement === t.document.getElementById(p.nav),
			'open=' + t.api.open() + ' focus=' + (t.document.activeElement && t.document.activeElement.id));
	}

	// ── PROOF: the chat is hidden, never reset (D-C5) ──────────────────────
	{
		const t = boot();
		const list = t.document.getElementById('messagesList');
		const reply = t.document.createElement('div');
		reply.id = 'streamingReply';
		reply.textContent = 'The assistant is ';
		list.appendChild(reply);
		t.document.getElementById('composerInput').value = 'a half-typed thought';

		for (const p of PAGES) {
			clickNav(t, p.nav);
			await tick(t.window);
			// the stream lands WHILE the page is open
			reply.textContent += 'still talking. ';
		}
		key(t.window, t.document.body, 'Escape');
		await tick(t.window);

		check('a streaming reply keeps streaming behind all three pages — the node is never detached '
			+ 'and every chunk written while a page was open is there on return',
			t.document.getElementById('streamingReply') === reply
			&& reply.textContent === 'The assistant is still talking. still talking. still talking. '
			&& list.children.length === 1,
			JSON.stringify(reply.textContent));
		check('a half-typed message survives the round trip through all three pages',
			t.document.getElementById('composerInput').value === 'a half-typed thought'
			&& t.chatVisible(),
			JSON.stringify(t.document.getElementById('composerInput').value));
	}

	// ── PROOF: re-entering reloads; only Usage keeps a Refresh ─────────────
	for (const p of PAGES) {
		const t = boot();
		clickNav(t, p.nav);
		await tick(t.window);
		const first = t.reqs(p.api).length;
		key(t.window, t.document.body, 'Escape');
		await tick(t.window);
		clickNav(t, p.nav);
		await tick(t.window);
		check(p.route + ': the page LOADS ON ENTRY and re-entering reloads it',
			first === 1 && t.reqs(p.api).length === 2,
			'first=' + first + ' second=' + t.reqs(p.api).length);
	}
	{
		const t = boot();
		check('Memories and Connections have NO Refresh button; Usage does (its numbers move while you read)',
			!t.document.querySelector('#memoriesPane #memoriesRefresh')
			&& !t.document.querySelector('#connectionsPane #integrationsRefresh')
			&& !!t.document.querySelector('#usagePane #usageRefresh'),
			'usageRefresh=' + !!t.document.getElementById('usageRefresh'));

		// Refresh IS the Usage page's primary action, so it is the filled button —
		// the same class Projects' Create project and Scheduled's New task carry —
		// with the Ask entry secondary beside it.
		const refresh = t.document.getElementById('usageRefresh');
		const ask = t.document.getElementById('usageAskAssistant');
		check('Usage: Refresh is the page\'s FILLED primary action (modal-btn-primary), with Ask '
			+ 'secondary beside it in the same header slot',
			refresh.classList.contains('modal-btn')
			&& refresh.classList.contains('modal-btn-primary')
			&& !refresh.classList.contains('modal-btn-secondary')
			&& ask.classList.contains('modal-btn-secondary')
			&& refresh.parentNode.classList.contains('console-page-actions')
			&& ask.parentNode === refresh.parentNode,
			'refresh=' + refresh.className + ' ask=' + ask.className);
	}

	// ── PROOF: Usage Refresh reloads the numbers IN PLACE ──────────────────
	{
		const t = boot();
		clickNav(t, 'navUsage');
		await tick(t.window);
		const before = t.document.getElementById('usageList').textContent;
		const rowsBefore = t.document.querySelectorAll('#usageList .usage-row').length;
		click(t.window, t.document.getElementById('usageRefresh'));
		await tick(t.window);
		check('Usage: Refresh reloads the numbers IN PLACE — a second GET /usage, the page still open, '
			+ 'the bars and rows rebuilt',
			t.reqs(/^\/usage/).length === 2 && t.api.open() === 'usage'
			&& t.location.hash === '#usage'
			&& t.document.querySelectorAll('#usageList .usage-row').length === rowsBefore
			&& rowsBefore === 2 && t.document.getElementById('usageList').textContent === before,
			'gets=' + t.reqs(/^\/usage/).length + ' rows=' + rowsBefore);

		check('Usage: the bars render from the shipped code (all models + the pinned family top)',
			t.document.querySelectorAll('#usageBars .usage-bar').length === 2
			&& /claude-opus-5/.test(t.document.getElementById('usageBars').textContent),
			'bars=' + t.document.querySelectorAll('#usageBars .usage-bar').length);
	}

	// ── PROOF: Memories — delete a row → confirm → the row goes, page stays ─
	{
		const t = boot();
		clickNav(t, 'navMemories');
		await tick(t.window);
		const rows = t.document.querySelectorAll('#memoriesList .memory-row');
		check('Memories: the modal\'s rows render unchanged on the page',
			rows.length === 2 && /Prefers short answers/.test(rows[0].textContent),
			'rows=' + rows.length);

		click(t.window, rows[0].querySelector('.memory-btn-delete'));
		await tick(t.window);
		check('Memories: Delete asks first, then removes the row — and the PAGE stays open',
			t.state.confirms.length === 1 && /Prefers short answers/.test(t.state.confirms[0])
			&& t.reqs(/^\/memories\/m1$/).length === 1
			&& t.reqs(/^\/memories\/m1$/)[0].method === 'DELETE'
			&& t.document.querySelectorAll('#memoriesList .memory-row').length === 1
			&& t.api.open() === 'memories' && t.location.hash === '#memories' && !t.chatVisible(),
			'confirms=' + t.state.confirms.length + ' rows='
			+ t.document.querySelectorAll('#memoriesList .memory-row').length);
	}
	{
		const t = boot(null, { confirmAnswer: false });
		clickNav(t, 'navMemories');
		await tick(t.window);
		click(t.window, t.document.querySelector('#memoriesList .memory-btn-delete'));
		await tick(t.window);
		check('Memories: answering the confirm NO deletes nothing and leaves the row alone',
			t.state.confirms.length === 1 && t.reqs(/^\/memories\/m1$/).length === 0
			&& t.document.querySelectorAll('#memoriesList .memory-row').length === 2,
			'deletes=' + t.reqs(/^\/memories\/m1$/).length);
	}
	{
		// Escape while the confirm is up must close the CONFIRM, not the page. It
		// does, because the confirm is the PLATFORM's — window.confirm blocks the
		// thread, so no keydown reaches the document at all while it is open.
		// jsdom's confirm does not block, so driving a real Escape through it
		// would test something a browser cannot do; the property that makes the
		// guarantee true is checked instead — Memories renders no in-page confirm
		// layer of its own, and the shell's Escape rule has nothing to stand aside
		// for that Memories could leave behind.
		const t = boot();
		clickNav(t, 'navMemories');
		await tick(t.window);
		const inPageConfirm = t.document.querySelectorAll(
			'#memoriesPane .modal-overlay, #memoriesPane .chat-menu, #memoriesPane [role="dialog"]').length;
		click(t.window, t.document.querySelector('#memoriesList .memory-btn-delete'));
		await tick(t.window);
		check('Memories: the delete confirm is the PLATFORM\'s (window.confirm, which blocks the '
			+ 'thread) — Escape closes it first because no keydown can reach the page while it is up, '
			+ 'and the page renders no confirm layer of its own for Escape to trip over',
			inPageConfirm === 0
			&& t.state.confirms.length === 1
			&& t.document.querySelectorAll('#memoriesPane .modal-overlay, #memoriesPane .chat-menu').length === 0
			&& t.api.open() === 'memories',
			'inPageConfirm=' + inPageConfirm + ' confirms=' + t.state.confirms.length
			+ ' open=' + t.api.open());

		// And once it is answered, Escape is the page's again.
		key(t.window, t.document.body, 'Escape');
		await tick(t.window);
		check('Memories: after the confirm is answered, Escape returns to the chat as on any page',
			t.api.open() === null && t.chatVisible(), 'open=' + t.api.open());
	}
	{
		const t = boot();
		clickNav(t, 'navMemories');
		await tick(t.window);
		const status = t.document.getElementById('memoriesStatus');
		status.textContent = 'Could not delete that memory.';
		key(t.window, t.document.body, 'Escape');
		await tick(t.window);
		check('Memories: onLeave clears the status line — an outcome from before you left is not '
			+ 'what greets you on return',
			status.textContent === '', JSON.stringify(status.textContent));
	}

	// ── PROOF: Connections — the pin, the connect action, the confirms ─────
	{
		const t = boot();
		clickNav(t, 'navConnections');
		await tick(t.window);
		const rows = t.document.querySelectorAll('#integrationsList .integration-row');
		check('Connections: the modal\'s app rows render unchanged on the page',
			rows.length === 2 && /Gmail/.test(rows[0].textContent),
			'rows=' + rows.length);

		const picker = t.document.querySelector('.integration-primary');
		const radios = picker ? picker.querySelectorAll('input[type="radio"]') : [];
		check('Connections: the primary-for-chat picker still decorates the mail row, rendered from '
			+ 'STORED truth',
			!!picker && radios.length === 2 && radios[0].checked === true && radios[1].checked === false,
			'radios=' + radios.length);

		radios[1].checked = true;
		radios[1].dispatchEvent(new t.window.Event('change', { bubbles: true }));
		await tick(t.window);
		const put = t.reqs(/^\/primary-mailbox$/).filter(r => r.method === 'PUT');
		check('Connections: the primary-for-chat pin still TOGGLES — a PUT for the account picked, '
			+ 'then the list re-read from stored truth, with the page still open',
			put.length === 1 && /"connected_account_id":"a2"/.test(put[0].body)
			&& t.reqs(/^\/user\/integrations$/).length === 2
			&& t.api.open() === 'connections' && t.location.hash === '#connections',
			'puts=' + put.length + ' reloads=' + t.reqs(/^\/user\/integrations$/).length);
	}
	{
		const t = boot();
		clickNav(t, 'navConnections');
		await tick(t.window);
		const slackRow = t.document.querySelector('.integration-row[data-server-id="s-slack"]');
		const connectBtn = slackRow.querySelector('.integration-btn-primary');
		check('Connections: Connect is still a PER-ROW action on the app that is not connected '
			+ '(there is no page-level connect — which app is the row\'s choice)',
			!!connectBtn && connectBtn.textContent === 'Connect'
			&& !t.document.querySelector('.console-page-actions .integration-btn'),
			'btn=' + (connectBtn && connectBtn.textContent));

		click(t.window, connectBtn);
		await tick(t.window);
		const link = slackRow.querySelector('a.integration-btn-primary');
		check('Connections: the connect action still OPENS WHERE IT DID — the consent URL becomes a '
			+ 'link in that row\'s own action cell, target=_blank, and the page stays put',
			t.reqs(/authorize$/).length === 1
			&& !!link && link.href === 'https://consent.e.test/go'
			&& link.target === '_blank' && /noopener/.test(link.rel)
			&& link.closest('.integration-row') === slackRow
			&& t.api.open() === 'connections' && !t.chatVisible(),
			'posts=' + t.reqs(/authorize$/).length + ' href=' + (link && link.href));
	}
	{
		const t = boot();
		clickNav(t, 'navConnections');
		await tick(t.window);
		// C6c: Disconnect moved behind the â‹¯ menu. Driven through the menu now, and the
		// assertion is deliberately unchanged â€” the point of the slice is that only WHERE
		// you click it moved.
		const row = t.document.querySelector('.integration-row[data-server-id="s-gmail"]');
		const menuBtn = row && row.querySelector('.console-menu-btn');
		if (menuBtn) { click(t.window, menuBtn); await tick(t.window); }
		const dis = row && row.querySelector('.console-menu-drop .console-menu-item-danger');
		if (dis) { click(t.window, dis); }
		await tick(t.window);
		check('Connections: Disconnect still asks before it acts, with the same per-row confirm',
			!!menuBtn && !!dis
			&& t.state.confirms.length === 1 && /Gmail/.test(t.state.confirms[0])
			&& /ends your own access/.test(t.state.confirms[0])
			&& t.reqs(/disconnect$/).length === 1,
			'menuBtn=' + !!menuBtn + ' item=' + !!dis + ' confirms=' + t.state.confirms.length
			+ ' posts=' + t.reqs(/disconnect$/).length);
	}

	// ── PROOF: the OAuth focus re-check is armed on entry, DISARMED on leave ─
	{
		const t = boot();
		clickNav(t, 'navConnections');
		await tick(t.window);
		const afterEnter = t.reqs(/^\/user\/integrations$/).length;
		t.window.dispatchEvent(new t.window.Event('focus'));
		await tick(t.window);
		const afterFocus = t.reqs(/^\/user\/integrations$/).length;

		key(t.window, t.document.body, 'Escape');
		await tick(t.window);
		t.window.dispatchEvent(new t.window.Event('focus'));
		t.window.dispatchEvent(new t.window.Event('focus'));
		await tick(t.window);
		check('Connections: returning from the consent tab re-checks while the page is OPEN, and '
			+ 'onLeave DISARMS it — two focus events off the page fire no load (the C2 lesson)',
			afterEnter === 1 && afterFocus === 2
			&& t.reqs(/^\/user\/integrations$/).length === 2,
			'enter=' + afterEnter + ' focus=' + afterFocus
			+ ' afterLeave=' + t.reqs(/^\/user\/integrations$/).length);
	}

	// ── The Ask entry is the help layer, and no page carries help text ─────
	for (const p of PAGES) {
		const t = boot();
		const head = t.document.querySelector('#' + p.pane + ' .console-page-head');
		const descs = head.querySelectorAll('.console-page-desc');
		check(p.route + ': the header carries the title, EXACTLY ONE description line and no help '
			+ 'paragraph anywhere in the page',
			descs.length === 1 && head.querySelectorAll('p').length === 1
			&& t.document.querySelectorAll('#' + p.pane + ' p').length
				=== (p.route === 'usage' ? 2 : 1),   // Usage's second <p> is the resets DATA line
			'descs=' + descs.length + ' ps=' + t.document.querySelectorAll('#' + p.pane + ' p').length);
	}
	{
		const t = boot();
		const asks = [
			['memories', 'memoriesAskAssistant', 'Ask Sue how Memories work'],
			['usage', 'usageAskAssistant', 'Ask Sue how Usage works'],
			['connections', 'connectionsAskAssistant', 'Ask Sue how Connections work']
		];
		for (const [route, id, label] of asks) {
			const btn = t.document.getElementById(id);
			check(route + ': the Ask entry sits in the header actions slot and is named for the assistant',
				!!btn && btn.parentNode.classList.contains('console-page-actions')
				&& btn.textContent === label,
				'label=' + JSON.stringify(btn && btn.textContent));
		}
		for (const [route, id] of asks) {
			const t2 = boot();
			clickNav(t2, PAGES.find(p => p.route === route).nav);
			await tick(t2.window);
			click(t2.window, t2.document.getElementById(id));
			await tick(t2.window);
			check(route + ': the Ask entry starts a fresh chat with its question and LEAVES the page',
				t2.state.newChats === 1 && t2.state.clearedProject === 1
				&& t2.state.sent.length === 1 && t2.state.sent[0].length > 30
				&& t2.api.open() === null && t2.chatVisible(),
				'open=' + t2.api.open() + ' sent=' + t2.state.sent.length);
		}
	}

	// ── Page → page, and the chat entry is not disowned ────────────────────
	{
		const t = await bootWithPrevious();
		const base = t.window.history.length;
		clickNav(t, 'navMemories');
		await tick(t.window);
		clickNav(t, 'navUsage');
		await tick(t.window);
		clickNav(t, 'navConnections');
		await tick(t.window);
		check('page → page → page: only the one page shows, each entry current in turn, and the three '
			+ 'hops push exactly three entries',
			t.api.open() === 'connections'
			&& t.document.getElementById('memoriesPane').hidden === true
			&& t.document.getElementById('usagePane').hidden === true
			&& t.document.getElementById('connectionsPane').hidden === false
			&& !t.document.getElementById('navMemories').hasAttribute('aria-current')
			&& !t.document.getElementById('navUsage').hasAttribute('aria-current')
			&& t.document.getElementById('navConnections').getAttribute('aria-current') === 'page'
			&& t.window.history.length === base + 3,
			'open=' + t.api.open() + ' len=' + t.window.history.length + ' base=' + base);

		// Escape from a page reached page → page is NOT a pop (the entry below is
		// another page, not the chat) — it pushes the clean URL instead.
		key(t.window, t.document.body, 'Escape');
		await tick(t.window, 60);
		check('leaving a page reached page → page still lands in the chat with a clean URL',
			t.api.open() === null && t.chatVisible() && t.location.hash === ''
			&& t.document.getElementById('connectionsPane').hidden === true,
			'open=' + t.api.open() + ' hash=' + JSON.stringify(t.location.hash));
	}
	{
		// Leaving a page must run ITS onLeave, not the incoming page's.
		const t = boot();
		clickNav(t, 'navConnections');
		await tick(t.window);
		clickNav(t, 'navUsage');
		await tick(t.window);
		t.window.dispatchEvent(new t.window.Event('focus'));
		await tick(t.window);
		check('page → page runs the LEAVING page\'s onLeave: Connections\' focus re-check is disarmed '
			+ 'by moving to Usage, not only by returning to the chat',
			t.reqs(/^\/user\/integrations$/).length === 1,
			'loads=' + t.reqs(/^\/user\/integrations$/).length);
	}

	// ── Opening a conversation leaves whichever page is open ───────────────
	for (const p of PAGES) {
		const t = boot();
		clickNav(t, p.nav);
		await tick(t.window);
		click(t.window, t.document.getElementById('conv1'));
		await tick(t.window);
		check(p.route + ': opening a conversation from the list leaves the page for the chat',
			t.api.open() === null && t.chatVisible() && t.location.hash === '',
			'open=' + t.api.open());
	}

	// ── An error is an error, never an empty page ──────────────────────────
	{
		const t = boot(null, { fail: () => true });
		clickNav(t, 'navMemories');
		await tick(t.window);
		const st = t.document.getElementById('memoriesStatus');
		// C6d: the error kind reads through the kit's name now. The assertion is
		// otherwise unchanged — what the page SAYS on a failed load did not move.
		check('Memories: a failed load reports the error and does NOT claim the store is empty',
			/DEF said no/.test(st.textContent) && /console-status-error/.test(st.className)
			&& t.document.querySelectorAll('.memory-row').length === 0,
			'status=' + JSON.stringify(st.textContent));

		const t2 = boot(null, { fail: () => true });
		clickNav(t2, 'navUsage');
		await tick(t2.window);
		const st2 = t2.document.getElementById('usageStatus');
		check('Usage: a failed load reports the error and does NOT draw a zeroed week',
			/DEF said no/.test(st2.textContent) && /usage-status-error/.test(st2.className)
			&& t2.document.getElementById('usageBars').children.length === 0
			&& t2.document.getElementById('usageResets').textContent === '',
			'status=' + JSON.stringify(st2.textContent));
	}

	// ── A modifier click is the browser's, on the new entries too ──────────
	{
		const mods = [['Ctrl', { ctrlKey: true }], ['Cmd', { metaKey: true }],
			['Shift', { shiftKey: true }], ['middle-click', { button: 1 }]];
		for (const [name, init] of mods) {
			const t = boot();
			const evs = PAGES.map(p => clickNav(t, p.nav, init));
			await tick(t.window);
			// jsdom has no tabs, so it follows the un-prevented hash link in this
			// same document; a browser opens a new tab. What the console owes
			// either one is identical: keep its hands off the event.
			check(name + ' on Memories / Usage / Connections is NOT prevented — open-in-new-tab, '
				+ 'new-window and the context menu stay the browser\'s',
				evs.every(e => e.defaultPrevented === false),
				'prevented=[' + evs.map(e => e.defaultPrevented) + ']');
		}
		// The shipped entry's own icon — the click a finger actually lands on.
		const t = boot();
		const icon = t.document.querySelector('#navMemories svg path');
		const ev = click(t.window, icon);
		await tick(t.window);
		check('a plain click on the icon INSIDE an entry is still delegated to the entry (SVG child)',
			!!icon && ev.defaultPrevented === true && t.api.open() === 'memories',
			'icon=' + !!icon + ' open=' + t.api.open());
	}

	// ── An unknown route is not an error ───────────────────────────────────
	{
		const t = boot('https://e.test/staff-ai/#memoriez');
		t.api.applyRoute();
		await tick(t.window);
		check('a near-miss route (#memoriez) lands in the chat rather than erroring, and loads nothing',
			t.api.open() === null && t.chatVisible() && t.requests.length === 0,
			'open=' + t.api.open() + ' requests=' + t.requests.length);
	}

	console.log(results.join('\n'));
	console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
	process.exit(fail ? 1 : 0);
})();
