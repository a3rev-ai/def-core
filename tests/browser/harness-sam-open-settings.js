/*
 * An open settings page follows the Setup Assistant's changes (v9.5.1, v9.5.2).
 *
 * Saving posts every field on the tab, so a field left stale would put the old value
 * back on the next Save. Runs the shipped drawer in jsdom.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const SRC = fs.readFileSync(path.resolve(__dirname, '../../assets/js/setup-assistant-drawer.js'), 'utf8');
const P = 'def_core_chat_escalation_';

let pass = 0, fail = 0;
function check(label, cond) {
	cond ? pass++ : fail++;
	console.log((cond ? '  ok   ' : '  FAIL ') + label);
}

(async function () {
	const dom = new JSDOM('<!doctype html><html><body>' +
		'<button id="def-setup-assistant-trigger"></button>' +
		'<div id="def-setup-assistant-drawer"><div class="def-sa-backdrop"></div><div class="def-sa-panel">' +
		'<button class="def-sa-clear"></button><button class="def-sa-close"></button>' +
		'<div class="def-sa-messages"></div><textarea class="def-sa-input"></textarea><button class="def-sa-send"></button>' +
		'</div></div>' +
		`<input id="${P}title"><input id="${P}desc"><input id="${P}ack_subject">` +
		`<textarea id="${P}pref_options"></textarea><textarea id="${P}ack_body"></textarea>` +
		`<select id="${P}business_fields"><option value="hidden"></option><option value="required"></option></select>` +
		`<input type="checkbox" id="${P}pref_enabled"><input type="checkbox" id="${P}ack_enabled" checked>` +
		'<input type="hidden" id="def_core_app_icon_id"><input type="hidden" id="def_core_chat_button_icon_id">' +
		'<input type="hidden" id="def_core_chat_loading_mark_id"><input type="url" id="def_core_chat_privacy_url">' +
		'<input type="number" id="def_core_logo_max_height">' +
		'<input type="checkbox" id="def_core_logo_show_staff_ai"><input type="checkbox" id="def_core_logo_show_customer_chat">' +
		'<input type="checkbox" id="def_core_chat_show_floating"><input type="checkbox" id="def_core_chat_ai_notice">' +
		'<input type="radio" name="def_core_chat_button_position" value="right" checked>' +
		'<input type="radio" name="def_core_chat_button_position" value="left">' +
		'</body></html>', { url: 'https://site.test/wp-admin/', runScripts: 'outside-only', pretendToBeVisual: true });
	const w = dom.window;
	extract.stubMatchMedia(w);
	w.eval(fs.readFileSync(path.resolve(__dirname, '../../assets/js/def-persona.js'), 'utf8'));
	w.defSetupAssistant = { restUrl: 'https://site.test/wp-json/def-core/v1/', nonce: 'n' };
	w.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) });
	w.eval(SRC.replace(/new SetupAssistantDrawer\(\);/g, 'window.__sam = new SetupAssistantDrawer();'));
	await new Promise(r => setTimeout(r, 20));

	const el = key => w.document.getElementById(P + key);
	const update = (key, value) => w.__sam.handleUpdateField({ field: 'chat_escalation_' + key, value });

	for (const key of ['title', 'desc', 'pref_options', 'ack_subject', 'ack_body']) {
		update(key, 'new ' + key);
		check(key + ' takes the new value', el(key).value === 'new ' + key);
	}
	update('business_fields', 'required');
	check('business_fields takes the new choice', el('business_fields').value === 'required');
	update('pref_enabled', '1');
	check('a switch turned on is ticked', el('pref_enabled').checked === true);
	update('ack_enabled', '0');
	check('a switch turned off is unticked', el('ack_enabled').checked === false);

	const field = key => w.document.getElementById('def_core_' + key);
	for (const [key, value] of [['app_icon_id', '12'], ['chat_button_icon_id', '13'], ['chat_loading_mark_id', '14'],
		['chat_privacy_url', 'https://site.test/terms/'], ['logo_max_height', '60']]) {
		w.__sam.handleUpdateField({ field: key, value });
		check(key + ' takes the new value', field(key).value === value);
	}
	for (const key of ['logo_show_staff_ai', 'logo_show_customer_chat', 'chat_show_floating', 'chat_ai_notice']) {
		w.__sam.handleUpdateField({ field: key, value: '1' });
		check(key + ' is ticked', field(key).checked === true);
	}
	w.__sam.handleUpdateField({ field: 'chat_button_position', value: 'left' });
	check('chat_button_position moves to left',
		w.document.querySelector('input[name="def_core_chat_button_position"]:checked').value === 'left');

	console.log('harness-sam-open-settings: ' + pass + ' passed, ' + fail + ' failed');
	process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
