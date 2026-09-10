/*
 * Hands-free voice — behavioural harness (V-S6b / v7.8.7). Runs the SHIPPED
 * assets/js/def-core-voice.js inside jsdom against a fake microphone, and the
 * SHIPPED voice blocks of both surfaces (the console's and Customer Chat's,
 * each extracted by marker) against their own DOM. Nothing here is a copy of
 * the code under test; the widget's phrase set is read out of its shipped
 * strings map rather than retyped.
 *
 * Bite checks: VOICE_MODULE=… (the shared module), VOICE=… (the console block),
 * CHAT_VOICE=… (the widget block), CHAT_STRINGS=… (the strings map) load a
 * scratch file instead.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const MODULE = fs.readFileSync(
	process.env.VOICE_MODULE ? path.resolve(process.env.VOICE_MODULE) : extract.VOICE_PATH, 'utf8');
const VOICE = extract.voice();
const CHAT_VOICE = extract.chatVoice();
const STRINGS = new Function(extract.chatStrings() + '; return DEFAULT_STRINGS;')();

// ── The fake microphone ─────────────────────────────────────────────────
// Every read of the analyser hands back one window of audio, so a check plays
// a sound rather than asserting on the rule's arithmetic. `crestOf` is what
// keeps these honest: a pure tone would clear the crest gate however tight it
// were set, so the voiced wave is a pitch-pulse train and its crest is asserted
// to sit in the 3–5 band real voiced speech occupies.
function voicedWave(buf) {   // ~220 Hz glottal pulses, crest ≈ 4.3, RMS 0.058
	for (let i = 0; i < buf.length; i++) {
		const t = i % 200;
		buf[i] = 0.5 * Math.exp(-t / 12) * Math.sin(2 * Math.PI * t / 40);
	}
}
function plosiveWave(buf) {   // the burst of a /b/ or /t/ over quiet voicing: crest ≈ 15
	buf.fill(0);
	for (let i = 0; i < 30; i++) buf[i] = 0.6 * Math.exp(-i / 6) * (i % 2 ? -1 : 1);
	for (let i = 30; i < buf.length; i++) buf[i] = 0.03 * Math.sin(i / 7);
}
function clickWave(buf) {   // a keystroke beside the phone: RMS 0.066 (over the floor), crest ≈ 15
	buf.fill(0);
	for (let i = 0; i < 40; i++) buf[i] = Math.exp(-i / 8) * (i % 2 ? -1 : 1);
}
function silentWave(buf) { buf.fill(0); }

function crestOf(wave) {
	const buf = new Float32Array(1024);
	wave(buf);
	let sum = 0, peak = 0;
	for (const v of buf) { sum += v * v; peak = Math.max(peak, Math.abs(v)); }
	const rms = Math.sqrt(sum / buf.length);
	return { rms, crest: peak / rms };
}

function boot(wave, bodyHtml) {
	const dom = new JSDOM('<!doctype html><html><body>' + (bodyHtml || '') + '</body></html>',
		{ url: 'https://e.test/', runScripts: 'outside-only' });
	const window = dom.window;
	let current = wave, script = null, reads = 0;
	const analyser = {
		fftSize: 1024,
		getFloatTimeDomainData(buf) {
			// Scripted playback is per READ, not per millisecond: an utterance is the
			// reads the detector actually takes, whatever the host's timer jitter.
			(script ? (script[reads] || silentWave) : current)(buf);
			reads++;
		},
	};
	window.AudioContext = function () {
		this.state = 'running';
		this.createAnalyser = () => analyser;
		this.createMediaStreamSource = () => ({ connect() {}, disconnect() {} });
		this.resume = () => Promise.resolve();
	};
	Object.defineProperty(window.navigator, 'mediaDevices', {
		configurable: true,
		value: { getUserMedia: () => Promise.resolve({ getTracks: () => [{ stop() {} }] }) },
	});
	window.MediaRecorder = function () {
		this.state = 'recording';
		this.mimeType = 'audio/webm';
		this.start = () => {};
		this.stop = () => { this.state = 'inactive'; if (this.onstop) this.onstop(); };
	};
	window.MediaRecorder.isTypeSupported = () => true;
	window.eval(MODULE);
	return {
		window, DefVoice: window.DefVoice,
		say: w => { script = null; current = w; },
		play: list => { script = list; reads = 0; },
		reads: () => reads,
	};
}

const wait = (w, ms) => new Promise(r => w.setTimeout(r, ms));

// ── The console's voice block, wired to a DOM and injectable stubs ───────
const CONSOLE_HTML = '<button id="micBtn"></button><span id="micLabel"></span>'
	+ '<button id="voiceBtn"></button><textarea id="composerInput" placeholder="Ask"></textarea>';

const CONSOLE_TAIL = `
	return {
		startTurn: function () { conversationOn = true; spokenTurn = true; },
		handleSpokenStop: handleSpokenStop,
		state: function () { return { on: conversationOn, spoken: spokenTurn,
			stopped: voiceStopped, mic: micBtn.dataset.state }; }
	};`;

function bootConsole(spoken) {
	const { window } = boot(voicedWave, CONSOLE_HTML);
	const document = window.document;
	const calls = [];
	const messages = [
		{ role: 'user', content: '', via_voice: true, transcribing: true },
		{ role: 'assistant', content: '', isTyping: true },
	];
	const factory = new window.Function(
		'window', 'document', 't', 'micBtn', 'micLabel', 'voiceBtn', 'composerInput',
		'messages', 'renderMessages', 'showInfo', 'showError', 'removeTypingMessage',
		'_streamAbort', 'assistantName', 'onAssistantName', 'isLoading', 'isReadOnly',
		VOICE + CONSOLE_TAIL);
	const api = factory(window, document,
		(key, fallback) => fallback,
		document.getElementById('micBtn'), document.getElementById('micLabel'),
		document.getElementById('voiceBtn'), document.getElementById('composerInput'),
		messages, () => calls.push('render'), msg => calls.push('info:' + msg),
		msg => calls.push('error:' + msg),
		() => { const i = messages.findIndex(m => m.isTyping); if (i >= 0) messages.splice(i, 1); },
		{ abort: () => calls.push('abort') }, 'Sue', () => {}, false, false);
	if (spoken !== false) api.startTurn();
	return { api, messages, calls };
}

// ── Customer Chat's voice section, same treatment ────────────────────────
const CHAT_HTML = '<div id="messages"><div class="msg"><span id="bubble">Transcribing…</span></div></div>'
	+ '<button id="micBtn"></button><textarea id="input" placeholder="Type"></textarea>';

const CHAT_TAIL = `
	return {
		endOnSpokenStop: endOnSpokenStop,
		state: function () { return { on: conversationOn, spoken: spokenTurn }; }
	};`;

function bootChat() {
	const { window } = boot(voicedWave, CHAT_HTML);
	const document = window.document;
	const calls = [];
	const els = {
		micBtn: document.getElementById('micBtn'), input: document.getElementById('input'),
		messages: document.getElementById('messages'),
	};
	const factory = new window.Function(
		'window', 'document', 'config', 't', 'els', 'root', 'appendMessage', 'setState',
		'setComposerDisabled', 'hideThinking', 'voiceRecorder', 'speaker', 'conversationOn',
		'spokenTurn', 'transcribingEl', 'composerPlaceholder', 'destroyed', 'isComposerDisabled',
		CHAT_VOICE + CHAT_TAIL);
	const api = factory(window, document, { assistantName: 'Joe' },
		key => STRINGS[key] || key, els, document.body,
		(role, text) => calls.push('said:' + text), () => {},
		off => calls.push('composer:' + (off ? 'off' : 'on')),
		() => calls.push('thinking:hidden'),
		{ release: () => calls.push('mic:released') }, { stop: () => {} },
		true, true, document.getElementById('bubble'), '', false, false);
	return { api, calls, document };
}

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

(async () => {
	// 1. a burst of keystrokes beside the phone is not speech
	{
		const t = boot(clickWave);
		const rec = t.DefVoice.createRecorder({});
		await rec.start();
		await wait(t.window, 600);
		const done = await rec.stop();
		check(1, 'a keystroke burst does NOT register as speech', !!done && done.spoke === false,
			'spoke=' + (done && done.spoke));
	}
	// 2. voiced speech at a REAL crest — not a tone that would clear any gate
	{
		const t = boot(voicedWave);
		const v = crestOf(voicedWave);
		const rec = t.DefVoice.createRecorder({});
		await rec.start();
		await wait(t.window, 400);
		const done = await rec.stop();
		check(2, 'voiced speech at the crest real speech runs (3–5, not a tone) DOES register',
			v.crest > 3 && v.crest < 5 && v.rms > 0.02 && !!done && done.spoke === true,
			'crest=' + v.crest.toFixed(2) + ' rms=' + v.rms.toFixed(3) + ' spoke=' + (done && done.spoke));
	}
	// 3. a one-word answer — four reads, one of them the word's own plosive burst.
	//    This is what SPEECH_SAMPLES is pinned by: at three, "book it" is heard as
	//    nothing and the conversation ends on "Nothing was heard".
	{
		const t = boot(silentWave);
		const rec = t.DefVoice.createRecorder({});
		await rec.start();
		t.play([voicedWave, plosiveWave, voicedWave, voicedWave]);
		await wait(t.window, 600);
		const done = await rec.stop();
		check(3, 'a one-word answer (~450 ms, a plosive inside it) still registers as speech',
			!!done && done.spoke === true, 'spoke=' + (done && done.spoke) + ' reads=' + t.reads());
	}
	// 4. Steve's canary: typing after speaking must not hold the mic open
	{
		const t = boot(voicedWave);
		let silenced = 0;
		const rec = t.DefVoice.createRecorder({ onSilence: () => { silenced++; } });
		await rec.start();
		await wait(t.window, 500);
		t.say(clickWave);
		await wait(t.window, 2200);
		check(4, 'typing after a spoken turn does NOT keep the mic listening (the pause still sends)',
			silenced === 1, 'onSilence fired ' + silenced + ' time(s)');
		await rec.stop();
	}
	// 5. what counts as a stop phrase
	{
		const { DefVoice } = boot(voicedWave);
		const set = STRINGS.voiceStopPhrases;
		const is = text => DefVoice.isStopPhrase(text, set, 'Sue');
		const cases = [
			// The name is MANDATORY and LEADS or TRAILS (Steve, 2026-09-10 — the Siri rule).
			['Sue, stop!', true], ['SUE STOP', true], ['Stop, Sue', true],
			["Sue, that's all", true], ["Thanks, that's all, Sue", true],
			// Bare phrases are no longer a stop. These four were true before this rule and
			// ARE the change: "stop" is a word people say, and as a one-word clip it is the
			// case the transcriber gets wrong (see isStopPhrase's comment).
			['Stop.', false], ['stop', false], ["That's all.", false], ['thats all', false],
			// The name must be at an END: "Thanks Sue, that's all" no longer lands. Trailing
			// it — "Thanks, that's all, Sue" — says the same thing and does.
			["Thanks Sue, that's all", false],
			// Unchanged: an instruction is not a stop; another employee is not the employee.
			['stop the newsletter', false], ['Can you stop that', false], ['', false],
			['Joe, stop', false],
		];
		const wrong = cases.filter(c => is(c[0]) !== c[1]).map(c => JSON.stringify(c[0]));
		// A translated set stays translatable — but the name is required in every language,
		// so the bare translated phrase no longer stops on its own.
		const cjkNamed = DefVoice.isStopPhrase('Sue 停止', 'ストップ、停止', 'Sue');
		const cjkBare = DefVoice.isStopPhrase('停止', 'ストップ、停止', 'Sue');
		check(5, 'a stop names the employee, leading or trailing; a bare phrase or a request does not',
			wrong.length === 0 && cjkNamed === true && cjkBare === false,
			'misjudged ' + wrong.join(', ') + ' cjkNamed=' + cjkNamed + ' cjkBare=' + cjkBare);
	}
	// 6. the console: the stop ends the session and renders no turn
	{
		const t = bootConsole();
		const stopped = t.api.handleSpokenStop('Sue, stop.');
		const s = t.api.state();
		check(6, 'console — a spoken stop ends the session, aborts the turn and leaves no bubble behind',
			stopped === true && t.messages.length === 0 && s.on === false && s.mic === 'idle' &&
			t.calls.includes('abort') && t.calls.includes('info:Conversation ended.'),
			'stopped=' + stopped + ' messages=' + t.messages.length + ' state=' + JSON.stringify(s) +
			' calls=[' + t.calls + ']');
	}
	// 7. the console: anything else is a turn, exactly as before
	{
		const t = bootConsole();
		const stopped = t.api.handleSpokenStop('Stop the newsletter going out on Friday.');
		check(7, 'console — a non-stop transcript proceeds as a turn',
			stopped === false && t.messages.length === 2 && t.api.state().on === true &&
			!t.calls.includes('abort'), 'stopped=' + stopped + ' calls=[' + t.calls + ']');
	}
	// 8. the console: a turn that was TYPED is not ended by the word on screen
	{
		const t = bootConsole(false);
		const stopped = t.api.handleSpokenStop('stop');
		check(8, 'console — the stop rule fires on a SPOKEN turn only',
			stopped === false && t.messages.length === 2 && !t.calls.includes('abort'),
			'stopped=' + stopped + ' calls=[' + t.calls + ']');
	}
	// 9. the widget: the same phrase, out of its own shipped strings map
	{
		const t = bootChat();
		const stopped = t.api.endOnSpokenStop("Joe, that's all.", null);
		check(9, 'widget — a stop phrase from the shipped strings map ends the session and drops the bubble',
			stopped === true && t.api.state().on === false && !t.document.getElementById('bubble') &&
			t.calls.includes('composer:on') && t.calls.includes('said:Conversation ended.'),
			'stopped=' + stopped + ' state=' + JSON.stringify(t.api.state()) + ' calls=[' + t.calls + ']');
	}
	// 10. the widget: anything else proceeds
	{
		const t = bootChat();
		const stopped = t.api.endOnSpokenStop('Do you stop deliveries in January?', null);
		check(10, 'widget — a non-stop transcript proceeds as a turn',
			stopped === false && t.api.state().on === true && !!t.document.getElementById('bubble'),
			'stopped=' + stopped + ' calls=[' + t.calls + ']');
	}

	console.log(results.join('\n'));
	console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
	process.exit(fail ? 1 : 0);
})();
