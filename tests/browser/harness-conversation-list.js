/*
 * The history list keeps its place — behavioural harness (v8.1.2).
 *
 * renderConversationList() redraws the list whole on every change: it removes
 * every .conversation-item and appends them again. A scroller whose children all
 * leave is a scroller at 0, so opening a conversation from far down the history
 * threw the list back to the top while it loaded — and the row just tapped was
 * off the screen. The redraw now reads the offset before the rows go and puts it
 * back once they are all returned.
 *
 * Runs the SHIPPED renderConversationList (sliced by marker, tests/browser/
 * extract.js) inside jsdom against the SHIPPED markup of the list and its
 * placeholder. jsdom does no layout, so scrollTop is instrumented on the element
 * itself: that is what lets a check see WHEN it was read and written, and not
 * only what it ends up as.
 *
 *  1. The place is kept: a redraw leaves the offset where the reader left it.
 *  2. Every way the list is redrawn: a conversation opened, loaded, renamed,
 *     deleted, and the empty list. And the two halves of the rule: a send
 *     re-fetches the list and the open chat comes back at the TOP, where keeping
 *     the offset would hide it, so it is brought back on screen — while a distant
 *     rename, with the open chat where it was, leaves the reader untouched. The
 *     reveal is conditional on the open row having MOVED, and that is the half a
 *     bite must catch: make it unconditional and the distant-rename case fails.
 *  3. The redraw still does its job: the rows are the conversations, in order,
 *     and the open one is the row marked .active (which is what the CSS paints).
 *
 * Bite check: point the env var at the block as it stood in 8.1.1 —
 *   git show origin/main:assets/js/staff-ai.js | sed -n '/^\tfunction renderConversationList/,/Chat Options: the row/p' > /tmp/old.js
 *   CONVERSATION_LIST=/tmp/old.js node tests/browser/harness-conversation-list.js
 *
 * 12 checks.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');
const BLOCK = extract.conversationList();

// One row's height and the list's, for the modelled scroller below.
const ROW = 60, VIEWPORT = 120;

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + label); }
	else { fail++; results.push('  FAIL ' + label + (detail ? ' -- ' + detail : '')); }
}

// The SHIPPED markup: the list is a <nav> and the placeholder is the child every
// row is inserted before.
const HTML = `<!doctype html><html><body>
<nav class="conversation-list" id="conversationList" aria-label="Conversations">
	<div class="conversation-list-placeholder" id="conversationPlaceholder">No conversations yet</div>
</nav>
</body></html>`;

function boot(conversations, currentConversationId) {
	const dom = new JSDOM(HTML);
	const window = dom.window, document = window.document;
	const list = document.getElementById('conversationList');

	// jsdom has no layout, so scrollTop would answer 0 to every question and a
	// harness built on it would pass on the broken code too. Stand a real scroller
	// on the element: rows of a fixed height inside a fixed viewport, and — this is
	// the whole of the bug — an offset CLAMPED to the content as a browser clamps
	// it, the moment a row leaves. Empty the list and the offset is 0 and stays 0;
	// nothing but writing it back puts the reader where they were.

	let offset = 0;
	const rows = () => list.querySelectorAll('.conversation-item').length;
	const ceiling = () => Math.max(0, rows() * ROW - VIEWPORT);
	const clamp = () => { offset = Math.max(0, Math.min(offset, ceiling())); };
	Object.defineProperty(list, 'scrollTop', {
		configurable: true,
		get() { return offset; },
		set(v) { offset = Math.max(0, Math.min(v, ceiling())); },
	});
	// A browser re-clamps on the mutation itself, not at the next read: the rows
	// are removed one at a time, and by the last one the ceiling is 0.
	const remove = window.Element.prototype.remove;
	window.Element.prototype.remove = function () { remove.call(this); clamp(); };
	const insertBefore = list.insertBefore;
	list.insertBefore = function (node, ref) { const r = insertBefore.call(list, node, ref); clamp(); return r; };
	// jsdom has no scrollIntoView either. 'nearest' scrolls the least it can: a row
	// above the fold comes to the top edge, one below it to the bottom edge, and a
	// row already in view does not move the list at all.
	window.Element.prototype.scrollIntoView = function () {
		const all = Array.from(list.querySelectorAll('.conversation-item'));
		const i = all.indexOf(this);
		if (i < 0) return;
		const top = i * ROW, bottom = top + ROW;
		if (top < offset) offset = top;
		else if (bottom > offset + VIEWPORT) offset = bottom - VIEWPORT;
	};

	// currentConversationId is a parameter, and a parameter is a binding the
	// returned setter can write: that is how a check taps a row and redraws.
	const api = new window.Function(
		'window', 'document', 'conversationList', 'conversationPlaceholder',
		'conversations', 'currentConversationId', 'closeChatMenu', 't', 'formatTime',
		'loadConversation', 'openChatMenu',
		BLOCK + '\nreturn { render: renderConversationList,'
		+ ' open: function (id) { currentConversationId = id; } };'
	)(
		window, document, list, document.getElementById('conversationPlaceholder'),
		conversations, currentConversationId, function () {},
		function (key, def) { return def; },
		function (at) { return String(at || ''); },
		function () {}, function () {}
	);

	return {
		document, list, render: api.render, open: api.open,
		titles: () => Array.from(list.querySelectorAll('.conversation-item-title')).map(el => el.textContent),
		active: () => Array.from(list.querySelectorAll('.conversation-item.active')).map(el => el.dataset.id),
	};
}

const history = () => Array.from({ length: 12 }, (_, i) => ({
	id: 'c' + i, title: 'Conversation ' + i, updated_at: '2026-09-14T08:0' + (i % 10) + ':00Z',
}));

// ---- 1. the place is kept --------------------------------------------------
{
	const t = boot(history(), null);
	t.render();
	t.list.scrollTop = 420;            // the reader scrolls down the history
	t.render();

	check('a redraw leaves the offset where the reader left it', t.list.scrollTop === 420,
		'scrollTop=' + t.list.scrollTop);
}

// ---- 2. every way the list is redrawn --------------------------------------
{
	// Opened: the reader scrolls down the history and taps a row. It becomes the
	// current conversation and the list is redrawn under it while it loads — the
	// one Steve reported. c7 and not c9: at this offset c7 is the row actually on
	// screen, and a reader cannot tap a row they cannot see.
	const t = boot(history(), null);
	t.render();
	t.list.scrollTop = 380;
	t.open('c7');
	t.render();
	check('a conversation opened from far down the list: the list does not jump to the top',
		t.list.scrollTop === 380, 'scrollTop=' + t.list.scrollTop);
	check('and the open conversation is the row marked active',
		t.active().join(',') === 'c7', t.active().join(','));
}
{
	// Loaded: more history arrives and is appended to the same array.
	const rows = history();
	const t = boot(rows, null);
	t.render();
	t.list.scrollTop = 300;
	rows.push({ id: 'c12', title: 'Conversation 12', updated_at: '2026-09-14T09:00:00Z' });
	t.render();
	check('more history loaded: the place is kept and the new row is there',
		t.list.scrollTop === 300 && t.titles().length === 13,
		'scrollTop=' + t.list.scrollTop + ' rows=' + t.titles().length);
}
{
	// Renamed: one title changes, the list is redrawn whole.
	const rows = history();
	const t = boot(rows, null);
	t.render();
	t.list.scrollTop = 260;
	rows[4].title = 'The quote for the shed';
	t.render();
	check('a chat renamed: the place is kept and the new name is on the row',
		t.list.scrollTop === 260 && t.titles()[4] === 'The quote for the shed',
		'scrollTop=' + t.list.scrollTop + ' title=' + t.titles()[4]);
}
{
	// The reader has a chat open near the top, has scrolled away from it, and
	// renames something far down the list. The open chat has NOT moved, so the
	// place is still theirs — the reveal must not fire and drag the list back to
	// a row they scrolled away from on purpose.
	const rows = history();
	const t = boot(rows, null);
	t.render();
	t.open('c1');
	t.render();
	t.list.scrollTop = 380;   // scrolled well away from the open chat
	rows[9].title = 'The quote for the shed';
	t.render();
	check('a distant chat renamed while another is open and off screen: the reader keeps their place',
		t.list.scrollTop === 380 && t.active().join(',') === 'c1',
		'scrollTop=' + t.list.scrollTop + ' active=' + t.active().join(','));
}
{
	// Deleted: a row goes and the list is redrawn whole.
	const rows = history();
	const t = boot(rows, null);
	t.render();
	t.list.scrollTop = 240;
	rows.splice(2, 1);
	t.render();
	check('a chat deleted: the place is kept and the row is gone',
		t.list.scrollTop === 240 && t.titles().length === 11 && t.titles().indexOf('Conversation 2') === -1,
		'scrollTop=' + t.list.scrollTop + ' rows=' + t.titles().length);
}
{
	// The empty list takes the same rule out of the function.
	const rows = history();
	const t = boot(rows, null);
	t.render();
	t.list.scrollTop = 200;
	rows.length = 0;
	t.render();
	const placeholder = t.document.getElementById('conversationPlaceholder');
	check('the last chat deleted: the placeholder is shown and the list is at its top',
		placeholder.style.display === 'block' && t.titles().length === 0 && t.list.scrollTop === 0,
		'display=' + placeholder.style.display + ' scrollTop=' + t.list.scrollTop);
}

{
	// A send re-fetches the list (loadConversations runs on every send) and the
	// thread just used comes back FIRST. Keeping the offset would then hold the
	// list where that row no longer is, with the open chat off the top of it —
	// worse than the jump this whole change removes.
	const rows = history();
	const t = boot(rows, null);
	t.render();
	t.list.scrollTop = 380;
	t.open('c7');
	t.render();
	const keptWhileInView = t.list.scrollTop;

	rows.unshift(rows.splice(7, 1)[0]);   // the server returns it newest-first
	t.render();
	check('a send moves the open chat to the top of the list: it is brought back on screen, not left above it',
		t.list.scrollTop === 0 && t.active().join(',') === 'c7',
		'scrollTop=' + t.list.scrollTop + ' active=' + t.active().join(','));
	check('and a redraw that does NOT move the open chat leaves the offset exactly alone',
		keptWhileInView === 380, 'scrollTop=' + keptWhileInView);
}

// ---- 3. the redraw still does its job --------------------------------------
{
	const t = boot(history(), 'c3');
	t.render();
	t.render();   // twice: the rows are replaced, not doubled
	check('the rows are the conversations, in order, once each',
		t.titles().length === 12 && t.titles()[0] === 'Conversation 0' && t.titles()[11] === 'Conversation 11',
		t.titles().length + ' rows');
	check('one row is active — the open conversation, and only it',
		t.active().join(',') === 'c3', t.active().join(','));
}

console.log('harness-conversation-list: ' + pass + ' passed, ' + fail + ' failed');
results.forEach(r => console.log(r));
process.exit(fail ? 1 : 0);
