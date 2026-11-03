/*
 * W75 G2 — the chat reference cookie and the page report, 7 checks.
 *
 * Runs the SHIPPED setChatRefCookie (customer-chat widget) and reportPageReached
 * (the loader, which runs on every page) in a jsdom page on https.
 *
 * What it holds: the cookie mirrors the thread id host-only on Path=/ for 30 days
 * under a name page caches do not bypass on, and is cleared; the page report sends
 * the path without its query string or fragment, and only while a cookie of DEF's
 * shape exists.
 */
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const COOKIE_FN = extract.slice('setChatRefCookie',
	l => l.startsWith('\tfunction setChatRefCookie(id) {'),
	l => l.startsWith('\tfunction t(key) {'),
	['document.cookie'], 'CC_MODULE', extract.CC_PATH);
const PAGE_FN = extract.slice('reportPageReached',
	l => l.startsWith('\tfunction reportPageReached() {'),
	l => l.includes('─── Init'),
	['goals/page'], 'CC_LOADER', path.join(extract.REPO, 'assets/js/def-core-customer-chat-loader.js'));

const REF = 'orch-0123456789abcdef';

function page(url) {
	const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: url || 'https://shop.example/thank-you/?email=a%40b.c&name=Ann#done' });
	const w = dom.window;
	const posts = [];
	w.fetch = (u, opts) => { posts.push({ url: u, opts: opts }); return Promise.resolve({}); };
	const setCookie = new w.Function('document', 'CHAT_REF_COOKIE', COOKIE_FN + '\nreturn setChatRefCookie;')(w.document, 'def_chat_ref');
	const report = new w.Function('window', 'document', 'fetch', 'config', PAGE_FN + '\nreturn reportPageReached;')(
		w, w.document, w.fetch, { wpRestUrl: 'https://shop.example/wp-json/a3-ai/v1/' });
	return { w, posts, setCookie, report, jar: dom.cookieJar };
}

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

(function () {
	let p = page();
	p.setCookie(REF);
	const c = p.jar.getCookiesSync('https://shop.example/any/page')[0];
	check(1, 'the thread id is mirrored host-only, Path=/, Secure, SameSite=Lax, 30 days, readable by JS',
		c && c.key === 'def_chat_ref' && c.value === REF && c.hostOnly === true && c.path === '/' &&
		c.secure === true && c.sameSite === 'lax' && c.httpOnly === false && c.maxAge === 2592000,
		JSON.stringify(c));
	check(2, 'the name carries no prefix a page cache bypasses on',
		!/^(wordpress_|wp-|woocommerce_|comment_author)/.test(c.key));

	p.report();
	const body = p.posts[0] && JSON.parse(p.posts[0].opts.body);
	check(3, 'a page load with the cookie reports the path only — no query string, no fragment',
		p.posts.length === 1 && p.posts[0].url.endsWith('/goals/page') && body.path === '/thank-you/' &&
		Object.keys(body).length === 1, JSON.stringify(p.posts));
	check(4, 'the report is same-origin with the cookie, and carries no nonce',
		p.posts[0].opts.credentials === 'same-origin' && !('X-WP-Nonce' in p.posts[0].opts.headers));

	p.setCookie(null);
	check(5, 'clearing removes the cookie', p.w.document.cookie === '', p.w.document.cookie);
	p.report();
	check(6, 'no cookie, no report', p.posts.length === 1);

	p = page();
	p.w.document.cookie = 'def_chat_ref=orch-NOT-HEX; Path=/';
	p.report();
	check(7, 'a cookie not of DEF\'s shape sends nothing', p.posts.length === 0);

	console.log('W75 G2 chat reference cookie + page report harness');
	console.log(results.join('\n'));
	console.log(pass + ' passed, ' + fail + ' failed');
	process.exit(fail ? 1 : 0);
})();
