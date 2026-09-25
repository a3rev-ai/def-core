/*
 * Customer Chat — what a screen reader hears (v8.7.3), 32 checks.
 *
 * Runs the SHIPPED chat module (assets/js/def-core-customer-chat.js), whole, in a
 * jsdom page with the shipped marked, DOMPurify and product-card renderer, over a
 * fake network: a context token, server threads, the login endpoint, the cart,
 * and the chat itself — answered as JSON (the sync path) or as an SSE stream the
 * check feeds one event at a time.
 *
 * It watches the announcer with a MutationObserver from before init(), so what it
 * counts is every write, not the final attributes.
 *
 * Its scope is the chat MODULE, not the page around it. The test host is a bare
 * div, and regionOf finds a node's region by Chrome's rule — the nearest aria-live
 * wins, and "off" ends the walk — so by design it cannot see a live region outside
 * the module wrapping the log (WebKit does not stop at "off"). That the loader adds
 * none — no aria-live on its host, nothing live above the log — is
 * harness-cc-a11y.js's (#39–40), over the real loader. What it holds:
 *  - The visible log is role="log" and explicitly aria-live="off"; within the
 *    module the one live region is the announcer: polite, visually hidden, last
 *    in the panel, empty.
 *  - Startup (banner, greeting, chips, a site's opening message, the offline
 *    notice), a local thread restored, a server thread restored on a logged-in
 *    load or adopted after logging in, the logged-out-elsewhere reset and New
 *    conversation write NOTHING to it.
 *  - A new message is written once, as the words its bubble shows: the visitor's
 *    (typed, a chip, spoken once its transcript arrives), a non-streamed reply,
 *    a streamed reply only when the stream ends (done, error, or cut off) — never
 *    its start, its tokens, a superseded round, a tool row — and each notice
 *    raised mid-conversation (an error, rate limit, the cart's toast and link,
 *    Welcome back, logged out, Conversation ended).
 *  - Product cards are drawn and not announced; a bubble cleared while it
 *    streamed says nothing when its done arrives; a turn starts by emptying the
 *    announcer; no line is ever written twice or edited.
 *
 * The writes counted are those into ANY live region, so 8.7.2 — no announcer, a
 * live log — is caught saying its greeting, its restores and every token rather
 * than passing for having nothing to write to: against it this fails 31 of the
 * 32. The one left green (#5) reads the stylesheet, whose .def-cc-sr-only rule
 * was already there, unused. Fifteen one-line mutants of the 8.7.3 module (a
 * restore spoken, tokens spoken, the "Transcribing…" placeholder spoken, done
 * silent, the final reply said twice, the turn reset or the clear dropped, the
 * detached-bubble guard dropped, bare textContent for the words, the log made
 * live, and each announce() removed in turn) are each caught by the checks
 * written for them — the list is in the PR body.
 *
 * Bite: CC_MODULE=/path/to/old-chat.js node tests/browser/harness-cc-announce.js
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const MODULE = extract.customerChatSource();
// What the page has loaded before the module: the persona controller (enqueued by
// PHP), then what the loader chains in — marked, DOMPurify, the product cards.
const VENDOR = ['assets/js/def-persona.js', 'assets/js/vendor/marked.min.js', 'assets/js/vendor/purify.min.js',
  'assets/js/def-core-product-cards.js'].map(f => fs.readFileSync(path.join(extract.REPO, f), 'utf8'));
const CSS = fs.readFileSync(path.join(extract.REPO, 'assets/css/def-core-customer-chat.css'), 'utf8');

const BASE = {
  apiBaseUrl: 'https://api.example.test',
  chatStreamUrl: 'https://shop.test/wp-json/def-core/v1/chat/stream',
  wpRestUrl: 'https://shop.test/wp-json/def-core/v1/',
  wpRestRoot: 'https://shop.test/wp-json/',
  restUrl: 'https://shop.test/wp-json/def-core/v1/context-token',
  loginUrl: 'https://shop.test/wp-admin/admin-ajax.php',
  nonce: 'n', wpRestNonce: 'n',
  assistantName: 'Joe',
  isLoggedIn: false,
};

function jwt(payload) {
  const b64 = s => Buffer.from(JSON.stringify(s)).toString('base64');
  return b64({ alg: 'none' }) + '.' + b64(Object.assign({ exp: Math.floor(Date.now() / 1000) + 3600 }, payload)) + '.sig';
}

const sleep = ms => new Promise(r => setTimeout(r, ms));

async function boot(opts) {
  opts = opts || {};
  const dom = new JSDOM('<!doctype html><html><body><div id="host"></div></body></html>',
    { url: 'https://shop.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  // Time runs fast: the word drain, the stream's pacing and the focus delays
  // shrink to a millisecond. Long timers (a toast's life, the token refresh) keep theirs.
  const realST = w.setTimeout.bind(w);
  w.setTimeout = (fn, ms) => realST(fn, (ms || 0) >= 1000 ? ms : Math.min(ms || 0, 1));
  for (const src of VENDOR) w.eval(src);
  if (opts.stream) w.ReadableStream = function () {};   // the module only asks typeof
  for (const k of Object.keys(opts.storage || {})) w.localStorage.setItem(k, opts.storage[k]);

  const h = { w, doc: w.document, streams: [], log: [], serverThreads: opts.serverThreads || [], calls: [] };
  const cfg = Object.assign({}, BASE, opts.config || {});
  if (opts.offline) delete cfg.apiBaseUrl;

  function res(body, status) {
    status = status || 200;
    return Promise.resolve({
      ok: status >= 200 && status < 300, status,
      headers: { get: () => null },
      json: () => Promise.resolve(body),
      text: () => Promise.resolve(JSON.stringify(body)),
    });
  }
  function makeStream() {
    const enc = new w.TextEncoder();
    const queue = []; let waiting = null; let ended = false;
    function flush() {
      if (!waiting) return;
      const p = waiting;
      if (queue.length) { waiting = null; p({ done: false, value: queue.shift() }); }
      else if (ended) { waiting = null; p({ done: true }); }
    }
    const s = {
      reader: { read: () => new Promise(r => { waiting = r; flush(); }) },
      send(evt) { queue.push(enc.encode('data: ' + JSON.stringify(evt) + '\n\n')); flush(); },
      end() { ended = true; flush(); },
    };
    h.streams.push(s);
    return s;
  }
  w.fetch = function (url, init) {
    url = String(url);
    h.calls.push(url);
    if (url === cfg.chatStreamUrl) {
      if (h.chatHold) return new Promise(() => {});
      if (h.chatFail) return Promise.reject(new TypeError('Failed to fetch'));
      if (!opts.stream) return res(h.syncReply || {}, h.syncStatus || 200);
      const s = makeStream();
      return Promise.resolve({ ok: true, status: 200, body: { getReader: () => s.reader } });
    }
    if (url === cfg.restUrl) return res({ token: jwt({ first_name: opts.firstName || 'Sam' }) });
    if (url.indexOf('/api/my/threads') !== -1) return res({ threads: h.serverThreads });
    if (url.indexOf('/claim') !== -1) return res({});
    if (url === cfg.loginUrl) return res({ success: true, data: { token: jwt({ first_name: opts.firstName || 'Sam' }) } });
    if (url.indexOf('employee-tools') !== -1) return res({ tools: [] });
    if (url === cfg.wpRestUrl + 'chat/voice') return res({ enabled: !!opts.voice });
    if (url.indexOf('wc/store/v1/cart/add-item') !== -1) {
      return res({ items: [{ id: 5, name: 'Cordless Drill', key: 'k1' }], items_count: 1,
        totals: { total_price: '9900', total_items: '9900', currency_code: 'AUD', currency_minor_unit: 2 } });
    }
    return res({});
  };

  if (opts.voice) {
    h.voice = { opts: null, stop: false };
    w.DefVoice = {
      supported: () => true,
      micAllowedBySite: () => true,
      createSpeaker: () => ({ stop() {}, unlock() {}, speak() {}, whenIdle: () => new Promise(() => {}) }),
      createRecorder: o => {
        h.voice.opts = o;
        return {
          start: () => Promise.resolve(true), isRecording: () => false, release() {},
          stop: () => Promise.resolve({ blob: {}, seconds: 2, spoke: true, mime: 'audio/webm' }),
        };
      },
      toBase64: () => Promise.resolve('AAAA'),
      isStopPhrase: () => h.voice.stop,
      log: () => [],
    };
  }

  const host = w.document.getElementById('host');
  const root = host.attachShadow({ mode: 'open' });
  root.innerHTML = '<div class="def-cc-panel"><button class="def-cc-panel-close" aria-label="Close chat"></button></div>';
  h.root = root;
  h.panel = root.querySelector('.def-cc-panel');
  h.messages = () => root.querySelector('.def-cc-messages');
  // The announcer, found by what it is rather than its class: the live region in
  // the panel that is not the (silent) log. The old module has none.
  h.liveRegions = () => Array.from(h.panel.querySelectorAll('[aria-live]'))
    .filter(n => n.getAttribute('aria-live') !== 'off');
  h.announcer = () => h.liveRegions().filter(n => !n.classList.contains('def-cc-messages'))[0] || null;

  // Every write into ANY live region, from before init() — not only the
  // announcer's, so a module whose log is live (8.7.2) is caught speaking a
  // restore rather than passing for having no announcer to write to. A node's
  // region is its nearest aria-live (off = none) or implicitly live role.
  const LIVE_ROLES = { log: 1, status: 1, alert: 1 };
  const regionOf = node => {
    for (let n = node.nodeType === 1 ? node : node.parentNode; n && n.nodeType === 1; n = n.parentNode) {
      const live = n.getAttribute('aria-live');
      if (live) return live === 'off' ? null : n;
      if (LIVE_ROLES[n.getAttribute('role')]) return n;
    }
    return null;
  };
  const collect = records => {
    for (const r of records) {
      if (!regionOf(r.target)) continue;
      if (r.type === 'childList') {
        for (const n of r.addedNodes) h.log.push({ kind: 'add', text: n.textContent });
        for (const n of r.removedNodes) h.log.push({ kind: 'remove', text: n.textContent });
      } else {
        h.log.push({ kind: 'edit', text: r.target.data });
      }
    }
  };
  const mo = new w.MutationObserver(collect);
  mo.observe(root, { subtree: true, childList: true, characterData: true });
  h.flush = () => collect(mo.takeRecords());
  h.said = () => { h.flush(); return h.log.filter(e => e.kind === 'add').map(e => e.text); };
  h.records = () => { h.flush(); return h.log.slice(); };
  h.mark = () => { h.flush(); return h.log.length; };
  h.saidSince = m => { h.flush(); return h.log.slice(m).filter(e => e.kind === 'add').map(e => e.text); };
  h.lines = () => { const a = h.announcer(); return a ? Array.from(a.childNodes).map(n => n.textContent) : null; };
  // Empty, and there: no announcer at all is a failure, not an empty one.
  h.announcerEmpty = () => { const l = h.lines(); return !!l && l.length === 0; };

  // The bubbles on show: the greeting counts until a message hides it.
  h.bubbles = () => Array.from(root.querySelectorAll('.def-cc-message:not(.def-cc-message--thinking)'))
    .filter(m => m.style.display !== 'none');
  h.bubbleTexts = () => h.bubbles().map(m => m.querySelector('.def-cc-message-content').textContent.replace(/\s+/g, ' ').trim());
  h.input = () => root.querySelector('.def-cc-composer-input');
  h.send = async text => {
    h.input().value = text;
    h.input().dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    await sleep(30);
  };
  h.stream = () => h.streams[h.streams.length - 1];
  h.event = async (evt, ms) => { h.stream().send(evt); await sleep(ms || 40); };
  h.newConversation = async () => {
    root.querySelector('.def-cc-header-refresh').click();
    root.querySelector('.def-cc-confirm-btn--danger').click();
    await sleep(20);
  };

  w.eval(MODULE);
  w.DEFCustomerChat.init(root, Object.assign({}, cfg));
  await sleep(opts.settle || 40);
  return h;
}

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const show = v => JSON.stringify(v);

const LOCAL_THREAD = [
  { role: 'user', content: 'Do you sell drills?' },
  { role: 'assistant', content: 'We do — **three** cordless models.' },
  { role: 'user', content: 'Show me one' },
  { role: 'assistant', content: 'Here it is:', tool_outputs: [{ result_type: 'wp_product', section_heading: 'Drills',
    result_cards: [{ id: 5, title: 'Cordless Drill', url: 'https://shop.test/p/drill' }] }] },
];
const RESTORE_STORAGE = { a3rev_thread_id: 'thr-local', a3rev_threads: JSON.stringify([{ id: 'thr-local', messages: LOCAL_THREAD }]) };
const SERVER_THREADS = [{ id: 'thr-server', updatedAt: '2026-09-20T10:00:00Z', messages: [
  { role: 'user', content: 'Where is my order?' },
  { role: 'assistant', content: 'Order #1042 shipped on Monday.' },
] }];
const WELCOME = {
  heroImageUrl: 'https://shop.test/banner.png',
  welcomeChip1: 'Opening hours', welcomeChip2: 'Returns',
  welcomeChip2Intro: 'Happy to help with a return — what did you buy?', welcomeChip3: 'Delivery',
};

(async function () {
  let h, m, a, s;

  // ── Startup ────────────────────────────────────────────────────────────
  h = await boot({ config: WELCOME });
  const log = h.messages();
  check(1, 'the visible log keeps role="log", aria-relevant="additions", tabindex="0", aria-label="Conversation" and is explicitly aria-live="off"',
    log && log.getAttribute('role') === 'log' && log.getAttribute('aria-live') === 'off' &&
    log.getAttribute('aria-relevant') === 'additions' && log.getAttribute('tabindex') === '0' &&
    log.getAttribute('aria-label') === 'Conversation', log ? log.outerHTML.slice(0, 200) : 'no log');

  a = h.announcer();
  const focusables = a ? a.querySelectorAll('a[href], button, input, textarea, select, [tabindex]') : [];
  check(2, 'within the module, the one live region is the announcer: aria-live="polite", visually hidden (def-cc-sr-only), outside the log, last in the panel, not a Tab stop',
    h.liveRegions().length === 1 && !!a && a.getAttribute('aria-live') === 'polite' &&
    a.classList.contains('def-cc-sr-only') && !log.contains(a) && h.panel.lastElementChild === a &&
    !a.hasAttribute('tabindex') && a.tabIndex < 0 && focusables.length === 0,
    'live regions=' + h.liveRegions().map(n => n.className).join(','));

  const greetingShown = h.root.querySelector('.def-cc-welcome-banner') && h.root.querySelector('.def-cc-welcome-chips') &&
    h.bubbleTexts()[0].indexOf("Hi! I'm Joe, your AI Assistant.") === 0;
  const startupRecords = h.records().length;
  let h2 = await boot({ config: { openingMessage: 'Welcome to the shop.\nAsk me anything.' } });
  check(3, 'startup — banner, the built-in greeting, three chips; or a site\'s opening message — is in the log and wrote nothing to the announcer',
    greetingShown && a && a.childNodes.length === 0 && startupRecords === 0 &&
    h2.bubbleTexts()[0] === 'Welcome to the shop.Ask me anything.' && h2.records().length === 0 &&
    h2.announcer() && h2.announcer().childNodes.length === 0,
    'records=' + startupRecords + '/' + h2.records().length + ' lines=' + show(h.lines()));

  h = await boot({ offline: true });
  check(4, 'the offline widget\'s notice is startup too: shown, not announced',
    !!h.root.querySelector('.def-cc-offline') && h.announcer() && h.records().length === 0 && h.announcerEmpty(),
    'records=' + show(h.records()));

  const rules = extract.cssRules(CSS);
  const body = (extract.ruleBody(CSS, '.def-cc-sr-only') || '').replace(/\s+/g, ' ');
  const touching = rules.rules.filter(r => /\.def-cc-(sr-only|announcer)\b/.test(r.selector)).map(r => r.selector);
  check(5, 'the stylesheet hides it without taking space: absolute, 1px, -1px margin, clipped, no overflow — and no other rule restyles it',
    /position: absolute;/.test(body) && /width: 1px;/.test(body) && /height: 1px;/.test(body) &&
    /margin: -1px;/.test(body) && /overflow: hidden;/.test(body) && /clip: rect\(0, 0, 0, 0\);/.test(body) &&
    /white-space: nowrap;/.test(body) && /border: 0;/.test(body) && /padding: 0;/.test(body) &&
    same(touching, ['.def-cc-sr-only']), 'rule=' + body + ' selectors=' + show(touching));

  // ── A local thread restored ────────────────────────────────────────────
  h = await boot({ storage: RESTORE_STORAGE, config: WELCOME });
  check(6, 'a local thread restored at init: its four messages and product card are shown, the log stays off, the announcer is never written',
    same(h.bubbleTexts(), ['Do you sell drills?', 'We do — three cordless models.', 'Show me one', 'Here it is:']) &&
    !!h.root.querySelector('.def-cc-result-section') && h.messages().getAttribute('aria-live') === 'off' &&
    h.records().length === 0 && h.announcerEmpty(), 'bubbles=' + show(h.bubbleTexts()) + ' records=' + show(h.records()));

  h = await boot({ storage: Object.assign({ 'def:auth_state': 'logged_in' }, RESTORE_STORAGE) });
  check(7, 'logged out outside the widget: the init-time reset (clearConversation) is silent',
    h.bubbleTexts().length === 1 && h.records().length === 0, 'bubbles=' + show(h.bubbleTexts()) + ' records=' + show(h.records()));

  // ── Server / login restore ─────────────────────────────────────────────
  h = await boot({ config: { isLoggedIn: true }, serverThreads: SERVER_THREADS, settle: 80 });
  check(8, 'a logged-in load: the server thread is adopted and rendered, and nothing is announced',
    same(h.bubbleTexts(), ['Where is my order?', 'Order #1042 shipped on Monday.']) && h.records().length === 0,
    'bubbles=' + show(h.bubbleTexts()) + ' records=' + show(h.records()));

  h = await boot({ serverThreads: SERVER_THREADS, firstName: "O'Brien" });
  h.root.querySelector('.def-cc-menu-item:nth-child(2)').click();   // Log in
  const inputs = h.root.querySelectorAll('.def-cc-login-input');
  inputs[0].value = 'obrien'; inputs[1].value = 'secret';
  h.root.querySelector('.def-cc-login-submit').click();
  await sleep(80);
  const welcome = "Welcome back, O'Brien! How can I help you?";
  check(9, 'logging in: "Welcome back" is a NEW notice, said once in the words its bubble shows (the source carried &#039;); the thread adopted after it is rendered, not said',
    same(h.said(), [welcome]) && h.bubbleTexts().indexOf(welcome) !== -1 &&
    h.bubbleTexts().indexOf('Order #1042 shipped on Monday.') !== -1, 'said=' + show(h.said()) + ' bubbles=' + show(h.bubbleTexts()));

  // ── New conversation ───────────────────────────────────────────────────
  h = await boot({ storage: RESTORE_STORAGE, config: WELCOME });
  await h.newConversation();
  check(10, 'New conversation from a restored thread: messages gone, greeting, banner and chips back, log off, the announcer never written',
    h.bubbleTexts().length === 1 && h.bubbleTexts()[0].indexOf("Hi! I'm Joe") === 0 &&
    h.root.querySelector('.def-cc-welcome-chips').style.display === '' &&
    h.root.querySelector('.def-cc-welcome-banner').style.display === '' &&
    !h.root.querySelector('.def-cc-result-section') && h.messages().getAttribute('aria-live') === 'off' &&
    h.records().length === 0, 'bubbles=' + show(h.bubbleTexts()) + ' records=' + show(h.records()));

  h = await boot({ stream: true });
  await h.send('Hello there');
  await h.event({ type: 'text_delta', text: 'Hi! How can I help?' });
  await h.event({ type: 'done', thread_id: 'thr-1', choices: [{ message: { content: 'Hi! How can I help?' } }] });
  h.stream().end(); await sleep(40);
  m = h.mark();
  await h.newConversation();
  const afterClear = h.records().slice(m);
  check(11, 'New conversation after a live turn: the announcer is emptied by removals only — nothing added, nothing edited',
    afterClear.length > 0 && afterClear.every(e => e.kind === 'remove') && h.announcerEmpty(),
    'records=' + show(afterClear));

  // ── The visitor's message ──────────────────────────────────────────────
  h = await boot({ stream: true });
  h.chatHold = true;
  await h.send('<b>hi</b> & bye');
  check(12, 'a typed message is appended and said exactly once, as the literal text the bubble shows',
    same(h.bubbleTexts().slice(-1), ['<b>hi</b> & bye']) && same(h.said(), ['<b>hi</b> & bye']),
    'said=' + show(h.said()));

  h = await boot({ config: WELCOME });
  h.chatHold = true;
  h.root.querySelectorAll('.def-cc-welcome-chip')[1].click();   // Returns — has an intro
  await sleep(20);
  const introSaid = h.said();
  h = await boot({ config: WELCOME });
  h.chatHold = true;
  h.root.querySelectorAll('.def-cc-welcome-chip')[0].click();   // Opening hours — sent as the visitor
  await sleep(30);
  check(13, 'a chip is never said as a chip: tapped, its intro is said once as the new assistant message, or its text once as the visitor\'s',
    same(introSaid, ['Happy to help with a return — what did you buy?']) && same(h.said(), ['Opening hours']),
    'intro=' + show(introSaid) + ' sent=' + show(h.said()));

  // ── A non-streamed reply ───────────────────────────────────────────────
  h = await boot();
  // A <br> (marked's breaks: true) has no text of its own: bare textContent would
  // say "a linksecond line". The list items are blocks, the image speaks its alt.
  h.syncReply = { thread_id: 'thr-9', choices: [{ message: {
    content: '**Bold** and [a link](https://shop.test/x)\nsecond line\n\n- one\n- two\n\n![A red drill](https://shop.test/d.png)' } }] };
  await h.send('Format test');
  const syncBubble = h.bubbles().slice(-1)[0];
  check(14, 'a non-streamed reply (the sync path) is said once, as the words its bubble shows — no markdown, no markup, a <br> and blocks as breaks, an image as its alt',
    syncBubble && !!syncBubble.querySelector('a[href="https://shop.test/x"]') && !!syncBubble.querySelector('br') &&
    same(h.said(), ['Format test', 'Bold and a link second line one two A red drill']), 'said=' + show(h.said()));

  h = await boot({ stream: true });
  await h.send('Quick one');
  await h.event({ type: 'done', thread_id: 'thr-2', choices: [{ message: { content: 'Short answer.' } }] });
  h.stream().end(); await sleep(40);
  check(15, 'a done that streamed no text (the non-streamed fallback) is said once, through appendMessage',
    same(h.said(), ['Quick one', 'Short answer.']) && same(h.bubbleTexts().slice(-1), ['Short answer.']),
    'said=' + show(h.said()));

  // ── A streamed reply ───────────────────────────────────────────────────
  h = await boot({ stream: true });
  await h.send('Do you have drills?');
  m = h.mark();
  await h.event({ type: 'thinking', message: 'Looking into it…' });
  await h.event({ type: 'tool_start', tool: 'search' });
  await h.event({ type: 'tool_done', tool: 'search', status: 'success' });
  check(16, 'the stream\'s start — the thinking row and a tool\'s row, running and done — writes nothing',
    h.saidSince(m).length === 0 && !!h.root.querySelector('.cc-tool-status'), 'said=' + show(h.saidSince(m)));

  await h.event({ type: 'text_delta', text: 'We have ' });
  await h.event({ type: 'text_delta', text: '**three** cordless ' });
  const streaming = h.root.querySelector('.def-cc-message--streaming .def-cc-message-content');
  const partialShown = streaming && streaming.textContent.indexOf('We have') === 0;
  await h.event({ type: 'text_delta', text: 'models in stock.' });
  check(17, 'tokens stream into the visible bubble and write nothing',
    partialShown && h.saidSince(m).length === 0, 'said=' + show(h.saidSince(m)));

  await h.event({ type: 'done', thread_id: 'thr-3', choices: [{ message: { content: 'We have **three** cordless models in stock.' } }] });
  const finalBubble = h.bubbles().slice(-1)[0];
  check(18, 'done: exactly one write, the final bubble\'s words, and the bubble is no longer streaming',
    same(h.saidSince(m), ['We have three cordless models in stock.']) &&
    finalBubble && !finalBubble.classList.contains('def-cc-message--streaming') &&
    finalBubble.textContent.replace(/\s+/g, ' ').trim() === 'We have three cordless models in stock.',
    'said=' + show(h.saidSince(m)));

  h.stream().end();
  await sleep(80);
  check(19, 'the reader\'s end, the drain and the completion pass after done add nothing: said once, not twice',
    same(h.saidSince(m), ['We have three cordless models in stock.']) &&
    h.w.localStorage.getItem('a3rev_thread_id') === 'thr-3', 'said=' + show(h.saidSince(m)));

  h = await boot({ stream: true });
  await h.send('How many in stock?');
  m = h.mark();
  await h.event({ type: 'text_delta', text: 'Checking the stock now.', step: 1 });
  await h.event({ type: 'tool_start', tool: 'search' });
  await h.event({ type: 'tool_done', tool: 'search', status: 'success' });
  await h.event({ type: 'step_superseded', step: 1 });
  await h.event({ type: 'text_delta', text: 'We have 4 in stock.', step: 2 });
  await h.event({ type: 'done', thread_id: 'thr-4', choices: [{ message: { content: 'We have 4 in stock.' } }] });
  check(20, 'a superseded round (moved to the working line) is never said; the reply is said once as it finally reads',
    same(h.saidSince(m), ['We have 4 in stock.']), 'said=' + show(h.saidSince(m)));

  h = await boot({ stream: true });
  await h.send('Tell me everything');
  m = h.mark();
  await h.event({ type: 'text_delta', text: 'Partial answ' });
  await h.event({ type: 'error', message: 'The assistant hit a problem.' });
  check(21, 'an error event after text: the reply as it stands is said once, then the error, once',
    same(h.saidSince(m), ['Partial answ', 'The assistant hit a problem.']), 'said=' + show(h.saidSince(m)));

  h = await boot({ stream: true });
  await h.send('And then?');
  m = h.mark();
  await h.event({ type: 'text_delta', text: 'Half an answer' });
  h.stream().end();
  await sleep(120);
  check(22, 'a stream cut off without done (5.8.4): the reply as it stands once, then "may be incomplete" once',
    same(h.saidSince(m), ['Half an answer', 'Connection lost — the reply above may be incomplete.']),
    'said=' + show(h.saidSince(m)));

  h = await boot({ stream: true });
  await h.send('Again');
  m = h.mark();
  await h.event({ type: 'error', status: 429, message: 'Please wait a moment before sending another message' });
  check(23, 'an error before any text: the error alone, once',
    same(h.saidSince(m), ['Please wait a moment before sending another message']), 'said=' + show(h.saidSince(m)));

  h = await boot({ stream: true });
  h.chatFail = true;
  await h.send('Anyone there?');
  check(24, 'the request itself failing (handleChatError): the visitor\'s message, then "Unable to connect", once each',
    same(h.said(), ['Anyone there?', 'Unable to connect. Please try again.']), 'said=' + show(h.said()));

  h = await boot({ stream: true });
  await h.send('Long question');
  await h.event({ type: 'text_delta', text: 'A long reply that is still' });
  await h.newConversation();
  m = h.mark();
  await h.event({ type: 'done', thread_id: 'thr-5', choices: [{ message: { content: 'A long reply that is still coming.' } }] });
  h.stream().end(); await sleep(60);
  check(25, 'New conversation while a reply streams: when its done lands, the cleared bubble says nothing',
    h.saidSince(m).length === 0 && h.announcerEmpty(), 'said=' + show(h.saidSince(m)));

  // ── Notices mid-conversation ───────────────────────────────────────────
  h = await boot();
  h.syncStatus = 429;
  await h.send('Hello?');
  check(26, 'the sync path\'s rate limit is a notice: said once',
    same(h.said(), ['Hello?', 'Please wait a moment before sending another message']), 'said=' + show(h.said()));

  h = await boot({ stream: true });
  await h.send('Add the drill to my cart');
  m = h.mark();
  await h.event({ type: 'text_delta', text: 'Adding it now.' });
  await h.event({ type: 'done', thread_id: 'thr-6', choices: [{ message: { content: 'Adding it now.' } }], tool_outputs: [
    { type: 'wp_rest_call', endpoint: 'wc/store/v1/cart/add-item', method: 'POST', tool_name: 'add_to_cart',
      tool_call_id: 'call-1', body: { id: 5, quantity: 1 }, success_message: '"Cordless Drill" has been added to your cart.' },
    { result_type: 'wp_product', section_heading: 'Drills',
      result_cards: [{ id: 5, title: 'Cordless Drill', url: 'https://shop.test/p/drill' }] },
  ] }, 80);
  check(27, 'the cart: its toast and its link are notices, said once each after the reply; the product card is drawn and not said',
    same(h.saidSince(m), ['Adding it now.', '"Cordless Drill" has been added to your cart.', 'Here is a link to your cart: View cart']) &&
    !!h.root.querySelector('.def-cc-result-section .def-cc-result-card') && !!h.root.querySelector('.cc-toast'),
    'said=' + show(h.saidSince(m)));

  h = await boot({ config: { isLoggedIn: true }, storage: RESTORE_STORAGE, settle: 80 });
  m = h.mark();
  h.root.querySelector('.def-cc-menu-item:nth-child(3)').click();   // Log out
  await sleep(20);
  check(28, 'logging out: the conversation is cleared silently and "You have been logged out." is said once',
    same(h.saidSince(m), ['You have been logged out.']) && same(h.bubbleTexts().slice(-1), ['You have been logged out.']),
    'said=' + show(h.saidSince(m)));

  // ── A spoken turn ──────────────────────────────────────────────────────
  h = await boot({ stream: true, voice: true });
  h.root.querySelector('.def-cc-composer-mic').click();
  await sleep(20);
  h.voice.opts.onSilence();
  await sleep(40);
  const placeholderShown = h.bubbleTexts().slice(-1)[0] === 'Transcribing…';
  const beforeTranscript = h.said().slice();
  await h.event({ type: 'transcript', text: 'what time do you open' });
  await h.event({ type: 'text_delta', text: 'We open at nine.' });
  await h.event({ type: 'done', thread_id: 'thr-7', choices: [{ message: { content: 'We open at nine.' } }] });
  check(29, 'a spoken turn: "Transcribing…" is not said; the transcript is, once, when it fills the bubble; then the reply once',
    placeholderShown && beforeTranscript.length === 0 && same(h.said(), ['what time do you open', 'We open at nine.']),
    'before=' + show(beforeTranscript) + ' said=' + show(h.said()));

  h = await boot({ stream: true, voice: true });
  h.root.querySelector('.def-cc-composer-mic').click();
  await sleep(20);
  h.voice.opts.onSilence();
  await sleep(40);
  h.voice.stop = true;
  await h.event({ type: 'transcript', text: 'stop' });
  check(30, 'a spoken stop: the placeholder goes unsaid and "Conversation ended." is said once',
    same(h.said(), ['Conversation ended.']) && h.bubbleTexts().indexOf('Transcribing…') === -1,
    'said=' + show(h.said()));

  // ── No duplicates, across a whole session ──────────────────────────────
  h = await boot({ stream: true, storage: RESTORE_STORAGE });
  await h.send('Is the drill in stock?');
  await h.event({ type: 'text_delta', text: 'Yes, 4 left.' });
  await h.event({ type: 'done', thread_id: 'thr-local', choices: [{ message: { content: 'Yes, 4 left.' } }] });
  h.stream().end(); await sleep(60);
  const turnOneLines = h.lines();
  await h.send('Thanks!');
  const turnTwoStart = h.lines();
  await h.event({ type: 'text_delta', text: 'Any time.' });
  await h.event({ type: 'done', thread_id: 'thr-local', choices: [{ message: { content: 'Any time.' } }] });
  h.stream().end(); await sleep(60);
  check(31, 'a new turn starts by emptying the announcer: it holds the current turn only (after a silent restore)',
    same(turnOneLines, ['Is the drill in stock?', 'Yes, 4 left.']) && same(turnTwoStart, ['Thanks!']) &&
    same(h.lines(), ['Thanks!', 'Any time.']), 'turn1=' + show(turnOneLines) + ' turn2 start=' + show(turnTwoStart) + ' end=' + show(h.lines()));
  const all = h.said();
  const edits = h.records().filter(e => e.kind === 'edit');
  check(32, 'over the whole session every message is said exactly once, in order, and no line is ever edited in place',
    same(all, ['Is the drill in stock?', 'Yes, 4 left.', 'Thanks!', 'Any time.']) && edits.length === 0,
    'said=' + show(all) + ' edits=' + edits.length);

  console.log('Customer Chat announcer harness (what a screen reader hears)');
  console.log(results.join('\n'));
  console.log(pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
