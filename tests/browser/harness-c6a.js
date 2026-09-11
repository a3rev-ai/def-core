/*
 * Documents on the card kit — behavioural harness (C6a / v7.9.4).
 *
 * Runs the SHIPPED page shell and the SHIPPED initDocuments block (both sliced by
 * marker, tests/browser/extract.js) inside jsdom, against the SHIPPED
 * #documentsPane markup out of templates/staff-ai-shell.php.
 *
 *  1. The proof, C5's check 17 run over this page: every kit name the shipped
 *     block writes SHARES every rule with the name it replaced, so Documents wears
 *     the kit as it is rather than a variant of its own. The deliberate exceptions
 *     are declared below.
 *  2. The ⋯ menu and its touch sheet on a document card, driven the way C5 drove
 *     Projects', and the three actions that moved behind them — Move to project…,
 *     Download, Delete — each doing what its old button did.
 *  3. The document viewer's status line is NOT the kit's. C4 folds it into the
 *     page description with a compound rule on purpose; this file keeps a later
 *     sweep from taking it.
 *
 * 19 checks.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');
const SHELL = extract.pageShell();
const DOCUMENTS = extract.documents();
const VIEWER = extract.documentViewer();
const CSS = fs.readFileSync(path.join(extract.REPO, 'assets/css/staff-ai.css'), 'utf8');
const JS = fs.readFileSync(extract.JS_PATH, 'utf8');
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
// Each kit name the Documents block writes, and the name it replaced: the page's
// own where it had one — joined into the kit's selectors the way C5 joined
// Projects' — and otherwise the 7.8.0 name the kit itself was derived from.
const REPLACED = {
  'console-card': 'document-card',
  'console-card-actions': 'document-action',
  'console-status': 'documents-status',
  'console-status-muted': 'documents-status-muted',
  'console-status-error': 'documents-status-error',
  'console-card-head': 'project-card-head',
  'console-menu': 'chat-menu',
  'console-menu-item': 'chat-menu-item',
  'console-menu-item-danger': 'chat-menu-item-danger',
  'console-menu-sep': 'chat-menu-sep',
  'console-menu-btn': 'project-menu-btn',
  'console-menu-drop': 'project-manage-menu',
  'console-menu-sheet': 'project-manage',
  'console-menu-sheet-btn': 'project-manage-btn',
  'console-menu-sheet-list': 'project-manage-sheet'
};

// The rules a replaced name keeps that its kit name deliberately does not take.
//  - `.chat-menu` alone: the chat row's menu is pinned with position:fixed; a
//    card's menu hangs off the card (C5's exception, unchanged).
//  - `.console-page-desc.documents-status-*`: the document VIEWER's status line,
//    folded into its page description (C4). It is not the Documents list's status
//    line and does not become the kit's — section 3 holds it where it is.
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
// stripped. `'console-status-' + kind` reads as console-status; its kinds are the
// ones setStatus is actually called with.
const CODE = DOCUMENTS.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
const WRITTEN = new Set(CODE.match(/\bconsole-[a-z]+(?:-[a-z]+)*\b/g) || []);
if (WRITTEN.has('console-status')) {
  (CODE.match(/,\s*'(muted|error|ok)'\)/g) || []).forEach(m => WRITTEN.add('console-status-' + /'(\w+)'/.exec(m)[1]));
}

// ── 2. the page, booted ───────────────────────────────────────────────────

const HTML = `<!doctype html><html><body>
${extract.templateNav()}
<div id="conversationList"></div>
<div class="messages-container" id="messagesContainer"><div id="messageList"></div></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
${extract.templatePage('documentsPane')}
</body></html>`;

const DOCS = [
  { document_id: 'd1', title: 'Go-Live Runsheet', file_type: 'md', size_bytes: 2048,
    created_at: '2026-09-10T10:00:00Z', download_url: 'https://e.test/files/d1',
    project_id: 'p1', project_name: 'Rowell Walton', slot: 'runsheet' },
  { document_id: 'd2', title: 'Signed quote', file_type: 'pdf', size_bytes: 90000,
    created_at: '2026-09-09T10:00:00Z', download_url: 'https://e.test/files/d2' }
];
const PROJECTS = [
  { project_id: 'p1', name: 'Rowell Walton', status: 'active' },
  { project_id: 'p2', name: 'Shelved', status: 'archived' }
];
const VIEWABLE_TYPES = new Function('return ' + /const VIEWABLE_TYPES = (\[[^\]]*\]);/.exec(JS)[1])();

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

  // `apiRequest` answers from fixtures and records every call; `holdDelete` keeps
  // a DELETE out until the check releases it.
  const state = { requests: [], confirms: [], viewer: null, release: null };
  window.confirm = function (msg) { state.confirms.push(msg); return opts.confirm !== false; };
  async function apiRequest(url, init) {
    const method = (init && init.method) || 'GET';
    state.requests.push({ url: url, method: method, body: init && init.body ? JSON.parse(init.body) : null });
    if (/^\/projects/.test(url)) return { projects: opts.projects || PROJECTS };
    if (method === 'DELETE') {
      if (opts.holdDelete) await new Promise(r => { state.release = r; });
      if (opts.failDelete) throw new Error('DEF said no');
      return {};
    }
    if (method === 'PUT') return {};
    if (opts.failList) throw new Error('DEF said no');
    return { documents: opts.docs || DOCS };
  }

  new window.Function(
    'window', 'document', 'consolePages', 't', 'apiRequest', 'apiBase', 'projectsCache',
    'formatTime', 'safeHttpHref', 'openDocumentViewer', 'VIEWABLE_TYPES',
    'openDocumentsForProject', 'showPage', 'askEntry', 'isPlainClick',
    DOCUMENTS
  )(
    window, document, api.consolePages, function (key, def) { return def; }, apiRequest, '/def/v1', [],
    function (x) { return String(x || ''); },
    function (u) { return /^https?:\/\//i.test(u || '') ? u : ''; },
    function (id, title) { state.viewer = { id: id, title: title }; },
    VIEWABLE_TYPES, null, api.showPage,
    extract.buildAskEntry(window, { composerInput: document.getElementById('composerInput') }),
    function (e) { return e.button === 0 && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.altKey; }
  );

  return {
    window, document, api, state,
    // A page that does not wear the kit renders no .console-card, so every lookup
    // below reports that rather than throwing on a null.
    card: title => Array.from(document.querySelectorAll('#documentsGrid .console-card'))
      .find(c => { const nm = c.querySelector('.document-name'); return nm && nm.textContent === title; }) || null,
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
async function enter(t) { t.api.showPage('documents'); await tick(t.window, 80); }
async function openMenu(t, title) {
  clickIf(t, within(t.card(title), '.console-menu-btn'));
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
    check(++n, 'PROOF: every kit name Documents takes SHARES every rule with the name it replaced — no Documents variant of the kit',
      WRITTEN.size > 0 && drifted.length === 0, drifted.join(' | ') || 'written=' + WRITTEN.size);

    const undeclared = Array.from(WRITTEN).filter(name => !REPLACED[name]);
    const unused = Object.keys(REPLACED).filter(name => !WRITTEN.has(name));
    check(++n, 'the table above IS the page: every kit name the shipped block writes is declared, and none declared goes unwritten',
      undeclared.length === 0 && unused.length === 0,
      'undeclared: ' + undeclared.join(', ') + ' / unused: ' + unused.join(', '));

    const view = drift('document-view-btn', 'project-open-btn');
    check(++n, "View is the card's one filled action and shares every rule with Projects' Open Project",
      /'modal-btn modal-btn-primary document-view-btn'/.test(CODE) && view.length === 0, view.join(' | '));

    const left = ['document-card', 'document-action', 'documents-status']
      .filter(nm => new RegExp("'" + nm + "['\\s]").test(CODE))
      .concat(/document-btn/.test(CODE) ? ['document-btn'] : []);
    check(++n, 'the shipped block no longer writes a name the kit replaced — no card, action row, button or status of its own',
      left.length === 0, 'left: ' + left.join(', '));
  }

  // ---- 3. the viewer's status line, held where it is ------------------------
  {
    check(++n, "the template gives the Documents status line the kit's name and leaves the viewer's line its own",
      /<div class="console-status" id="documentsStatus">/.test(TEMPLATE) &&
      /<p class="console-page-desc documents-status" id="documentViewerStatus">/.test(TEMPLATE));

    const writes = VIEWER.match(/statusEl\.className = '[^']*'/g) || [];
    const muted = writes.filter(w => w === "statusEl.className = 'console-page-desc documents-status documents-status-muted'").length;
    const error = writes.filter(w => w === "statusEl.className = 'console-page-desc documents-status documents-status-error'").length;
    const rules = /\.console-page-desc\.documents-status-error\s*\{\s*color:\s*var\(--banner-error-text\);\s*\}/.test(CSS) &&
      /\.console-page-desc\.documents-status-muted\s*\{\s*color:\s*var\(--text-tertiary\);\s*\}/.test(CSS);
    check(++n, "the document viewer's status writes are UNCHANGED — all five its own compound, none the kit's, and the rules behind them intact",
      writes.length === 5 && muted === 2 && error === 3 && !/console-status/.test(VIEWER) && rules,
      'writes=' + writes.length + ' muted=' + muted + ' error=' + error + ' rules=' + rules);
  }

  // ---- 2. the card, its ⋯ menu and the touch sheet ---------------------------
  {
    const t = boot(); await enter(t);
    const md = t.card('Go-Live Runsheet'), pdf = t.card('Signed quote');
    const face = c => {
      const head = c && Array.from(c.children).find(el => el.classList.contains('console-card-head'));
      const acts = head && Array.from(head.children).find(el => el.classList.contains('console-card-actions'));
      return head && acts && head.firstElementChild.classList.contains('document-info')
        ? Array.from(acts.children).map(el => el.textContent) : null;
    };
    // The moved actions exist only inside the menus now, never on the card's face.
    const loose = c => !c || Array.from(c.querySelectorAll('button, a'))
      .some(el => /^(Move to project…|Download|Delete)$/.test(el.textContent) && !el.closest('.console-menu'));
    const mdFace = face(md), pdfFace = face(pdf);
    const viewBtn = within(md, '.document-view-btn');
    clickIf(t, viewBtn);
    check(++n, 'a document card is a .console-card: its head holds the name and the actions, View (filled) and ⋯ — nothing else on its face',
      JSON.stringify(mdFace) === JSON.stringify(['View', '•••']) && JSON.stringify(pdfFace) === JSON.stringify(['•••']) &&
      !loose(md) && !loose(pdf) && t.state.viewer && t.state.viewer.id === 'd1',
      'md=' + JSON.stringify(mdFace) + ' pdf=' + JSON.stringify(pdfFace) + ' viewer=' + JSON.stringify(t.state.viewer));
  }
  {
    const t = boot({ failList: true }); await enter(t);
    const s = t.document.getElementById('documentsStatus');
    const failed = s.className + ' :: ' + s.textContent;
    const u = boot({ docs: [] }); await enter(u);
    const e = u.document.getElementById('documentsStatus');
    check(++n, "the list's status line speaks through the kit — console-status with its -error and -muted kinds",
      failed === 'console-status console-status-error :: DEF said no' &&
      e.className === 'console-status console-status-muted' && e.textContent === 'No documents yet.',
      failed + ' / ' + e.className);
  }
  {
    const t = boot(); await enter(t);
    const c = t.card('Go-Live Runsheet');
    const btn = within(c, '.console-menu-btn');
    const menu = await openMenu(t, 'Go-Live Runsheet');
    check(++n, "the ⋯ menu opens on a document card and is a .console-menu anchored in that card's .console-card-actions",
      !!menu && c.contains(menu) && menu.classList.contains('console-menu') &&
      menu.parentNode.classList.contains('console-card-actions') &&
      items(menu).join('|') === 'Move to project…|Download|—|Delete!' &&
      btn.getAttribute('aria-expanded') === 'true' && t.document.activeElement === menu.firstElementChild,
      'items=' + items(menu).join('|'));
  }
  {
    const t = boot(); await enter(t);
    const menu = await openMenu(t, 'Go-Live Runsheet');
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const menuGone = !!menu && !t.document.querySelector('.console-menu-drop');
    const stillOnPage = t.api.open() === 'documents';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(++n, 'Escape closes the ⋯ menu first and leaves Documents second',
      menuGone && stillOnPage && t.api.open() === null && t.chatVisible(),
      'menuGone=' + menuGone + ' stillOnPage=' + stillOnPage + ' final=' + t.api.open());
  }
  {
    const t = boot(); await enter(t);
    const c = t.card('Go-Live Runsheet');
    const btn = within(c, '.console-menu-sheet-btn');
    const sheet = within(c, '.console-menu-sheet-list');
    const ids = Array.from(t.document.querySelectorAll('.console-menu-sheet-list')).map(s => s.id);
    const startsClosed = !!btn && !!sheet && sheet.hidden && btn.parentNode.classList.contains('console-menu-sheet') &&
      btn.getAttribute('aria-expanded') === 'false' && btn.getAttribute('aria-controls') === sheet.id &&
      ids.length === 2 && new Set(ids).size === 2;
    clickIf(t, btn); await tick(t.window);
    const opened = startsClosed && !sheet.hidden && btn.getAttribute('aria-expanded') === 'true' &&
      sheet.classList.contains('console-menu') && items(sheet).join('|') === 'Move to project…|Download|—|Delete!';
    clickIf(t, btn); await tick(t.window);
    check(++n, 'the touch sheet expands in place with the same actions and folds away again',
      startsClosed && opened && sheet.hidden && btn.getAttribute('aria-expanded') === 'false',
      'closed=' + startsClosed + ' opened=' + opened + ' ids=' + ids.join(','));
  }
  {
    const t = boot(); await enter(t);
    const hidden = t.document.querySelectorAll('.console-menu-sheet-list[hidden]').length;
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(++n, "a card's HIDDEN touch sheet does not hold Escape — one press leaves Documents",
      hidden === 2 && t.api.open() === null && t.chatVisible(), 'hidden=' + hidden + ' open=' + t.api.open());
  }
  {
    const t = boot(); await enter(t);
    const btn = within(t.card('Go-Live Runsheet'), '.console-menu-sheet-btn');
    clickIf(t, btn); await tick(t.window);
    const wasOpen = !!btn && btn.getAttribute('aria-expanded') === 'true';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    const folded = wasOpen && btn.getAttribute('aria-expanded') === 'false' && t.api.open() === 'documents';
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(++n, 'an OPEN touch sheet takes the first Escape and the page leaves on the second',
      wasOpen && folded && t.api.open() === null, 'open=' + wasOpen + ' folded=' + folded);
  }
  {
    const t = boot(); await enter(t);
    await openMenu(t, 'Go-Live Runsheet');
    clickIf(t, within(t.card('Signed quote'), '.console-menu-sheet-btn')); await tick(t.window);
    const bothOpen = !!t.document.querySelector('.console-menu-drop') &&
      t.document.querySelectorAll('.console-menu-sheet-list:not([hidden])').length === 1;
    t.api.showChat(); await tick(t.window);
    const left = t.document.querySelectorAll('.console-menu:not([hidden])').length;
    check(++n, 'leaving Documents closes an open ⋯ menu and an expanded sheet, so neither holds Escape on the next page',
      bothOpen && left === 0, 'bothOpen=' + bothOpen + ' left=' + left);
  }

  // ---- the three actions, from the menu -------------------------------------
  {
    const t = boot(); await enter(t);
    const menu = await openMenu(t, 'Signed quote');
    clickIf(t, item(menu, 'Move to project…')); await tick(t.window);
    const closed = !!menu && !t.document.querySelector('.console-menu-drop');
    const row = within(t.card('Signed quote'), '.document-assign-row');
    const selects = row ? row.querySelectorAll('select') : [];
    const options = selects[0] ? Array.from(selects[0].options).map(o => o.textContent).join('|') : '';
    let sent = [];
    if (row) {
      selects[0].value = 'p1';
      selects[0].dispatchEvent(new t.window.Event('change'));
      selects[1].value = 'runsheet';
      const before = t.state.requests.length;
      click(t.window, row.querySelector('.modal-btn-primary')); await tick(t.window, 80);
      sent = t.state.requests.slice(before);
    }
    const put = sent.find(r => r.method === 'PUT');
    check(++n, 'Move to project…, picked from the menu, opens the same inline editor, saves the same PUT and reloads',
      closed && options === 'No project|Rowell Walton' && !!put && put.url === '/documents/d2/project' &&
      JSON.stringify(put.body) === JSON.stringify({ project_id: 'p1', slot: 'runsheet' }) &&
      sent.some(r => r.method === 'GET' && /^\/documents/.test(r.url)) && !t.document.querySelector('.document-assign-row'),
      'closed=' + closed + ' options=' + options + ' sent=' + JSON.stringify(sent));
  }
  {
    const t = boot({ projects: [{ project_id: 'p2', name: 'Shelved', status: 'archived' }] }); await enter(t);
    const menu = await openMenu(t, 'Signed quote');
    check(++n, 'Move to project… is offered only while there is an active project to move to, as the button was',
      !!menu && items(menu).join('|') === 'Download|—|Delete!', 'items=' + items(menu).join('|'));
  }
  {
    const unsafe = Object.assign({}, DOCS[1], { download_url: 'javascript:alert(1)' });
    const t = boot({ docs: [DOCS[0], unsafe] }); await enter(t);
    const dl = item(await openMenu(t, 'Go-Live Runsheet'), 'Download');
    let prevented = null;
    if (dl) {
      // Registered after the page's own listener, so this sees what the page did
      // to the click — then stops jsdom attempting the navigation itself.
      dl.addEventListener('click', function (e) { prevented = e.defaultPrevented; e.preventDefault(); });
      click(t.window, dl); await tick(t.window);
    }
    const closed = !t.document.querySelector('.console-menu-drop');
    const other = await openMenu(t, 'Signed quote');
    check(++n, "Download, picked from the menu, is the document's own download link, followed by the browser as before",
      !!dl && dl.tagName === 'A' && dl.getAttribute('href') === 'https://e.test/files/d1' &&
      prevented === false && closed && !!other && !items(other).includes('Download'),
      'tag=' + (dl && dl.tagName) + ' href=' + (dl && dl.getAttribute('href')) + ' prevented=' + prevented +
      ' unsafe=' + items(other).join('|'));
  }
  {
    const declined = boot({ confirm: false }); await enter(declined);
    clickIf(declined, item(await openMenu(declined, 'Go-Live Runsheet'), 'Delete')); await tick(declined.window);
    const t = boot(); await enter(t);
    clickIf(t, item(await openMenu(t, 'Go-Live Runsheet'), 'Delete')); await tick(t.window, 80);
    const del = t.state.requests.findIndex(r => r.method === 'DELETE');
    check(++n, 'Delete, picked from the menu, asks by name, sends the same DELETE on OK and reloads; Cancel sends nothing',
      declined.state.confirms.length === 1 && !declined.state.requests.some(r => r.method === 'DELETE') &&
      t.state.confirms[0] === 'Delete "Go-Live Runsheet"? This permanently removes it from your library.' &&
      del >= 0 && t.state.requests[del].url === '/documents/d1' &&
      t.state.requests.slice(del + 1).some(r => r.method === 'GET' && /^\/documents/.test(r.url)),
      'confirms=' + JSON.stringify(t.state.confirms) + ' requests=' + JSON.stringify(t.state.requests.map(r => r.method + ' ' + r.url)));
  }
  {
    const t = boot({ holdDelete: true, failDelete: true }); await enter(t);
    clickIf(t, item(await openMenu(t, 'Go-Live Runsheet'), 'Delete')); await tick(t.window);
    // The old button stayed disabled while its DELETE was out. The popover is
    // rebuilt on every open, so the same guarantee has to survive a reopen.
    const whileOut = item(await openMenu(t, 'Go-Live Runsheet'), 'Delete');
    const heldDisabled = !!whileOut && whileOut.disabled === true;
    click(t.window, t.document.body); await tick(t.window);
    if (t.state.release) t.state.release();
    await tick(t.window, 80);
    const s = t.document.getElementById('documentsStatus');
    const after = item(await openMenu(t, 'Go-Live Runsheet'), 'Delete');
    check(++n, 'a Delete still on its way cannot be sent twice from a reopened menu, and a failed one is offered again with the error shown',
      heldDisabled && t.state.requests.filter(r => r.method === 'DELETE').length === 1 &&
      s.className === 'console-status console-status-error' && s.textContent === 'DEF said no' &&
      !!after && after.disabled === false,
      'heldDisabled=' + heldDisabled + ' status=' + s.className + ' after=' + (after && after.disabled));
  }

  console.log('\n' + results.join('\n'));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail > 0 ? 1 : 0);
})();
