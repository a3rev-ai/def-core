/*
 * Console page shell - behavioural harness (C1 / v7.8.1, PR #332 review fixes).
 * Runs the SHIPPED page-shell block (extracted by marker) inside jsdom against a
 * DOM that mirrors templates/staff-ai-shell.php.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');
const BLOCK = extract.pageShell();

const HTML = `<!doctype html><html><body>
<nav>
  <button class="sidebar-nav-item" id="navNewChat">New chat</button>
  <a class="sidebar-nav-item" id="navDocuments" href="#documents"><svg viewBox="0 0 24 24" aria-hidden="true"><path id="docIcon" d="M14 2H6a2 2 0 0 0-2 2v16"></path></svg>My documents</a>
  <a class="sidebar-nav-item" id="navScheduled" href="#scheduled"><svg viewBox="0 0 24 24" aria-hidden="true"><circle id="schedIcon" cx="12" cy="12" r="9"></circle></svg>Scheduled tasks</a>
  <a class="sidebar-nav-item" id="navUnknown" href="#not-a-console-page">Some other anchor</a>
</nav>
<div id="conversationList"><div class="conversation-item" id="conv1">A chat</div></div>
<div id="messagesContainer"><div id="messageList"></div></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
<section class="console-page" id="scheduledPane" hidden>
  <h1 class="console-page-title" id="scheduledTitle" tabindex="-1">Scheduled tasks</h1>
</section>
<section class="console-page console-page-compact" id="documentsPane" hidden>
  <h1 class="console-page-title" id="documentsTitle" tabindex="-1">My documents</h1>
  <input type="search" class="documents-search" id="documentsSearch">
  <select id="documentsProjectFilter"><option>All</option></select>
  <div id="documentsList"></div>
</section>
</body></html>`;

const REG = `
    consolePages.push({ route: 'documents', el: document.getElementById('documentsPane'),
      title: document.getElementById('documentsTitle'),
      onEnter: function () { enters.push('documents'); } });
    consolePages.push({ route: 'scheduled', el: document.getElementById('scheduledPane'),
      title: document.getElementById('scheduledTitle'),
      onEnter: function () { enters.push('scheduled'); } });
    return { showPage: showPage, showChat: showChat, applyRoute: applyRoute,
             open: function () { return openPage ? openPage.route : null; } };`;

function boot(startUrl) {
  const dom = new JSDOM(HTML, { url: startUrl || 'https://e.test/staff-ai/', pretendToBeVisual: true });
  const window = dom.window, document = window.document, location = window.location;
  const messagesContainer = document.getElementById('messagesContainer');
  const composerContainer = document.getElementById('composerContainer');
  const conversationList = document.getElementById('conversationList');
  const enters = [], leaves = [];
  const factory = new window.Function(
    'window', 'document', 'location', 'messagesContainer', 'composerContainer',
    'conversationList', 'enters', 'leaves', BLOCK + REG
  );
  const api = factory(window, document, location, messagesContainer, composerContainer,
    conversationList, enters, leaves);
  return {
    dom, window, document, location, api, enters, leaves,
    chatVisible: () => messagesContainer.style.display !== 'none' && composerContainer.style.display !== 'none'
  };
}

const tick = (w, n) => new Promise(r => w.setTimeout(r, n === undefined ? 30 : n));
function click(w, el, opts) {
  const init = Object.assign({ bubbles: true, cancelable: true, button: 0,
    ctrlKey: false, metaKey: false, shiftKey: false, altKey: false }, opts || {});
  const ev = new w.MouseEvent('click', init);
  el.dispatchEvent(ev);
  return ev;
}
const clickNav = (t, id, opts) => click(t.window, t.document.getElementById(id), opts);
// Boot with a page BEFORE the console in this session's history, so a Back out
// of the console has somewhere real to land.
async function bootWithPrevious() {
  const t = boot('https://e.test/before/');
  t.window.history.pushState({}, '', '/staff-ai/');
  await tick(t.window);
  return t;
}
function key(w, target, k) {
  target.dispatchEvent(new w.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
}

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

(async () => {
  // 1. chat -> Documents -> Back returns to chat
  {
    const t = boot(); t.document.getElementById('navDocuments').focus();
    t.api.showPage('documents'); await tick(t.window);
    const opened = t.api.open() === 'documents' && t.location.hash === '#documents';
    t.window.history.back(); await tick(t.window);
    check(1, 'chat -> Documents -> Back returns to chat',
      opened && t.api.open() === null && t.chatVisible() && t.location.hash === '',
      'opened=' + opened + ' open=' + t.api.open() + ' hash=' + JSON.stringify(t.location.hash));
  }
  // 2. chat -> Scheduled -> Back returns to chat
  {
    const t = boot(); t.document.getElementById('navScheduled').focus();
    t.api.showPage('scheduled'); await tick(t.window);
    const opened = t.api.open() === 'scheduled' && t.location.hash === '#scheduled';
    t.window.history.back(); await tick(t.window);
    check(2, 'chat -> Scheduled -> Back returns to chat',
      opened && t.api.open() === null && t.chatVisible() && t.location.hash === '',
      'opened=' + opened + ' open=' + t.api.open() + ' hash=' + JSON.stringify(t.location.hash));
  }
  // 3. leaving does not grow an extra chat entry
  {
    const t = boot(); const base = t.window.history.length;
    t.api.showPage('documents'); await tick(t.window);
    const afterOpen = t.window.history.length;
    t.api.showChat(); await tick(t.window);
    const afterLeave = t.window.history.length;
    check(3, 'leaving a page does NOT push an extra chat entry (history model)',
      afterOpen === base + 1 && afterLeave === afterOpen && t.location.hash === '',
      'base=' + base + ' open=' + afterOpen + ' leave=' + afterLeave + ' hash=' + JSON.stringify(t.location.hash));
  }
  // 4. Documents -> Escape -> Back does NOT reopen Documents
  {
    const t = boot();
    t.api.showPage('documents'); await tick(t.window);
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const leftByEscape = t.api.open() === null && t.location.hash === '';
    t.window.history.back(); await tick(t.window);
    check(4, 'Documents -> Escape -> Back does NOT immediately reopen Documents',
      leftByEscape && t.api.open() !== 'documents',
      'leftByEscape=' + leftByEscape + ' openAfterBack=' + t.api.open() + ' hash=' + JSON.stringify(t.location.hash));
  }
  // 5. repeated cycles do not trap history
  {
    const t = boot(); const base = t.window.history.length; const lens = [];
    for (let i = 0; i < 6; i++) {
      t.api.showPage('documents'); await tick(t.window);
      key(t.window, t.document.body, 'Escape'); await tick(t.window);
      lens.push(t.window.history.length);
    }
    check(5, 'repeated open/leave cycles do not trap browser history inside the console',
      lens.every(l => l <= base + 1) && t.api.open() === null,
      'base=' + base + ' lens=[' + lens + ']');
  }
  // 6. Documents -> Scheduled -> Escape focuses SCHEDULED entry
  {
    const t = boot();
    t.document.getElementById('navDocuments').focus();
    t.api.showPage('documents'); await tick(t.window);
    t.api.showPage('scheduled'); await tick(t.window);
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const af = t.document.activeElement;
    check(6, 'Documents -> Scheduled -> Escape restores focus to SCHEDULED sidebar entry (not Documents)',
      !!af && af.id === 'navScheduled', 'activeElement=' + (af && (af.id || af.tagName)));
  }
  // 7. reload on #scheduled
  {
    const t = boot('https://e.test/staff-ai/#scheduled');
    t.api.applyRoute(); await tick(t.window);
    check(7, 'reload on #scheduled reopens Scheduled',
      t.api.open() === 'scheduled' && !t.document.getElementById('scheduledPane').hidden && !t.chatVisible(),
      'open=' + t.api.open());
  }
  // 8. reload on #documents
  {
    const t = boot('https://e.test/staff-ai/#documents');
    t.api.applyRoute(); await tick(t.window);
    check(8, 'reload on #documents reopens Documents',
      t.api.open() === 'documents' && !t.document.getElementById('documentsPane').hidden,
      'open=' + t.api.open());
  }
  // 9. unknown hash falls back to chat
  {
    let threw = null;
    const t = boot('https://e.test/staff-ai/#totally-unknown-route');
    try { t.api.applyRoute(); await tick(t.window); } catch (e) { threw = e; }
    check(9, 'unknown hash falls back to chat without error',
      !threw && t.api.open() === null && t.chatVisible(), 'threw=' + (threw && threw.message));
  }
  // 10. Escape from a normal page returns to chat
  {
    const t = boot();
    t.api.showPage('documents'); await tick(t.window);
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(10, 'Escape from a normal page returns to chat',
      t.api.open() === null && t.chatVisible() && t.document.getElementById('documentsPane').hidden);
  }
  // 11. Escape while #documentsSearch focused
  {
    const t = boot();
    t.api.showPage('documents'); await tick(t.window);
    const s = t.document.getElementById('documentsSearch'); s.focus();
    key(t.window, s, 'Escape'); await tick(t.window);
    check(11, 'Escape while #documentsSearch is focused does NOT leave Documents',
      t.api.open() === 'documents', 'open=' + t.api.open());
  }
  // 12-14. input / select / textarea
  {
    const t = boot();
    t.api.showPage('documents'); await tick(t.window);
    const inp = t.document.createElement('input');
    t.document.getElementById('documentsPane').appendChild(inp);
    inp.focus(); key(t.window, inp, 'Escape'); await tick(t.window);
    check(12, 'Escape while an INPUT is focused does NOT leave the page', t.api.open() === 'documents');
    const sel = t.document.getElementById('documentsProjectFilter');
    sel.focus(); key(t.window, sel, 'Escape'); await tick(t.window);
    check(13, 'Escape while a SELECT is focused does NOT leave the page', t.api.open() === 'documents');
    const ta = t.document.createElement('textarea');
    t.document.getElementById('documentsPane').appendChild(ta);
    ta.focus(); key(t.window, ta, 'Escape'); await tick(t.window);
    check(14, 'Escape while a TEXTAREA is focused does NOT leave the page', t.api.open() === 'documents');
  }
  // 15-16. .document-assign-row
  {
    const t = boot();
    t.api.showPage('documents'); await tick(t.window);
    const row = t.document.createElement('div');
    row.className = 'document-assign-row';
    row.innerHTML = '<select><option>Project A</option></select>';
    t.document.getElementById('documentsList').appendChild(row);
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(15, 'Escape while .document-assign-row exists does NOT leave Documents',
      t.api.open() === 'documents', 'open=' + t.api.open());
    check(16, 'Escape does NOT discard the inline assign-row state',
      !!t.document.querySelector('.document-assign-row'));
  }
  // 17. streaming continues while Documents is open
  {
    const t = boot();
    const list = t.document.getElementById('messageList');
    let streamed = '';
    const iv = t.window.setInterval(() => { streamed += 'x'; list.textContent = streamed; }, 10);
    t.api.showPage('documents'); await tick(t.window, 80);
    const midOpen = streamed.length;
    key(t.window, t.document.body, 'Escape'); await tick(t.window, 60);
    t.window.clearInterval(iv);
    check(17, 'streaming reply continues while Documents is open (chat hidden, never reset)',
      midOpen > 0 && streamed.length > midOpen && list.textContent === streamed,
      'midOpen=' + midOpen + ' final=' + streamed.length);
  }
  // 18. composer text survives round trip
  {
    const t = boot();
    const ci = t.document.getElementById('composerInput');
    ci.value = 'half-typed message';
    t.api.showPage('documents'); await tick(t.window);
    const hiddenDuring = t.document.getElementById('composerContainer').style.display === 'none';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(18, 'half-typed composer text survives a page round trip',
      hiddenDuring && ci.value === 'half-typed message' && t.chatVisible(),
      'value=' + JSON.stringify(ci.value));
  }
  // 19-21. aria-current
  {
    const t = boot();
    const nd = t.document.getElementById('navDocuments'), ns = t.document.getElementById('navScheduled');
    t.api.showPage('documents'); await tick(t.window);
    const a = nd.getAttribute('aria-current') === 'page' && !ns.hasAttribute('aria-current');
    t.api.showPage('scheduled'); await tick(t.window);
    const b = ns.getAttribute('aria-current') === 'page' && !nd.hasAttribute('aria-current');
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const c = !nd.hasAttribute('aria-current') && !ns.hasAttribute('aria-current');
    check(19, 'aria-current follows the open page and moves on page -> page', a && b, 'a=' + a + ' b=' + b);
    check(20, 'aria-current is cleared once the chat is shown again', c);
    check(21, 'sidebar-nav-item-current class tracks aria-current',
      !nd.classList.contains('sidebar-nav-item-current') && !ns.classList.contains('sidebar-nav-item-current'));
  }
  // 22. chat menu / modal unchanged
  {
    const t = boot();
    t.api.showPage('documents'); await tick(t.window);
    const menu = t.document.createElement('div'); menu.className = 'chat-menu';
    t.document.body.appendChild(menu);
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const heldForMenu = t.api.open() === 'documents';
    menu.remove();
    const ov = t.document.createElement('div'); ov.className = 'modal-overlay visible';
    t.document.body.appendChild(ov);
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const heldForModal = t.api.open() === 'documents';
    ov.remove();
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(22, 'Escape yields to an open chat menu and to a visible modal, then leaves once both are gone',
      heldForMenu && heldForModal && t.api.open() === null,
      'menu=' + heldForMenu + ' modal=' + heldForModal + ' final=' + t.api.open());
  }
  // 23. opening a conversation leaves the page
  {
    const t = boot();
    t.api.showPage('documents'); await tick(t.window);
    t.document.getElementById('conv1').dispatchEvent(new t.window.MouseEvent('click', { bubbles: true }));
    await tick(t.window);
    check(23, 'opening a conversation leaves the page without yanking focus to the sidebar',
      t.api.open() === null && t.chatVisible() &&
      t.document.activeElement !== t.document.getElementById('navDocuments'),
      'open=' + t.api.open());
  }
  // 24. title focus + pane swap
  {
    const t = boot();
    t.api.showPage('documents'); await tick(t.window);
    const f1 = t.document.activeElement && t.document.activeElement.id === 'documentsTitle';
    t.api.showPage('scheduled'); await tick(t.window);
    const swapped = t.document.getElementById('documentsPane').hidden &&
      !t.document.getElementById('scheduledPane').hidden;
    const f2 = t.document.activeElement && t.document.activeElement.id === 'scheduledTitle';
    check(24, 'entering a page focuses its title and page -> page swaps exactly one visible pane',
      f1 && swapped && f2, 'f1=' + f1 + ' swapped=' + swapped + ' f2=' + f2);
  }
  // 25. page reached by address leaves by pushing
  {
    const t = boot('https://e.test/staff-ai/#documents');
    t.api.applyRoute(); await tick(t.window);
    const base = t.window.history.length;
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(25, 'a page reached by address leaves by PUSHing a clean URL (no stray back out of the site)',
      t.api.open() === null && t.location.hash === '' && t.window.history.length === base + 1,
      'base=' + base + ' now=' + t.window.history.length + ' hash=' + JSON.stringify(t.location.hash));
  }
  // 26. Documents -> Scheduled -> Escape lands in chat
  {
    const t = boot();
    t.api.showPage('documents'); await tick(t.window);
    t.api.showPage('scheduled'); await tick(t.window);
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(26, 'Documents -> Scheduled -> Escape lands in the chat with a clean URL',
      t.api.open() === null && t.chatVisible() && t.location.hash === '',
      'open=' + t.api.open() + ' hash=' + JSON.stringify(t.location.hash));
  }

  // -- Steve's remaining finding: the REAL sidebar link-click path -----------
  // Every check below CLICKS the entry. None of them calls showPage directly.

  // 27. Harness fidelity: the default action is live in here, so "not
  //     prevented" below means the browser really would have navigated.
  {
    const t = boot();
    const ev = clickNav(t, 'navUnknown'); await tick(t.window);
    check(27, 'harness fidelity: a sidebar link naming no console page keeps its NATIVE navigation',
      ev.defaultPrevented === false && t.location.hash === '#not-a-console-page' && t.api.open() === null,
      'prevented=' + ev.defaultPrevented + ' hash=' + JSON.stringify(t.location.hash));
  }
  // 28. the console takes the plain click and owns the push
  {
    const t = boot();
    const ev = clickNav(t, 'navDocuments'); await tick(t.window);
    check(28, 'a plain left click on Documents is intercepted and opens the page (console owns the push)',
      ev.defaultPrevented === true && t.api.open() === 'documents' &&
      t.location.hash === '#documents' && !t.chatVisible(),
      'prevented=' + ev.defaultPrevented + ' open=' + t.api.open() + ' hash=' + JSON.stringify(t.location.hash));
  }
  // 29. PROOF 1 - previous page -> chat -> CLICK Documents -> Escape -> Back
  {
    const t = await bootWithPrevious();
    clickNav(t, 'navDocuments'); await tick(t.window);
    const opened = t.api.open() === 'documents';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const backInChat = t.api.open() === null && t.location.pathname === '/staff-ai/' && t.location.hash === '';
    t.window.history.back(); await tick(t.window);
    check(29, 'PROOF 1: previous page -> chat -> CLICK Documents -> Escape -> Back leaves the console (NOT Documents)',
      opened && backInChat && t.api.open() !== 'documents' && t.location.pathname === '/before/',
      'opened=' + opened + ' backInChat=' + backInChat + ' open=' + t.api.open() +
      ' path=' + t.location.pathname + ' hash=' + JSON.stringify(t.location.hash));
  }
  // 30. PROOF 2 - CLICK Documents -> Escape adds no extra clean-URL entry
  {
    const t = boot(); const base = t.window.history.length;
    clickNav(t, 'navDocuments'); await tick(t.window);
    const afterClick = t.window.history.length;
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(30, 'PROOF 2: chat -> CLICK Documents -> Escape adds NO extra clean-URL history entry',
      afterClick === base + 1 && t.window.history.length === afterClick &&
      t.api.open() === null && t.chatVisible() && t.location.hash === '',
      'base=' + base + ' click=' + afterClick + ' escape=' + t.window.history.length +
      ' hash=' + JSON.stringify(t.location.hash));
  }
  // 31. PROOF 3 - the same for Scheduled
  {
    const t = boot(); const base = t.window.history.length;
    clickNav(t, 'navScheduled'); await tick(t.window);
    const afterClick = t.window.history.length;
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(31, 'PROOF 3: chat -> CLICK Scheduled -> Escape adds NO extra clean-URL history entry',
      afterClick === base + 1 && t.window.history.length === afterClick &&
      t.api.open() === null && t.chatVisible() && t.location.hash === '',
      'base=' + base + ' click=' + afterClick + ' escape=' + t.window.history.length);
  }
  // 32. PROOF 4 - five real click/open/leave cycles
  {
    const t = boot(); const base = t.window.history.length; const lens = [];
    for (let i = 0; i < 5; i++) {
      clickNav(t, i % 2 ? 'navScheduled' : 'navDocuments'); await tick(t.window);
      key(t.window, t.document.body, 'Escape'); await tick(t.window);
      lens.push(t.window.history.length);
    }
    check(32, 'PROOF 4: five REAL click/open/leave cycles do not grow history',
      lens.every(l => l <= base + 1) && t.api.open() === null && t.chatVisible() && t.location.hash === '',
      'base=' + base + ' lens=[' + lens + '] open=' + t.api.open());
  }
  // 33. PROOF 5 - Back from a clicked-open Documents
  {
    const t = boot();
    clickNav(t, 'navDocuments'); await tick(t.window);
    const opened = t.api.open() === 'documents';
    t.window.history.back(); await tick(t.window);
    check(33, 'PROOF 5: browser Back from a CLICK-opened Documents returns naturally to the chat',
      opened && t.api.open() === null && t.chatVisible() && t.location.hash === '',
      'opened=' + opened + ' open=' + t.api.open() + ' hash=' + JSON.stringify(t.location.hash));
  }
  // 34. PROOF 6 - Back from a clicked-open Scheduled
  {
    const t = boot();
    clickNav(t, 'navScheduled'); await tick(t.window);
    const opened = t.api.open() === 'scheduled';
    t.window.history.back(); await tick(t.window);
    check(34, 'PROOF 6: browser Back from a CLICK-opened Scheduled returns naturally to the chat',
      opened && t.api.open() === null && t.chatVisible() && t.location.hash === '',
      'opened=' + opened + ' open=' + t.api.open());
  }
  // 35. PROOF 7 - direct load on #documents
  {
    const t = boot('https://e.test/staff-ai/#documents');
    t.api.applyRoute(); await tick(t.window);
    check(35, 'PROOF 7: a DIRECT load on #documents still opens Documents through hashchange',
      t.api.open() === 'documents' && !t.document.getElementById('documentsPane').hidden && !t.chatVisible(),
      'open=' + t.api.open());
  }
  // 36. PROOF 8 - direct load on #scheduled
  {
    const t = boot('https://e.test/staff-ai/#scheduled');
    t.api.applyRoute(); await tick(t.window);
    check(36, 'PROOF 8: a DIRECT load on #scheduled still opens Scheduled through hashchange',
      t.api.open() === 'scheduled' && !t.document.getElementById('scheduledPane').hidden && !t.chatVisible(),
      'open=' + t.api.open());
  }
  // 37. PROOF 9 - Back/Forward traversal after real clicks
  {
    const t = boot();
    clickNav(t, 'navDocuments'); await tick(t.window);
    clickNav(t, 'navScheduled'); await tick(t.window);
    const atScheduled = t.api.open() === 'scheduled' && t.location.hash === '#scheduled';
    t.window.history.back(); await tick(t.window);
    const backToDocs = t.api.open() === 'documents' && t.location.hash === '#documents';
    t.window.history.back(); await tick(t.window);
    const backToChat = t.api.open() === null && t.chatVisible() && t.location.hash === '';
    t.window.history.forward(); await tick(t.window);
    const fwdToDocs = t.api.open() === 'documents' && t.location.hash === '#documents';
    t.window.history.forward(); await tick(t.window);
    check(37, 'PROOF 9: Back/Forward traversal still works through hashchange after real clicks',
      atScheduled && backToDocs && backToChat && fwdToDocs &&
      t.api.open() === 'scheduled' && t.location.hash === '#scheduled',
      'sched=' + atScheduled + ' back1=' + backToDocs + ' back2=' + backToChat +
      ' fwd1=' + fwdToDocs + ' fwd2=' + t.api.open());
  }
  // 38-42. PROOF 10 - modified and non-left clicks stay the browser's own
  {
    const mods = [
      ['Ctrl+click', { ctrlKey: true }],
      ['Meta/Cmd+click', { metaKey: true }],
      ['Shift+click', { shiftKey: true }],
      ['Alt+click', { altKey: true }]
    ];
    let n = 38;
    for (const m of mods) {
      const t = boot();
      const d = clickNav(t, 'navDocuments', m[1]);
      const sc = clickNav(t, 'navScheduled', m[1]);
      await tick(t.window);
      check(n, 'PROOF 10: ' + m[0] + ' on a page entry is NOT prevented (open-in-new-tab stays native)',
        d.defaultPrevented === false && sc.defaultPrevented === false,
        'documents=' + d.defaultPrevented + ' scheduled=' + sc.defaultPrevented);
      n++;
    }
    const t = boot();
    const mid = clickNav(t, 'navDocuments', { button: 1 });
    const right = clickNav(t, 'navScheduled', { button: 2 });
    await tick(t.window);
    check(42, 'PROOF 10: middle-click and right-click on page entries are NOT prevented',
      mid.defaultPrevented === false && right.defaultPrevented === false,
      'middle=' + mid.defaultPrevented + ' right=' + right.defaultPrevented);
  }
  // 43. the click usually lands on the entry's icon, not the anchor
  {
    const t = boot();
    const ev = click(t.window, t.document.getElementById('docIcon'));
    await tick(t.window);
    check(43, "a click on the icon INSIDE the entry is delegated to the entry (SVG child)",
      ev.defaultPrevented === true && t.api.open() === 'documents' && t.location.hash === '#documents',
      'prevented=' + ev.defaultPrevented + ' open=' + t.api.open());
  }
  // 44. clicking the entry already open reloads without disowning the chat entry
  {
    const t = boot(); const base = t.window.history.length;
    clickNav(t, 'navDocuments'); await tick(t.window);
    const firstEnters = t.enters.filter(x => x === 'documents').length;
    clickNav(t, 'navDocuments'); await tick(t.window);
    const reloaded = t.enters.filter(x => x === 'documents').length === firstEnters + 1;
    const noExtraEntry = t.window.history.length === base + 1;
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(44, 'clicking the entry already open re-enters (reload) and still POPS back to the chat entry',
      reloaded && noExtraEntry && t.api.open() === null && t.chatVisible() &&
      t.window.history.length === base + 1 && t.location.hash === '',
      'reloaded=' + reloaded + ' noExtraEntry=' + noExtraEntry + ' len=' + t.window.history.length +
      ' hash=' + JSON.stringify(t.location.hash));
  }
  // 45. an entry's own click listener still runs BEFORE the page is entered,
  //     which is what lets Documents drop its slot exclusion before the load.
  {
    const t = boot(); const order = [];
    t.document.getElementById('navDocuments').addEventListener('click', function () { order.push('entry-listener'); });
    const before = t.enters.length;
    clickNav(t, 'navDocuments'); await tick(t.window);
    check(45, "an entry's own click listener runs BEFORE onEnter (the exclusion is dropped before the load)",
      order[0] === 'entry-listener' && t.enters.length === before + 1 && t.enters[before] === 'documents',
      'order=[' + order + '] enters=' + JSON.stringify(t.enters.slice(before)));
  }

  console.log(results.join('\n'));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail ? 1 : 0);
})();
