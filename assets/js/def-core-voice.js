/**
 * DEF Core — voice (7.7.0). One module for both chat surfaces: the mic
 * (record → the upload rail → DEF transcribes and discards) and the readback
 * (the employee's own voice from DEF, or the device voice). The Staff-AI
 * console consumes it now; Customer Chat consumes the same object when its
 * employee's Voice switch is on. Consumers hand in `api.request(endpoint,
 * options)` — their own BFF client, resolving parsed JSON and throwing
 * Error{status} — so this file knows no channel and no URL.
 */
window.DefVoice = (function () {
	'use strict';

	// Recording stops itself here — a UX stop, not a cap: a two-minute
	// instruction is a memo. DEF's transcription bound (25 MB) sits far above.
	var MAX_RECORD_MS = 120000;
	// Safari (iPhone) records audio/mp4; Chrome (Android/desktop) webm+opus.
	var MIME_CANDIDATES = ['audio/mp4', 'audio/webm;codecs=opus', 'audio/webm'];
	// A 46-byte silent WAV. Playing it INSIDE the mic-tap gesture is what
	// lets iOS play later, programmatic audio on the same element.
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

	// The container alone: "audio/webm;codecs=opus" is MediaRecorder's
	// spelling, not a media type the upload rail or OpenAI key on.
	function containerOf(mime) {
		return String(mime || '').split(';')[0].trim() || 'audio/webm';
	}

	function extensionFor(mime) {
		return containerOf(mime) === 'audio/mp4' ? 'm4a' : 'webm';
	}

	/**
	 * One recording at a time. start() resolves once the mic is live (rejects
	 * when the browser refuses it); stop() resolves {blob, mime, seconds} or
	 * null when nothing was recording; cancel() discards. handlers.onTick
	 * (whole seconds) drives a timer; handlers.onAutoStop fires at the
	 * two-minute stop so the consumer runs its normal stop path.
	 */
	function createRecorder(handlers) {
		handlers = handlers || {};
		var recorder = null, stream = null, chunks = [], startedAt = 0, timer = null, autoStop = null;
		var pending = null, starting = false;

		function release() {
			if (timer) { clearInterval(timer); timer = null; }
			if (autoStop) { clearTimeout(autoStop); autoStop = null; }
			if (stream) { stream.getTracks().forEach(function (track) { track.stop(); }); stream = null; }
			recorder = null;
		}

		// Resolves true when recording began; false when a recording was already
		// live or starting (a second tap during the permission prompt).
		async function start() {
			if (recorder || starting) return false;
			starting = true;
			try {
				stream = await navigator.mediaDevices.getUserMedia({ audio: true });
			} finally {
				starting = false;
			}
			var mime = pickMime();
			var rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
			recorder = rec;
			chunks = [];
			rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
			rec.onstop = function () {
				var seconds = (Date.now() - startedAt) / 1000;
				var type = containerOf(rec.mimeType || mime);
				var resolve = pending;
				pending = null;
				release();
				if (resolve) resolve({ blob: new Blob(chunks, { type: type }), mime: type, seconds: seconds });
			};
			startedAt = Date.now();
			rec.start();
			timer = setInterval(function () {
				if (handlers.onTick) handlers.onTick(Math.floor((Date.now() - startedAt) / 1000));
			}, 500);
			autoStop = setTimeout(function () {
				if (handlers.onAutoStop) handlers.onAutoStop();
			}, MAX_RECORD_MS);
			return true;
		}

		function stop() {
			return new Promise(function (resolve) {
				if (!recorder || recorder.state === 'inactive') { release(); resolve(null); return; }
				pending = resolve;
				recorder.stop();
			});
		}

		function cancel() {
			pending = null;
			if (recorder && recorder.state !== 'inactive') {
				recorder.onstop = release;
				recorder.stop();
			} else {
				release();
			}
			chunks = [];
		}

		return { start: start, stop: stop, cancel: cancel, isRecording: function () { return !!recorder; } };
	}

	/**
	 * The recording up the upload rail (init → PUT → commit, exactly as an
	 * attachment), then DEF's transcribe-and-discard. Resolves the text.
	 * conversationId may be null on a fresh chat — '_pending', as attachments.
	 */
	async function transcribe(api, recording, conversationId) {
		var conversation = conversationId || '_pending';
		var init = await api.request('/uploads/init', {
			method: 'POST',
			body: JSON.stringify({
				filename: 'recording.' + extensionFor(recording.mime),
				mime_type: recording.mime,
				size_bytes: recording.blob.size,
				conversation_id: conversation
			})
		});
		if (!init || !init.upload_url) throw new Error('Upload could not start');
		var bytes = await recording.blob.arrayBuffer();
		// Three tries with backoff, as attachments get: a phone's first PUT after
		// waking is the one that fails, and a lost clip means re-recording it.
		var put = null;
		for (var attempt = 1; attempt <= 3; attempt++) {
			try {
				put = await fetch(init.upload_url, {
					method: 'PUT',
					headers: { 'Content-Type': recording.mime, 'x-ms-blob-type': 'BlockBlob' },
					body: bytes
				});
				if (put.ok) break;
			} catch (e) {
				put = null;
			}
			if (attempt < 3) await new Promise(function (r) { setTimeout(r, 500 * attempt); });
		}
		if (!put || !put.ok) throw new Error('Upload failed' + (put ? ' (' + put.status + ')' : ''));
		await api.request('/uploads/commit', { method: 'POST', body: JSON.stringify({ file_id: init.file_id }) });
		var out = await api.request('/voice/transcribe', {
			method: 'POST',
			body: JSON.stringify({ file_id: init.file_id, conversation_id: conversation, seconds: recording.seconds })
		});
		return (out && typeof out.text === 'string') ? out.text.trim() : '';
	}

	/**
	 * Readback. mode 'server' = the employee's own voice (DEF /voice/speak),
	 * 'device' = speechSynthesis, 'off'. Lines queue and play in order.
	 * unlock() must run inside the user's gesture — the mic tap — because
	 * iOS starts an <audio> element and speechSynthesis only from one.
	 * A server refusal (409: the tenant has no Voice key) drops to the
	 * device voice for the rest of the session and tells the consumer once.
	 */
	function createSpeaker(api, handlers) {
		handlers = handlers || {};
		var mode = 'server', audio = null, queue = [], busy = false;

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

		async function playServer(text, conversationId) {
			var out = await api.request('/voice/speak', {
				method: 'POST',
				body: JSON.stringify({ text: text, conversation_id: conversationId })
			});
			var raw = atob(out.audio_base64 || '');
			var buf = new Uint8Array(raw.length);
			for (var i = 0; i < raw.length; i++) buf[i] = raw.charCodeAt(i);
			var url = URL.createObjectURL(new Blob([buf], { type: out.mime || 'audio/mpeg' }));
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

		async function pump() {
			if (busy) return;
			busy = true;
			while (queue.length) {
				var item = queue.shift();
				try {
					if (mode === 'server') await playServer(item.text, item.conversationId);
					else await playDevice(item.text);
				} catch (e) {
					if (mode === 'server' && e && e.status === 409) {
						mode = 'device';
						if (handlers.onFallback) handlers.onFallback(e);
						await playDevice(item.text);
					} else {
						console.warn('[DEF voice] readback skipped:', e);
					}
				}
			}
			busy = false;
		}

		return {
			setMode: function (next) { mode = next; },
			getMode: function () { return mode; },
			unlock: unlock,
			speak: function (text, conversationId) {
				if (mode === 'off' || !text) return;
				queue.push({ text: text, conversationId: conversationId });
				pump();
			},
			stop: function () {
				queue = [];
				if (audio) { try { audio.pause(); } catch (e) { /* never started */ } }
				if (window.speechSynthesis) speechSynthesis.cancel();
			}
		};
	}

	// Markdown → speakable prose.
	function plain(text) {
		return String(text || '')
			.replace(/```[\s\S]*?```/g, ' ')
			.replace(/`([^`]*)`/g, '$1')
			.replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
			.replace(/[*_#>|]+/g, '')
			.replace(/^\s*[-\d.)]+\s+/gm, '')
			.replace(/\s+/g, ' ')
			.trim();
	}

	// The opening sentence — the first prefix of at least three words that
	// ends at a sentence boundary — or null while it is still arriving. The
	// boundary needs the whitespace AFTER it: a chunk ending "costs 3." is
	// not a sentence until the next chunk says whether "5 million" follows.
	// Callers holding the finished text append a space.
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
		transcribe: transcribe,
		createSpeaker: createSpeaker,
		firstSentence: firstSentence,
		closingLine: closingLine,
		MAX_RECORD_MS: MAX_RECORD_MS
	};
})();
