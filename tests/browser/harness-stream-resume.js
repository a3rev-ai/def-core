/*
 * A Staff AI turn survives a dropped connection, and the working line says what is
 * happening (v8.7.14) — behavioural harness.
 *
 * Runs the SHIPPED send path (sendMessage / sendMessageStreaming), the SSE parser, the
 * request path and recoverTurn, sliced by marker from assets/js/staff-ai.js, inside jsdom
 * against a scripted fetch that answers with real SSE byte streams: DEF's frames carry an
 * `id:` and name their turn; the proxy's own frames (a 410, a 404, a lost connection)
 * carry no id. Timers are faked so the backoff and the clock are read, not waited for.
 *
 * 16 checks.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const PARSER = extract.slice('SSE parser',
  l => l.includes('// SSE buffer parser'),
  l => l.includes('// Tool status labels for SSE streaming'),
  ['function parseSSEBuffer', "indexOf('id:')"], 'RESUME_PARSER');
const REQUEST = extract.slice('request path',
  l => l.includes('// Every request that carries the nonce'),
  l => l.includes('\t// Elements'),
  ['async function wpFetch', 'async function apiRequest', 'function extractServerMessage']);
const BANNER = extract.slice('error banner',
  l => l.includes('\t// Show error'),
  l => l.includes('// Smart scroll'),
  ['function showError', 'function hideError']);
const RECOVER = extract.slice('recoverTurn',
  l => l.includes("// V-S1's client half (7.6.9)"),
  l => l.includes('// ── C3b: the installed app keeps up'),
  ['async function recoverTurn', 'until()', 'RECOVER_TURN_POLL_MS'], 'RESUME_RECOVER');
const SEND = extract.slice('send path',
  l => l.startsWith('\tasync function sendMessage() {'),
  l => l.includes('// SHARE MODAL'),
  ['async function sendMessageStreaming', 'function workingLine', 'function showProgress',
    'async function readStream', "'/turns/'", "t('reconnecting'", 'function pollBeside'], 'RESUME_SEND');

const BASE = 'https://e.test/wp-json/def-core/v1/staff-ai';
const STREAM_URL = BASE + '/chat/stream';
const resumeUrl = after => BASE + '/conversations/th-1/turns/msg-1/stream?after=' + after;
const isResume = url => url.indexOf('/turns/') !== -1;
const isPoll = url => url === BASE + '/conversations/th-1';
const UNAVAILABLE = 'The assistant is unavailable right now. Please try again.';

// One DEF frame: an `id:` and the turn named, as every DEF frame is (8.7.14).
const frame = (seq, evt) => 'id: ' + seq + '\ndata: ' + JSON.stringify(Object.assign({ thread_id: 'th-1', turn_id: 'msg-1' }, evt)) + '\n\n';
// The proxy's own frame: no id (stream_proxy's sse_error_payload / connection failure).
const proxyError = status => 'data: ' + JSON.stringify(status ? { type: 'error', message: UNAVAILABLE, status: status } : { type: 'error', message: 'Backend connection failed.' }) + '\n\n';
const delta = (seq, text) => frame(seq, { type: 'text_delta', text: text, step: 1 });
const done = (seq, text) => frame(seq, { type: 'done', choices: [{ message: { content: text, tool_outputs: [] } }] });

// A 200 SSE response over the given chunks: closed cleanly, or failing like a dropped network.
function sse(chunks, fails) {
  const enc = new TextEncoder();
  let i = 0;
  return new Response(new ReadableStream({
    pull(c) {   // one chunk per read, so a failure lands after the frames, not over them
      if (i < chunks.length) c.enqueue(enc.encode(chunks[i++]));
      else if (fails) c.error(new TypeError('network error')); else c.close();
    }
  }), { status: 200, headers: { 'content-type': 'text/event-stream' } });
}
// A stream the check feeds by hand, to look at the page mid-turn.
function liveStream() {
  const enc = new TextEncoder();
  let ctrl;
  const response = new Response(new ReadableStream({ start(c) { ctrl = c; } }),
    { status: 200, headers: { 'content-type': 'text/event-stream' } });
  return { response, push: s => ctrl.enqueue(enc.encode(s)), close: () => ctrl.close() };
}
const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
const thread = last => json(200, { success: true, id: 'th-1', messages: [{ role: 'user', content: 'Build the sheet' }, last] });

const HEAD = `
	let nonce = StaffAIConfig.nonce;
	let messages = [], isLoading = false, isReadOnly = false, conversationOn = false, spokenTurn = false,
		openingSpoken = null, lastSuggestion = null, selectedModel = '', currentConversationId = null,
		activeProjectId = null, voiceStopped = false, _isStreaming = false, _userScrolledUp = false,
		dirtyInput = false, chipEpoch = 0, readbackBuffer = '';
	var SSE_TOOL_PACING_MS = 0;
	function t(k, d) { return d; }
	function renderMessages() {
		messagesList.textContent = '';
		messages.forEach(function (m) {
			var msg = document.createElement('div');
			msg.className = 'message message-' + m.role;
			var c = document.createElement('div');
			c.className = 'message-content';
			if (m.isTyping) c.innerHTML = '<div class="typing-indicator"></div>'; else c.textContent = m.content || '';
			msg.appendChild(c);
			messagesList.appendChild(msg);
		});
	}
	function removeTypingMessage() {
		for (var i = messages.length - 1; i >= 0; i--) { if (messages[i].isTyping) { messages.splice(i, 1); return; } }
	}
`;
const TAIL = `
	return {
		sendMessage: sendMessage,
		parse: parseSSEBuffer,
		leave: function () { currentConversationId = 'th-other'; },
		state: function () { return { isLoading: isLoading, messages: messages, conversation: currentConversationId }; }
	};`;

function boot(script) {
  const dom = new JSDOM('<!doctype html><div id="messagesContainer"><div id="messagesList"></div></div>' +
    '<div id="errorBanner"></div><div id="confirmChips"></div><textarea id="composerInput"></textarea>',
    { url: 'https://e.test/staff-ai/' });
  const window = dom.window, document = window.document;
  window.DefPersona = { createController: () => ({ handleEvent() {}, formatThinkingLabel: m => m, reset() {} }) };
  const calls = [], waits = [], intervals = new Map(), clock = { now: 1e12 }, hooks = {};
  let nextInterval = 0;
  // `script` is a list answered in order, or a router (url) => response for a check where
  // the thread poll and the resume retries run side by side.
  async function fakeFetch(url, init) {
    calls.push(url);
    const next = typeof script === 'function' ? (calls.length > 300 ? null : () => script(url)) : script.shift();
    // Past the script (or 300 requests), the person leaves: code that would retry for ever
    // (a bite run) still ends, and the extra request is in `calls` for the check to see.
    if (!next) { api.leave(); throw new Error('unscripted request to ' + url); }
    return typeof next === 'function' ? next(url, init) : next;
  }
  class FakeDate extends Date { static now() { return clock.now; } }
  const deps = {
    window, document, fetch: fakeFetch, Response, ReadableStream, TextDecoder, AbortController, Date: FakeDate,
    // Time is simulated: a wait returns at once and moves the clock on by what it asked for.
    setTimeout: (fn, ms) => { waits.push(ms); clock.now += ms || 0; if (hooks.onWait) hooks.onWait(ms); return setTimeout(fn, 0); },
    setInterval: fn => { intervals.set(++nextInterval, fn); return nextInterval; },
    clearInterval: id => { intervals.delete(id); },
    StaffAIConfig: { nonce: 'n0000000', nonceUrl: 'https://e.test/wp-admin/admin-ajax.php?action=rest-nonce' },
    apiBase: BASE, chatStreamUrl: STREAM_URL, location: { reload() {} },
    messagesList: document.getElementById('messagesList'), messagesContainer: document.getElementById('messagesContainer'),
    errorBanner: document.getElementById('errorBanner'), confirmChips: document.getElementById('confirmChips'),
    composerInput: document.getElementById('composerInput'),
    autoResize() {}, updateSendButton() {}, hideInfo() {}, hasActiveFiles: () => false, stagedFiles: [],
    classifySuggestionOutcome: () => ({}), endConversation() {}, browserTimezone: () => '', clearStagedFiles() {},
    readBack() {}, readBackSoFar() {}, loadConversations() {}, updateReadOnlyState() {}, afterSpokenTurn() {},
    dropUnfilledTranscript() { return false; }, uploadAllStagedFiles: async () => ({ success: true, fileIds: [] }),
    renderMarkdown: s => s, renderToolStatus: () => document.createElement('div'), completeToolStatus() {},
    applyCitations() {}, buildCitationMap: () => ({}), createToolOutputCard: () => null, handleSpokenStop: () => false,
    console: { info() {}, error() {} }
  };
  const names = Object.keys(deps);
  const api = new window.Function(...names, HEAD + PARSER + '\n' + REQUEST + '\n' + BANNER + '\n' + RECOVER + '\n' + SEND + TAIL)
    (...names.map(k => deps[k]));
  const list = deps.messagesList;
  return {
    api, calls, waits, intervals, clock, hooks,
    send: text => { deps.composerInput.value = text; return api.sendMessage(); },
    working: () => { const w = list.querySelector('.tool-status'); return w ? w.textContent : null; },
    bubble: () => { const b = list.querySelectorAll('.message-assistant .message-content'); return b.length ? b[b.length - 1].textContent : null; },
    banner: () => deps.errorBanner.classList.contains('visible') ? deps.errorBanner.textContent : '',
    backoff: () => waits.filter(ms => ms === 1000 || ms === 2000 || ms === 5000),
  };
}
// Real turns of the event loop, timers included: a 0 ms timer can miss a run of setImmediates.
const settle = async () => { for (let i = 0; i < 10; i++) await new Promise(r => setTimeout(r, 2)); };

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(pass + fail).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(pass + fail).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

(async function () {
  {
    const t = boot([]);
    const p = t.api.parse(': keepalive\n\nid: 7\ndata: {"type":"thinking"}\n\ndata:{"type":"text_delta","text":"a"}\nid:8\n\ndata: {"type":"done"}\n\nid: 9\ndata: {"ty');
    check('the parser reads an `id:` on any frame, before or after its data, and pairs it with that frame; a frame without one is null, a comment is no frame',
      JSON.stringify(p.parsed.map(e => e.type)) === '["thinking","text_delta","done"]' &&
      JSON.stringify(p.ids) === '["7","8",null]' && p.remaining === 'id: 9\ndata: {"ty',
      JSON.stringify(p));
  }
  {
    // The 2026-10-13 canary: the stream ended 142 s in with no `done`, and nothing followed.
    let reconnecting = null;
    const t = boot([
      sse([frame(1, { type: 'thinking', message: 'Thinking...' }), delta(2, 'Here is '), delta(3, 'the sheet')]),
      () => { reconnecting = t.working(); return sse([delta(4, ' you asked for.'), done(5, 'Here is the sheet you asked for.')]); },
    ]);
    await t.send('Build the sheet');
    check('a stream that ends without `done` re-attaches through the resume proxy with the last id it saw, after 1 s',
      t.calls.join(' ') === STREAM_URL + ' ' + resumeUrl(3) && t.backoff().join() === '1000', t.calls.join(' ') + ' waits=' + t.backoff());
    check('"Reconnecting…" is on the working line while it re-attaches, under the text already shown',
      reconnecting === 'Reconnecting…', JSON.stringify(reconnecting));
    check('the resumed frames continue the same bubble and `done` ends the turn: one reply, the composer free, no error',
      t.bubble() === 'Here is the sheet you asked for.' && t.working() === null && !t.api.state().isLoading && t.banner() === '' &&
      t.api.state().messages.filter(m => m.role === 'assistant').length === 1,
      'bubble=' + JSON.stringify(t.bubble()) + ' banner=' + t.banner());
  }
  {
    // Every way a re-attach can fail, then one that works: no ceiling, 1 s / 2 s / 5 s / 5 s…
    const t = boot([
      sse([frame(1, { type: 'thinking', message: 'Thinking...' })], true),        // the network drops mid-stream
      () => { throw new TypeError('Failed to fetch'); },                            // offline
      sse([proxyError(502)]),                                                       // the gateway answered for DEF
      sse([proxyError(0)]),                                                         // the proxy could not reach DEF
      json(504, { message: 'Gateway Timeout' }),                                    // the host's own gateway
    ].concat(Array.from({ length: 5 }, () => sse([proxyError(503)])), [           // nine failed re-attaches in all...
      sse([delta(2, 'Done it.')]),                                                  // ...then back, and frames flow,
      sse([done(3, 'Done it.')]),                                                   // so the next wait is 1 s again
    ]));
    await t.send('Pull the clients');
    check('any end short of `done` re-attaches with no ceiling — a dropped network, offline, a 5xx, a lost upstream — backing off 1 s, 2 s, 5 s, 5 s… and starting again at 1 s once frames flow',
      t.backoff().join() === '1000,2000,5000,5000,5000,5000,5000,5000,5000,5000,1000' &&
      t.calls.slice(1).join(' ') === [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 2].map(resumeUrl).join(' '),
      'waits=' + t.backoff() + ' calls=' + t.calls.join(' '));
    check('the proxy\'s own error frames are the connection failing, never shown; the reply lands',
      t.bubble() === 'Done it.' && t.banner() === '', 'banner=' + t.banner() + ' bubble=' + JSON.stringify(t.bubble()));
  }
  {
    // Still running on another worker for 2 min 3 s past the 410 — longer than the 7.6.9
    // poll's deadline — and every retry of the resume lands on a worker without it.
    let polls = 0;
    const t = boot(url => url === STREAM_URL ? sse([frame(1, { type: 'thinking', message: 'Thinking...' })])
      : isResume(url) ? sse([proxyError(410)])
      : ++polls <= 41 ? thread({ role: 'assistant', content: '', is_complete: false })
      : thread({ role: 'assistant', content: 'The sheet is ready: 225 rows.', is_complete: true }));
    await t.send('Build the sheet');
    const polled = t.calls.filter(isPoll);
    check('a 410 (DEF no longer holds the turn here) polls the thread every 3 s, with no deadline, until the placeholder is complete',
      polled.length === 42 && t.waits.filter(ms => ms === 3000).length === 41, 'polls=' + polled.length);
    check('the polled reply is rendered, with no error and the composer free',
      t.bubble() === 'The sheet is ready: 225 rows.' && t.banner() === '' && !t.api.state().isLoading,
      'bubble=' + JSON.stringify(t.bubble()) + ' banner=' + t.banner());
    const after = t.calls.length;
    await settle();
    check('the resume is retried on its backoff beside the poll, and both stop once the poll shows the reply complete',
      t.calls.filter(isResume).length > 2 && t.calls.lastIndexOf(t.calls.find(isResume)) > t.calls.indexOf(polled[0]) &&
      t.calls.length === after, 'resumes=' + t.calls.filter(isResume).length + ' later=' + (t.calls.length - after));
  }
  {
    // Three workers without the turn, then the one holding it (def-api: three per replica).
    const live = liveStream();
    const resumes = [sse([proxyError(410)]), sse([proxyError(410)]), sse([proxyError(410)]), live.response];
    const t = boot(url => url === STREAM_URL ? sse([frame(1, { type: 'thinking', message: 'Thinking...' })])
      : isResume(url) ? (resumes.shift() || sse([proxyError(410)]))
      : thread({ role: 'assistant', content: '', is_complete: false }));
    const run = t.send('Build the sheet');
    for (let i = 0; i < 100 && resumes.length; i++) await settle();
    live.push(delta(2, 'Your sheet is '));                 // the live turn: frames flow, the turn runs on
    await settle();
    const pollsAtAttach = t.calls.filter(isPoll).length;
    await settle();
    await settle();
    const pollsLater = t.calls.filter(isPoll).length;
    live.push(delta(3, 'ready.') + done(4, 'Your sheet is ready.'));
    live.close();
    await run;
    const after = t.calls.length;
    await settle();
    check('three 410s then a resume that reaches the turn: the poll stops at the first live frame, the live frames finish the reply, and nothing is retried',
      t.calls.filter(isResume).join(' ') === [1, 1, 1, 1].map(resumeUrl).join(' ') && t.backoff().join() === '1000,2000,5000,5000' &&
      pollsAtAttach > 0 && pollsLater === pollsAtAttach &&
      t.bubble() === 'Your sheet is ready.' && t.banner() === '' && !t.api.state().isLoading && t.calls.length === after &&
      t.api.state().messages.filter(m => m.role === 'assistant').length === 1,
      'resumes=' + t.calls.filter(isResume).length + ' waits=' + t.backoff() + ' polls=' + pollsAtAttach + '->' + pollsLater +
      ' bubble=' + JSON.stringify(t.bubble()) + ' later=' + (t.calls.length - after));
  }
  {
    const t = boot([sse([frame(1, { type: 'thinking', message: 'Thinking...' }), delta(2, 'Half')]), sse([proxyError(404)])]);
    await t.send('Build the sheet');
    check('a 404 stops: the error line shows, the text already shown stays, nothing is retried',
      t.calls.length === 2 && t.banner() === UNAVAILABLE && t.bubble() === 'Half' && t.working() === null && !t.api.state().isLoading,
      'calls=' + t.calls.length + ' banner=' + t.banner() + ' bubble=' + JSON.stringify(t.bubble()));
  }
  {
    // DEF's own error frame carries an id: the turn failed, and that is final.
    const t = boot([sse([frame(1, { type: 'thinking', message: 'Thinking...' }), frame(2, { type: 'error', message: 'The assistant stopped before finishing. Please try again.' })])]);
    await t.send('Build the sheet');
    check('DEF\'s own `error` (it carries an id) ends the turn as before: shown, no re-attach',
      t.calls.length === 1 && t.banner() === 'The assistant stopped before finishing. Please try again.', 'calls=' + t.calls.length + ' banner=' + t.banner());
  }
  {
    const t = boot([sse([frame(1, { type: 'thinking', message: 'Thinking...' })])]);
    t.hooks.onWait = ms => { if (ms === 1000) t.api.leave(); };   // New chat while it waits to re-attach
    await t.send('Build the sheet');
    check('a person who has left the conversation is not re-attached for',
      t.calls.length === 1 && !t.api.state().isLoading, 'calls=' + t.calls.join(' '));
  }
  {
    // A stream that ends before DEF has named the turn: the 7.6.9 thread poll, now for a clean end too.
    const t = boot([sse(['data: {"type":"thinking","message":"Thinking...","thread_id":"th-1"}\n\n']),
      thread({ role: 'assistant', content: 'Here you go.' })]);
    await t.send('Build the sheet');
    check('a stream that ends before the turn is named falls back to polling the thread, and renders the reply',
      t.calls[1] === BASE + '/conversations/th-1' && t.bubble() === 'Here you go.' && t.banner() === '',
      'calls=' + t.calls.join(' ') + ' bubble=' + JSON.stringify(t.bubble()));
  }
  {
    const s = liveStream();
    const t = boot([() => s.response]);
    const run = t.send('Build the sheet');
    s.push(frame(1, { type: 'progress', phase: 'tool', label: 'Creating the spreadsheet…', elapsed_s: 135 }));
    await settle();
    const first = t.working();
    t.clock.now += 5000;
    t.intervals.forEach(fn => fn());
    const later = t.working();
    s.push(delta(2, 'Your sheet is ready.'));
    await settle();
    t.intervals.forEach(fn => fn());
    const afterText = t.working(), ticking = t.intervals.size;
    s.push(done(3, 'Your sheet is ready.'));
    s.close();
    await run;
    check('a `progress` frame shows its label on the working line with a clock from the phase\'s start, and the clock runs between frames',
      first === 'Creating the spreadsheet… 2:15' && later === 'Creating the spreadsheet… 2:20',
      JSON.stringify([first, later]));
    check('the line and its clock go when real content arrives',
      afterText === null && ticking === 0 && t.bubble() === 'Your sheet is ready.',
      'working=' + JSON.stringify(afterText) + ' intervals=' + ticking);
  }

  console.log('\n' + results.join('\n'));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail > 0 ? 1 : 0);
})();
