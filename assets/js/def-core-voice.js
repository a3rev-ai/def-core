/**
 * DEF Core — voice (7.7.1). One module for both chat surfaces: the mic (record, with
 * silence detection so a pause sends), the recording as base64 for the chat request
 * (DEF transcribes it on the stream — one round trip), and the readback (the
 * employee's own voice arriving as `speech` frames on that stream, or the device
 * voice from the reply's text). The Staff-AI console consumes it now; Customer Chat
 * consumes the same object when its employee's Voice switch is on.
 */
window.DefVoice = (function () {
	'use strict';

	// Recording stops itself here — a UX stop, not a cap: a two-minute instruction
	// is a memo. DEF's transcription bound (the vendor's 25 MB) sits far above.
	var MAX_RECORD_MS = 120000;
	// Hands-free: a pause this long after speech sends; this long with no speech
	// at all ends the conversation rather than listening to an empty room.
	var SILENCE_MS = 1800;
	var IDLE_MS = 10000;
	var SPEECH_RMS = 0.02;      // a voice at phone distance sits well above; room tone below
	var SPEECH_SAMPLES = 2;     // two speech-shaped 100 ms reads before it counts (a hand shift is one)
	var SPEECH_CREST_MAX = 6;   // peak ÷ RMS within one read: voiced speech runs 3–5, a click spikes past 8
	// Safari (iPhone) records audio/mp4; Chrome (Android/desktop) webm+opus.
	var MIME_CANDIDATES = ['audio/mp4', 'audio/webm;codecs=opus', 'audio/webm'];
	// A 46-byte silent WAV. Playing it INSIDE the mic-tap gesture is what lets
	// iOS play later, programmatic audio on the same element.
	var SILENCE = 'data:audio/wav;base64,UklGRiYAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQIAAAAAAA==';

	function supported() {
		return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
	}

	// A site can forbid the microphone for every visitor with a Permissions-Policy
	// header (security hardening does it by default); Chrome then refuses without
	// asking, which read as "permission denied" on two phones (2026-09-07, a3rev.com).
	// false = the site itself blocks it; true = allowed, or the browser can't say.
	function micAllowedBySite() {
		var policy = document.featurePolicy || document.permissionsPolicy;
		try { return !policy || policy.allowsFeature('microphone') !== false; } catch (e) { return true; }
	}

	// One AudioContext for the page, made and started inside the first mic tap (a
	// context made outside a gesture never runs on iOS). The recorder's analyser
	// hangs off it and the employee's voice plays THROUGH it — the way a phone
	// plays clip after clip on a single gesture; an <audio> element let the first
	// clip through and refused the rest (7.7.3 canary). It stays running for the
	// page: only a live mic capture, stopped before a reply plays, affects routing.
	var audioCtx = null;
	// What the phone did, for a canary to read back (long-press the Voice button).
	var voiceLog = [];
	function log(event) {
		var t = new Date();
		voiceLog.push(('0' + t.getMinutes()).slice(-2) + ':' + ('0' + t.getSeconds()).slice(-2) + ' ' + event);
		if (voiceLog.length > 40) voiceLog.shift();
	}
	function ensureContext() {
		var Ctx = window.AudioContext || window.webkitAudioContext;
		if (audioCtx && audioCtx.state === 'closed') { log('context closed'); audioCtx = null; }   // the browser shut it: a new one on this tap
		if (audioCtx || !Ctx) return audioCtx;
		try {
			audioCtx = new Ctx();
			log('context ' + audioCtx.state);
		} catch (e) {
			audioCtx = null;
			log('context failed ' + ((e && e.name) || ''));
		}
		return audioCtx;
	}
	// Resolves true when the context runs. Outside a gesture a phone may refuse,
	// or never answer — the race keeps a caller from waiting on it forever, and a
	// refusal is remembered for ten seconds so a multi-line reply pays it once.
	var wakeRefusedAt = 0;
	async function wakeContext() {
		if (!audioCtx) return false;
		if (audioCtx.state !== 'running') {
			if (Date.now() - wakeRefusedAt < 10000) return false;
			try {
				await Promise.race([audioCtx.resume(), new Promise(function (r) { setTimeout(r, 1500); })]);
			} catch (e) { /* judged by state */ }
			log('context ' + audioCtx.state);
			if (audioCtx.state !== 'running') wakeRefusedAt = Date.now();
		}
		return audioCtx.state === 'running';
	}

	function pickMime() {
		for (var i = 0; i < MIME_CANDIDATES.length; i++) {
			if (MediaRecorder.isTypeSupported(MIME_CANDIDATES[i])) return MIME_CANDIDATES[i];
		}
		return '';
	}

	// The container alone: "audio/webm;codecs=opus" is MediaRecorder's spelling,
	// not a media type DEF keys on.
	function containerOf(mime) {
		return String(mime || '').split(';')[0].trim() || 'audio/webm';
	}

	function toBase64(blob) {
		return new Promise(function (resolve, reject) {
			var reader = new FileReader();
			reader.onload = function () { resolve(String(reader.result).split(',')[1] || ''); };
			reader.onerror = function () { reject(new Error('Could not read the recording')); };
			reader.readAsDataURL(blob);
		});
	}

	/**
	 * One recording at a time. The mic is taken in start() and dropped in onstop —
	 * before a word of the reply plays — and taken again next turn; the page's
	 * AudioContext is shared with the speaker. start() resolves true when
	 * recording began, false when one was already live or starting; it rejects
	 * when the browser refuses the mic, and can reject after the mic was granted
	 * (the recorder itself failing), so a caller release()s on rejection. stop()
	 * resolves {blob, mime, seconds, spoke} or null. release() drops the mic (the
	 * page's context stays). handlers: onTick(seconds), onAutoStop (the two-minute
	 * stop), onSilence (a pause after speech), onIdle (no speech at all).
	 */
	function createRecorder(handlers) {
		handlers = handlers || {};
		var stream = null, source = null, analyser = null, samples = null;
		var recorder = null, chunks = [], startedAt = 0, timer = null, autoStop = null, meter = null;
		var pending = null, starting = false, spokeAt = 0, quietSince = 0, loudRun = 0;

		function stopTimers() {
			if (timer) { clearInterval(timer); timer = null; }
			if (autoStop) { clearTimeout(autoStop); autoStop = null; }
			if (meter) { clearInterval(meter); meter = null; }
		}

		// The microphone is held only while a recording runs. An open mic puts an
		// iPhone's audio session into record mode — playback drops to the earpiece
		// and is ducked, which is how 7.7.1's readback went unheard — so it is
		// dropped the moment the recording stops and taken again for the next turn
		// (no re-prompt once granted). The AudioContext, unlocked by the tap, lives
		// for the whole conversation: one made outside a gesture never runs on iOS,
		// and the silence detector needs it on every turn.
		function stopCapture() {
			if (source) { try { source.disconnect(); } catch (e) { /* already gone */ } source = null; }
			if (stream) { stream.getTracks().forEach(function (track) { track.stop(); }); stream = null; }
		}

		function release() {
			stopTimers();
			recorder = null;
			stopCapture();
		}

		// The detector counts only while its context runs and the mic feeds it: a
		// suspended or interrupted context (iOS after the lock screen) reads zeros,
		// which would look like ten seconds of silence while the user is talking.
		function detecting() {
			return !!(source && audioCtx && audioCtx.state === 'running');
		}

		function ensureAnalyser() {
			if (!ensureContext()) return;
			if (analyser && analyser.context !== audioCtx) analyser = null;   // the context was replaced
			if (analyser) return;
			try {
				analyser = audioCtx.createAnalyser();
				analyser.fftSize = 1024;
				samples = new Float32Array(analyser.fftSize);
			} catch (e) {
				analyser = null;   // no silence detection: the tap still sends
			}
		}

		async function openCapture() {
			stopCapture();
			stream = await navigator.mediaDevices.getUserMedia({ audio: true });
			if (analyser) {
				try {
					source = audioCtx.createMediaStreamSource(stream);
					source.connect(analyser);
				} catch (e) {
					source = null;
				}
			}
		}

		// Loud is not spoken (7.8.7). A keystroke beside the phone is a transient: it
		// spikes a read's peak far above that read's own RMS, while a spoken word fills
		// the read at a peak near its RMS — which is what "typing kept the mic listening"
		// was missing (Steve's hands-free canary).
		function isSpeech() {
			analyser.getFloatTimeDomainData(samples);
			var sum = 0, peak = 0, v;
			for (var i = 0; i < samples.length; i++) {
				v = samples[i];
				sum += v * v;
				if (v > peak) peak = v; else if (-v > peak) peak = -v;
			}
			var rms = Math.sqrt(sum / samples.length);
			return rms > SPEECH_RMS && peak < rms * SPEECH_CREST_MAX;
		}

		function watch() {
			if (!detecting()) return;
			var now = Date.now();
			if (isSpeech()) {
				// Speech is sustained; a phone shifting in the hand is one loud sample.
				loudRun += 1;
				if (loudRun >= SPEECH_SAMPLES) { spokeAt = now; quietSince = 0; }
				return;
			}
			if (loudRun) loudRun -= 1;   // decays, so a stop consonant mid-word does not reset it
			if (spokeAt) {
				if (!quietSince) quietSince = now;
				if (now - quietSince >= SILENCE_MS && handlers.onSilence) { stopTimers(); handlers.onSilence(); }
			} else if (now - startedAt >= IDLE_MS && handlers.onIdle) {
				stopTimers();
				handlers.onIdle();
			}
		}

		async function start() {
			if (recorder || starting) return false;
			starting = true;
			try {
				ensureAnalyser();
				// A mic with no silence or idle stop is not opened blind: the caller
				// shows "tap to speak again", and the tap wakes the context.
				if (audioCtx && !(await wakeContext())) throw new Error('Silence detection unavailable until the next tap');
				await openCapture();
				log('mic open');
			} finally {
				starting = false;
			}
			var mime = pickMime();
			var rec;
			try {
				rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
			} catch (e) {
				stopCapture();   // the module's own promise: no recorder, no open mic
				throw e;
			}
			recorder = rec;
			chunks = [];
			spokeAt = 0;
			quietSince = 0;
			loudRun = 0;
			rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
			rec.onstop = function () {
				if (recorder !== rec) return;   // release() already tore this one down
				var seconds = (Date.now() - startedAt) / 1000;
				var type = containerOf(rec.mimeType || mime);
				var resolve = pending;
				pending = null;
				stopTimers();
				recorder = null;
				// No live detector → assume speech: the tap decides.
				var spoke = !!spokeAt || !detecting();
				stopCapture();   // the mic is off before a word of the reply plays
				log('mic off ' + seconds.toFixed(1) + 's' + (spoke ? '' : ' no speech'));
				if (resolve) resolve({ blob: new Blob(chunks, { type: type }), mime: type, seconds: seconds, spoke: spoke });
			};
			startedAt = Date.now();
			rec.start();
			timer = setInterval(function () {
				if (handlers.onTick) handlers.onTick(Math.floor((Date.now() - startedAt) / 1000));
			}, 500);
			meter = setInterval(watch, 100);
			autoStop = setTimeout(function () { if (handlers.onAutoStop) handlers.onAutoStop(); }, MAX_RECORD_MS);
			return true;
		}

		function stop() {
			return new Promise(function (resolve) {
				if (!recorder || recorder.state === 'inactive') { stopTimers(); recorder = null; stopCapture(); resolve(null); return; }
				pending = resolve;
				recorder.stop();
			});
		}

		return {
			start: start,
			stop: stop,
			release: release,
			isRecording: function () { return !!recorder; }
		};
	}

	/**
	 * Readback. mode 'server' = the employee's own voice (audio frames the chat
	 * stream carries), 'device' = speechSynthesis from the reply's text, 'off'.
	 * speak(text, audio) queues one line: server audio when it came and the mode
	 * is server, the device voice otherwise (a line whose synthesis failed still
	 * gets read). Lines play in order; whenIdle() resolves once nothing is left —
	 * hands-free listens again on that. unlock() must run inside the user's
	 * gesture — the mic tap — because iOS starts audio only from one.
	 * handlers.onError(e) hears every line that failed to play (the browser's
	 * own error), so a consumer can put the reason on screen.
	 */
	function createSpeaker(handlers) {
		handlers = handlers || {};
		var mode = 'server', audio = null, queue = [], busy = false, idleWaiters = [], playing = null;

		function unlock() {
			// Inside the tap: the page's context starts here, and stays running.
			if (ensureContext() && audioCtx.state !== 'running') {
				var note = function () { log('context ' + audioCtx.state); };
				try { audioCtx.resume().then(note, note); } catch (e) { /* judged at play */ }
			}
			// The element fallback needs its own unlock: one gesture-time play.
			if (!audio) {
				audio = new Audio(SILENCE);
				audio.play().catch(function () {});
			}
			if (window.speechSynthesis && !speechSynthesis.speaking) {
				try { speechSynthesis.speak(new SpeechSynthesisUtterance('')); } catch (e) { /* no device voice */ }
			}
			log('unlock');
		}

		function playDevice(text) {
			return new Promise(function (resolve) {
				if (!window.speechSynthesis) { resolve(); return; }
				var utterance = new SpeechSynthesisUtterance(text);
				// The device's own language first: a Vietnamese phone reads a Vietnamese
				// reply in a Vietnamese voice, whatever language the page is served in.
				// (The reply's own language would be the right signal; it isn't known here,
				// so the device's language is the better guess of the two.)
				utterance.lang = navigator.language || document.documentElement.lang;
				utterance.onend = resolve;
				utterance.onerror = resolve;
				speechSynthesis.speak(utterance);
			});
		}

		function decodeBytes(base64) {
			var raw = atob(base64 || '');
			var buf = new Uint8Array(raw.length);
			for (var i = 0; i < raw.length; i++) buf[i] = raw.charCodeAt(i);
			return buf;
		}

		// Through the page's context (a buffer source: starts whenever the context
		// runs, clip after clip, no gesture per clip); the <audio> element only when
		// there is no running context.
		async function playThroughContext(bytes) {
			var buffer = await new Promise(function (resolve, reject) {
				var result = audioCtx.decodeAudioData(bytes.buffer.slice(0), resolve, reject);   // Safari: callbacks
				if (result && result.then) result.then(resolve, reject);
			});
			log('play ' + buffer.duration.toFixed(1) + 's');
			await new Promise(function (resolve) {
				var node = audioCtx.createBufferSource();
				node.buffer = buffer;
				node.connect(audioCtx.destination);
				node.onended = function () { if (playing === node) playing = null; node.onended = null; resolve(); };
				playing = node;
				node.start();
			});
			log('played');
		}

		async function playElement(bytes, mime) {
			var url = URL.createObjectURL(new Blob([bytes], { type: mime || 'audio/mpeg' }));
			if (!audio) audio = new Audio();
			log('element play');
			try {
				await new Promise(function (resolve, reject) {
					audio.onended = resolve;
					audio.onpause = resolve;   // stop() pauses; a natural end fires pause too
					audio.onerror = function () { reject(new Error('Playback failed')); };
					audio.src = url;
					audio.play().catch(reject);
				});
			} finally {
				URL.revokeObjectURL(url);
			}
		}

		async function playServer(base64, mime) {
			var bytes = decodeBytes(base64);
			if (audioCtx && await wakeContext()) {
				try {
					return await playThroughContext(bytes);
				} catch (e) {
					log('context play failed ' + ((e && e.name) || ''));   // a decode the device refused
				}
			}
			return playElement(bytes, mime);
		}

		function settleIdle() {
			var waiters = idleWaiters;
			idleWaiters = [];
			waiters.forEach(function (resolve) { resolve(); });
		}

		async function pump() {
			if (busy) return;
			busy = true;
			while (queue.length) {
				var item = queue.shift();
				try {
					if (mode === 'server' && item.audio) await playServer(item.audio.base64, item.audio.mime);
					else await playDevice(item.text);
				} catch (e) {
					console.warn('[DEF voice] readback skipped:', e);
					log('play failed ' + ((e && (e.name !== 'Error' && e.name)) || (e && e.message) || ''));
					if (handlers.onError) handlers.onError(e);   // the phone's own reason, on screen
				}
			}
			busy = false;
			settleIdle();
		}

		return {
			setMode: function (next) { mode = next; },
			getMode: function () { return mode; },
			unlock: unlock,
			speak: function (text, audio) {
				if (mode === 'off' || (!text && !audio)) return;
				queue.push({ text: text, audio: audio || null });
				pump();
			},
			whenIdle: function () {
				return new Promise(function (resolve) {
					if (!busy && !queue.length) resolve(); else idleWaiters.push(resolve);
				});
			},
			stop: function () {
				queue = [];
				if (playing) {
					// Settled here, not by `ended`: a suspended context (lock screen, a call)
					// fires no `ended` for a stopped node, and busy would never clear.
					var node = playing;
					playing = null;
					try { node.stop(); } catch (e) { /* already ended */ }
					log('stopped');
					if (node.onended) node.onended();
				}
				if (audio) { try { audio.pause(); } catch (e) { /* never started */ } }
				if (window.speechSynthesis) speechSynthesis.cancel();
			}
		};
	}

	// Markdown → speakable prose (the device-voice path; DEF applies the same rules
	// server-side for the employee's voice).
	function plain(text) {
		return String(text || '')
			.replace(/```[\s\S]*?```/g, ' ')
			.replace(/`([^`]*)`/g, '$1')
			.replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
			.replace(/[*_#>|]+/g, '')
			.replace(/^\s*(?:[-*+]|\d+[.)])\s+/gm, '')
			.replace(/\s+/g, ' ')
			.trim();
	}

	// The opening sentence — the first prefix of at least three words that ends at
	// a sentence boundary — or null while it is still arriving. The boundary needs
	// the whitespace AFTER it: a chunk ending "costs 3." is not a sentence until
	// the next chunk says whether "5 million" follows. Callers holding the
	// finished text append a space.
	function firstSentence(buffer) {
		var text = String(buffer || '');
		var boundary = /[.!?](?=\s)/g;
		var match;
		while ((match = boundary.exec(text))) {
			var sentence = plain(text.slice(0, match.index + 1));
			if (sentence.split(' ').length >= 3) return sentence;
		}
		return null;
	}

	// Index just past the sentence boundary that produced `opening` in the raw
	// (markdown) text — the first boundary at which the plain text so far equals
	// it — so the device voice can cut the opening out and read on from there.
	function boundaryEnd(text, opening) {
		var raw = String(text || '');
		var boundary = /[.!?](?=\s)/g, match;
		while ((match = boundary.exec(raw))) {
			if (plain(raw.slice(0, match.index + 1)) === opening) return match.index + 1;
		}
		return raw.length;
	}

	// Bare words: what was said, without the case and punctuation the transcriber
	// chose. An apostrophe holds a word together (however it was typed), anything
	// else separates — so "That's all." and "thats all" reduce to the same thing.
	function bareWords(text) {
		return String(text || '').toLowerCase().replace(/['‘’ʼ]/g, '')
			.replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
	}

	// A spoken stop (V-S6b). `phrases` is one i18n string of phrases, so the ones a
	// language actually says are a translation and not a code change (its separator
	// may be a CJK comma). The employee's own name can sit anywhere among them —
	// "Sue, stop", "Stop, Sue", "Thanks Sue, that's all" — so it is dropped as a word.
	// The WHOLE transcript must be the phrase: "stop the newsletter" is an instruction.
	function isStopPhrase(transcript, phrases, name) {
		var said = bareWords(transcript);
		if (!said) return false;
		var who = bareWords(name).split(' ')[0];
		if (who) said = said.split(' ').filter(function (word) { return word !== who; }).join(' ');
		return String(phrases || '').split(/[,、，]/).some(function (phrase) {
			var want = bareWords(phrase);
			return !!want && want === said;
		});
	}

	return {
		supported: supported,
		micAllowedBySite: micAllowedBySite,
		isStopPhrase: isStopPhrase,
		createRecorder: createRecorder,
		createSpeaker: createSpeaker,
		toBase64: toBase64,
		plain: plain,
		firstSentence: firstSentence,
		boundaryEnd: boundaryEnd,
		log: function () { return voiceLog.slice(); },
		MAX_RECORD_MS: MAX_RECORD_MS
	};
})();
