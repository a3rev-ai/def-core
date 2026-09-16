/*
 * The Content Drafts page is Carol's (v8.2.6).
 *
 * The Content Agent is now Carol - Creator, a platform-managed employee on the
 * tenant's roster, and the tenant can rename her. So the page cannot say "the
 * Content Agent" and it cannot hard-code "Carol" either: DEF puts the stored
 * name on BOTH content list responses, the BFF forwards it, PHP renders the
 * platform default (the page draws before any data lands), and the first
 * response to arrive repaints the title and the three tab descriptions.
 *
 * Which is why the sentences carry the name as a PLACEHOLDER. A sentence built
 * by concatenation ("the " + name + " has drafted") reads correctly for exactly
 * one name; these are whole strings through __(), so a translator gets a whole
 * sentence and any name drops into it.
 *
 * Three parts:
 *
 *  1-13. THE BEHAVIOUR, in jsdom, over the SHIPPED block — sliced out of
 *        assets/js/def-core-draft-cards.js by its markers (extract.creator), so
 *        these run the shipped lines and not a copy. The strings the fixture
 *        starts from and the templates handed to the block are read out of the
 *        PHP page, so the harness cannot pass against copy the page never
 *        renders. Checks 8-11 are the ones that matter most: the name is
 *        backend data on an admin screen, so it goes in as TEXT — a name
 *        carrying markup stays a name — and anything unusable leaves the
 *        default standing rather than titling the page "undefined - Creator".
 *
 * 14-19. THE JOINS the slice cannot contain, read off the working tree: each
 *        bundle naming her from its OWN list response, the Clusters bundle's
 *        dependency on the block, and the five sentences in the two bundles
 *        that used to say "the Content Agent".
 *
 * 20-23. THE PAGE, read out of includes/class-def-core-content-drafts-page.php:
 *        the default rendered under the ids the block repaints, the same
 *        strings localized for it, all four reaching a translator, and nothing
 *        user-visible still naming the Content Agent.
 *
 * 24-25. THE FLASH, gone (v8.2.8). PHP renders the name DEF last sent rather
 *        than always the default, so the block must repaint only when the name
 *        has actually changed — a MutationObserver, because a write is a write
 *        even when the text written is identical. What a tenant who renamed her
 *        used to see on EVERY visit now happens once, on the visit after the
 *        rename. (That PHP remembers and re-validates the name is PHP's half:
 *        tests/test-creator-name-remembered.php.)
 *
 * Bite checks. The extractor takes CREATOR, naming a file to load instead of
 * the shipped block:
 *
 *   CREATOR=/tmp/old-creator.js node tests/browser/harness-carol-drafts.js
 *
 * The 8.2.6 block IS an old block — it repainted unconditionally — and putting
 * it back turns check 24 red on its own. The other three bites are targeted
 * mutations of the CURRENT one, and they are the ones to re-run after touching
 * it:
 *
 *   - title.textContent = …  ->  title.innerHTML = …   : check 8 goes red (a
 *     name carrying markup would be parsed as markup).
 *   - delete the `typeof name !== 'string' || !name.trim()` guard: checks 10
 *     and 11 go red (an absent or empty name would wipe the default).
 *   - replace(/%(?:\d+\$)?s/g, creatorName) — the STRING form: check 9 goes red
 *     (a name holding $& would be expanded rather than inserted).
 *
 * 25 checks.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const BLOCK = extract.creator();
const read = f => fs.readFileSync(path.join(extract.REPO, f), 'utf8');
const PAGE = read('includes/class-def-core-content-drafts-page.php');
const DRAFTS_JS = read('assets/js/def-core-draft-cards.js');
const CLUSTERS_JS = read('assets/js/def-core-cluster-targets.js');
const BFF = read('includes/class-def-core-staff-ai.php');

let pass = 0, fail = 0, n = 0;
const results = [];
function check(label, cond, detail) {
	n++;
	if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

// ── The page's own strings ──────────────────────────────────────────────────
//
// Read out of the PHP rather than written here: the fixture is only as honest
// as the page it mirrors, and checks 20-21 hold the page to this shape.
const phpString = re => { const m = re.exec(PAGE); return m ? m[1] : null; };
const TITLE_TPL = phpString(/\$creator_title = __\( '([^']*)'/);
const COPY_TPL = {
	optimize: phpString(/'optimize' => __\( '([^']*)'/),
	clusters: phpString(/'clusters' => __\( '([^']*)'/),
	create: phpString(/'create'   => __\( '([^']*)'/),
};
const DEFAULT_NAME = phpString(/CREATOR_DEFAULT_NAME = '([^']*)'/) ||
	(/CREATOR_DEFAULT_NAME = '([^']*)'/.exec(BFF) || [])[1];

// The oracle: what sprintf() does to one of these templates, written out here
// so the block is checked against something other than itself.
const filled = (tpl, name) => String(tpl).replace(/%(?:\d+\$)?s/g, () => name);

// PHP renders whichever name it last saw and localizes that same name (8.2.8),
// so a boot takes one: the default on a site DEF has never answered for, her
// own name on every visit after that.
const fixture = name => '<div class="wrap def-core-wrap">' +
	'<h1><span id="def-creator-title">' + filled(TITLE_TPL, name) + '</span>' +
	'<button type="button" id="def-setup-assistant-trigger"></button></h1>' +
	Object.keys(COPY_TPL).map(k =>
		'<p class="description" id="def-creator-copy-' + k + '">' + filled(COPY_TPL[k], name) + '</p>'
	).join('') + '</div>';

function boot(rendered) {
	const name = rendered || DEFAULT_NAME;
	const dom = new JSDOM('<!doctype html><html><body>' + fixture(name) + '</body></html>');
	const window = dom.window, document = window.document;
	const cfg = { creator: { name: name, title: TITLE_TPL, copy: COPY_TPL } };
	const api = new window.Function('window', 'document', 'cfg',
		BLOCK + '\nreturn { setCreatorName: setCreatorName, withName: withName };'
	)(window, document, cfg);
	return {
		window: window,
		api: api,
		title: () => document.getElementById('def-creator-title'),
		copy: k => document.getElementById('def-creator-copy-' + k),
	};
}

// ── 1-3. The page the tenant has not renamed ────────────────────────────────
const cold = boot();
check('the page draws with the platform default, and the block leaves it there',
	DEFAULT_NAME === 'Carol' && cold.title().textContent === 'Carol - Creator',
	'default ' + JSON.stringify(DEFAULT_NAME) + ', title ' + JSON.stringify(cold.title().textContent));

check('all four strings come out of the page, not out of this file',
	!!TITLE_TPL && Object.keys(COPY_TPL).every(k => !!COPY_TPL[k] && COPY_TPL[k].indexOf('%') !== -1),
	JSON.stringify(COPY_TPL));

check('the default name is the one the BFF falls back to',
	/const CREATOR_DEFAULT_NAME = 'Carol';/.test(BFF) &&
	/DEF_Core_Staff_AI::CREATOR_DEFAULT_NAME/.test(PAGE),
	'page and BFF must share one default');

// ── 4-7. The first list response names her ──────────────────────────────────
const named = boot();
named.api.setCreatorName('Rowena');

check('the title takes the tenant\'s name for her',
	named.title().textContent === 'Rowena - Creator',
	JSON.stringify(named.title().textContent));

check('the Optimize tab says what she has drafted',
	named.copy('optimize').textContent === filled(COPY_TPL.optimize, 'Rowena'),
	JSON.stringify(named.copy('optimize').textContent));

check('the Clusters tab names her in BOTH places (%1$s twice in one sentence)',
	named.copy('clusters').textContent === filled(COPY_TPL.clusters, 'Rowena') &&
	named.copy('clusters').textContent.split('Rowena').length === 3,
	JSON.stringify(named.copy('clusters').textContent));

check('the Create tab asks her for the post',
	named.copy('create').textContent === filled(COPY_TPL.create, 'Rowena'),
	JSON.stringify(named.copy('create').textContent));

// ── 8-9. The name is data, not markup ───────────────────────────────────────
const markup = boot();
markup.api.setCreatorName('<b>Bo</b><img src=x onerror=alert(1)>');
check('a name carrying markup lands as text — the title holds no elements',
	markup.title().children.length === 0 &&
	markup.title().textContent === '<b>Bo</b><img src=x onerror=alert(1)> - Creator',
	JSON.stringify(markup.title().innerHTML));

const dollar = boot();
dollar.api.setCreatorName("$& Bo $' $1");
check('a name holding $& or $\' goes in whole (the replacement is a function)',
	dollar.title().textContent === "$& Bo $' $1 - Creator",
	JSON.stringify(dollar.title().textContent));

// ── 10-11. Nothing usable arrived ───────────────────────────────────────────
const DEFAULT_TITLE = filled(TITLE_TPL, DEFAULT_NAME);

const absent = boot();
absent.api.setCreatorName(undefined);            // an older DEF, or a failed list
check('an absent creator_name leaves the default standing',
	absent.title().textContent === DEFAULT_TITLE &&
	absent.copy('optimize').textContent === filled(COPY_TPL.optimize, DEFAULT_NAME),
	JSON.stringify(absent.title().textContent));

const malformed = ['', '   ', '\t\n ', 7, null, {}, [], true];
const survived = malformed.filter(v => {
	const b = boot();
	b.api.setCreatorName(v);
	return b.title().textContent === DEFAULT_TITLE;
});
check('a malformed creator_name leaves it standing too — empty, blank, or not a string',
	survived.length === malformed.length,
	'renamed the page on: ' + JSON.stringify(malformed.filter(v => survived.indexOf(v) === -1)));

// ── 12-13. What the Clusters bundle calls, and what the h1 keeps ────────────
const shared = boot();
check('window.DefCreator exposes the two the Clusters bundle calls',
	!!shared.window.DefCreator &&
	typeof shared.window.DefCreator.setName === 'function' &&
	typeof shared.window.DefCreator.withName === 'function');

shared.window.DefCreator.setName('Rowena');
check('withName follows the rename, so a sentence rendered later still names her',
	shared.window.DefCreator.withName('Ask %s for a post') === 'Ask Rowena for a post' &&
	shared.window.document.querySelector('#def-setup-assistant-trigger') !== null,
	'the Setup Assistant trigger must survive the swap — the title is a span INSIDE the h1');

// ── 14-19. The joins, and the five sentences ────────────────────────────────
check('the drafts bundle names her off its own list response, before it renders',
	/api\('\/drafts', 'GET'\)\.then\(function \(res\) \{\s*setCreatorName\(res && res\.creator_name\);\s*render\(/.test(DRAFTS_JS));

check('the Clusters bundle names her off its own list response',
	/function loadTargets\(\) \{\s*return api\('\/targets'\)\.then\(function \(res\) \{\s*creator\.setName\(res && res\.creator_name\);/.test(CLUSTERS_JS));

check('the Clusters bundle stands down without the block that owns the name',
	/var creator = window\.DefCreator;/.test(CLUSTERS_JS) &&
	/if \(!root \|\| !cfg\.restBase \|\| !creator\) \{/.test(CLUSTERS_JS));

const SENTENCES = [
	[DRAFTS_JS, "withName('No optimization drafts waiting for review. %s stages optimizations here after each scheduled run.')"],
	[DRAFTS_JS, "'%d item needs a focus keyphrase before %s can optimize it. Set one in your SEO plugin:'"],
	[DRAFTS_JS, "'%d items need a focus keyphrase before %s can optimize them. Set one in your SEO plugin:'"],
	[CLUSTERS_JS, "creator.withName('No keyphrases queued yet. Click Derive to have %s propose some, or add your own below.')"],
	[CLUSTERS_JS, "creator.withName('Deriving — %s is reading the target,"],
];
check('the five sentences in the two bundles take her name as a placeholder',
	SENTENCES.every(s => s[0].indexOf(s[1]) !== -1),
	'missing: ' + JSON.stringify(SENTENCES.filter(s => s[0].indexOf(s[1]) === -1).map(s => s[1])));

check('the count goes into the keyphrase line BEFORE the name, so a name cannot land on the %d',
	/withName\(head\.replace\('%d', String\(total\)\)\)/.test(DRAFTS_JS));

// Every mention left in either bundle is a comment. A line that is only a
// comment is one starting with // or * or /*; anything else is code, and a
// user-visible string lives in code.
const codeLines = src => src.split(/\r?\n/).filter(l => !/^\s*(\/\/|\/?\*)/.test(l));
const stillNamed = [['draft cards', DRAFTS_JS], ['cluster targets', CLUSTERS_JS], ['the page', PAGE]]
	.map(f => [f[0], codeLines(f[1]).filter(l => /Content Agent|the agent\b/.test(l))])
	.filter(f => f[1].length);
check('no user-visible string in either bundle or the page still says "the Content Agent"',
	stillNamed.length === 0, JSON.stringify(stillNamed));

// ── 20-23. The page ─────────────────────────────────────────────────────────
check('the h1 renders the default in the span the block repaints',
	/<h1><span id="def-creator-title"><\?php echo esc_html\( sprintf\( \$creator_title, \$creator_name \) \); \?><\/span>/.test(PAGE));

check('each tab description renders the default under the id the block repaints',
	Object.keys(COPY_TPL).every(k => new RegExp(
		'<p class="description" id="def-creator-copy-' + k + '">\\s*<\\?php echo esc_html\\( sprintf\\( \\$creator_copy\\[\'' + k + '\'\\], \\$creator_name \\) \\); \\?>'
	).test(PAGE)));

check('the same strings are localized onto DefDraftCards for the repaint',
	/'creator'   => array\(\s*'name'  => \$creator_name,\s*'title' => \$creator_title,\s*'copy'  => \$creator_copy,/.test(PAGE));

check('all four sentences reach a translator, whole',
	(PAGE.match(/__\( '[^']*%(?:1\$)?s[^']*', 'digital-employees' \)/g) || []).length === 4);

// ── 24-25. An unchanged name touches nothing (8.2.8) ────────────────────────
//
// PHP now renders the name DEF last sent, so the ordinary load hands the block
// the name already on screen. Repainting it anyway is invisible when the name
// matches the default and was the whole defect when it did not — "Carol -
// Creator" flashing into the tenant's own name on every single visit. A
// MutationObserver is the honest test: a write is a write even when the text
// written is identical, and takeRecords() reads it back synchronously.
function writesDuring(b, name) {
	const obs = new b.window.MutationObserver(() => {});
	obs.observe(b.window.document.body, { subtree: true, childList: true, characterData: true, attributes: true });
	b.api.setCreatorName(name);
	const records = obs.takeRecords();
	obs.disconnect();
	return records.length;
}

const settled = boot('Caz');
check('the name PHP rendered comes back on the list and nothing is written',
	writesDuring(settled, 'Caz') === 0 && settled.title().textContent === 'Caz - Creator',
	'wrote ' + writesDuring(boot('Caz'), 'Caz') + ' time(s), title ' + JSON.stringify(settled.title().textContent));

const renamed = boot();
check('a name that differs repaints, and repaints once — the visit after a rename',
	writesDuring(renamed, 'Caz') > 0 &&
	renamed.title().textContent === 'Caz - Creator' &&
	writesDuring(renamed, 'Caz') === 0,
	JSON.stringify(renamed.title().textContent));

console.log('\n' + results.join('\n'));
console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
process.exit(fail > 0 ? 1 : 0);
