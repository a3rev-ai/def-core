/*
 * The arrow back to the latest message (v9.5.0, W81).
 *
 * Runs the SHIPPED block (sliced by marker, extract.js) in jsdom. jsdom does no layout, so
 * the container's scroll metrics are instrumented, and ResizeObserver is a stub the harness
 * fires when the list grows.
 *
 * Bite check: remove the ResizeObserver line and the slice itself refuses (BLOCK MISSING).
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + String(pass + fail).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(pass + fail).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

function boot(reducedMotion) {
	const dom = new JSDOM('<!doctype html><div id="messagesContainer"><div id="messagesList"></div></div><button id="scrollBottomBtn" hidden></button>',
		{ runScripts: 'outside-only' });
	const w = dom.window;
	const metrics = { scrollHeight: 2000, clientHeight: 500, scrollTop: 1500 };
	const box = w.document.getElementById('messagesContainer');
	for (const key of Object.keys(metrics)) {
		Object.defineProperty(box, key, { get: () => metrics[key], set: v => { metrics[key] = v; }, configurable: true });
	}
	const scrolled = [];
	box.scrollTo = opts => { scrolled.push(opts); };
	w.matchMedia = () => ({ matches: !!reducedMotion });
	let grew = null;
	w.ResizeObserver = function (fn) { grew = fn; this.observe = () => {}; };
	w.eval('(function () {\n'
		+ "const messagesContainer = document.getElementById('messagesContainer');\n"
		+ "const messagesList = document.getElementById('messagesList');\n"
		+ extract.scrollArrow()
		+ '\nwindow.__t = { setStreaming: v => { _isStreaming = v; }, setUp: v => { _userScrolledUp = v; }, up: () => _userScrolledUp };\n'
		+ '})();');
	const btn = w.document.getElementById('scrollBottomBtn');
	return {
		w, btn, metrics, scrolled,
		scrollTo(top) { metrics.scrollTop = top; box.dispatchEvent(new w.Event('scroll')); },
		grow(by) { metrics.scrollHeight += by; grew(); },
	};
}

let t = boot();
t.scrollTo(1500);
check('at the latest message the arrow is hidden', t.btn.hidden === true);

t.scrollTo(600);
check('scrolled up, the arrow shows', t.btn.hidden === false);

t.scrollTo(1500);
check('back at the bottom it hides by itself', t.btn.hidden === true);

t = boot();
t.scrollTo(1450);
check('  (a reader just above the bottom counts as there)', t.btn.hidden === true);
t.grow(800);
check('a reply growing below the reader shows it, with no scroll event', t.btn.hidden === false);

t = boot();
t.scrollTo(600);
t.w.__t.setUp(true);
t.btn.click();
check('a click goes to the latest message, smoothly',
	t.scrolled.length === 1 && t.scrolled[0].top === t.metrics.scrollHeight && t.scrolled[0].behavior === 'smooth',
	JSON.stringify(t.scrolled));
check('  and the chat follows a reply again', t.w.__t.up() === false);

t = boot(true);
t.scrollTo(600);
t.btn.click();
check('someone who turned motion off gets no animation', t.scrolled[0].behavior === 'auto', JSON.stringify(t.scrolled));

t = boot();
t.w.__t.setStreaming(true);
t.scrollTo(600);
check('scrolling up during a reply still stops the chat following it', t.w.__t.up() === true && t.btn.hidden === false);

const shell = extract.templateSource();
check('the shipped page carries the button, hidden, with a label',
	/id="scrollBottomBtn"[^>]*aria-label="[^"]+"[^>]*\shidden>/.test(shell));
const css = fs.readFileSync(path.join(extract.REPO, 'assets/css/staff-ai.css'), 'utf8');
check('display:flex cannot defeat hidden', /\.scroll-bottom-btn\[hidden\]\s*\{\s*display:\s*none/.test(css));

console.log('harness-scroll-to-bottom: the arrow back to the latest message (v9.5.0)');
results.forEach(r => console.log(r));
console.log(pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
