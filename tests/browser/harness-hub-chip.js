/*
 * The "Project Hub" chip (P-E2 / v8.11.0) — behavioural harness.
 *
 * DEF runs a chat with no project chosen in the person's Project Hub (D-P17), so
 * every new chat with no other project chosen says so above the message box. Runs
 * the SHIPPED chip block, the SHIPPED New chat (resetToNewChat and its button),
 * the SHIPPED loadConversation and the SHIPPED Ask entry inside jsdom, each sliced
 * by marker: the console opens on "Project Hub"; a chosen project reads
 * "Project: <name>" as before; New chat, an Ask entry and deleting the open chat
 * each come back to "Project Hub"; a chat opened from the sidebar shows no chip,
 * also from a new chat; and the first send leaves the chip as it was (P-B).
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const CHIP = extract.projectChip();
const NEW_CHAT = extract.slice('resetToNewChat',
  l => l.includes('function resetToNewChat() {'), l => l.includes('// Auto-resize textarea'),
  ['currentConversationId = null;', 'newChatBtn.addEventListener']);
const LOAD = extract.slice('loadConversation',
  l => l.includes('async function loadConversation(id, shared) {'), l => l.includes('// Update read-only state'),
  ['currentConversationId = id;', 'clearActiveProject();']);

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

function boot() {
  const dom = new JSDOM('<!doctype html><button id="newChatBtn"></button><button class="x-ask-btn">Ask</button>' +
    '<div id="composerContainer"><textarea id="composerInput"></textarea></div>', { url: 'https://e.test/staff-ai/' });
  const window = dom.window, document = window.document;
  const noop = function () {};
  const deps = {
    window, document, console: { error: noop }, t: (k, d) => d,
    composerInput: document.getElementById('composerInput'), newChatBtn: document.getElementById('newChatBtn'),
    sidebar: document.createElement('div'), toggleSidebar: noop, endConversation: noop, showChat: noop,
    clearConfirmChips: noop, updateReadOnlyState: noop, renderConversationList: noop, renderMessages: noop,
    updateSendButton: noop, hideError: noop, hideInfo: noop, showError: noop,
    apiRequest: async () => ({ messages: [] })
  };
  const names = Object.keys(deps);
  const api = new window.Function(...names,
    'var currentConversationId = null, isReadOnly = false, conversationOn = false, messages = [];\n' +
    CHIP + '\n' + NEW_CHAT + '\n' + LOAD + '\n' +
    'return { setActiveProject: setActiveProject, clearActiveProject: clearActiveProject,' +
    ' resetToNewChat: resetToNewChat, loadConversation: loadConversation,' +
    ' send: function () { currentConversationId = "t-new"; } };'
  )(...names.map(k => deps[k]));
  const ask = extract.buildAskEntry(window, { composerInput: deps.composerInput,
    clearActiveProject: api.clearActiveProject, resetToNewChat: api.resetToNewChat, sendMessage: api.send });
  ask([document.querySelector('.x-ask-btn')], 'x', { plain: 'Ask', prompt: 'Explain' });
  const chip = () => { const c = document.getElementById('composerProjectChip'); return c ? c.textContent : null; };
  const click = sel => document.querySelector(sel).dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  return { api, chip, click };
}

(async function () {
  let n = 0;
  {
    const b = boot();
    check(++n, 'the console opens on a new chat that says "Project Hub", with no "Project: " prefix',
      b.chip() === 'Project Hub', JSON.stringify(b.chip()));
  }
  {
    const b = boot();
    b.api.setActiveProject({ project_id: 'p1', name: 'Acme' });
    b.api.resetToNewChat();
    check(++n, 'a chosen project still reads "Project: <name>"', b.chip() === 'Project: Acme', JSON.stringify(b.chip()));
    b.click('#newChatBtn');
    check(++n, 'New chat leaves the project and says "Project Hub"', b.chip() === 'Project Hub', JSON.stringify(b.chip()));
  }
  {
    const b = boot();
    await b.api.loadConversation('t1');
    check(++n, 'a chat opened from the sidebar, from a new chat, shows no chip', b.chip() === null, JSON.stringify(b.chip()));
    b.api.setActiveProject({ project_id: 'p1', name: 'Acme' });
    b.api.resetToNewChat();
    await b.api.loadConversation('t2');
    check(++n, 'a chat opened from a new chat in a project shows no chip either', b.chip() === null, JSON.stringify(b.chip()));
    // Deleting the open chat runs exactly this pair (the chat menu's Delete).
    b.api.clearActiveProject(); b.api.resetToNewChat();
    check(++n, 'deleting the open chat lands on a new chat that says "Project Hub"', b.chip() === 'Project Hub',
      JSON.stringify(b.chip()));
  }
  {
    const b = boot();
    await b.api.loadConversation('t1');
    b.click('.x-ask-btn');
    check(++n, 'an Ask entry starts a new chat that says "Project Hub", and its first send keeps the chip',
      b.chip() === 'Project Hub', JSON.stringify(b.chip()));
  }

  console.log('\n' + results.join('\n'));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail > 0 ? 1 : 0);
})();
