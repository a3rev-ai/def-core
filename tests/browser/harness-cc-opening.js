/*
 * Customer Chat opening message - behavioural harness (v8.6.0), 6 checks.
 *
 * Runs the SHIPPED renderOpeningMessage() block (extracted by marker from
 * assets/js/def-core-customer-chat.js) inside jsdom over a fake of its closure.
 *
 * What it holds: a site's own opening message replaces the built-in greeting,
 * as text with its line breaks; markup in it stays literal; blank or missing
 * keeps the built-in greeting with the assistant's name and the visitor's.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const OPENING = extract.chatOpening();

function render(config) {
  const dom = new JSDOM('<!doctype html><html><body></body></html>');
  const document = dom.window.document;
  const el = (tag, cls) => { const n = document.createElement(tag); if (cls) n.className = cls; return n; };
  const target = el('div', 'def-cc-message-content');
  const factory = new dom.window.Function('config', 'el', 'document', OPENING + '\nreturn renderOpeningMessage;');
  factory(config, el, document)(target);
  return target;
}

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

(function () {
  let t = render({ openingMessage: "Hi! I'm Joe.\nAsk me what Widrow would do for you.", assistantName: 'Joe' });
  check(1, "a site's message is the whole greeting, line breaks as <br>",
    t.textContent === "Hi! I'm Joe.Ask me what Widrow would do for you." && t.querySelectorAll('br').length === 1 && !t.querySelector('ul'),
    t.innerHTML);

  t = render({ openingMessage: '<b>bold</b> & <script>x()</script>', assistantName: 'Joe' });
  check(2, 'markup in the message stays literal text',
    !t.querySelector('b') && !t.querySelector('script') && t.textContent === '<b>bold</b> & <script>x()</script>', t.innerHTML);

  t = render({ openingMessage: '   ', assistantName: 'Joe', userFirstName: 'Sam' });
  check(3, 'blank keeps the built-in greeting, with the names',
    t.textContent.indexOf("Hi Sam! I'm Joe, your AI Assistant.") === 0 && t.querySelectorAll('li').length === 3, t.textContent.slice(0, 80));

  t = render({ assistantName: 'Joe' });
  check(4, 'a missing setting (older PHP) keeps the built-in greeting',
    t.textContent.indexOf("Hi! I'm Joe, your AI Assistant.") === 0 && t.textContent.indexOf('What can I do for you today?') > 0, t.textContent.slice(0, 80));

  t = render({ openingMessage: 'One line only' });
  check(5, 'a one-line message has no <br>', t.querySelectorAll('br').length === 0 && t.textContent === 'One line only');

  t = render({ openingMessage: 'a\r\nb\r\nc' });
  check(6, 'Windows line endings count as line breaks', t.querySelectorAll('br').length === 2 && t.textContent === 'abc', t.innerHTML);

  console.log('Customer Chat opening message harness');
  console.log(results.join('\n'));
  console.log(pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
})();
