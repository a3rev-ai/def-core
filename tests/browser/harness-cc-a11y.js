/*
 * Customer Chat panel and greeting bubble, keyboard and screen reader (v8.7.1), 41 checks.
 *
 * Runs the SHIPPED loader (assets/js/def-core-customer-chat-loader.js), whole, in
 * a jsdom page, and drives it through real events: focus, click, keydown.
 *
 * What it holds:
 *  - Opening moves focus into the panel: the composer when the chat module has
 *    built it, the panel itself (tabindex="-1") while it is still loading — and
 *    on a phone always the panel, so a reopen does not raise the keyboard (#37).
 *  - Closing — X, Escape, the drawer's backdrop, a click outside — hands focus
 *    back to whoever opened the chat: a page trigger, else — or when the opener
 *    can no longer take focus — the launcher. Focus the visitor put on the page
 *    by clicking stays where they put it.
 *  - The dialog is named "Chat" and aria-modal in all three display modes; closed,
 *    it is inert (out of the Tab order and the accessibility tree).
 *  - Tab and Shift+Tab wrap at the panel's two ends and bring stray focus back in;
 *    between the ends the browser's own order is left alone.
 *  - Page scroll is locked on the element the viewport takes its overflow from,
 *    and closing puts back exactly the inline style that was there — unless the
 *    page cleared our lock while the chat was open, when it is left alone (#38).
 *  - Over the SHIPPED chat module (def-core-customer-chat.js, run in the same
 *    page): every icon-only header control is named, and reopening focuses the
 *    module's own composer — the class the loader looks for is the one it builds.
 *    The message list stays role="log" and is a named Tab stop (tabindex="0",
 *    aria-label="Conversation") in the Tab sequence between Close chat and the
 *    composer, which the trap lets through while its two ends still wrap.
 *    Since 8.7.3 it is explicitly aria-live="off" (#28): what a screen reader
 *    hears is harness-cc-announce.js's.
 *  - Nothing above that list is live (8.7.3, #39–40): the widget host carries no
 *    aria-live, and no ancestor of the list — panel, host, page — has aria-live
 *    or a live role, in any mode. The module's one live region is its announcer;
 *    an outer one wrapping it is what harness-cc-announce.js cannot see, by design.
 *    The loader's own hard failure is role="alert" instead of riding on the host (#41).
 *  - The greeting bubble is two sibling buttons, not a control inside a control;
 *    both open/dismiss work from the keyboard and the 24h dismissal holds.
 *
 * Against 8.7.0 (the loader AND the chat module) it fails 29 of the 41. The 12 it leaves green are
 * behaviour 8.7.0 already had (Escape and the backdrop returning to the launcher —
 * which it did whoever the opener was, so the stand-in check passes there too —
 * the named header, the 24h dismissal, the bubble's tail) and the checks that
 * the trap and lock do not over-reach (Ctrl+Tab, a closed panel, <html> left alone).
 * The loader with its host still live and no alert fails #39–41 and nothing else.
 *
 * jsdom does no layout, so getClientRects() is stubbed to report a box for
 * anything not display:none — which is all the loader's tab-stop test asks of it.
 * The real-browser walk and axe run that go with this are in the PR body.
 *
 * Bite: CC_LOADER=/path/to/old-loader.js and/or CC_MODULE=/path/to/old-chat.js
 *       node tests/browser/harness-cc-a11y.js  (the old module alone fails #28 and #29)
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const LOADER = extract.customerChatLoader();
const GREETING_KEY = 'def:greeting_dismissed_at';

async function boot(opts) {
  opts = opts || {};
  const dom = new JSDOM(
    '<!doctype html><html><head>' + (opts.head || '') + '</head><body' +
      (opts.bodyAttrs || '') + '>' +
      '<a id="before" href="#x">A link on the page</a>' +
      '<button id="cta" data-def-chat-trigger>Ask Joe</button>' +
      '<input id="search" type="search">' +
      '</body></html>',
    { url: 'https://example.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;

  // No layout in jsdom: a box for anything not display:none up to the host.
  w.Element.prototype.getClientRects = function () {
    for (let n = this; n; n = n.parentNode || n.host) {
      if (n.nodeType === 1 && w.getComputedStyle(n).display === 'none') return [];
    }
    return [{}];
  };
  // The greeting waits 5s before it shows; the harness does not.
  const realTimeout = w.setTimeout.bind(w);
  w.setTimeout = (fn, ms) => realTimeout(fn, ms === 5000 ? 0 : ms);

  if (opts.storage) {
    for (const k of Object.keys(opts.storage)) w.localStorage.setItem(k, opts.storage[k]);
  }
  w.DEFCore = Object.assign({
    showFloatingButton: '1',
    chatDisplayMode: 'modal',
    chatModuleUrl: '', // the module never loads here unless a check builds its composer
    greetingBubbleEnabled: '',
    greetingBubbleText: '',
  }, opts.config || {});
  if (w.document.readyState === 'loading') {
    await new Promise(r => w.document.addEventListener('DOMContentLoaded', r));
  }
  w.eval(LOADER);

  const host = w.document.getElementById('def-customer-chat-host');
  const root = host.shadowRoot;
  const h = {
    w, doc: w.document, host, root,
    trigger: root.querySelector('.def-cc-trigger'),
    panel: () => root.querySelector('.def-cc-panel'),
    // The element with focus, looking through the shadow root.
    active() {
      const a = w.document.activeElement;
      return a === host ? root.activeElement : a;
    },
    key(target, key, mods) {
      const e = new w.KeyboardEvent('keydown', Object.assign(
        { key, bubbles: true, cancelable: true, composed: true }, mods || {}));
      (target || w.document.body).dispatchEvent(e);
      return e;
    },
    // What the chat module's buildChatUI() leaves behind, reduced to the stops.
    buildModule() {
      const p = h.panel();
      const header = w.document.createElement('div');
      header.className = 'def-cc-header';
      const menu = w.document.createElement('button');
      menu.setAttribute('aria-label', 'Menu');
      header.appendChild(menu);
      header.appendChild(p.querySelector('.def-cc-panel-close'));
      p.insertBefore(header, p.firstChild);
      const log = w.document.createElement('div');
      log.innerHTML = '<p>Hi!</p><a href="#faq">FAQ</a>';
      p.appendChild(log);
      const hiddenMenu = w.document.createElement('button');
      hiddenMenu.style.visibility = 'hidden';
      p.appendChild(hiddenMenu);
      const input = w.document.createElement('textarea');
      input.className = 'def-cc-composer-input';
      p.appendChild(input);
      const send = w.document.createElement('button');
      send.setAttribute('aria-label', 'Send message');
      p.appendChild(send);
      const overlay = w.document.createElement('div');
      overlay.style.display = 'none';
      overlay.innerHTML = '<button>Cancel</button>';
      p.appendChild(overlay);
      return { menu, close: header.lastChild, link: log.querySelector('a'), input, send, hiddenMenu };
    },
  };
  return h;
}

const tick = () => new Promise(r => setTimeout(r, 5));

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}
function tag(el) {
  if (!el) return String(el);
  return el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') +
    (el.className && typeof el.className === 'string' ? '.' + el.className.split(' ')[0] : '');
}

(async function () {
  let h, p, e, s;

  // ── Open: focus goes in ────────────────────────────────────────────────
  h = await boot();
  h.trigger.focus();
  h.trigger.click();
  p = h.panel();
  check(1, 'opening from the launcher while the module loads focuses the panel itself',
    h.active() === p && p.getAttribute('tabindex') === '-1', 'active=' + tag(h.active()));
  check(2, 'the dialog is named "Chat", aria-modal, and not inert while open',
    p.getAttribute('role') === 'dialog' && p.getAttribute('aria-label') === 'Chat' &&
    p.getAttribute('aria-modal') === 'true' && !p.hasAttribute('inert'));

  s = h.buildModule();
  h.key(h.active(), 'Escape');
  h.trigger.focus();
  h.trigger.click();
  check(3, 'with the module built, opening focuses the composer',
    h.active() === s.input, 'active=' + tag(h.active()));

  s.input.disabled = true;
  h.key(h.active(), 'Escape');
  h.trigger.click();
  check(4, 'a disabled composer (a turn in flight) is skipped: the panel takes focus',
    h.active() === h.panel(), 'active=' + tag(h.active()));
  s.input.disabled = false;

  // ── Close: focus comes back ────────────────────────────────────────────
  h = await boot();
  h.trigger.focus();
  h.trigger.click();
  h.key(h.active(), 'Escape');
  p = h.panel();
  check(5, 'Escape closes and focus is back on the launcher',
    !p.classList.contains('def-cc-panel--open') && h.active() === h.trigger,
    'active=' + tag(h.active()));
  check(6, 'closed, the panel is inert (out of the Tab order and the accessibility tree)',
    p.hasAttribute('inert') && h.trigger.getAttribute('aria-expanded') === 'false');

  h = await boot();
  h.doc.getElementById('cta').focus();
  h.doc.getElementById('cta').click();
  h.panel().querySelector('.def-cc-panel-close').click();
  check(7, 'opened from a page trigger, closed with X: focus is back on that trigger',
    h.active() === h.doc.getElementById('cta'), 'active=' + tag(h.active()));

  h = await boot();
  h.trigger.focus();
  h.trigger.click();
  h.doc.getElementById('search').focus(); // the mousedown of a click on the page
  h.doc.getElementById('search').click();
  check(8, 'a click outside onto a page field closes the chat and leaves focus in that field',
    !h.panel().classList.contains('def-cc-panel--open') &&
    h.active() === h.doc.getElementById('search'), 'active=' + tag(h.active()));

  h = await boot();
  h.trigger.focus();
  h.trigger.click();
  h.active().blur(); // a click on blank page: focus drops to <body>
  h.doc.body.click();
  check(9, 'a click outside on blank page closes and puts focus back on the opener',
    !h.panel().classList.contains('def-cc-panel--open') && h.active() === h.trigger,
    'active=' + tag(h.active()));

  h = await boot({ config: { chatDisplayMode: 'drawer' } });
  h.trigger.focus();
  h.trigger.click();
  p = h.panel();
  check(10, 'drawer: named, aria-modal, focus inside, launcher hidden while open',
    p.getAttribute('aria-modal') === 'true' && h.active() === p &&
    h.trigger.classList.contains('def-cc-trigger--hidden'));
  h.active().blur(); // a backdrop click takes focus nowhere
  h.root.querySelector('.def-cc-backdrop').click();
  check(11, 'drawer: a backdrop click closes and focus is back on the (shown again) launcher',
    !p.classList.contains('def-cc-panel--open') && h.active() === h.trigger &&
    !h.trigger.classList.contains('def-cc-trigger--hidden'), 'active=' + tag(h.active()));

  h = await boot({ config: { chatDisplayMode: 'spotlight' } });
  h.trigger.focus();
  h.trigger.click();
  p = h.panel();
  const spotOpen = h.active() === p && p.getAttribute('aria-modal') === 'true';
  h.key(h.active(), 'Escape');
  check(12, 'spotlight: aria-modal, focus inside, Escape returns focus to the launcher',
    spotOpen && h.active() === h.trigger, 'active=' + tag(h.active()));

  h = await boot({ storage: { 'def:customer-chat:state': JSON.stringify({ open: true }) } });
  const restoredFocus = h.active() === h.panel();
  h.key(h.active(), 'Escape');
  check(13, 'restored open on page load: focus in the panel, and the launcher stands in as opener',
    restoredFocus && h.active() === h.trigger, 'active=' + tag(h.active()));

  h = await boot();
  const cta = h.doc.getElementById('cta');
  cta.focus();
  cta.click();
  // The opener can no longer take focus when the chat closes — a menu item in a
  // phone menu that folded away behind the chat. (jsdom ignores display:none for
  // focus, so disabled stands in for it here; the real-browser walk hides it.)
  cta.disabled = true;
  h.key(h.active(), 'Escape');
  check(14, 'an opener that cannot take focus any more: the launcher stands in, focus is not lost',
    h.active() === h.trigger, 'active=' + tag(h.active()));

  // ── Tab stays inside ───────────────────────────────────────────────────
  h = await boot();
  h.trigger.focus();
  h.trigger.click();
  s = h.buildModule();
  s.send.focus();
  e = h.key(s.send, 'Tab');
  check(15, 'Tab on the last stop wraps to the first', e.defaultPrevented && h.active() === s.menu,
    'active=' + tag(h.active()));
  e = h.key(s.menu, 'Tab', { shiftKey: true });
  check(16, 'Shift+Tab on the first stop wraps to the last', e.defaultPrevented && h.active() === s.send,
    'active=' + tag(h.active()));
  s.link.focus();
  e = h.key(s.link, 'Tab');
  check(17, 'between the ends the browser moves focus (no preventDefault)', !e.defaultPrevented);
  h.panel().focus();
  e = h.key(h.panel(), 'Tab', { shiftKey: true });
  check(18, 'Shift+Tab from the panel itself wraps to the last stop', e.defaultPrevented && h.active() === s.send,
    'active=' + tag(h.active()));
  h.doc.getElementById('before').focus();
  e = h.key(h.doc.getElementById('before'), 'Tab');
  check(19, 'focus found on the page is brought back to the first stop on Tab',
    e.defaultPrevented && h.active() === s.menu, 'active=' + tag(h.active()));
  s.send.focus();
  e = h.key(s.send, 'Tab', { ctrlKey: true });
  check(20, 'Ctrl+Tab is the browser\'s, not the trap\'s', !e.defaultPrevented && h.active() === s.send);
  h.key(h.active(), 'Escape');
  e = h.key(h.trigger, 'Tab');
  check(21, 'closed, Tab is left alone', !e.defaultPrevented);

  // ── Page scroll ────────────────────────────────────────────────────────
  h = await boot({
    head: '<style>html { overflow-x: visible; overflow-y: visible; }</style>',
    bodyAttrs: ' style="color: red; overflow: scroll"',
  });
  const bodyBefore = h.doc.body.style.cssText; // serialized, so a re-written ';' is not a difference
  h.trigger.click();
  const locked = h.doc.body.style.getPropertyValue('overflow') === 'hidden' &&
    h.doc.body.style.getPropertyPriority('overflow') === 'important';
  h.key(h.active(), 'Escape');
  check(22, '<html> overflow visible: <body> takes the lock, and its inline style comes back exactly',
    locked && h.doc.body.style.cssText === bodyBefore && bodyBefore === 'color: red; overflow: scroll;',
    'after=' + h.doc.body.style.cssText + ' before=' + bodyBefore);
  check(23, 'the lock never touched <html> in that case',
    !h.doc.documentElement.style.getPropertyValue('overflow'));

  h = await boot({ head: '<style>html { overflow-x: hidden; overflow-y: auto; }</style>' });
  h.trigger.click();
  const htmlLocked = h.doc.documentElement.style.getPropertyValue('overflow') === 'hidden' &&
    !h.doc.body.hasAttribute('style');
  h.panel().querySelector('.def-cc-panel-close').click();
  check(24, '<html> not visible: <html> takes the lock, <body> is untouched, and both end as they began',
    htmlLocked && !h.doc.documentElement.hasAttribute('style') && !h.doc.body.hasAttribute('style'),
    'html=' + h.doc.documentElement.getAttribute('style'));

  h = await boot({ head: '<style>html { overflow-x: visible; overflow-y: visible; }</style>' });
  h.trigger.click();
  h.w.DEFCustomerChatLoader.destroy();
  check(25, 'destroy() while open gives the page its scroll back', !h.doc.body.hasAttribute('style'));

  // ── The shipped chat module in the panel ───────────────────────────────
  // Its header is the chat module's, not the loader's, and the loader finds its
  // composer by class — so both are read off the real module, not a stand-in.
  h = await boot({ config: { apiBaseUrl: 'https://api.example.test' } });
  h.w.fetch = () => new Promise(() => {}); // requests stay pending; nothing here needs an answer
  h.trigger.click();
  h.w.eval(extract.customerChatSource());
  h.w.DEFCustomerChat.init(h.root, h.w.DEFCore);
  // The menu's items sit in the header too, but carry their words as text.
  const nameOf = b => (b.getAttribute('aria-label') || b.textContent).trim();
  const allHeader = Array.from(h.root.querySelectorAll('.def-cc-header button'));
  const iconOnly = allHeader.filter(b => !b.closest('.def-cc-menu'));
  check(26, 'every icon-only control in the shipped header has a name (New conversation, Menu, Close chat), and so does every menu item',
    iconOnly.map(nameOf).join('|') === 'New conversation|Menu|Close chat' &&
    iconOnly.every(b => !b.textContent.trim()) && allHeader.every(b => nameOf(b)),
    allHeader.map(nameOf).join('|'));
  h.key(h.active(), 'Escape');
  h.trigger.click();
  check(27, 'reopened over the shipped module, focus lands on the module\'s own composer',
    h.active() && h.active().tagName === 'TEXTAREA' && h.active().matches('.def-cc-composer-input'),
    'active=' + tag(h.active()));

  // The conversation is a scroll box; a keyboard has to be able to reach it to
  // scroll it (axe scrollable-region-focusable). It stays a log, but since 8.7.3
  // never a live one — new messages are spoken by the announcer
  // (harness-cc-announce.js holds what is and is not said).
  const log = h.root.querySelector('.def-cc-messages');
  check(28, 'the message list keeps role="log" and aria-relevant, is explicitly aria-live="off" (8.7.3), and is a named Tab stop: tabindex="0", aria-label="Conversation"',
    log && log.getAttribute('role') === 'log' && log.getAttribute('aria-live') === 'off' &&
    log.getAttribute('aria-relevant') === 'additions' &&
    log.getAttribute('tabindex') === '0' && log.getAttribute('aria-label') === 'Conversation',
    log ? log.outerHTML.slice(0, 160) : 'no .def-cc-messages');
  // The order the browser Tabs through: tree order, tabIndex >= 0, enabled,
  // rendered, visible, not inert — the same test the trap applies. (jsdom loads
  // no stylesheet, so the closed menu's items count here; they sit in the header.)
  const sequence = () => Array.from(h.panel().querySelectorAll('*')).filter(el =>
    el.tabIndex >= 0 && !el.disabled && !el.closest('[inert]') &&
    el.getClientRects().length > 0 && h.w.getComputedStyle(el).visibility !== 'hidden');
  const seq = sequence();
  const at = seq.indexOf(log);
  const closeX = h.root.querySelector('.def-cc-panel-close');
  const composer = h.root.querySelector('.def-cc-composer-input');
  closeX.focus();
  const fromClose = h.key(closeX, 'Tab');
  log.focus();
  const fromLog = h.key(log, 'Tab');
  log.focus();
  const backFromLog = h.key(log, 'Tab', { shiftKey: true });
  check(29, 'while open it is in the Tab sequence, straight after Close chat and before the composer, and the trap lets Tab through it both ways',
    at > 0 && seq[at - 1] === closeX && seq.indexOf(composer) > at &&
    h.active() === log && !fromClose.defaultPrevented && !fromLog.defaultPrevented && !backFromLog.defaultPrevented,
    'sequence=' + seq.map(tag).join(' > '));
  const lastStop = seq[seq.length - 1];
  lastStop.focus();
  const wrapFwd = h.key(lastStop, 'Tab');
  const landedFirst = h.active() === seq[0];
  const wrapBack = h.key(seq[0], 'Tab', { shiftKey: true });
  check(30, 'with the list in the sequence the trap\'s ends still wrap: last → New conversation, and back',
    seq[0].getAttribute('aria-label') === 'New conversation' && wrapFwd.defaultPrevented && landedFirst &&
    wrapBack.defaultPrevented && h.active() === lastStop, 'first=' + tag(seq[0]) + ' last=' + tag(lastStop));
  h.w.DEFCustomerChat.destroy();

  // ── Greeting bubble ────────────────────────────────────────────────────
  const greeting = { greetingBubbleEnabled: '1', greetingBubbleText: 'Hi! I\'m Joe\nAsk me anything', logoUrl: 'https://example.test/logo.png' };
  h = await boot({ config: greeting });
  await tick();
  let bubble = h.root.querySelector('.def-cc-greeting-bubble');
  const openBtn = bubble && bubble.querySelector('.def-cc-greeting-bubble-open');
  const closeBtn = bubble && bubble.querySelector('.def-cc-greeting-bubble-close');
  check(31, 'the bubble is a plain box holding two sibling buttons, each keeping its name',
    !!bubble && !bubble.hasAttribute('role') && !bubble.hasAttribute('tabindex') &&
    openBtn && openBtn.tagName === 'BUTTON' && openBtn.getAttribute('aria-label') === 'Open chat' &&
    closeBtn && closeBtn.tagName === 'BUTTON' && closeBtn.getAttribute('aria-label') === 'Dismiss greeting' &&
    openBtn.parentNode === bubble && closeBtn.parentNode === bubble);
  const nested = bubble && Array.from(bubble.querySelectorAll('button, a[href], input, [tabindex], [role="button"]'))
    .filter(el => el.parentNode.closest('button, a[href], [role="button"]'));
  check(32, 'no interactive element sits inside another (axe nested-interactive)',
    nested && nested.length === 0 && !openBtn.querySelector('div'),
    nested ? nested.map(tag).join(',') : 'no bubble');

  // (The 8.7.0 box was the role="button" itself; falling back to it lets a bite
  // run against the old loader report this check rather than throw.)
  const opener = openBtn || bubble;
  opener.focus();
  opener.click(); // what Enter or Space on a <button> does
  const openedFromBubble = h.panel() && h.panel().classList.contains('def-cc-panel--open') &&
    !h.root.querySelector('.def-cc-greeting-bubble') && h.active() === h.panel();
  h.key(h.active(), 'Escape');
  check(33, 'Open chat from the keyboard opens the panel with focus inside; Escape lands on the launcher (the bubble is gone)',
    openedFromBubble && h.active() === h.trigger, 'active=' + tag(h.active()));

  h = await boot({ config: greeting });
  await tick();
  bubble = h.root.querySelector('.def-cc-greeting-bubble');
  const x = bubble.querySelector('.def-cc-greeting-bubble-close');
  x.focus();
  x.click();
  const stamp = parseInt(h.w.localStorage.getItem(GREETING_KEY) || '0', 10);
  check(34, 'Dismiss from the keyboard removes the bubble, opens nothing, and focus goes to the launcher',
    !h.root.querySelector('.def-cc-greeting-bubble') && !h.panel() && h.active() === h.trigger &&
    Date.now() - stamp < 5000, 'active=' + tag(h.active()) + ' stamp=' + stamp);

  h = await boot({ config: greeting, storage: { [GREETING_KEY]: String(Date.now() - 60 * 60 * 1000) } });
  await tick();
  const hiddenWithin = !h.root.querySelector('.def-cc-greeting-bubble');
  h = await boot({ config: greeting, storage: { [GREETING_KEY]: String(Date.now() - 25 * 60 * 60 * 1000) } });
  await tick();
  check(35, 'the 24h dismissal holds: hidden an hour later, back after 25 hours',
    hiddenWithin && !!h.root.querySelector('.def-cc-greeting-bubble'));

  bubble = h.root.querySelector('.def-cc-greeting-bubble');
  bubble.click(); // the box itself — its tail
  check(36, 'a click on the bubble box (its tail) still opens the chat',
    h.panel() && h.panel().classList.contains('def-cc-panel--open'));

  // ── Review round 1 (appended, so the numbers the panel cites stay put) ──
  // A phone: reopening with the module built must not focus the composer —
  // that opens the on-screen keyboard over half the chat on every reopen.
  h = await boot();
  Object.defineProperty(h.w, 'innerWidth', { value: 390, configurable: true });
  h.trigger.focus();
  h.trigger.click();
  s = h.buildModule();
  h.key(h.active(), 'Escape');
  h.trigger.focus();
  h.trigger.click();
  check(37, 'on a phone (390px) a reopen with the module built focuses the panel, not the composer (desktop keeps the composer: #3)',
    h.active() === h.panel() && h.active() !== s.input, 'active=' + tag(h.active()));

  // A theme's phone menu set <body> overflow:hidden inline, the chat opened
  // over it, and the menu cleared that style while the chat was open — taking
  // our lock with it. Closing must not write the menu's "hidden" back.
  h = await boot({
    head: '<style>html { overflow-x: visible; overflow-y: visible; }</style>',
    bodyAttrs: ' style="overflow: hidden"',
  });
  h.doc.getElementById('cta').focus();
  h.doc.getElementById('cta').click();
  const lockedOverMenu = h.doc.body.style.getPropertyValue('overflow') === 'hidden' &&
    h.doc.body.style.getPropertyPriority('overflow') === 'important';
  h.doc.body.style.removeProperty('overflow'); // the menu folds away
  h.key(h.active(), 'Escape');
  check(38, 'a phone menu that cleared <body> overflow while the chat was open: closing leaves no inline overflow, and the page can scroll',
    lockedOverMenu && !h.doc.body.style.getPropertyValue('overflow') &&
    h.w.getComputedStyle(h.doc.body).overflowY !== 'hidden' &&
    !h.doc.documentElement.style.getPropertyValue('overflow'),
    'body style=' + JSON.stringify(h.doc.body.getAttribute('style')));

  // ── Review round 2 (appended, as above) ────────────────────────────────
  // One live region: the chat's announcer. A live host wrapped the whole widget
  // in a second, and WebKit's walk up from a change does not stop at the log's
  // aria-live="off" — so nothing above the log may be live, whatever the mode.
  h = await boot({ config: greeting });
  await tick();
  const hostAtRest = h.host.hasAttribute('aria-live');
  const bubbleUp = !!h.root.querySelector('.def-cc-greeting-bubble');
  h.trigger.click();
  check(39, 'the widget host carries no aria-live — with the greeting bubble up, and with the panel open (8.7.3)',
    bubbleUp && !hostAtRest && !h.host.hasAttribute('aria-live') && h.panel().classList.contains('def-cc-panel--open'),
    'host=' + h.host.outerHTML.slice(0, 120));

  const LIVE_ROLES = ['alert', 'log', 'marquee', 'status', 'timer'];
  const liveAbove = [];
  for (const mode of ['modal', 'drawer', 'spotlight']) {
    h = await boot({ config: { chatDisplayMode: mode, apiBaseUrl: 'https://api.example.test' } });
    h.w.fetch = () => new Promise(() => {});
    h.trigger.click();
    h.w.eval(extract.customerChatSource());
    h.w.DEFCustomerChat.init(h.root, h.w.DEFCore);
    const list = h.root.querySelector('.def-cc-messages');
    // Up through the shadow root to the host and on to <html>.
    const chain = [];
    for (let n = list && list.parentNode; n; n = n.parentNode || n.host) {
      if (n.nodeType === 1) chain.push(n);
    }
    const live = chain.filter(n => n.hasAttribute('aria-live') ||
      (n.getAttribute('role') || '').split(/\s+/).some(r => LIVE_ROLES.indexOf(r) !== -1));
    if (!list || list.getAttribute('role') !== 'log' || list.getAttribute('aria-live') !== 'off' ||
        chain.indexOf(h.host) === -1 || chain[chain.length - 1] !== h.doc.documentElement) {
      liveAbove.push(mode + ': list/chain wrong (' + chain.map(tag).join(' < ') + ')');
    }
    for (const n of live) {
      liveAbove.push(mode + ': ' + tag(n) + ' aria-live=' + n.getAttribute('aria-live') + ' role=' + n.getAttribute('role'));
    }
    h.w.DEFCustomerChat.destroy();
  }
  check(40, 'over the shipped module, in all three modes: the list itself stays role="log" aria-live="off", and nothing above it up to <html> — panel, host, page — has aria-live or a live role',
    liveAbove.length === 0, liveAbove.join('; '));

  // The loader's own hard failure: a script in its chain does not load.
  h = await boot({ config: { chatModuleUrl: 'https://example.test/chat.js',
    productCardsScriptUrl: 'https://example.test/cards.js', productCardsStyleUrl: 'https://example.test/cards.css' } });
  h.trigger.click();
  const scriptFor = src => Array.from(h.doc.head.querySelectorAll('script')).filter(el => el.src === src)[0];
  scriptFor('https://example.test/cards.js').dispatchEvent(new h.w.Event('load'));
  scriptFor('https://example.test/chat.js').dispatchEvent(new h.w.Event('error'));
  const failed = h.panel().querySelector('.def-cc-loading');
  const failSpan = failed && failed.children.length === 1 && failed.firstElementChild;
  check(41, 'a script that fails to load: the same words, in the same red span, now role="alert" — the host no longer carries it',
    !!failSpan && failSpan.tagName === 'SPAN' && failed.textContent === 'Failed to load chat. Please refresh the page.' &&
    failSpan.getAttribute('role') === 'alert' && failSpan.getAttribute('style') === 'color:#ef4444;',
    failed ? failed.innerHTML : 'no .def-cc-loading');

  console.log('Customer Chat panel + greeting bubble a11y harness');
  console.log(results.join('\n'));
  console.log(pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
})();
