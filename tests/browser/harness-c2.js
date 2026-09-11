/*
 * Projects on the console shell — behavioural harness (C2 / v7.8.2).
 * Runs the SHIPPED page-shell block AND the SHIPPED initProjects block
 * (both extracted by marker) inside jsdom, against a DOM that mirrors
 * templates/staff-ai-shell.php. Nothing here is a copy of the code under test.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const REPO = path.resolve(__dirname, '..', '..');
const extract = require('./extract');
const SHELL = extract.pageShell();
const PROJECTS = extract.projects();
const SHELL_PHP = fs.readFileSync(path.join(REPO, 'templates/staff-ai-shell.php'), 'utf8');

const HTML = `<!doctype html><html><body>
<nav class="sidebar-nav">
  <a class="sidebar-nav-item" id="navProjects" href="#projects"><svg aria-hidden="true"><path id="projIcon" d="M22 19"></path></svg>Projects</a>
  <a class="sidebar-nav-item" id="navDocuments" href="#documents">My documents</a>
  <a class="sidebar-nav-item" id="navScheduled" href="#scheduled">Scheduled</a>
</nav>
<div id="conversationList"><div class="conversation-item" id="conv1">A chat</div></div>
<div class="messages-container" id="messagesContainer"><div id="messageList"></div></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
<section class="console-page console-page-compact" id="projectsPane" hidden>
  <div class="console-page-head">
    <div>
      <h1 class="console-page-title" id="projectsTitle" tabindex="-1">Projects</h1>
      <p class="console-page-desc">Folders your assistant works from.</p>
    </div>
    <div class="console-page-actions">
      <button type="button" class="modal-btn modal-btn-secondary projects-ask-btn">Ask how Projects work</button>
      <div class="projects-create-row">
        <input type="text" class="form-input projects-create-name" id="projectsNewName" maxlength="120">
        <button type="button" class="modal-btn modal-btn-primary" id="projectsCreateBtn">Create project</button>
      </div>
    </div>
  </div>
  <label class="projects-archived-toggle"><input type="checkbox" id="projectsShowArchived"> Show archived</label>
  <div class="console-status" id="projectsStatus"></div>
  <div class="projects-list" id="projectsList"></div>
</section>
<section class="console-page" id="documentsPane" hidden>
  <h1 class="console-page-title" id="documentsTitle" tabindex="-1">My documents</h1>
</section>
<section class="console-page" id="scheduledPane" hidden>
  <h1 class="console-page-title" id="scheduledTitle" tabindex="-1">Scheduled</h1>
</section>
</body></html>`;

// The shell block exports its own entry points; the Projects block registers on
// consolePages and is otherwise driven through the real DOM.
const SHELL_TAIL = `
    consolePages.push({ route: 'documents', el: document.getElementById('documentsPane'),
      title: document.getElementById('documentsTitle'),
      onEnter: function () { calls.push('documents:onEnter'); } });
    consolePages.push({ route: 'scheduled', el: document.getElementById('scheduledPane'),
      title: document.getElementById('scheduledTitle') });
    return { showPage: showPage, showChat: showChat, applyRoute: applyRoute,
             consolePages: consolePages,
             open: function () { return openPage ? openPage.route : null; } };`;

function boot(startUrl, opts) {
  opts = opts || {};
  const dom = new JSDOM(HTML, { url: startUrl || 'https://e.test/staff-ai/', pretendToBeVisual: true });
  const window = dom.window, document = window.document, location = window.location;
  const messagesContainer = document.getElementById('messagesContainer');
  const composerContainer = document.getElementById('composerContainer');
  const composerInput = document.getElementById('composerInput');
  const conversationList = document.getElementById('conversationList');
  const calls = [];
  const requests = [];

  const shellFactory = new window.Function(
    'window', 'document', 'location', 'messagesContainer', 'composerContainer',
    'conversationList', 'calls', SHELL + SHELL_TAIL
  );
  const api = shellFactory(window, document, location, messagesContainer, composerContainer,
    conversationList, calls);

  // ---- the outer bindings initProjects reads, stubbed at the seam ----------
  const projects = opts.projects || [
    { project_id: 'p1', name: 'Runsheets', status: 'active', created_at: '2026-09-01T10:00:00Z' },
    { project_id: 'p2', name: 'Shelved work', status: 'archived', created_at: '2026-08-01T10:00:00Z' }
  ];
  const docs = opts.docs || {
    p1: [{ document_id: 'd1', slot: 'runsheet', title: 'The runsheet', version: 3, download_url: 'https://e.test/d1' },
         { document_id: 'd9', title: 'A loose note' }],
    p2: []
  };
  async function apiRequest(url, init) {
    requests.push({ url: url, method: (init && init.method) || 'GET', body: init && init.body });
    if (opts.fail && opts.fail(url, init)) throw new Error('DEF said no');
    if (/\/tasks$/.test(url)) return { tasks: [] };
    if (/^\/projects(\?|$)/.test(url)) return { projects: projects };
    if (/^\/documents/.test(url)) {
      const id = /project_id=([^&]+)/.exec(url);
      return { documents: (id && docs[decodeURIComponent(id[1])]) || [] };
    }
    return {};
  }
  const state = { activeProject: null, newChats: 0, sent: [], viewer: null, docFilter: null };
  // The four bindings the page's Ask entry needs, named because the SHIPPED
  // askEntry helper (C5) is built from the same ones — the "Ask Sue how Projects
  // work" checks below run the real helper, not a stand-in for it.
  const t = function (key, def) { return def; };
  const clearActiveProject = function () { state.activeProject = null; };
  const resetToNewChat = function () { api.showChat({ focus: false }); state.newChats++; };
  const sendMessage = function () { state.sent.push(composerInput.value); };
  const projectsFactory = new window.Function(
    'window', 'document', 'consolePages', 't', 'apiRequest', 'apiBase', 'assistantName',
    'onAssistantName', 'clearActiveProject', 'resetToNewChat', 'composerInput',
    'updateSendButton', 'sendMessage', 'setActiveProject', 'activeProjectId', 'formatTime',
    'openDocumentViewer', 'openDocumentsForProject', 'safeHttpHref', 'projectsCache',
    'showPage', 'askEntry', 'state',
    PROJECTS
  );
  projectsFactory(
    window, document, api.consolePages,
    t,
    apiRequest, '/def/v1', 'Sue',
    function (fn) { fn(); },
    clearActiveProject,
    resetToNewChat,
    composerInput,
    function () {}, sendMessage,
    function (p) { state.activeProject = p; },
    null, function (x) { return String(x || ''); },
    function (id, title) { state.viewer = id; },
    function (id, excl) { state.docFilter = { id: id, excl: !!excl }; api.showPage('documents'); },
    function (u) { return u; }, [],
    api.showPage,
    extract.buildAskEntry(window, {
      t: t, assistantName: 'Sue', composerInput: composerInput,
      clearActiveProject: clearActiveProject, resetToNewChat: resetToNewChat,
      sendMessage: sendMessage
    }),
    state
  );

  return {
    dom, window, document, location, api, calls, requests, state,
    chatVisible: () => messagesContainer.style.display !== 'none' && composerContainer.style.display !== 'none'
  };
}

const tick = (w, n) => new Promise(r => w.setTimeout(r, n === undefined ? 40 : n));
function click(w, el, opts) {
  const init = Object.assign({ bubbles: true, cancelable: true, button: 0,
    ctrlKey: false, metaKey: false, shiftKey: false, altKey: false }, opts || {});
  const ev = new w.MouseEvent('click', init);
  el.dispatchEvent(ev);
  return ev;
}
const clickNav = (t, id, opts) => click(t.window, t.document.getElementById(id), opts);
function key(w, target, k) {
  target.dispatchEvent(new w.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
}
async function bootWithPrevious() {
  const t = boot('https://e.test/before/');
  t.window.history.pushState({}, '', '/staff-ai/');
  await tick(t.window);
  return t;
}
const cards = t => Array.prototype.slice.call(t.document.querySelectorAll('#projectsList .console-card'));
const byText = (root, sel, re) =>
  Array.prototype.slice.call(root.querySelectorAll(sel)).filter(el => re.test(el.textContent))[0];

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

(async () => {
  // ---- the page and its address -------------------------------------------
  // 1. reload on #projects opens Projects
  {
    const t = boot('https://e.test/staff-ai/#projects');
    t.api.applyRoute(); await tick(t.window);
    check(1, 'a reload on #projects opens Projects (the chat hidden, the pane shown)',
      t.api.open() === 'projects' && !t.document.getElementById('projectsPane').hidden && !t.chatVisible(),
      'open=' + t.api.open());
  }
  // 2. a plain click on the sidebar entry opens it and the console owns the push
  {
    const t = boot();
    const ev = clickNav(t, 'navProjects'); await tick(t.window);
    check(2, 'a plain left click on the Projects entry opens the page at #projects',
      ev.defaultPrevented === true && t.api.open() === 'projects' && t.location.hash === '#projects',
      'prevented=' + ev.defaultPrevented + ' open=' + t.api.open() + ' hash=' + JSON.stringify(t.location.hash));
  }
  // 3. Back / the phone's back gesture returns to the chat
  {
    const t = await bootWithPrevious();
    clickNav(t, 'navProjects'); await tick(t.window);
    const opened = t.api.open() === 'projects';
    t.window.history.back(); await tick(t.window);
    check(3, "Back (the phone's back gesture) from Projects returns to the chat, still in the console",
      opened && t.api.open() === null && t.chatVisible() &&
      t.location.hash === '' && t.location.pathname === '/staff-ai/',
      'opened=' + opened + ' open=' + t.api.open() + ' path=' + t.location.pathname);
  }
  // 4. five open/leave cycles leave history.length unchanged
  {
    const t = boot(); const base = t.window.history.length; const lens = [];
    for (let i = 0; i < 5; i++) {
      clickNav(t, 'navProjects'); await tick(t.window);
      key(t.window, t.document.body, 'Escape'); await tick(t.window);
      lens.push(t.window.history.length);
    }
    check(4, 'five REAL open/leave cycles on Projects leave history.length unchanged',
      lens.every(l => l <= base + 1) && t.api.open() === null && t.chatVisible() && t.location.hash === '',
      'base=' + base + ' lens=[' + lens + ']');
  }
  // 5. the sidebar tells the truth (D-C7)
  {
    const t = boot();
    const nav = t.document.getElementById('navProjects');
    const isLink = nav.tagName === 'A' && nav.getAttribute('href') === '#projects' && !nav.hasAttribute('aria-haspopup');
    clickNav(t, 'navProjects'); await tick(t.window);
    const marked = nav.getAttribute('aria-current') === 'page' && nav.classList.contains('sidebar-nav-item-current');
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(5, 'the Projects entry is a link to #projects with no aria-haspopup, and carries aria-current while open',
      isLink && marked && !nav.hasAttribute('aria-current'),
      'isLink=' + isLink + ' marked=' + marked);
  }
  // 6. the shipped template no longer carries the Projects modal
  {
    const gone = !/id="projectsModal"/.test(SHELL_PHP) && !/id="projectsRefresh"/.test(SHELL_PHP)
      && !/id="projectsClose"/.test(SHELL_PHP) && !/id="projectsModalClose"/.test(SHELL_PHP);
    const pageThere = /id="projectsPane"/.test(SHELL_PHP) && /id="projectsTitle"/.test(SHELL_PHP);
    const navIsLink = /<a class="sidebar-nav-item" id="navProjects" href="#projects">/.test(SHELL_PHP);
    check(6, 'the Projects modal markup is DELETED from the shipped template, not hidden',
      gone && pageThere && navIsLink, 'gone=' + gone + ' pageThere=' + pageThere + ' navIsLink=' + navIsLink);
  }

  // ---- the cards, moved as they are ---------------------------------------
  // 7. entering the page loads the cards
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const c = cards(t);
    check(7, 'entering Projects loads the card grid (a card per project, the archived one marked)',
      c.length === 2 && /Runsheets/.test(c[0].textContent) &&
      c[1].classList.contains('project-card-archived'),
      'cards=' + c.length);
  }
  // 8. Open Project -> the chat, with the project made active (the composer chip)
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    click(t.window, cards(t)[0].querySelector('.project-open-btn')); await tick(t.window, 60);
    check(8, 'Open Project from a card leaves the page for the chat with the project active (the composer chip)',
      t.api.open() === null && t.chatVisible() &&
      t.state.activeProject && t.state.activeProject.project_id === 'p1' && t.state.newChats === 1,
      'open=' + t.api.open() + ' active=' + JSON.stringify(t.state.activeProject && t.state.activeProject.project_id));
  }
  // 9. a slot tile -> the Documents PAGE; Back returns to Projects
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    click(t.window, byText(cards(t)[0], '.console-slot', /Other documents/)); await tick(t.window, 60);
    const atDocs = t.api.open() === 'documents' && t.location.hash === '#documents' &&
      t.state.docFilter && t.state.docFilter.id === 'p1' && t.state.docFilter.excl === true;
    t.window.history.back(); await tick(t.window, 60);
    check(9, "a project's Other documents opens the Documents PAGE pre-filtered, and Back returns to Projects",
      atDocs && t.api.open() === 'projects' && t.location.hash === '#projects',
      'atDocs=' + atDocs + ' afterBack=' + t.api.open() + ' hash=' + JSON.stringify(t.location.hash));
  }
  // 10. the Ask entry lives in the page head and starts the chat
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const ask = t.document.querySelector('#projectsPane .console-page-head .projects-ask-btn');
    const named = ask && /Sue/.test(ask.textContent);
    click(t.window, ask); await tick(t.window, 60);
    check(10, 'the "Ask Sue how Projects work" entry sits in the page head and starts the chat that explains Projects',
      named && t.api.open() === null && t.chatVisible() && t.state.sent.length === 1 &&
      /Walk me through/.test(t.state.sent[0]),
      'named=' + named + ' open=' + t.api.open() + ' sent=' + JSON.stringify(t.state.sent));
  }
  // 11. Restore / Archive still answer on the page
  {
    const t = boot();
    t.window.confirm = () => true;
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const before = t.requests.length;
    click(t.window, cards(t)[1].querySelector('.console-menu-btn')); await tick(t.window);
    click(t.window, byText(t.document, '.console-menu-drop .console-menu-item', /Restore/)); await tick(t.window, 90);
    const put = t.requests.slice(before).filter(r => r.method === 'PUT')[0];
    check(11, 'Restore from the ⋯ menu still PUTs status:active and reloads the list',
      !!put && put.url === '/projects/p2' && /"status":"active"/.test(put.body || ''),
      'put=' + JSON.stringify(put));
  }

  // ---- the ⋯ menu leaves fixed positioning --------------------------------
  // 12. the menu is a child of its own card, not of <body>
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const card = cards(t)[0];
    click(t.window, card.querySelector('.console-menu-btn')); await tick(t.window);
    const menu = t.document.querySelector('.console-menu-drop');
    check(12, 'the ⋯ menu is anchored INSIDE its own card (not appended to <body>)',
      !!menu && card.contains(menu) && menu.parentNode.classList.contains('console-card-actions'),
      'inCard=' + (!!menu && card.contains(menu)) + ' parent=' + (menu && menu.parentNode.className));
  }
  // 13. no viewport maths left on the element
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    click(t.window, cards(t)[0].querySelector('.console-menu-btn')); await tick(t.window);
    const menu = t.document.querySelector('.console-menu-drop');
    check(13, 'the ⋯ menu carries NO measured inline top/right (the fixed overlay maths are gone)',
      !!menu && !menu.style.top && !menu.style.right,
      'top=' + JSON.stringify(menu && menu.style.top) + ' right=' + JSON.stringify(menu && menu.style.right));
  }
  // 14. a page scroll no longer tears the menu down
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    click(t.window, cards(t)[0].querySelector('.console-menu-btn')); await tick(t.window);
    const upBefore = !!t.document.querySelector('.console-menu-drop');
    t.document.getElementById('projectsPane').dispatchEvent(new t.window.Event('scroll'));
    t.document.getElementById('projectsList').dispatchEvent(new t.window.Event('scroll'));
    t.window.dispatchEvent(new t.window.Event('resize'));
    await tick(t.window);
    check(14, 'the ⋯ menu survives a page scroll and a resize — it moves with its card now',
      upBefore && !!t.document.querySelector('.console-menu-drop'),
      'before=' + upBefore + ' after=' + !!t.document.querySelector('.console-menu-drop'));
  }
  // 15. a click elsewhere still closes it
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    click(t.window, cards(t)[0].querySelector('.console-menu-btn')); await tick(t.window);
    click(t.window, t.document.getElementById('projectsTitle')); await tick(t.window);
    check(15, 'a click anywhere else still closes the ⋯ menu',
      !t.document.querySelector('.console-menu-drop') && t.api.open() === 'projects');
  }

  // ---- Escape: the menu first, the page second (the C1 rule) --------------
  // 16. the popover
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    click(t.window, cards(t)[0].querySelector('.console-menu-btn')); await tick(t.window);
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const menuGone = !t.document.querySelector('.console-menu-drop');
    const stillOnPage = t.api.open() === 'projects';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(16, 'Escape closes the ⋯ menu FIRST and the page SECOND',
      menuGone && stillOnPage && t.api.open() === null && t.chatVisible(),
      'menuGone=' + menuGone + ' stillOnPage=' + stillOnPage + ' final=' + t.api.open());
  }
  // 17. the hidden touch sheets must not hold the page hostage
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const sheets = t.document.querySelectorAll('.console-menu-sheet-list');
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(17, "the cards' HIDDEN touch sheets (also .console-menu) do not block Escape from leaving Projects",
      sheets.length === 2 && t.api.open() === null && t.chatVisible(),
      'sheets=' + sheets.length + ' open=' + t.api.open());
  }
  // 18. an OPEN touch sheet closes first, the page second
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const btn = cards(t)[0].querySelector('.console-menu-sheet-btn');
    click(t.window, btn); await tick(t.window);
    const opened = btn.getAttribute('aria-expanded') === 'true';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const sheetClosed = btn.getAttribute('aria-expanded') === 'false' && t.api.open() === 'projects';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(18, 'an OPEN in-place sheet closes on the first Escape and the page leaves on the second',
      opened && sheetClosed && t.api.open() === null,
      'opened=' + opened + ' sheetClosed=' + sheetClosed + ' final=' + t.api.open());
  }

  // ---- D-C5: the chat is hidden, never reset -----------------------------
  // 19. a streaming reply keeps streaming
  {
    const t = boot();
    const list = t.document.getElementById('messageList');
    let streamed = '';
    const iv = t.window.setInterval(() => { streamed += 'x'; list.textContent = streamed; }, 10);
    clickNav(t, 'navProjects'); await tick(t.window, 90);
    const midOpen = streamed.length;
    key(t.window, t.document.body, 'Escape'); await tick(t.window, 60);
    t.window.clearInterval(iv);
    check(19, 'a streaming reply keeps streaming while Projects is open (the chat hidden, never reset)',
      midOpen > 0 && streamed.length > midOpen && list.textContent === streamed,
      'midOpen=' + midOpen + ' final=' + streamed.length);
  }
  // 20. a half-typed message survives
  {
    const t = boot();
    const ci = t.document.getElementById('composerInput');
    ci.value = 'half-typed message';
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const hiddenDuring = t.document.getElementById('composerContainer').style.display === 'none';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(20, 'a half-typed message is still in the composer after a Projects round trip',
      hiddenDuring && ci.value === 'half-typed message' && t.chatVisible(),
      'value=' + JSON.stringify(ci.value));
  }

  // ---- focus (D-C6) -------------------------------------------------------
  // 26. the title takes focus on entry, the sidebar entry on the way out
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const onTitle = t.document.activeElement.id === 'projectsTitle';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(21, 'entering focuses the page title; leaving puts focus back on the Projects entry',
      onTitle && t.document.activeElement.id === 'navProjects',
      'onTitle=' + onTitle + ' after=' + t.document.activeElement.id);
  }
  // 27. page -> page swaps exactly one visible pane
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    clickNav(t, 'navDocuments'); await tick(t.window, 60);
    check(22, 'Projects -> Documents swaps exactly one visible pane and closes nothing else',
      t.document.getElementById('projectsPane').hidden &&
      !t.document.getElementById('documentsPane').hidden && t.api.open() === 'documents',
      'open=' + t.api.open());
  }


  // ======================================================================
  // Steve's review (PR #333) — the two behavioural findings
  // ======================================================================

  // ---- A. onLeave must close the touch management sheets -----------------
  // 28. leaving Projects with a sheet expanded closes it
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const btn = cards(t)[0].querySelector('.console-menu-sheet-btn');
    const sheet = cards(t)[0].querySelector('.console-menu-sheet-list');
    click(t.window, btn); await tick(t.window);
    const opened = !sheet.hidden && btn.getAttribute('aria-expanded') === 'true';
    clickNav(t, 'navDocuments'); await tick(t.window, 60);
    check(23, 'leaving Projects for Documents CLOSES an expanded touch sheet',
      opened && sheet.hidden === true && btn.getAttribute('aria-expanded') === 'false',
      'opened=' + opened + ' hidden=' + sheet.hidden + ' aria=' + btn.getAttribute('aria-expanded'));
  }
  // 29. and the hidden page therefore holds no open .console-menu
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    click(t.window, cards(t)[0].querySelector('.console-menu-sheet-btn')); await tick(t.window);
    const leaked = t.document.querySelectorAll('.console-menu:not([hidden])').length;
    clickNav(t, 'navScheduled'); await tick(t.window, 60);
    check(24, "the hidden Projects page leaves NO .console-menu:not([hidden]) behind (the shell's guard)",
      leaked === 1 && t.document.querySelectorAll('.console-menu:not([hidden])').length === 0,
      'whileOpen=' + leaked + ' afterLeave=' + t.document.querySelectorAll('.console-menu:not([hidden])').length);
  }
  // 30. THE BUG: the destination page's first Escape must not be swallowed
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    click(t.window, cards(t)[0].querySelector('.console-menu-sheet-btn')); await tick(t.window);
    clickNav(t, 'navDocuments'); await tick(t.window, 60);
    const onDocs = t.api.open() === 'documents';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(25, 'PROOF: the FIRST Escape on Documents leaves the page (not swallowed by hidden Projects)',
      onDocs && t.api.open() === null && t.chatVisible(),
      'onDocs=' + onDocs + ' afterFirstEscape=' + t.api.open());
  }
  // 31. the same on Scheduled, and the ⋯ popover path still cleans up too
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    click(t.window, cards(t)[0].querySelector('.console-menu-sheet-btn')); await tick(t.window);
    clickNav(t, 'navScheduled'); await tick(t.window, 60);
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const scheduledLeft = t.api.open() === null;
    const t2 = boot();
    clickNav(t2, 'navProjects'); await tick(t2.window, 80);
    click(t2.window, cards(t2)[0].querySelector('.console-menu-sheet-btn')); await tick(t2.window);
    clickNav(t2, 'navDocuments'); await tick(t2.window, 60);
    check(26, 'the FIRST Escape on Scheduled leaves too, and no ⋯ popover survives the move',
      scheduledLeft && t2.document.querySelectorAll('.console-menu-drop').length === 0,
      'scheduledLeft=' + scheduledLeft + ' popovers=' + t2.document.querySelectorAll('.console-menu-drop').length);
  }
  // 32. re-entering Projects after that leave is clean (no half-open sheet)
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    click(t.window, cards(t)[0].querySelector('.console-menu-sheet-btn')); await tick(t.window);
    clickNav(t, 'navDocuments'); await tick(t.window, 60);
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const open = Array.prototype.slice.call(
      t.document.querySelectorAll('#projectsList .console-menu-sheet-list')).filter(s => !s.hidden);
    check(27, 'coming back to Projects shows every touch sheet closed',
      t.api.open() === 'projects' && open.length === 0, 'openSheets=' + open.length);
  }


  // ---- Create project is the INLINE ROW in the page head (Steve's ruling) --
  // the dialog is gone from the shipped markup, JS and CSS
  {
    const tpl = fs.readFileSync(path.join(REPO, 'templates/staff-ai-shell.php'), 'utf8');
    const js = fs.readFileSync(path.join(REPO, 'assets/js/staff-ai.js'), 'utf8');
    const css = fs.readFileSync(path.join(REPO, 'assets/css/staff-ai.css'), 'utf8');
    const markup = ['projectCreateModal', 'projectCreateModalClose', 'projectCreateCancel',
                    'projectCreateConfirm', 'projectCreateStatus'].filter(k => tpl.includes(k));
    const ident = ['projectCreateModal', 'projectCreateConfirm', 'projectCreateCancel',
                   'projectCreateStatus', 'openCreate', 'closeCreate',
                   'setCreateStatus'].filter(k => js.includes(k));
    const styles = ['documents-status-dialog', 'projectCreateStatus'].filter(k => css.includes(k));
    check(28, 'the Create DIALOG markup is DELETED from the shipped template, not hidden',
      markup.length === 0, 'left: ' + markup.join(', '));
    check(29, 'no Create-dialog identifier or function survives in the shipped JS',
      ident.length === 0, 'left: ' + ident.join(', '));
    check(30, 'no Create-dialog-only rule survives in the shipped CSS',
      styles.length === 0, 'left: ' + styles.join(', '));
  }
  // the name field lives in the shared header actions slot, beside the buttons
  {
    const t = boot();
    const slot = t.document.querySelector('#projectsPane .console-page-head .console-page-actions');
    const nameEl = t.document.getElementById('projectsNewName');
    const ask = slot && slot.querySelector('.projects-ask-btn');
    const create = t.document.getElementById('projectsCreateBtn');
    // The field and Create are one ROW inside the slot rather than two loose
    // items in it (2026-09-12): as loose items the wrap fell between them and
    // stranded Create on its own line under Ask. Still the page header, which is
    // what this check has always been protecting - not a dialog, not a modal.
    //
    // SHELL_PHP as well as the fixture. Until now this check read only the copy
    // of the header written at the top of this file, so the shipped template was
    // free to differ from it and nothing here would have said so.
    const shipped = /<div class="projects-create-row">\s*<input[^>]*id="projectsNewName"[\s\S]*?id="projectsCreateBtn"[\s\S]*?<\/div>/.test(SHELL_PHP);
    const row = nameEl && nameEl.parentNode;
    check(31, 'the name field and Create are one row in the Projects header actions slot, below the Ask entry',
      shipped &&
      !!slot && !!nameEl && !!ask && !!create &&
      !!row && row.classList.contains('projects-create-row') && row.parentNode === slot &&
      create.parentNode === row && ask.parentNode === slot &&
      Array.prototype.indexOf.call(slot.children, ask) <
        Array.prototype.indexOf.call(slot.children, row) &&
      nameEl.classList.contains('projects-create-name'),
      'shipped=' + shipped + ' row=' + (row && row.className) +
      ' inSlot=' + (!!row && row.parentNode === slot) +
      ' ask=' + !!ask + ' create=' + (!!create && create.parentNode === row));
  }
  // the header carries the title, ONE description line and nothing else
  {
    const t = boot();
    const head = t.document.querySelector('#projectsPane .console-page-head');
    const descs = head.querySelectorAll('.console-page-desc');
    check(32, 'the Projects header keeps its title and exactly one description line (no help paragraph)',
      !!t.document.getElementById('projectsTitle') && descs.length === 1 &&
      head.querySelectorAll('p').length === 1,
      'descs=' + descs.length + ' paragraphs=' + head.querySelectorAll('p').length);
  }
  // Enter in the name field creates: trimmed POST, field cleared, list reloaded, focus back
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const nameEl = t.document.getElementById('projectsNewName');
    const listsBefore = t.requests.filter(r => r.method === 'GET' && /^\/projects/.test(r.url)).length;
    nameEl.value = '  My project  ';
    nameEl.focus();
    key(t.window, nameEl, 'Enter'); await tick(t.window, 110);
    const post = t.requests.filter(r => r.method === 'POST' && r.url === '/projects').pop();
    const listsAfter = t.requests.filter(r => r.method === 'GET' && /^\/projects/.test(r.url)).length;
    check(33, 'Enter in the name field POSTs the trimmed name, clears it, reloads and refocuses it',
      !!post && JSON.parse(post.body).name === 'My project' && nameEl.value === '' &&
      listsAfter === listsBefore + 1 && t.document.activeElement === nameEl &&
      t.api.open() === 'projects',
      'post=' + (post && post.body) + ' value=' + JSON.stringify(nameEl.value) +
      ' reloads=' + listsBefore + '->' + listsAfter + ' focus=' + t.document.activeElement.id);
  }
  // clicking Create project does exactly the same
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const nameEl = t.document.getElementById('projectsNewName');
    const listsBefore = t.requests.filter(r => r.method === 'GET' && /^\/projects/.test(r.url)).length;
    nameEl.value = '  My project  ';
    click(t.window, t.document.getElementById('projectsCreateBtn')); await tick(t.window, 110);
    const post = t.requests.filter(r => r.method === 'POST' && r.url === '/projects').pop();
    const listsAfter = t.requests.filter(r => r.method === 'GET' && /^\/projects/.test(r.url)).length;
    check(34, 'clicking Create project does the same: trimmed POST, cleared, reloaded, refocused',
      !!post && JSON.parse(post.body).name === 'My project' && nameEl.value === '' &&
      listsAfter === listsBefore + 1 && t.document.activeElement === nameEl,
      'post=' + (post && post.body) + ' value=' + JSON.stringify(nameEl.value) +
      ' reloads=' + listsBefore + '->' + listsAfter + ' focus=' + t.document.activeElement.id);
  }
  // a create that fails answers through the PAGE's status line, and nothing is lost
  {
    const t = boot(null, { fail: (url, init) => init && init.method === 'POST' });
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const nameEl = t.document.getElementById('projectsNewName');
    const createBtn = t.document.getElementById('projectsCreateBtn');
    nameEl.value = 'Doomed';
    click(t.window, createBtn); await tick(t.window, 110);
    const pageStatus = t.document.getElementById('projectsStatus');
    check(35, 'a failed create reports through #projectsStatus, keeps the page, the field and the button',
      /DEF said no/.test(pageStatus.textContent) && t.api.open() === 'projects' &&
      !t.document.getElementById('projectsPane').hidden &&
      createBtn.disabled === false && t.document.body.contains(nameEl),
      'status=' + JSON.stringify(pageStatus.textContent) + ' disabled=' + createBtn.disabled +
      ' open=' + t.api.open());
  }
  // there is no second, create-only status surface
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const statuses = t.document.querySelectorAll('#projectsPane .console-status');
    check(36, 'Projects has ONE status surface — no create-only status line',
      statuses.length === 1 && statuses[0].id === 'projectsStatus',
      'count=' + statuses.length);
  }
  // an empty (or whitespace-only) name posts nothing
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    const nameEl = t.document.getElementById('projectsNewName');
    nameEl.value = '';
    click(t.window, t.document.getElementById('projectsCreateBtn')); await tick(t.window, 90);
    key(t.window, nameEl, 'Enter'); await tick(t.window, 90);
    nameEl.value = '   ';
    click(t.window, t.document.getElementById('projectsCreateBtn')); await tick(t.window, 90);
    check(37, 'an empty or whitespace-only name POSTs nothing',
      t.requests.filter(r => r.method === 'POST').length === 0,
      'posts=' + t.requests.filter(r => r.method === 'POST').length);
  }
  // Escape on Projects still just leaves the page — the field is an input, so the
  // shell's "leave what you are editing alone" rule applies while it has focus
  {
    const t = boot();
    clickNav(t, 'navProjects'); await tick(t.window, 80);
    t.document.getElementById('projectsNewName').focus();
    key(t.window, t.document.getElementById('projectsNewName'), 'Escape'); await tick(t.window);
    const stayedWhileTyping = t.api.open() === 'projects';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(38, 'Escape in the name field leaves Projects open; Escape off it returns to the chat',
      stayedWhileTyping && t.api.open() === null && t.chatVisible(),
      'inField=' + stayedWhileTyping + ' after=' + t.api.open());
  }

  console.log(results.join('\n'));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail ? 1 : 0);
})();
