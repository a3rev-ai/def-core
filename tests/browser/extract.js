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

function slice(label, startMatch, endMatch, needs, envVar) {
	if (envVar && process.env[envVar]) {
		return fs.readFileSync(path.resolve(process.env[envVar]), 'utf8');
	}
	const src = fs.readFileSync(JS_PATH, 'utf8').split(/\r?\n/);
	const start = src.findIndex(startMatch);
	const end = src.findIndex(endMatch);
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

module.exports = { REPO, JS_PATH, slice, pageShell, projects, memories, usage, integrations };
