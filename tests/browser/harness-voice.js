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
	// Every buffer the context starts, by duration: a clip decodes to CLIP seconds,
	// a silence is what its own length says. A source "ends" a few ms after it starts —
	// or, with ctl.hold, only when a check calls its end(). `scheduled` keeps each
	// start's when, duration and buffer, and whether stop() reached it.
	const starts = [], scheduled = [], elementPlays = [], elements = [], blobs = [];
	const ctl = { hold: false, ctx: null };
	window.AudioContext = function () {
		ctl.ctx = this;
		this.state = 'running';
		this.sampleRate = 48000;
		this.currentTime = 0;
		this.destination = {};
		this.createAnalyser = () => analyser;
		this.createMediaStreamSource = () => ({ connect() {}, disconnect() {} });
		this.resume = () => Promise.resolve();
		this.createBuffer = (channels, length, rate) => {
			const data = new Float32Array(length);
			return { duration: length / rate, getChannelData: () => data };
		};
		this.decodeAudioData = (bytes, resolve) => resolve({ duration: CLIP });
		this.createBufferSource = () => {
			const rec = { stopped: false, end: () => node.onended && node.onended() };
			const node = { connect() {},
				stop() { rec.stopped = true; },
				start(when) {
					Object.assign(rec, { when: when || 0, duration: node.buffer.duration, buffer: node.buffer });
					starts.push(node.buffer.duration);
					scheduled.push(rec);
					if (!ctl.hold) window.setTimeout(rec.end, 5);
				} };
			return node;
		};
	};
	window.HTMLMediaElement.prototype.play = function () {
		elementPlays.push(this.src); elements.push(this); return Promise.resolve();
	};
	window.URL.createObjectURL = blob => { blobs.push(blob); return 'blob:https://e.test/' + blobs.length; };
	window.URL.revokeObjectURL = () => {};
	window.HTMLMediaElement.prototype.pause = function () {};
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
		window, DefVoice: window.DefVoice, starts, scheduled, elementPlays, elements, blobs, ctl,
		say: w => { script = null; current = w; },
		play: list => { script = list; reads = 0; },
		reads: () => reads,
	};
}

const wait = (w, ms) => new Promise(r => w.setTimeout(r, ms));
const CLIP = 2.4;
const REPLY = { base64: 'AAAA', mime: 'audio/mpeg' };

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
		enableVoice: enableVoice,
		use: function (rec, spk) { voiceRecorder = rec; speaker = spk; },
		set: function (on, spoken) { conversationOn = on; spokenTurn = spoken; },
		state: function () { return { on: conversationOn, spoken: spokenTurn, mic: els.micBtn.dataset.state }; }
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
	return { api, calls, document, window };
}

// The widget with its voice enabled as the page does it, then a hide of one kind.
function hideChat(on, spoken, event, visibility) {
	const t = bootChat();
	t.api.enableVoice();
	t.api.enableVoice();   // a widget opened twice still ends once
	t.api.use({ release: () => t.calls.push('mic:released') }, { stop: () => t.calls.push('speaker:stopped') });
	t.api.set(on, spoken);
	Object.defineProperty(t.document, 'visibilityState', { value: visibility, configurable: true });
	(event === 'pagehide' ? t.window : t.document).dispatchEvent(new t.window.Event(event));
	return t;
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

	// 11. the mic tap warms the output: silence starts through the context inside the tap
	{
		const t = boot(voicedWave);
		const speaker = t.DefVoice.createSpeaker({});
		speaker.unlock();
		check(11, 'unlock() starts at least 0.4 s of silence through the context, inside the call',
			t.starts.length === 1 && t.starts[0] >= 0.4 && t.elementPlays.length === 1 &&
			t.DefVoice.log().some(l => l.endsWith(' warm')),
			'starts=' + JSON.stringify(t.starts) + ' element=' + t.elementPlays.length);
	}
	// 12. no AudioContext on the page: unlock still runs, the element warm is unchanged
	{
		const t = boot(voicedWave);
		delete t.window.AudioContext;
		let threw = null;
		try { t.DefVoice.createSpeaker({}).unlock(); } catch (e) { threw = e; }
		check(12, 'unlock() with no AudioContext still plays the element and starts nothing else',
			!threw && t.starts.length === 0 && t.elementPlays.length === 1,
			'threw=' + threw + ' starts=' + JSON.stringify(t.starts));
	}
	// 13–16. the lead-in: 300 ms of silence before a reply's first clip, and only there
	{
		const t = boot(voicedWave);
		const speaker = t.DefVoice.createSpeaker({});
		speaker.unlock();
		await wait(t.window, 20);
		t.starts.length = 0;
		speaker.speak('Yes, I can read your message.', REPLY);
		await speaker.whenIdle();
		check(13, 'an idle speaker plays ~0.3 s of silence through the context BEFORE the clip',
			JSON.stringify(t.starts) === JSON.stringify([0.3, CLIP]), JSON.stringify(t.starts));

		t.starts.length = 0;
		speaker.speak('One.', REPLY);
		speaker.speak('Two.', REPLY);
		await speaker.whenIdle();
		check(14, 'a line queued while the speaker is busy gets no lead-in of its own',
			JSON.stringify(t.starts) === JSON.stringify([0.3, CLIP, CLIP]), JSON.stringify(t.starts));

		t.starts.length = 0;
		speaker.speak('The next reply.', REPLY);
		await speaker.whenIdle();
		check(15, 'the next reply, once the speaker went idle, gets its lead-in again',
			JSON.stringify(t.starts) === JSON.stringify([0.3, CLIP]), JSON.stringify(t.starts));

		t.starts.length = 0;
		speaker.setMode('device');
		speaker.speak('Read by the device.', REPLY);
		await speaker.whenIdle();
		check(16, 'the device voice gets no lead-in', t.starts.length === 0, JSON.stringify(t.starts));
	}
	// 17–21. the widget: a tab you leave stops speaking and closes its mic (W72)
	{
		const ended = ['mic:released', 'speaker:stopped'];
		const endedOn = t => JSON.stringify(t.calls) === JSON.stringify(ended) &&
			t.api.state().on === false && t.api.state().spoken === false && t.api.state().mic === 'idle';

		let t = hideChat(true, false, 'visibilitychange', 'hidden');
		check(17, 'widget — hands-free on, the tab hides: the mic is released and the speaker stopped',
			endedOn(t), 'calls=[' + t.calls + '] state=' + JSON.stringify(t.api.state()));
		t = hideChat(false, true, 'visibilitychange', 'hidden');
		check(18, 'widget — a spoken turn still being read, the tab hides: the same',
			endedOn(t), 'calls=[' + t.calls + '] state=' + JSON.stringify(t.api.state()));
		t = hideChat(false, false, 'visibilitychange', 'hidden');
		check(19, 'widget — nothing spoken or listening: a hide touches nothing',
			t.calls.length === 0, 'calls=[' + t.calls + ']');
		t = hideChat(true, true, 'visibilitychange', 'visible');
		check(20, 'widget — coming back to the tab touches nothing',
			t.calls.length === 0 && t.api.state().on === true, 'calls=[' + t.calls + ']');
		t = hideChat(true, false, 'pagehide', 'hidden');
		check(21, 'widget — pagehide ends it as a hide does', endedOn(t), 'calls=[' + t.calls + ']');
	}

	// 22–29. streamed speech (W70): a segment arrives as pcm parts of one seq, the
	// last of them a closing frame with no audio. Nodes end only when a check says.
	{
		const PCM = 'audio/pcm;rate=24000';
		const part = (seq, n, bytes, last) => ({ base64: bytes ? Buffer.from(bytes).toString('base64') : '',
			mime: PCM, seq, part: n, last: !!last });
		const settle = w => wait(w, 3);
		async function bootStream() {
			const t = boot(voicedWave);
			const speaker = t.DefVoice.createSpeaker({});
			speaker.unlock();
			await wait(t.window, 20);   // the warm plays out
			t.scheduled.length = 0;
			t.starts.length = 0;
			t.ctl.hold = true;
			t.ctl.ctx.currentTime = 1;
			let idle = false;
			return { t, speaker, w: t.window, idle: () => idle,
				watchIdle: () => { idle = false; speaker.whenIdle().then(() => { idle = true; }); } };
		}

		// 22. parts play back to back, after the lead-in, and the line ends at its closing frame
		{
			const s = await bootStream();
			s.speaker.speak('Hello there, friend.', part(1, 0, [1, 0, 2, 0], false));
			await settle(s.w);
			const leadAlone = s.t.scheduled.length === 1 && s.t.scheduled[0].duration === 0.3;
			s.t.scheduled[0].end();
			await settle(s.w);
			s.speaker.speak('', part(1, 1, [3, 0, 4, 0, 5, 0], false));
			await settle(s.w);
			s.watchIdle();
			s.t.scheduled.slice(1).forEach(r => r.end());   // both parts play out before the closing frame
			await settle(s.w);
			const idleBeforeClose = s.idle();
			s.speaker.speak('', part(1, 2, null, true));
			await settle(s.w);
			const [, p0, p1] = s.t.scheduled;
			const logged = s.t.DefVoice.log().join('|');
			check(22, 'stream — part 0 after the lead-in, part 1 at exactly part 0\'s when + duration, the line ends at its closing frame',
				leadAlone && s.t.scheduled.length === 3 && p0.when === 1 + 0.05 && p0.duration === 2 / 24000 &&
				p1.when === p0.when + p0.duration && p1.duration === 3 / 24000 &&
				!idleBeforeClose && s.idle() && /play stream/.test(logged) && / played/.test(logged),
				JSON.stringify(s.t.scheduled.map(r => [r.when, r.duration])) + ' idleBeforeClose=' + idleBeforeClose +
				' idle=' + s.idle() + ' log=' + logged);
		}
		// 23. seq 2 never starts before seq 1's last part ends (its own parts join it while it waits)
		{
			const s = await bootStream();
			s.speaker.speak('One.', part(1, 0, [1, 0], false));
			await settle(s.w);
			s.t.scheduled[0].end();
			await settle(s.w);
			s.speaker.speak('Two.', part(2, 0, [1, 0, 2, 0, 3, 0, 4, 0], false));
			s.speaker.speak('', part(2, 1, null, true));
			s.speaker.speak('', part(1, 1, [2, 0], false));
			s.speaker.speak('', part(1, 2, null, true));
			await settle(s.w);
			const seq1Only = s.t.scheduled.length;
			s.t.scheduled[1].end();
			await settle(s.w);
			const afterFirst = s.t.scheduled.length;
			s.t.scheduled[2].end();
			await settle(s.w);
			const seq2 = s.t.scheduled[3];
			check(23, 'stream — seq 2 starts only once seq 1\'s last part has ended, with no lead-in of its own',
				seq1Only === 3 && afterFirst === 3 && s.t.scheduled.length === 4 && seq2.duration === 4 / 24000,
				'counts=' + [seq1Only, afterFirst, s.t.scheduled.length] + ' ' + JSON.stringify(s.t.starts));
		}
		// 24. the queue drains mid-reply (audio trails playback): the next segment gets no second lead-in
		{
			const s = await bootStream();
			s.speaker.speak('One.', part(1, 0, [1, 0], false));
			await settle(s.w);
			s.t.scheduled[0].end();
			await settle(s.w);
			s.speaker.speak('', part(1, 1, null, true));
			await settle(s.w);
			s.watchIdle();
			s.t.scheduled[1].end();
			await settle(s.w);
			const drained = s.idle();
			s.speaker.speak('Two.', part(2, 0, [1, 0, 2, 0], false));
			await settle(s.w);
			check(24, 'stream — a segment after the queue drained mid-reply gets no second lead-in',
				drained && JSON.stringify(s.t.starts) === JSON.stringify([0.3, 1 / 24000, 2 / 24000]),
				'drained=' + drained + ' ' + JSON.stringify(s.t.starts));
		}
		// 25. little-endian Int16 → Float32: bytes 0x00,0x40 are 0.5
		{
			const s = await bootStream();
			s.speaker.speak('Half.', part(1, 0, [0x00, 0x40, 0x00, 0xC0], true));
			await settle(s.w);
			s.t.scheduled[0].end();
			await settle(s.w);
			const buf = s.t.scheduled[1] && s.t.scheduled[1].buffer;
			const data = buf && buf.getChannelData && buf.getChannelData(0);
			check(25, 'stream — bytes 0x00,0x40 decode to 0.5 (and 0x00,0xC0 to -0.5) at 24 kHz',
				!!data && data[0] === 0.5 && data[1] === -0.5 && s.t.scheduled[1].duration === 2 / 24000,
				'data=' + (data && Array.from(data)));
		}
		// 26. stop() mid-segment: every node stops, a later part never plays, whenIdle resolves
		{
			const s = await bootStream();
			s.speaker.speak('One.', part(1, 0, [1, 0], false));
			await settle(s.w);
			s.t.scheduled[0].end();
			await settle(s.w);
			s.speaker.speak('', part(1, 1, [2, 0], false));
			await settle(s.w);
			s.watchIdle();
			s.speaker.stop();
			await settle(s.w);
			const parts = s.t.scheduled.slice(1);
			s.speaker.speak('', part(1, 2, [3, 0], false));
			s.speaker.speak('', part(1, 3, null, true));
			await settle(s.w);
			check(26, 'stream — stop() mid-segment stops every scheduled part, a later part never plays, whenIdle resolves',
				parts.length === 2 && parts.every(r => r.stopped) && s.t.scheduled.length === 3 && s.idle(),
				'stopped=' + parts.map(r => r.stopped) + ' scheduled=' + s.t.scheduled.length + ' idle=' + s.idle());
		}
		// 27. whenIdle waits for the last part's END, not its scheduling
		{
			const s = await bootStream();
			s.speaker.speak('One.', part(1, 0, [1, 0], false));
			await settle(s.w);
			s.t.scheduled[0].end();
			await settle(s.w);
			s.speaker.speak('', part(1, 1, [2, 0], false));
			s.speaker.speak('', part(1, 2, null, true));
			await settle(s.w);
			s.watchIdle();
			await settle(s.w);
			const whileBoth = s.idle();
			s.t.scheduled[1].end();
			await settle(s.w);
			const whileLast = s.idle();
			s.t.scheduled[2].end();
			await settle(s.w);
			check(27, 'stream — whenIdle resolves only once the last part has ended',
				!whileBoth && !whileLast && s.idle(), [whileBoth, whileLast, s.idle()].join(','));
		}
		// 28. an old one-frame mp3 line (part 0, last) still decodes and plays as one clip
		{
			const t = boot(voicedWave);
			const speaker = t.DefVoice.createSpeaker({});
			speaker.unlock();
			await wait(t.window, 20);
			t.starts.length = 0;
			speaker.speak('An old clip.', { base64: 'AAAA', mime: 'audio/mpeg', seq: 1, part: 0, last: true });
			await speaker.whenIdle();
			check(28, 'stream — a one-frame mp3 line still plays through decodeAudioData after its lead-in',
				JSON.stringify(t.starts) === JSON.stringify([0.3, CLIP]), JSON.stringify(t.starts));
		}
		// 29. no AudioContext: the element plays ONE audio/wav, after the closing frame, holding every part
		{
			const t = boot(voicedWave);
			delete t.window.AudioContext;
			const speaker = t.DefVoice.createSpeaker({});
			speaker.unlock();
			const warmed = t.elementPlays.length;
			speaker.speak('One.', part(1, 0, [1, 0, 2, 0], false));
			speaker.speak('', part(1, 1, [3, 0, 4, 0, 5, 0], false));
			await wait(t.window, 10);
			const beforeClose = t.elementPlays.length - warmed;
			speaker.speak('', part(1, 2, null, true));
			await wait(t.window, 10);
			const blob = t.blobs[0];
			const head = blob ? new DataView(await blob.arrayBuffer()) : null;
			let idle = false;
			speaker.whenIdle().then(() => { idle = true; });
			const el = t.elements[t.elements.length - 1];
			if (el && el.onended) el.onended();
			await wait(t.window, 10);
			check(29, 'stream — with no AudioContext the element plays one audio/wav after the closing frame, its data every part',
				beforeClose === 0 && t.elementPlays.length - warmed === 1 && t.blobs.length === 1 &&
				!!blob && blob.type === 'audio/wav' && blob.size === 44 + 10 && head.getUint32(40, true) === 10 &&
				head.getUint32(24, true) === 24000 && idle,
				'beforeClose=' + beforeClose + ' plays=' + (t.elementPlays.length - warmed) + ' blobs=' + t.blobs.length +
				' type=' + (blob && blob.type) + ' size=' + (blob && blob.size) + ' idle=' + idle);
		}
		// 30. the stream ended without a line's closing frame: end() lets it finish what it has
		{
			const s = await bootStream();
			s.speaker.speak('Three.', part(3, 0, [1, 0], false));
			await settle(s.w);
			s.speaker.speak('', part(3, 1, [2, 0], false));
			await settle(s.w);
			s.watchIdle();
			s.speaker.end();
			await settle(s.w);
			const beforeEnd = s.idle();
			s.t.scheduled.forEach(r => r.end());
			await settle(s.w);
			check(30, 'stream — end() closes a line whose closing frame never came: whenIdle resolves once its last part ends',
				s.t.scheduled.length === 2 && !beforeEnd && s.idle(),
				'scheduled=' + s.t.scheduled.length + ' beforeEnd=' + beforeEnd + ' idle=' + s.idle());
		}
	}

	console.log(results.join('\n'));
	console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
	process.exit(fail ? 1 : 0);
})();
