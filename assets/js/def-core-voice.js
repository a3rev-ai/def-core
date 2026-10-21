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
	// A streamed line's parts: raw 16-bit mono PCM (DEF's SPEECH_STREAM_MIME).
	var PCM_MIME = /^audio\/pcm\b/;
	var PCM_RATE = 24000;
	// Live transcription (W75): after the pause, the transcript is waited for this long —
	// the batch transcription it replaces (1.7 s, 2026-10-19), so a stalled socket costs
	// at most that before the clip goes instead.
	var LIVE_FINAL_WAIT_MS = 1700;
	// DEF closed a live socket with 4415 (this employee's voice has no live transcriber):
	// the page sends clips from then on.
	var liveUnsupported = false;

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
	// A context the page left behind is not trusted: iOS can hand the page back with it
	// saying 'running' and rendering nothing — no node ends, the analyser reads zeros, so
	// every tap after the return closed the mic on "silence" (Joe on widrow.ai, 2026-10-20;
	// WebKit bugs 276016, 276687). The first tap after a hide makes a new one, as a reload would.
	var awayCtx = null;
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') awayCtx = audioCtx;
	});
	function ensureContext() {
		var Ctx = window.AudioContext || window.webkitAudioContext;
		if (audioCtx && audioCtx.state === 'closed') { log('context closed'); audioCtx = null; }   // the browser shut it: a new one on this tap
		if (audioCtx && audioCtx === awayCtx) {
			try { audioCtx.close().catch(function () {}); } catch (e) { /* replaced either way */ }
			log('context replaced');
			audioCtx = null;
		}
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

	// The mic at the context's rate as 16-bit little-endian mono PCM at 24 kHz, the wire
	// format DEF relays (its SPEECH_STREAM_MIME). The nearest sample: speech keeps its words.
	function pcm24k(input, rate) {
		var step = rate / PCM_RATE, n = Math.floor(input.length / step);
		var view = new DataView(new ArrayBuffer(n * 2));
		for (var i = 0; i < n; i++) {
			var v = Math.max(-1, Math.min(1, input[Math.floor(i * step)]));
			view.setInt16(2 * i, v < 0 ? v * 32768 : v * 32767, true);
		}
		return view.buffer;
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
	 *
	 * start(live), live = {url, token, thread, context} (W75): the recording also streams
	 * to DEF over one socket as it is spoken, and stop() waits up to LIVE_FINAL_WAIT_MS
	 * for the transcript, resolving with `transcript` beside the clip when one came. The
	 * clip is always recorded: any failure of the socket leaves it as the turn.
	 */
	function createRecorder(handlers) {
		handlers = handlers || {};
		var stream = null, source = null, analyser = null, samples = null;
		var recorder = null, chunks = [], startedAt = 0, timer = null, autoStop = null, meter = null;
		var pending = null, starting = false, spokeAt = 0, quietSince = 0, loudRun = 0;
		var link = null;   // the live socket and its tap on the mic (W75)

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
			if (link) closeLive(link);
			stopCapture();
		}

		// The token rides as a subprotocol (DEF echoes "def-live"), never in the URL. Frames
		// spoken before the socket opens wait for it, behind the `start` that opens the turn.
		function openLive(live) {
			var queued = [JSON.stringify({ type: 'start', context: live.context || '' })];
			var open = { transcript: undefined }, settle;
			open.settled = new Promise(function (resolve) { settle = resolve; });
			try {
				open.ws = new WebSocket(live.url + '?thread=' + encodeURIComponent(live.thread || ''), ['def-live', live.token]);
				open.tap = audioCtx.createScriptProcessor(4096, 1, 1);
			} catch (e) {
				if (open.ws) open.ws.close();
				log('live failed');
				return null;
			}
			var ws = open.ws;
			open.send = function (data) {
				if (ws.readyState === 1) ws.send(data);
				else if (ws.readyState === 0) queued.push(data);
			};
			ws.binaryType = 'arraybuffer';
			ws.onopen = function () {
				log('live open');
				queued.forEach(function (data) { ws.send(data); });
				queued = [];
			};
			ws.onmessage = function (e) {
				var msg = null;
				try { msg = JSON.parse(e.data); } catch (x) { /* not a frame DEF sends */ }
				// A final with no words is no transcript: the clip gets the batch transcriber's look.
				if (msg && msg.type === 'final' && msg.text) open.transcript = String(msg.text);
				if (msg && (msg.type === 'final' || msg.type === 'error')) settle();
			};
			ws.onclose = function (e) {
				if (e.code === 4415) liveUnsupported = true;
				settle();
			};
			open.tap.onaudioprocess = function (e) { open.send(pcm24k(e.inputBuffer.getChannelData(0), audioCtx.sampleRate)); };
			source.connect(open.tap);
			open.tap.connect(audioCtx.destination);   // a ScriptProcessor runs only while it feeds the output; it writes silence
			return open;
		}

		function closeLive(open) {
			try { open.tap.disconnect(); } catch (e) { /* already gone */ }
			open.tap.onaudioprocess = null;
			open.ws.close();
			if (link === open) link = null;
		}

		// The pause: `end`, then the transcript, or nothing once LIVE_FINAL_WAIT_MS has run.
		async function finishLive(open) {
			try { open.tap.disconnect(); } catch (e) { /* already gone */ }
			open.send(JSON.stringify({ type: 'end' }));
			var offAt = Date.now();
			await Promise.race([open.settled, new Promise(function (r) { setTimeout(r, LIVE_FINAL_WAIT_MS); })]);
			closeLive(open);
			log(open.transcript === undefined ? 'live failed' : 'live final ' + ((Date.now() - offAt) / 1000).toFixed(2) + 's');
			return open.transcript;
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

		async function start(live) {
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
				var result = { blob: new Blob(chunks, { type: type }), mime: type, seconds: seconds, spoke: spoke };
				var open = link;
				if (open && !spoke) closeLive(open);
				stopCapture();   // the mic is off before a word of the reply plays
				log('mic off ' + seconds.toFixed(1) + 's' + (spoke ? '' : ' no speech'));
				if (!open || !spoke) { if (resolve) resolve(result); return; }
				finishLive(open).then(function (transcript) {
					if (transcript !== undefined) result.transcript = transcript;
					if (resolve) resolve(result);
				});
			};
			startedAt = Date.now();
			rec.start();
			if (live && source && !liveUnsupported) link = openLive(live);
			timer = setInterval(function () {
				if (handlers.onTick) handlers.onTick(Math.floor((Date.now() - startedAt) / 1000));
			}, 500);
			meter = setInterval(watch, 100);
			autoStop = setTimeout(function () { if (handlers.onAutoStop) handlers.onAutoStop(); }, MAX_RECORD_MS);
			return true;
		}

		function stop() {
			return new Promise(function (resolve) {
				if (!recorder || recorder.state === 'inactive') { release(); resolve(null); return; }
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

	// Raw 16-bit mono PCM in a WAV header, so the <audio> element can play it
	// (the shape of DEF's wav_from_pcm16).
	function wavFromPcm16(pcm, rate) {
		var wav = new Uint8Array(44 + pcm.length), view = new DataView(wav.buffer);
		var text = function (at, s) { for (var i = 0; i < s.length; i++) wav[at + i] = s.charCodeAt(i); };
		text(0, 'RIFF'); view.setUint32(4, 36 + pcm.length, true); text(8, 'WAVE');
		text(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
		view.setUint32(24, rate, true); view.setUint32(28, rate * 2, true); view.setUint16(32, 2, true);
		view.setUint16(34, 16, true);
		text(36, 'data'); view.setUint32(40, pcm.length, true);
		wav.set(pcm, 44);
		return wav;
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
		var mode = 'server', audio = null, queue = [], busy = false, idleWaiters = [], playing = [], current = null;

		function unlock() {
			// Inside the tap: the page's context starts here, and stays running.
			if (ensureContext() && audioCtx.state !== 'running') {
				var note = function () { log('context ' + audioCtx.state); };
				try { audioCtx.resume().then(note, note); } catch (e) { /* judged at play */ }
			}
			// Half a second of silence through the context, inside the tap: a Bluetooth
			// headset wakes its link on the first sound and drops what plays meanwhile —
			// without this, the first word of the first reply (V-S8, 2026-10-19).
			if (audioCtx) {
				try { startBuffer(silence(0.5)); log('warm'); } catch (e) { /* judged at play */ }
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

		function silence(seconds) {
			return audioCtx.createBuffer(1, Math.round(audioCtx.sampleRate * seconds), audioCtx.sampleRate);
		}

		// One buffer through the context, now or at `when`; resolves when it ends or
		// stop() cuts it.
		function startBuffer(buffer, when) {
			return new Promise(function (resolve) {
				var node = audioCtx.createBufferSource();
				node.buffer = buffer;
				node.connect(audioCtx.destination);
				node.onended = function () {
					var i = playing.indexOf(node);
					if (i >= 0) playing.splice(i, 1);
					node.onended = null;
					resolve();
				};
				playing.push(node);
				node.start(when || 0);
			});
		}

		// A streamed part: 16-bit little-endian mono PCM at 24 kHz, as DEF sends it.
		function pcmBuffer(bytes) {
			var view = new DataView(bytes.buffer), n = bytes.length >> 1;
			var buffer = audioCtx.createBuffer(1, n, PCM_RATE), data = buffer.getChannelData(0);
			for (var i = 0; i < n; i++) data[i] = view.getInt16(2 * i, true) / 32768;
			return buffer;
		}

		function partLanded(item) {
			return new Promise(function (resolve) {
				item.wake = function () { item.wake = null; resolve(); };
			});
		}

		// Seconds of 24 kHz 16-bit audio in a line's parts so far.
		function heldSeconds(item) {
			return item.parts.reduce(function (s, p) { return s + atob(p || '').length; }, 0) / 2 / PCM_RATE;
		}

		// A streamed line: each part starts the moment the one before it ends (scheduled
		// on the context's clock, so there is no gap), as soon as it lands. The line ends
		// once its closing frame has come and its last part has ended. It starts once
		// 300 ms of it is here, or all of it: begun on one 100 ms part, every part that
		// lands late is a gap in its first words (Joe on widrow.ai, 2026-10-20).
		async function playStream(item) {
			if (!(audioCtx && await wakeContext())) {
				// The element plays whole files only: the line once it is all here.
				while (!item.last && !item.cut) await partLanded(item);
				if (item.cut) return;
				var chunks = item.parts.map(decodeBytes), total = 0, at = 0;
				chunks.forEach(function (c) { total += c.length; });
				var pcm = new Uint8Array(total);
				chunks.forEach(function (c) { pcm.set(c, at); at += c.length; });
				return playElement(wavFromPcm16(pcm, PCM_RATE), 'audio/wav');
			}
			while (!item.last && !item.cut && heldSeconds(item) < 0.3) await partLanded(item);
			log('play stream');
			var nextAt = 0, ended = null, i = 0;
			while (!item.cut) {
				if (i < item.parts.length) {
					var buffer = pcmBuffer(decodeBytes(item.parts[i++]));
					var when = Math.max(nextAt, audioCtx.currentTime + 0.05);
					ended = startBuffer(buffer, when);
					nextAt = when + buffer.duration;
				} else if (item.last) {
					break;
				} else {
					await partLanded(item);
				}
			}
			await ended;
			log('played');
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
			await startBuffer(buffer);
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
			var first = true;
			while (queue.length) {
				var item = queue.shift();
				current = item;
				var line = item.audio;
				// A reply's first clip gets 300 ms of silence ahead of it, so a headset that
				// dozed since the last reply wakes on nothing rather than on its first word.
				// The first clip is seq 1, never a later segment that finds the queue run dry
				// because the reply's audio trailed its playback. A line with no seq: the
				// first after idle, as before.
				if (mode === 'server' && line && (line.seq === undefined ? first : line.seq === 1) &&
						audioCtx && await wakeContext()) {
					try { log('lead-in'); await startBuffer(silence(0.3)); } catch (e) { /* the clip still plays */ }
				}
				first = false;
				if (item.cut) continue;   // stop() came during the lead-in
				try {
					if (mode === 'server' && line && PCM_MIME.test(line.mime || '')) await playStream(item);
					else if (mode === 'server' && line) await playServer(line.base64, line.mime);
					else await playDevice(item.text);
				} catch (e) {
					console.warn('[DEF voice] readback skipped:', e);
					log('play failed ' + ((e && (e.name !== 'Error' && e.name)) || (e && e.message) || ''));
					if (handlers.onError) handlers.onError(e);   // the phone's own reason, on screen
				}
				if (current === item) current = null;
			}
			busy = false;
			settleIdle();
		}

		// The line a later part belongs to: the newest queued or playing line of that
		// seq still open. None once stop() ended it.
		function openLine(seq) {
			var items = [current].concat(queue);
			for (var i = items.length - 1; i >= 0; i--) {
				if (items[i] && items[i].audio && items[i].audio.seq === seq && !items[i].last) return items[i];
			}
			return null;
		}

		return {
			setMode: function (next) { mode = next; },
			getMode: function () { return mode; },
			unlock: unlock,
			// audio may carry seq/part/last (a streamed line); a missing part is 0 and a
			// missing last is true. A later part joins its line; a closing frame (last,
			// no audio) closes it.
			speak: function (text, audio) {
				if (mode === 'off' || (!text && !audio)) return;
				if (audio && audio.part > 0) {
					var line = openLine(audio.seq);
					if (!line) return;
					if (audio.base64) line.parts.push(audio.base64);
					if (audio.last !== false) line.last = true;
					if (line.wake) line.wake();
					return;
				}
				queue.push({ text: text, audio: audio || null, parts: audio ? [audio.base64] : [],
					last: !audio || audio.last !== false });
				pump();
			},
			// The stream is over: a line whose closing frame never came plays the parts it
			// has and finishes, so whenIdle still resolves.
			end: function () {
				[current].concat(queue).forEach(function (item) {
					if (!item || item.last) return;
					item.last = true;
					if (item.wake) item.wake();
				});
			},
			whenIdle: function () {
				return new Promise(function (resolve) {
					if (!busy && !queue.length) resolve(); else idleWaiters.push(resolve);
				});
			},
			stop: function () {
				queue = [];
				if (current) {
					// A streamed line waiting on its next part ends here; parts still to come are dropped.
					current.cut = true;
					if (current.wake) current.wake();
					current = null;
				}
				if (playing.length) {
					// Settled here, not by `ended`: a suspended context (lock screen, a call)
					// fires no `ended` for a stopped node, and busy would never clear.
					var nodes = playing;
					playing = [];
					nodes.forEach(function (node) {
						try { node.stop(); } catch (e) { /* already ended */ }
						if (node.onended) node.onended();
					});
					log('stopped');
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

	// A spoken stop MUST NAME THE EMPLOYEE — "Sue, stop", not "stop" (Steve, 2026-09-10).
	// The Siri rule: you say "Siri, stop". `phrases` is one i18n string, so the words a
	// language actually says are a translation and not a code change (its separator may
	// be a CJK comma). The name leads or trails — "Sue, stop", "Stop, Sue", "Thanks,
	// that's all, Sue" — and the WHOLE transcript must be the phrase, so "stop the
	// newsletter" is still an instruction.
	//
	// Why the name is required: a bare "stop" is a one-word clip, the worst case for the
	// transcriber, and 7.7.9 transcribes short clips WITH the conversation as context.
	// Seven real stop attempts on 2026-09-10 produced transcripts of 3 to 85 characters
	// and not one was the word "stop" — so the stop never matched and the employee
	// answered it. Naming her gives the transcriber two words and a proper noun, and the
	// vendor hint already carries her name, so this agrees with what it will emit.
	// MUST stay identical to DEF's is_stop_phrase (app/shared/voice.py) — if the two
	// halves disagree, one side ends the session while the other answers the turn.
	function isStopPhrase(transcript, phrases, name) {
		var said = bareWords(transcript);
		if (!said) return false;
		var who = bareWords(name).split(' ')[0];
		if (who) {
			var words = said.split(' ');
			if (words[0] === who) words = words.slice(1);
			else if (words[words.length - 1] === who) words = words.slice(0, -1);
			else return false;   // the name is mandatory — a bare phrase is a message
			said = words.join(' ');
		}
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
		// A widget's own line on the same log: each chat stream's send → headers time (W74).
		note: log,
		MAX_RECORD_MS: MAX_RECORD_MS
	};
})();
