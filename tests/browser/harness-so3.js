/*
 * The `once` cadence on the Scheduled page — behavioural harness (S-O3).
 * Runs the SHIPPED initScheduled block (extracted by marker) inside jsdom
 * against the SHIPPED #scheduledPane section and #scheduleModal markup sliced
 * out of templates/staff-ai-shell.php. Nothing here is a copy of the code or
 * the markup under test.
 *
 * The clock is pinned WEST of Greenwich on purpose: a one-off date is a plain
 * YYYY-MM-DD, and handing that to Date(string) reads it as UTC midnight and
 * renders the day BEFORE for exactly these readers. Under TZ=UTC or Brisbane
 * that bug is invisible.
 *
 * 12 checks.
 */
process.env.TZ = 'America/New_York';
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const SCHEDULED = extract.scheduled();

const HTML = `<!doctype html><html><body>
${extract.templatePage('scheduledPane')}
${extract.templateModal('scheduleModal')}
</body></html>`;

// The date a reader in this timezone should see for 2026-12-24 — the local day,
// not the UTC instant.
const EXPECTED_DATE = new Date(2026, 11, 24).toLocaleDateString();

const ONCE = { id: 't-once', name: 'Christmas eve note', instruction: 'Write it', enabled: true,
	cadence: 'once', send_date_local: '2026-12-24', send_weekday: 0, send_hour_local: 9,
	send_minute_local: 30, timezone: 'Australia/Brisbane', destinations: ['email'], model: '' };
const RAN = Object.assign({}, ONCE, { id: 't-ran', name: 'Went off already', cadence: 'manual' });
const PLAIN = Object.assign({}, ONCE, { id: 't-plain', name: 'By hand', cadence: 'manual',
	send_date_local: null });

function boot(tasks) {
	const dom = new JSDOM(HTML, { url: 'https://e.test/staff-ai/#scheduled', pretendToBeVisual: true });
	const window = dom.window, document = window.document;
	const requests = [];
	const consolePages = [];
	async function apiRequest(url, init) {
		const method = (init && init.method) || 'GET';
		requests.push({ url: url, method: method, body: init && init.body });
		if (/^\/tasks$/.test(url)) return { tasks: tasks || [] };
		if (/^\/triage-schedules/.test(url)) return { schedules: [] };
		if (/^\/projects/.test(url)) return { projects: [] };
		if (/^\/user\/integrations$/.test(url)) return { apps: [] };
		if (/^\/mail-connections/.test(url)) return { connections: [] };
		return { task: {} };
	}
	const outer = [window, document, consolePages,
		function (key, def) { return def; },          // t
		apiRequest, '/wp-json/def/v1/staff-ai',       // apiBase
		'Sue',                                        // assistantName
		function (fn) { fn(); },                      // onAssistantName
		function () {},                               // clearActiveProject
		function () {},                               // resetToNewChat
		document.createElement('textarea'),           // composerInput
		function () {},                               // updateSendButton
		function () {}];                              // sendMessage
	const names = ['window', 'document', 'consolePages', 't', 'apiRequest', 'apiBase',
		'assistantName', 'onAssistantName', 'clearActiveProject', 'resetToNewChat',
		'composerInput', 'updateSendButton', 'sendMessage'];
	new window.Function(...names, SCHEDULED)(...outer);
	const $ = (id) => document.getElementById(id);
	return {
		window, document, requests, $,
		load: () => consolePages[0].onEnter(),
		cards: () => Array.prototype.slice.call(document.querySelectorAll('.task-card')),
		badge: (i) => document.querySelectorAll('.task-card')[i].querySelector('.task-badge').textContent,
		shown: (id) => $(id).style.display !== 'none',
		click: (el) => el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true })),
		change: (el) => el.dispatchEvent(new window.Event('change', { bubbles: true })),
		sentTask: function () {
			const put = this.requests.filter(r => r.method === 'PUT' || r.method === 'POST');
			return put.length ? JSON.parse(put[put.length - 1].body) : null;
		}
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

(async function () {

	// ── The creator offers Once, and the form follows it ───────────────────
	{
		const t = boot([]);
		const opts = Array.prototype.map.call(t.$('taskCadence').options, o => o.value);
		check('the SHIPPED cadence select offers once beside the five it had',
			opts.join(',') === 'manual,hourly,daily,weekdays,weekly,once', opts.join(','));

		t.click(t.$('taskCreateBtn'));
		await tick(t.window);
		check('a NEW task starts on daily, with no date field in sight',
			t.$('taskCadence').value === 'daily' && !t.shown('taskDateRow'));

		t.$('taskCadence').value = 'once';
		t.change(t.$('taskCadence'));
		check('choosing Once shows the date beside the time and zone, and hides the weekly day',
			t.shown('taskDateRow') && t.shown('taskTimeRow') && t.shown('taskTzRow')
			&& !t.shown('taskWeekdayRow') && t.$('taskDate').readOnly === false,
			'date=' + t.shown('taskDateRow') + ' weekday=' + t.shown('taskWeekdayRow'));

		t.$('taskCadence').value = 'weekly';
		t.change(t.$('taskCadence'));
		check('switching back to a repeat takes the date field away again',
			!t.shown('taskDateRow') && t.shown('taskWeekdayRow'));

		// Create one: the picked date rides the body under the cadence.
		t.$('taskCadence').value = 'once';
		t.change(t.$('taskCadence'));
		t.$('taskName').value = 'Christmas eve note';
		t.$('taskInstruction').value = 'Write it';
		t.$('taskDate').value = '2026-12-24';
		t.click(t.$('scheduleSave'));
		await tick(t.window);
		const made = t.sentTask();
		check('saving sends cadence once with the date the user picked',
			!!made && made.cadence === 'once' && made.send_date_local === '2026-12-24',
			JSON.stringify(made && { c: made.cadence, d: made.send_date_local }));
	}

	// ── The card says which of the two it is ───────────────────────────────
	{
		const t = boot([ONCE, RAN, PLAIN]);
		await t.load();
		await tick(t.window);
		check('three cards render', t.cards().length === 3, 'cards=' + t.cards().length);
		check('a PENDING once reads "Once on <date> at <time> (<zone>)", in the reader\'s own date format',
			t.badge(0).indexOf('Once on ' + EXPECTED_DATE + ' at ') === 0
			&& t.badge(0).indexOf('(Australia/Brisbane)') !== -1,
			t.badge(0) + ' expected date ' + EXPECTED_DATE);
		check('a FIRED once — manual, still carrying its date — reads "Ran once at <date> <time>"',
			t.badge(1).indexOf('Ran once at ' + EXPECTED_DATE + ' ') === 0, t.badge(1));
		check('a manual task with NO date reads exactly as it always did',
			t.badge(2) === 'Runs when you press Run now', t.badge(2));
	}

	// ── Editing a fired once ───────────────────────────────────────────────
	{
		const t = boot([RAN]);
		await t.load();
		await tick(t.window);
		t.click(t.cards()[0].querySelector('.task-card-icon[title="Edit"]'));
		await tick(t.window);
		check('the editor shows the date it ran, read-only, under a label that says so',
			t.shown('taskDateRow') && t.$('taskDate').value === '2026-12-24'
			&& t.$('taskDate').readOnly === true
			&& t.$('taskDateLabel').textContent === 'Ran once at',
			'value=' + t.$('taskDate').value + ' ro=' + t.$('taskDate').readOnly
			+ ' label=' + t.$('taskDateLabel').textContent);

		t.click(t.$('scheduleSave'));
		await tick(t.window);
		check('saving it untouched sends the date back, so nothing can wipe the stamp',
			t.sentTask().send_date_local === '2026-12-24', JSON.stringify(t.sentTask()));
	}
	{
		const t = boot([RAN]);
		await t.load();
		await tick(t.window);
		t.click(t.cards()[0].querySelector('.task-card-icon[title="Edit"]'));
		await tick(t.window);
		t.$('taskCadence').value = 'weekly';
		t.change(t.$('taskCadence'));
		t.click(t.$('scheduleSave'));
		await tick(t.window);
		const sent = t.sentTask();
		check('switching it to a repeat sends NO date — which is how DEF is told to clear it',
			sent.cadence === 'weekly' && !('send_date_local' in sent), JSON.stringify(sent));
	}

	console.log('S-O3 — the once cadence on the Scheduled page');
	results.forEach(line => console.log(line));
	console.log('  ' + pass + ' passed, ' + fail + ' failed');
	process.exit(fail ? 1 : 0);
})();
