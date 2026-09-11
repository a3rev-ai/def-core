/*
 * Pull a block out of a SHIPPED widget script (assets/js/staff-ai.js, or the
 * customer-chat widget) by marker, so a harness exercises the real code text
 * rather than a copy that can drift.
 *
 * The console's JS is one long IIFE over a shared closure — it cannot be
 * `require`d, and there is no build step to hook. Slicing the file by the
 * comment markers the code already carries is what lets a harness run the
 * shipped lines: change the code and the harness runs the change; delete the
 * marker and the extraction fails loudly rather than testing a stale copy.
 *
 * Every extractor asserts the names it expects to find, so a rename that moves
 * a function out of the block is a hard error here and not a silent pass.
 *
 * Each also honours an override env var (BLOCK, PROJECTS, MEMORIES, USAGE,
 * INTEGRATIONS, ATTACH_GATE, UPLOAD_STAGED, SCHEDULED, VOICE, CHAT_VOICE,
 * CHAT_STRINGS, ASK_ENTRY, ASK_ENTRY_CALLS) naming a file to load instead — that is how a "bite check" is run: put the OLD code back in
 * a scratch file, point the env var at it, and watch the checks that are meant
 * to catch the regression actually fail.
 */
const fs = require('fs');
const path = require('path');

const REPO = path.resolve(__dirname, '..', '..');
const JS_PATH = path.join(REPO, 'assets/js/staff-ai.js');
const CC_PATH = path.join(REPO, 'assets/js/def-core-customer-chat.js');
const VOICE_PATH = path.join(REPO, 'assets/js/def-core-voice.js');

function slice(label, startMatch, endMatch, needs, envVar, file) {
	if (envVar && process.env[envVar]) {
		return fs.readFileSync(path.resolve(process.env[envVar]), 'utf8');
	}
	const src = fs.readFileSync(file || JS_PATH, 'utf8').split(/\r?\n/);
	const start = src.findIndex(startMatch);
	const end = src.findIndex((l, i) => i > start && endMatch(l));
	if (start < 0 || end < 0 || end <= start) {
		throw new Error(label + ': MARKERS NOT FOUND (start=' + start + ' end=' + end + ')');
	}
	const block = src.slice(start, end).join('\n').replace(/\s+$/, '');
	for (const need of needs) {
		if (!block.includes(need)) throw new Error(label + ': BLOCK MISSING ' + need);
	}
	return block;
}

// The page shell: showPage / showChat / applyRoute, the hash listener, the
// delegated sidebar click and the Escape rule (D-C3, D-C4, D-C6).
function pageShell() {
	return slice('page shell',
		l => l.includes("── The console's page shell"),
		l => l.includes('// New chat (shared with Projects'),
		['function showPage', 'function showChat', 'function applyRoute', 'chatEntryBelow'],
		'BLOCK');
}

// initProjects, the C2 page (cards, the ⋯ menu, the touch sheets, Create).
function projects() {
	return slice('initProjects',
		l => l.startsWith('\t(function initProjects() {'),
		l => l.startsWith('\t(function initMemories() {'),
		['consolePages.push', 'function toggleManageMenu', 'function createProject',
			'function closeManageSheets', 'function openProjectDocuments'],
		'PROJECTS');
}

// The C3 trio, each from its own marker to the block that follows it.
function memories() {
	return slice('initMemories',
		l => l.startsWith('	(function initMemories() {'),
		l => l.includes('// USAGE (Usage & Budgets D-U7)'),
		// The Ask entry's three keys are built from the base now (C5), so the key
		// to assert on is the base the block hands the shared helper.
		['consolePages.push', 'function loadList', 'function removeMemory', "askEntry(askBtn, 'memories'"],
		'MEMORIES');
}

function usage() {
	return slice('initUsage',
		l => l.startsWith('	(function initUsage() {'),
		l => l.includes('// SCHEDULED TASKS (Phase 3)'),
		['consolePages.push', 'function loadUsage', 'function render', 'usageRefresh'],
		'USAGE');
}

function integrations() {
	return slice('initIntegrations',
		l => l.startsWith('	(function initIntegrations() {'),
		l => l.includes('// MY DOCUMENTS PANEL (document library'),
		['consolePages.push', 'function loadList', 'function renderRow', 'function connect', 'pageOpen'],
		'INTEGRATIONS');
}

// C4: the document viewer as a PAGE — its registry entry with the id-carrying
// route, the chunked load, "Show more", and the download link off the response.
function documentViewer() {
	return slice('document viewer',
		l => l.startsWith('\t(function initDocumentViewer() {'),
		l => l.startsWith('\t(function initDocuments() {'),
		['consolePages.push', 'async function load', 'async function fetchChunk',
			'openDocumentViewer ='],
		'DOCVIEWER');
}

// C5: the one "Ask X how this works" entry, and the seven shipped calls to it.
// The helper is sliced by its markers; the calls are found BY PATTERN — an
// askEntry( … ) at the console's inner indent through the line that closes it —
// so a page that moves keeps its entry covered and a page that loses one is a
// hard failure here rather than a quietly untested button.
function askEntry() {
	return slice('askEntry',
		l => l.includes('── The "Ask X how this works" entry'),
		l => l.includes('── end Ask entry'),
		['function askEntry', "'AskNamed'", "'AskPrompt'", 'defaults.before'],
		'ASK_ENTRY');
}

function askEntryCalls() {
	if (process.env.ASK_ENTRY_CALLS) {
		return JSON.parse(fs.readFileSync(path.resolve(process.env.ASK_ENTRY_CALLS), 'utf8'));
	}
	const src = fs.readFileSync(JS_PATH, 'utf8').split(/\r?\n/);
	const calls = [];
	for (let i = 0; i < src.length; i++) {
		if (!/^\t\taskEntry\(/.test(src[i])) continue;
		const end = src.findIndex((l, j) => j > i && l === '\t\t});');
		if (end < 0) throw new Error('askEntry calls: UNCLOSED CALL at line ' + (i + 1));
		const source = src.slice(i, end + 1).join('\n');
		const base = /askEntry\(\s*[^,]+,\s*'([A-Za-z0-9_]+)'/.exec(source);
		if (!base) throw new Error('askEntry calls: NO KEY BASE at line ' + (i + 1));
		calls.push({ base: base[1], source: source, line: i + 1 });
		i = end;
	}
	if (calls.length !== 7) {
		throw new Error('askEntry calls: EXPECTED SEVEN, FOUND ' + calls.length +
			' (' + calls.map(c => c.base).join(', ') + ')');
	}
	return calls;
}

// Append the shipped Ask-entry helper to a harness's parallel names/outer arrays,
// built from the seam stubs those arrays already declare. Three harnesses wire it
// exactly this way, so it lives here rather than three times over.
function pushAskEntry(window, names, outer) {
	const at = name => outer[names.indexOf(name)];
	outer.push(buildAskEntry(window, {
		t: at('t'), assistantName: at('assistantName'), onAssistantName: at('onAssistantName'),
		clearActiveProject: at('clearActiveProject'), resetToNewChat: at('resetToNewChat'),
		composerInput: at('composerInput'), updateSendButton: at('updateSendButton'),
		sendMessage: at('sendMessage')
	}));
	names.push('askEntry');
}

// Compile the shipped Ask-entry helper into a harness's jsdom realm. Four pages
// and the task creator call it now, so this is the one place they all get the
// REAL helper — a harness that stood in a fake here would be testing its own
// stub's labels instead of the console's.
function buildAskEntry(window, deps) {
	return new window.Function(
		'window', 'document', 't', 'assistantName', 'onAssistantName', 'clearActiveProject',
		'resetToNewChat', 'composerInput', 'updateSendButton', 'sendMessage',
		askEntry() + '\nreturn askEntry;'
	)(
		window, window.document,
		deps.t || function (key, def) { return def; },
		deps.assistantName || '',
		deps.onAssistantName || function (relabel) { relabel(); },
		deps.clearActiveProject || function () {},
		deps.resetToNewChat || function () {},
		deps.composerInput,
		deps.updateSendButton || function () {},
		deps.sendMessage || function () {}
	);
}

// C3b: the installed app's release check — the version compare, the quiet-moment
// rule, the after-the-reload notice, and the visibilitychange listener driving them.
function release() {
	return slice('release check',
		l => l.includes('── C3b: the installed app keeps up with the release'),
		l => l.includes('// Render messages'),
		['function quietEnough', 'function takeUpdateWhenQuiet', 'function checkForNewRelease',
			"addEventListener('visibilitychange'"],
		'RELEASE');
}

// The SSE stream handlers of both widgets — the streaming state, the renderer
// and the event switch (V-S7b: `step_superseded` moves a superseded round out
// of the bubble).
function staffAiStream() {
	return slice('staff-ai stream',
		l => l.includes('// Progressive markdown rendering state.'),
		l => l.includes('// Read SSE chunks'),
		['function renderStreamChunk', 'async function processEventQueue'],
		'STAFFSTREAM');
}

function customerChatStream() {
	return slice('customer-chat stream',
		l => l.includes('// Progressive text rendering state.'),
		l => l.includes('fetch(config.chatStreamUrl, {'),
		['function drainNextWord', 'function handleSSEEvent'],
		'CCSTREAM', CC_PATH);
}

// The console's voice block: the recorder wiring, endConversation, and the
// spoken-stop rule (V-S6b).
function voice() {
	return slice('voice',
		l => l.includes('// VOICE (7.7.1)'),
		l => l.startsWith('\tasync function sendMessage() {'),
		['function handleSpokenStop', 'function endConversation', 'function dropUnfilledTranscript'],
		'VOICE');
}

// Customer Chat's voice section, out of the widget's own file.
function chatVoice() {
	return slice('customer chat voice',
		l => l.includes('6b. VOICE'),
		l => l.startsWith('\tfunction sendMessageSync('),
		['function endOnSpokenStop', 'function endConversation', 'function dropUnfilledTranscript'],
		'CHAT_VOICE', CC_PATH);
}

// The widget's shipped English strings — the i18n map a phrase set has to live
// in for a translator to ever see it.
function chatStrings() {
	return slice('customer chat strings',
		l => l.includes('var DEFAULT_STRINGS = {'),
		l => l.includes('var SANITIZE_CONFIG = {'),
		['voiceStopPhrases', 'micStart'],
		'CHAT_STRINGS', CC_PATH);
}

// initScheduled, the Scheduled page and its creator/editor modal (row 9: the
// `once` cadence, the date field, the card's two one-off badges).
function scheduled() {
	return slice('initScheduled',
		l => l.includes('// SCHEDULED TASKS (Phase 3)'),
		l => l.includes('// UPLOAD EVENT HANDLERS'),
		['consolePages.push', 'function scheduleBadgeText', 'function onceDate',
			'function applyCadenceRows', 'function fillTaskForm', 'function saveTask'],
		'SCHEDULED');
}

// ── Customer Chat (U-1b) ────────────────────────────────────────────────

// The attach gate: the control's visibility, the thread setter every
// assignment goes through, and the drop/paste path that has no button to hide.
function attachGate() {
	return slice('attach gate',
		l => l.includes('── The attach gate (U-1b)'),
		l => l.includes('── end attach gate'),
		['function setUploadEligible', 'function setThreadId',
			'function refreshAttachControl', 'function stageAttachedFiles'],
		'ATTACH_GATE', CC_PATH);
}

// The staged-upload path, which is what actually names the conversation.
function uploadStaged() {
	return slice('uploadStagedFiles',
		l => l.startsWith('\tfunction uploadStagedFiles() {'),
		l => l.includes('// Server copy renders ONLY for known-safe refusal codes'),
		['function uploadedFileIds', 'uploadSingleFile('],
		'UPLOAD_STAGED', CC_PATH);
}

function customerChatSource() {
	return fs.readFileSync(CC_PATH, 'utf8');
}

// ── The shipped TEMPLATE, sliced the same way ───────────────────────────
// A harness that hand-writes its own copy of a <section> tests the copy: the
// page can be renamed, lose an id, change a description or take the wrong
// button class and every behaviour check still passes. These pull the real
// markup out of templates/staff-ai-shell.php and hand it to jsdom, so the
// behaviour checks run against what ships.

const TEMPLATE_PATH = path.join(REPO, 'templates/staff-ai-shell.php');

function templateSource() {
	return fs.readFileSync(TEMPLATE_PATH, 'utf8');
}

// The two WordPress escapers the console's markup uses, so a sliced string
// reaches the DOM the way the page renders it. Anything else inside <?php … ?>
// is a hard error rather than raw PHP smuggled into the fixture.
function phpToHtml(chunk, label) {
	const htmlEsc = v => v.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	const attrEsc = v => htmlEsc(v).replace(/"/g, '&quot;');
	const unquote = v => v.replace(/\\(['\\])/g, '$1');
	const ECHO = /<\?php\s+echo\s+esc_(html|attr)__\(\s*'((?:\\.|[^'\\])*)'\s*,\s*'digital-employees'\s*\);\s*\?>/g;
	// One interpolated string in the creator: printf( esc_html__( '…%s' ),
	// esc_html( $expr ) ). The value is the reader's own session, so the fixture
	// names a stand-in; the SHIPPED sentence around it is what matters here.
	const PRINTF = /<\?php\s*(?:\/\*[\s\S]*?\*\/\s*)?printf\(\s*esc_html__\(\s*'((?:\\.|[^'\\])*)'\s*,\s*'digital-employees'\s*\)\s*,\s*esc_html\([^)]*\)\s*\);\s*\?>/g;
	// A block that carries only a comment renders nothing. The body is spelled
	// "anything that is not the terminator" rather than lazily: a lazy run can
	// still be pushed PAST its own `*/` to satisfy the `?>` that follows, which
	// would swallow a real echo sitting between the two.
	const COMMENT = /<\?php\s*\/\*(?:(?!\*\/)[\s\S])*\*\/\s*\?>/g;
	const out = chunk
		.replace(PRINTF, (m, str) => htmlEsc(unquote(str)).replace('%s', 'you@example.test'))
		.replace(COMMENT, '')
		.replace(ECHO, (m, kind, str) => (kind === 'attr' ? attrEsc : htmlEsc)(unquote(str)));
	const left = out.match(/<\?php[\s\S]*?\?>/);
	if (left) throw new Error(label + ': unhandled PHP in the sliced markup — ' + left[0].slice(0, 80));
	return out;
}

// Slice one balanced element by its opening tag, counting nesting so a child of
// the same kind added later cannot truncate the slice.
function element(src, tag, openMatch, label) {
	const lines = src.split(/\r?\n/);
	const start = lines.findIndex(openMatch);
	if (start < 0) throw new Error(label + ': OPENING TAG NOT FOUND');
	const open = new RegExp('<' + tag + '[\\s>]', 'g');
	const close = new RegExp('</' + tag + '>', 'g');
	let depth = 0;
	for (let i = start; i < lines.length; i++) {
		depth += (lines[i].match(open) || []).length;
		depth -= (lines[i].match(close) || []).length;
		if (depth === 0) return lines.slice(start, i + 1).join('\n');
	}
	throw new Error(label + ': UNBALANCED <' + tag + '>');
}

// The console page section with this id, e.g. 'memoriesPane'.
function templatePage(id) {
	const chunk = element(templateSource(), 'section',
		l => l.includes('id="' + id + '"'), 'section#' + id);
	if (!/class="console-page/.test(chunk)) throw new Error('section#' + id + ': not a .console-page');
	return phpToHtml(chunk, 'section#' + id);
}

// The sidebar nav, so the entries the shell delegates from are the shipped ones
// — their tag, their href, and whether they still claim to open a dialog.
function templateNav() {
	return phpToHtml(
		element(templateSource(), 'nav', l => l.includes('class="sidebar-nav"'), 'nav.sidebar-nav'),
		'nav.sidebar-nav');
}

// A .modal-overlay by id, e.g. 'scheduleModal' — the creator/editor's markup.
function templateModal(id) {
	return phpToHtml(element(templateSource(), 'div',
		l => l.includes('id="' + id + '"'), 'div#' + id), 'div#' + id);
}

module.exports = { REPO, JS_PATH, CC_PATH, VOICE_PATH, TEMPLATE_PATH, slice, pageShell, projects, memories,
	usage, integrations, documentViewer, release, askEntry, askEntryCalls, buildAskEntry, pushAskEntry,
	staffAiStream, customerChatStream, scheduled,
	attachGate, uploadStaged, customerChatSource, voice, chatVoice, chatStrings,
	templateSource, templatePage, templateNav, templateModal };
