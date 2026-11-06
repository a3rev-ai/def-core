/*
 * W82 — a Stop button in Staff AI (behavioural harness).
 *
 * Runs the SHIPPED button block (updateSendButton / requestStop), its click wiring, the
 * Enter handler and the send path, sliced by marker from assets/js/staff-ai.js, inside jsdom
 * against a scripted fetch that answers with real SSE byte streams. Once the stream names its
 * turn, Send is Stop; pressing it posts the stop, and it reads "Stopping…", disabled, until
 * the turn's last frame. A stopped `done` keeps the words shown and says "Stopped".
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const PARSER = extract.slice('SSE parser',
  l => l.includes('// SSE buffer parser'),
  l => l.includes('// Tool status labels for SSE streaming'),
  ['function parseSSEBuffer']);
const TOOL_LABELS = extract.slice('tool status labels',
  l => l.includes('// Tool status labels for SSE streaming'),
  l => l.includes('// HTML escape helper'),
  ['const SSE_TOOL_PACING_MS']);
const ESCAPE = extract.slice('escapeHtml',
  l => l.includes('// HTML escape helper'),
  l => l.includes('// Markdown rendering'),
  ['function escapeHtml']);
const TOOL_ROWS = extract.slice('tool rows',
  l => l.includes('SSE STREAMING UI HELPERS'),
  l => l.startsWith('\tfunction updateTypingLabel('),
  ['function renderToolStatus']);
const REQUEST = extract.slice('request path',
  l => l.includes('// Every request that carries the nonce'),
  l => l.includes('\t// Elements'),
  ['async function wpFetch']);
const BANNER = extract.slice('error banner',
  l => l.includes('\t// Show error'),
  l => l.includes('// Smart scroll'),
  ['function showError', 'function hideError']);
const RECOVER = extract.slice('recoverTurn',
  l => l.includes("// V-S1's client half (7.6.9)"),
  l => l.includes('// ── C3b: the installed app keeps up'),
  ['async function recoverTurn']);
const SEND = extract.slice('send path',
  l => l.startsWith('\tasync function sendMessage() {'),
  l => l.includes('// SHARE MODAL'),
  ['async function sendMessageStreaming', 'stopTurn = {', 'stopTurn = null', 'function appendStoppedNote',
    'appendStoppedNote(streamEl)'], 'STOP_SEND');
const BUTTON = extract.slice('the Stop button',
  l => l.includes('// Update send button state.'),
  l => l.includes('// The Stop button end'),
  ['function updateSendButton', 'async function requestStop', "'/stop'"], 'STOP_BUTTON');
const KEYS = extract.slice('Enter handler',
  l => l.includes('// Keyboard handler: Enter to send'),
  l => l.includes('// Click in input clears suggestion'),
  ["e.key === 'Enter'"]);
const CLICK = extract.slice('send button click',
  l => l.startsWith("\tsendBtn.addEventListener('click'"),
  l => l.includes('// Show info'),
  ['requestStop()']);

const BASE = 'https://e.test/wp-json/def-core/v1/staff-ai';
const STREAM_URL = BASE + '/chat/stream';
const STOP_URL = BASE + '/conversations/th-1/turns/msg-1/stop';
const resumeUrl = after => BASE + '/conversations/th-1/turns/msg-1/stream?after=' + after;

const frame = (seq, evt) => 'id: ' + seq + '\ndata: ' + JSON.stringify(Object.assign({ thread_id: 'th-1', turn_id: 'msg-1' }, evt)) + '\n\n';
const delta = (seq, text) => frame(seq, { type: 'text_delta', text: text, step: 1 });
const done = (seq, text, stopped) => frame(seq, Object.assign({ type: 'done', choices: [{ message: { content: text, tool_outputs: [] } }] },
  stopped ? { stopped: true } : {}));

function sse(chunks) {
  const enc = new TextEncoder();
  let i = 0;
  return new Response(new ReadableStream({
    pull(c) { if (i < chunks.length) c.enqueue(enc.encode(chunks[i++])); else c.close(); }
  }), { status: 200, headers: { 'content-type': 'text/event-stream' } });
}
function liveStream() {
  const enc = new TextEncoder();
  let ctrl;
  const response = new Response(new ReadableStream({ start(c) { ctrl = c; } }),
    { status: 200, headers: { 'content-type': 'text/event-stream' } });
  return { response, push: s => ctrl.enqueue(enc.encode(s)), close: () => ctrl.close() };
}
// The proxy's own frame for a 410: DEF no longer holds the turn on the worker reached.
const gone = 'data: ' + JSON.stringify({ type: 'error', message: 'The assistant is unavailable right now. Please try again.', status: 410 }) + '\n\n';
const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });

const HEAD = `
	let nonce = StaffAIConfig.nonce;
	let messages = [], isLoading = false, isReadOnly = false, conversationOn = false, spokenTurn = false,
		openingSpoken = null, lastSuggestion = null, selectedModel = '', currentConversationId = null,
		activeProjectId = null, voiceStopped = false, _isStreaming = false, _userScrolledUp = false,
		dirtyInput = false, chipEpoch = 0, readbackBuffer = '';
	function t(k, d) { return d; }
	function renderMessages() {
		messagesList.querySelectorAll('.message').forEach(function (el) { el.remove(); });
		messages.forEach(function (m) {
			var msg = document.createElement('div');
			msg.className = 'message message-' + m.role;
			var c = document.createElement('div');
			c.className = 'message-content';
			if (m.isTyping) c.innerHTML = '<div class="typing-indicator"></div>'; else c.textContent = m.content || '';
			if (m.stopped) appendStoppedNote(c);
			msg.appendChild(c);
			messagesList.appendChild(msg);
		});
	}
	function removeTypingMessage() {
		for (var i = messages.length - 1; i >= 0; i--) { if (messages[i].isTyping) { messages.splice(i, 1); return; } }
	}
`;
const TAIL = `
	return { sendMessage: sendMessage, state: function () { return { isLoading: isLoading, messages: messages }; } };`;

function boot(route) {
  const dom = new JSDOM('<!doctype html><div id="messagesContainer"><div id="messagesList"></div></div>' +
    '<div id="errorBanner"></div><div id="infoBanner"></div><div id="confirmChips"></div>' +
    '<textarea id="composerInput"></textarea><button id="sendBtn" disabled aria-label="Send message"></button>',
    { url: 'https://e.test/staff-ai/' });
  const window = dom.window, document = window.document;
  window.DefPersona = { createController: () => ({ handleEvent() {}, formatThinkingLabel: m => m, reset() {} }) };
  const calls = [];
  async function fakeFetch(url, init) {
    calls.push({ url, method: (init && init.method) || 'GET' });
    return route(url, init);
  }
  const $ = id => document.getElementById(id);
  const deps = {
    window, document, fetch: fakeFetch, Response, ReadableStream, TextDecoder, AbortController,
    setTimeout: fn => setTimeout(fn, 0), setInterval: () => 0, clearInterval() {},
    StaffAIConfig: { nonce: 'n0000000', nonceUrl: 'https://e.test/wp-admin/admin-ajax.php?action=rest-nonce' },
    apiBase: BASE, chatStreamUrl: STREAM_URL, location: { reload() {} },
    messagesList: $('messagesList'), messagesContainer: $('messagesContainer'), errorBanner: $('errorBanner'),
    confirmChips: $('confirmChips'), composerInput: $('composerInput'), sendBtn: $('sendBtn'),
    showInfo: msg => { $('infoBanner').textContent = msg; },
    autoResize() {}, hideInfo() {}, hasActiveFiles: () => false, stagedFiles: [],
    classifySuggestionOutcome: () => ({}), endConversation() {}, browserTimezone: () => '', clearStagedFiles() {},
    readBack() {}, readBackSoFar() {}, loadConversations() {}, updateReadOnlyState() {}, afterSpokenTurn() {},
    dropUnfilledTranscript() { return false; }, uploadAllStagedFiles: async () => ({ success: true, fileIds: [] }),
    renderMarkdown: s => s, speaker: { speak() {} },
    applyCitations() {}, buildCitationMap: () => ({}), createToolOutputCard: () => null, handleSpokenStop: () => false,
    console: { info() {}, error() {} }
  };
  const names = Object.keys(deps);
  const api = new window.Function(...names, HEAD + PARSER + '\n' + TOOL_LABELS + '\n' + ESCAPE + '\n' + REQUEST + '\n' +
    BANNER + '\n' + RECOVER + '\n' + TOOL_ROWS + '\n' + BUTTON + '\n' + KEYS + '\n' + CLICK + '\n' + SEND + TAIL)(...names.map(k => deps[k]));
  const btn = $('sendBtn'), list = $('messagesList');
  return {
    api, calls,
    send: text => { $('composerInput').value = text; return api.sendMessage(); },
    press: () => btn.click(),
    enter: text => {
      $('composerInput').value = text;
      $('composerInput').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    },
    button: () => ({ stop: btn.classList.contains('is-stop'), label: btn.getAttribute('aria-label'), disabled: btn.disabled }),
    bubble: () => { const b = list.querySelectorAll('.message-assistant .message-content'); return b.length ? b[b.length - 1] : null; },
    banner: () => $('errorBanner').classList.contains('visible') ? $('errorBanner').textContent : '',
    info: () => $('infoBanner').textContent,
    stops: () => calls.filter(c => c.url === STOP_URL),
  };
}
const settle = async () => { for (let i = 0; i < 10; i++) await new Promise(r => setTimeout(r, 2)); };
const note = el => { const n = el && el.querySelector('.message-stopped-note'); return n ? n.textContent : null; };
const words = el => el ? Array.prototype.filter.call(el.childNodes, n => n.nodeType === 3).map(n => n.textContent).join('') : null;

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(pass + fail).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(pass + fail).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

(async function () {
  {
    const live = liveStream();
    const t = boot(url => url === STREAM_URL ? live.response : json(200, { state: 'stopping' }));
    t.send('Write the report');
    await settle();
    const beforeTurn = t.button();
    live.push(delta(1, 'The report '));
    await settle();
    const running = t.button();
    t.enter('another message');
    await settle();
    check('Send, then Stop once the stream names its turn; Enter while it runs neither sends nor stops',
      !beforeTurn.stop && beforeTurn.disabled && running.stop && running.label === 'Stop' && !running.disabled &&
      t.calls.length === 1,
      JSON.stringify({ beforeTurn, running, calls: t.calls.map(c => c.url) }));
    t.press();
    await settle();
    const stopping = t.button();
    t.press();   // a second press while stopping does nothing
    await settle();
    check('pressing Stop posts the stop for this turn, and the button reads "Stopping…", disabled',
      t.stops().length === 1 && t.stops()[0].method === 'POST' && stopping.stop && stopping.label === 'Stopping…' && stopping.disabled,
      JSON.stringify({ stopping, calls: t.calls }));
    live.push(done(2, 'The report ', true));
    live.close();
    await settle();
    const after = t.button();
    check('the stopped `done` keeps what she wrote with a small "Stopped" note, no error; the button is Send again',
      words(t.bubble()) === 'The report' && note(t.bubble()) === 'Stopped' && t.banner() === '' &&
      !after.stop && after.label === 'Send message' && !t.api.state().isLoading &&
      t.api.state().messages.filter(m => m.role === 'assistant')[0].stopped === true,
      JSON.stringify({ words: words(t.bubble()), note: note(t.bubble()), banner: t.banner(), after }));
  }
  for (const failure of ['500', 'network']) {
    const live = liveStream();
    const t = boot(url => url === STREAM_URL ? live.response
      : failure === '500' ? json(500, { detail: 'down' }) : Promise.reject(new TypeError('Failed to fetch')));
    t.send('Write the report');
    live.push(delta(1, 'The report '));
    await settle();
    t.press();
    await settle();
    const back = t.button();
    live.push(delta(2, 'is done.') + done(3, 'The report is done.'));
    live.close();
    await settle();
    check('a failed stop post (' + failure + ') goes back to Stop with a toast; the turn then ends as usual',
      back.stop && back.label === 'Stop' && !back.disabled && t.info() === "Couldn't stop the reply. Try again." &&
      note(t.bubble()) === null && words(t.bubble()) === 'The report is done.',
      JSON.stringify({ back, info: t.info(), words: words(t.bubble()) }));
  }
  {
    // Stopped before she wrote a word: DEF's own sentence, never the error.
    const t = boot(url => url === STREAM_URL
      ? sse([frame(1, { type: 'thinking', message: 'Thinking...' }), done(2, 'You stopped this reply.', true)])
      : json(200, {}));
    t.send('Write the report');
    await settle();
    check('a stop before any text shows DEF\'s sentence with the note, never the error',
      words(t.bubble()) === 'You stopped this reply.' && note(t.bubble()) === 'Stopped' && t.banner() === '',
      JSON.stringify({ words: words(t.bubble()), note: note(t.bubble()), banner: t.banner() }));
  }
  {
    // The stream drops after the stop; the re-attach brings the stopped `done`.
    const t = boot(url => url === STREAM_URL ? sse([delta(1, 'The report ')])
      : url === resumeUrl(1) ? sse([done(2, 'The report ', true)]) : json(200, {}));
    t.send('Write the report');
    await settle();
    check('a stopped `done` that arrives on a re-attach keeps the note',
      t.calls.some(c => c.url === resumeUrl(1)) && words(t.bubble()) === 'The report' && note(t.bubble()) === 'Stopped',
      JSON.stringify({ calls: t.calls.map(c => c.url), note: note(t.bubble()) }));
  }
  {
    // The re-attach reaches a worker without the turn (410): the console renders the thread.
    const t = boot(url => url === STREAM_URL ? sse([delta(1, 'The report ')])
      : url.indexOf('/turns/') !== -1 ? sse([gone])
      : url === BASE + '/conversations/th-1' ? json(200, { success: true, id: 'th-1', messages: [
        { role: 'user', content: 'Write the report' },
        { role: 'assistant', content: 'The report', is_complete: true, stopped: true }] })
      : json(200, {}));
    await t.send('Write the report');
    await settle();
    check('a stopped reply read from the thread after a 410 re-attach keeps the note',
      t.calls.some(c => c.url === BASE + '/conversations/th-1') && words(t.bubble()) === 'The report' &&
      note(t.bubble()) === 'Stopped' && t.banner() === '',
      JSON.stringify({ calls: t.calls.map(c => c.url), words: words(t.bubble()), note: note(t.bubble()), banner: t.banner() }));
  }

  console.log('Staff AI Stop button (W82)');
  results.forEach(r => console.log(r));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
