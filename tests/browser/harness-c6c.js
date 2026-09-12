/*
 * Connections on the card kit — behavioural harness (C6c).
 *
 * Runs the SHIPPED page-shell and initIntegrations blocks (sliced by marker,
 * tests/browser/extract.js) inside jsdom, against the SHIPPED #connectionsPane markup
 * out of templates/staff-ai-shell.php. Nothing here is a copy of the code under test
 * and nothing is a copy of the markup.
 *
 * Connections is the page where D-C8's "a page adopts only what fits" does real work:
 * it is ROWS, not cards, so it takes the ⋯ menu and the status line and NONE of the
 * card family. Two consequences this file pins that the card pages never had to:
 *
 *  - a row with nothing to manage renders NO ⋯ button, rather than an empty menu. On
 *    Documents and Scheduled every card always had Download/Delete or Edit/Remove;
 *    here a row that never offered Disconnect offers nothing at all.
 *  - Connect STAYS on the row as the filled action. Only Disconnect moved.
 *
 * 14 checks.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const SHELL = extract.pageShell();
const INTEGRATIONS = extract.integrations();
// C7: the ⋯ menu's machinery is shared and sits outside the page block.
const MENU = extract.consoleMenu();
const CSS = fs.readFileSync(path.join(extract.REPO, 'assets/css/staff-ai.css'), 'utf8');
const TEMPLATE = extract.templateSource();
const INDEX = extract.cssRules(CSS);

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

const HTML = `<!doctype html><html><body>
${extract.templateNav()}
<div id="conversationList"></div>
<div class="messages-container" id="messagesContainer"><div id="messagesList"></div></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
${extract.templatePage('connectionsPane')}
</body></html>`;

const SHELL_TAIL = `
    return { showPage: showPage, showChat: showChat, consolePages: consolePages,
             open: function () { return openPage ? openPage.route : null; } };`;

// gmail: connected WITH a live grant -> has a ⋯ menu holding Disconnect.
// slack: not connected          -> Connect on the row, and NO menu.
// files: no_auth                -> Ready, no Connect, and NO menu.
const DEFAULT_APPS = [
  { server_id: 's-gmail', category: 'gmail', authorized: true, has_grant: true },
  { server_id: 's-slack', category: 'slack', authorized: false, has_grant: false },
  { server_id: 's-files', category: 'local_files', no_auth: true, has_grant: false }
];

function boot(opts) {
  opts = opts || {};
  const dom = new JSDOM(HTML, { url: 'https://e.test/staff-ai/', pretendToBeVisual: true });
  const window = dom.window, document = window.document;
  const messagesContainer = document.getElementById('messagesContainer');
  const composerContainer = document.getElementById('composerContainer');
  const composerInput = document.getElementById('composerInput');
  const requests = [];
  const state = { confirms: [], confirmAnswer: opts.confirmAnswer !== false };

  const api = new window.Function(
    'window', 'document', 'location', 'messagesContainer', 'composerContainer',
    'conversationList', SHELL + SHELL_TAIL
  )(window, document, window.location, messagesContainer, composerContainer,
    document.getElementById('conversationList'));

  async function apiRequest(url, init) {
    const method = (init && init.method) || 'GET';
    requests.push({ url: url, method: method, body: init && init.body });
    if (/^\/user\/integrations$/.test(url)) {
      return { configured: true, apps: opts.apps || DEFAULT_APPS };
    }
    if (/disconnect$/.test(url)) {
      if (opts.failDisconnect) throw new Error('DEF said no');
      return { requested: 1, failed: 0 };
    }
    if (/^\/mail-connections/.test(url)) return { connections: [] };
    if (/authorize$|connect-another$/.test(url)) {
      return { status: 'pending', redirect_url: 'https://consent.e.test/go' };
    }
    return {};
  }
  window.confirm = function (msg) { state.confirms.push(msg); return state.confirmAnswer; };

  const names = ['window', 'document', 'consolePages', 't', 'apiRequest', 'assistantName',
    'onAssistantName', 'clearActiveProject', 'resetToNewChat', 'composerInput',
    'updateSendButton', 'sendMessage', 'formatTime', 'CSS'];
  const outer = [
    window, document, api.consolePages,
    function (key, def) { return def; },
    apiRequest,
    'Sue',
    function (fn) { fn(); },
    function () {}, function () {}, composerInput,
    function () {}, function () {},
    function (x) { return String(x || ''); },
    window.CSS && window.CSS.escape ? window.CSS
      : { escape: function (v) { return String(v).replace(/([^\w-])/g, '\\$1'); } }
  ];
  extract.pushAskEntry(window, names, outer);
  new window.Function(...names, MENU + '\n' + INTEGRATIONS)(...outer);

  return {
    window, document, api, state, requests,
    reqs: re => requests.filter(r => re.test(r.url)),
    row: id => document.querySelector('.integration-row[data-server-id="' + id + '"]')
  };
}

const tick = (w, n) => new Promise(r => w.setTimeout(r, n === undefined ? 40 : n));
function click(w, el) {
  // Returns false rather than throwing when the node is absent. This file is RUN against
  // the old block to prove it bites, and that is precisely the run where the menu button
  // and the touch sheet do not exist yet: a crash at the first one would report nothing
  // about the other thirteen checks.
  if (!el) { return false; }
  el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  return true;
}
function keyOn(w, el, k) {
  if (!el) { return false; }
  el.dispatchEvent(new w.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
  return true;
}
function key(w, target, k) {
  target.dispatchEvent(new w.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
}
async function enter(t) { t.api.showPage('connections'); await tick(t.window, 60); }
async function openMenu(t, id) {
  const btn = t.row(id) && t.row(id).querySelector('.console-menu-btn');
  if (btn) { click(t.window, btn); await tick(t.window); }
  return t.row(id) && t.row(id).querySelector('.console-menu-drop');
}
const items = menu => menu
  ? Array.from(menu.querySelectorAll('.console-menu-item')).map(el =>
      el.textContent + (el.classList.contains('console-menu-item-danger') ? '!' : '')) : [];

(async () => {
  let n = 0;

  // ---- 1. what the page took, and what it did NOT -------------------------
  {
    // D-C8: Connections is rows. Taking the card family would be the opposite of what
    // the decision says, so its absence is asserted rather than assumed.
    // C7: the row's code is the page block plus the shared menu it delegates to.
    const CODE = (MENU + '\n' + INTEGRATIONS).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    const written = new Set(CODE.match(/\bconsole-[a-z]+(?:-[a-z]+)*\b/g) || []);
    const cardNames = Array.from(written).filter(x => /^console-card/.test(x));
    check(++n, 'Connections takes NO card name — it is rows, and D-C8 says a page adopts only what fits',
      cardNames.length === 0, 'took: ' + cardNames.join(', '));
    check(++n, 'it takes the menu and the status line, and those are all it takes',
      written.has('console-menu') && written.has('console-menu-btn') &&
      written.has('console-menu-item') && written.has('console-status'),
      'written: ' + Array.from(written).sort().join(', '));
  }
  {
    // The shipped block must no longer write the name it gave up, or both would be live.
    const CODE = INTEGRATIONS.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    const left = ['integrations-status-muted', 'integrations-status-ok', 'integrations-status-error']
      .filter(name => CODE.indexOf(name) !== -1);
    check(++n, 'the shipped block no longer writes the status name the kit replaced',
      left.length === 0 && /class="console-status" id="integrationsStatus"/.test(TEMPLATE),
      'left: ' + left.join(', '));
  }

  // ---- 2. the 10px the re-point ADDS - which the page head then absorbs ----
  {
    // `.console-page .console-status` carries a `margin-top: 10px` that the retired
    // `.integrations-status` never declared. It does NOT move anything on screen: the
    // status div is the immediately following sibling of `.console-page-head`, whose
    // `margin-bottom: 20px` collapses with it, and adjacent margins collapse to the
    // LARGER - 20px before the re-point and 20px after. Sorin measured 0.0px on C6d.
    //
    // So what is worth pinning is not a movement, it is that the page's own status
    // element now carries the kit's NAME inside a `.console-page`. The rule itself has
    // been in the stylesheet since C6a, so asserting it exists would prove nothing.
    const t = boot(); await enter(t);
    const el = t.document.getElementById('integrationsStatus');
    const inPage = !!(el && el.closest('.console-page'));
    const moved = INDEX.rules.filter(r => /\.console-page\s+\.console-status\b/.test(r.selector));
    check(++n, 'the kit rule adds a 10px margin-top Connections never declared — which collapses into the page head above it, so nothing moves',
      !!el && el.classList.contains('console-status') && inPage &&
      moved.length === 1 && /margin-top:\s*10px/.test(moved[0].body.replace(/\s+/g, ' ')),
      'class=' + (el && el.className) + ' inPage=' + inPage + ' rules=' + moved.length);
  }

  // ---- 3. the menu, on a row that has something to manage -----------------
  {
    const t = boot(); await enter(t);
    const row = t.row('s-gmail');
    const btn = row && row.querySelector('.console-menu-btn');
    const menu = await openMenu(t, 's-gmail');
    check(++n, 'a connected row opens a .console-menu anchored in its own action cell, holding Disconnect',
      !!menu && menu.classList.contains('console-menu') &&
      menu.parentNode.classList.contains('integration-action') &&
      row.contains(menu) && items(menu).join('|') === 'Disconnect!' &&
      btn.getAttribute('aria-expanded') === 'true' &&
      t.document.activeElement === menu.firstElementChild,
      'items=' + items(menu).join('|') + ' parent=' + (menu && menu.parentNode.className));
  }
  {
    // Connect is the row's filled action and did NOT move (D-C8).
    const t = boot(); await enter(t);
    const slack = t.row('s-slack');
    const connect = slack && slack.querySelector('.integration-btn-primary');
    check(++n, 'Connect stays on the row as the filled action — only Disconnect moved',
      !!connect && connect.textContent === 'Connect' &&
      connect.closest('.integration-action') === slack.querySelector('.integration-action'),
      'connect=' + (connect && connect.textContent));
  }
  {
    // The page's own case, which the card pages never had: nothing to manage, no button.
    const t = boot(); await enter(t);
    const slack = t.row('s-slack'), files = t.row('s-files');
    check(++n, 'a row with nothing to manage renders NO ⋯ button, rather than an empty menu',
      !!slack && !slack.querySelector('.console-menu-btn') &&
      !slack.querySelector('.console-menu-sheet') &&
      !!files && !files.querySelector('.console-menu-btn'),
      'slack=' + !!(slack && slack.querySelector('.console-menu-btn')) +
      ' files=' + !!(files && files.querySelector('.console-menu-btn')));
  }

  // ---- 4. Disconnect does what the row button did -------------------------
  {
    const t = boot(); await enter(t);
    const menu = await openMenu(t, 's-gmail');
    const dis = menu && menu.querySelector('.console-menu-item-danger');
    if (dis) { click(t.window, dis); }
    await tick(t.window, 80);
    check(++n, 'Disconnect, picked from the menu, asks by name and sends the same DELETE',
      !!dis && t.state.confirms.length === 1 && /Gmail/.test(t.state.confirms[0]) &&
      t.reqs(/disconnect$/).length === 1,
      'confirms=' + JSON.stringify(t.state.confirms) + ' posts=' + t.reqs(/disconnect$/).length);
  }
  {
    const t = boot({ confirmAnswer: false }); await enter(t);
    const menu = await openMenu(t, 's-gmail');
    const dis = menu && menu.querySelector('.console-menu-item-danger');
    if (dis) { click(t.window, dis); }
    await tick(t.window, 80);
    check(++n, 'Cancel at the confirm sends nothing, exactly as the old button behaved',
      !!dis && t.state.confirms.length === 1 && t.reqs(/disconnect$/).length === 0,
      'confirms=' + t.state.confirms.length + ' posts=' + t.reqs(/disconnect$/).length);
  }

  // ---- 5. Escape, and the touch rendering --------------------------------
  {
    const t = boot(); await enter(t);
    const menu = await openMenu(t, 's-gmail');
    // On the BODY, as a real keypress arrives at the document. It does not reach the menu
    // element (a keydown bubbles up, not down) and the shell's guard deliberately stands
    // aside while a .console-menu is on screen — so what answers this is the PAGE's own
    // document-level Escape listener. That listener is the thing C6c first shipped
    // without, and dispatching at the focused element hid its absence.
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const gone = !!menu && !t.document.querySelector('.console-menu-drop');
    const stillHere = t.api.open() === 'connections';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(++n, 'Escape closes the ⋯ menu first and leaves Connections second',
      gone && stillHere && t.api.open() !== 'connections',
      'gone=' + gone + ' stayed=' + stillHere + ' then=' + t.api.open());
  }
  {
    const t = boot(); await enter(t);
    const row = t.row('s-gmail');
    const btn = row && row.querySelector('.console-menu-sheet-btn');
    const sheet = row && row.querySelector('.console-menu-sheet-list');
    const closed = !!btn && !!sheet && sheet.hidden && btn.getAttribute('aria-expanded') === 'false' &&
      btn.getAttribute('aria-controls') === sheet.id;
    click(t.window, btn); await tick(t.window);
    const opened = closed && !sheet.hidden && btn.getAttribute('aria-expanded') === 'true' &&
      items(sheet).join('|') === 'Disconnect!';
    click(t.window, btn); await tick(t.window);
    check(++n, 'the touch sheet expands in place with the same action and folds away again',
      closed && opened && sheet.hidden && btn.getAttribute('aria-expanded') === 'false',
      'btn=' + !!btn + ' sheet=' + !!sheet + ' closed=' + closed + ' opened=' + opened);
  }
  {
    // The check C6c was missing, and the bug it missed: an EXPANDED sheet is a
    // `.console-menu` that is not [hidden], so the shell's guard matches it and steps
    // back. Without the page's own Escape listener nothing answered at all — the sheet
    // stayed open and the page would not leave, however many times you pressed it.
    const t = boot(); await enter(t);
    const row = t.row('s-gmail');
    const btn = row && row.querySelector('.console-menu-sheet-btn');
    click(t.window, btn); await tick(t.window);
    const sheet = row && row.querySelector('.console-menu-sheet-list');
    const wasOpen = !!sheet && !sheet.hidden;
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const closedIt = wasOpen && sheet.hidden && t.api.open() === 'connections';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(++n, 'an EXPANDED touch sheet answers Escape first, and only then does Escape leave the page',
      closedIt && t.api.open() !== 'connections',
      'wasOpen=' + wasOpen + ' closedIt=' + closedIt + ' then=' + t.api.open());
  }
  {
    const t = boot(); await enter(t);
    const row = t.row('s-gmail');
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(++n, "a HIDDEN touch sheet does not hold Escape — one press leaves Connections",
      !!(row && row.querySelector('.console-menu-sheet-list[hidden]')) && t.api.open() !== 'connections',
      'sheet=' + !!(row && row.querySelector('.console-menu-sheet-list[hidden]')) +
      ' open=' + t.api.open());
  }
  {
    const t = boot(); await enter(t);
    const row = t.row('s-gmail');
    click(t.window, row && row.querySelector('.console-menu-sheet-btn')); await tick(t.window);
    await openMenu(t, 's-gmail');
    const sheet = row && row.querySelector('.console-menu-sheet-list');
    const bothOpen = !!t.document.querySelector('.console-menu-drop') && !!sheet && !sheet.hidden;
    t.api.showChat(); await tick(t.window);
    const left = t.document.querySelectorAll('.console-menu:not([hidden])').length;
    check(++n, 'leaving Connections closes an open ⋯ menu and an expanded sheet, so neither holds Escape next',
      bothOpen && left === 0, 'bothOpen=' + bothOpen + ' left=' + left);
  }

  console.log('\n' + results.join('\n'));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail > 0 ? 1 : 0);
})();
