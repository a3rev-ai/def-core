/*
 * The console's palette against WCAG 2.1 AA — computed, not pinned.
 *
 * This asserts a RATIO, not a hex string: change --accent-green to any value you
 * like and these stay green so long as it is still readable. That is the point —
 * a pinned colour would have to be edited by whoever breaks it, which is exactly
 * the person not thinking about contrast.
 *
 * Why it exists: --accent-green was #19c37d, which is 2.30:1 on white. It carried
 * the OK status line, the uploaded-file tick, the upload chip's border and the
 * integrations badge — four foreground uses, all failing, in the theme most people
 * read the console in. The dark theme was fine, which is the trap: no single value
 * passes in both, so the token is split by theme and this file holds both halves.
 *
 * Thresholds: 4.5:1 for text, 3:1 for a non-text UI component (icon, border, ring).
 */
const fs = require('fs');
const path = require('path');
const extract = require('./extract');

const CSS = fs.readFileSync(path.join(extract.REPO, 'assets/css/staff-ai.css'), 'utf8');

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

// ── WCAG 2.1 relative luminance and contrast ────────────────────────────────
function lin(c) {
  c = c / 255;
  return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}
function rgb(colour) {
  // Three-digit hex as well as six: the shipped CSS writes both, and `#fff` is what
  // the OK pill used to be.
  const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(colour.trim());
  if (hex) {
    const h = hex[1].length === 3 ? hex[1].replace(/./g, c => c + c) : hex[1];
    return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
  }
  const m = /^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+)\s*)?\)$/i.exec(colour.trim());
  if (!m) throw new Error('cannot parse colour: ' + colour);
  return [Number(m[1]), Number(m[2]), Number(m[3]), m[4] === undefined ? 1 : Number(m[4])];
}
// A translucent fill IS its blend with what is behind it — comparing the raw rgba
// would flatter every tinted pill in the console.
function flatten(colour, behind) {
  const c = rgb(colour), a = c.length === 4 ? c[3] : 1;
  if (a === 1) return c.slice(0, 3);
  const b = rgb(behind);
  return [0, 1, 2].map(i => a * c[i] + (1 - a) * b[i]);
}
function lum(c) { return 0.2126 * lin(c[0]) + 0.7152 * lin(c[1]) + 0.0722 * lin(c[2]); }
function ratio(fg, bg, behind) {
  const f = lum(flatten(fg, behind || bg)), b = lum(flatten(bg, behind || '#ffffff'));
  const hi = Math.max(f, b), lo = Math.min(f, b);
  return (hi + 0.05) / (lo + 0.05);
}

// ── Read the shipped values ─────────────────────────────────────────────────
//
// Sliced by block so the light and dark declarations of the same token cannot be
// confused for one another — the whole bug was that one value served both.
function block(startRe) {
  const i = CSS.search(startRe);
  if (i < 0) throw new Error('block not found: ' + startRe);
  const end = CSS.indexOf('}', i);
  if (end < 0) throw new Error('unterminated block: ' + startRe);
  return CSS.slice(i, end);
}
// Undeclared is not an error — it is the cascade. A token the dark block does not
// redeclare inherits the :root value, which is the exact failure check 8 names, so
// this reports absence rather than throwing on it.
function tokenIn(blockText, name) {
  const m = new RegExp('--' + name + ':\\s*([^;]+);').exec(blockText);
  return m ? m[1].trim() : null;
}
// `color: var(--accent-green)` has to become a colour before it can be measured.
function resolve(value, vars) {
  const v = /^var\(\s*--([\w-]+)\s*\)$/.exec(value.trim());
  return v ? (tokenIn(vars, v[1]) || tokenIn(LIGHT_VARS, v[1])) : value;
}
// Anchored to the start of a rule. Unanchored, `.integration-badge-ok` also matches
// INSIDE `.dark-theme .integration-badge-ok`, so the first match in file order wins and
// a reorder — a no-op, since the dark rule takes specificity either way — silently
// points the light check at the dark rule. A broken light pill would then read as the
// dark one's values and pass.
const RULE_START = '(?:^|[}\\n])\\s*';
function esc(selector) { return selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
function hasRule(selector) {
  return new RegExp(RULE_START + esc(selector) + '\\s*\\{').test(CSS);
}
function ruleProp(selector, prop) {
  const re = new RegExp(RULE_START + esc(selector) + '\\s*\\{([^}]*)\\}');
  const m = re.exec(CSS);
  if (!m) throw new Error('rule not found: ' + selector);
  const p = new RegExp('(?:^|;)\\s*' + prop + ':\\s*([^;]+)').exec(m[1]);
  if (!p) throw new Error(selector + ': no ' + prop);
  return p[1].trim();
}

const LIGHT_VARS = block(/:root\s*\{/);
const DARK_VARS = block(/\.dark-theme\s*\{/);
const lightGreen = tokenIn(LIGHT_VARS, 'accent-green');
const darkDeclared = tokenIn(DARK_VARS, 'accent-green');
// What dark mode ACTUALLY paints: its own value, or the light one inherited.
const darkGreen = darkDeclared || lightGreen;
const lightBg = tokenIn(LIGHT_VARS, 'bg-main');
const darkBg = tokenIn(DARK_VARS, 'bg-main') || tokenIn(LIGHT_VARS, 'bg-main');
if (!lightGreen) { throw new Error(':root does not declare --accent-green at all'); }

const TEXT = 4.5, UI = 3.0;
let n = 0;

// 1-2. The OK status line (.console-status-ok — Connections retired its own
//      .integrations-status-ok into it in C6c) and the uploaded tick both take their
//      colour straight from the token.
[['light', lightGreen, lightBg], ['dark', darkGreen, darkBg]].forEach(function (t) {
  const r = ratio(t[1], t[2]);
  check(++n, '--accent-green carries text on the ' + t[0] + ' page at AA (' + t[1] + ' on ' + t[2] + ')',
    r >= TEXT, r.toFixed(2) + ':1 needs ' + TEXT);
});

// 3-4. The same token as a non-text component — the upload chip's border and its tick.
//      Measured against --bg-input, which is what the chip actually sits on: against
//      --bg-main this is the same pair as checks 1-2 at a LOWER threshold, so it could
//      never fail while they passed.
const lightInput = tokenIn(LIGHT_VARS, 'bg-input');
const darkInput = tokenIn(DARK_VARS, 'bg-input') || lightInput;
[['light', lightGreen, lightInput], ['dark', darkGreen, darkInput]].forEach(function (t) {
  const r = ratio(t[1], t[2]);
  check(++n, '--accent-green works as the upload chip border/tick on the ' + t[0] +
    ' theme, on --bg-input (3:1)', r >= UI, r.toFixed(2) + ':1 on ' + t[2] + ' needs ' + UI);
});

// 5-6. The integrations OK pill. It was white-on-green, which is 2.30:1 whatever the
//      page is doing — a filled pill's contrast has nothing to do with the ground
//      behind it, so the token could never have fixed this one.
[['light', '.integration-badge-ok', lightBg, LIGHT_VARS],
 ['dark', '.dark-theme .integration-badge-ok', darkBg, DARK_VARS]]
  .forEach(function (t) {
    // A theme with no variant of its own wears the base rule.
    const sel = hasRule(t[1]) ? t[1] : '.integration-badge-ok';
    const vars = t[3];
    const fg = resolve(ruleProp(sel, 'color'), vars);
    const bg = resolve(ruleProp(sel, 'background'), vars);
    const r = ratio(fg, bg, t[2]);
    check(++n, 'the integrations OK pill is readable on the ' + t[0] + ' page (' + fg + ' on ' + bg + ')',
      r >= TEXT, r.toFixed(2) + ':1 needs ' + TEXT);
  });

// 7. The pill is not white on a solid green any more — in BOTH themes, which means
//    reading both rules and not just the base one. Its own check because 5-6 would pass
//    on any readable white-on-dark-green pairing, and the rule kept here is narrower:
//    do not paint white on the accent at all.
const whiteOnAccent = ['.integration-badge-ok', '.dark-theme .integration-badge-ok']
  .filter(hasRule)
  .filter(function (sel) {
    return /^(#fff|#ffffff|white)$/i.test(ruleProp(sel, 'color')) ||
      /var\(--accent-green\)/.test(ruleProp(sel, 'background'));
  });
check(++n, 'neither OK pill rule paints white on the accent colour',
  whiteOnAccent.length === 0, 'offending: ' + (whiteOnAccent.join(', ') || 'none'));

// 8. The trap that started this: one value for both themes. If a future edit deletes
//    the dark declaration, the light green inherits into dark mode and drops to
//    2.26:1 - checks 1-4 would still pass, because they would read the same value twice.
check(++n, 'the accent is declared SEPARATELY for each theme, not inherited into both',
  !!darkDeclared && darkDeclared !== lightGreen,
  'light=' + lightGreen + ' dark=' + (darkDeclared || 'NOT DECLARED — inherits ' + lightGreen));

// 9. EVERY token used as plain text, both themes, enumerated FROM THE STYLESHEET
//    rather than listed here. This is the check that makes the file worth keeping: a
//    colour added below AA fails without anyone remembering to add a case for it.
//
//    Judged against the worst ground it could sit on (page, sidebar, input, modal),
//    because static CSS cannot know which one an element actually lands on. A rule that
//    paints its own background is a self-contained pair and is skipped — checks 5-6
//    cover the one that matters.
//
//    Two narrow exemptions, both non-text uses that answer to the 3:1 rule instead —
//    named as token@ground so the exemption is visible and cannot quietly widen:
//      tool-icon-color      — .tool-output-icon svg, an icon everywhere it appears.
//      accent-green@bg-input — the upload chip's tick sits on the input ground; the
//                              token's TEXT uses (.console-status-ok) are on bg-main
//                              at 5.28:1. Checks 3-4 hold this pairing to its real 3:1.
const NON_TEXT = ['tool-icon-color', 'accent-green@bg-input'];
const GROUNDS = ['bg-main', 'bg-sidebar', 'bg-input', 'bg-modal'];
const RULE = /(?:^|[}\n])\s*([^{}@\/][^{}]*?)\s*\{([^{}]*)\}/g;
const textTokens = new Set();
let rm;
while ((rm = RULE.exec(CSS)) !== null) {
  if (/^:root/.test(rm[1].trim())) continue;
  if (/(?:^|;)\s*background(?:-color)?:/.test(rm[2])) continue;
  const c = /(?:^|;)\s*color:\s*var\(\s*--([\w-]+)\s*\)/.exec(rm[2]);
  if (c && NON_TEXT.indexOf(c[1]) === -1) textTokens.add(c[1]);
}
const belowAA = [];
Array.from(textTokens).sort().forEach(function (tok) {
  [['light', LIGHT_VARS], ['dark', DARK_VARS]].forEach(function (t) {
    const fgRaw = tokenIn(t[1], tok) || tokenIn(LIGHT_VARS, tok);
    if (!fgRaw) { return; }
    const fg = resolve(fgRaw, t[1]);
    GROUNDS.forEach(function (g) {
      const bgRaw = tokenIn(t[1], g) || tokenIn(LIGHT_VARS, g);
      if (!bgRaw) { return; }
      if (NON_TEXT.indexOf(tok + '@' + g) !== -1) { return; }
      const r = ratio(fg, resolve(bgRaw, t[1]));
      if (r < TEXT) { belowAA.push('--' + tok + ' ' + t[0] + '/' + g + ' ' + r.toFixed(2) + ':1'); }
    });
  });
});
check(++n, 'every token used as plain text clears 4.5:1 on every ground, both themes (' +
  textTokens.size + ' tokens)',
  belowAA.length === 0, belowAA.join('; '));

console.log('\n' + results.join('\n'));
console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
process.exit(fail > 0 ? 1 : 0);
