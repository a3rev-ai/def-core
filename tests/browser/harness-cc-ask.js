/*
 * Customer Chat ask() - behavioural harness (v8.5.0), 6 checks.
 *
 * Runs the SHIPPED ask() block (extracted by marker from
 * assets/js/def-core-customer-chat.js) over a fake of the closure it lives in.
 *
 * What it holds: a page trigger's question is sent as the visitor's message,
 * trimmed, through the same submit a typed message takes; an empty question,
 * a destroyed widget, or a turn already in flight sends nothing and leaves the
 * composer alone.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const ASK = extract.chatAsk();

function boot(opts) {
  opts = opts || {};
  const dom = new JSDOM('<!doctype html><html><body><textarea class="def-cc-input"></textarea></body></html>');
  const input = dom.window.document.querySelector('.def-cc-input');
  input.value = opts.draft || '';
  const submits = [];
  const factory = new dom.window.Function(
    'els', 'destroyed', 'isComposerDisabled', 'setState', 'autoResizeInput', 'updateSendButton', 'handleSubmit',
    ASK + '\nreturn ask;');
  const ask = factory(
    { input: opts.noInput ? null : input }, !!opts.destroyed, !!opts.disabled,
    () => {}, () => {}, () => {}, e => submits.push(input.value));
  return { ask, input, submits };
}

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

(function () {
  let h = boot();
  const sent = h.ask('  What could Widrow do for my business?  ');
  check(1, 'a question is sent once, trimmed, as the composer text',
    sent === true && h.submits.length === 1 && h.submits[0] === 'What could Widrow do for my business?',
    JSON.stringify(h.submits));

  h = boot();
  check(2, 'an empty or blank question sends nothing',
    h.ask('') === false && h.ask('   ') === false && h.submits.length === 0);

  h = boot({ disabled: true, draft: 'my half-typed line' });
  check(3, 'a turn in flight refuses before touching the composer',
    h.ask('Another question') === false && h.submits.length === 0 && h.input.value === 'my half-typed line',
    'value=' + h.input.value);

  h = boot({ destroyed: true });
  check(4, 'a destroyed widget refuses', h.ask('Hello') === false && h.submits.length === 0);

  h = boot({ noInput: true });
  check(5, 'no composer yet refuses rather than throwing', h.ask('Hello') === false);

  h = boot();
  check(6, 'a non-string is coerced, not thrown on', h.ask(42) === true && h.submits[0] === '42');

  console.log('Customer Chat ask() harness');
  console.log(results.join('\n'));
  console.log(pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
})();
