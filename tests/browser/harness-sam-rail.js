/*
 * The Setup Assistant column collapses to a rail on a desktop (S4, v8.3.0).
 *
 * Above 782px the panel is a docked column that reserves 380px of every page it
 * rides on — Settings, Content AI and Connection Logs — whether or not anyone is
 * talking to Sam. Collapsed it is a 44px rail on the right edge carrying the Ask
 * Sam entry, and #wpcontent's margin follows the state, so the page gets the
 * width back. Sam is the help layer, so the entry never disappears: collapsed is
 * narrow, not absent.
 *
 * Two halves, because the feature has two:
 *
 *  1-20. THE BEHAVIOUR, in jsdom, over the SHIPPED block — sliced out of
 *        assets/js/setup-assistant-drawer.js by the S4 markers
 *        (extract.setupAssistantCollapse), so these are the shipped lines and
 *        not a copy that can drift. Every check drives the DOM through the
 *        shipped bindCollapse() and bootCollapsed(), never by calling the
 *        painter behind the buttons' back.
 *
 *        Three groups are worth reading before the rest:
 *
 *        6-10 — the default is EXPANDED, and it has to survive being reached
 *        from every direction: nothing stored, a '0', junk nobody wrote, a
 *        getItem that throws, a setItem that throws. This is the check that
 *        stops a refactor quietly shipping "collapsed unless proven otherwise",
 *        which would take Sam off the page for every admin who has never
 *        touched the control.
 *
 *        14-16 — a conversation survives. The collapse hides the panel, it does
 *        not unmount it, so a reply in flight keeps arriving: a fake stream
 *        appends across the collapse and everything it wrote is still there
 *        afterwards. 16 is the reason WHY, asserted off the block's own text —
 *        the collapse has no opinion about the message stream at all.
 *
 *        17-20 — the breakpoint. A remembered desktop collapse is never painted
 *        onto a phone (below 783px there is no rail rule to bring the panel back
 *        from it), and neither listener answers on the wrong side of it, so the
 *        phone's trigger chip and × keep the open/close they have always had.
 *
 * 21-33. THE STYLESHEET AND THE MARKUP the block cannot contain, read out of the
 *        shipped files. extract.mediaBlock reads one breakpoint at a time, which
 *        is the whole question here: the same selectors say opposite things on
 *        the two sides of 783px, and a rule indexed by class alone cannot tell
 *        you which side you are on.
 *
 *        25 came out of the canary and is the subtle one. The state is painted
 *        from localStorage by a footer script, which is after the page has laid
 *        out — so every transition has to be armed a frame LATER than the paint,
 *        or an admin who chose the rail watches the column animate itself shut on
 *        every single visit. It enumerates the four rules that must not be armed
 *        early rather than naming one, so the next declaration added in front of
 *        the gate fails here without anyone remembering to add a case.
 *
 *        27-29 are the phone's rules, unchanged, held here so the desktop rail
 *        can never be bought with the phone's slide-in.
 *
 * Bite checks, all four run. The extractor takes SA_COLLAPSE, naming a file to
 * load instead of the shipped block:
 *
 *   SA_COLLAPSE=/tmp/old-collapse.js node tests/browser/harness-sam-rail.js
 *
 *  - The 8.2.8 drawer, which had no collapse at all, so the block is empty: the
 *    file dies in boot() on the first missing name, exit 1. The loudest possible
 *    failure, and the right one — there is nothing there to check.
 *  - readCollapsed() returning `getItem(...) !== '0'`, the same feature defaulted
 *    the other way: 1, 6 and 8 go red and everything else stays green. That is
 *    the mutation this file most exists to catch, because it is the one that
 *    takes Sam off the page for every admin who never asked.
 *  - paintCollapsed() emptying .def-sa-messages, a collapse that tears the
 *    conversation down instead of hiding it: 14, 15 and 16 go red.
 *  - And the stylesheet, which the CSS half reads out of the working tree rather
 *    than through the override. Put the 8.2.8 file back and 22-26, 30 and 31 go
 *    red while 27-29 stay green — which is the shape to expect, those three
 *    being the phone rules this PR deliberately leaves alone.
 *
 * 33 checks.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const BLOCK = extract.setupAssistantCollapse();

// The block minus its own prose. Check 16 asks what the CODE reaches for, and
// the comment above it names the very things it promises not to touch.
const BLOCK_CODE = BLOCK.split(/\r?\n/).filter(l => !/^\s*\/\//.test(l)).join('\n');

const read = f => fs.readFileSync(path.join(extract.REPO, f), 'utf8');
const DRAWER_CSS = read('assets/css/setup-assistant-drawer.css');
const DRAWER_JS = read('assets/js/setup-assistant-drawer.js');
const TPL = read('templates/setup-assistant-drawer.php');
const ADMIN_PHP = read('includes/class-def-core-admin.php');

let pass = 0, fail = 0;
const results = [];
let n = 0;
function check(label, cond, detail) {
	n++;
	if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

// `phone` is the label the TEMPLATE rendered, which the drawer captures in
// init() and puts back when a window drops below the breakpoint — not a third
// localized string.
const PHONE_CLOSE_LABEL = 'Close Setup Assistant';
const LABELS = {
	collapse: 'Collapse Setup Assistant',
	expand: 'Expand Setup Assistant',
	phone: PHONE_CLOSE_LABEL
};

// The namespaced prefix the shipped script builds from the site's REST URL
// (line ~88). The block derives its own key from it, which is what check 3 reads.
const LS_PREFIX = 'def_sa_httpsexamplecomwpjsonde_';
const KEY = LS_PREFIX + 'collapsed';

// A localStorage that can be made to misbehave the way a real one does: holding
// something nobody wrote, or throwing because the store is blocked or full.
function fakeStore(initial, mode) {
	return {
		data: Object.assign({}, initial),
		writes: [],
		getItem: function (k) {
			if (mode === 'throw-read') throw new Error('SecurityError');
			return Object.prototype.hasOwnProperty.call(this.data, k) ? this.data[k] : null;
		},
		setItem: function (k, v) {
			if (mode === 'throw-write') throw new Error('QuotaExceededError');
			this.writes.push([k, v]);
			this.data[k] = v;
		}
	};
}

// The page, in the shape the three call sites render it: the trigger chip inside
// the h1 carrying both labels, and the drawer with its header button carrying
// both icons. Checks 32-33 hold the shipped markup to this shape.
function boot(opts) {
	opts = opts || {};
	const desktopStart = opts.desktop !== false;

	const dom = new JSDOM('<!doctype html><html><body class="wp-admin admin-bar">' +
		'<div id="wpcontent"><div class="wrap"><h1>Digital Employees' +
		'<button type="button" id="def-setup-assistant-trigger" class="def-sa-trigger"' +
		' aria-expanded="false" aria-controls="def-setup-assistant-drawer">' +
		'<span class="dashicons dashicons-admin-comments"></span>' +
		'<span class="def-sa-trigger-label">Setup Assistant</span>' +
		'<span class="def-sa-trigger-rail">Ask Sam</span>' +
		'</button></h1></div></div>' +
		'<div id="def-setup-assistant-drawer" class="def-sa-drawer" aria-hidden="false">' +
		'<div class="def-sa-panel"><div class="def-sa-header">' +
		'<h2 class="def-sa-title">Sam - Setup Assistant</h2>' +
		'<div class="def-sa-header-actions">' +
		'<button type="button" class="def-sa-clear"></button>' +
		'<button type="button" class="def-sa-close" aria-label="Close Setup Assistant">' +
		'<span class="dashicons dashicons-no-alt def-sa-close-icon"></span>' +
		'<span class="dashicons dashicons-arrow-right-alt2 def-sa-collapse-icon"></span>' +
		'</button></div></div>' +
		'<div class="def-sa-messages" role="log"></div>' +
		'<div class="def-sa-composer"><textarea class="def-sa-input"></textarea></div>' +
		'</div></div></body></html>');

	const window = dom.window, document = window.document;
	const store = opts.store || fakeStore({});

	// The SHIPPED block, run as it ships. localStorage arrives as a parameter, so
	// the block's bare `localStorage` reads the fake one — the same shape the
	// drawer's own checkFirstVisit() uses.
	const api = new window.Function('window', 'document', 'localStorage', 'LS_PREFIX',
		BLOCK + '\nreturn { LS_COLLAPSED_KEY: LS_COLLAPSED_KEY, readCollapsed: readCollapsed,' +
		' writeCollapsed: writeCollapsed, paintCollapsed: paintCollapsed, setCollapsed: setCollapsed,' +
		' bootCollapsed: bootCollapsed, bindCollapse: bindCollapse,' +
		' syncCollapsedToViewport: syncCollapsedToViewport };'
	)(window, document, store, LS_PREFIX);

	const els = {
		body: document.body,
		close: document.querySelector('.def-sa-close'),
		trigger: document.getElementById('def-setup-assistant-trigger')
	};

	// The viewport, switchable mid-test the way a dragged window is. Both
	// listeners read it at CALL time, which is what this exercises.
	let desktop = desktopStart;
	const isDesktop = () => desktop;

	// bindEvents() binds these on every viewport; init() boots the state only on
	// a desktop. The fixture does the same, so a phone here is a real phone.
	const expands = [];
	api.bindCollapse(els, LABELS, isDesktop, () => expands.push(1));

	const armed = [];
	if (desktopStart) {
		api.bootCollapsed(els, LABELS, arm => armed.push(arm));
	}

	const messages = document.querySelector('.def-sa-messages');
	return {
		api, els, store, document, window, messages, expands,
		arm: () => armed.forEach(f => f()),
		setViewport: v => { desktop = v; },
		sync: () => api.syncCollapsedToViewport(els, LABELS, isDesktop),
		collapsed: () => document.body.classList.contains('def-sa-collapsed'),
		ready: () => document.body.classList.contains('def-sa-ready'),
		click: el => el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true })),
		// A reply arriving token by token, the way sendMessageStreaming appends.
		stream: text => {
			let bubble = messages.querySelector('.def-sa-message-streaming');
			if (!bubble) {
				bubble = document.createElement('div');
				bubble.className = 'def-sa-message def-sa-message-streaming';
				messages.appendChild(bubble);
			}
			bubble.textContent += text;
			return bubble;
		},
		text: () => (messages.textContent || '')
	};
}

// ── 1-5. Collapse: the class, the store, the two controls ───────────────────
//
// Driven through the shipped listener on the shipped button, so a control that
// paints without persisting — or persists without painting — fails here.
const a = boot();
check('a fresh desktop with nothing stored starts EXPANDED', !a.collapsed());

a.click(a.els.close);
check('the Collapse control sets the state class the stylesheet reads',
	a.collapsed(), 'body class: ' + a.els.body.className);

check('collapsing writes the remembered state under the namespaced key',
	a.store.writes.length === 1 && a.store.writes[0][0] === KEY && a.store.writes[0][1] === '1',
	'writes: ' + JSON.stringify(a.store.writes));

check('the Collapse control flips aria-expanded and relabels itself Expand',
	a.els.close.getAttribute('aria-expanded') === 'false' &&
	a.els.close.getAttribute('aria-label') === LABELS.expand &&
	a.els.close.getAttribute('title') === LABELS.expand,
	'aria-expanded=' + a.els.close.getAttribute('aria-expanded') +
		' label=' + a.els.close.getAttribute('aria-label'));

// The rail is the page's own trigger chip re-used, not a second entry, so its
// aria-expanded is the other end of the same disclosure. No aria-label is put
// over the top of it: its name stays the "Ask Sam" it renders.
check('the rail carries aria-expanded="false" and keeps its own visible name',
	a.els.trigger.getAttribute('aria-expanded') === 'false' &&
	a.els.trigger.getAttribute('aria-label') === null &&
	/Ask Sam/.test(a.els.trigger.textContent),
	'aria-expanded=' + a.els.trigger.getAttribute('aria-expanded') +
		' aria-label=' + a.els.trigger.getAttribute('aria-label'));

// ── 6-10. The default is EXPANDED, reached from every direction ─────────────
check('a boot with nothing stored is expanded', !boot().collapsed());

check('a boot with a stored "0" is expanded',
	!boot({ store: fakeStore({ [KEY]: '0' }) }).collapsed());

check('a boot with junk in the key is expanded, not collapsed',
	!boot({ store: fakeStore({ [KEY]: 'yes' }) }).collapsed());

check('a localStorage that throws on read leaves Sam where he was',
	!boot({ store: fakeStore({}, 'throw-read') }).collapsed());

// A store that cannot be written costs the preference, not the column: the
// collapse still has to happen on screen.
const noWrite = boot({ store: fakeStore({}, 'throw-write') });
noWrite.click(noWrite.els.close);
check('a localStorage that throws on write still collapses the column',
	noWrite.collapsed());

// ── 11-13. A fresh boot reads what was stored, and the rail puts it back ────
const stored = boot({ store: fakeStore({ [KEY]: '1' }) });
check('a fresh boot with a stored collapse opens as the rail',
	stored.collapsed() &&
	stored.els.close.getAttribute('aria-expanded') === 'false' &&
	stored.els.trigger.getAttribute('aria-expanded') === 'false',
	'collapsed=' + stored.collapsed());

// The transitions are armed a frame LATER on purpose. Armed during the boot
// paint, a page that opens collapsed would animate itself shut on every visit.
const readyAtPaint = stored.ready();
stored.arm();
check('the boot paint lands before the transitions are armed',
	!readyAtPaint && stored.ready(),
	'ready at paint: ' + readyAtPaint + ', ready after arming: ' + stored.ready());

stored.click(stored.els.trigger);
check('one click on the rail expands it again, and remembers that too',
	!stored.collapsed() &&
	stored.els.close.getAttribute('aria-expanded') === 'true' &&
	stored.els.close.getAttribute('aria-label') === LABELS.collapse &&
	stored.store.data[KEY] === '0' &&
	stored.expands.length === 1,
	'collapsed=' + stored.collapsed() + ' stored=' + stored.store.data[KEY]);

// ── 14-16. A conversation survives the collapse ─────────────────────────────
//
// The panel is hidden, never unmounted. A reply in flight keeps arriving, and
// everything it wrote is still there when the column comes back.
const REPLY = 'Your API key is set, and the connection is live.';
const live = boot();
live.stream('Your API key ');
live.stream('is set, ');
live.click(live.els.close);
live.stream('and the connection ');
live.stream('is live.');

check('a streaming reply keeps appending straight through a collapse',
	live.collapsed() && live.text() === REPLY,
	'collapsed=' + live.collapsed() + ' text=' + JSON.stringify(live.text()));

live.click(live.els.trigger);
check('the whole reply is there when the column is expanded again',
	!live.collapsed() && live.text() === REPLY &&
	live.messages.querySelectorAll('.def-sa-message').length === 1,
	'text=' + JSON.stringify(live.text()));

// Asserted off the block's own code rather than off this fixture: a stream
// survives because the collapse has no opinion about the stream at all.
check('nothing in the shipped collapse code reaches into the message stream',
	!/def-sa-messages|messagesEl|threadId|EventSource|fetch\(|\.abort|innerHTML/i.test(BLOCK_CODE),
	'the block reaches for the stream — a collapse that touches it can stop it');

// ── 17-20. The breakpoint: the phone keeps exactly what it had ──────────────
const phone = boot({ desktop: false });
phone.click(phone.els.close);
check('below 783px the header button collapses nothing — it is still Close',
	!phone.collapsed() && phone.store.writes.length === 0,
	'collapsed=' + phone.collapsed() + ' writes=' + JSON.stringify(phone.store.writes));

phone.click(phone.els.trigger);
check('below 783px the trigger chip is not the rail either — it is still the chip',
	!phone.collapsed() && phone.expands.length === 0);

// A window dragged narrow. The preference is kept; the class is not painted on a
// viewport with no rail rule to bring the panel back from it.
const dragged = boot({ store: fakeStore({ [KEY]: '1' }) });
dragged.setViewport(false);
dragged.sync();
check('dragged below 783px the rail comes off, so a phone gets the panel it always had',
	!dragged.collapsed() && dragged.store.data[KEY] === '1',
	'collapsed=' + dragged.collapsed() + ' stored=' + dragged.store.data[KEY]);

// The class is not the only thing the desktop wrote. A window dragged narrow
// after a collapse used to leave a Close button announcing itself as "Expand",
// with a tooltip saying so and an aria-expanded that does not belong on a Close.
// "Below 783px nothing changes" has to be true of the ATTRIBUTES too.
check('and the header button is the template\'s Close again, with nothing of the desktop left on it',
	dragged.els.close.getAttribute('aria-label') === PHONE_CLOSE_LABEL &&
	dragged.els.close.getAttribute('title') === null &&
	dragged.els.close.getAttribute('aria-expanded') === null,
	'aria-label=' + dragged.els.close.getAttribute('aria-label') +
		' title=' + dragged.els.close.getAttribute('title') +
		' aria-expanded=' + dragged.els.close.getAttribute('aria-expanded'));

dragged.setViewport(true);
dragged.sync();
check('dragged wide again the remembered rail comes back',
	dragged.collapsed() && dragged.els.close.getAttribute('aria-expanded') === 'false');

// ── 21-26. The desktop rules, read at the desktop breakpoint ───────────────
// A breakpoint by brace depth, or an empty one. A media query that has gone
// missing should fail the CHECKS that read it, naming the rule, rather than
// killing the file before any of them report — which is exactly what happens
// when this harness is pointed at the 8.2.8 stylesheet.
function breakpoint(re) {
	try {
		return extract.mediaBlock(DRAWER_CSS, re);
	} catch (e) {
		return { start: -1, end: -1, text: '', missing: String(re) };
	}
}

const DOCK = breakpoint(/@media \(min-width: 783px\) \{/);
const decl = (css, sel, prop) => {
	const body = extract.ruleBody(css, sel);
	const m = body && new RegExp('(?:^|;)\\s*' + prop + ':\\s*([^;]+)').exec(body);
	return m ? m[1].trim() : null;
};
const RAIL = 'body.def-sa-collapsed .def-sa-trigger';

check('at 783px and up #wpcontent reserves the column: 380px',
	decl(DOCK.text, '#wpcontent', 'margin-right') === '380px',
	'got: ' + decl(DOCK.text, '#wpcontent', 'margin-right'));

check('collapsed it reserves the rail instead: 44px, so the page reclaims the space',
	decl(DOCK.text, 'body.def-sa-collapsed #wpcontent', 'margin-right') === '44px',
	'got: ' + decl(DOCK.text, 'body.def-sa-collapsed #wpcontent', 'margin-right'));

// The rail is the trigger, pinned to the right edge — and exactly as wide as the
// margin above it. A rail wider than its reserved margin is a rail sitting on
// top of the page it was meant to give the width back to.
check('the rail is the trigger, fixed to the right edge, the width of its own margin',
	decl(DOCK.text, RAIL, 'width') === decl(DOCK.text, 'body.def-sa-collapsed #wpcontent', 'margin-right') &&
	decl(DOCK.text, RAIL, 'width') === '44px' &&
	decl(DOCK.text, RAIL, 'position') === 'fixed' &&
	decl(DOCK.text, RAIL, 'right') === '0' &&
	decl(DOCK.text, RAIL, 'display') === 'flex',
	'width=' + decl(DOCK.text, RAIL, 'width') + ' position=' + decl(DOCK.text, RAIL, 'position'));

// Hidden, not unmounted — the stylesheet half of checks 14-16. display:none would
// take the panel out of layout entirely, which is what breaks a stream's scroll.
const hidden = extract.ruleBody(DOCK.text, 'body.def-sa-collapsed .def-sa-drawer .def-sa-panel') || '';
check('collapsed the panel is moved off the edge and hidden, never display:none',
	/transform:\s*translateX\(100%\)/.test(hidden) &&
	/visibility:\s*hidden/.test(hidden) &&
	!/display:\s*none/.test(hidden),
	'got: ' + hidden.replace(/\s+/g, ' ').trim());

// The boot flash, which is a rule about WHERE the transitions are declared, not
// whether they exist. The state is painted from localStorage by a footer script,
// after the page has laid out — so anything that transitions before .def-sa-ready
// lands animates itself into the remembered state on every single visit. Three
// declarations have to be behind that gate and none in front of it.
// Read the VALUE rather than testing for "transition: not none" — a negative
// lookahead after \s* simply backtracks the whitespace away and matches the
// thing it was written to exclude.
const transitionOf = body => {
	const m = /(?:^|;)\s*transition:\s*([^;]+)/.exec(body || '');
	return m ? m[1].trim() : 'none';
};
const unarmed = [
	['the panel at rest', extract.ruleBody(DOCK.text, '.def-sa-panel')],
	['the collapsed panel', hidden],
	['the reserved margin', extract.ruleBody(DOCK.text, '#wpcontent')],
	['the collapsed margin', extract.ruleBody(DOCK.text, 'body.def-sa-collapsed #wpcontent')]
].filter(pair => transitionOf(pair[1]) !== 'none').map(pair => pair[0]);

check('no travel is armed before .def-sa-ready, so a remembered rail opens as the rail',
	unarmed.length === 0 &&
	/transition:\s*transform/.test(
		extract.ruleBody(DOCK.text, 'body.def-sa-ready .def-sa-drawer .def-sa-panel') || '') &&
	/transition:\s*transform/.test(
		extract.ruleBody(DOCK.text, 'body.def-sa-ready.def-sa-collapsed .def-sa-drawer .def-sa-panel') || '') &&
	/transition:\s*margin-right/.test(
		extract.ruleBody(DOCK.text, 'body.def-sa-ready #wpcontent') || ''),
	unarmed.length ? 'armed before .def-sa-ready: ' + unarmed.join(', ')
		: 'one of the three .def-sa-ready rules is missing');

check('the rail label reads vertically, and the chip\'s horizontal one comes off',
	/writing-mode:\s*vertical-rl/.test(
		extract.ruleBody(DOCK.text, 'body.def-sa-collapsed .def-sa-trigger-rail') || '') &&
	/display:\s*none/.test(
		extract.ruleBody(DOCK.text, 'body.def-sa-collapsed .def-sa-trigger-label') || ''));

// ── 27-29. The phone's rules, unchanged ─────────────────────────────────────
//
// The rail must never be bought with the phone's slide-in. These are the 8.2.8
// rules, asserted still to be exactly what they were.
const PHONE = breakpoint(/@media \(max-width: 600px\)/);
check('in portrait the panel is still the full width of the screen',
	decl(PHONE.text, '.def-sa-panel', 'width') === '100%',
	'got: ' + decl(PHONE.text, '.def-sa-panel', 'width'));

check('the phone trigger chip still drops its label and tightens its padding',
	decl(PHONE.text, '.def-sa-trigger-label', 'display') === 'none' &&
	decl(PHONE.text, '.def-sa-trigger', 'padding') === '6px 10px',
	'display=' + decl(PHONE.text, '.def-sa-trigger-label', 'display') +
		' padding=' + decl(PHONE.text, '.def-sa-trigger', 'padding'));

// The base panel: the overlay's own slide-in, off-screen until the drawer opens.
check('the base panel still slides in from off-screen at its own width',
	decl(DRAWER_CSS, '.def-sa-panel', 'transform') === 'translateX(100%)' &&
	decl(DRAWER_CSS, '.def-sa-panel', 'width') === '400px' &&
	/transform:\s*translateX\(0\)/.test(
		extract.ruleBody(DRAWER_CSS, '.def-sa-drawer[aria-hidden="false"] .def-sa-panel') || ''),
	'transform=' + decl(DRAWER_CSS, '.def-sa-panel', 'transform') +
		' width=' + decl(DRAWER_CSS, '.def-sa-panel', 'width'));

// ── 30-31. Reduced motion ───────────────────────────────────────────────────
//
// Matched with \s* rather than by exact selector text: the grouped selector
// spans two lines, and a Windows checkout puts a \r before each of them.
const RM = breakpoint(/@media \(min-width: 783px\) and \(prefers-reduced-motion: reduce\)/);
check('prefers-reduced-motion takes the travel out of collapse and expand',
	/body\.def-sa-ready \.def-sa-drawer \.def-sa-panel,\s*body\.def-sa-ready\.def-sa-collapsed \.def-sa-drawer \.def-sa-panel \{\s*transition:\s*none;/
		.test(RM.text) &&
	/body\.def-sa-ready #wpcontent \{\s*transition:\s*none;/.test(RM.text),
	RM.missing ? 'no such breakpoint in the stylesheet: ' + RM.missing
		: 'reduced-motion block: ' + RM.text.replace(/\s+/g, ' ').slice(0, 220));

// Scoped to the desktop, so a reduced-motion phone keeps the slide-in it has.
check('the reduced-motion rule is scoped to the desktop breakpoint, not to the phone',
	/min-width: 783px/.test(DRAWER_CSS.slice(RM.start, RM.start + 90)));

// ── 32-33. The markup and the strings the block cannot contain ──────────────
//
// The block paints a button and a rail it does not render. Both ends read off
// the shipped files, because neither is inside the slice.
check('the header button ships both icons and the phone\'s aria-label',
	/class="def-sa-close"/.test(TPL) &&
	/dashicons-no-alt def-sa-close-icon/.test(TPL) &&
	/dashicons-arrow-right-alt2 def-sa-collapse-icon/.test(TPL) &&
	/esc_attr_e\( 'Close Setup Assistant', 'digital-employees' \)/.test(TPL));

// The trigger chip is written inline at every page that carries the drawer, so
// the rail's label has to be at all three or one page collapses to a blank
// strip. Both desktop labels reach a translator through PHP — the JS carries the
// English as a fallback, not as the shipped text.
const CALL_SITES = ['templates/admin-settings.php',
	'includes/class-def-core-content-drafts-page.php',
	'includes/class-def-core-logs-page.php'];
const missingRail = CALL_SITES.filter(f =>
	!/<span class="def-sa-trigger-rail"><\?php esc_html_e\( 'Ask Sam', 'digital-employees' \); \?><\/span>/
		.test(read(f)));
check('every page carrying the drawer renders the rail\'s label, and both desktop labels ship through __()',
	missingRail.length === 0 &&
	/'collapseLabel'\s*=> __\( 'Collapse Setup Assistant', 'digital-employees' \)/.test(ADMIN_PHP) &&
	/'expandLabel'\s*=> __\( 'Expand Setup Assistant', 'digital-employees' \)/.test(ADMIN_PHP) &&
	/config\.collapseLabel/.test(DRAWER_JS) && /config\.expandLabel/.test(DRAWER_JS),
	missingRail.length ? 'no rail label in: ' + missingRail.join(', ') : 'labels not localized');

// ── 35-36. The two numbers nothing else can assert ──────────────────────────
//
// Both are constants the behaviour half takes as given: the harness passes
// LS_PREFIX in as a parameter and injects its own viewport boolean, so a change
// to either of the real ones would leave all 34 checks above green. They are
// read off the shipped files here for exactly that reason.

// The key is namespaced per site so two installs in one browser remember their
// own column. Check 3 proves the block builds its key from the prefix; this
// proves the prefix is the site-derived one and not a bare literal.
check('the remembered state hangs off the site-namespaced prefix the drawer already uses',
	/LS_COLLAPSED_KEY\s*=\s*LS_PREFIX\s*\+\s*'collapsed'/.test(BLOCK) &&
	/LS_PREFIX\s*=\s*'def_sa_'\s*\+\s*\(config\.restUrl/.test(DRAWER_JS) &&
	/\.replace\(\/\[\^a-z0-9\]\/gi, ''\)\.slice\(0, 32\)/.test(DRAWER_JS),
	'the prefix derivation at line ~88 has moved or the key no longer uses it');

// isDesktop() gates which half of each button answers; the media query gates
// whether there is a rail to answer with. They have to name the same edge, or
// there is a band of widths where the script thinks desktop and the stylesheet
// thinks phone — and in it the header button would write a preference while
// visibly doing nothing.
const jsEdge = /window\.innerWidth > (\d+)/.exec(DRAWER_JS);
const cssEdge = /@media \(min-width: (\d+)px\) \{/.exec(DRAWER_CSS);
check('the script and the stylesheet draw the breakpoint at the same place',
	!!jsEdge && !!cssEdge && Number(jsEdge[1]) + 1 === Number(cssEdge[1]),
	'script says innerWidth > ' + (jsEdge && jsEdge[1]) +
		', stylesheet says min-width: ' + (cssEdge && cssEdge[1]) + 'px');

console.log('\n' + results.join('\n'));
console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
process.exit(fail > 0 ? 1 : 0);
