/*
 * The widget streams straight from the platform (W74, v8.9.0) — both widgets.
 *
 * Customer Chat runs its SHIPPED module whole in jsdom (as harness-cc-announce.js does);
 * Staff AI runs its SHIPPED send path, request path and SSE parser, sliced by marker
 * (as harness-stream-resume.js does). Each answers a scripted network: the site's
 * stream-token route, the platform's stream (direct) and the site's proxy.
 *
 * What it holds, for both: the chat opening mints one token for its conversation; a
 * send whose thread matches goes direct — credentials omitted, the token as a Bearer,
 * the same body; a 401, a 403 or a TypeError before the response falls back to the site
 * once for that turn and re-mints; any other refusal does not fall back; the token is
 * re-minted after a turn that changed the thread or left under five minutes, never
 * used with under 30 s left; and each stream writes one line to the voice log. Customer
 * Chat also: a refusal is read by its `error` case (429 → rateLimited, a spoken 422 →
 * nothingHeard), the cart header travels as X-DEF-WC-Cart-Token, a thread switch goes
 * through the site, and logging in drops the token.
 *
 * 28 checks.
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
const show = v => JSON.stringify(v);
const sleep = ms => new Promise(r => setTimeout(r, ms));

// ── Customer Chat ────────────────────────────────────────────────────────────
const MODULE = extract.customerChatSource();
const VENDOR = ['assets/js/def-persona.js', 'assets/js/vendor/marked.min.js', 'assets/js/vendor/purify.min.js',
  'assets/js/def-core-product-cards.js'].map(f => fs.readFileSync(path.join(extract.REPO, f), 'utf8'));
const CC = {
  apiBaseUrl: 'https://api.example.test',
  chatStreamUrl: 'https://shop.test/wp-json/def-core/v1/chat/stream',
  streamTokenUrl: 'https://shop.test/wp-json/def-core/v1/chat/stream-token',
  wpRestUrl: 'https://shop.test/wp-json/def-core/v1/',
  wpRestRoot: 'https://shop.test/wp-json/',
  restUrl: 'https://shop.test/wp-json/def-core/v1/context-token',
  loginUrl: 'https://shop.test/wp-admin/admin-ajax.php',
  nonce: 'n0nce', wpRestNonce: 'n0nce', assistantName: 'Joe', isLoggedIn: false,
};
const CC_DIRECT = 'https://api.example.test/api/chat/stream';

async function bootChat(opts) {
  opts = opts || {};
  const dom = new JSDOM('<!doctype html><html><body><div id="host"></div></body></html>',
    { url: 'https://shop.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  const realST = w.setTimeout.bind(w);
  w.setTimeout = (fn, ms) => realST(fn, (ms || 0) >= 1000 ? ms : Math.min(ms || 0, 1));
  for (const src of VENDOR) w.eval(src);
  w.ReadableStream = function () {};
  for (const k of Object.keys(opts.storage || {})) w.localStorage.setItem(k, opts.storage[k]);
  // direct: what the platform answers each direct request with, in turn — 'ok', a status, or 'typeerror'.
  const h = { w, direct: opts.direct || [], mints: [], directCalls: [], proxyCalls: [], notes: [], life: opts.life || 600 };
  const res = (body, status) => Promise.resolve({ ok: status >= 200 && status < 300, status,
    headers: { get: () => null }, json: () => Promise.resolve(body), text: () => Promise.resolve(JSON.stringify(body)) });
  function sse(events) {
    const enc = new w.TextEncoder();
    const chunks = events.map(e => enc.encode('data: ' + JSON.stringify(e) + '\n\n'));
    return Promise.resolve({ ok: true, status: 200, body: { getReader: () => ({
      read: () => Promise.resolve(chunks.length ? { done: false, value: chunks.shift() } : { done: true }) }) } });
  }
  // A turn on a thread stays on it; a new conversation's first turn names a new one.
  let threads = 0;
  const reply = init => {
    const thread = JSON.parse(init.body).thread_id || 'thr-' + (++threads);
    return sse([{ type: 'text_delta', text: 'Hello.' }, { type: 'done', thread_id: thread, choices: [{ message: { content: 'Hello.' } }] }]);
  };
  w.fetch = function (url, init) {
    url = String(url); init = init || {};
    if (url === CC.streamTokenUrl) {
      h.mints.push({ body: JSON.parse(init.body), nonce: init.headers['X-WP-Nonce'], credentials: init.credentials });
      return res({ token: 'tok-' + h.mints.length, exp: Math.floor(Date.now() / 1000) + h.life, stream_url: CC_DIRECT }, 200);
    }
    if (url === CC_DIRECT) {
      h.directCalls.push(init);
      const next = h.direct.shift() || 'ok';
      if (next === 'typeerror') return Promise.reject(new w.TypeError('Failed to fetch'));
      if (typeof next === 'number') return res({ detail: 'refused' }, next);
      return reply(init);
    }
    if (url === CC.chatStreamUrl) { h.proxyCalls.push(init); return reply(init); }
    if (url === CC.loginUrl) return res({ success: true, data: { token: 'a.' + Buffer.from('{"first_name":"Sam"}').toString('base64') + '.s' } }, 200);
    if (url === CC.wpRestUrl + 'chat/voice') return res({ enabled: !!opts.voice }, 200);
    return res({}, 200);
  };
  if (opts.voice) {
    h.voice = {};
  }
  w.DefVoice = {
    supported: () => true, micAllowedBySite: () => true, isStopPhrase: () => false,
    createSpeaker: () => ({ stop() {}, unlock() {}, speak() {}, end() {}, whenIdle: () => new Promise(() => {}) }),
    createRecorder: o => {
      if (h.voice) h.voice.opts = o;
      return { start: () => Promise.resolve(true), isRecording: () => false, release() {},
        stop: () => Promise.resolve({ blob: {}, seconds: 2, spoke: true, mime: 'audio/webm' }) };
    },
    toBase64: () => Promise.resolve('AAAA'), log: () => [],
    note: line => h.notes.push(line),
  };
  const host = w.document.getElementById('host');
  const root = host.attachShadow({ mode: 'open' });
  root.innerHTML = '<div class="def-cc-panel"><button class="def-cc-panel-close" aria-label="Close chat"></button></div>';
  h.root = root;
  h.input = () => root.querySelector('.def-cc-composer-input');
  h.send = async text => {
    h.input().value = text;
    h.input().dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    // The turn is over when the composer is given back; then the after-turn mint lands.
    for (let i = 0; i < 300 && (i < 2 || h.input().disabled); i++) await sleep(10);
    await sleep(30);
  };
  h.last = () => { const b = root.querySelectorAll('.def-cc-message--assistant .def-cc-message-content'); return b.length ? b[b.length - 1].textContent.trim() : ''; };
  w.eval(MODULE);
  w.DEFCustomerChat.init(root, Object.assign({}, CC, opts.config || {}));
  await sleep(40);
  return h;
}

// ── Staff AI ─────────────────────────────────────────────────────────────────
const PARSER = extract.slice('SSE parser',
  l => l.includes('// SSE buffer parser'), l => l.includes('// Tool status labels for SSE streaming'),
  ['function parseSSEBuffer']);
const TOOL_LABELS = extract.slice('tool status labels',
  l => l.includes('// Tool status labels for SSE streaming'), l => l.includes('// HTML escape helper'),
  ['const SSE_TOOL_PACING_MS']);
const ESCAPE = extract.slice('escapeHtml', l => l.includes('// HTML escape helper'), l => l.includes('// Markdown rendering'),
  ['function escapeHtml']);
const TOOL_ROWS = extract.slice('tool rows', l => l.includes('SSE STREAMING UI HELPERS'),
  l => l.startsWith('\tfunction updateTypingLabel('), ['function renderToolStatus']);
const REQUEST = extract.slice('request path', l => l.includes('// Every request that carries the nonce'),
  l => l.includes('\t// Elements'), ['async function wpFetch', 'function extractServerMessage']);
const BANNER = extract.slice('error banner', l => l.includes('\t// Show error'), l => l.includes('// Smart scroll'),
  ['function showError']);
const RECOVER = extract.slice('recoverTurn', l => l.includes("// V-S1's client half (7.6.9)"),
  l => l.includes('// ── C3b: the installed app keeps up'), ['async function recoverTurn']);
const SEND = extract.slice('send path', l => l.startsWith('\tasync function sendMessage() {'),
  l => l.includes('// SHARE MODAL'),
  ['async function sendMessageStreaming', 'async function openStream', 'async function mintStreamToken', "credentials: 'omit'"],
  'DIRECT_SEND');

const SA = 'https://e.test/wp-json/def-core/v1';
const SA_PROXY = SA + '/staff-ai/chat/stream';
const SA_MINT = SA + '/chat/stream-token';
const SA_DIRECT = 'https://api.example.test/api/staff-ai/chat/stream';
const HEAD = `
	let nonce = StaffAIConfig.nonce;
	let messages = [], isLoading = false, isReadOnly = false, conversationOn = false, spokenTurn = false,
		openingSpoken = null, lastSuggestion = null, selectedModel = '', currentConversationId = null,
		activeProjectId = null, voiceStopped = false, _isStreaming = false, _userScrolledUp = false,
		dirtyInput = false, chipEpoch = 0, readbackBuffer = '';
	function renderMessages() {
		messagesList.querySelectorAll('.message').forEach(function (el) { el.remove(); });
		messages.forEach(function (m) {
			var msg = document.createElement('div');
			msg.className = 'message message-' + m.role;
			msg.textContent = m.content || '';
			messagesList.appendChild(msg);
		});
	}
	function removeTypingMessage() {
		for (var i = messages.length - 1; i >= 0; i--) { if (messages[i].isTyping) { messages.splice(i, 1); return; } }
	}
`;
const TAIL = `
	return { sendMessage: sendMessage, open: function (id) { currentConversationId = id; } };`;

async function bootStaff(opts) {
  opts = opts || {};
  const dom = new JSDOM('<!doctype html><div id="messagesContainer"><div id="messagesList"></div></div>' +
    '<div id="errorBanner"></div><div id="confirmChips"></div><textarea id="composerInput"></textarea>',
    { url: 'https://e.test/staff-ai/' });
  const window = dom.window, document = window.document;
  window.DefPersona = { createController: () => ({ handleEvent() {}, formatThinkingLabel: m => m, reset() {} }) };
  const h = { direct: opts.direct || [], mints: [], directCalls: [], proxyCalls: [], notes: [], life: opts.life || 600 };
  window.DefVoice = { note: line => h.notes.push(line) };
  const enc = new TextEncoder();
  let threads = 0;
  const sse = init => { const thread = JSON.parse(init.body).thread_id || 'th-' + (++threads); return new Response(new ReadableStream({ start(c) {
    c.enqueue(enc.encode('id: 1\ndata: ' + JSON.stringify({ type: 'text_delta', text: 'Done.', thread_id: thread, turn_id: 'm1' }) + '\n\n'));
    c.enqueue(enc.encode('id: 2\ndata: ' + JSON.stringify({ type: 'done', thread_id: thread, turn_id: 'm1', choices: [{ message: { content: 'Done.' } }] }) + '\n\n'));
    c.close();
  } }), { status: 200, headers: { 'content-type': 'text/event-stream' } }); };
  const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
  async function fakeFetch(url, init) {
    init = init || {};
    if (url === SA_MINT) {
      h.mints.push({ body: JSON.parse(init.body), nonce: init.headers['X-WP-Nonce'] });
      return json(200, { token: 'tok-' + h.mints.length, exp: Math.floor(Date.now() / 1000) + h.life, stream_url: SA_DIRECT });
    }
    if (url === SA_DIRECT) {
      h.directCalls.push(init);
      const next = h.direct.shift() || 'ok';
      if (next === 'typeerror') throw new TypeError('Failed to fetch');
      if (typeof next === 'number') return json(next, { detail: 'Too many requests right now.' });
      return sse(init);
    }
    if (url === SA_PROXY) { h.proxyCalls.push(init); return sse(init); }
    throw new Error('unscripted request to ' + url);
  }
  const deps = {
    window, document, fetch: fakeFetch, Response, ReadableStream, TextDecoder, AbortController,
    StaffAIConfig: { nonce: 'n0000000', nonceUrl: 'https://e.test/wp-admin/admin-ajax.php?action=rest-nonce', streamTokenUrl: SA_MINT },
    apiBase: SA + '/staff-ai', chatStreamUrl: SA_PROXY, location: { reload() {} }, t: (k, d) => d,
    messagesList: document.getElementById('messagesList'), messagesContainer: document.getElementById('messagesContainer'),
    errorBanner: document.getElementById('errorBanner'), confirmChips: document.getElementById('confirmChips'),
    composerInput: document.getElementById('composerInput'),
    autoResize() {}, updateSendButton() {}, hideInfo() {}, hasActiveFiles: () => false, stagedFiles: [],
    classifySuggestionOutcome: () => ({}), endConversation() {}, browserTimezone: () => '', clearStagedFiles() {},
    readBack() {}, readBackSoFar() {}, loadConversations() {}, updateReadOnlyState() {}, afterSpokenTurn() {},
    dropUnfilledTranscript() { return false; }, uploadAllStagedFiles: async () => ({ success: true, fileIds: [] }),
    renderMarkdown: s => s, applyCitations() {}, buildCitationMap: () => ({}), createToolOutputCard: () => null,
    handleSpokenStop: () => false, console: { info() {}, error() {} }
  };
  const names = Object.keys(deps);
  h.api = new window.Function(...names, HEAD + PARSER + '\n' + TOOL_LABELS + '\n' + ESCAPE + '\n' + REQUEST + '\n' +
    BANNER + '\n' + RECOVER + '\n' + TOOL_ROWS + '\n' + SEND + TAIL)(...names.map(k => deps[k]));
  h.send = async text => { deps.composerInput.value = text; await h.api.sendMessage(); await sleep(20); };
  h.banner = () => deps.errorBanner.classList.contains('visible') ? deps.errorBanner.textContent : '';
  h.last = () => { const m = deps.messagesList.querySelectorAll('.message-assistant'); return m.length ? m[m.length - 1].textContent : ''; };
  await sleep(20);
  return h;
}

(async function () {
  let h;

  // ── Customer Chat ──────────────────────────────────────────────────────────
  h = await bootChat({ storage: { 'def:wc_cart_token': 'aaa.bbb.ccc' } });
  check('Customer Chat: opening the chat mints one token, for the new conversation (thread ""), with the nonce, same-origin',
    h.mints.length === 1 && show(h.mints[0]) === show({ body: { channel: 'customer_chat', thread: '' }, nonce: 'n0nce', credentials: 'same-origin' }),
    show(h.mints));
  await h.send('Hi');
  const d = h.directCalls[0] || {};
  const body = d.body ? JSON.parse(d.body) : {};
  check('the first send goes straight to the platform: credentials omitted; Content-Type, the Bearer token and the cart as X-DEF-WC-Cart-Token — no nonce, no Cart-Token',
    h.directCalls.length === 1 && h.proxyCalls.length === 0 && d.credentials === 'omit' &&
    show(d.headers) === show({ 'Content-Type': 'application/json', 'Authorization': 'Bearer tok-1', 'X-DEF-WC-Cart-Token': 'aaa.bbb.ccc' }),
    show({ direct: h.directCalls.length, proxy: h.proxyCalls.length, headers: d.headers }));
  check('the body is the turn\'s own: the message, thread_id null on a new conversation', body.thread_id === null &&
    body.messages && body.messages[0].content === 'Hi' && h.last() === 'Hello.', d.body);
  await h.send('And another');
  await h.send('And a third');
  check('three turns in a new conversation mint twice — at open, and once the first turn named the thread — and all three go direct',
    h.mints.length === 2 && h.mints[1].body.thread === 'thr-1' && h.directCalls.length === 3 && h.proxyCalls.length === 0 &&
    h.directCalls[2].headers.Authorization === 'Bearer tok-2',
    show({ mints: h.mints.map(m => m.body), direct: h.directCalls.length, proxy: h.proxyCalls.length }));
  check('one voice-log line per stream: send → headers, direct', h.notes.length === 3 &&
    h.notes.every(n => /^stream direct \d+\.\d\ds$/.test(n)), show(h.notes));

  for (const refusal of [401, 403, 'typeerror']) {
    h = await bootChat({ direct: [refusal], storage: { a3rev_thread_id: 'thr-1' } });
    await h.send('Hi');
    const fellBack = h.directCalls.length === 1 && h.proxyCalls.length === 1 && h.last() === 'Hello.';
    const reMinted = h.mints.length === 2;
    await h.send('Again');
    const label = refusal === 'typeerror' ? 'a TypeError before the response' : 'a ' + refusal;
    check('Customer Chat: ' + label + ' runs that turn through the site once, re-mints, and the next turn goes direct on the new token',
      fellBack && reMinted && h.directCalls.length === 2 && h.proxyCalls.length === 1 &&
      h.directCalls[1].headers.Authorization === 'Bearer tok-2',
      show({ direct: h.directCalls.length, proxy: h.proxyCalls.length, mints: h.mints.length, last: h.last() }));
    check('  … and its log line says it fell back, then the next says direct', h.notes.length === 2 &&
      new RegExp('^stream proxy \\d+\\.\\d\\ds \\(direct ' + (refusal === 'typeerror' ? 'failed' : refusal) + '\\)$').test(h.notes[0]) &&
      /^stream direct /.test(h.notes[1]), show(h.notes));
  }

  h = await bootChat({ direct: [429] });
  await h.send('Hi');
  check('Customer Chat: a 429 does not fall back or re-mint, and shows the rate-limit line',
    h.directCalls.length === 1 && h.proxyCalls.length === 0 && h.mints.length === 1 &&
    h.last() === 'Please wait a moment before sending another message', show({ direct: h.directCalls.length, proxy: h.proxyCalls.length, mints: h.mints.length, last: h.last() }));

  h = await bootChat({ direct: [500] });
  await h.send('Hi');
  check('a 500 straight from the platform is the error case\'s own line, not a fallback',
    h.proxyCalls.length === 0 && h.last() === 'Unable to connect. Please try again.', h.last());

  h = await bootChat({ voice: true, direct: [422] });
  h.root.querySelector('.def-cc-composer-mic').click();
  await sleep(20);
  h.voice.opts.onSilence();
  await sleep(80);
  check('a spoken turn the platform refuses with 422 says "Nothing was heard", as through the site',
    h.directCalls.length === 1 && h.proxyCalls.length === 0 && /^Nothing was heard/.test(h.last()) &&
    !h.root.querySelector('.def-cc-message--transcribing'), h.last());

  h = await bootChat();
  await h.send('Hi');
  h.root.querySelector('.def-cc-header-refresh').click();
  h.root.querySelector('.def-cc-confirm-btn--danger').click();
  await sleep(20);
  await h.send('A new question');
  check('a thread switch (a new conversation after one named thr-1) goes through the site, then re-mints for the thread it lands on',
    h.directCalls.length === 1 && h.proxyCalls.length === 1 && h.mints.length === 3 && h.mints[2].body.thread === 'thr-2',
    show({ direct: h.directCalls.length, proxy: h.proxyCalls.length, mints: h.mints.map(m => m.body.thread) }));

  h = await bootChat({ storage: { a3rev_thread_id: 'thr-1' } });
  h.root.querySelector('.def-cc-menu-item:nth-child(2)').click();   // Log in
  const inputs = h.root.querySelectorAll('.def-cc-login-input');
  inputs[0].value = 'sam'; inputs[1].value = 'secret';
  h.root.querySelector('.def-cc-login-submit').click();
  await sleep(60);
  await h.send('Where is my order?');
  check('logging in drops the visitor\'s token: the next turn goes through the site, and a fresh token is minted after it',
    h.mints.length === 2 && h.directCalls.length === 0 && h.proxyCalls.length === 1,
    show({ direct: h.directCalls.length, proxy: h.proxyCalls.length, mints: h.mints.length }));

  h = await bootChat({ life: 200 });
  await h.send('Hi');
  check('a token with under five minutes left is re-minted after the turn', h.mints.length === 2 && h.directCalls.length === 1,
    show({ mints: h.mints.length, direct: h.directCalls.length }));
  h = await bootChat({ life: 20 });
  await h.send('Hi');
  check('a token with under 30 s left is never used: the send goes through the site', h.directCalls.length === 0 && h.proxyCalls.length === 1,
    show({ direct: h.directCalls.length, proxy: h.proxyCalls.length }));

  h = await bootChat({ config: { streamTokenUrl: undefined } });
  await h.send('Hi');
  check('a page without the token route (an older plugin page in cache) mints nothing and streams through the site',
    h.mints.length === 0 && h.directCalls.length === 0 && h.proxyCalls.length === 1 && /^stream proxy \d+\.\d\ds$/.test(h.notes[0]),
    show({ mints: h.mints.length, notes: h.notes }));

  // ── Staff AI ───────────────────────────────────────────────────────────────
  h = await bootStaff();
  check('Staff AI: opening the console mints one token for the new conversation, with the nonce',
    show(h.mints) === show([{ body: { channel: 'staff_ai', thread: '' }, nonce: 'n0000000' }]), show(h.mints));
  await h.send('Draft the email');
  const s = h.directCalls[0] || {};
  check('the first send goes straight to the platform, credentials omitted, the Bearer token, the same body; then re-mints for the thread it named',
    h.directCalls.length === 1 && h.proxyCalls.length === 0 && s.credentials === 'omit' &&
    show(s.headers) === show({ 'Content-Type': 'application/json', 'Authorization': 'Bearer tok-1' }) &&
    JSON.parse(s.body).messages[0].content === 'Draft the email' && h.last() === 'Done.' &&
    h.mints.length === 2 && h.mints[1].body.thread === 'th-1',
    show({ direct: h.directCalls.length, proxy: h.proxyCalls.length, headers: s.headers, mints: h.mints.map(m => m.body) }));
  await h.send('Shorter');
  check('the next turn on that thread goes direct on the new token, with thread_id, and mints nothing more; one log line per stream',
    h.directCalls.length === 2 && h.directCalls[1].headers.Authorization === 'Bearer tok-2' &&
    JSON.parse(h.directCalls[1].body).thread_id === 'th-1' && h.mints.length === 2 &&
    h.notes.length === 2 && h.notes.every(n => /^stream direct \d+\.\d\ds$/.test(n)), show({ mints: h.mints.length, notes: h.notes }));

  for (const refusal of [401, 403, 'typeerror']) {
    h = await bootStaff({ direct: [refusal] });
    await h.send('Draft the email');
    check('Staff AI: ' + (refusal === 'typeerror' ? 'a TypeError' : 'a ' + refusal) + ' runs that turn through the site once (with the nonce) and re-mints; the log says so',
      h.directCalls.length === 1 && h.proxyCalls.length === 1 && h.proxyCalls[0].headers['X-WP-Nonce'] === 'n0000000' &&
      h.last() === 'Done.' && h.banner() === '' && h.mints.length >= 2 &&
      new RegExp('^stream proxy \\d+\\.\\d\\ds \\(direct ' + (refusal === 'typeerror' ? 'failed' : refusal) + '\\)$').test(h.notes[0]),
      show({ direct: h.directCalls.length, proxy: h.proxyCalls.length, mints: h.mints.length, notes: h.notes, banner: h.banner() }));
  }

  h = await bootStaff({ direct: [429] });
  await h.send('Draft the email');
  check('Staff AI: a 429 does not fall back or re-mint, and the banner shows the platform\'s own words',
    h.directCalls.length === 1 && h.proxyCalls.length === 0 && h.mints.length === 1 && h.banner() === 'Too many requests right now.',
    show({ direct: h.directCalls.length, proxy: h.proxyCalls.length, mints: h.mints.length, banner: h.banner() }));

  h = await bootStaff();
  h.api.open('th-7');   // a conversation opened from the list
  await h.send('Pick up where we left off');
  check('Staff AI: a just-opened conversation\'s first turn goes through the site, then a token for it is minted',
    h.directCalls.length === 0 && h.proxyCalls.length === 1 && h.mints.length === 2 && h.mints[1].body.thread === 'th-7',
    show({ direct: h.directCalls.length, proxy: h.proxyCalls.length, mints: h.mints.map(m => m.body) }));

  // ── The voice module's log ─────────────────────────────────────────────────
  {
    const dom = new JSDOM('<!doctype html>', { runScripts: 'outside-only' });
    dom.window.eval(fs.readFileSync(path.join(extract.REPO, 'assets/js/def-core-voice.js'), 'utf8'));
    dom.window.DefVoice.note('stream direct 0.42s');
    check('DefVoice.note() writes a line on the same log the mic\'s long-press reads', /^\d\d:\d\d stream direct 0\.42s$/.test(dom.window.DefVoice.log().slice(-1)[0]),
      show(dom.window.DefVoice.log()));
  }

  console.log('Direct stream (W74)\n' + results.join('\n'));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
