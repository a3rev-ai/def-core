/*
 * W77 — the page's pictures ride the chat message (customer-chat page-reads
 * runsheet D-PR4).
 *
 * Runs the SHIPPED assets/js/def-core-page-context.js inside jsdom with the
 * payload PHP localizes (window.DefCorePageContext), then calls the helper's
 * build() the way the chat does at submit time.
 *
 *  1. build() carries the payload's images on `current`, as PHP listed them.
 *  2. A payload without images (an older cached page) sends an empty list.
 *
 * 2 checks.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const SOURCE = fs.readFileSync(
	path.join(__dirname, '..', '..', 'assets', 'js', 'def-core-page-context.js'), 'utf8');

let passed = 0, failed = 0;
function check(cond, name) {
	if (cond) { passed++; console.log('  ok   ' + name); }
	else { failed++; console.log('  FAIL ' + name); }
}

function build(payload) {
	const dom = new JSDOM('<!doctype html><html><body></body></html>',
		{ url: 'https://shop.example/kettle/', runScripts: 'outside-only' });
	dom.window.DefCorePageContext = payload;
	dom.window.eval(SOURCE);
	return dom.window.DefCorePageContextHelper.build(null);
}

console.log('page context images:');
const images = [
	{ url: 'https://shop.example/wp-content/uploads/front.jpg', alt: 'Front view', caption: 'The kettle' },
	{ url: 'https://shop.example/wp-content/uploads/lid.jpg', alt: '', caption: 'Inside the lid' },
];
const current = build({ page_type: 'product', page_id: 7, images: images }).current;
check(JSON.stringify(current.images) === JSON.stringify(images), 'build() carries the images on current');
check(JSON.stringify(build({ page_type: 'post', page_id: 7 }).current.images) === '[]',
	'no images in the payload → an empty list');

console.log(passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
