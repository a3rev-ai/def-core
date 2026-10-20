/*
 * Skip the next run, and pause until a date, on the Scheduled page (W9) —
 * behavioural harness. Runs the SHIPPED initScheduled block (extracted by
 * marker) inside jsdom against the SHIPPED #scheduledPane section and
 * #scheduleModal markup sliced out of templates/staff-ai-shell.php.
 *
 * The clock is pinned WEST of Greenwich, as harness-so3.js does: the pause date
 * is a plain YYYY-MM-DD, and Date(string) would render the day before here.
 *
 * 13 checks.
 */
process.env.TZ = 'America/New_York';
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const SCHEDULED = extract.scheduled();
const MENU = extract.consoleMenu();

const HTML = `<!doctype html><html><body>
${extract.templatePage('scheduledPane')}
${extract.templateModal('scheduleModal')}
</body></html>`;

const EXPECTED_DATE = new Date(2026, 10, 3).toLocaleDateString();

const DAILY = { id: 't-daily', name: 'Morning brief', instruction: 'Write it', enabled: true,
	cadence: 'daily', send_date_local: null, send_weekday: 0, send_hour_local: 7,
	send_minute_local: 30, timezone: 'Australia/Brisbane', destinations: ['email', 'slack'],
	model: '', project_id: 'p-1', paused_until: null, skip_next: false, last_run: null };
const HELD = Object.assign({}, DAILY, { id: 't-held', name: 'Friday wrap-up', cadence: 'weekly',
	send_weekday: 4, paused_until: '2026-11-03', skip_next: true });
const OFF = Object.assign({}, DAILY, { id: 't-off', name: 'Switched off', enabled: false, skip_next: true });
const MANUAL = Object.assign({}, DAILY, { id: 't-manual', name: 'By hand', cadence: 'manual' });
const ONCE = Object.assign({}, DAILY, { id: 't-once', name: 'One-off', cadence: 'once',
	send_date_local: '2026-12-24' });

function boot(tasks, opts) {
	opts = opts || {};
	const dom = new JSDOM(HTML, { url: 'https://e.test/staff-ai/#scheduled', pretendToBeVisual: true });
	const window = dom.window, document = window.document;
	const requests = [];
	const consolePages = [];
	const state = { answer: null, prompts: 0 };
	window.prompt = function () { state.prompts++; return state.answer; };
	async function apiRequest(url, init) {
		const method = (init && init.method) || 'GET';
		requests.push({ url: url, method: method, body: init && init.body });
		if (method === 'PUT' && opts.refuse) throw new Error(opts.refuse);
		if (/^\/tasks$/.test(url)) return { tasks: tasks };
		if (/^\/triage-schedules/.test(url)) return { schedules: [] };
		if (/^\/projects/.test(url)) return { projects: [] };
		if (/^\/user\/integrations$/.test(url)) return { apps: [] };
		if (/^\/mail-connections/.test(url)) return { connections: [] };
		return { task: {} };
	}
	const outer = [window, document, consolePages,
		function (key, def) { return def; },          // t
		apiRequest, '/wp-json/def/v1/staff-ai',       // apiBase
		'you@example.test',                           // userEmail
		'Sue',                                        // assistantName
		function (fn) { fn(); },                      // onAssistantName
		function () {},                               // clearActiveProject
		function () {},                               // resetToNewChat
		document.createElement('textarea'),           // composerInput
		function () {},                               // updateSendButton
		function () {}];                              // sendMessage
	const names = ['window', 'document', 'consolePages', 't', 'apiRequest', 'apiBase',
		'userEmail', 'assistantName', 'onAssistantName', 'clearActiveProject', 'resetToNewChat',
		'composerInput', 'updateSendButton', 'sendMessage'];
	extract.pushAskEntry(window, names, outer);
	new window.Function(...names, MENU + '\n' + SCHEDULED)(...outer);
	const click = (el) => el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
	const card = (name) => Array.prototype.find.call(
		document.querySelectorAll('#taskCardGrid .console-card'), (c) => c.textContent.includes(name));
	return {
		window, document, requests, state,
		load: () => consolePages[0].onEnter(),
		// The schedule badges; the project badge is not this harness's.
		badges: (name) => Array.prototype.map.call(
			card(name).querySelectorAll('.task-badge:not(.task-badge-project)'), (b) => b.textContent),
		// The ⋯ menu as its reader meets it: labels in order, — for the divider, ! for danger.
		menu: function (name) {
			click(card(name).querySelector('.console-menu-btn'));
			const drop = document.querySelector('.console-menu-drop');
			const labels = Array.prototype.map.call(drop.children, (el) => el.classList.contains('console-menu-sep')
				? '—' : el.textContent + (el.classList.contains('console-menu-item-danger') ? '!' : ''));
			click(document.body);
			return labels.join('|');
		},
		pick: function (name, label) {
			click(card(name).querySelector('.console-menu-btn'));
			const item = Array.prototype.find.call(document.querySelectorAll('.console-menu-drop .console-menu-item'),
				(b) => b.textContent === label);
			click(item || document.body);   // a missing item is a failed check, not a crash
		},
		puts: () => requests.filter((r) => r.method === 'PUT'),
		status: () => document.getElementById('scheduledPaneStatus').textContent
	};
}

const tick = (w, n) => new Promise(r => w.setTimeout(r, n === undefined ? 30 : n));

let pass = 0, fail = 0, n = 0;
const results = [];
function check(label, ok, detail) {
	n++;
	if (ok) { pass++; results.push('  ok   ' + n + '. ' + label); }
	else { fail++; results.push('  FAIL ' + n + '. ' + label + (detail ? '  [' + detail + ']' : '')); }
}

// The full task the card's row says, in saveTask's shape — what every PUT must carry.
function fullTask(row) {
	return { name: row.name, instruction: row.instruction, enabled: row.enabled, cadence: row.cadence,
		model: row.model, send_weekday: row.send_weekday, send_hour_local: row.send_hour_local,
		send_minute_local: row.send_minute_local, timezone: row.timezone,
		destinations: row.destinations, project_id: row.project_id };
}

(async function () {

	// ── The card says what is set ──────────────────────────────────────────
	{
		const t = boot([DAILY, HELD, OFF]);
		await t.load();
		await tick(t.window);
		check('a task with neither set shows its cadence badge and nothing else',
			t.badges('Morning brief').length === 1 && t.badges('Morning brief')[0].indexOf('Every day at ~') === 0,
			JSON.stringify(t.badges('Morning brief')));
		check('a paused task reads "Paused — runs again from <date>", in the reader\'s own date format',
			t.badges('Friday wrap-up')[0] === 'Paused — runs again from ' + EXPECTED_DATE,
			t.badges('Friday wrap-up')[0] + ' expected ' + EXPECTED_DATE);
		check('a skip shows "Skips its next run" beside it',
			t.badges('Friday wrap-up')[1] === 'Skips its next run', JSON.stringify(t.badges('Friday wrap-up')));
		check('a switched-off task still says "Not scheduled", and a skip it carries is still shown as waiting',
			JSON.stringify(t.badges('Switched off')) === JSON.stringify(['Not scheduled', 'Skips its next run']),
			JSON.stringify(t.badges('Switched off')));
	}

	// ── Which items the ⋯ menu offers, and when ────────────────────────────
	{
		const t = boot([DAILY, HELD, OFF, MANUAL, ONCE]);
		await t.load();
		await tick(t.window);
		check('an enabled repeating task offers Skip the next run and Pause until…',
			t.menu('Morning brief') === 'Edit|Skip the next run|Pause until…|—|Remove!', t.menu('Morning brief'));
		check("once set, they read Don't skip the next run and Resume now",
			t.menu('Friday wrap-up') === "Edit|Don't skip the next run|Resume now|—|Remove!", t.menu('Friday wrap-up'));
		check('a switched-off, a manual and a once task offer neither',
			[t.menu('Switched off'), t.menu('By hand'), t.menu('One-off')].every((m) => m === 'Edit|—|Remove!'),
			[t.menu('Switched off'), t.menu('By hand'), t.menu('One-off')].join(' / '));
	}

	// ── Each item PUTs the full task plus its one field, then reloads ──────
	{
		const t = boot([DAILY]);
		await t.load();
		await tick(t.window);
		t.pick('Morning brief', 'Skip the next run');
		await tick(t.window);
		const put = t.puts()[0];
		const body = put && JSON.parse(put.body);
		const want = Object.assign(fullTask(DAILY), { skip_next: true });
		check('Skip the next run PUTs the whole task from the card\'s row with skip_next true, and nothing about a pause',
			!!put && put.url === '/tasks/t-daily' && JSON.stringify(body) === JSON.stringify(want),
			JSON.stringify(body));
		const after = t.requests.slice(t.requests.indexOf(put) + 1);
		check('and the page reloads its tasks afterwards',
			!!put && after.some((r) => r.method === 'GET' && r.url === '/tasks'), JSON.stringify(after.map((r) => r.url)));
	}
	{
		const t = boot([HELD]);
		await t.load();
		await tick(t.window);
		t.pick('Friday wrap-up', "Don't skip the next run");
		await tick(t.window);
		t.pick('Friday wrap-up', 'Resume now');
		await tick(t.window);
		const bodies = t.puts().map((p) => JSON.parse(p.body));
		check("Don't skip sends skip_next false; Resume now sends paused_until \"\" — each its one field only",
			bodies.length === 2 && bodies[0].skip_next === false && !('paused_until' in bodies[0])
			&& bodies[1].paused_until === '' && !('skip_next' in bodies[1]),
			JSON.stringify(bodies));
	}
	{
		const t = boot([DAILY]);
		await t.load();
		await tick(t.window);
		t.state.answer = null;
		t.pick('Morning brief', 'Pause until…');
		await tick(t.window);
		t.state.answer = '   ';
		t.pick('Morning brief', 'Pause until…');
		await tick(t.window);
		check('a cancelled or empty prompt sends nothing',
			t.state.prompts === 2 && t.puts().length === 0, 'prompts=' + t.state.prompts + ' puts=' + t.puts().length);
		t.state.answer = ' 2026-11-03 ';
		t.pick('Morning brief', 'Pause until…');
		await tick(t.window);
		const body = t.puts().length ? JSON.parse(t.puts()[0].body) : null;
		check('an answered prompt PUTs the whole task with the date it was given',
			JSON.stringify(body) === JSON.stringify(Object.assign(fullTask(DAILY), { paused_until: '2026-11-03' })),
			JSON.stringify(body));
	}

	// ── A refusal is DEF's (or the door's) own sentence ─────────────────────
	{
		const refusal = 'paused_until must be after today';
		const t = boot([DAILY], { refuse: refusal });
		await t.load();
		await tick(t.window);
		t.state.answer = '2020-01-01';
		t.pick('Morning brief', 'Pause until…');
		await tick(t.window);
		check('a refused date shows the refusal on the page\'s status line',
			t.status() === refusal, t.status());
	}

	results.forEach((r) => console.log(r));
	console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + n + ') ===');
	process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
