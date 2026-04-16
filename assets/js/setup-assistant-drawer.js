/**
 * Digital Employee Framework - Setup Assistant Drawer
 *
 * Right-side slide-in chat panel for the Setup Assistant.
 * Communicates with the DEF backend via def-core REST proxy.
 * Handles: chat, thread persistence, ui_actions, dirty field tracking,
 * first-visit auto-open, and WCAG accessibility.
 *
 * @package def-core
 * @since   2.0.0
 */
(function () {
	'use strict';

	var config = window.defSetupAssistant || {};

	// ─── Constants ───────────────────────────────────────────────

	var VALID_TABS = [
		'connection', 'branding', 'chat-settings', 'escalation',
		'user-roles'
	];

	var FIELD_MAP = {
		'api_url':                    { id: 'def_core_staff_ai_api_url',   max_length: 2000 },
		'api_key':                    { id: 'def_core_api_key',            max_length: 256 },
		'display_name':               { id: 'def_core_display_name',       max_length: 100 },
		'chat_drawer_width':          { id: 'def_core_chat_drawer_width',  max_length: 5 },
		'chat_button_color':          { id: 'def_core_chat_button_color',  max_length: 7, type: 'color' },
		'chat_button_hover_color':    { id: 'def_core_chat_button_hover_color', max_length: 7, type: 'color' },
		'allowed_origins':            { id: 'def_core_allowed_origins',    max_length: 2000 },
		'escalation_customer':        { id: 'escalation_customer',         max_length: 320 },
		'escalation_setup_assistant': { id: 'escalation_setup_assistant',  max_length: 320 },
		'chat_display_mode':          { type: 'radio', name: 'def_core_chat_display_mode', max_length: 10 },
		'chat_button_icon':           { type: 'radio', name: 'def_core_chat_button_icon', max_length: 10 },
		'chat_button_label':          { type: 'radio', name: 'def_core_chat_button_label', max_length: 10 }
	};

	var TOOL_STATUS_LABELS = {
		'get_setup_status':      'Checking setup status...',
		'get_setting':           'Reading settings...',
		'test_connection':       'Testing connection...',
		'get_users_with_roles':  'Checking user roles...',
		'search_users':          'Searching users...',
		'update_setting':        'Updating settings...',
		'update_user_role':      'Updating user role...'
	};

	var TOOL_DONE_LABELS = {
		'get_setup_status':      'Setup status checked',
		'get_setting':           'Settings read',
		'test_connection':       'Connection tested',
		'get_users_with_roles':  'User roles checked',
		'search_users':          'Users found',
		'update_setting':        'Settings updated',
		'update_user_role':      'User role updated'
	};

	// Secret fields: never send raw values in dirty context.
	var SECRET_FIELDS = ['def_core_api_key'];

	// Namespace localStorage keys per site to avoid cross-install collisions.
	var LS_PREFIX     = 'def_sa_' + (config.restUrl || '').replace(/[^a-z0-9]/gi, '').slice(0, 32) + '_';
	var LS_THREAD_KEY = LS_PREFIX + 'thread_id';
	var LS_SEEN_KEY   = LS_PREFIX + 'seen';

	// ─── Constructor ─────────────────────────────────────────────

	// Minimum visual pacing between SSE tool status updates (ms).
	var SSE_TOOL_PACING_MS = 400;

	function SetupAssistantDrawer() {
		this.apiBase       = config.restUrl || '';
		this.nonce         = config.nonce || '';
		this.chatStreamUrl = config.chatStreamUrl || '';
		this.threadId      = null;
		this.messages      = [];
		this.dirtyFields   = {};
		this.settingsSnapshot = {};
		this.isOpen        = false;
		this.isSending     = false;
		this.previousFocus = null;

		// DOM refs (set in init).
		this.drawerEl   = null;
		this.triggerEl  = null;
		this.backdropEl = null;
		this.panelEl    = null;
		this.closeEl    = null;
		this.clearEl    = null;
		this.messagesEl = null;
		this.inputEl    = null;
		this.sendEl     = null;
		this.dirtyInput = false;
		this.lastSuggestion = null;       // Phase 10.1: last suggestion shown

		this.init();
	}

	// ─── Lifecycle ───────────────────────────────────────────────

	SetupAssistantDrawer.prototype.init = function () {
		// Cache DOM refs.
		this.drawerEl   = document.getElementById('def-setup-assistant-drawer');
		this.triggerEl  = document.getElementById('def-setup-assistant-trigger');

		if (!this.drawerEl || !this.triggerEl) {
			return;
		}

		this.backdropEl = this.drawerEl.querySelector('.def-sa-backdrop');
		this.panelEl    = this.drawerEl.querySelector('.def-sa-panel');
		this.closeEl    = this.drawerEl.querySelector('.def-sa-close');
		this.clearEl    = this.drawerEl.querySelector('.def-sa-clear');
		this.messagesEl = this.drawerEl.querySelector('.def-sa-messages');
		this.inputEl    = this.drawerEl.querySelector('.def-sa-input');
		this.sendEl     = this.drawerEl.querySelector('.def-sa-send');

		// Remove inline display:none (FOUC guard) — CSS visibility takes over.
		this.drawerEl.style.display = '';

		// Bind events.
		this.bindEvents();

		// Dirty field tracking.
		this.captureSettingsSnapshot();
		this.trackDirtyFields();

		// Thread + first-visit (async, non-blocking).
		this.getOrCreateThread();
		this.checkFirstVisit();
	};

	SetupAssistantDrawer.prototype.bindEvents = function () {
		var self = this;

		this.triggerEl.addEventListener('click', function () {
			self.toggle();
		});

		this.closeEl.addEventListener('click', function () {
			self.close();
		});

		if (this.backdropEl) {
			this.backdropEl.addEventListener('click', function () {
				self.close();
			});
		}

		this.clearEl.addEventListener('click', function () {
			self.clearConversation();
		});

		this.sendEl.addEventListener('click', function () {
			self.handleSend();
		});

		// Keyboard events.
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && self.isOpen) {
				e.preventDefault();
				self.close();
			}
		});

		this.inputEl.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				self.handleSend();
			}
			// Tab in textarea → move to Send button.
			if (e.key === 'Tab' && !e.shiftKey) {
				e.preventDefault();
				self.sendEl.focus();
			}
			// Auto-clear suggestion ghost text on any printable keystroke
			if (self.inputEl.classList.contains('def-sa-suggestion-text') &&
				e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
				self.inputEl.value = '';
				self.inputEl.classList.remove('def-sa-suggestion-text');
				self.autoResizeInput();
			}
		});
		// Click in input clears suggestion ghost text
		this.inputEl.addEventListener('click', function () {
			if (self.inputEl.classList.contains('def-sa-suggestion-text')) {
				self.inputEl.value = '';
				self.inputEl.classList.remove('def-sa-suggestion-text');
				self.autoResizeInput();
			}
		});

		// Auto-resize textarea + track dirty input.
		this.inputEl.addEventListener('input', function () {
			self.dirtyInput = true;
			if (self.inputEl.classList.contains('def-sa-suggestion-text')) {
				self.inputEl.classList.remove('def-sa-suggestion-text');
			}
			self.autoResizeInput();
		});

		// Focus trap.
		this.drawerEl.addEventListener('keydown', function (e) {
			if (e.key === 'Tab') {
				self.handleFocusTrap(e);
			}
		});
	};

	SetupAssistantDrawer.prototype.open = function () {
		if (this.isOpen) {
			return;
		}
		this.previousFocus = document.activeElement;
		this.drawerEl.setAttribute('aria-hidden', 'false');
		this.triggerEl.setAttribute('aria-expanded', 'true');
		document.body.classList.add('def-sa-drawer-open');
		this.isOpen = true;

		// Show welcome if the message area is empty (no thread history loaded).
		if (!this.messagesEl.hasChildNodes()) {
			this.showWelcome();
		}

		// Focus textarea after transition.
		var self = this;
		setTimeout(function () {
			self.inputEl.focus();
		}, 350);
	};

	SetupAssistantDrawer.prototype.close = function () {
		if (!this.isOpen) {
			return;
		}
		this.drawerEl.setAttribute('aria-hidden', 'true');
		this.triggerEl.setAttribute('aria-expanded', 'false');
		document.body.classList.remove('def-sa-drawer-open');
		this.isOpen = false;

		// Restore focus.
		if (this.previousFocus && this.previousFocus.focus) {
			this.previousFocus.focus();
		}
	};

	SetupAssistantDrawer.prototype.toggle = function () {
		if (this.isOpen) {
			this.close();
		} else {
			this.open();
		}
	};

	// ─── Chat ────────────────────────────────────────────────────

	// KEEP IN SYNC: def-core-customer-chat.js, staff-ai.js, setup-assistant-drawer.js
	function classifySuggestionOutcome(sentText, suggestion) {
		if (!suggestion) return { outcome: null, score: null };
		var normalize = function(s) {
			return s.trim().toLowerCase().replace(/[^\w\s]/g, '').replace(/\s+/g, ' ');
		};
		var normSent = normalize(sentText);
		var normSuggestion = normalize(suggestion);
		if (normSent === normSuggestion) return { outcome: 'accepted', score: 1.0 };
		var stopwords = ['the','a','an','is','are','was','were','to','of','in','for','on','and','or','but','it','be'];
		var tokenize = function(s) {
			return s.split(/\s+/).filter(function(w) {
				return w.length >= 3 && stopwords.indexOf(w) === -1;
			});
		};
		var sentTokens = tokenize(normSent);
		var suggTokens = tokenize(normSuggestion);
		if (suggTokens.length < 4) {
			return normSent === normSuggestion
				? { outcome: 'accepted', score: 1.0 }
				: { outcome: 'ignored', score: 0.0 };
		}
		var union = {};
		var intersection = 0;
		var i;
		for (i = 0; i < sentTokens.length; i++) union[sentTokens[i]] = true;
		for (i = 0; i < suggTokens.length; i++) {
			if (union[suggTokens[i]]) intersection++;
			union[suggTokens[i]] = true;
		}
		var unionSize = Object.keys(union).length;
		var score = unionSize > 0 ? intersection / unionSize : 0;
		if (score >= 0.5) return { outcome: 'edited', score: score };
		return { outcome: 'ignored', score: score };
	}

	SetupAssistantDrawer.prototype.handleSend = function () {
		var text = this.inputEl.value.trim();
		if (!text || this.isSending) {
			return;
		}

		// Phase 10.1: Classify suggestion outcome before clearing input
		var suggResult = classifySuggestionOutcome(text, this.lastSuggestion);
		this._pendingOutcome = suggResult.outcome;
		this._pendingScore = suggResult.score;
		this.lastSuggestion = null;

		this.inputEl.value = '';
		this.autoResizeInput();
		this.sendMessage(text);
	};

	SetupAssistantDrawer.prototype.sendMessage = function (text) {
		if (!this.chatStreamUrl) {
			this.renderError('Setup Assistant requires API URL configuration. Please check your connection settings.');
			return;
		}
		if (typeof ReadableStream === 'undefined') {
			this.renderError('Your browser does not support streaming. Please use a modern browser.');
			return;
		}
		this.sendMessageStreaming(text);
	};

	// ─── SSE Buffer Parser ───────────────────────────────────────

	function parseSSEBuffer(buffer) {
		var events = [];
		var parts = buffer.split('\n\n');
		var remaining = parts.pop(); // incomplete chunk kept for next iteration

		for (var i = 0; i < parts.length; i++) {
			var block = parts[i];
			var lines = block.split('\n');
			var dataLines = [];

			for (var j = 0; j < lines.length; j++) {
				var line = lines[j];
				// Skip SSE comments (heartbeats etc.)
				if (line.charAt(0) === ':') continue;
				// Collect data lines
				if (line.indexOf('data: ') === 0) {
					dataLines.push(line.substring(6));
				} else if (line.indexOf('data:') === 0) {
					dataLines.push(line.substring(5));
				}
				// Ignore event:, id:, retry: fields (not used)
			}

			if (dataLines.length > 0) {
				var payload = dataLines.join('\n');
				try { events.push(JSON.parse(payload)); } catch (e) { /* skip malformed */ }
			}
		}

		return { parsed: events, remaining: remaining };
	}

	// ─── Streaming Chat (direct to DEF via JWT) ──────────────────

	SetupAssistantDrawer.prototype.sendMessageStreaming = function (text) {
		var self = this;
		this.isSending = true;
		this.sendEl.disabled = true;

		// Render user message immediately.
		this.renderMessage({ role: 'user', content: text });

		// Show typing indicator.
		this.showTypingIndicator();

		// Build request body.
		var body = { message: text };
		if (this.threadId) {
			body.thread_id = this.threadId;
		}
		var dirtyContext = this.getDirtyContext();
		if (dirtyContext.dirty_fields) {
			body.context = dirtyContext;
		}
		// Phase 10.1: Add suggestion feedback signal
		if (self._pendingOutcome) {
			body.suggestion_outcome = self._pendingOutcome;
			body.similarity_score = self._pendingScore;
		}
		self._pendingOutcome = null;
		self._pendingScore = null;

		// Track active tool status elements for pacing.
		var toolStatusEls = {};
		var eventQueue = [];
		var processing = false;
		var lastToolTime = 0;

		// Progressive text rendering state.
		var streamBuffer = '';
		var streamEl = null;
		var wordDrainTimer = null;
		var displayedLen = 0;
		var thinkingStatusEl = null;
		var thinkingDeferTimer = null;
		var pendingThinkingMessage = null;

		function drainNextWord() {
			if (displayedLen >= streamBuffer.length) {
				wordDrainTimer = null;
				return;
			}
			var i = displayedLen;
			// Skip whitespace, then skip word characters.
			while (i < streamBuffer.length && /\s/.test(streamBuffer[i])) { i++; }
			while (i < streamBuffer.length && !/\s/.test(streamBuffer[i])) { i++; }
			displayedLen = i;
			streamEl.innerHTML = self.formatMarkdown(streamBuffer.slice(0, displayedLen));
			self.scrollToBottom();
			wordDrainTimer = setTimeout(drainNextWord, 30);
		}

		function processEventQueue() {
			if (processing || eventQueue.length === 0) { return; }
			processing = true;

			function processNext() {
				if (eventQueue.length === 0) {
					processing = false;
					return;
				}

				var event = eventQueue.shift();
				var now = Date.now();
				var delay = 0;

				// Visual pacing: ensure minimum gap between tool status updates.
				if (event.type === 'tool_start' || event.type === 'tool_done') {
					var elapsed = now - lastToolTime;
					if (elapsed < SSE_TOOL_PACING_MS) {
						delay = SSE_TOOL_PACING_MS - elapsed;
					}
				}

				setTimeout(function () {
					handleSSEEvent(event);
					lastToolTime = Date.now();
					processNext();
				}, delay);
			}

			processNext();
		}

		function handleSSEEvent(event) {
			switch (event.type) {
				case 'thinking':
					self.hideTypingIndicator();
					var saThinkMsg = (event.message || '').toString().trim();
					if (!saThinkMsg) {
						break;
					}
					pendingThinkingMessage = saThinkMsg;
					if (!thinkingStatusEl && !thinkingDeferTimer) {
						thinkingDeferTimer = setTimeout(function() {
							var div = document.createElement('div');
							div.className = 'def-sa-tool-status';
							div.innerHTML = '<span class="def-sa-spinner"></span><span class="def-sa-tool-label"></span>';
							div.querySelector('.def-sa-tool-label').textContent = pendingThinkingMessage;
							self.messagesEl.appendChild(div);
							self.scrollToBottom();
							thinkingStatusEl = div;
							thinkingDeferTimer = null;
						}, 700);
						break;
					}
					if (thinkingDeferTimer) {
						break;
					}
					var saLabelEl = thinkingStatusEl.querySelector('.def-sa-tool-label');
					if (saLabelEl) saLabelEl.textContent = saThinkMsg;
					break;

				case 'tool_start':
					if (thinkingDeferTimer) { clearTimeout(thinkingDeferTimer); thinkingDeferTimer = null; }
					if (thinkingStatusEl) { thinkingStatusEl.remove(); thinkingStatusEl = null; }
					// Create a tool status element with spinner.
					var startEl = self.renderToolStatusForStream(event.tool);
					toolStatusEls[event.tool] = startEl;
					break;

				case 'tool_done':
					// Complete the tool status (spinner → checkmark).
					var doneEl = toolStatusEls[event.tool];
					if (doneEl) {
						self.completeToolStatus(doneEl, event.tool);
					}
					break;

				case 'text_delta':
					if (!streamEl) {
						self.hideTypingIndicator();
						if (thinkingDeferTimer) { clearTimeout(thinkingDeferTimer); thinkingDeferTimer = null; }
						if (thinkingStatusEl) { thinkingStatusEl.remove(); thinkingStatusEl = null; }
						streamEl = document.createElement('div');
						streamEl.className = 'def-sa-message def-sa-message-assistant def-sa-message-streaming';
						self.messagesEl.appendChild(streamEl);
					}
					streamBuffer += event.text;
					if (!wordDrainTimer) {
						drainNextWord();
					}
					break;

				case 'done':
					self.hideTypingIndicator();
					if (thinkingDeferTimer) { clearTimeout(thinkingDeferTimer); thinkingDeferTimer = null; }
					if (thinkingStatusEl) { thinkingStatusEl.remove(); thinkingStatusEl = null; }
					if (wordDrainTimer) clearTimeout(wordDrainTimer);

					if (streamEl) {
						var finalText = event.reply || streamBuffer;
						streamEl.innerHTML = self.formatMarkdown(finalText);
						streamEl.classList.remove('def-sa-message-streaming');
						self.messages.push({ role: 'assistant', content: finalText });
						self.scrollToBottom();
					} else if (event.reply) {
						self.renderMessage({ role: 'assistant', content: event.reply });
					}

					streamBuffer = '';
					streamEl = null;
					wordDrainTimer = null;
					displayedLen = 0;
					thinkingStatusEl = null;

					// Process tool_outputs (for ui_actions like tab highlighting, field updates).
					if (event.tool_outputs && event.tool_outputs.length) {
						for (var i = 0; i < event.tool_outputs.length; i++) {
							var output = event.tool_outputs[i];
							if (output.ui_actions && output.ui_actions.length) {
								self.processUiActions(output.ui_actions);
							}
							if (output.type === 'escalation_offer') {
								self.renderEscalationCard({ message: output.reason });
							}
						}
					}

					// Save thread ID.
					if (event.thread_id && !self.threadId) {
						self.threadId = event.thread_id;
						self.saveThread(event.thread_id);
					}

					self.isSending = false;
					self.sendEl.disabled = false;
					self.dirtyInput = false;
					break;

				case 'suggestions':
					self.lastSuggestion = event.suggestion || null;
					if (!self.dirtyInput && self.inputEl && event.suggestion) {
						self.inputEl.value = event.suggestion;
						self.inputEl.classList.add('def-sa-suggestion-text');
						self.autoResizeInput();
					}
					break;

				case 'error':
					self.hideTypingIndicator();
					if (wordDrainTimer) clearTimeout(wordDrainTimer);
					streamBuffer = '';
					streamEl = null;
					wordDrainTimer = null;
					displayedLen = 0;
					thinkingStatusEl = null;
					self.renderError(event.message || 'An error occurred.');
					self.isSending = false;
					self.sendEl.disabled = false;
					break;
			}
		}

		// Start SSE stream via WordPress BFF proxy.
		fetch(self.chatStreamUrl, {
			method: 'POST',
			headers: {
				'X-WP-Nonce': self.nonce,
				'Content-Type': 'application/json'
			},
			body: JSON.stringify(body),
			credentials: 'same-origin'
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error('Stream request failed: ' + response.status);
				}

				var reader = response.body.getReader();
				var decoder = new TextDecoder();
				var buffer = '';

				function readChunk() {
					return reader.read().then(function (result) {
						if (result.done) {
							// Process any remaining buffer.
							if (buffer.trim()) {
								var final = parseSSEBuffer(buffer + '\n\n');
								for (var i = 0; i < final.parsed.length; i++) {
									eventQueue.push(final.parsed[i]);
								}
								processEventQueue();
							}
							return;
						}

						buffer += decoder.decode(result.value, { stream: true });
						var parsed = parseSSEBuffer(buffer);
						buffer = parsed.remaining;

						for (var i = 0; i < parsed.parsed.length; i++) {
							eventQueue.push(parsed.parsed[i]);
						}
						processEventQueue();

						return readChunk();
					});
				}

				return readChunk();
			})
			.catch(function (err) {
				// Mid-stream fallback: show error but keep any progress already rendered.
				self.hideTypingIndicator();
				self.renderError(err.message || 'Connection lost. Please try again.');
				self.isSending = false;
				self.sendEl.disabled = false;
			});
	};

	// ─── Streaming Tool Status Helpers ───────────────────────────

	SetupAssistantDrawer.prototype.renderToolStatusForStream = function (toolName) {
		var label = TOOL_STATUS_LABELS[toolName] || 'Processing...';
		var div = document.createElement('div');
		div.className = 'def-sa-tool-status';
		div.innerHTML = '<span class="def-sa-spinner"></span><span class="def-sa-tool-label">' + this.escapeHtml(label) + '</span>';
		this.messagesEl.appendChild(div);
		this.scrollToBottom();
		return div;
	};

	SetupAssistantDrawer.prototype.updateTypingIndicator = function (text) {
		var indicator = this.messagesEl.querySelector('.def-sa-typing');
		if (indicator) {
			var label = indicator.querySelector('.def-sa-typing-label');
			if (label) {
				label.textContent = text;
			} else {
				// Add label span next to the dots.
				var span = document.createElement('span');
				span.className = 'def-sa-typing-label';
				span.textContent = text;
				indicator.appendChild(span);
			}
		}
	};

	// ─── Message Rendering ───────────────────────────────────────

	SetupAssistantDrawer.prototype.renderMessage = function (msg) {
		var div = document.createElement('div');
		div.className = 'def-sa-message';

		if (msg.role === 'user') {
			div.className += ' def-sa-message-user';
			div.textContent = msg.content;
		} else {
			div.className += ' def-sa-message-assistant';
			div.innerHTML = this.formatMarkdown(msg.content);
		}

		this.messages.push(msg);
		this.messagesEl.appendChild(div);
		this.scrollToBottom();
	};

	SetupAssistantDrawer.prototype.renderToolStatus = function (output) {
		var label = TOOL_STATUS_LABELS[output.tool_name] || 'Processing...';
		var div = document.createElement('div');
		div.className = 'def-sa-tool-status';
		div.innerHTML = '<span class="def-sa-spinner"></span><span class="def-sa-tool-label">' + this.escapeHtml(label) + '</span>';

		// Add show-details toggle if there's raw output data.
		if (output.result) {
			var toggleBtn = document.createElement('button');
			toggleBtn.type = 'button';
			toggleBtn.className = 'def-sa-tool-details-toggle';
			toggleBtn.textContent = 'Show details';

			var detailsDiv = document.createElement('div');
			detailsDiv.className = 'def-sa-tool-details';
			try {
				detailsDiv.textContent = typeof output.result === 'string'
					? output.result
					: JSON.stringify(output.result, null, 2);
			} catch (e) {
				detailsDiv.textContent = String(output.result);
			}

			toggleBtn.addEventListener('click', function () {
				var showing = detailsDiv.classList.toggle('show');
				toggleBtn.textContent = showing ? 'Hide details' : 'Show details';
			});

			div.appendChild(toggleBtn);
			div.appendChild(detailsDiv);
		}

		this.messagesEl.appendChild(div);
		this.scrollToBottom();
		return div;
	};

	SetupAssistantDrawer.prototype.renderEscalationCard = function (data) {
		var self = this;
		var card = document.createElement('div');
		card.className = 'def-sa-escalation-card';

		var intro = document.createElement('p');
		intro.className = 'def-sa-escalation-intro';
		intro.textContent = 'Fill in a subject and a brief message. Your request will be emailed to your DEF partner.';
		card.appendChild(intro);

		// Subject input.
		var subjectLabel = document.createElement('label');
		subjectLabel.className = 'def-sa-escalation-label';
		subjectLabel.textContent = 'Subject';
		card.appendChild(subjectLabel);

		var subjectInput = document.createElement('input');
		subjectInput.type = 'text';
		subjectInput.className = 'def-sa-escalation-input';
		subjectInput.value = 'Setup Assistant — human help requested';
		card.appendChild(subjectInput);

		// Message textarea.
		var messageLabel = document.createElement('label');
		messageLabel.className = 'def-sa-escalation-label';
		messageLabel.textContent = 'Message';
		card.appendChild(messageLabel);

		var messageInput = document.createElement('textarea');
		messageInput.className = 'def-sa-escalation-textarea';
		messageInput.rows = 4;
		messageInput.placeholder = 'Briefly describe what you need help with…';
		card.appendChild(messageInput);

		// Status line (shown during sending / on error / on success).
		var status = document.createElement('div');
		status.className = 'def-sa-escalation-status';
		status.style.display = 'none';
		card.appendChild(status);

		// Actions.
		var actions = document.createElement('div');
		actions.className = 'def-sa-escalation-actions';

		var sendBtn = document.createElement('button');
		sendBtn.type = 'button';
		sendBtn.className = 'def-sa-escalation-accept';
		sendBtn.textContent = 'Send';

		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button';
		cancelBtn.className = 'def-sa-escalation-decline';
		cancelBtn.textContent = 'Cancel';

		// AbortController for in-flight sends — lets Cancel interrupt a POST
		// that's already in progress so the success handler can't fire after
		// the user clicked Cancel.
		var inflightController = null;
		var isCancelled = false;

		cancelBtn.addEventListener('click', function () {
			isCancelled = true;
			if (inflightController) {
				try { inflightController.abort(); } catch (e) {}
			}
			card.style.opacity = '0.5';
			sendBtn.disabled = true;
			cancelBtn.disabled = true;
			subjectInput.disabled = true;
			messageInput.disabled = true;
			status.style.display = '';
			status.textContent = 'Escalation cancelled.';
		});

		// Clear the status line when the user resumes typing after a
		// validation error, so stale messages don't linger.
		function clearStatusOnInput() {
			if (!isCancelled && status.style.display !== 'none') {
				status.style.display = 'none';
				status.textContent = '';
			}
		}
		subjectInput.addEventListener('input', clearStatusOnInput);
		messageInput.addEventListener('input', clearStatusOnInput);

		sendBtn.addEventListener('click', function () {
			if (isCancelled) return;
			var subject = (subjectInput.value || '').trim();
			var message = (messageInput.value || '').trim();
			if (!subject || !message) {
				status.style.display = '';
				status.textContent = 'Please enter a subject and a message.';
				return;
			}
			sendBtn.disabled = true;
			cancelBtn.disabled = true;
			subjectInput.disabled = true;
			messageInput.disabled = true;
			status.style.display = '';
			status.textContent = 'Sending…';
			inflightController = new AbortController();
			self.submitEscalation({
				subject: subject,
				message: message,
			}, inflightController.signal).then(function () {
				if (isCancelled) return;
				status.textContent = '✓ Your request has been sent to your DEF partner.';
			}).catch(function (err) {
				if (isCancelled) return;
				sendBtn.disabled = false;
				cancelBtn.disabled = false;
				subjectInput.disabled = false;
				messageInput.disabled = false;
				status.textContent = 'Failed to send: ' + (err && err.message ? err.message : 'unknown error') + '. Please try again.';
			});
		});

		actions.appendChild(sendBtn);
		actions.appendChild(cancelBtn);
		card.appendChild(actions);

		this.messagesEl.appendChild(card);
		this.scrollToBottom();
	};

	SetupAssistantDrawer.prototype.submitEscalation = function (payload, abortSignal) {
		var self = this;
		var cfg = window.defSetupAssistant || {};
		if (!cfg.escalationUrl || !cfg.nonce) {
			return Promise.reject(new Error('escalation endpoint not configured'));
		}

		// Build body text: user's message + recent conversation transcript.
		// Only include user + assistant turns; skip tool/system/error entries.
		var bodyParts = [];
		bodyParts.push(payload.message);

		if (self.messages && self.messages.length > 0) {
			bodyParts.push('');
			bodyParts.push('---');
			bodyParts.push('Conversation Transcript:');
			bodyParts.push('');
			for (var i = 0; i < self.messages.length; i++) {
				var msg = self.messages[i];
				if (msg.role !== 'user' && msg.role !== 'assistant') continue;
				var role = msg.role === 'user' ? 'User' : 'Assistant';
				bodyParts.push(role + ': ' + msg.content);
				bodyParts.push('');
			}
		}

		return fetch(cfg.escalationUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			body: JSON.stringify({
				subject: payload.subject,
				body: bodyParts.join('\n'),
			}),
			credentials: 'same-origin',
			signal: abortSignal,
		}).then(function (res) {
			// Parse the response body once. If it's not JSON (HTML error
			// page, empty body, etc.) surface the status code instead of
			// letting the JSON parse error bubble up opaquely.
			return res.text().then(function (text) {
				var data = null;
				if (text) {
					try { data = JSON.parse(text); } catch (e) { /* ignore */ }
				}
				if (res.ok && data && data.status === 'sent') {
					return data;
				}
				var msg = (data && (data.message || data.error)) || ('HTTP ' + res.status);
				throw new Error(msg);
			});
		});
	};

	SetupAssistantDrawer.prototype.showWelcome = function () {
		var name = (window.defSetupAssistant && window.defSetupAssistant.userFirstName) || '';

		var div = document.createElement('div');
		div.className = 'def-sa-welcome';

		var intro = document.createElement('strong');
		intro.textContent = (name ? 'Hi ' + name + '!' : 'Hi there!') + ' I\'m your Setup Assistant.';
		div.appendChild(intro);

		div.appendChild(document.createElement('br'));
		div.appendChild(document.createElement('br'));
		div.appendChild(document.createTextNode('Here\u2019s what I can help you with:'));

		var ul = document.createElement('ul');
		var capabilities = [
			'Check your plugin setup status',
			'Test the connection to the DEF backend',
			'Configure display name, chat mode, colors and escalation emails',
			'Find users and manage their DEF access roles',
			'Troubleshoot connection issues or chat widget problems',
			'Guide you through the full setup flow'
		];
		for (var i = 0; i < capabilities.length; i++) {
			var li = document.createElement('li');
			li.textContent = capabilities[i];
			ul.appendChild(li);
		}
		div.appendChild(ul);

		div.appendChild(document.createTextNode('What would you like help with?'));

		this.messagesEl.appendChild(div);
		this.scrollToBottom();
	};

	SetupAssistantDrawer.prototype.showTypingIndicator = function () {
		this.hideTypingIndicator();
		var div = document.createElement('div');
		div.className = 'def-sa-typing';
		div.id = 'def-sa-typing-indicator';
		div.innerHTML =
			'<span class="def-sa-typing-dot"></span>' +
			'<span class="def-sa-typing-dot"></span>' +
			'<span class="def-sa-typing-dot"></span>';
		this.messagesEl.appendChild(div);
		this.scrollToBottom();
	};

	SetupAssistantDrawer.prototype.hideTypingIndicator = function () {
		var indicator = document.getElementById('def-sa-typing-indicator');
		if (indicator && indicator.parentNode) {
			indicator.parentNode.removeChild(indicator);
		}
	};

	SetupAssistantDrawer.prototype.renderError = function (message) {
		var div = document.createElement('div');
		div.className = 'def-sa-error';
		div.textContent = message;
		this.messagesEl.appendChild(div);
		this.scrollToBottom();
	};

	SetupAssistantDrawer.prototype.scrollToBottom = function () {
		var el = this.messagesEl;
		// Use requestAnimationFrame for smooth scroll after DOM update.
		requestAnimationFrame(function () {
			el.scrollTop = el.scrollHeight;
		});
	};

	// ─── Tool Outputs Processing ─────────────────────────────────

	SetupAssistantDrawer.prototype.processToolOutputs = function (outputs) {
		for (var i = 0; i < outputs.length; i++) {
			var output = outputs[i];
			var statusEl = this.renderToolStatus(output);

			// Process ui_actions from this tool output.
			if (output.ui_actions && output.ui_actions.length) {
				this.processUiActions(output.ui_actions);
			}

			// Check for escalation.
			if (output.escalation) {
				this.renderEscalationCard(output.escalation);
			}

			// Mark tool status as complete (replace spinner with checkmark).
			this.completeToolStatus(statusEl, output.tool_name);
		}
	};

	SetupAssistantDrawer.prototype.completeToolStatus = function (statusEl, toolName) {
		if (!statusEl) {
			return;
		}
		var spinner = statusEl.querySelector('.def-sa-spinner');
		if (spinner) {
			spinner.className = 'def-sa-check';
			spinner.innerHTML = '&#10003;';
		}
		// Update label to "done" text.
		var doneLabel = TOOL_DONE_LABELS[toolName] || 'Done';
		var labelEl = statusEl.querySelector('.def-sa-tool-label');
		if (labelEl) {
			labelEl.textContent = doneLabel;
		}
		statusEl.classList.add('def-sa-tool-done');
	};

	// ─── ui_actions Interpreter ──────────────────────────────────

	SetupAssistantDrawer.prototype.processUiActions = function (actions) {
		for (var i = 0; i < actions.length; i++) {
			var action = actions[i];
			switch (action.action) {
				case 'highlight_tab':
					this.handleHighlightTab(action);
					break;
				case 'scroll_to':
					this.handleScrollTo(action);
					break;
				case 'update_field':
					this.handleUpdateField(action);
					break;
				case 'show_toast':
					this.handleShowToast(action);
					break;
				case 'update_user_row':
					this.handleUpdateUserRow(action);
					break;
				default:
					if (typeof console !== 'undefined' && console.log) {
						console.log('[DEF SA] Unknown ui_action:', action.action);
					}
			}
		}
	};

	SetupAssistantDrawer.prototype.handleHighlightTab = function (action) {
		var tab = action.tab;
		if (VALID_TABS.indexOf(tab) === -1) {
			return;
		}

		if (window.DEFAdmin && window.DEFAdmin.switchTab) {
			window.DEFAdmin.switchTab(tab);
		}

		// Apply highlight animation to the tab button.
		var tabEl = document.getElementById('tab-' + tab);
		if (tabEl) {
			tabEl.classList.remove('def-sa-highlighted');
			// Force reflow.
			void tabEl.offsetWidth;
			tabEl.classList.add('def-sa-highlighted');
		}
	};

	SetupAssistantDrawer.prototype.handleScrollTo = function (action) {
		var sectionId = action.section;
		if (!sectionId || typeof sectionId !== 'string') {
			return;
		}
		// Sanitize: only alphanumeric and hyphens.
		sectionId = sectionId.replace(/[^a-zA-Z0-9\-_]/g, '');

		var el = document.getElementById('section-' + sectionId);
		if (el) {
			el.scrollIntoView({ behavior: 'smooth', block: 'start' });
			el.classList.remove('def-sa-highlighted');
			void el.offsetWidth;
			el.classList.add('def-sa-highlighted');
		}
	};

	SetupAssistantDrawer.prototype.handleUpdateField = function (action) {
		var fieldKey = action.field;
		var value = action.value;

		if (!fieldKey || value === undefined || value === null) {
			return;
		}

		var fieldConfig = FIELD_MAP[fieldKey];
		if (!fieldConfig) {
			if (typeof console !== 'undefined' && console.warn) {
				console.warn('[DEF SA] update_field: unknown FIELD_MAP key', fieldKey);
			}
			return;
		}

		// Cap value at max_length.
		value = String(value);
		if (fieldConfig.max_length && value.length > fieldConfig.max_length) {
			value = value.slice(0, fieldConfig.max_length);
		}

		// Handle radio type.
		if (fieldConfig.type === 'radio') {
			var radio = document.querySelector(
				'input[name="' + fieldConfig.name + '"][value="' + this.escapeAttr(value) + '"]'
			);
			if (radio) {
				radio.checked = true;
				this.triggerChangeEvent(radio);
			}
			return;
		}

		// Handle standard input/textarea.
		var el = document.getElementById(fieldConfig.id);
		if (el) {
			el.value = value;
			this.triggerChangeEvent(el);

			// Color inputs: update the preview span directly and force swatch repaint.
			if (fieldConfig.type === 'color') {
				var valueSpan = el.parentElement && el.parentElement.querySelector('.def-core-color-value');
				if (valueSpan) {
					valueSpan.textContent = value;
				}
				// Force the native color picker to repaint by toggling the attribute.
				el.setAttribute('value', value);
				try {
					el.dispatchEvent(new Event('input', { bubbles: true }));
				} catch (e) {
					// IE fallback — not critical.
				}
			}

			// Brief highlight.
			el.classList.remove('def-sa-highlighted');
			void el.offsetWidth;
			el.classList.add('def-sa-highlighted');
		}
	};

	SetupAssistantDrawer.prototype.handleShowToast = function (action) {
		var validTypes = ['success', 'error'];
		var type = validTypes.indexOf(action.type) !== -1 ? action.type : 'success';
		var message = String(action.message || '').slice(0, 200);

		if (!message) {
			return;
		}

		if (window.DEFAdmin && window.DEFAdmin.showToast) {
			window.DEFAdmin.showToast(message, type);
		}
	};

	SetupAssistantDrawer.prototype.handleUpdateUserRow = function (action) {
		var userId = action.wp_user_id;
		var caps = action.capabilities || {};
		if (!userId) return;

		var tbody = document.getElementById('def-core-roles-tbody');
		if (!tbody) return;

		var row = tbody.querySelector('tr[data-user-id="' + userId + '"]');
		if (row) {
			// Update existing row checkboxes.
			var checkboxes = row.querySelectorAll('input[type="checkbox"]');
			for (var i = 0; i < checkboxes.length; i++) {
				var cap = checkboxes[i].getAttribute('data-cap');
				if (cap && caps.hasOwnProperty(cap)) {
					checkboxes[i].checked = !!caps[cap];
				}
			}
		} else if (window.DEFAdmin && window.DEFAdmin.addUserRow) {
			// New user — add row via existing admin function.
			window.DEFAdmin.addUserRow({
				id: userId,
				display_name: action.display_name || '',
				user_email: action.email || '',
				wp_role: action.wp_role || '',
				avatar: action.avatar || '',
				caps: caps,
			});
			row = tbody.querySelector('tr[data-user-id="' + userId + '"]');
		}

		// Flash highlight on the updated row so the change is visible.
		if (row) {
			row.classList.remove('def-core-row-flash');
			void row.offsetWidth; // force reflow to restart animation
			row.classList.add('def-core-row-flash');
		}
	};

	// ─── Dirty Field Tracking ────────────────────────────────────

	SetupAssistantDrawer.prototype.captureSettingsSnapshot = function () {
		var wrap = document.querySelector('.def-core-wrap');
		if (!wrap) {
			return;
		}

		var self = this;
		this.settingsSnapshot = {};

		// Capture all [data-setting] inputs.
		var fields = wrap.querySelectorAll('[data-setting]');
		fields.forEach(function (el) {
			var key = el.getAttribute('data-setting');
			if (key) {
				self.settingsSnapshot[key] = el.value || '';
			}
		});

		// Capture radio groups.
		var radios = wrap.querySelectorAll('input[type="radio"]:checked');
		radios.forEach(function (radio) {
			var name = radio.getAttribute('name');
			if (name) {
				self.settingsSnapshot['radio:' + name] = radio.value;
			}
		});
	};

	SetupAssistantDrawer.prototype.trackDirtyFields = function () {
		var wrap = document.querySelector('.def-core-wrap');
		if (!wrap) {
			return;
		}

		var self = this;

		wrap.addEventListener('input', function (e) {
			self.checkDirty(e.target);
		});

		wrap.addEventListener('change', function (e) {
			self.checkDirty(e.target);
		});
	};

	SetupAssistantDrawer.prototype.checkDirty = function (el) {
		// Handle data-setting elements.
		var key = el.getAttribute('data-setting');
		if (key) {
			var original = this.settingsSnapshot[key];
			if (el.value !== original) {
				this.dirtyFields[key] = el.value;
			} else {
				delete this.dirtyFields[key];
			}
			return;
		}

		// Handle radio inputs.
		if (el.type === 'radio' && el.checked) {
			var name = el.getAttribute('name');
			if (name) {
				var snapshotKey = 'radio:' + name;
				var original = this.settingsSnapshot[snapshotKey];
				if (el.value !== original) {
					this.dirtyFields[snapshotKey] = el.value;
				} else {
					delete this.dirtyFields[snapshotKey];
				}
			}
		}
	};

	SetupAssistantDrawer.prototype.getDirtyContext = function () {
		var keys = Object.keys(this.dirtyFields);
		if (!keys.length) {
			return {};
		}
		// Redact secret fields — send boolean only, never raw values.
		var safe = {};
		for (var i = 0; i < keys.length; i++) {
			var key = keys[i];
			if (SECRET_FIELDS.indexOf(key) !== -1) {
				safe[key] = '[changed]';
			} else {
				safe[key] = this.dirtyFields[key];
			}
		}
		return { dirty_fields: safe };
	};

	// ─── Thread Persistence ──────────────────────────────────────

	SetupAssistantDrawer.prototype.getOrCreateThread = function () {
		var self = this;

		// Check localStorage first (fast).
		try {
			var cached = localStorage.getItem(LS_THREAD_KEY);
			if (cached) {
				self.threadId = cached;
				return;
			}
		} catch (e) {
			// localStorage unavailable.
		}

		// Check REST (cross-browser consistency).
		this.apiRequest('setup/thread', 'GET')
			.then(function (data) {
				var threadId = data && data.data && data.data.thread_id;
				if (threadId) {
					self.threadId = threadId;
					try {
						localStorage.setItem(LS_THREAD_KEY, threadId);
					} catch (e) {
						// Ignore.
					}
				}
			})
			.catch(function () {
				// Thread not found — will be created on first message.
			});
	};

	SetupAssistantDrawer.prototype.saveThread = function (threadId) {
		try {
			localStorage.setItem(LS_THREAD_KEY, threadId);
		} catch (e) {
			// Ignore.
		}

		// Best-effort REST save.
		this.apiRequest('setup/thread', 'POST', { thread_id: threadId })
			.catch(function () {
				// Non-critical.
			});
	};

	SetupAssistantDrawer.prototype.clearConversation = function () {
		var self = this;

		// Clear UI.
		this.messages = [];
		this.messagesEl.innerHTML = '';
		this.showWelcome();

		// Clear thread.
		this.threadId = null;
		try {
			localStorage.removeItem(LS_THREAD_KEY);
		} catch (e) {
			// Ignore.
		}

		// Delete from REST.
		this.apiRequest('setup/thread', 'DELETE')
			.catch(function () {
				// Non-critical.
			});
	};

	// ─── First-Visit Auto-Open ───────────────────────────────────

	SetupAssistantDrawer.prototype.checkFirstVisit = function () {
		var self = this;

		// Check localStorage first (fast).
		try {
			if (localStorage.getItem(LS_SEEN_KEY)) {
				self.maybePulse();
				return;
			}
		} catch (e) {
			// Ignore.
		}

		// Check REST.
		this.apiRequest('setup/seen', 'GET')
			.then(function (data) {
				var seen = data && data.data && data.data.seen;
				if (seen) {
					try {
						localStorage.setItem(LS_SEEN_KEY, '1');
					} catch (e) {
						// Ignore.
					}
					self.maybePulse();
				} else {
					// First visit — auto-open.
					self.open();
					self.showWelcome();
					self.markAsSeen();
				}
			})
			.catch(function () {
				// On error, treat as first visit.
				self.open();
				self.showWelcome();
				self.markAsSeen();
			});
	};

	SetupAssistantDrawer.prototype.markAsSeen = function () {
		try {
			localStorage.setItem(LS_SEEN_KEY, '1');
		} catch (e) {
			// Ignore.
		}

		// Best-effort REST save.
		this.apiRequest('setup/seen', 'POST')
			.catch(function () {
				// Non-critical.
			});
	};

	SetupAssistantDrawer.prototype.maybePulse = function () {
		if (!this.threadId) {
			var self = this;
			this.triggerEl.classList.add('def-sa-trigger-pulse');
			setTimeout(function () {
				self.triggerEl.classList.remove('def-sa-trigger-pulse');
			}, 10000);
		}
	};

	// ─── Focus Management (WCAG) ─────────────────────────────────

	SetupAssistantDrawer.prototype.handleFocusTrap = function (e) {
		if (!this.isOpen) {
			return;
		}

		var focusable = this.panelEl.querySelectorAll(
			'button:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
		);

		if (!focusable.length) {
			return;
		}

		var first = focusable[0];
		var last  = focusable[focusable.length - 1];

		if (e.shiftKey) {
			// Shift+Tab: if on first focusable, wrap to last.
			if (document.activeElement === first) {
				e.preventDefault();
				last.focus();
			}
		} else {
			// Tab: if on last focusable, wrap to first.
			if (document.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		}
	};

	// ─── Textarea Auto-Resize ────────────────────────────────────

	SetupAssistantDrawer.prototype.autoResizeInput = function () {
		var el = this.inputEl;
		el.style.height = 'auto';
		var scrollHeight = el.scrollHeight;
		// Clamp to max-height from CSS (120px).
		el.style.height = Math.min(scrollHeight, 120) + 'px';
	};

	// ─── API Helper ──────────────────────────────────────────────

	SetupAssistantDrawer.prototype.apiRequest = function (endpoint, method, body) {
		var url = this.apiBase + endpoint;
		var options = {
			method: method,
			headers: {
				'X-WP-Nonce': this.nonce,
				'Content-Type': 'application/json'
			},
			credentials: 'same-origin'
		};

		if (body && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
			options.body = JSON.stringify(body);
		}

		return fetch(url, options)
			.then(function (response) {
				if (!response.ok) {
					return response.json().then(function (data) {
						var msg = (data && data.error && data.error.message) || 'Request failed.';
						throw new Error(msg);
					}).catch(function (parseErr) {
						if (parseErr.message && parseErr.message !== 'Request failed.') {
							throw parseErr;
						}
						throw new Error('HTTP ' + response.status + ' error.');
					});
				}
				return response.json();
			});
	};

	// ─── Markdown Formatter ──────────────────────────────────────

	SetupAssistantDrawer.prototype.formatMarkdown = function (text) {
		if (!text) {
			return '';
		}

		// Escape HTML first.
		var html = this.escapeHtml(text);

		// Bold: **text** or __text__
		html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
		html = html.replace(/__(.+?)__/g, '<strong>$1</strong>');

		// Italic: *text* or _text_ (but not inside words with underscores).
		html = html.replace(/(?<!\w)\*([^*]+?)\*(?!\w)/g, '<em>$1</em>');
		html = html.replace(/(?<!\w)_([^_]+?)_(?!\w)/g, '<em>$1</em>');

		// Code: `text`
		html = html.replace(/`([^`]+?)`/g, '<code>$1</code>');

		// Links: [text](url) — only allow http/https.
		html = html.replace(/\[([^\]]+?)\]\((https?:\/\/[^)]+?)\)/g,
			'<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

		// Unordered lists: lines starting with - or *.
		html = html.replace(/(?:^|\n)((?:[\-\*] .+(?:\n|$))+)/g, function (match, listBlock) {
			var items = listBlock.trim().split('\n').map(function (line) {
				return '<li>' + line.replace(/^[\-\*]\s+/, '') + '</li>';
			}).join('');
			return '<ul>' + items + '</ul>';
		});

		// Line breaks.
		html = html.replace(/\n/g, '<br>');

		return html;
	};

	// ─── Utility ─────────────────────────────────────────────────

	SetupAssistantDrawer.prototype.escapeHtml = function (str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	};

	SetupAssistantDrawer.prototype.escapeAttr = function (str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	};

	SetupAssistantDrawer.prototype.triggerChangeEvent = function (el) {
		var event;
		try {
			event = new Event('change', { bubbles: true });
		} catch (e) {
			// IE fallback.
			event = document.createEvent('Event');
			event.initEvent('change', true, true);
		}
		el.dispatchEvent(event);

		try {
			event = new Event('input', { bubbles: true });
		} catch (e) {
			event = document.createEvent('Event');
			event.initEvent('input', true, true);
		}
		el.dispatchEvent(event);
	};

	// ─── Initialize ──────────────────────────────────────────────

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			new SetupAssistantDrawer();
		});
	} else {
		new SetupAssistantDrawer();
	}
})();
