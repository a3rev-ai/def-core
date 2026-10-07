/*
 * The nonce refresh — behavioural harness (v8.7.6).
 *
 * An installed app left open overnight outlives its wp_rest nonce, and the next
 * send came back "Cookie check failed" with the message gone. Runs the SHIPPED
 * request path (wpFetch / apiRequest), the SHIPPED error banner and the SHIPPED
 * send path, sliced by marker, inside jsdom against a scripted fetch.
 *
 * 9 checks.
 */
const fs = require('fs');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const REQUEST = extract.slice('request path',
  l => l.includes('// Every request that carries the nonce'),
  l => l.includes('\t// Elements'),
  ['async function wpFetch', 'async function isNonceRefusal', 'async function apiRequest', 'function extractServerMessage'], 'NONCE_REQUEST');
const BANNER = extract.slice('error banner',
  l => l.includes('\t// Show error'),
  l => l.includes('// Smart scroll'),
  ['function showError', 'function showSessionExpired'], 'NONCE_BANNER');
const SEND = extract.slice('send path',
  l => l.startsWith('\tasync function sendMessage() {'),
  l => l.includes('// SHARE MODAL'),
  ['async function sendMessageSync', 'async function sendMessageStreaming'], 'NONCE_SEND');
const JS = fs.readFileSync(extract.JS_PATH, 'utf8');

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

const NONCE_URL = 'https://e.test/wp-admin/admin-ajax.php?action=rest-nonce';
const STREAM_URL = 'https://e.test/wp-json/def-core/v1/staff-ai/chat/stream';
const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
const expired = () => json(403, { code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed', data: { status: 403 } });
const reply = () => json(200, { choices: [{ message: { content: 'Done.' } }], thread_id: 'th-1' });

// `script` answers each request in turn: a function of (url, init) or a Response.
function boot(script, opts) {
  const dom = new JSDOM('<!doctype html><div id="errorBanner"></div><textarea id="composerInput"></textarea>',
    { url: 'https://e.test/staff-ai/' });
  const window = dom.window, document = window.document;
  const calls = [];
  const state = { reloads: 0 };
  async function fakeFetch(url, init) {
    init = init || {};
    calls.push({ url: url, nonce: (init.headers || {})['X-WP-Nonce'] });
    const next = script.shift();
    if (!next) throw new Error('unscripted request to ' + url);
    return typeof next === 'function' ? next(url, init) : next;
  }
  const deps = {
    window, document, fetch: fakeFetch, Response,
    StaffAIConfig: { nonce: 'old0000000', nonceUrl: NONCE_URL },
    t: (k, d) => d, apiBase: 'https://e.test/wp-json/def-core/v1/staff-ai', chatStreamUrl: STREAM_URL,
    errorBanner: document.getElementById('errorBanner'), composerInput: document.getElementById('composerInput'),
    location: { reload: () => { state.reloads++; } },
    ReadableStream: (opts || {}).noStream ? undefined : function () {},
    messages: [], renderMessages() {}, autoResize() {}, updateSendButton() {}, hideInfo() {},
    hasActiveFiles: () => false, stagedFiles: [], classifySuggestionOutcome: () => ({}),
    endConversation() {}, browserTimezone: () => '', clearStagedFiles() {}, readBack() {},
    loadConversations() {}, updateReadOnlyState() {}, recoverTurn: async () => false, afterSpokenTurn() {},
    removeTypingMessage() { const i = deps.messages.findIndex(m => m.isTyping); if (i >= 0) deps.messages.splice(i, 1); },
    dropUnfilledTranscript() {}, uploadAllStagedFiles: async () => ({ success: true, fileIds: [] }),
    console: { info() {}, error() {} }
  };
  const names = Object.keys(deps);
  const api = new window.Function(...names,
    'let nonce = StaffAIConfig.nonce; var isLoading = false, isReadOnly = false, conversationOn = false,' +
    ' spokenTurn = false, openingSpoken = null, lastSuggestion = null, selectedModel = "", currentConversationId = null,' +
    ' activeProjectId = null, _streamAbort = null, _turnReachedServer = false, voiceStopped = false, _eventsSeen = 0,' +
    ' _isStreaming = false, _userScrolledUp = false;\n' +
    REQUEST + '\n' + BANNER + '\n' + SEND +
    '\nreturn { sendMessage: sendMessage, apiRequest: apiRequest, nonce: function () { return nonce; } };'
  )(...names.map(k => deps[k]));
  return { window, document, api, calls, state, deps,
    type: text => { deps.composerInput.value = text; },
    banner: () => deps.errorBanner };
}

(async function () {
  let n = 0;
  {
    const t = boot([expired(), new Response('fresh11111', { status: 200 }), reply(), json(200, { ok: 1 })]);
    t.type('Morning, what is on today?');
    await t.api.sendMessage();
    const urls = t.calls.map(c => c.url);
    check(++n, 'a send refused for its nonce fetches ONE fresh nonce and the retry carries it',
      urls.join(' ') === [STREAM_URL, NONCE_URL, STREAM_URL].join(' ') &&
      t.calls[0].nonce === 'old0000000' && t.calls[2].nonce === 'fresh11111',
      JSON.stringify(t.calls));
    check(++n, 'the retried message goes: the reply lands and no error shows',
      t.deps.messages.map(m => m.role + ':' + m.content).join('|') === 'user:Morning, what is on today?|assistant:Done.' &&
      !t.banner().classList.contains('visible'),
      JSON.stringify(t.deps.messages));
    await t.api.apiRequest('/conversations');
    check(++n, 'every later request reads the refreshed nonce, and the file sets X-WP-Nonce in one place only',
      t.calls[3].nonce === 'fresh11111' && (JS.match(/'X-WP-Nonce'/g) || []).length === 1,
      'later=' + t.calls[3].nonce);
  }
  {
    const t = boot([expired(), new Response('fresh11111', { status: 200 }), expired()]);
    t.type('Draft the reply to Sam');
    await t.api.sendMessage();
    const a = t.banner().querySelector('a');
    if (a) { a.click(); }
    check(++n, 'refused again after a refresh: no third attempt, the input keeps its text, its bubble goes',
      t.calls.length === 3 && t.deps.composerInput.value === 'Draft the reply to Sam' && t.deps.messages.length === 0,
      'calls=' + t.calls.length + ' input=' + t.deps.composerInput.value + ' msgs=' + JSON.stringify(t.deps.messages));
    check(++n, 'the banner says the session expired, in our words, with a Reload link that reloads',
      t.banner().classList.contains('visible') &&
      t.banner().textContent === 'Your session has expired. Reload the page and sign in again. Reload' &&
      !/Cookie check failed/.test(t.banner().textContent) && !!a && t.state.reloads === 1,
      'banner=' + t.banner().textContent + ' reloads=' + t.state.reloads);
  }
  {
    // Logged out: admin-ajax answers "0" with a 400.
    const t = boot([expired(), new Response('0', { status: 400 })]);
    t.type('Still there?');
    await t.api.sendMessage();
    check(++n, 'a refresh that fails (logged out) retries nothing and shows the session-expired banner',
      t.calls.length === 2 && t.deps.composerInput.value === 'Still there?' &&
      /^Your session has expired\./.test(t.banner().textContent),
      'calls=' + t.calls.length + ' banner=' + t.banner().textContent);
  }
  {
    // A browser with no ReadableStream sends through sendMessageSync -> apiRequest('/chat').
    const t = boot([expired(), new Response('0', { status: 400 })], { noStream: true });
    t.type('Quick question');
    await t.api.sendMessage();
    check(++n, 'the sync fallback undoes the turn the same way: the words back in the box, no bubble, a Reload link',
      t.calls[0].url.endsWith('/staff-ai/chat') && t.deps.composerInput.value === 'Quick question' &&
      t.deps.messages.length === 0 && !!t.banner().querySelector('a'),
      'calls=' + JSON.stringify(t.calls) + ' input=' + t.deps.composerInput.value);
  }
  {
    // A 200 that is not a nonce: "0", or a login page a security plugin serves instead.
    const sent = [];
    for (const body of ['0', '<html><body>Log in</body></html>']) {
      const t = boot([expired(), new Response(body, { status: 200 })]);
      t.type('Hi');
      await t.api.sendMessage();
      sent.push(t.calls.length + ':' + t.api.nonce());
    }
    check(++n, 'a 200 that is not a nonce is never taken as one, and nothing is retried',
      sent.join(' ') === '2:old0000000 2:old0000000', sent.join(' '));
  }
  {
    const t = boot([json(403, { code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.' })]);
    t.type('Hello');
    await t.api.sendMessage();
    check(++n, 'a 403 that is not about the nonce fetches no nonce and shows its own message',
      t.calls.length === 1 && t.calls[0].url === STREAM_URL && t.api.nonce() === 'old0000000' &&
      t.banner().textContent === 'Sorry, you are not allowed to do that.',
      'calls=' + JSON.stringify(t.calls) + ' banner=' + t.banner().textContent);
  }

  console.log('\n' + results.join('\n'));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail > 0 ? 1 : 0);
})();
