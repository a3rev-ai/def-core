/*
 * The card kit named, and the one Ask entry — behavioural harness (C5).
 *
 * Two things this PR touched, run against the SHIPPED code:
 *
 *  1. `askEntry` and the SEVEN calls to it. The helper is sliced by its markers;
 *     the calls are found by pattern (tests/browser/extract.js) and each is
 *     EXECUTED here against a real button, so a page that loses its entry, or
 *     asks for the wrong key, fails loudly. Named and unnamed both: a tenant that
 *     has named its assistant and one that has not read different labels.
 *  2. The kit's names. The acceptance test for C5 is that Projects renders
 *     IDENTICALLY, and the way this file proves it is structural: every kit class
 *     SHARES its rule with the `.project-*` / `.chat-menu` / `.documents-status`
 *     name it was derived from rather than restating the declarations, so the two
 *     cannot differ by a pixel. The two deliberate exceptions are declared below.
 *     The ⋯ menu and the touch sheet are then driven through the kit's names to
 *     show the shipped page really wears them.
 *
 * 27 checks.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const REPO = path.resolve(__dirname, '..', '..');
const extract = require('./extract');
const ASK = extract.askEntry();
const CALLS = extract.askEntryCalls();
const SHELL = extract.pageShell();
const PROJECTS = extract.projects();
const CSS = fs.readFileSync(path.join(REPO, 'assets/css/staff-ai.css'), 'utf8');
const JS = fs.readFileSync(path.join(REPO, 'assets/js/staff-ai.js'), 'utf8');
const SHELL_PHP = fs.readFileSync(path.join(REPO, 'templates/staff-ai-shell.php'), 'utf8');

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

// ── 1. the Ask entry ──────────────────────────────────────────────────────
//
// Each call is run in its own jsdom with the outer bindings stubbed at the seam.
// `t` is swapped between two modes: `keys` returns the key it was asked for (so a
// check can name the key without copying the prose), `defaults` returns the
// shipped English (so a check can see what a reader actually gets).

function runEntry(call, opts) {
  opts = opts || {};
  const dom = new JSDOM('<!doctype html><html><body>' +
    '<button id="b1"></button><button id="b2"></button>' +
    '<button id="scheduledAskAssistant"></button>' +
    '<textarea id="composerInput"></textarea></body></html>',
    { url: 'https://e.test/staff-ai/' });
  const window = dom.window, document = window.document;
  const composerInput = document.getElementById('composerInput');
  const log = [];
  const confirms = [];

  window.confirm = function (message) { confirms.push(message); return opts.confirm !== false; };

  // `onAssistantName` is the real one's shape — it REGISTERS and then calls — and
  // `nameArrives` is the status call landing afterwards, which is the whole reason
  // the console has a subscriber list: the tenant's name for the assistant is not
  // known when the page wires its buttons. The helper reads the live binding, so
  // reassigning it here is what a late `assistant_name` does on a real page.
  const subs = [];
  const realm = new window.Function(
    'window', 'document', 't', 'assistantName', 'onAssistantName', 'clearActiveProject',
    'resetToNewChat', 'composerInput', 'updateSendButton', 'sendMessage',
    ASK + '\nreturn { askEntry: askEntry, nameArrives: function (v) { assistantName = v; } };'
  )(
    window, document,
    opts.mode === 'defaults' ? function (key, def) { return def; } : function (key) { return key; },
    opts.assistantName === undefined ? 'Sue' : opts.assistantName,
    function (relabel) { subs.push(relabel); relabel(); },
    function () { log.push('clearActiveProject'); },
    function () { log.push('resetToNewChat'); },
    composerInput,
    function () { log.push('updateSendButton'); },
    function () { log.push('sendMessage'); }
  );
  const askEntry = realm.askEntry;

  // The buttons each call site reaches for, whatever shape it asks in.
  const b1 = document.getElementById('b1'), b2 = document.getElementById('b2');
  new window.Function(
    'window', 't', 'askEntry', 'askBtn', 'askBtns', 'resultsAskBtns',
    'nameEl', 'instructionEl', 'closeModal',
    call.source
  )(
    window,
    opts.mode === 'defaults' ? function (key, def) { return def; } : function (key) { return key; },
    askEntry, b1, [b1], [b1, b2],
    opts.dirty ? { value: 'Half a task' } : { value: '' },
    { value: '' },
    function () { log.push('closeModal'); }
  );

  // `scheduled` names its button by id; the rest were handed one directly.
  const btns = call.base === 'scheduled'
    ? [document.getElementById('scheduledAskAssistant')]
    : (call.base === 'scheduleResults' ? [b1, b2] : [b1]);

  return {
    window, document, composerInput, log, confirms, btns,
    click: function (i) {
      btns[i || 0].dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
    },
    // The status call landing: set the name, then run the subscribers it wakes.
    nameArrives: function (v) { realm.nameArrives(v); subs.forEach(function (f) { f(); }); }
  };
}

// ── 2. the kit's names ────────────────────────────────────────────────────
//
// Strip comments, then walk the stylesheet's rules so each simple class name maps
// to the set of declaration blocks it takes part in. Two names that always appear
// in the SAME blocks are the same styling, by construction.
//
// The limit, stated so it is not mistaken for more than it is: this compares
// MEMBERSHIP, not position within a selector. It proves the kit name and the name
// it replaced take part in the same rules with the same declarations; it would not
// catch a kit name wired into the wrong half of a descendant pair. Every such pair
// in the kit is one line apart from its twin below, which is what makes that
// readable by eye.

function ruleIndex(css) {
  const src = css.replace(/\/\*[\s\S]*?\*\//g, '');
  const byClass = new Map();
  const rules = [];
  const RULE = /([^{}]+)\{([^{}]*)\}/g;
  let m;
  while ((m = RULE.exec(src)) !== null) {
    const selector = m[1].trim();
    if (!selector || selector.startsWith('@')) continue;
    const id = rules.length;
    rules.push({ selector: selector, body: m[2].trim() });
    const seen = new Set();
    let c;
    const CLS = /\.([A-Za-z0-9_-]+)/g;
    while ((c = CLS.exec(selector)) !== null) seen.add(c[1]);
    seen.forEach(function (name) {
      if (!byClass.has(name)) byClass.set(name, new Set());
      byClass.get(name).add(id);
    });
  }
  return { rules: rules, byClass: byClass };
}

const INDEX = ruleIndex(CSS);

// Each kit name and the name it was derived from. Sharing every rule is what
// makes re-pointing Projects a no-op on screen.
const KIT = [
  ['console-card', 'project-card'],
  ['console-card-head', 'project-card-head'],
  ['console-card-actions', 'project-card-actions'],
  ['console-menu', 'chat-menu'],
  ['console-menu-item', 'chat-menu-item'],
  ['console-menu-item-danger', 'chat-menu-item-danger'],
  ['console-menu-sep', 'chat-menu-sep'],
  ['console-menu-btn', 'project-menu-btn'],
  ['console-menu-drop', 'project-manage-menu'],
  ['console-menu-sheet', 'project-manage'],
  ['console-menu-sheet-btn', 'project-manage-btn'],
  ['console-menu-sheet-list', 'project-manage-sheet'],
  ['console-slots', 'project-slots'],
  ['console-slot', 'project-slot'],
  ['console-slot-name', 'project-slot-name'],
  ['console-slot-empty', 'project-slot-empty'],
  ['console-slot-meta', 'project-slot-meta'],
  ['console-status', 'documents-status'],
  ['console-status-muted', 'documents-status-muted'],
  ['console-status-error', 'documents-status-error'],
  ['console-status-ok', 'integrations-status-ok']
];

// The rules a kit name deliberately does NOT take, each one stated rather than
// assumed — an undeclared difference is what this file exists to catch.
//
//  - `.chat-menu` alone: the chat row's menu is measured against the viewport and
//    pinned with position:fixed. A card's menu hangs off the card in normal flow,
//    which is the whole reason C2 dropped the measuring, so `.console-menu` is the
//    surface without the pinning.
//  - `.console-page-desc.documents-status-*`: the C4 document viewer folds its
//    status line INTO the page description, and wears both classes to win the
//    cascade. That is one page's compound, not a kit pattern; no page wearing the
//    kit's status line also wears `.console-page-desc`.
const EXCEPTIONS = [
  /^\.chat-menu$/,
  /^\.console-page-desc\.documents-status-(error|muted)$/
];
const EXCEPTED = sel => EXCEPTIONS.some(re => re.test(sel));

function sharedRules(kitName, oldName) {
  const kit = INDEX.byClass.get(kitName) || new Set();
  const was = INDEX.byClass.get(oldName) || new Set();
  const kitOnly = [], oldOnly = [];
  kit.forEach(id => { if (!was.has(id)) kitOnly.push(INDEX.rules[id].selector); });
  was.forEach(id => { if (!kit.has(id) && !EXCEPTED(INDEX.rules[id].selector)) oldOnly.push(INDEX.rules[id].selector); });
  return { count: kit.size, kitOnly: kitOnly, oldOnly: oldOnly };
}

// ── the ⋯ menu, driven through the kit's names ────────────────────────────

const PAGE_HTML = `<!doctype html><html><body>
<nav class="sidebar-nav">
  <a class="sidebar-nav-item" id="navProjects" href="#projects">Projects</a>
</nav>
<div id="conversationList"></div>
<div class="messages-container" id="messagesContainer"><div id="messageList"></div></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
<section class="console-page console-page-compact" id="projectsPane" hidden>
  <div class="console-page-head">
    <h1 class="console-page-title" id="projectsTitle" tabindex="-1">Projects</h1>
    <div class="console-page-actions">
      <button type="button" class="modal-btn modal-btn-secondary projects-ask-btn">Ask how Projects work</button>
      <input type="text" class="form-input projects-create-name" id="projectsNewName" maxlength="120">
      <button type="button" class="modal-btn modal-btn-primary" id="projectsCreateBtn">Create project</button>
    </div>
  </div>
  <label class="projects-archived-toggle"><input type="checkbox" id="projectsShowArchived"> Show archived</label>
  <div class="console-status" id="projectsStatus"></div>
  <div class="projects-list" id="projectsList"></div>
</section>
</body></html>`;

function bootProjects() {
  const dom = new JSDOM(PAGE_HTML, { url: 'https://e.test/staff-ai/', pretendToBeVisual: true });
  const window = dom.window, document = window.document;
  const messagesContainer = document.getElementById('messagesContainer');
  const composerContainer = document.getElementById('composerContainer');
  const composerInput = document.getElementById('composerInput');
  const conversationList = document.getElementById('conversationList');
  const calls = [];
  const api = new window.Function(
    'window', 'document', 'location', 'messagesContainer', 'composerContainer',
    'conversationList', 'calls',
    SHELL + '\nreturn { showPage: showPage, showChat: showChat, applyRoute: applyRoute,' +
    ' consolePages: consolePages, open: function () { return openPage ? openPage.route : null; } };'
  )(window, document, window.location, messagesContainer, composerContainer, conversationList, calls);

  const projects = [{ project_id: 'p1', name: 'Runsheets', status: 'active', created_at: '2026-09-01T10:00:00Z' }];
  async function apiRequest(url) {
    if (/\/tasks$/.test(url)) return { tasks: [] };
    if (/^\/projects(\?|$)/.test(url)) return { projects: projects };
    if (/^\/documents/.test(url)) return { documents: [] };
    return {};
  }
  const state = { sent: [] };
  new window.Function(
    'window', 'document', 'consolePages', 't', 'apiRequest', 'apiBase', 'assistantName',
    'onAssistantName', 'clearActiveProject', 'resetToNewChat', 'composerInput',
    'updateSendButton', 'sendMessage', 'setActiveProject', 'activeProjectId', 'formatTime',
    'openDocumentViewer', 'openDocumentsForProject', 'safeHttpHref', 'projectsCache',
    'showPage', 'askEntry', 'state', PROJECTS
  )(
    window, document, api.consolePages, function (key, def) { return def; },
    apiRequest, '/def/v1', 'Sue', function (fn) { fn(); },
    function () {}, function () { api.showChat({ focus: false }); },
    composerInput, function () {}, function () { state.sent.push(composerInput.value); },
    function () {}, null, function (x) { return String(x || ''); },
    function () {}, function () {}, function (u) { return u; }, [],
    api.showPage,
    extract.buildAskEntry(window, {
      assistantName: 'Sue', composerInput: composerInput,
      sendMessage: function () { state.sent.push(composerInput.value); }
    }),
    state
  );
  return {
    window, document, api, state,
    chatVisible: () => messagesContainer.style.display !== 'none' && composerContainer.style.display !== 'none'
  };
}

const tick = (w, n) => new Promise(r => w.setTimeout(r, n === undefined ? 40 : n));
function click(w, el) {
  el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
}
function key(w, target, k) {
  target.dispatchEvent(new w.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
}
// A page that has NOT been re-pointed renders no .console-card at all, so every
// check below has to report that rather than throw on a null.
const card = t => t.document.querySelector('#projectsList .console-card');
const within = (t, sel) => { const c = card(t); return c ? c.querySelector(sel) : null; };
const clickIf = (t, el) => { if (el) click(t.window, el); return !!el; };

(async () => {
  let n = 0;

  // ---- the seven entries ---------------------------------------------------
  check(++n, 'the console wires exactly SEVEN Ask entries, one per surface that has one',
    CALLS.length === 7 &&
    ['connections', 'documents', 'projects', 'memories', 'usage', 'scheduleResults', 'scheduled']
      .every(b => CALLS.some(c => c.base === b)),
    'found: ' + CALLS.map(c => c.base).join(', '));

  // Every entry asks for its own three keys, and each click sends the prompt key.
  {
    const wrongLabel = [], wrongPrompt = [], noChat = [];
    CALLS.forEach(function (call) {
      const t = runEntry(call, { mode: 'keys' });
      if (t.btns[0].textContent !== call.base + 'AskNamed') wrongLabel.push(call.base + '=' + t.btns[0].textContent);
      t.click(0);
      if (t.composerInput.value !== call.base + 'AskPrompt') wrongPrompt.push(call.base + '=' + t.composerInput.value);
      const order = t.log.filter(x => x !== 'closeModal').join(',');
      if (order !== 'clearActiveProject,resetToNewChat,updateSendButton,sendMessage') noChat.push(call.base + '=' + order);
    });
    check(++n, 'each entry labels itself from <base>AskNamed when the tenant has named its assistant',
      wrongLabel.length === 0, wrongLabel.join(' | '));
    check(++n, 'each entry puts its own <base>AskPrompt in the composer — no two share a prompt',
      wrongPrompt.length === 0, wrongPrompt.join(' | '));
    check(++n, 'each entry leaves the project, opens a fresh chat and sends, in that order',
      noChat.length === 0, noChat.join(' | '));
  }

  // The unnamed variant: a tenant that has not named its assistant.
  {
    const wrong = [];
    CALLS.forEach(function (call) {
      const t = runEntry(call, { mode: 'keys', assistantName: '' });
      if (t.btns[0].textContent !== call.base + 'Ask') wrong.push(call.base + '=' + t.btns[0].textContent);
    });
    check(++n, 'with no assistant name set, each entry falls back to its <base>Ask label',
      wrong.length === 0, wrong.join(' | '));
  }

  // What a reader actually sees: shipped English, with the name substituted.
  {
    const prompts = [], labels = [], leftovers = [], unnamedHasName = [];
    CALLS.forEach(function (call) {
      const t = runEntry(call, { mode: 'defaults' });
      labels.push(t.btns[0].textContent);
      if (/%s/.test(t.btns[0].textContent) || !/Sue/.test(t.btns[0].textContent)) leftovers.push(call.base + '=' + t.btns[0].textContent);
      t.click(0);
      prompts.push(t.composerInput.value);
      const u = runEntry(call, { mode: 'defaults', assistantName: '' });
      if (/%s|Sue/.test(u.btns[0].textContent)) unnamedHasName.push(call.base + '=' + u.btns[0].textContent);
    });
    check(++n, 'the named label substitutes the tenant\'s name for %s and leaves no placeholder behind',
      leftovers.length === 0, leftovers.join(' | '));
    check(++n, 'the unnamed label carries neither the placeholder nor a name it was never given',
      unnamedHasName.length === 0, unnamedHasName.join(' | '));
    check(++n, 'all seven prompts are real, distinct English sentences',
      new Set(prompts).size === 7 && prompts.every(p => p.length > 30 && /[?.]$/.test(p)),
      'distinct=' + new Set(prompts).size + ' shortest=' + Math.min.apply(null, prompts.map(p => p.length)));
    check(++n, 'all seven labels are distinct — no two entries offer the same words',
      new Set(labels).size === 7, 'distinct=' + new Set(labels).size);
  }

  // The reason the subscriber list exists: on a real page the buttons are wired
  // before the status call says what the tenant calls its assistant. Every entry
  // has to relabel itself when it lands — reading the name at wiring time would
  // leave all seven saying "your assistant" for ever, and no check above would
  // see it, because they all boot with the name already there.
  {
    const stuck = [];
    CALLS.forEach(function (call) {
      const t = runEntry(call, { mode: 'keys', assistantName: '' });
      const before = t.btns[0].textContent;
      t.nameArrives('Sue');
      if (before !== call.base + 'Ask' || t.btns[0].textContent !== call.base + 'AskNamed') {
        stuck.push(call.base + '=' + before + '→' + t.btns[0].textContent);
      }
    });
    check(++n, 'a name that arrives AFTER the buttons are wired relabels every one of the seven',
      stuck.length === 0, stuck.join(' | '));
  }

  // A name is the tenant's own text, not a replacement pattern.
  {
    const call = CALLS.find(c => c.base === 'projects');
    const t = runEntry(call, { mode: 'defaults', assistantName: "$& Ru$'th" });
    check(++n, "an assistant name containing $& or $' reaches the label as typed, not as a substitution",
      t.btns[0].textContent === "Ask $& Ru$'th how Projects work", 'label=' + JSON.stringify(t.btns[0].textContent));
  }

  // Both buttons of the repeated entry are wired, not just the first.
  {
    const call = CALLS.find(c => c.base === 'scheduleResults');
    const t = runEntry(call, { mode: 'keys' });
    const labelled = t.btns.every(b => b.textContent === 'scheduleResultsAskNamed');
    t.click(1);
    check(++n, 'the entry that appears twice in the creator wires BOTH buttons, label and click',
      t.btns.length === 2 && labelled && t.composerInput.value === 'scheduleResultsAskPrompt',
      'labelled=' + labelled + ' sent=' + JSON.stringify(t.composerInput.value));
  }

  // The creator's `before` hook — the only entry that can be called off.
  {
    const call = CALLS.find(c => c.base === 'scheduleResults');
    const declined = runEntry(call, { mode: 'keys', dirty: true, confirm: false });
    declined.click(0);
    check(++n, 'a half-written task asks before leaving, and a declined confirm sends nothing and keeps the form',
      declined.confirms.length === 1 && declined.log.length === 0 && declined.composerInput.value === '',
      'confirms=' + declined.confirms.length + ' log=[' + declined.log + ']');

    const accepted = runEntry(call, { mode: 'keys', dirty: true, confirm: true });
    accepted.click(0);
    check(++n, 'an accepted confirm closes the creator FIRST, then opens the chat',
      accepted.confirms.length === 1 &&
      accepted.log.join(',') === 'closeModal,clearActiveProject,resetToNewChat,updateSendButton,sendMessage',
      'log=[' + accepted.log + ']');

    const clean = runEntry(call, { mode: 'keys', dirty: false });
    clean.click(0);
    check(++n, 'an empty creator does not ask at all — there is nothing to discard',
      clean.confirms.length === 0 && clean.log[0] === 'closeModal' && clean.composerInput.value === 'scheduleResultsAskPrompt',
      'confirms=' + clean.confirms.length + ' log=[' + clean.log + ']');
  }

  // A surface whose button is not in the DOM must not throw on the way past.
  {
    const call = CALLS.find(c => c.base === 'usage');
    let threw = null;
    try {
      const dom = new JSDOM('<!doctype html><html><body><textarea id="composerInput"></textarea></body></html>');
      const w = dom.window;
      const askEntry = new w.Function('window', 'document', 't', 'assistantName', 'onAssistantName',
        'clearActiveProject', 'resetToNewChat', 'composerInput', 'updateSendButton', 'sendMessage',
        ASK + '\nreturn askEntry;')(w, w.document, k => k, 'Sue', fn => fn(),
        () => {}, () => {}, w.document.getElementById('composerInput'), () => {}, () => {});
      askEntry('nothingHere', 'usage', { named: 'a', plain: 'b', prompt: 'c' });
      askEntry(null, 'usage', { named: 'a', plain: 'b', prompt: 'c' });
    } catch (e) { threw = e; }
    check(++n, 'an entry whose button is not on the page is a no-op, not a thrown error',
      threw === null, threw && threw.message);
  }

  // The seven blocks it replaced are gone from the shipped JS.
  {
    const left = (JS.match(/function labelAsk\(/g) || []).length +
      (JS.match(/function labelResultsAsk\(/g) || []).length +
      (JS.match(/function labelScheduledAsk\(/g) || []).length;
    check(++n, 'no hand-rolled Ask block survives in the shipped JS — all seven go through the helper',
      left === 0, 'left=' + left);
  }

  // ---- the kit's names -----------------------------------------------------
  {
    const drifted = [];
    KIT.forEach(function (pair) {
      const r = sharedRules(pair[0], pair[1]);
      if (r.count === 0) { drifted.push(pair[0] + ': no rule in the stylesheet at all'); return; }
      if (r.kitOnly.length) drifted.push(pair[0] + ' alone in: ' + r.kitOnly.join(' / '));
      if (r.oldOnly.length) drifted.push(pair[1] + ' alone in: ' + r.oldOnly.join(' / '));
    });
    check(++n, 'PROOF: every kit name SHARES every rule with the name it replaced — it cannot render differently',
      drifted.length === 0, drifted.join(' | '));

    // The chat row's exception, checked rather than taken on trust: the rule
    // .console-menu skips must be the pinning and nothing else.
    const chatFixed = INDEX.rules.filter(r => /^\.chat-menu$/.test(r.selector));
    check(++n, "the one rule .console-menu does NOT take is the chat row's position:fixed, and that rule is all it is",
      chatFixed.length === 1 && /^position:\s*fixed;?$/.test(chatFixed[0].body.replace(/\s+/g, ' ').trim()),
      'rules=' + chatFixed.length + ' body=' + JSON.stringify(chatFixed.map(r => r.body).join('')));

    // A kit name that styles something on its own would be a restyle, not a rename.
    const orphans = Array.from(INDEX.byClass.keys())
      .filter(name => /^console-(card|menu|slot|status)/.test(name))
      .filter(name => !KIT.some(p => p[0] === name));
    check(++n, 'the kit introduces no name beyond the ones it declares — nothing was restyled under a new label',
      orphans.length === 0, 'stray: ' + orphans.join(', '));
  }

  // ---- Projects really wears it -------------------------------------------
  {
    // Every name the kit replaced. `project-card-archived` is NOT here: archived is
    // a Projects state, not a kit pattern, so the block still writes it — and the
    // leading quote in the test below is what keeps it from matching that.
    const oldNames = ['project-card', 'project-card-head', 'project-card-actions',
      'project-menu-btn', 'project-manage-menu', 'project-manage-sheet', 'project-manage-btn',
      'project-slots', 'project-slot', 'chat-menu-item', 'chat-menu-sep', 'documents-status'];
    const start = JS.indexOf('\t(function initProjects() {');
    const end = JS.indexOf('\t(function initMemories() {');
    const block = JS.slice(start, end);
    // The name must be COMPLETE at the start of a class literal — `'project-card'`
    // or `'project-card …'`. A \b would end the match inside `'project-card-id'`,
    // which the card still writes and which the kit never claimed.
    const left = oldNames.filter(nm => new RegExp("'" + nm + "['\\s]").test(block));
    check(++n, 'the shipped Projects block builds its markup from the kit, not from its own 7.8.0 names',
      start > 0 && end > start && left.length === 0, 'left: ' + left.join(', '));
    check(++n, 'the shipped template gives the Projects status line the kit name',
      /<div class="console-status" id="projectsStatus">/.test(SHELL_PHP) &&
      !/class="documents-status" id="projectsStatus"/.test(SHELL_PHP));
  }

  // ---- the ⋯ menu and the touch sheet, through the kit's names -------------
  {
    const t = bootProjects();
    click(t.window, t.document.getElementById('navProjects')); await tick(t.window, 80);
    const c = card(t);
    clickIf(t, within(t, '.console-menu-btn')); await tick(t.window);
    const menu = t.document.querySelector('.console-menu-drop');
    check(++n, 'the ⋯ menu opens on Projects and is a .console-menu anchored in the card\'s .console-card-actions',
      !!c && !!menu && c.contains(menu) && menu.classList.contains('console-menu') &&
      menu.parentNode.classList.contains('console-card-actions') &&
      menu.querySelectorAll('.console-menu-item').length > 0 &&
      menu.querySelectorAll('.console-menu-sep').length === 1,
      'menu=' + (menu && menu.className));
  }
  {
    const t = bootProjects();
    click(t.window, t.document.getElementById('navProjects')); await tick(t.window, 80);
    const raised = clickIf(t, within(t, '.console-menu-btn')); await tick(t.window);
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const menuGone = raised && !t.document.querySelector('.console-menu-drop');
    const stillOnPage = t.api.open() === 'projects';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(++n, 'Escape closes the ⋯ menu first and leaves the page second — the shell stands aside for a .console-menu',
      menuGone && stillOnPage && t.api.open() === null && t.chatVisible(),
      'menuGone=' + menuGone + ' stillOnPage=' + stillOnPage + ' final=' + t.api.open());
  }
  {
    const t = bootProjects();
    click(t.window, t.document.getElementById('navProjects')); await tick(t.window, 80);
    const btn = within(t, '.console-menu-sheet-btn');
    const sheet = within(t, '.console-menu-sheet-list');
    const startsClosed = !!btn && !!sheet && sheet.hidden && btn.getAttribute('aria-expanded') === 'false'
      && btn.getAttribute('aria-controls') === sheet.id;
    clickIf(t, btn); await tick(t.window);
    const opened = startsClosed && !sheet.hidden && btn.getAttribute('aria-expanded') === 'true'
      && sheet.classList.contains('console-menu')
      && sheet.querySelectorAll('.console-menu-item').length > 0;
    clickIf(t, btn); await tick(t.window);
    check(++n, 'the touch sheet still expands in place under Open Project and folds away again',
      startsClosed && opened && sheet.hidden && btn.getAttribute('aria-expanded') === 'false',
      'closed=' + startsClosed + ' opened=' + opened);
  }
  {
    const t = bootProjects();
    click(t.window, t.document.getElementById('navProjects')); await tick(t.window, 80);
    const btn = within(t, '.console-menu-sheet-btn');
    clickIf(t, btn); await tick(t.window);
    const wasOpen = !!btn && btn.getAttribute('aria-expanded') === 'true';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const sheetClosed = wasOpen && btn.getAttribute('aria-expanded') === 'false' && t.api.open() === 'projects';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(++n, 'an OPEN touch sheet takes the first Escape and the page leaves on the second',
      wasOpen && sheetClosed && t.api.open() === null, 'open=' + wasOpen + ' closed=' + sheetClosed);
  }
  {
    const t = bootProjects();
    click(t.window, t.document.getElementById('navProjects')); await tick(t.window, 80);
    const hidden = t.document.querySelectorAll('.console-menu-sheet-list[hidden]').length;
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(++n, "the card's HIDDEN touch sheet is a .console-menu that does not hold Escape hostage",
      hidden === 1 && t.api.open() === null && t.chatVisible(), 'hidden=' + hidden + ' open=' + t.api.open());
  }

  console.log('\n' + results.join('\n'));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail > 0 ? 1 : 0);
})();
