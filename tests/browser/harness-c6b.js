/*
 * Scheduled on the card kit — behavioural harness (C6b / v7.9.5).
 *
 * Runs the SHIPPED page shell and the SHIPPED initScheduled block (both sliced by
 * marker, tests/browser/extract.js) inside jsdom, against the SHIPPED
 * #scheduledPane section and #scheduleModal markup out of templates/staff-ai-shell.php.
 *
 *  1. The proof, C6a's run over this page. Two kinds, kept apart:
 *     - every kit name the block WRITES shares every rule with the name the kit was
 *       derived from (C5's table), so Scheduled wears the kit as it is rather than a
 *       variant of its own. The card's look changed on purpose — Edit and Remove
 *       moved behind the ⋯ menu — so this is a proof about names, not an identical
 *       render of the old card;
 *     - Run now is the one name JOINED here: it keeps its own class and shares every
 *       rule with Projects' Open Project.
 *  2. The ⋯ menu and its touch sheet on a task card AND a triage card, built once for
 *     both, and Edit and Remove driven through them to what the old icon buttons
 *     did. Run now stays on the card.
 *  3. The page's status line on the kit, its ok state included; the creator's own
 *     two lines left as they are.
 *
 * 19 checks.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');
const SHELL = extract.pageShell();
const SCHEDULED = extract.scheduled();
// C7: the menu machinery is shared and sits outside the page block.
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

// ── 1. the proof ──────────────────────────────────────────────────────────
//
// Each kit name the Scheduled block writes, and the name it is held to. The card,
// the menu and the status line all changed their look on this page (the old card's
// padding, its icon buttons, the status line's green pill), so there is no old
// Scheduled rendering to hold them to: each is held to the name the kit was derived
// from, which is what catches a Scheduled variant of the kit.
const REPLACED = {
  'console-card': 'project-card',
  'console-card-head': 'project-card-head',
  'console-card-actions': 'project-card-actions',
  'console-menu': 'chat-menu',
  'console-menu-item': 'chat-menu-item',
  'console-menu-item-danger': 'chat-menu-item-danger',
  'console-menu-sep': 'chat-menu-sep',
  'console-menu-btn': 'project-menu-btn',
  'console-menu-drop': 'project-manage-menu',
  'console-menu-sheet': 'project-manage',
  'console-menu-sheet-btn': 'project-manage-btn',
  'console-menu-sheet-list': 'project-manage-sheet',
  'console-status': 'documents-status',
  // 'console-status-ok': 'integrations-status-ok' â€” retired in C6c with Connections'
  // own status family; see the note in harness-c5.js.
  'console-status-muted': 'documents-status-muted',
  'console-status-error': 'documents-status-error',
  // null = its predecessor has RETIRED (C6c took Connections' own status family with it),
  // so there is nothing left to hold this name against. Declared rather than dropped:
  // this table is also the list of names the kit owns. See the note in harness-c5.js.
  'console-status-ok': null
};

// The rules a derived-from name keeps that its kit name deliberately does not take
// (C5's two, unchanged):
//  - `.chat-menu` alone: the chat row's menu is pinned with position:fixed; a card's
//    menu hangs off the card.
//  - `.console-page-desc.documents-status-*`: the document viewer's status line,
//    folded into its page description (C4). No page wearing the kit's line has it.
const EXCEPTIONS = [/^\.chat-menu$/, /^\.console-page-desc\.documents-status-(error|muted)$/];
const EXCEPTED = sel => EXCEPTIONS.some(re => re.test(sel));

function drift(kitName, oldName) {
  const kit = INDEX.byClass.get(kitName) || new Set();
  const was = INDEX.byClass.get(oldName) || new Set();
  const out = [];
  if (!kit.size) out.push(kitName + ': no rule at all');
  kit.forEach(id => { if (!was.has(id)) out.push(kitName + ' alone in ' + INDEX.rules[id].selector); });
  was.forEach(id => {
    if (!kit.has(id) && !EXCEPTED(INDEX.rules[id].selector)) out.push(oldName + ' alone in ' + INDEX.rules[id].selector);
  });
  return out;
}

// The kit names the shipped block writes, read off its code with the comments
// stripped. `'console-status-' + (kind || 'muted')` reads as console-status; its
// kinds are the default and the ones setStatus is called with.
// C7: a card's code is the page block plus the shared menu it delegates to.
const CODE = (MENU + '\n' + SCHEDULED).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
const WRITTEN = new Set(CODE.match(/\bconsole-[a-z]+(?:-[a-z]+)*\b/g) || []);
if (WRITTEN.has('console-status')) {
  (CODE.match(/(?:,|\|\|)\s*'(muted|error|ok)'\)/g) || []).forEach(m => WRITTEN.add('console-status-' + /'(\w+)'/.exec(m)[1]));
}

// ── 2. the page, booted ───────────────────────────────────────────────────

const HTML = `<!doctype html><html><body>
${extract.templateNav()}
<div id="conversationList"></div>
<div class="messages-container" id="messagesContainer"><div id="messageList"></div></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
${extract.templatePage('scheduledPane')}
${extract.templateModal('scheduleModal')}
</body></html>`;

const TRIAGE = { schedule_id: 's1', enabled: true, send_hour_local: 7, send_minute_local: 0,
  timezone: 'UTC', destinations: ['email'], connected_account_id: '', last_run: null };
const TASK = { id: 't1', name: 'Daily brief', instruction: 'Write my morning brief.', enabled: true,
  cadence: 'daily', send_weekday: 0, send_hour_local: 7, send_minute_local: 0, timezone: 'UTC',
  destinations: ['email'], model: '', project_id: null, last_run: null };
const OFF = Object.assign({}, TASK, { id: 't2', name: 'Parked idea', enabled: false });

function boot(opts) {
  opts = opts || {};
  const dom = new JSDOM(HTML, { url: 'https://e.test/staff-ai/', pretendToBeVisual: true });
  const window = dom.window, document = window.document;
  const messagesContainer = document.getElementById('messagesContainer');
  const composerContainer = document.getElementById('composerContainer');
  const api = new window.Function(
    'window', 'document', 'location', 'messagesContainer', 'composerContainer', 'conversationList',
    SHELL + '\nreturn { showPage: showPage, showChat: showChat, consolePages: consolePages,' +
    ' open: function () { return openPage ? openPage.route : null; } };'
  )(window, document, window.location, messagesContainer, composerContainer,
    document.getElementById('conversationList'));

  // `apiRequest` answers from fixtures and records every call; `holdPost` and
  // `holdDelete` keep that request out until the check releases it.
  const state = { requests: [], confirms: [], release: null };
  window.confirm = function (msg) { state.confirms.push(msg); return opts.confirm !== false; };
  async function apiRequest(url, init) {
    const method = (init && init.method) || 'GET';
    state.requests.push({ url: url, method: method });
    if (method === 'DELETE') {
      if (opts.holdDelete) await new Promise(r => { state.release = r; });
      if (opts.failDelete) throw new Error('DEF said no');
      return {};
    }
    if (method === 'POST') {
      if (opts.holdPost) await new Promise(r => { state.release = r; });
      return {};
    }
    if (url === '/tasks') {
      if (opts.failTasks) throw new Error('DEF said no');
      return { tasks: [TASK, OFF] };
    }
    if (url === '/triage-schedules') return { schedules: [TRIAGE] };
    if (url === '/mail-connections') return { connections: [] };
    if (url === '/user/integrations') return { apps: [] };
    if (/^\/projects/.test(url)) return { projects: [] };
    return {};
  }

  // Run now's watcher polls every 5s. The block's clock is handed in (code compiled
  // in a jsdom window does not see that window's setTimeout swapped), so
  // `fastPoll` lets a check see the poll's re-render.
  const clock = (fn, ms) => window.setTimeout(fn, opts.fastPoll && ms === 5000 ? 20 : ms);
  const composerInput = document.getElementById('composerInput');
  new window.Function(
    'window', 'document', 'consolePages', 't', 'apiRequest', 'apiBase', 'userEmail', 'projectsCache',
    'askEntry', 'setTimeout', MENU + '\n' + SCHEDULED
  )(
    window, document, api.consolePages, function (key, def) { return def; }, apiRequest, '/def/v1',
    'you@example.test', [], extract.buildAskEntry(window, { composerInput: composerInput }), clock
  );

  return {
    window, document, api, state,
    // A page that does not wear the kit renders no .console-card, so every lookup
    // below reports that rather than throwing on a null.
    card: name => Array.from(document.querySelectorAll('#taskCardGrid .console-card'))
      .find(c => { const nm = c.querySelector('.task-card-name'); return nm && nm.textContent === name; }) || null,
    line: () => {
      const s = document.getElementById('scheduledPaneStatus');
      return s.className + ' :: ' + s.textContent;
    },
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
const within = (el, sel) => el ? el.querySelector(sel) : null;
const clickIf = (t, el) => { if (el) click(t.window, el); return !!el; };
async function enter(t) { t.api.showPage('scheduled'); await tick(t.window, 80); }
async function openMenu(t, name) {
  clickIf(t, within(t.card(name), '.console-menu-btn'));
  await tick(t.window);
  return t.document.querySelector('.console-menu-drop');
}
// A menu read as its reader meets it: labels in order, — for the divider, ! for danger.
const items = menu => menu ? Array.from(menu.children).map(el => el.classList.contains('console-menu-sep')
  ? '—' : el.textContent + (el.classList.contains('console-menu-item-danger') ? '!' : '')) : [];
const item = (menu, label) => menu
  ? Array.from(menu.querySelectorAll('.console-menu-item')).find(el => el.textContent === label) || null : null;

(async () => {
  let n = 0;

  // ---- 1. the proof ---------------------------------------------------------
  {
    const drifted = [];
    WRITTEN.forEach(name => { if (REPLACED[name]) drifted.push.apply(drifted, drift(name, REPLACED[name])); });
    check(++n, 'PROOF: every kit name Scheduled takes SHARES every rule with the name it replaced — no Scheduled variant of the kit',
      WRITTEN.size > 0 && drifted.length === 0, drifted.join(' | ') || 'written=' + WRITTEN.size);

    // `in`, not truthiness: a name whose predecessor has retired is declared with
    // null, and is still owned by the kit. Line 214 above correctly uses truthiness,
    // because THAT test needs an old name to compare against.
    const undeclared = Array.from(WRITTEN).filter(name => !(name in REPLACED));
    const unused = Object.keys(REPLACED).filter(name => !WRITTEN.has(name));
    check(++n, 'the table above IS the page: every kit name the shipped block writes is declared, and none declared goes unwritten',
      undeclared.length === 0 && unused.length === 0,
      'undeclared: ' + undeclared.join(', ') + ' / unused: ' + unused.join(', '));

    const run = drift('task-card-run', 'project-open-btn');
    check(++n, "Run now is the card's one filled action, JOINED to Open Project's rules — it shares every one of them",
      /'modal-btn modal-btn-primary task-card-run'/.test(CODE) && run.length === 0, run.join(' | '));

    // A whole class token anywhere in a string literal: `'console-card task-card'`
    // counts, `'task-card-name'` does not.
    const left = ['task-card', 'task-card-head', 'task-card-actions', 'task-card-icon']
      .filter(nm => new RegExp("'(?:[^'\\n]*\\s)?" + nm + "(?=['\\s])").test(CODE));
    check(++n, 'the shipped block no longer writes a name the kit replaced — no card, head, action row or icon button of its own',
      left.length === 0, 'left: ' + left.join(', '));

    // Two card types share baseCard, and two copies of a menu are how they drift.
    const count = s => CODE.split(s).length - 1;
    const body = name => {
      const at = CODE.indexOf('function ' + name + '(');
      const next = CODE.indexOf('\n\t\tfunction ', at + 1);
      return at < 0 ? '' : CODE.slice(at, next < 0 ? undefined : next);
    };
    const builders = ["'console-menu-btn'", "'console-menu console-menu-drop'",
      "'console-menu console-menu-sheet-list'", "'console-menu-sheet-btn'"].map(count);
    const renderers = ['renderTaskCard', 'renderTriageCard'].map(body);
    check(++n, 'ONE menu builder: the ⋯ button, its popover and its touch sheet are each built in one place, and neither card renderer builds any of them — each hands baseCard its own items',
      builders.every(c => c === 1) &&
      renderers.every(b => /baseCard\(/.test(b) && /onPick/.test(b) && !/console-menu/.test(b)),
      'built=' + builders.join(',') + ' renderers=' + renderers.map(b => b.length).join(','));
  }

  // ---- 2. the cards --------------------------------------------------------
  {
    const t = boot(); await enter(t);
    const face = c => {
      const head = c && Array.from(c.children).find(el => el.classList.contains('console-card-head'));
      const acts = head && Array.from(head.children).find(el => el.classList.contains('console-card-actions'));
      return head && acts && head.firstElementChild.classList.contains('task-card-name')
        ? Array.from(acts.children).map(el => el.textContent) : null;
    };
    // The moved actions exist only inside the menus now, never on the card's face.
    const loose = c => !c || Array.from(c.querySelectorAll('button, a'))
      .some(el => /^(Edit|Remove|✎|✕)$/.test(el.textContent) && !el.closest('.console-menu'));
    const cards = ['Email Triage', 'Daily brief', 'Parked idea'].map(t.card);
    const faces = cards.map(face);
    check(++n, "a triage card and a task card are each a .console-card whose head holds the name and the actions, Run now and ⋯ (a disabled task only ⋯), and Edit and Remove are nowhere on a card's face",
      JSON.stringify(faces) === JSON.stringify([['Run now', '•••'], ['Run now', '•••'], ['•••']]) &&
      cards.every(c => !loose(c)),
      'faces=' + JSON.stringify(faces));
  }
  {
    const t = boot(); await enter(t);
    const off = t.card('Parked idea');
    const offRun = !off || Array.from(off.querySelectorAll('button')).some(b => b.textContent === 'Run now');
    const runBtn = within(t.card('Daily brief'), '.task-card-run');
    clickIf(t, runBtn); await tick(t.window);
    const said = t.line();
    const queued = within(t.card('Daily brief'), '.task-last-run');
    clickIf(t, within(t.card('Email Triage'), '.task-card-run')); await tick(t.window);
    const posts = t.state.requests.filter(r => r.method === 'POST').map(r => r.url);
    t.window.close();
    check(++n, "Run now is still on the card and still fires — a task's asks for its run and a triage's for its own, and the card says it is queued — and a disabled task still has none",
      !!runBtn && !offRun &&
      JSON.stringify(posts) === JSON.stringify(['/tasks/t1/run-now', '/triage-schedules/s1/run-now']) &&
      /The task will run shortly/.test(said) && !!queued && queued.textContent === 'Queued — starts on the next run cycle',
      'offRun=' + offRun + ' posts=' + JSON.stringify(posts) + ' said=' + said + ' queued=' + (queued && queued.textContent));
  }

  // ---- 3. the status lines ---------------------------------------------------
  {
    const tpl = /<div class="console-status" id="scheduledPaneStatus">/.test(TEMPLATE);
    const failed = boot({ failTasks: true }); await enter(failed);
    const t = boot({ holdPost: true }); await enter(t);
    const idle = t.line();
    clickIf(t, within(t.card('Daily brief'), '.task-card-run')); await tick(t.window);
    const asking = t.line();
    if (t.state.release) t.state.release();
    await tick(t.window);
    const done = t.line();
    t.window.close();
    check(++n, "the page's status line speaks through the kit — console-status, -muted while it works, -error when a read fails and -ok when a run is on its way",
      tpl && failed.line() === 'console-status console-status-error :: DEF said no' &&
      idle === 'console-status :: ' && asking === 'console-status console-status-muted :: Asking for a run…' &&
      done === 'console-status console-status-ok :: The task will run shortly. Its output arrives at the destinations you chose.',
      [tpl, failed.line(), idle, asking, done].join(' / '));
  }
  {
    const t = boot(); await enter(t);
    click(t.window, t.document.getElementById('taskCreateBtn')); await tick(t.window);
    click(t.window, t.document.getElementById('scheduleSave')); await tick(t.window);
    const own = t.document.getElementById('taskStatus');
    check(++n, "the creator's own status lines are not the page's: they keep schedule-status and its kinds",
      own.className === 'schedule-status schedule-status-error' && own.textContent === 'Give the task a name.' &&
      /<div class="schedule-status" id="taskStatus">/.test(TEMPLATE) && /<div class="schedule-status" id="scheduleStatus">/.test(TEMPLATE),
      own.className + ' :: ' + own.textContent);
  }

  // ---- 4. the ⋯ menu and the touch sheet ------------------------------------
  {
    const t = boot(); await enter(t);
    const seen = [];
    for (const name of ['Daily brief', 'Email Triage']) {
      const c = t.card(name);
      const btn = within(c, '.console-menu-btn');
      const menu = await openMenu(t, name);
      seen.push(name + ': ' + (!!menu && c.contains(menu) && menu.classList.contains('console-menu') &&
        menu.parentNode.classList.contains('console-card-actions') &&
        items(menu).join('|') === 'Edit|—|Remove!' && btn.getAttribute('aria-expanded') === 'true' &&
        btn.getAttribute('aria-label') === 'Manage task' && t.document.activeElement === menu.firstElementChild));
      click(t.window, t.document.body); await tick(t.window);
    }
    check(++n, "the ⋯ menu opens on a task card AND a triage card, each a .console-menu anchored in that card's .console-card-actions",
      seen.join(', ') === 'Daily brief: true, Email Triage: true', seen.join(', '));
  }
  {
    const t = boot(); await enter(t);
    const menu = await openMenu(t, 'Daily brief');
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const menuGone = !!menu && !t.document.querySelector('.console-menu-drop');
    const stillOnPage = t.api.open() === 'scheduled';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(++n, 'Escape closes the ⋯ menu first and leaves Scheduled second',
      menuGone && stillOnPage && t.api.open() === null && t.chatVisible(),
      'menuGone=' + menuGone + ' stillOnPage=' + stillOnPage + ' final=' + t.api.open());
  }
  {
    const t = boot(); await enter(t);
    const c = t.card('Email Triage');
    const btn = within(c, '.console-menu-sheet-btn');
    const sheet = within(c, '.console-menu-sheet-list');
    const ids = Array.from(t.document.querySelectorAll('.console-menu-sheet-list')).map(s => s.id);
    const startsClosed = !!btn && !!sheet && sheet.hidden && btn.parentNode.classList.contains('console-menu-sheet') &&
      btn.getAttribute('aria-expanded') === 'false' && btn.getAttribute('aria-controls') === sheet.id &&
      ids.length === 3 && new Set(ids).size === 3;
    clickIf(t, btn); await tick(t.window);
    const opened = startsClosed && !sheet.hidden && btn.getAttribute('aria-expanded') === 'true' &&
      sheet.classList.contains('console-menu') && items(sheet).join('|') === 'Edit|—|Remove!';
    clickIf(t, btn); await tick(t.window);
    check(++n, 'the touch sheet expands in place with the same actions and folds away again',
      startsClosed && opened && sheet.hidden && btn.getAttribute('aria-expanded') === 'false',
      'closed=' + startsClosed + ' opened=' + opened + ' ids=' + ids.join(','));
  }
  {
    const t = boot(); await enter(t);
    const hidden = t.document.querySelectorAll('.console-menu-sheet-list[hidden]').length;
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(++n, "a card's HIDDEN touch sheet does not hold Escape — one press leaves Scheduled",
      hidden === 3 && t.api.open() === null && t.chatVisible(), 'hidden=' + hidden + ' open=' + t.api.open());
  }
  {
    const t = boot(); await enter(t);
    const btn = within(t.card('Daily brief'), '.console-menu-sheet-btn');
    clickIf(t, btn); await tick(t.window);
    const wasOpen = !!btn && btn.getAttribute('aria-expanded') === 'true';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const folded = wasOpen && btn.getAttribute('aria-expanded') === 'false' && t.api.open() === 'scheduled';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(++n, 'an OPEN touch sheet takes the first Escape and the page leaves on the second',
      wasOpen && folded && t.api.open() === null, 'open=' + wasOpen + ' folded=' + folded);
  }
  {
    const t = boot(); await enter(t);
    await openMenu(t, 'Email Triage');
    clickIf(t, within(t.card('Parked idea'), '.console-menu-sheet-btn')); await tick(t.window);
    const bothOpen = !!t.document.querySelector('.console-menu-drop') &&
      t.document.querySelectorAll('.console-menu-sheet-list:not([hidden])').length === 1;
    t.api.showChat(); await tick(t.window);
    const left = t.document.querySelectorAll('.console-menu:not([hidden])').length;
    check(++n, 'leaving Scheduled closes an open ⋯ menu and an expanded sheet, so neither holds Escape on the next page',
      bothOpen && left === 0, 'bothOpen=' + bothOpen + ' left=' + left);
  }
  {
    // Run now's watcher re-renders the grid on every poll. The old icons were simply
    // rebuilt; a menu the user has open must not vanish under them.
    const t = boot({ fastPoll: true }); await enter(t);
    clickIf(t, within(t.card('Daily brief'), '.task-card-run')); await tick(t.window);
    await openMenu(t, 'Email Triage');
    clickIf(t, within(t.card('Parked idea'), '.console-menu-sheet-btn'));
    const before = t.card('Email Triage');
    await tick(t.window, 80);
    const tri = t.card('Email Triage'), off = t.card('Parked idea');
    const rebuilt = !!before && !!tri && !t.document.contains(before);
    const drop = t.document.querySelector('.console-menu-drop');
    const menuKept = !!drop && tri.contains(drop) && within(tri, '.console-menu-btn').getAttribute('aria-expanded') === 'true';
    const sheetKept = !!off && !within(off, '.console-menu-sheet-list').hidden &&
      within(off, '.console-menu-sheet-btn').getAttribute('aria-expanded') === 'true';
    t.window.close();
    check(++n, 'a pending run re-renders the cards, and the ⋯ menu open on one of them stays open, as does an expanded sheet',
      rebuilt && menuKept && sheetKept, 'rebuilt=' + rebuilt + ' menu=' + menuKept + ' sheet=' + sheetKept);
  }

  // ---- 5. the two actions, from the menu ------------------------------------
  {
    const t = boot(); await enter(t);
    const $ = id => t.document.getElementById(id);
    const modal = $('scheduleModal');
    // A task, from the popover.
    const menu = await openMenu(t, 'Daily brief');
    clickIf(t, item(menu, 'Edit')); await tick(t.window);
    const taskForm = !!menu && !t.document.querySelector('.console-menu-drop') && modal.classList.contains('visible') &&
      $('scheduleTitle').textContent === 'Edit scheduled task' && $('taskName').value === 'Daily brief' &&
      $('taskFormView').style.display !== 'none' && $('taskTypeRow').style.display === 'none';
    if (taskForm) { click(t.window, $('scheduleSave')); await tick(t.window, 80); }
    // A triage setup, from the touch sheet.
    const tri = t.card('Email Triage');
    clickIf(t, within(tri, '.console-menu-sheet-btn')); await tick(t.window);
    clickIf(t, item(within(tri, '.console-menu-sheet-list'), 'Edit')); await tick(t.window);
    const triageForm = modal.classList.contains('visible') && $('scheduleTitle').textContent === 'Email triage schedule' &&
      $('scheduleFormView').style.display !== 'none' && $('taskFormView').style.display === 'none';
    if (triageForm) { click(t.window, $('scheduleSave')); await tick(t.window, 80); }
    const puts = t.state.requests.filter(r => r.method === 'PUT').map(r => r.url);
    check(++n, 'Edit, picked from the popover or the sheet, opens the editor on THAT task or triage setup, as the pencil did, and saves back to it',
      taskForm && triageForm && JSON.stringify(puts) === JSON.stringify(['/tasks/t1', '/triage-schedules/s1']),
      'task=' + taskForm + ' triage=' + triageForm + ' puts=' + JSON.stringify(puts));
  }
  {
    const declined = boot({ confirm: false }); await enter(declined);
    clickIf(declined, item(await openMenu(declined, 'Daily brief'), 'Remove')); await tick(declined.window);
    const t = boot(); await enter(t);
    clickIf(t, item(await openMenu(t, 'Daily brief'), 'Remove')); await tick(t.window, 80);
    const taskGone = !t.card('Daily brief') && !!t.card('Parked idea');
    const said = t.line();
    clickIf(t, item(await openMenu(t, 'Email Triage'), 'Remove')); await tick(t.window, 80);
    const dels = t.state.requests.filter(r => r.method === 'DELETE').map(r => r.url);
    check(++n, 'Remove, picked from the menu, asks first, sends the same DELETE on OK and takes the card away — for a task and a triage setup; Cancel sends nothing',
      declined.state.confirms.length === 1 && !declined.state.requests.some(r => r.method === 'DELETE') &&
      !!declined.card('Daily brief') &&
      JSON.stringify(t.state.confirms) === JSON.stringify(['Remove "Daily brief"? Its schedule stops now; past run history is kept.',
        'Remove Email Triage? Your mailbox stays connected.']) &&
      JSON.stringify(dels) === JSON.stringify(['/tasks/t1', '/triage-schedules/s1']) &&
      taskGone && !t.card('Email Triage') && said === 'console-status console-status-ok :: Task removed. Its schedule has stopped.',
      'confirms=' + JSON.stringify(t.state.confirms) + ' dels=' + JSON.stringify(dels) + ' said=' + said);
  }
  {
    // The old icon stayed disabled while its DELETE was out; the same has to hold in
    // both renderings, the popover reopened included — on both kinds of card.
    const bad = [];
    for (const name of ['Daily brief', 'Email Triage']) {
      const t = boot({ holdDelete: true, failDelete: true }); await enter(t);
      clickIf(t, item(await openMenu(t, name), 'Remove')); await tick(t.window);
      const drop = await openMenu(t, name);
      const whileOut = item(drop, 'Remove');
      const editLive = !!item(drop, 'Edit') && item(drop, 'Edit').disabled === false;
      const sheetRemove = item(within(t.card(name), '.console-menu-sheet-list'), 'Remove');
      const held = !!whileOut && whileOut.disabled === true && !!sheetRemove && sheetRemove.disabled === true && editLive;
      click(t.window, t.document.body); await tick(t.window);
      if (t.state.release) t.state.release();
      await tick(t.window, 80);
      const said = t.line();
      const after = item(await openMenu(t, name), 'Remove');
      const sheetAfter = item(within(t.card(name), '.console-menu-sheet-list'), 'Remove');
      const dels = t.state.requests.filter(r => r.method === 'DELETE').length;
      if (!(held && dels === 1 && said === 'console-status console-status-error :: DEF said no' &&
        !!after && after.disabled === false && !!sheetAfter && sheetAfter.disabled === false)) {
        bad.push(name + ': held=' + held + ' dels=' + dels + ' said=' + said +
          ' after=' + (after && after.disabled) + ' sheet=' + (sheetAfter && sheetAfter.disabled));
      }
    }
    check(++n, 'a Remove still on its way, from a task or a triage card, cannot be sent twice from either rendering, and a failed one is offered again with the error shown',
      bad.length === 0, bad.join(' | '));
  }

  console.log('\n' + results.join('\n'));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail > 0 ? 1 : 0);
})();
