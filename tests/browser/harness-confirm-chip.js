/*
 * The confirm chip (v8.7.8, DEF S8) — behavioural harness.
 *
 * A change waiting for confirmation arrives as a "confirmation" stream event carrying the
 * platform's own sentence. Runs the SHIPPED stream handler and the SHIPPED send path, sliced
 * by marker, inside jsdom: the chip shows the sentence as text, Confirm sends the sentence
 * and Cancel sends "Cancel: …" through the same send as typing, the chip goes once sent, and
 * no ghost suggestion shows beside one; stacked chips go one at a time.
 *
 * 10 checks, and new chat and opening a conversation are sliced to show they clear the chips.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const STREAM = extract.staffAiStream();
// New chat and opening a conversation both clear the chips (the epoch check rests on it).
const LEAVERS = [['function resetToNewChat() {', '\tnewChatBtn.addEventListener'],
  ['async function loadConversation(id, shared) {', '\t\tif (window.innerWidth <= 768)']].map(([from, to]) =>
  extract.slice(from, l => l.includes(from), l => l.includes(to), ['clearConfirmChips();']));
const SEND = extract.slice('send path',
  l => l.startsWith('\tasync function sendMessage() {'),
  l => l.includes('// SHARE MODAL'),
  ['async function sendMessageStreaming', 'function showConfirmChip', 'function clearConfirmChips'], 'CHIP_SEND');

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

const SENTENCE = 'Confirm: mutate campaign budgets — operations.update.amount_micros: 60.00 AUD';
const STREAM_URL = 'https://e.test/wp-json/def-core/v1/staff-ai/chat/stream';

function boot() {
  const dom = new JSDOM('<!doctype html><div id="errorBanner"></div><div id="messagesContainer"><div id="messagesList">' +
    '<div class="message message-assistant"><div class="message-content"></div></div></div></div>' +
    '<div id="confirmChips"></div><textarea id="composerInput"></textarea>', { url: 'https://e.test/staff-ai/' });
  const window = dom.window, document = window.document;
  window.DefPersona = { createController: () => ({ handleEvent() {}, formatThinkingLabel: m => m, reset() {} }) };
  const sent = [];
  const deps = {
    window, document, Response, ReadableStream: function () {},
    fetch: async (url, init) => { const m = JSON.parse(init.body).messages; sent.push(m[m.length - 1].content);
      return new Response(JSON.stringify({ choices: [{ message: { content: 'Done.' } }] }), { status: 200 }); },
    StaffAIConfig: { nonce: 'n' }, t: (k, d) => d, apiBase: 'https://e.test/wp-json/def-core/v1/staff-ai',
    chatStreamUrl: STREAM_URL, errorBanner: document.getElementById('errorBanner'),
    composerInput: document.getElementById('composerInput'), confirmChips: document.getElementById('confirmChips'),
    messagesList: document.getElementById('messagesList'), messagesContainer: document.getElementById('messagesContainer'),
    messages: [], renderMessages() {}, autoResize() {}, updateSendButton() {}, hideInfo() {}, hideError() {},
    showError() {}, showSessionExpired() {}, hasActiveFiles: () => false, stagedFiles: [],
    classifySuggestionOutcome: () => ({}), endConversation() {}, browserTimezone: () => '', clearStagedFiles() {},
    readBack() {}, readBackSoFar() {}, loadConversations() {}, updateReadOnlyState() {}, recoverTurn: async () => false,
    afterSpokenTurn() {}, removeTypingMessage() {}, dropUnfilledTranscript() {}, renderMarkdown: s => s,
    renderToolStatus: () => document.createElement('div'), completeToolStatus() {}, applyCitations() {},
    buildCitationMap: () => ({}), createToolOutputCard: () => null, handleSpokenStop: () => false,
    wpFetch: (url, init) => deps.fetch(url, init), apiRequest: async () => ({}),
    uploadAllStagedFiles: async () => ({ success: true, fileIds: [] }), console: { info() {}, error() {} }
  };
  const names = Object.keys(deps);
  const api = new window.Function(...names,
    'var isLoading = false, isReadOnly = false, conversationOn = false, spokenTurn = false, openingSpoken = null,' +
    ' lastSuggestion = null, selectedModel = "", currentConversationId = null, activeProjectId = null,' +
    ' _streamAbort = null, _turnReachedServer = false, voiceStopped = false, _eventsSeen = 0, _isStreaming = false,' +
    ' _userScrolledUp = false, dirtyInput = false, SSE_TOOL_PACING_MS = 0, eventQueue = [], processing = false,' +
    ' chipEpoch = 0,' +
    ' toolStatusElements = {}, readbackBuffer = "", speaker = null;\n' + STREAM + '\n' + SEND +
    '\nreturn { push: async function (evts) { for (var i = 0; i < evts.length; i++) eventQueue.push(evts[i]);' +
    ' await processEventQueue(); }, leave: function () { clearConfirmChips(); },' +
    ' block: function (loading, readOnly) { isLoading = loading; isReadOnly = readOnly; } };'
  )(...names.map(k => deps[k]));
  const chips = () => Array.from(document.querySelectorAll('#confirmChips .confirm-chip'));
  const click = async (chip, label) => {
    Array.from(chip.querySelectorAll('button')).find(b => b.textContent === label).click();
    await new Promise(r => setTimeout(r, 0));
  };
  return { api, deps, sent, chips, click };
}

(async function () {
  let n = 0;
  {
    const b = boot();
    await b.api.push([{ type: 'confirmation', text: SENTENCE }, { type: 'suggestions', suggestion: 'Anything else?' }]);
    const c = b.chips();
    check(++n, 'a confirmation event shows one chip with the sentence, a Confirm and a Cancel button',
      c.length === 1 && c[0].querySelector('.confirm-chip-text').textContent === SENTENCE &&
      Array.from(c[0].querySelectorAll('button')).map(x => x.textContent).join('|') === 'Confirm|Cancel',
      c.length && c[0].outerHTML);
    check(++n, 'no ghost suggestion is written into the composer while a chip is up',
      b.deps.composerInput.value === '' && !b.deps.composerInput.classList.contains('staff-ai-suggestion-text'),
      'composer=' + b.deps.composerInput.value);
    await b.click(c[0], 'Confirm');
    check(++n, 'Confirm sends exactly the sentence through the send path, and the chip goes',
      b.sent.join('|') === SENTENCE && b.chips().length === 0, JSON.stringify(b.sent) + ' chips=' + b.chips().length);
  }
  {
    const b = boot();
    await b.api.push([{ type: 'confirmation', text: SENTENCE }]);
    await b.click(b.chips()[0], 'Cancel');
    check(++n, 'Cancel sends "Cancel: " and the sentence', b.sent.join('|') === 'Cancel: ' + SENTENCE, JSON.stringify(b.sent));
  }
  {
    const b = boot();
    await b.api.push([{ type: 'confirmation', text: 'Confirm: send — note: <b>hi</b><img src=x onerror=alert(1)>' },
      { type: 'confirmation', text: 'Confirm: second change' }]);
    const c = b.chips();
    check(++n, 'the sentence is text: an injected tag renders literally and adds no element',
      c[0].querySelector('.confirm-chip-text').textContent.includes('<b>hi</b><img') &&
      !c[0].querySelector('b') && !c[0].querySelector('img'), c[0].innerHTML);
    await b.click(c[1], 'Confirm');
    check(++n, 'one chip per pending confirmation, stacked; confirming one sends it and leaves the other',
      c.length === 2 && b.sent.join('|') === 'Confirm: second change' && b.chips().length === 1 && b.chips()[0] === c[0],
      'chips=' + c.length + ' sent=' + JSON.stringify(b.sent));
  }
  for (const [loading, readOnly] of [[true, false], [false, true]]) {
    const b = boot();
    await b.api.push([{ type: 'confirmation', text: SENTENCE }]);
    b.api.block(loading, readOnly);
    await b.click(b.chips()[0], 'Confirm');
    check(++n, 'a click ' + (loading ? 'while a turn is running' : 'on a read-only view') +
      ' sends nothing and keeps the chip', b.sent.length === 0 && b.chips().length === 1 &&
      b.deps.composerInput.value === '', 'sent=' + JSON.stringify(b.sent) + ' chips=' + b.chips().length);
  }
  {
    const b = boot();
    b.api.leave();   // the person opened another conversation while this turn was still streaming
    await b.api.push([{ type: 'confirmation', text: SENTENCE }]);
    check(++n, 'a confirmation from a turn the person has left shows no chip', b.chips().length === 0,
      'chips=' + b.chips().length);
  }
  {
    const b = boot();
    await b.api.push([{ type: 'suggestions', suggestion: 'Anything else?' }]);
    check(++n, 'with no chip, the ghost suggestion still shows as before',
      b.deps.composerInput.value === 'Anything else?' && b.chips().length === 0, 'composer=' + b.deps.composerInput.value);
  }

  console.log('\n' + results.join('\n'));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail > 0 ? 1 : 0);
})();
