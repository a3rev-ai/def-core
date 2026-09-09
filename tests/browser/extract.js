/*
 * Pull a block out of the SHIPPED assets/js/staff-ai.js by marker, so a harness
 * exercises the real code text rather than a copy that can drift.
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
 * INTEGRATIONS) naming a file
 * to load instead — that is how a "bite check" is run: put the OLD code back in
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
		['consolePages.push', 'function loadList', 'function removeMemory', 'memoriesAskPrompt'],
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
	const out = chunk.replace(ECHO, (m, kind, str) => (kind === 'attr' ? attrEsc : htmlEsc)(unquote(str)));
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

module.exports = { REPO, JS_PATH, CC_PATH, VOICE_PATH, TEMPLATE_PATH, slice, pageShell, projects,
	memories, usage, integrations, staffAiStream, customerChatStream, voice, chatVoice, chatStrings,
	templateSource, templatePage, templateNav };
