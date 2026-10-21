/*
 * Notes to Staff AI on the Scheduled page (W21) — behavioural harness. Runs the SHIPPED
 * initScheduled block (extracted by marker) inside jsdom against the SHIPPED
 * #scheduledPane section and #scheduleModal markup sliced out of
 * templates/staff-ai-shell.php, as harness-task-pause.js does.
 *
 * The third Task type is offered only once Slack is connected and the user has none
 * yet; its form is on/off and Hourly | Daily, with the hint naming the word a note
 * starts with; Save sends kind slack_notes and no name or instruction (DEF sets
 * both); and its card never says "Delivering to" or "Sent to" — the answers are in Slack.
 *
 * 9 checks.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const SCHEDULED = extract.scheduled();
const MENU = extract.consoleMenu();

const HTML = `<!doctype html><html><body>
${extract.templatePage('scheduledPane')}
${extract.templateModal('scheduleModal')}
</body></html>`;

const SLACK = [{ category: 'slack', has_grant: true }];
const NOTES = { id: 't-notes', kind: 'slack_notes', name: 'Notes to Staff AI — Slack',
	instruction: "Answers each message you start with my name in your own Slack DM, in that message's thread.",
	enabled: true, cadence: 'daily', send_date_local: null, send_weekday: 0, send_hour_local: 9,
	send_minute_local: 15, timezone: 'Australia/Brisbane', destinations: ['email'], model: '',
	project_id: null, paused_until: null, skip_next: false,
	last_run: { status: 'succeeded', at: '2026-10-21T01:00:00Z', delivered: false } };

function boot(tasks, apps) {
	const dom = new JSDOM(HTML, { url: 'https://e.test/staff-ai/#scheduled', pretendToBeVisual: true });
	const window = dom.window, document = window.document;
	const requests = [];
	const consolePages = [];
	async function apiRequest(url, init) {
		const method = (init && init.method) || 'GET';
		requests.push({ url: url, method: method, body: init && init.body && JSON.parse(init.body) });
		if (/^\/tasks$/.test(url) && method === 'GET') return { tasks: tasks, address_as: 'Sue' };
		if (/^\/triage-schedules/.test(url)) return { schedules: [] };
		if (/^\/projects/.test(url)) return { projects: [] };
		if (/^\/user\/integrations$/.test(url)) return { apps: apps };
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
	const $ = (id) => document.getElementById(id);
	const click = (el) => el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
	const card = (name) => Array.prototype.find.call(
		document.querySelectorAll('#taskCardGrid .console-card'), (c) => c.textContent.includes(name));
	return {
		window, document, requests, $, click, card,
		load: () => consolePages[0].onEnter(),
		types: () => Array.prototype.map.call($('taskType').options, (o) => o.value),
		pickType: function (value) {
			$('taskType').value = value;
			$('taskType').dispatchEvent(new window.Event('change', { bubbles: true }));
		},
		edit: function (name) {
			click(card(name).querySelector('.console-menu-btn'));
			click(Array.prototype.find.call(document.querySelectorAll('.console-menu-drop .console-menu-item'),
				(b) => b.textContent === 'Edit'));
		},
		sent: (method) => requests.filter((r) => r.method === method && /^\/tasks/.test(r.url))
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

	// ── Offered only once Slack is connected, and only once ────────────────
	{
		const t = boot([], []);
		await t.load();
		await tick(t.window);
		t.click(t.$('taskCreateBtn'));
		check('without a Slack connection the Task type list has no Notes to Staff AI',
			JSON.stringify(t.types()) === '["freetext","email_triage"]', JSON.stringify(t.types()));
	}
	{
		const t = boot([NOTES], SLACK);
		await t.load();
		await tick(t.window);
		t.click(t.$('taskCreateBtn'));
		check('a user who already has one is not offered a second',
			t.types().indexOf('slack_notes') === -1, JSON.stringify(t.types()));
	}

	// ── The form, and what Save sends ──────────────────────────────────────
	{
		const t = boot([], SLACK);
		await t.load();
		await tick(t.window);
		t.click(t.$('taskCreateBtn'));
		check('with Slack connected it is offered as the third type',
			t.types()[2] === 'slack_notes' && /Notes to Staff AI - answer what I leave myself in Slack/
				.test(t.$('taskType').options[2].textContent), JSON.stringify(t.types()));
		t.pickType('slack_notes');
		check('picking it shows its own form, Hourly by default, the free-text form hidden',
			t.$('notesFormView').style.display === '' && t.$('taskFormView').style.display === 'none'
				&& t.$('notesCadence').value === 'hourly' && t.$('notesEnabled').checked);
		check('the hint names the word a note starts with',
			t.$('notesHint').textContent.indexOf('Start a message to yourself in Slack with "Sue,"') === 0,
			t.$('notesHint').textContent);
		t.click(t.$('scheduleSave'));
		await tick(t.window);
		const body = (t.sent('POST')[0] || {}).body || {};
		check('Save POSTs kind slack_notes, hourly, email only, and no name or instruction',
			body.kind === 'slack_notes' && body.cadence === 'hourly'
				&& JSON.stringify(body.destinations) === '["email"]'
				&& !('name' in body) && !('instruction' in body), JSON.stringify(body));
	}

	// ── The card, and editing it ───────────────────────────────────────────
	{
		const t = boot([NOTES], SLACK);
		await t.load();
		await tick(t.window);
		const text = t.card('Notes to Staff AI — Slack').textContent;
		check('its card never says "Delivering to" or "Sent to" — the answers are in Slack',
			text.indexOf('Delivering to') === -1 && text.indexOf('Sent to') === -1, text);
		t.edit('Notes to Staff AI — Slack');
		check('Edit opens the Notes form with its stored frequency',
			t.$('notesFormView').style.display === '' && t.$('notesCadence').value === 'daily');
		t.$('notesCadence').value = 'hourly';
		t.click(t.$('scheduleSave'));
		await tick(t.window);
		const put = t.sent('PUT')[0] || {};
		check('Save PUTs that task with its new frequency, keeping its time and zone',
			put.url === '/tasks/t-notes' && put.body.cadence === 'hourly'
				&& put.body.send_hour_local === 9 && put.body.send_minute_local === 15
				&& put.body.timezone === 'Australia/Brisbane', JSON.stringify(put));
	}

	results.forEach((r) => console.log(r));
	console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + n + ') ===');
	process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
