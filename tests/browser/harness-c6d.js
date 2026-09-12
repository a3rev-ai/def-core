/*
 * Memories on the kit's status line — behavioural harness (C6d).
 *
 * Runs the SHIPPED page-shell and initMemories blocks (sliced by marker,
 * tests/browser/extract.js) inside jsdom, against the SHIPPED #memoriesPane markup
 * out of templates/staff-ai-shell.php, and reads the SHIPPED stylesheet. Nothing
 * here is a copy of the code under test and nothing is a copy of the markup.
 *
 * The runsheet rules this page by name (row 6): "Memories (rows, Delete only — no
 * menu for one action)". So C6d takes exactly ONE thing from the kit — the status
 * line — and the checks below are as much about what the page did NOT take as what
 * it did: a ⋯ menu holding a single Delete would be a click to reach a click.
 *
 * Two things this file has to do that the earlier adoptions did not:
 *
 *  - The name it replaced is GONE. `.memories-status` retires from the stylesheet in
 *    this same PR, so "shares every rule with the name it replaced" cannot be read
 *    off the shipped sheet on both sides the way harness-c5 reads it. What the family
 *    carried is RECORDED below; the kit side is COMPUTED off the sheet and held to
 *    that record, and the record is what makes the deletion provable rather than
 *    assumed.
 *  - There is no -ok kind on this page, and this PR does not add one. Only the two
 *    kinds Memories has ever had are re-pointed.
 *
 * 17 checks.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const SHELL = extract.pageShell();
const MEMORIES = extract.memories();
const CSS = fs.readFileSync(path.join(extract.REPO, 'assets/css/staff-ai.css'), 'utf8');
const TEMPLATE = extract.templateSource();
const INDEX = extract.cssRules(CSS);

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

// ── 1. the stylesheet ─────────────────────────────────────────────────────
//
// What `.memories-status` carried before C6d removed it (v7.9.11,
// assets/css/staff-ai.css:2709-2722), and the kit selector each one retires into.
// This is a record of something the shipped sheet no longer holds, which is the only
// reason it is spelled out here rather than read: everything it is compared AGAINST
// is computed off the sheet. It is therefore this file's trust anchor, not a derived
// value — a mis-recorded line here would let both proofs below pass over a real
// change — so the citation above is exact and auditable against the merge-base.
const RETIRED = [
  ['.memories-status', '.console-status',
    ['font-size: 13px', 'margin-bottom: 10px', 'min-height: 18px']],
  ['.memories-status:empty', '.console-status:empty', ['margin-bottom: 0']],
  ['.memories-status-muted', '.console-status-muted', ['color: var(--text-tertiary)']],
  ['.memories-status-error', '.console-status-error', ['color: var(--banner-error-text)']]
];
// The bare class name inside a kit selector: `.console-status:empty` -> console-status,
// which is the key extract.cssRules indexes the stylesheet by.
const className = sel => sel.replace(/^\./, '').split(':')[0];
const KIT_NAMES = Array.from(new Set(RETIRED.map(r => className(r[1]))));

const parts = sel => sel.split(',').map(s => s.replace(/\s+/g, ' ').trim()).filter(Boolean);
const declsOf = rule => rule.body.split(';').map(d => d.replace(/\s+/g, ' ').trim()).filter(Boolean);
const mentions = (part, cls) => new RegExp('\\.' + cls + '(?![A-Za-z0-9_-])').test(part);
const rulesOf = cls => Array.from(INDEX.byClass.get(cls) || []).map(id => INDEX.rules[id]);

// ── 2. the page, booted ───────────────────────────────────────────────────

const HTML = `<!doctype html><html><body>
${extract.templateNav()}
<div id="conversationList"></div>
<div class="messages-container" id="messagesContainer"><div id="messagesList"></div></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
${extract.templatePage('memoriesPane')}
</body></html>`;

const SHELL_TAIL = `
    return { showPage: showPage, showChat: showChat, consolePages: consolePages,
             open: function () { return openPage ? openPage.route : null; } };`;

const DEFAULT_MEMORIES = [
  { entry_id: 'm1', content: 'Prefers short answers', category: 'preferences', created_at: '2026-09-01T10:00:00Z' },
  { entry_id: 'm2', content: 'Runs the console track', category: 'role', created_at: '2026-08-20T10:00:00Z' }
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
    if (opts.fail && opts.fail(url, method)) throw new Error('DEF said no');
    if (/^\/memories/.test(url)) {
      if (method === 'DELETE') return {};
      return { memories: opts.memories === undefined ? DEFAULT_MEMORIES : opts.memories };
    }
    return {};
  }
  window.confirm = function (msg) { state.confirms.push(msg); return state.confirmAnswer; };

  const names = ['window', 'document', 'consolePages', 't', 'apiRequest', 'assistantName',
    'onAssistantName', 'clearActiveProject', 'resetToNewChat', 'composerInput',
    'updateSendButton', 'sendMessage', 'formatTime'];
  const outer = [
    window, document, api.consolePages,
    function (key, def) { return def; },
    apiRequest,
    'Sue',
    function (fn) { fn(); },
    function () {}, function () {}, composerInput,
    function () {}, function () {},
    function (x) { return String(x || ''); }
  ];
  extract.pushAskEntry(window, names, outer);
  new window.Function(...names, MEMORIES)(...outer);

  return {
    window, document, api, state, requests,
    reqs: re => requests.filter(r => re.test(r.url)),
    rows: () => Array.from(document.querySelectorAll('.memory-row')),
    status: () => document.getElementById('memoriesStatus'),
    // What the reader actually sees, as one string: the names the line is wearing
    // and the words on it.
    line: function () {
      const el = document.getElementById('memoriesStatus');
      return el.className + ' :: ' + el.textContent;
    }
  };
}

const tick = (w, n) => new Promise(r => w.setTimeout(r, n === undefined ? 40 : n));
function click(w, el) {
  // Returns false rather than throwing when the node is absent: this file is RUN
  // against main to prove it bites, and a crash at the first missing node would
  // report nothing about the rest of them.
  if (!el) { return false; }
  el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  return true;
}
function key(w, target, k) {
  target.dispatchEvent(new w.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
}
async function enter(t) { t.api.showPage('memories'); await tick(t.window, 60); }
const del = row => row && row.querySelector('.memory-btn-delete');

(async () => {
  let n = 0;

  // ---- what the page took, and what it did NOT ----------------------------
  {
    // The runsheet's row 6, made testable: rows, Delete only, NO menu. The kit's
    // card and menu families are asserted ABSENT rather than assumed absent — this
    // is the check a later "adoption" of the menu here would have to argue with.
    const CODE = MEMORIES.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    const written = new Set(CODE.match(/\bconsole-[a-z]+(?:-[a-z]+)*\b/g) || []);
    check(++n, 'the status line is the ONE thing Memories takes from the kit — no menu and no card name, because the runsheet rules it rows with one Delete',
      written.has('console-status') && written.size === 1,
      'written: ' + Array.from(written).sort().join(', '));
  }
  {
    // Both halves move together: setStatus overwrites className wholesale, so a
    // template still saying `memories-status` — or a block still writing it — is a
    // page with no status styling at all.
    const CODE = MEMORIES.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    const left = ['memories-status', 'memories-status-muted', 'memories-status-error']
      .filter(name => CODE.indexOf(name) !== -1);
    const tpl = /class="console-status" id="memoriesStatus"/.test(TEMPLATE);
    check(++n, 'the shipped block no longer writes the status name the kit replaced, and the template moved with it',
      left.length === 0 && tpl, 'left: ' + left.join(', ') + ' template=' + tpl);
  }
  {
    // Only -muted and -error exist on this page. `.console-status-ok` is in the sheet
    // (Connections retired its own into it in C6c) and Memories must not reach it:
    // re-point what is there, do not add a kind the page never had.
    const CODE = MEMORIES.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    const kinds = new Set((CODE.match(/(?:,|\|\|)\s*'(muted|error|ok)'/g) || [])
      .map(m => /'(\w+)'/.exec(m)[1]));
    check(++n, 'the page speaks in two kinds, muted and error — no -ok was invented on the way',
      kinds.has('muted') && kinds.has('error') && !kinds.has('ok') && kinds.size === 2,
      'kinds: ' + Array.from(kinds).sort().join(', '));
  }

  // ---- the stylesheet: every rule the retired family carried --------------
  {
    const missing = [];
    RETIRED.forEach(function (row) {
      const kitSel = row[1], want = row[2];
      const have = new Set();
      rulesOf(className(kitSel)).forEach(function (rule) {
        if (parts(rule.selector).indexOf(kitSel) !== -1) declsOf(rule).forEach(d => have.add(d));
      });
      if (!have.size) { missing.push(kitSel + ': no rule in the stylesheet at all'); return; }
      want.forEach(function (d) { if (!have.has(d)) missing.push(kitSel + ' lacks {' + d + '}'); });
    });
    check(++n, 'PROOF: every declaration the retired .memories-status family carried is carried by the kit name that replaced it',
      missing.length === 0, missing.join(' | '));
  }
  {
    // The other half of the proof, and the thing this PR REPORTS rather than absorbs.
    // Computed, not declared: every DECLARATION the three kit names carry that the
    // retired family did not. There must be exactly one. Declarations rather than whole
    // rules, because a stray line added to `.console-status` itself would be just as
    // much a restyle as a new rule, and a rule-shaped comparison walks straight past it.
    const want = new Map(RETIRED.map(r => [r[1], r[2]]));
    const extras = [];
    KIT_NAMES.forEach(function (cls) {
      rulesOf(cls).forEach(function (rule) {
        parts(rule.selector).filter(p => mentions(p, cls)).forEach(function (p) {
          const carried = want.get(p) || [];
          declsOf(rule).forEach(function (d) {
            if (carried.indexOf(d) === -1) extras.push(p + ' {' + d + '}');
          });
        });
      });
    });
    check(++n, 'THE ONE EXCEPTION, DECLARED: the kit carries exactly one declaration the retired family never did — margin-top: 10px, inside a .console-page',
      extras.length === 1 && extras[0] === '.console-page .console-status {margin-top: 10px}',
      'extras: ' + JSON.stringify(extras));
  }
  {
    // The 10px the kit brings never reaches the reader on THIS page, and the reason
    // is worth pinning rather than asserting the rule and calling it a move.
    // #memoriesStatus is the IMMEDIATE next sibling of .console-page-head, both plain
    // in-flow block boxes inside a .console-page that is not a flex container — so the
    // head's margin-bottom and the status line's new margin-top collapse to the larger
    // of the two, and every margin-bottom the head declares is larger than 10px.
    // Net movement: none. Both numbers are read off the shipped sheet, so the day a
    // control row is inserted between them — as Documents has — this goes red and the
    // line really does drop.
    const t = boot(); await enter(t);
    const el = t.status();
    const prev = el && el.previousElementSibling;
    const lengths = (rules, prop) => rules
      .map(r => (declsOf(r).find(d => d.indexOf(prop + ':') === 0) || '').split(':')[1])
      .filter(Boolean).map(v => parseFloat(v.trim()));
    const headRules = rulesOf('console-page-head')
      .filter(r => parts(r.selector).some(x => /\.console-page-head$/.test(x)));
    // A `margin:` shorthand on either side would make the readings above incomplete.
    const shorthand = headRules.some(r => declsOf(r).some(d => d.indexOf('margin:') === 0));
    const kitTop = lengths(rulesOf('console-status')
      .filter(r => parts(r.selector).indexOf('.console-page .console-status') !== -1), 'margin-top');
    const headBottoms = lengths(headRules, 'margin-bottom');
    check(++n, 'the re-point moves NOTHING the reader sees: the 10px the kit brings collapses into the page head sitting directly above the line',
      !!el && el.classList.contains('console-status') && !!el.closest('.console-page') &&
      !!prev && prev.classList.contains('console-page-head') && !shorthand &&
      kitTop.length === 1 && headBottoms.length > 0 && headBottoms.every(v => v >= kitTop[0]),
      'class=' + (el && el.className) + ' prev=' + (prev && prev.className) +
      ' kitTop=' + JSON.stringify(kitTop) + ' headBottoms=' + JSON.stringify(headBottoms) +
      ' shorthand=' + shorthand);
  }
  {
    const survivors = Array.from(INDEX.byClass.keys()).filter(k => /^memories-status/.test(k));
    check(++n, 'the retired family is gone from the shipped sheet — one live status family on this page, not two',
      survivors.length === 0, 'survivors: ' + survivors.join(', '));
  }

  // ---- the status line, through the kit's names ---------------------------
  {
    const t = boot();
    t.api.showPage('memories');
    const loading = t.line();
    await tick(t.window, 60);
    check(++n, 'while it loads the line is muted, and a populated list clears it back to the bare name',
      loading === 'console-status console-status-muted :: Loading what Staff AI remembers…' &&
      t.line() === 'console-status :: ' && t.rows().length === 2,
      'loading=' + JSON.stringify(loading) + ' then=' + JSON.stringify(t.line()));
  }
  {
    const t = boot({ memories: [] }); await enter(t);
    check(++n, 'an empty store says so in the muted kind, with no rows',
      t.line() === 'console-status console-status-muted :: Staff AI has not noted anything about you yet.' &&
      t.rows().length === 0, JSON.stringify(t.line()));
  }
  {
    // An error, never an empty list — DEF answers 503 when the store is unreachable,
    // and "nothing here" would be a lie about their data.
    const t = boot({ fail: (url, m) => m === 'GET' }); await enter(t);
    check(++n, "a failed load reports the error in the kit's error kind, and does not claim the store is empty",
      t.line() === 'console-status console-status-error :: DEF said no' && t.rows().length === 0,
      JSON.stringify(t.line()));
  }
  {
    const t = boot({ memories: [] }); await enter(t);
    const said = t.line();
    key(t.window, t.document.body, 'Escape'); await tick(t.window);
    check(++n, 'leaving clears the line — an outcome from before you left is not what greets you on return',
      said !== 'console-status :: ' && t.line() === 'console-status :: ' && t.api.open() !== 'memories',
      'was=' + JSON.stringify(said) + ' now=' + JSON.stringify(t.line()));
  }

  // ---- Delete does exactly what it did ------------------------------------
  {
    const t = boot(); await enter(t);
    const rows = t.rows();
    check(++n, "Delete is the row's own button, one per row, and nothing on the row opens a menu",
      rows.length === 2 && rows.every(r => !!del(r) && del(r).textContent === 'Delete' &&
        r.querySelectorAll('.memory-btn-delete').length === 1 &&
        !r.querySelector('.console-menu-btn, .console-menu, [aria-haspopup]')),
      'rows=' + rows.length + ' buttons=' + rows.map(r => !!del(r)).join(','));
  }
  {
    const t = boot(); await enter(t);
    click(t.window, del(t.rows()[0]));
    await tick(t.window, 80);
    check(++n, 'Delete asks first, naming the memory, then sends the same DELETE and takes the row away',
      t.state.confirms.length === 1 &&
      /Prefers short answers/.test(t.state.confirms[0]) &&
      t.reqs(/^\/memories\/m1$/).length === 1 &&
      t.reqs(/^\/memories\/m1$/)[0].method === 'DELETE' &&
      t.rows().length === 1 && t.line() === 'console-status :: ',
      'confirms=' + JSON.stringify(t.state.confirms) + ' reqs=' +
      JSON.stringify(t.reqs(/^\/memories/).map(r => r.method + ' ' + r.url)) + ' line=' + t.line());
  }
  {
    const t = boot({ confirmAnswer: false }); await enter(t);
    click(t.window, del(t.rows()[0]));
    await tick(t.window, 80);
    check(++n, 'Cancel at the confirm sends nothing and keeps the row, its button still live',
      t.state.confirms.length === 1 && t.reqs(/^\/memories\/m1$/).length === 0 &&
      t.rows().length === 2 && del(t.rows()[0]).disabled === false,
      'reqs=' + t.reqs(/^\/memories\/m1$/).length + ' rows=' + t.rows().length);
  }
  {
    // The id goes through the URL encoder, and the confirm through a FUNCTION
    // replacement — a remembered fact containing $& would garble a string-pattern
    // replace (the doclib lesson). Both are behaviour a re-point must not cost.
    const t = boot({ memories: [{ entry_id: 'm/1 2', content: 'Bills $& invoices', category: 'habits', created_at: '' }] });
    await enter(t);
    click(t.window, del(t.rows()[0]));
    await tick(t.window, 80);
    check(++n, 'an id with a slash is encoded, and a memory containing $& is quoted literally in the confirm',
      t.reqs(/^\/memories\/m%2F1%202$/).length === 1 &&
      /Bills \$& invoices/.test(t.state.confirms[0] || ''),
      'urls=' + JSON.stringify(t.reqs(/^\/memories\//).map(r => r.url)) +
      ' confirm=' + JSON.stringify(t.state.confirms[0]));
  }
  {
    const t = boot({ memories: [DEFAULT_MEMORIES[0]] }); await enter(t);
    click(t.window, del(t.rows()[0]));
    await tick(t.window, 80);
    const emptied = t.rows().length === 0 &&
      t.line() === 'console-status console-status-muted :: Staff AI has not noted anything about you yet.';
    // Re-entering reloads: the page has no Refresh because entering IS the refresh.
    t.api.showChat(); await tick(t.window);
    await enter(t);
    check(++n, 'deleting the last one leaves the empty line in the muted kind, and re-entering reloads the page',
      emptied && t.reqs(/^\/memories$/).length === 2,
      'emptied=' + emptied + ' line=' + JSON.stringify(t.line()) + ' loads=' + t.reqs(/^\/memories$/).length);
  }
  {
    const t = boot({ fail: (url, m) => m === 'DELETE' }); await enter(t);
    click(t.window, del(t.rows()[0]));
    await tick(t.window, 80);
    check(++n, "a DELETE that fails says why in the kit's error kind, keeps the row and hands the button back",
      t.line() === 'console-status console-status-error :: DEF said no' &&
      t.rows().length === 2 && del(t.rows()[0]).disabled === false,
      'line=' + JSON.stringify(t.line()) + ' rows=' + t.rows().length +
      ' disabled=' + (del(t.rows()[0]) && del(t.rows()[0]).disabled));
  }

  console.log('\n' + results.join('\n'));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail > 0 ? 1 : 0);
})();
