/*
 * A multi-step reply is shown once (V-S7b / v7.8.4) — behavioural harness.
 * Runs the SHIPPED SSE stream blocks of BOTH widgets (extracted by marker from
 * assets/js/staff-ai.js and assets/js/def-core-customer-chat.js) inside jsdom
 * and drives them through real event sequences: a superseded round's text
 * leaves the reply bubble for the working line, a later delta on the same step
 * (the cap-hit summary) stays, a server that never sends the frame renders as
 * before, and the device-voice readback is untouched. 8 checks.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const STAFF = extract.staffAiStream();
const CC = extract.customerChatStream();

const PERSONA = { createController: () => ({ handleEvent() {}, formatThinkingLabel: m => m, reset() {} }) };

// ── Staff AI ────────────────────────────────────────────────────────────
const STAFF_HTML = `<!doctype html><html><body>
<div class="messages-container" id="messagesContainer"><div id="messagesList">
  <div class="message message-assistant"><div class="message-content"><div class="typing-indicator"></div></div></div>
</div></div>
</body></html>`;

// Everything the block reads from the surrounding closure. `renderToolStatus`
// gets its own class so `.tool-status` names the working line only.
const STAFF_HEAD = `
	var messagesList = document.getElementById('messagesList');
	var messagesContainer = document.getElementById('messagesContainer');
	var SSE_TOOL_PACING_MS = 0;
	var eventQueue = [], processing = false, toolStatusElements = {};
	var messages = [], spoken = [], readbackBuffer = '';
	var currentConversationId = null, dirtyInput = false, lastSuggestion = null;
	var composerInput = null, conversationOn = false, speaker = null;
	var _isStreaming = false, _userScrolledUp = false;
	function renderMarkdown(s) { return s; }
	function t(k, fb) { return fb; }
	function renderToolStatus() { return document.createElement('div'); }
	function completeToolStatus() {}
	function clearStagedFiles() {}
	function removeTypingMessage() {}
	function dropUnfilledTranscript() { return false; }
	function renderMessages() {}
	function applyCitations() {}
	function buildCitationMap() { return {}; }
	function createToolOutputCard() { return null; }
	function loadConversations() {}
	function updateReadOnlyState() {}
	function showError() {}
	function endConversation() {}
	function readBackSoFar() { spoken.push(readbackBuffer); }
	function readBack(final) { spoken.push('final:' + final); }
`;

const STAFF_TAIL = `
	return {
		push: async function (evts) {
			for (var i = 0; i < evts.length; i++) eventQueue.push(evts[i]);
			await processEventQueue();
		},
		bubble: function () { return messagesList.querySelector('.message-content').textContent; },
		working: function () {
			var w = messagesList.querySelector('.tool-status');
			return w ? w.textContent : null;
		},
		saved: function () { return messages.length ? messages[messages.length - 1].content : null; },
		spoken: function () { return spoken; }
	};`;

function bootStaff(spokenTurn) {
	const dom = new JSDOM(STAFF_HTML, { url: 'https://e.test/staff-ai/', pretendToBeVisual: true });
	dom.window.DefPersona = PERSONA;
	const factory = new dom.window.Function('window', 'document', 'spokenTurn',
		STAFF_HEAD + STAFF + STAFF_TAIL);
	return factory(dom.window, dom.window.document, !!spokenTurn);
}

// ── Customer Chat ───────────────────────────────────────────────────────
const CC_HTML = `<!doctype html><html><body>
<div id="ccMessages"></div><textarea id="ccInput"></textarea>
</body></html>`;

const CC_HEAD = `
	var els = { messages: document.getElementById('ccMessages'), input: document.getElementById('ccInput') };
	var SSE_TOOL_PACING_MS = 0;
	var eventQueue = [], processing = false, lastToolTime = 0;
	var requestAnimationFrame = window.requestAnimationFrame.bind(window);
	var toolStatusEls = {}, transcribingEl = null, currentEscalationSubject = null;
	var thinkingEl = null, lastUserText = '', text = 'how much do I owe?';
	var streamTerminated = false, dirtyInput = false, conversationOn = false;
	var lastSuggestion = null, speaker = null, persisted = null;
	function el(tag, cls) { var d = document.createElement(tag); if (cls) d.className = cls; return d; }
	function scrollToBottom() {}
	function renderMarkdown(s) { return s; }
	function hideThinking() {}
	function renderToolStatusForStream() { return el('div'); }
	function completeToolStatus() {}
	function deferAsyncTick() {}
	function setState(node, cls, on) { if (node) node.classList.toggle(cls, !!on); }
	function appendMessage() {}
	function t(k) { return k; }
	function setComposerDisabled() {}
	function endConversation() {}
	function dropUnfilledTranscript() {}
	function autoResizeInput() {}
	function updateSendButton() {}
	function processChatResponseMeta(evt) { persisted = evt.choices[0].message.content; }
`;

const CC_TAIL = `
	return {
		push: function (evts) {
			for (var i = 0; i < evts.length; i++) eventQueue.push(evts[i]);
			processEventQueue();
		},
		idle: function () { return eventQueue.length === 0 && !processing; },
		bubble: function () {
			var b = els.messages.querySelector('.def-cc-message-content');
			return b ? b.textContent : null;
		},
		working: function () {
			var w = els.messages.querySelector('.cc-tool-status');
			return w ? w.textContent : null;
		},
		persisted: function () { return persisted; }
	};`;

function bootCC() {
	const dom = new JSDOM(CC_HTML, { url: 'https://e.test/', pretendToBeVisual: true });
	dom.window.DefPersona = PERSONA;
	const factory = new dom.window.Function('window', 'document', 'spokenTurn',
		CC_HEAD + CC + CC_TAIL);
	const api = factory(dom.window, dom.window.document, false);
	// One event per processEventQueue() call, re-scheduled through rAF: drive it
	// to quiet before asserting.
	api.settle = async function () {
		for (let i = 0; i < 200 && !api.idle(); i++) {
			await new Promise(r => dom.window.setTimeout(r, 5));
		}
	};
	return api;
}

// ── The streams ─────────────────────────────────────────────────────────
const LEAD = 'Checking FreshBooks now.';
const ANSWER = 'Your oldest unpaid invoice is #1042, due 12 August.';
const CAPPED = 'I stopped after ten steps. Here is what I found.';
const delta = (text, step) => ({ type: 'text_delta', text: text, step: step });
const TOOL = [{ type: 'tool_start', tool: 'freshbooks' }, { type: 'tool_done', tool: 'freshbooks', status: 'success' }];
const done = content => ({ type: 'done', choices: [{ message: { content: content, tool_outputs: [] } }], thread_id: 'th-1' });

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + String(pass + fail).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(pass + fail).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

(async () => {
	// 1. Staff AI, two rounds: the bubble ends holding the answer alone.
	{
		const t = bootStaff();
		await t.push([delta(LEAD, 1), { type: 'step_superseded', step: 1 }].concat(TOOL));
		const midBubble = t.bubble(), midWorking = t.working();
		await t.push([delta(ANSWER, 2), done(ANSWER)]);
		check('Staff AI: a superseded round leaves the bubble for the working line; the bubble ends holding the final round',
			midBubble === '' && midWorking === LEAD && t.bubble() === ANSWER && t.saved() === ANSWER,
			'mid=' + JSON.stringify(midBubble) + ' working=' + JSON.stringify(midWorking)
			+ ' end=' + JSON.stringify(t.bubble()) + ' saved=' + JSON.stringify(t.saved()));
	}
	// 2. Staff AI, the cut marker: a later delta on the SAME step is kept.
	{
		const t = bootStaff();
		await t.push([delta(LEAD, 1), { type: 'step_superseded', step: 1 }, delta(CAPPED, 1), done('')]);
		check('Staff AI: a cap-hit summary streaming on the superseded step opens a new slice and is kept',
			t.bubble() === CAPPED && t.saved() === CAPPED,
			'bubble=' + JSON.stringify(t.bubble()));
	}
	// 3. Staff AI against a server that never sends the frame.
	{
		const t = bootStaff();
		await t.push([delta(LEAD, 1)].concat(TOOL).concat([delta(ANSWER, 2), done(ANSWER)]));
		check('Staff AI: a stream with no step_superseded renders exactly as before (both rounds in the bubble)',
			t.bubble() === LEAD + ANSWER && t.working() === null,
			'bubble=' + JSON.stringify(t.bubble()));
	}
	// 4. The device-voice readback is untouched — the lead-in was read once.
	{
		const t = bootStaff(true);
		await t.push([delta(LEAD, 1), { type: 'step_superseded', step: 1 }].concat(TOOL)
			.concat([delta(ANSWER, 2), done(ANSWER)]));
		const spoken = t.spoken();
		check('Staff AI: the cut does not touch the readback buffer — the lead-in stays read once',
			spoken[0] === LEAD && spoken[1] === LEAD + ANSWER && spoken.length === 3,
			'spoken=' + JSON.stringify(spoken));
	}
	// 5. A frame before anything streamed is a no-op.
	{
		const t = bootStaff();
		await t.push([{ type: 'step_superseded', step: 1 }].concat(TOOL).concat([delta(ANSWER, 2), done(ANSWER)]));
		check('Staff AI: a frame for a round that streamed no text adds no working line and loses nothing',
			t.bubble() === ANSWER && t.working() === null,
			'bubble=' + JSON.stringify(t.bubble()) + ' working=' + JSON.stringify(t.working()));
	}
	// 6. Customer Chat, two rounds.
	{
		const t = bootCC();
		t.push([delta(LEAD, 1), { type: 'step_superseded', step: 1 }].concat(TOOL));
		await t.settle();
		const midBubble = t.bubble(), midWorking = t.working();
		t.push([delta(ANSWER, 2), done(ANSWER)]);
		await t.settle();
		check('Customer Chat: a superseded round leaves the bubble for the working line; the bubble and the persisted turn end at the final round',
			midBubble === '' && midWorking === LEAD && t.bubble() === ANSWER && t.persisted() === ANSWER,
			'mid=' + JSON.stringify(midBubble) + ' working=' + JSON.stringify(midWorking)
			+ ' end=' + JSON.stringify(t.bubble()) + ' persisted=' + JSON.stringify(t.persisted()));
	}
	// 7. Customer Chat, the cut marker.
	{
		const t = bootCC();
		t.push([delta(LEAD, 1), { type: 'step_superseded', step: 1 }, delta(CAPPED, 1), done('')]);
		await t.settle();
		check('Customer Chat: a cap-hit summary streaming on the superseded step is kept',
			t.bubble() === CAPPED && t.persisted() === CAPPED,
			'bubble=' + JSON.stringify(t.bubble()));
	}
	// 8. Customer Chat against a server that never sends the frame — the
	//    segment break between the two rounds is the one that already shipped.
	{
		const t = bootCC();
		t.push([delta(LEAD, 1)].concat(TOOL).concat([delta(ANSWER, 2), done(ANSWER)]));
		await t.settle();
		check('Customer Chat: a stream with no step_superseded renders exactly as before (both rounds, segment break between)',
			t.bubble() === LEAD + '\n\n' + ANSWER && t.working() === null,
			'bubble=' + JSON.stringify(t.bubble()));
	}

	console.log(results.join('\n'));
	console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
	process.exit(fail ? 1 : 0);
})();
