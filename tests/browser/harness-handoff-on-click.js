/*
 * The hand-off opens on the person's click (v9.2.0, W75 Joe Goals G0).
 *
 * An escalation_offer used to open the hand-off form at once, so the reply that
 * explained it was hidden behind the form. Now the reply stays in view with ONE
 * button under it, and the form opens only on that button's click.
 *
 * Joe: the SHIPPED Customer Chat module, whole, in jsdom (as harness-direct-stream.js
 * runs it), over a streamed turn and a non-streamed one. The button reads the hand-off
 * form's own title, t('escalate'), which the Form title setting overrides.
 * Sam: the SHIPPED Setup Assistant drawer, whole, in jsdom, over a streamed turn and
 * through processToolOutputs. The button reads "Talk to a person".
 *
 * 19 checks. Bite: on 9.1.5 every check fails but the four guards (no offer, no button;
 * a restored thread re-opens nothing — 7, 11, 13, 19) — the form opened at once, no button.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + String(pass + fail).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(pass + fail).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}
const sleep = ms => new Promise(r => setTimeout(r, ms));
const read = f => fs.readFileSync(path.join(extract.REPO, f), 'utf8');

const REPLY = 'A partner can set this up with you. Use the button below and they will be in touch.';
const OFFER = { type: 'escalation_offer', reason: 'Wants a partner to set up the store' };

// ── Joe ──────────────────────────────────────────────────────────────────────
const CC_MODULE = extract.customerChatSource();
const CC_VENDOR = ['assets/js/def-persona.js', 'assets/js/vendor/marked.min.js', 'assets/js/vendor/purify.min.js',
	'assets/js/def-core-product-cards.js'].map(read);
const CC = {
	apiBaseUrl: 'https://api.example.test',
	chatStreamUrl: 'https://shop.test/wp-json/def-core/v1/chat/stream',
	wpRestUrl: 'https://shop.test/wp-json/def-core/v1/',
	wpRestRoot: 'https://shop.test/wp-json/',
	restUrl: 'https://shop.test/wp-json/def-core/v1/context-token',
	nonce: 'n0nce', wpRestNonce: 'n0nce', assistantName: 'Joe', isLoggedIn: false,
};

async function bootJoe(opts) {
	const dom = new JSDOM('<!doctype html><html><body><div id="host"></div></body></html>',
		{ url: 'https://shop.test/', runScripts: 'outside-only', pretendToBeVisual: true });
	const w = dom.window;
	extract.stubMatchMedia(w);
	const realST = w.setTimeout.bind(w);
	w.setTimeout = (fn, ms) => realST(fn, (ms || 0) >= 1000 ? ms : Math.min(ms || 0, 1));
	for (const src of CC_VENDOR) w.eval(src);
	// Joe streams when ReadableStream exists and falls back to the sync path when not.
	w.ReadableStream = opts.sync ? undefined : function () {};
	const done = { thread_id: 'thr-1', choices: [{ message: { content: REPLY } }], tool_outputs: opts.outputs };
	const res = (body, status) => Promise.resolve({ ok: status < 300, status, headers: { get: () => null },
		json: () => Promise.resolve(body), text: () => Promise.resolve(JSON.stringify(body)) });
	w.fetch = function (url) {
		if (String(url) !== CC.chatStreamUrl) return res({}, 200);
		if (opts.sync) return res(done, 200);
		const enc = new w.TextEncoder();
		const chunks = [{ type: 'text_delta', text: REPLY }, Object.assign({ type: 'done' }, done)]
			.map(e => enc.encode('data: ' + JSON.stringify(e) + '\n\n'));
		return Promise.resolve({ ok: true, status: 200, body: { getReader: () => ({
			read: () => Promise.resolve(chunks.length ? { done: false, value: chunks.shift() } : { done: true }) }) } });
	};
	for (const k of Object.keys(opts.storage || {})) w.localStorage.setItem(k, opts.storage[k]);
	const root = w.document.getElementById('host').attachShadow({ mode: 'open' });
	root.innerHTML = '<div class="def-cc-panel"><button class="def-cc-panel-close" aria-label="Close chat"></button></div>';
	w.eval(CC_MODULE);
	w.DEFCustomerChat.init(root, Object.assign({}, CC, opts.strings ? { strings: opts.strings } : {}));
	await sleep(40);
	const input = root.querySelector('.def-cc-composer-input');
	if (!opts.storage) input.value = 'Can someone set up my store?';
	if (!opts.storage) input.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
	for (let i = 0; i < 300 && (i < 2 || input.disabled); i++) await sleep(10);
	await sleep(30);
	const bubbles = root.querySelectorAll('.def-cc-message--assistant');
	return {
		root,
		formOpen: () => root.querySelector('.def-cc-escalation-overlay').classList.contains('def-cc-escalation-overlay--open'),
		buttons: () => Array.from(root.querySelectorAll('.def-cc-handoff-btn')),
		reply: bubbles.length ? bubbles[bubbles.length - 1] : null,
	};
}

// ── Sam ──────────────────────────────────────────────────────────────────────
// The drawer constructs itself and keeps no handle; the harness names the instance
// so it can reach processToolOutputs, which has no caller in the drawer itself.
const SAM_SRC = read('assets/js/setup-assistant-drawer.js');
if (!/new SetupAssistantDrawer\(\);/.test(SAM_SRC)) throw new Error('setup assistant: constructor call not found');
const SAM = SAM_SRC.replace(/new SetupAssistantDrawer\(\);/g, 'window.__sam = new SetupAssistantDrawer();');

async function bootSam(opts) {
	opts = opts || {};
	const dom = new JSDOM('<!doctype html><html><body>' +
		'<button id="def-setup-assistant-trigger"></button>' +
		'<div id="def-setup-assistant-drawer"><div class="def-sa-backdrop"></div><div class="def-sa-panel">' +
		'<button class="def-sa-clear"></button><button class="def-sa-close" aria-label="Close Setup Assistant"></button>' +
		'<div class="def-sa-messages"></div><textarea class="def-sa-input"></textarea><button class="def-sa-send"></button>' +
		'</div></div></body></html>', { url: 'https://site.test/wp-admin/', runScripts: 'outside-only', pretendToBeVisual: true });
	const w = dom.window;
	extract.stubMatchMedia(w);
	w.eval(read('assets/js/def-persona.js'));
	w.defSetupAssistant = Object.assign({ restUrl: 'https://site.test/wp-json/def-core/v1/', nonce: 'n',
		chatStreamUrl: 'https://site.test/wp-json/a3-ai/v1/setup-assistant/chat/stream' }, opts.config || {});
	w.ReadableStream = function () {};
	w.TextDecoder = TextDecoder;
	w.fetch = function (url) {
		if (String(url) !== w.defSetupAssistant.chatStreamUrl) {
			return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) });
		}
		const enc = new TextEncoder();
		const chunks = [{ type: 'text_delta', text: REPLY },
			{ type: 'done', reply: REPLY, thread_id: 'th-1', tool_outputs: opts.outputs || [] }]
			.map(e => enc.encode('data: ' + JSON.stringify(e) + '\n\n'));
		return Promise.resolve({ ok: true, status: 200, body: { getReader: () => ({
			read: () => Promise.resolve(chunks.length ? { done: false, value: chunks.shift() } : { done: true }) }) } });
	};
	w.eval(SAM);
	await sleep(20);   // the drawer constructs on DOMContentLoaded while jsdom is still loading
	const sam = w.__sam;
	const messages = w.document.querySelector('.def-sa-messages');
	return {
		sam, w, messages,
		send: async () => { sam.sendMessage('Can someone help me connect?'); await sleep(400); },
		cards: () => messages.querySelectorAll('.def-sa-escalation-card'),
		buttons: () => Array.from(messages.querySelectorAll('.def-sa-handoff-btn')),
	};
}

(async function () {
	let j;

	// ── Joe: streamed and non-streamed ──────────────────────────────────────
	for (const sync of [false, true]) {
		const path = sync ? 'non-streaming' : 'streaming';
		j = await bootJoe({ sync, outputs: [OFFER] });
		const btns = j.buttons();
		check('Joe (' + path + '): an escalation_offer leaves the form closed and the reply in full',
			!j.formOpen() && !!j.reply && j.reply.textContent.trim() === REPLY,
			JSON.stringify({ open: j.formOpen(), reply: j.reply && j.reply.textContent.trim() }));
		check('  … with one button under the reply, reading the form\'s default title',
			btns.length === 1 && btns[0].textContent === 'Request Human Support' &&
			!!(j.reply.compareDocumentPosition(btns[0]) & 4 /* FOLLOWING */),
			JSON.stringify(btns.map(b => b.textContent)));
		const closed = !j.formOpen();
		if (btns[0]) btns[0].click();
		check('  … and clicking it opens the form, closed until then', closed && j.formOpen());
	}

	check('Joe: the reply with no offer gets no button (guard)',
		(await bootJoe({ outputs: [] })).buttons().length === 0);

	j = await bootJoe({ outputs: [OFFER], strings: { escalate: 'Take the next step' } });
	check('Joe: a custom Form title is the button\'s label',
		j.buttons().length === 1 && j.buttons()[0].textContent === 'Take the next step',
		JSON.stringify(j.buttons().map(b => b.textContent)));

	for (const sync of [false, true]) {
		j = await bootJoe({ sync, outputs: [OFFER, Object.assign({}, OFFER, { reason: 'again' })] });
		check('Joe (' + (sync ? 'non-streaming' : 'streaming') + '): two offers in one reply make one button, and no form',
			j.buttons().length === 1 && !j.formOpen(), 'buttons=' + j.buttons().length + ' open=' + j.formOpen());
	}

	// A restored thread replays text and product cards only, even if an offer was stored.
	j = await bootJoe({ storage: { a3rev_thread_id: 'thr-1', a3rev_threads: JSON.stringify([{ id: 'thr-1', messages: [
		{ role: 'user', content: 'Hi' }, { role: 'assistant', content: REPLY, tool_outputs: [OFFER] }] }]) } });
	check('Joe: a restored thread re-opens nothing (guard)',
		!j.formOpen() && j.buttons().length === 0 && !!j.reply && j.reply.textContent.trim() === REPLY,
		JSON.stringify({ open: j.formOpen(), buttons: j.buttons().length, reply: j.reply && j.reply.textContent }));

	// ── Sam ──────────────────────────────────────────────────────────────────
	let s = await bootSam({ outputs: [OFFER] });
	await s.send();
	const reply = s.messages.querySelector('.def-sa-message-assistant');
	check('Sam (streamed): an escalation_offer renders no request form, and the reply in full',
		s.cards().length === 0 && !!reply && reply.textContent.trim() === REPLY,
		JSON.stringify({ cards: s.cards().length, reply: reply && reply.textContent }));
	check('Sam: no offer, no button (guard)',
		await (async () => { const t = await bootSam({}); await t.send(); return t.buttons().length === 0; })());
	check('  … one "Talk to a person" button under the reply',
		s.buttons().length === 1 && s.buttons()[0].textContent === 'Talk to a person' &&
		!!(reply && reply.compareDocumentPosition(s.buttons()[0]) & 4),
		JSON.stringify(s.buttons().map(b => b.textContent)));
	let before = s.cards().length;
	if (s.buttons()[0]) s.buttons()[0].click();
	check('  … and clicking it renders the request form once, none until then',
		before === 0 && s.cards().length === 1 && s.buttons().length === 0, 'before=' + before + ' cards=' + s.cards().length);

	s = await bootSam({ outputs: [OFFER, OFFER] });
	await s.send();
	check('Sam (streamed): two offers in one reply make one button, and no form',
		s.buttons().length === 1 && s.cards().length === 0, 'buttons=' + s.buttons().length + ' cards=' + s.cards().length);

	s = await bootSam({ config: { talkToPerson: 'Mit einer Person sprechen' } });
	s.sam.processToolOutputs([{ tool_name: 'escalate_to_human', status: 'success', escalation: { message: 'x' } }]);
	check('Sam (processToolOutputs): an escalation renders a button, translated, and no form',
		s.buttons().length === 1 && s.buttons()[0].textContent === 'Mit einer Person sprechen' && s.cards().length === 0,
		JSON.stringify({ buttons: s.buttons().map(b => b.textContent), cards: s.cards().length }));
	before = s.cards().length;
	if (s.buttons()[0]) s.buttons()[0].click();
	check('  … and clicking it renders the request form, none until then', before === 0 && s.cards().length === 1);

	// A restored thread replays text only, so nothing opens and no button comes back.
	s = await bootSam({ config: { activeThreadUrl: 'https://site.test/active' } });
	s.w.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve({ thread_id: 'th-1',
		messages: [{ role: 'user', content: 'Hi' }, { role: 'assistant', content: REPLY, tool_outputs: [OFFER] }] }) });
	s.sam.resumeActiveThread();
	await sleep(50);
	check('Sam: a resumed thread re-opens nothing (guard)',
		s.cards().length === 0 && s.buttons().length === 0 && s.messages.textContent.includes(REPLY));

	console.log('harness-handoff-on-click: the hand-off opens on the person\'s click (v9.2.0)');
	results.forEach(r => console.log(r));
	console.log(pass + ' passed, ' + fail + ' failed');
	process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
