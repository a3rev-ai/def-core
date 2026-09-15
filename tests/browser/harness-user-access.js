/*
 * Settings → User Access: one row per person, roles as chips (S3, v8.2.0).
 *
 * The screen was a checkbox matrix with one column per vault role — eleven
 * columns wide at a3rev's five roles, and one column wider with every role
 * anyone adds. It is now one row per person: Staff | Management | neither as a
 * pill control, the vault roles as wrapping chips with a + Add role listbox, and
 * a filter above the list. The capability state did NOT move: every capability
 * is still carried by a .def-core-role-cb input, and the payload the save
 * submits is the payload the matrix submitted. That is the claim this file
 * holds — and it holds it by calling the SHIPPED accessPayload(), not by
 * rebuilding the loop, because a rebuilt loop only ever tests its own copy.
 *
 * Four parts, because the screen has four:
 *
 *  1-42. THE BEHAVIOUR, in jsdom, over the SHIPPED block — sliced out of
 *        assets/js/def-core-admin.js by the S3 markers (extract.userAccess), so
 *        these are the shipped lines and not a copy that can drift. Every check
 *        drives the DOM the way a person would, through the shipped
 *        initAccessRows(): nothing here calls the renderer or the filter behind
 *        the screen's back, which is what lets a control that redraws itself
 *        without re-running the filter show up as a failure.
 *
 *        Four groups are worth reading before the rest:
 *
 *        1-8 — the access level is Staff, Management, OR NEITHER. Clicking a
 *        pill writes both inputs, so "both" is unreachable; clicking the LIT
 *        pill again clears it, which is the only way to take a Staff-AI seat
 *        away without also stripping the person's DEF Admin and vault roles
 *        with the × . 5-7 pin that the clearing is surgical.
 *
 *        9-12 and 20-21 — the render must not INVENT a level for a row that
 *        stores none. A DEF Admin who never opens the console is a supported
 *        setup ("DEF-Admin alone is NOT a roster row",
 *        class-def-core-staff-roster.php), so a render that defaults such rows
 *        to Staff hands every one of them a console login the moment anyone
 *        presses Save — for someone else, with nothing on screen to show for it.
 *        20-21 are the same bug reached from the Setup Assistant: it revokes a
 *        level, the server commits it, the drawer mirrors "neither" onto the
 *        inputs, and a render that re-derives a level puts the revoked one
 *        straight back on.
 *
 *        28-30 — the list and its count say "people who hold this role". A chip
 *        edit changes who that is, so a chip that only redraws its own cell
 *        leaves the screen asserting something that stopped being true. Nothing
 *        in those three touches the filter; the shipped listener must react.
 *
 *        34-39 — the listbox. Opened with the mouse nothing is highlighted, or
 *        the first Arrow Down steps past the option it just highlighted. Options
 *        are out of the tab order and Enter reads the option under FOCUS, or Tab
 *        and the arrows disagree about which role Enter takes. And a redraw
 *        under an open menu closes it, or its outside-click listener outlives
 *        the element it closes over.
 *
 * 43-44. THE JOINS the slice cannot contain: initUserRoles' one call into the
 *        block, and the drawer's own file announcing the capabilities it writes.
 *        Read off the source, because neither end is inside the block.
 *
 * 45-53. THE SHIPPED MARKUP, read out of templates/admin-settings.php. The
 *        fixture below is only as honest as the template it mirrors, so the
 *        template is asserted to emit what the fixture assumes: the six columns
 *        and no per-role column, a data-label on every cell, one hidden input
 *        per catalog role, and (49-50) the level read from the STORED
 *        capabilities with no branch that turns "neither" into a level.
 *
 * 54-59. THE RESPONSIVE RULES, read out of assets/css/def-core-admin.css. Below
 *        782px the table presentation is REPLACED by stacked blocks with visible
 *        cell labels. Check 56 is subtle and was a real bug: the stacked layout
 *        sets display:block on every tr, `hidden` is only display:none in the UA
 *        stylesheet, and an author rule beats a UA rule — so the filter worked
 *        on a laptop and hid nobody on a phone. Check 59 is the one that
 *        outlives this PR: this screen must not reach for the sideways-scroll
 *        wrapper S1 gave the Connection Logs table. There is no row left to be
 *        wider than the screen.
 *
 *        ruleIn() below allows either line ending on purpose. Its grouped
 *        selector carries the newlines the stylesheet has, and core.autocrlf
 *        puts a \r before each of them on a Windows checkout — check 54 went red
 *        on the line endings of the file it was reading rather than on the rule
 *        it was checking.
 *
 * Bite checks. The extractor takes USER_ACCESS, naming a file to load instead of
 * the shipped block:
 *
 *   USER_ACCESS=/tmp/old-user-access.js node tests/browser/harness-user-access.js
 *
 * Against the 8.1.3 screen's logic behind the same names — initAccessRows
 * binding the two access checkboxes to each other and nothing else,
 * renderRolesCell and renderAccessRow as no-ops, applyRoleFilter returning the
 * row count without hiding anything, accessPayload unchanged — that fails 36 of
 * the 42 behaviour checks. The six it leaves green are the right six: 26, 31 and
 * 32 are the payload, which S3 deliberately did not change, and 9, 10 and 20 are
 * the neither-level row, which 8.1.3 also got right.
 *
 * Two targeted mutations of the CURRENT block, which are the ones to re-run
 * after touching either area:
 *
 *   - narrow accessPayload's selector to `.def-core-role-cb:not([hidden])` —
 *     which would revoke every access level and every vault role on Save, those
 *     inputs being exactly the hidden ones — and 12 checks go red.
 *   - make the pill click always select (`setAccessLevel(row, pair[0])`) instead
 *     of clearing the lit one, and checks 4-7 go red.
 *
 * Checks 43-59 read the working tree rather than the block, so they bite by
 * editing the scripts, the template and the stylesheet instead.
 *
 * 59 checks.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const BLOCK = extract.userAccess();
const TEMPLATE = fs.readFileSync(path.join(extract.REPO, 'templates/admin-settings.php'), 'utf8');
const ADMIN_JS = fs.readFileSync(path.join(extract.REPO, 'assets/js/def-core-admin.js'), 'utf8');
const ADMIN_CSS = fs.readFileSync(path.join(extract.REPO, 'assets/css/def-core-admin.css'), 'utf8');

let pass = 0, fail = 0;
const results = [];
let n = 0;
function check(label, cond, detail) {
	n++;
	if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

// ── The fixture ─────────────────────────────────────────────────────────────
//
// The tenant's catalog, as the server resolves it: {slug, name} and nothing
// else — there is no description to put under a name (class-def-core-tools.php
// sanitize_roles_catalog drops everything but the two).
const CATALOG = [
	{ slug: 'finance', name: 'Finance' },
	{ slug: 'hr', name: 'HR' },
	{ slug: 'legal', name: 'Legal' },
];

// One row, in the shape templates/admin-settings.php renders it. Checks 45-53
// hold the template to this shape, which is what stops the fixture drifting into
// a DOM the screen never produces.
function rowMarkup(u) {
	const cap = (c, on, hidden) =>
		'<input type="checkbox" class="def-core-role-cb' + (c === 'def_admin_access' ? ' def-core-admin-cb' : '') +
		'" data-user="' + u.id + '" data-cap="' + c + '"' + (on ? ' checked' : '') + (hidden ? ' hidden' : '') + ' />';
	const roles = u.roles || [];
	return '<tr class="def-core-user-row" data-user-id="' + u.id + '" data-roles="' + roles.join(' ') + '">' +
		'<td class="def-core-cell-user" data-label="User"><strong>' + u.name + '</strong></td>' +
		'<td class="def-core-cell-wp" data-label="WordPress role">' + (u.wp || 'Subscriber') + '</td>' +
		'<td class="def-core-cell-access" data-label="Access level">' +
			cap('def_staff_access', u.level === 'staff', true) +
			cap('def_management_access', u.level === 'management', true) +
		'</td>' +
		'<td class="def-core-cell-roles" data-label="Roles">' +
			CATALOG.map(r => cap('def_role_' + r.slug, roles.indexOf(r.slug) !== -1, true)).join('') +
		'</td>' +
		'<td class="def-core-cell-admin" data-label="DEF Admin">' + cap('def_admin_access', !!u.admin, false) + '</td>' +
		'<td class="def-core-cell-actions" data-label="Actions">' +
			'<button type="button" class="def-core-remove-user-btn" data-user-id="' + u.id + '">&times;</button>' +
		'</td></tr>';
}

// A role name out of the DEFHO catalog carrying $& and $' — the characters a
// string-form String.replace() expands. Used by the i18n checks below.
function accessNameProbe(window, api, document) {
	window.defCoreAdmin.rolesCatalog = [{ slug: 'legal', name: "$& Legal $'" }];
	const row = document.querySelector('tr.def-core-user-row');
	const cb = row.querySelector('[data-cap="def_role_legal"]');
	cb.checked = true;
	cb.dispatchEvent(new window.Event('change', { bubbles: true }));
	const x = row.querySelector('.def-core-chip-remove');
	return x && x.getAttribute('aria-label');
}

function boot(users) {
	const dom = new JSDOM('<!doctype html><html><body>' +
		'<div class="def-core-access-toolbar">' +
		'<label for="def-core-role-filter">Show people with role</label>' +
		'<select id="def-core-role-filter" class="def-core-role-filter">' +
		'<option value="">All roles</option>' +
		CATALOG.map(r => '<option value="' + r.slug + '">' + r.name + '</option>').join('') +
		'</select><span id="def-core-access-count" class="def-core-access-count"></span></div>' +
		'<table class="def-core-roles-table"><tbody id="def-core-roles-tbody">' +
		users.map(rowMarkup).join('') +
		'</tbody></table></body></html>');

	const window = dom.window, document = window.document;
	window.defCoreAdmin = { rolesCatalog: CATALOG };

	const api = new window.Function('window', 'document',
		BLOCK + '\nreturn { initAccessRows: initAccessRows, accessPayload: accessPayload };'
	)(window, document);

	const tbody = document.getElementById('def-core-roles-tbody');
	const countEl = document.getElementById('def-core-access-count');
	const filterEl = document.getElementById('def-core-role-filter');
	const rows = () => Array.from(tbody.querySelectorAll('tr.def-core-user-row'));

	// The whole screen, wired by the SHIPPED function — the rows decorated, the
	// row listener attached, the select bound and the count filled. Nothing
	// below reaches past it: a check drives this DOM the way a person would, so
	// a chip that redraws without re-running the filter is visible here.
	api.initAccessRows(tbody, filterEl, countEl);

	const row = id => tbody.querySelector('tr[data-user-id="' + id + '"]');
	// The SHIPPED accessPayload, not a re-implementation of it. A harness that
	// rebuilds the payload loop tests its own copy and nothing else: narrow the
	// real selector to `.def-core-role-cb:not([hidden])` — which revokes every
	// access level and every vault role on Save, those inputs being exactly the
	// hidden ones — and a copied loop stays green straight through it.
	const payload = () => {
		const out = {};
		api.accessPayload(document).forEach(pair => { out[pair[0]] = pair[1]; });
		return out;
	};
	return {
		document, tbody, row, payload,
		// Exactly what the Setup Assistant drawer does: write the inputs the
		// server has already committed, then announce it with one change.
		syncRow: id => row(id).querySelector('.def-core-role-cb')
			.dispatchEvent(new window.Event('change', { bubbles: true })),
		// Through the select, so the shipped binding is what runs.
		filter: slug => {
			filterEl.value = slug;
			filterEl.dispatchEvent(new window.Event('change', { bubbles: true }));
		},
		visible: () => rows().filter(r => !r.hidden).map(r => r.dataset.userId),
		count: () => countEl.textContent,
		chips: id => Array.from(row(id).querySelectorAll('.def-core-chip')).map(c => c.dataset.role),
		chipLabels: id => Array.from(row(id).querySelectorAll('.def-core-chip-label')).map(c => c.textContent),
		choices: id => Array.from(row(id).querySelectorAll('.def-core-role-option')).map(o => o.dataset.role),
		addBtn: id => row(id).querySelector('.def-core-add-role'),
		menu: id => row(id).querySelector('.def-core-role-menu'),
		// One option in one row's menu, or null. Null-safe on purpose: a bite
		// fixture that renders no menu at all must fail CHECKS, not crash the
		// file before the checks that are meant to catch it have run.
		option: (id, slug) => {
			const m = row(id).querySelector('.def-core-role-menu');
			return m && m.querySelector('[data-role="' + slug + '"]');
		},
		focused: () => (document.activeElement && document.activeElement.className) || '(none)',
		// The payload as a plain object, so a check can read one capability.
		key: (el, k) => el && el.dispatchEvent(new window.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true })),
		click: el => el && el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true })),
	};
}

const PEOPLE = [
	{ id: 1, name: 'Ada Admin', wp: 'Administrator', level: 'management', roles: ['finance', 'hr', 'legal'], admin: true },
	{ id: 2, name: 'Sam Staff', wp: 'Editor', level: 'staff', roles: ['finance'], admin: false },
	{ id: 3, name: 'Robin Roleless', wp: 'Subscriber', level: 'staff', roles: [], admin: false },
	// Stores NEITHER level: a DEF Admin who administers the settings page and has
	// no Staff-AI seat. class-def-core-staff-roster.php calls this out in so many
	// words — "DEF-Admin alone is NOT a roster row" — so it is a supported setup
	// and not a gap to be tidied away by the screen that renders it.
	{ id: 4, name: 'Dana DefAdmin', wp: 'Administrator', level: '', roles: [], admin: true },
];

// ── 1-4. Staff ↔ Management: exactly one, always ────────────────────────────
{
	const t = boot(PEOPLE);
	const opts = id => Array.from(t.row(id).querySelectorAll('.def-core-access-opt[aria-checked="true"]'))
		.map(o => o.dataset.level);

	check('the rendered level is the one the inputs carry — exactly one option selected',
		opts(2).join() === 'staff' && opts(1).join() === 'management',
		'user2=' + opts(2).join() + ' user1=' + opts(1).join());

	// The whole reason the two checkboxes are gone: they could BOTH be cleared,
	// and a person with neither capability has no console access while still
	// sitting in the list looking like they do.
	t.click(t.row(3).querySelector('.def-core-access-opt[data-level="management"]'));
	check('choosing Management selects exactly one option and writes both inputs',
		opts(3).join() === 'management' &&
		t.row(3).querySelector('[data-cap="def_management_access"]').checked === true &&
		t.row(3).querySelector('[data-cap="def_staff_access"]').checked === false,
		'selected=' + opts(3).join());

	t.click(t.row(3).querySelector('.def-core-access-opt[data-level="staff"]'));
	check('choosing Staff back again leaves exactly one selected, the other cleared',
		opts(3).join() === 'staff' &&
		t.row(3).querySelector('[data-cap="def_staff_access"]').checked === true &&
		t.row(3).querySelector('[data-cap="def_management_access"]').checked === false,
		'selected=' + opts(3).join());

	// A seat has to be REVOCABLE. Clicking the lit pill clears it, leaving
	// neither — otherwise the only way to take away a person's Staff AI access
	// is the × that strips their DEF Admin and every vault role with it, which
	// is a different decision than the one being made.
	t.click(t.row(3).querySelector('.def-core-access-opt[data-level="staff"]'));
	check('clicking the lit pill again clears it, leaving neither selected',
		opts(3).length === 0 &&
		t.row(3).querySelector('[data-cap="def_staff_access"]').checked === false &&
		t.row(3).querySelector('[data-cap="def_management_access"]').checked === false,
		'selected=' + opts(3).join());

	check('and the cleared level submits both capabilities as 0',
		t.payload()['roles[3][def_staff_access]'] === '0' &&
		t.payload()['roles[3][def_management_access]'] === '0',
		'staff=' + t.payload()['roles[3][def_staff_access]'] +
		' mgmt=' + t.payload()['roles[3][def_management_access]']);

	// Revoking the seat is not revoking everything else.
	{
		const t2 = boot(PEOPLE);
		const before = t2.payload();
		t2.click(t2.row(2).querySelector('.def-core-access-opt[data-level="staff"]'));
		const after = t2.payload();
		check('clearing the level leaves DEF Admin and the vault roles untouched',
			after['roles[2][def_role_finance]'] === before['roles[2][def_role_finance]'] &&
			after['roles[2][def_admin_access]'] === before['roles[2][def_admin_access]'] &&
			t2.chips(2).join() === 'finance' &&
			after['roles[2][def_staff_access]'] === '0',
			'finance=' + after['roles[2][def_role_finance]'] + ' chips=' + t2.chips(2));

		// Management clears the same way — it is the pill, not the level.
		const t3 = boot(PEOPLE);
		t3.click(t3.row(1).querySelector('.def-core-access-opt[data-level="management"]'));
		check('Management clears on a second click too',
			t3.row(1).querySelectorAll('.def-core-access-opt[aria-checked="true"]').length === 0 &&
			t3.payload()['roles[1][def_management_access]'] === '0' &&
			t3.payload()['roles[1][def_staff_access]'] === '0' &&
			t3.payload()['roles[1][def_admin_access]'] === '1',
			'mgmt=' + t3.payload()['roles[1][def_management_access]']);
	}

	// And out of neither, a pill still lands exactly one.
	t.click(t.row(3).querySelector('.def-core-access-opt[data-level="management"]'));
	check('selecting from neither lands exactly one again',
		opts(3).join() === 'management' &&
		t.payload()['roles[3][def_management_access]'] === '1' &&
		t.payload()['roles[3][def_staff_access]'] === '0',
		'selected=' + opts(3).join());

	// THE ONE THE PANEL CAUGHT. A row that stores neither level must render as
	// neither and, far more importantly, must SURVIVE the render: the decorator
	// runs on every row on every page load, so a decorator that defaults to Staff
	// hands a console login to every DEF-Admin-only user the moment anyone presses
	// Save — for someone else entirely, with nothing on screen to show for it.
	check('a person who stores neither level renders as neither, and keeps it',
		opts(4).length === 0 &&
		t.row(4).querySelector('[data-cap="def_staff_access"]').checked === false &&
		t.row(4).querySelector('[data-cap="def_management_access"]').checked === false,
		'selected=' + opts(4).join());

	check('and submits neither — the render did not stage a grant',
		t.payload()['roles[4][def_staff_access]'] === '0' &&
		t.payload()['roles[4][def_management_access]'] === '0' &&
		t.payload()['roles[4][def_admin_access]'] === '1',
		JSON.stringify(t.payload()['roles[4][def_staff_access]']));

	// The group is still reachable: with nothing selected there is no obvious
	// tab stop, so the first option carries it.
	check('a row with no level chosen is still reachable from the keyboard',
		(t.row(4).querySelector('.def-core-access-opt[data-level="staff"]') || {}).tabIndex === 0);

	// And a person can still choose one — "exactly one" is a property of the
	// interaction, which is the half of the rule that protects the reader.
	t.click(t.row(4).querySelector('.def-core-access-opt[data-level="staff"]'));
	check('choosing on such a row lands exactly one, as everywhere else',
		opts(4).join() === 'staff' &&
		t.payload()['roles[4][def_staff_access]'] === '1' &&
		t.payload()['roles[4][def_management_access]'] === '0',
		'selected=' + opts(4).join());

	// One radiogroup per row, all announcing "Access level", names whose it is
	// nowhere — and this is a list of people.
	const groups = Array.from(t.tbody.querySelectorAll('.def-core-access-level'))
		.map(g => g.getAttribute('aria-label'));
	check('a row’s access control announces the person it belongs to',
		groups.length === PEOPLE.length && new Set(groups).size === PEOPLE.length &&
		groups.indexOf('Access level for Sam Staff') !== -1,
		'labels=' + groups.join(' / '));
}

// ── 5-8. Chips: added, removed, and the choices that follow ─────────────────
{
	const t = boot(PEOPLE);

	check('a person with no vault roles renders no chips and every role on offer',
		t.chips(3).length === 0 && t.choices(3).join() === 'finance,hr,legal',
		'chips=' + t.chips(3) + ' choices=' + t.choices(3));

	check('a chip carries the role NAME from the catalog, not its slug',
		t.chipLabels(2).join() === 'Finance', 'got: ' + t.chipLabels(2).join());

	// Adding: the chip appears AND the role leaves the choices. Both halves, or
	// a role could be added twice.
	t.click(t.addBtn(3));
	t.click(t.option(3, 'hr'));
	check('adding a role creates its chip and removes it from the + Add role choices',
		t.chips(3).join() === 'hr' && t.choices(3).join() === 'finance,legal' &&
		t.row(3).querySelector('[data-cap="def_role_hr"]').checked === true,
		'chips=' + t.chips(3) + ' choices=' + t.choices(3));

	// Removing: the reverse, including the capability input.
	t.click(t.row(3).querySelector('.def-core-chip[data-role="hr"] .def-core-chip-remove'));
	check('removing a chip returns the role to the choices and clears its capability',
		t.chips(3).length === 0 && t.choices(3).join() === 'finance,hr,legal' &&
		t.row(3).querySelector('[data-cap="def_role_hr"]').checked === false,
		'chips=' + t.chips(3) + ' choices=' + t.choices(3));
}

// ── 9-10. Every role is on ──────────────────────────────────────────────────
{
	const t = boot(PEOPLE);
	check('a person holding every role is told so instead of being offered an empty menu',
		t.addBtn(1) === null &&
		(t.row(1).querySelector('.def-core-roles-all-on') || {}).textContent === 'Every role is on',
		'addBtn=' + t.addBtn(1));

	t.click(t.row(1).querySelector('.def-core-chip[data-role="hr"] .def-core-chip-remove'));
	check('removing one of them brings + Add role back, offering exactly that role',
		t.addBtn(1) !== null && t.choices(1).join() === 'hr', 'choices=' + t.choices(1));
}

// ── The Setup Assistant, revoking ───────────────────────────────────────────
//
// THE SECOND ONE THE PANEL CAUGHT, and a regression this PR would have
// introduced rather than inherited. handleUpdateUserRow mirrors state the server
// has ALREADY committed. When the assistant revokes a Staff-only user's console
// access, the committed state is "neither", the drawer writes both inputs false,
// and the change it now dispatches reaches the tbody listener. A listener that
// re-derives a level from "neither" ticks Staff straight back on, and the next
// Save re-grants exactly what the assistant just took away — while the row
// flashes green as though it agreed with the server.
{
	const t = boot(PEOPLE);

	// The drawer's write: the server committed "neither".
	t.row(2).querySelector('[data-cap="def_staff_access"]').checked = false;
	t.row(2).querySelector('[data-cap="def_management_access"]').checked = false;
	t.syncRow(2);

	check('a revoke mirrored from the server survives the redraw it triggers',
		t.payload()['roles[2][def_staff_access]'] === '0' &&
		t.payload()['roles[2][def_management_access]'] === '0',
		'staff=' + t.payload()['roles[2][def_staff_access]'] +
		' mgmt=' + t.payload()['roles[2][def_management_access]']);

	// The same path must still FOLLOW a real grant — it is a sync, not a freeze.
	t.row(2).querySelector('[data-cap="def_management_access"]').checked = true;
	t.syncRow(2);
	const selected = Array.from(t.row(2).querySelectorAll('.def-core-access-opt[aria-checked="true"]'))
		.map(o => o.dataset.level);
	check('and a grant mirrored from the server is picked up and shown',
		selected.join() === 'management' &&
		t.payload()['roles[2][def_management_access]'] === '1' &&
		t.payload()['roles[2][def_staff_access]'] === '0',
		'selected=' + selected.join());
}

// ── 11-14. The filter, and the count that follows it ────────────────────────
{
	const t = boot(PEOPLE);

	check('with no filter every person is shown, and the count says how many',
		t.visible().join() === '1,2,3,4' && t.count() === '4 people',
		'visible=' + t.visible() + ' count=' + t.count());

	t.filter('finance');
	check('filtering by a role hides the people who do not hold it',
		t.visible().join() === '1,2', 'visible=' + t.visible());

	t.filter('legal');
	check('the count follows the filter, and reads as one person when it is one',
		t.visible().join() === '1' && t.count() === '1 person',
		'visible=' + t.visible() + ' count=' + t.count());

	t.filter('');
	check('All roles restores everyone',
		t.visible().join() === '1,2,3,4' && t.count() === '4 people',
		'visible=' + t.visible() + ' count=' + t.count());
}

// ── 15-16. The filter is a view, not a permission ───────────────────────────
{
	const t = boot(PEOPLE);
	t.filter('legal');
	const p = t.payload();
	check('a filtered-out person still submits their capabilities — hiding is not revoking',
		p['roles[2][def_staff_access]'] === '1' && p['roles[2][def_role_finance]'] === '1',
		JSON.stringify({ staff: p['roles[2][def_staff_access]'], finance: p['roles[2][def_role_finance]'] }));

	// And the filter reads the chips rather than a copy of them: add a role to a
	// hidden person and they appear under that role's filter.
	t.filter('');
	t.click(t.addBtn(3));
	t.click(t.option(3, 'legal'));
	t.filter('legal');
	check('a role added by chip is immediately filterable — the filter reads the chips',
		t.visible().join() === '1,3', 'visible=' + t.visible());
}

// ── A chip edit re-runs the filter, with nobody touching the filter ──────────
//
// The list and its count describe "people who hold this role". Editing a chip
// changes who that is, so a chip edit that only redraws its own cell leaves the
// screen asserting something that stopped being true — and this is the ONLY way
// a reader sees it, because they are not going to re-pick the filter to check.
// Nothing below touches the select; the shipped listener is what must react.
{
	const t = boot(PEOPLE);
	t.filter('finance');
	check('with Finance on, it is the Finance holders and the count agrees',
		t.visible().join() === '1,2' && t.count() === '2 people',
		'visible=' + t.visible() + ' count=' + t.count());

	// Take Finance off someone ON the filtered list.
	t.click(t.row(2).querySelector('.def-core-chip[data-role="finance"] .def-core-chip-remove'));
	check('removing the filtered role drops that person from the list and the count',
		t.visible().join() === '1' && t.count() === '1 person',
		'visible=' + t.visible() + ' count=' + t.count());

	// And the other direction: give it to somebody currently hidden.
	t.click(t.addBtn(3));
	t.click(t.option(3, 'finance'));
	check('granting the filtered role brings that person onto the list and the count',
		t.visible().join() === '1,3' && t.count() === '2 people',
		'visible=' + t.visible() + ' count=' + t.count());
}

// ── 17-19. The save payload ─────────────────────────────────────────────────
{
	const t = boot(PEOPLE);
	const cap = (id, c) => t.payload()['roles[' + id + '][' + c + ']'];

	check('the payload carries every writable capability for every person, 1 or 0',
		Object.keys(t.payload()).length === PEOPLE.length * (3 + CATALOG.length) &&
		cap(2, 'def_staff_access') === '1' && cap(2, 'def_management_access') === '0' &&
		cap(2, 'def_role_finance') === '1' && cap(2, 'def_role_hr') === '0' &&
		cap(2, 'def_role_legal') === '0' && cap(2, 'def_admin_access') === '0',
		'keys=' + Object.keys(t.payload()).length);

	// The DEF Admin toggle is an ordinary capability input and reaches the
	// payload as one — it is not folded into the access level.
	const adminCb = t.row(2).querySelector('.def-core-admin-cb');
	adminCb.checked = true;
	check('the DEF Admin toggle contributes to the payload on its own axis',
		cap(2, 'def_admin_access') === '1' && cap(2, 'def_staff_access') === '1',
		'admin=' + cap(2, 'def_admin_access') + ' staff=' + cap(2, 'def_staff_access'));

	// Chips and the level are the same payload the matrix submitted: a role
	// added and the level switched both land as '1'/'0' under the same keys.
	t.click(t.row(2).querySelector('.def-core-access-opt[data-level="management"]'));
	t.click(t.addBtn(2));
	t.click(t.option(2, 'legal'));
	check('a chip added and a level switched reach the payload under the old keys',
		cap(2, 'def_role_legal') === '1' && cap(2, 'def_management_access') === '1' &&
		cap(2, 'def_staff_access') === '0' &&
		// And nothing the catalog does not name can be in it: the inputs are the
		// only source, and the server builds its writable set from the catalog too.
		Object.keys(t.payload()).every(k => /def_(staff|management|admin)_access|def_role_(finance|hr|legal)/.test(k)),
		'legal=' + cap(2, 'def_role_legal') + ' mgmt=' + cap(2, 'def_management_access'));
}

// ── The listbox from the keyboard ───────────────────────────────────────────
{
	const t = boot(PEOPLE);
	const active = id => Array.from(t.row(id).querySelectorAll('.def-core-role-option.is-active'))
		.map(o => o.dataset.role).join();

	t.click(t.addBtn(3));
	// Opened with the mouse, nothing is highlighted yet and focus is still on
	// the button. Highlighting the first option here would make the first Arrow
	// Down step PAST it — the reader presses Down once and lands on the second.
	check('opening + Add role with the mouse highlights nothing yet',
		!!t.menu(3) && t.menu(3).hidden === false &&
		(t.addBtn(3) && t.addBtn(3).getAttribute('aria-expanded')) === 'true' &&
		active(3) === '',
		'active=' + active(3) + ' hidden=' + (t.menu(3) && t.menu(3).hidden));

	t.key(t.addBtn(3), 'ArrowDown');
	const after1 = active(3);
	t.key(t.addBtn(3), 'ArrowUp');
	t.key(t.addBtn(3), 'ArrowUp');
	check('the first Arrow Down takes the first role, and Arrow Up wraps from it',
		after1 === 'finance' && active(3) === 'hr',
		'down=' + after1 + ' then up,up=' + active(3));

	t.key(t.addBtn(3), 'Escape');
	check('Escape closes the menu and puts focus back on + Add role',
		!!t.menu(3) && t.menu(3).hidden === true &&
		(t.addBtn(3) && t.addBtn(3).getAttribute('aria-expanded')) === 'false' &&
		!!t.addBtn(3) && t.document.activeElement === t.addBtn(3),
		'hidden=' + (t.menu(3) && t.menu(3).hidden) + ' focus=' + t.focused());

	// Enter takes the role. The cell is redrawn under it, so "focus returns to
	// + Add role" means the button of the REDRAWN row.
	t.click(t.addBtn(3));
	t.key(t.addBtn(3), 'ArrowDown');
	t.key(t.addBtn(3), 'ArrowDown');
	t.key(t.addBtn(3), 'Enter');
	check('Enter adds the highlighted role and leaves focus on the + Add role that replaces it',
		t.chips(3).join() === 'hr' && t.choices(3).join() === 'finance,legal' &&
		!!t.addBtn(3) && t.document.activeElement === t.addBtn(3) &&
		!!t.menu(3) && t.menu(3).hidden === true,
		'chips=' + t.chips(3) + ' focus=' + t.focused());

	// An option cannot be reached by Tab at all — that is what stops focus and
	// the highlight disagreeing. And when focus IS on an option (the arrows put
	// it there), Enter takes the one under focus rather than a remembered index.
	check('options are out of the tab order, and Enter reads the option under focus',
		t.choices(3).length > 0 &&
		Array.from(t.row(3).querySelectorAll('.def-core-role-option')).every(o => o.tabIndex === -1),
		'tabIndexes=' + Array.from(t.row(3).querySelectorAll('.def-core-role-option')).map(o => o.tabIndex).join());

	// A redraw while the menu is open detaches the wrap, and its outside-click
	// listener is on the DOCUMENT — it would outlive the element it closes over
	// and leave aria-expanded="true" stranded on a button nobody can see.
	const t2 = boot(PEOPLE);
	t2.click(t2.addBtn(3));
	const staleBtn = t2.addBtn(3);
	t2.syncRow(3);                       // what the Setup Assistant drawer does
	check('a redraw under an open menu closes it rather than stranding its listener',
		!!staleBtn && staleBtn.getAttribute('aria-expanded') === 'false' &&
		!!t2.menu(3) && t2.menu(3).hidden === true,
		'stale aria-expanded=' + (staleBtn && staleBtn.getAttribute('aria-expanded')));
}

// ── The strings ─────────────────────────────────────────────────────────────
//
// The checkbox grid put "Staff", "Management" and every column heading in the
// markup, where esc_html_e() reached them. The chips and the two-way control are
// built in JS, so those same words would have shipped as English into a
// translated page. The server hands them over instead — and the fallback is the
// English, so a key that goes missing degrades to what the grid used to say.
{
	const dom = new JSDOM('<!doctype html><html><body>' +
		'<span id="def-core-access-count"></span>' +
		'<table><tbody id="def-core-roles-tbody">' + rowMarkup(PEOPLE[2]) + '</tbody></table>' +
		'</body></html>');
	const window = dom.window, document = window.document;
	window.defCoreAdmin = {
		rolesCatalog: CATALOG,
		userAccessI18n: {
			staff: 'Personal', management: 'Direction',
			accessFor: 'Niveau pour %s', addRole: '+ Ajouter un rôle',
			allRolesOn: 'Tous les rôles sont actifs', removeRole: 'Retirer le rôle %s',
			onePerson: '1 personne', manyPeople: '%d personnes',
		},
	};
	const api = new window.Function('window', 'document',
		BLOCK + '\nreturn { initAccessRows: initAccessRows };')(window, document);
	const tbody = document.getElementById('def-core-roles-tbody');
	api.initAccessRows(tbody, null, document.getElementById('def-core-access-count'));
	const row = tbody.querySelector('tr');

	check('the words the script writes come from the server, not from the script',
		Array.from(row.querySelectorAll('.def-core-access-opt')).map(o => o.textContent).join() === 'Personal,Direction' &&
		row.querySelector('.def-core-add-role').textContent === '+ Ajouter un rôle' &&
		row.querySelector('.def-core-access-level').getAttribute('aria-label') === 'Niveau pour Robin Roleless' &&
		document.getElementById('def-core-access-count').textContent === '1 personne',
		'opts=' + Array.from(row.querySelectorAll('.def-core-access-opt')).map(o => o.textContent).join() +
		' count=' + document.getElementById('def-core-access-count').textContent);

	// %s is filled by a function replacement, so a name carrying $& or $' goes
	// in as itself rather than being expanded by the replacer.
	const odd = accessNameProbe(window, api, document);
	check("a name carrying $& or $' is substituted as itself, not expanded",
		odd === "Retirer le rôle $& Legal $'", 'got: ' + odd);

	// And the server sends every key the block asks for — a typo on either side
	// is a silent fall back to English on a translated page.
	const ADMIN_PHP = fs.readFileSync(path.join(extract.REPO, 'includes/class-def-core-admin.php'), 'utf8');
	const asked = Array.from(BLOCK.matchAll(/accessText\('([a-zA-Z]+)'/g)).map(m => m[1]);
	const sent = Array.from(ADMIN_PHP.matchAll(/'([a-zA-Z]+)'\s*=> __\( '/g)).map(m => m[1]);
	const missing = Array.from(new Set(asked)).filter(k => sent.indexOf(k) === -1);
	check('every string the block asks for is one the server actually sends',
		asked.length > 0 && missing.length === 0, 'missing: ' + missing.join(', '));
}

// ── The wiring, where it cannot be run ──────────────────────────────────────
//
// Everything above drives the screen through initAccessRows(), which IS the
// wiring and is now part of the sliced block — so the select, the row listener
// and the load-time count are all exercised rather than asserted about. These
// two cover the joins that reach code the slice cannot contain.

// initUserRoles' one call. Without it the block is correct and the page is dead.
check('the settings page actually wires the screen up',
	/var refreshFilter = initAccessRows\(tbody, filterEl, countEl\);/.test(ADMIN_JS) &&
	/getElementById\('def-core-role-filter'\)/.test(ADMIN_JS) &&
	/getElementById\('def-core-access-count'\)/.test(ADMIN_JS));

// The other side of the Setup Assistant contract, in the drawer's own file:
// writing .checked fires nothing, so the drawer has to say so. Checks 16-17
// prove the screen answers; this proves something asks.
check('the Setup Assistant drawer announces the capabilities it writes',
	/dispatchEvent\(new Event\('change', \{ bubbles: true \}\)\)/.test(
		fs.readFileSync(path.join(extract.REPO, 'assets/js/setup-assistant-drawer.js'), 'utf8')));

// ── 26-33. The shipped markup ───────────────────────────────────────────────

// Steve's wording, verbatim. Not paraphrasable: it is the only place the screen
// explains what the three grants actually open.
check('the screen carries the approved description, word for word',
	TEMPLATE.includes('Staff and Management both open the Staff AI console, at different document authority: ' +
		'a Management user can read documents a Staff user cannot. Roles give a person the vaults and tools ' +
		'that role holds. DEF Admin opens this settings page.'));

const headRow = extract.element(TEMPLATE, 'thead', l => l.includes('<thead>'), 'roles thead');
const headings = (headRow.match(/esc_html_e\(\s*'([^']+)'/g) || []).map(m => m.replace(/.*'([^']+)'.*/, '$1'));
check('the table has exactly the six columns, in order',
	headings.join(' · ') === 'User · WordPress role · Access level · Roles · DEF Admin · Actions',
	'got: ' + headings.join(' · '));

// The disease this replaces: a <th> emitted per catalog role.
check('no column is emitted per vault role any more',
	!/foreach\s*\(\s*\$roles_catalog[\s\S]{0,200}?<th/.test(headRow) &&
	!/<th[^>]*>\s*<\?php echo esc_html\( \$def_role\['name'\] \)/.test(TEMPLATE));

const bodyRow = extract.element(TEMPLATE, 'tr', l => l.includes('class="def-core-user-row"'), 'roles tr');
const labels = (bodyRow.match(/data-label="<\?php esc_attr_e\(\s*'([^']+)'/g) || [])
	.map(m => m.replace(/.*'([^']+)'.*/, '$1'));
check('every cell carries the label the phone layout reveals',
	labels.join(' · ') === 'User · WordPress role · Access level · Roles · DEF Admin · Actions',
	'got: ' + labels.join(' · '));

// map_def_capabilities() answers TRUE to has_cap('def_staff_access') for every
// Management and DEF-Admin user, so asking it what the STORED level is gives the
// wrong answer for exactly the people it matters most for. def_management_access
// carries no such filter. Getting this wrong renders a Management user as Staff.
check('the level is read from the STORED capabilities, never through has_cap()',
	// allcaps is the raw merged array; map_meta_cap — where map_def_capabilities
	// lives — never touches it. has_cap('def_staff_access') does go through that
	// filter and answers TRUE for every Management and DEF-Admin user, so asking
	// it here would render a tick that is not a stored fact.
	/\$level\s*=\s*'';/.test(TEMPLATE) &&
	/if \( ! empty\( \$u->allcaps\['def_management_access'\] \) \) \{\s*\$level = 'management';/.test(TEMPLATE) &&
	/\} elseif \( ! empty\( \$u->allcaps\['def_staff_access'\] \) \) \{\s*\$level = 'staff';/.test(TEMPLATE) &&
	!/has_cap\( 'def_staff_access' \)/.test(TEMPLATE));

// The third state is the whole of the fix: there must be no branch that turns
// "stores neither" into a level. A `: 'staff'` fallback anywhere in this
// derivation is the bug the panel caught, re-introduced.
check('a row that stores neither level is given neither, server-side',
	!/\$level\s*=\s*[^;]*\?\s*'management'\s*:\s*'staff'/.test(TEMPLATE) &&
	/data-cap="def_staff_access" <\?php checked\( 'staff' === \$level \); \?> hidden/.test(TEMPLATE));

check('the level is exactly one capability, server-side, before any script runs',
	/data-cap="def_staff_access" <\?php checked\( 'staff' === \$level \); \?> hidden/.test(bodyRow) &&
	/data-cap="def_management_access" <\?php checked\( 'management' === \$level \); \?> hidden/.test(bodyRow));

check('one hidden capability input per catalog role, still a .def-core-role-cb',
	/foreach \( \$roles_catalog as \$def_role \) :[\s\S]{0,400}?class="def-core-role-cb"[\s\S]{0,300}?'def_role_' \. \$def_role\['slug'\][\s\S]{0,200}?hidden/.test(bodyRow));

check('the filter offers All roles and then the catalog, and a count sits beside it',
	/<select id="def-core-role-filter"/.test(TEMPLATE) &&
	/esc_html_e\( 'All roles'/.test(TEMPLATE) &&
	/foreach \( \$roles_catalog as \$def_role \) :[\s\S]{0,200}?<option value="<\?php echo esc_attr\( \$def_role\['slug'\] \); \?>"/.test(TEMPLATE) &&
	/id="def-core-access-count"[^>]*aria-live="polite"/.test(TEMPLATE));

// ── 34-37. The phone, and the scroll that is NOT here ───────────────────────
function mediaBlock(css, re) {
	const start = css.search(re);
	if (start < 0) throw new Error('media query not found: ' + re);
	let depth = 0;
	for (let j = css.indexOf('{', start); j < css.length; j++) {
		if (css[j] === '{') depth++;
		else if (css[j] === '}' && --depth === 0) return css.slice(start, j);
	}
	throw new Error('unterminated media query: ' + re);
}
const PHONE = mediaBlock(ADMIN_CSS, /@media screen and \(max-width: 782px\)/);
const ruleIn = (css, selector) => {
	// A grouped selector is written below with the newlines the stylesheet has.
	// git's core.autocrlf puts a \r before every one of them on a Windows
	// checkout, so a literal \n here matches nothing, and the check then goes red
	// on the line endings of the file it is reading rather than on the rule it is
	// meant to be checking. Escape the metacharacters, then let each newline
	// match either ending.
	const pattern = selector
		.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
		.replace(/\n/g, '\\r?\\n');
	const m = new RegExp('(?:^|[}\\r\\n])\\s*' + pattern + '\\s*\\{([^}]*)\\}').exec(css);
	return m ? m[1] : null;
};

const stacked = ruleIn(PHONE, '.def-core-roles-table,\n\t.def-core-roles-table tbody,\n\t.def-core-roles-table tr,\n\t.def-core-roles-table td');
check('below 782px the table, its rows and its cells all become blocks',
	!!stacked && /display:\s*block/.test(stacked) && /(?:^|;)\s*width:\s*auto/.test(stacked),
	'got: ' + String(stacked).replace(/\s+/g, ' ').trim());

check('the column headings go, since each cell now carries its own',
	/display:\s*none/.test(ruleIn(PHONE, '.def-core-roles-table thead') || ''));

// The stacked layout above sets display:block on every tr. `hidden` is only
// display:none in the UA stylesheet, and an author rule beats a UA rule — so
// without this the filter would hide nobody on a phone while working perfectly
// on a laptop. The rule has to come AFTER the block rule and out-specify it.
{
	const blockAt = PHONE.indexOf('.def-core-roles-table,');
	const hiddenAt = PHONE.indexOf('.def-core-roles-table tr[hidden]');
	check('a filtered-out row is still hidden once the rows become blocks',
		hiddenAt > blockAt && blockAt !== -1 &&
		/display:\s*none/.test(ruleIn(PHONE, '.def-core-roles-table tr[hidden]') || ''),
		'blockAt=' + blockAt + ' hiddenAt=' + hiddenAt);
}

// The last DEF Admin has no × to show. A labelled cell with nothing under it
// reads as something missing, so the cell goes instead of standing empty.
check('a cell with nothing in it is not given a heading of its own on a phone',
	/\$is_locked \? ' is-empty' : ''/.test(TEMPLATE) &&
	/display:\s*none/.test(ruleIn(PHONE, '.def-core-roles-table td.is-empty') || ''));

const before = ruleIn(PHONE, '.def-core-roles-table td::before');
check('every cell shows its label, taken from the markup rather than repeated in the CSS',
	!!before && /content:\s*attr\(data-label\)/.test(before) && /display:\s*block/.test(before),
	'got: ' + String(before).replace(/\s+/g, ' ').trim());

// The rule that makes this a layout and not a workaround. S1 gave the Connection
// Logs table a min-width and a .def-core-table-scroll wrapper, which is right for
// a log line and wrong for this: a person's row has nothing in it that must stay
// wide. If this screen ever acquires either, it has stopped being responsive.
const rolesTableRules = (extract.cssRules(ADMIN_CSS).byClass.get('def-core-roles-table') || []);
const floored = Array.from(rolesTableRules)
	.map(i => extract.cssRules(ADMIN_CSS).rules[i])
	.filter(r => /(?:^|;)\s*min-width:/.test(r.body));
check('User Access uses NO sideways-scroll workaround: no floor on the table, no scroller around it',
	floored.length === 0 &&
	!/<div class="def-core-table-scroll"[\s\S]{0,400}?<table class="def-core-roles-table"/.test(TEMPLATE) &&
	!/overflow-x/.test(PHONE.slice(PHONE.indexOf('.def-core-roles-table'))),
	floored.length ? 'floored: ' + floored.map(r => r.selector).join(', ') : 'a scroller wraps the table');

console.log('\nSettings → User Access — one row per person, roles as chips (S3)\n');
results.forEach(r => console.log(r));
console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===\n');
process.exit(fail ? 1 : 0);
