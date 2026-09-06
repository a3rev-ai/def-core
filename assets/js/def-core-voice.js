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
	var SPEECH_RMS = 0.02;   // a voice at phone distance sits well above; room tone below
	// Safari (iPhone) records audio/mp4; Chrome (Android/desktop) webm+opus.
	var MIME_CANDIDATES = ['audio/mp4', 'audio/webm;codecs=opus', 'audio/webm'];
	// A 46-byte silent WAV. Playing it INSIDE the mic-tap gesture is what lets
	// iOS play later, programmatic audio on the same element.
	var SILENCE = 'data:audio/wav;base64,UklGRiYAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQIAAAAAAA==';

	function supported() {
		return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
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
	 * One recording at a time on a mic stream that stays open for a whole
	 * conversation (reopening it per turn would re-prompt on some phones).
	 * start() resolves true when recording began, false when one was already live
	 * or starting; rejects when the browser refuses the mic. stop() resolves
	 * {blob, mime, seconds, spoke} or null. release() closes the stream.
	 * handlers: onTick(seconds), onAutoStop (the two-minute stop), onSilence (a
	 * pause after speech), onIdle (no speech at all).
	 */
	function createRecorder(handlers) {
		handlers = handlers || {};
		var stream = null, audioCtx = null, analyser = null, samples = null;
		var recorder = null, chunks = [], startedAt = 0, timer = null, autoStop = null, meter = null;
		var pending = null, starting = false, spokeAt = 0, quietSince = 0;

		function stopTimers() {
			if (timer) { clearInterval(timer); timer = null; }
			if (autoStop) { clearTimeout(autoStop); autoStop = null; }
			if (meter) { clearInterval(meter); meter = null; }
		}

		function release() {
			stopTimers();
			recorder = null;
			if (audioCtx) { try { audioCtx.close(); } catch (e) { /* already closed */ } audioCtx = null; analyser = null; }
			if (stream) { stream.getTracks().forEach(function (track) { track.stop(); }); stream = null; }
		}

		// The detector counts only while its context runs: a suspended or
		// interrupted context (iOS after the lock screen) reads zeros, which would
		// look like ten seconds of silence while the user is talking.
		function detecting() {
			return !!(analyser && audioCtx && audioCtx.state === 'running');
		}

		async function openStream() {
			if (stream && stream.active) return;
			release();   // a dead stream's context goes with it — never two contexts
			stream = await navigator.mediaDevices.getUserMedia({ audio: true });
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if (!Ctx) return;
			try {
				audioCtx = new Ctx();
				analyser = audioCtx.createAnalyser();
				analyser.fftSize = 1024;
				audioCtx.createMediaStreamSource(stream).connect(analyser);
				samples = new Float32Array(analyser.fftSize);
			} catch (e) {
				analyser = null;   // no silence detection: the tap still sends
			}
		}

		function rms() {
			analyser.getFloatTimeDomainData(samples);
			var sum = 0;
			for (var i = 0; i < samples.length; i++) sum += samples[i] * samples[i];
			return Math.sqrt(sum / samples.length);
		}

		function watch() {
			if (!detecting()) return;
			var now = Date.now();
			if (rms() > SPEECH_RMS) {
				spokeAt = now;
				quietSince = 0;
			} else if (spokeAt) {
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
				await openStream();
				if (audioCtx && audioCtx.state !== 'running') { try { await audioCtx.resume(); } catch (e) { /* the tap decides */ } }
			} finally {
				starting = false;
			}
			var mime = pickMime();
			var rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
			recorder = rec;
			chunks = [];
			spokeAt = 0;
			quietSince = 0;
			rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
			rec.onstop = function () {
				var seconds = (Date.now() - startedAt) / 1000;
				var type = containerOf(rec.mimeType || mime);
				var resolve = pending;
				pending = null;
				stopTimers();
				recorder = null;
				// No live detector → assume speech: the tap decides.
				var spoke = !!spokeAt || !detecting();
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
				if (!recorder || recorder.state === 'inactive') { stopTimers(); recorder = null; resolve(null); return; }
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
	 */
	function createSpeaker() {
		var mode = 'server', audio = null, queue = [], busy = false, idleWaiters = [];

		function unlock() {
			if (!audio) {
				audio = new Audio(SILENCE);
				audio.play().catch(function () {});
			}
			if (window.speechSynthesis && !speechSynthesis.speaking) {
				try { speechSynthesis.speak(new SpeechSynthesisUtterance('')); } catch (e) { /* no device voice */ }
			}
		}

		function playDevice(text) {
			return new Promise(function (resolve) {
				if (!window.speechSynthesis) { resolve(); return; }
				var utterance = new SpeechSynthesisUtterance(text);
				utterance.lang = document.documentElement.lang || navigator.language;
				utterance.onend = resolve;
				utterance.onerror = resolve;
				speechSynthesis.speak(utterance);
			});
		}

		async function playServer(base64, mime) {
			var raw = atob(base64 || '');
			var buf = new Uint8Array(raw.length);
			for (var i = 0; i < raw.length; i++) buf[i] = raw.charCodeAt(i);
			var url = URL.createObjectURL(new Blob([buf], { type: mime || 'audio/mpeg' }));
			if (!audio) audio = new Audio();
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

	// The closing line: the last sentence of the reply's last non-empty line.
	function closingLine(text) {
		// Fences go first: a reply that ends in a code block must not read "```".
		var lines = String(text || '').replace(/```[\s\S]*?```/g, '\n').split(/\r?\n/).map(plain).filter(Boolean);
		if (!lines.length) return '';
		var last = lines[lines.length - 1];
		var boundary = /[.!?](?=\s)/g, cut = 0, match;
		while ((match = boundary.exec(last))) cut = match.index + 1;
		return last.slice(cut).trim() || last;
	}

	return {
		supported: supported,
		createRecorder: createRecorder,
		createSpeaker: createSpeaker,
		toBase64: toBase64,
		firstSentence: firstSentence,
		closingLine: closingLine,
		MAX_RECORD_MS: MAX_RECORD_MS
	};
})();
