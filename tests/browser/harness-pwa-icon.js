/*
 * Staff AI as an app — the head tags that decide the home-screen icon (v8.2.7).
 *
 * The shell deliberately skips wp_head(), so anything WordPress would have
 * emitted has to be written here by hand — and apple-touch-icon never was. iOS
 * reads ONLY that tag: with none, it fell back to a manifest PNG whose corners
 * are transparent and masked them onto white, which is the white ring around
 * the purple tile on Steve's iPhone.
 *
 * Read off the shipped template and the shipped PHP, so the checks bite by
 * editing those two files. The resolver's own chain and the true-size
 * declaration are the PHP half — tests/test-staff-ai-pwa-icon.php.
 *
 * 5 checks.
 */
const fs = require('fs');
const path = require('path');
const extract = require('./extract');

const read = f => fs.readFileSync(path.join(extract.REPO, f), 'utf8');
const TPL = read('templates/staff-ai-shell.php');
const PHP = read('includes/class-def-core-staff-ai.php');

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

let n = 0;

// The <head>, which is the only part of the document any of this lives in.
const HEAD = TPL.slice(TPL.indexOf('<head>'), TPL.indexOf('</head>'));

// ── 1-2. The tag iOS reads ──────────────────────────────────────────────────
const touch = /<link rel="apple-touch-icon" sizes="([^"]*)" href="([^"]*)">/.exec(HEAD);

// Both halves come off the resolver — including the size, which is the file's
// own, not a literal (the bug being fixed was a literal 192x192 over a 250 file).
check(++n, 'the head carries apple-touch-icon, href AND declared size from the shared resolver',
	!!touch && /\$apple_touch_icon\['sizes'\]/.test(touch[1]) &&
	/\$apple_touch_icon\['src'\]/.test(touch[2]),
	touch ? 'renders: ' + touch[0] : 'no apple-touch-icon link in the head at all');

// iOS ignores an SVG here, so the href must resolve to a raster file. Both ends
// of the resolver are held to that: the mime guard refuses anything a browser
// may not read, and the last link, the bundled default, is a .png at its source.
check(++n, 'the href names no SVG, the resolver admits only PNG/JPEG/WebP, and its default is a PNG',
	!!touch && !/\.svg/.test(touch[2]) &&
	/in_array\(\$mime, array\('image\/png', 'image\/jpeg', 'image\/webp'\), true\)/.test(PHP) &&
	/'assets\/images\/staff-ai-icon-' \. \$px \. '\.png'/.test(PHP),
	touch && /\.svg/.test(touch[2]) ? 'the href names an SVG'
		: 'the mime guard or bundled_icon()\'s .png has changed shape');

// ── 3. The name and the standalone hint ─────────────────────────────────────
//
// The un-prefixed mobile-web-app-capable is the standard; Chrome logs a
// deprecation warning for a page carrying only the legacy Apple one.
check(++n, 'the installed app is named Staff AI, and opens without browser chrome (both spellings)',
	/<meta name="apple-mobile-web-app-title" content="<\?php echo esc_attr__\( 'Staff AI', 'digital-employees' \); \?>">/.test(HEAD) &&
	/<meta name="mobile-web-app-capable" content="yes">/.test(HEAD) &&
	/<meta name="apple-mobile-web-app-capable" content="yes">/.test(HEAD));

// ── 4. Both halves wired, not just the tag ──────────────────────────────────
check(++n, 'render_shell() fills $apple_touch_icon from the manifest\'s own resolver, at 180',
	/\$apple_touch_icon = self::app_icon\( 180 \);/.test(PHP) &&
	/function app_icon\(int \$size\): array/.test(PHP));

// ── 5. The route it replaces is gone ────────────────────────────────────────
//
// /staff-ai/icon.svg served initials on a rounded #6366f1 plate — the fallback
// iOS was masking. Nothing may be left to answer it.
check(++n, 'the generated-SVG icon route is gone: no rewrite, no case, no method',
	!/icon\\\.svg/.test(PHP) && !/serve_pwa_icon/.test(PHP) &&
	!/staff_ai_pwa=icon/.test(PHP));

console.log('\n' + results.join('\n'));
console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
process.exit(fail > 0 ? 1 : 0);
