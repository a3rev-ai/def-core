/**
 * Staff AI - Standalone application JavaScript.
 *
 * Configuration is provided by StaffAIConfig global object
 * defined in the HTML template before this script loads.
 */

/**
 * Translation helper — returns i18n string from StaffAIConfig or fallback.
 */
function t(key, fallback) {
	return (StaffAIConfig && StaffAIConfig.i18n && StaffAIConfig.i18n[key]) || fallback || key;
}

(function() {
	'use strict';

	const channel = StaffAIConfig.channel;
	const userId = StaffAIConfig.userId;
	const userEmail = StaffAIConfig.userEmail;
	const apiBase = StaffAIConfig.apiBase;
	const nonce = StaffAIConfig.nonce;

	// HTML escape helper
	function escapeHtml(str) {
		const div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	// API helper function
	async function apiRequest(endpoint, options = {}) {
		const url = apiBase + endpoint;
		const headers = {
			'X-WP-Nonce': nonce,
			'Content-Type': 'application/json',
			...options.headers
		};
		const response = await fetch(url, {
			...options,
			headers,
			credentials: 'same-origin'
		});
		const data = await response.json();
		if (!response.ok) {
			const errorCode = data.code || data.error || '';
			const errorMsg = data.message || data.error || 'Request failed';
			console.error('Staff AI API error:', {
				code: errorCode,
				message: errorMsg,
				data: data
			});
			throw new Error(errorCode ? '[' + errorCode + '] ' + errorMsg : errorMsg);
		}
		return data;
	}

	// Elements
	const sidebar = document.getElementById('sidebar');
	const sidebarOverlay = document.getElementById('sidebarOverlay');
	const menuToggle = document.getElementById('menuToggle');
	const newChatBtn = document.getElementById('newChatBtn');
	const conversationList = document.getElementById('conversationList');
	const conversationPlaceholder = document.getElementById('conversationPlaceholder');
	const messagesContainer = document.getElementById('messagesContainer');
	const messagesList = document.getElementById('messagesList');
	const welcomeMessage = document.getElementById('welcomeMessage');
	const readonlyIndicator = document.getElementById('readonlyIndicator');
	const shareBtn = document.getElementById('shareBtn');
	const infoBanner = document.getElementById('infoBanner');
	const errorBanner = document.getElementById('errorBanner');
	const composerContainer = document.getElementById('composerContainer');
	const composerInput = document.getElementById('composerInput');
	const sendBtn = document.getElementById('sendBtn');
	// Share modal elements
	const shareModal = document.getElementById('shareModal');
	const shareModalClose = document.getElementById('shareModalClose');
	const shareLoading = document.getElementById('shareLoading');
	const shareError = document.getElementById('shareError');
	const shareErrorText = document.getElementById('shareErrorText');
	const shareErrorClose = document.getElementById('shareErrorClose');
	const shareFormContent = document.getElementById('shareFormContent');
	const shareTokenSelect = document.getElementById('shareRecipientTokenSelect');
	const shareTokensContainer = document.getElementById('shareRecipientTokens');
	const shareTokenPlaceholder = document.getElementById('shareRecipientPlaceholder');
	const shareDropdown = document.getElementById('shareRecipientDropdown');
	let shareSelectedRecipients = [];
	let shareAvailableRecipients = [];
	const shareSubject = document.getElementById('shareSubject');
	const shareMessage = document.getElementById('shareMessage');
	const shareTranscript = document.getElementById('shareTranscript');
	const shareCancel = document.getElementById('shareCancel');
	const shareSend = document.getElementById('shareSend');

	// State
	let conversations = [];
	let currentConversationId = null;
	let messages = [];
	let isLoading = false;
	let isReadOnly = false;

	// Initialize
	loadConversations();

	// Sidebar toggle (mobile)
	function toggleSidebar() {
		sidebar.classList.toggle('open');
		sidebarOverlay.classList.toggle('visible');
	}

	menuToggle.addEventListener('click', toggleSidebar);
	sidebarOverlay.addEventListener('click', toggleSidebar);

	// Theme toggle
	const themeToggle = document.getElementById('themeToggle');
	themeToggle.addEventListener('click', function() {
		const isDark = document.documentElement.classList.toggle('dark-theme');
		localStorage.setItem('staff-ai-theme', isDark ? 'dark' : 'light');
	});

	// Load conversations from backend
	async function loadConversations() {
		try {
			const result = await apiRequest('/conversations');
			conversations = result.conversations || [];
			renderConversationList();
			hideError();
		} catch (err) {
			console.error('Failed to load conversations:', err);
			conversations = [];
			renderConversationList();
			// Show detailed error with troubleshooting hint
			let errorMsg = err.message || t('failedToConnect', 'Failed to connect to backend service.');
			errorMsg += ' ' + t('checkStatus', 'Check /wp-json/a3-ai/v1/staff-ai/status for diagnostics.');
			showError(errorMsg);
		}
	}

	// Render conversation list
	function renderConversationList() {
		// Remove existing items
		const items = conversationList.querySelectorAll('.conversation-item');
		items.forEach(el => el.remove());

		if (conversations.length === 0) {
			conversationPlaceholder.style.display = 'block';
			return;
		}

		conversationPlaceholder.style.display = 'none';

		conversations.forEach(function(conv) {
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'conversation-item';
			if (conv.id === currentConversationId) {
				btn.classList.add('active');
			}
			btn.dataset.id = conv.id;

			const title = document.createElement('span');
			title.className = 'conversation-item-title';
			title.textContent = conv.title || t('newConversation', 'New conversation');

			const time = document.createElement('span');
			time.className = 'conversation-item-time';
			time.textContent = formatTime(conv.updated_at);

			btn.appendChild(title);
			btn.appendChild(time);
			btn.addEventListener('click', function() {
				loadConversation(conv.id, conv.is_shared);
			});

			conversationList.insertBefore(btn, conversationPlaceholder);
		});
	}

	// Format time for display
	function formatTime(timestamp) {
		if (!timestamp) return '';
		const date = new Date(timestamp);
		const now = new Date();
		const diff = now - date;
		if (diff < 86400000) {
			return date.toLocaleTimeString([], {
				hour: '2-digit',
				minute: '2-digit'
			});
		}
		return date.toLocaleDateString();
	}

	// Load a specific conversation
	async function loadConversation(id, shared) {
		currentConversationId = id;
		isReadOnly = !!shared;

		// Update UI state
		updateReadOnlyState();
		renderConversationList();

		try {
			const result = await apiRequest('/conversations/' + encodeURIComponent(id));
			messages = result.messages || [];
			renderMessages();
		} catch (err) {
			console.error('Failed to load conversation:', err);
			showError(t('failedToLoad', 'Failed to load conversation.'));
		}

		if (window.innerWidth <= 768) {
			toggleSidebar();
		}
	}

	// Update read-only state
	function updateReadOnlyState() {
		if (isReadOnly) {
			readonlyIndicator.classList.add('visible');
			composerContainer.classList.add('disabled');
		} else {
			readonlyIndicator.classList.remove('visible');
			composerContainer.classList.remove('disabled');
		}
		shareBtn.disabled = !currentConversationId;
	}

	// New chat
	newChatBtn.addEventListener('click', function() {
		currentConversationId = null;
		isReadOnly = false;
		messages = [];
		updateReadOnlyState();
		renderConversationList();
		renderMessages();
		composerInput.value = '';
		updateSendButton();
		hideError();
		hideInfo();
		if (window.innerWidth <= 768) {
			toggleSidebar();
		}
	});

	// Auto-resize textarea
	function autoResize() {
		composerInput.style.height = 'auto';
		composerInput.style.height = Math.min(composerInput.scrollHeight, 200) + 'px';
	}

	composerInput.addEventListener('input', function() {
		autoResize();
		updateSendButton();
	});

	// Update send button state
	function updateSendButton() {
		sendBtn.disabled = !composerInput.value.trim() || isLoading || isReadOnly;
	}

	// Keyboard handler: Enter to send, Shift+Enter for newline
	composerInput.addEventListener('keydown', function(e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			if (!sendBtn.disabled) {
				sendMessage();
			}
		}
	});

	sendBtn.addEventListener('click', sendMessage);

	// Show info
	function showInfo(msg) {
		infoBanner.textContent = msg;
		infoBanner.classList.add('visible');
	}

	// Hide info
	function hideInfo() {
		infoBanner.classList.remove('visible');
	}

	// Show error
	function showError(msg) {
		errorBanner.textContent = msg;
		errorBanner.classList.add('visible');
	}

	// Hide error
	function hideError() {
		errorBanner.classList.remove('visible');
	}

	// Render messages
	function renderMessages() {
		if (messages.length === 0) {
			welcomeMessage.style.display = 'block';
			const msgElements = messagesList.querySelectorAll('.message');
			msgElements.forEach(el => el.remove());
			return;
		}

		welcomeMessage.style.display = 'none';

		const msgElements = messagesList.querySelectorAll('.message');
		msgElements.forEach(el => el.remove());

		messages.forEach(function(msg) {
			// Share event banner (green)
			if (msg.role === 'share_event') {
				const el = document.createElement('div');
				el.className = 'message message-share-event';
				const ts = msg.timestamp ? new Date(msg.timestamp) : new Date();
				const dateStr = ts.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
				const timeStr = ts.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
				el.innerHTML = '<span class="message-share-event-content">' +
					t('sharedWith', 'Shared with') + ' ' +
					escapeHtml(msg.content) + ' · ' + escapeHtml(dateStr) + ' ' + escapeHtml(timeStr) +
					'</span>';
				messagesList.appendChild(el);
				return;
			}

			// Error event banner (red)
			if (msg.role === 'error_event') {
				const el = document.createElement('div');
				el.className = 'message message-error-event';
				const ts = msg.timestamp ? new Date(msg.timestamp) : new Date();
				const dateStr = ts.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
				const timeStr = ts.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
				el.innerHTML = '<span class="message-error-event-content">' +
					t('shareFailed', 'Share failed') + ': ' +
					escapeHtml(msg.content) + ' · ' + escapeHtml(dateStr) + ' ' + escapeHtml(timeStr) +
					'</span>';
				messagesList.appendChild(el);
				return;
			}

			const div = document.createElement('div');
			div.className = 'message message-' + msg.role;

			const avatar = document.createElement('div');
			avatar.className = 'message-avatar';
			avatar.textContent = msg.role === 'user' ? 'U' : 'AI';

			const content = document.createElement('div');
			content.className = 'message-content';

			if (msg.isTyping) {
				const indicator = document.createElement('div');
				indicator.className = 'typing-indicator';
				indicator.innerHTML = '<span></span><span></span><span></span>';
				content.appendChild(indicator);
			} else {
				content.textContent = msg.content;

				// Render tool outputs if present
				if (msg.tool_outputs && msg.tool_outputs.length > 0) {
					msg.tool_outputs.forEach(function(tool) {
						const card = createToolOutputCard(tool);
						content.appendChild(card);
					});
				}
			}

			div.appendChild(avatar);
			div.appendChild(content);
			messagesList.appendChild(div);
		});

		messagesContainer.scrollTop = messagesContainer.scrollHeight;
	}

	// Helper function to rewrite download URLs to use WordPress endpoint
	function rewriteDownloadUrl(url) {
		if (!url || url === '#') return url;
		// Convert /api/files/{tenant}/{filename} to /staff-ai-download/{tenant}/{filename}
		if (url.startsWith('/api/files/')) {
			return StaffAIConfig.homeUrl + 'staff-ai-download/' + url.replace('/api/files/', '');
		}
		return url;
	}

	// Create tool output card
	function createToolOutputCard(tool) {
		// Escalation offer — inline suggestion card
		if (tool.type === 'escalation_offer') {
			const card = document.createElement('div');
			card.className = 'escalation-suggestion';

			const header = document.createElement('div');
			header.className = 'escalation-suggestion-header';
			header.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> ' + t('internalHandoff', 'Internal Handoff Suggested');

			const reason = document.createElement('div');
			reason.className = 'escalation-suggestion-reason';
			reason.textContent = tool.reason || '';

			const hint = document.createElement('div');
			hint.className = 'escalation-suggestion-hint';
			hint.textContent = t('shareHint', 'Use the Share button to hand off this conversation to another team member.');

			card.appendChild(header);
			card.appendChild(reason);
			card.appendChild(hint);

			return card;
		}

		// File output — download card
		const card = document.createElement('div');
		card.className = 'tool-output-card';

		const icon = document.createElement('div');
		icon.className = 'tool-output-icon';
		icon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>';

		const info = document.createElement('div');
		info.className = 'tool-output-info';

		const name = document.createElement('div');
		name.className = 'tool-output-name';
		name.textContent = tool.file_name || t('download', 'Download');

		const type = document.createElement('div');
		type.className = 'tool-output-type';
		type.textContent = tool.file_type || t('file', 'File');

		info.appendChild(name);
		info.appendChild(type);

		const download = document.createElement('a');
		download.className = 'tool-output-download';
		download.href = rewriteDownloadUrl(tool.download_url) || '#';
		download.target = '_blank';
		download.rel = 'noopener';
		download.textContent = t('download', 'Download');

		card.appendChild(icon);
		card.appendChild(info);
		card.appendChild(download);

		return card;
	}

	// Send message
	async function sendMessage() {
		const text = composerInput.value.trim();
		if (!text || isLoading || isReadOnly) return;

		hideError();
		hideInfo();

		messages.push({
			role: 'user',
			content: text
		});
		renderMessages();

		composerInput.value = '';
		autoResize();
		updateSendButton();

		messages.push({
			role: 'assistant',
			content: '',
			isTyping: true
		});
		renderMessages();

		isLoading = true;
		updateSendButton();

		try {
			const result = await apiRequest('/chat', {
				method: 'POST',
				body: JSON.stringify({
					message: text,
					thread_id: currentConversationId
				})
			});

			messages.pop();
			messages.push({
				role: 'assistant',
				content: result.message?.content || '',
				tool_outputs: result.message?.tool_outputs || []
			});
			renderMessages();

			// Update conversation ID from response
			if (result.thread_id) {
				currentConversationId = result.thread_id;
			}

			// Refresh conversation list
			loadConversations();

			updateReadOnlyState();
		} catch (err) {
			messages.pop();
			renderMessages();
			console.error('Failed to send message:', err);
			showError(err.message || t('failedToSend', 'Failed to send message. Please try again.'));
		} finally {
			isLoading = false;
			updateSendButton();
		}
	}

	// =============================================
	// SHARE MODAL — AI-generated subject + summary
	// =============================================

	function showShareLoading() {
		shareLoading.style.display = '';
		shareError.style.display = 'none';
		shareFormContent.style.display = 'none';
	}

	function showShareError(msg) {
		shareLoading.style.display = 'none';
		shareError.style.display = '';
		shareFormContent.style.display = 'none';
		shareErrorText.textContent = msg;
	}

	function showShareForm() {
		shareLoading.style.display = 'none';
		shareError.style.display = 'none';
		shareFormContent.style.display = '';
	}

	function updateShareSendButton() {
		const hasRecipient = shareSelectedRecipients.length > 0;
		const hasSubject = shareSubject.value.trim() !== '';
		const hasMessage = shareMessage.value.trim() !== '';
		shareSend.disabled = !(hasRecipient && hasSubject && hasMessage);
	}

	// Enable/disable Send when fields change
	shareSubject.addEventListener('input', updateShareSendButton);
	shareMessage.addEventListener('input', updateShareSendButton);

	// ---- Token select component ----

	function renderTokenSelect() {
		// Clear tokens (keep placeholder)
		var chips = shareTokensContainer.querySelectorAll('.token-select-chip');
		chips.forEach(function(c) { c.remove(); });

		// Show/hide placeholder
		shareTokenPlaceholder.style.display = shareSelectedRecipients.length > 0 ? 'none' : '';

		// Add chips for selected recipients
		shareSelectedRecipients.forEach(function(email) {
			var chip = document.createElement('span');
			chip.className = 'token-select-chip';
			chip.innerHTML = escapeHtml(email) + '<span class="token-select-chip-remove" data-email="' + escapeHtml(email) + '">&times;</span>';
			shareTokensContainer.insertBefore(chip, shareTokenPlaceholder);
		});

		// Render dropdown options (only unselected)
		shareDropdown.innerHTML = '';
		var unselected = shareAvailableRecipients.filter(function(e) {
			return shareSelectedRecipients.indexOf(e) === -1;
		});
		if (unselected.length === 0) {
			var empty = document.createElement('div');
			empty.className = 'token-select-empty';
			empty.textContent = t('allRecipientsSelected', 'All recipients selected');
			shareDropdown.appendChild(empty);
		} else {
			unselected.forEach(function(email) {
				var opt = document.createElement('div');
				opt.className = 'token-select-option';
				opt.textContent = email;
				opt.dataset.email = email;
				shareDropdown.appendChild(opt);
			});
		}

		updateShareSendButton();
	}

	// Open/close dropdown
	shareTokensContainer.addEventListener('click', function(e) {
		if (e.target.classList.contains('token-select-chip-remove')) {
			// Remove chip
			var email = e.target.dataset.email;
			shareSelectedRecipients = shareSelectedRecipients.filter(function(r) { return r !== email; });
			renderTokenSelect();
			shareDropdown.classList.add('open');
			shareTokensContainer.classList.add('active');
			return;
		}
		// Toggle dropdown
		var isOpen = shareDropdown.classList.contains('open');
		if (isOpen) {
			shareDropdown.classList.remove('open');
			shareTokensContainer.classList.remove('active');
		} else {
			shareDropdown.classList.add('open');
			shareTokensContainer.classList.add('active');
		}
	});

	// Select option from dropdown
	shareDropdown.addEventListener('click', function(e) {
		var opt = e.target.closest('.token-select-option');
		if (!opt) return;
		var email = opt.dataset.email;
		if (email && shareSelectedRecipients.indexOf(email) === -1) {
			shareSelectedRecipients.push(email);
			renderTokenSelect();
			// Keep dropdown open for more selections
			shareDropdown.classList.add('open');
			shareTokensContainer.classList.add('active');
		}
	});

	// Close dropdown on outside click
	document.addEventListener('click', function(e) {
		if (!shareTokenSelect.contains(e.target)) {
			shareDropdown.classList.remove('open');
			shareTokensContainer.classList.remove('active');
		}
	});

	// Open share modal
	shareBtn.addEventListener('click', async function() {
		if (!currentConversationId) return;
		shareModal.classList.add('visible');
		showShareLoading();

		try {
			// Fetch recipients + AI summary in parallel
			const [settingsResp, summaryResp] = await Promise.all([
				apiRequest('/share-settings', {
					method: 'GET'
				}).catch(function() { throw new Error('Failed to load recipients'); }),
				apiRequest('/conversations/' + encodeURIComponent(currentConversationId) + '/summarize', {
					method: 'POST'
				})
			]);

			// Populate token select with recipients
			shareAvailableRecipients = (settingsResp && settingsResp.allowed_recipients) || [];
			shareSelectedRecipients = [];
			renderTokenSelect();

			// Populate subject + message from AI summary
			shareSubject.value = (summaryResp && summaryResp.suggested_subject) || '';
			shareMessage.value = (summaryResp && summaryResp.summary) || '';
			shareTranscript.checked = true;

			updateShareSendButton();
			showShareForm();
		} catch (err) {
			showShareError(err.message || t('failedToPrepareShare', 'Failed to prepare share form.'));
		}
	});

	// Send share email
	shareSend.addEventListener('click', async function() {
		if (shareSend.disabled || !currentConversationId) return;
		shareSend.disabled = true;

		try {
			if (shareSelectedRecipients.length === 0) return;
			const selectedRecipients = shareSelectedRecipients.slice();

			// Build body — message + optional transcript
			let bodyText = shareMessage.value;

			if (shareTranscript.checked && messages.length > 0) {
				bodyText += '\n\n---\nConversation Transcript:\n\n';
				messages.forEach(function(msg) {
					if (msg.role !== 'user' && msg.role !== 'assistant') return;
					const role = msg.role === 'user' ? 'User' : 'Assistant';
					bodyText += role + ': ' + msg.content + '\n\n';
				});
			}

			await apiRequest('/share-send', {
				method: 'POST',
				body: JSON.stringify({
					channel: 'staff_ai',
					to: selectedRecipients,
					subject: shareSubject.value,
					body: bodyText
				})
			});

			shareModal.classList.remove('visible');
			addShareEvent(selectedRecipients);
		} catch (err) {
			const errorMsg = err.message || t('failedToSendShare', 'Failed to send share email.');
			shareModal.classList.remove('visible');
			addErrorEvent(errorMsg);
		} finally {
			shareSend.disabled = false;
			updateShareSendButton();
		}
	});

	// Persist share event and add to message thread
	function addShareEvent(recipients) {
		const now = new Date().toISOString();
		const recipientList = Array.isArray(recipients) ? recipients : [recipients];

		// Add to local messages array for immediate display
		messages.push({
			role: 'share_event',
			content: recipientList.join(', '),
			timestamp: now,
		});
		renderMessages();

		// Persist to backend (fire-and-forget)
		apiRequest('/conversations/' + encodeURIComponent(currentConversationId) + '/share-event', {
			method: 'POST',
			body: JSON.stringify({
				type: 'share',
				recipients: recipientList,
				timestamp: now,
			})
		}).catch(function(err) {
			console.warn('Failed to persist share event:', err);
		});
	}

	// Persist error event and add to message thread
	function addErrorEvent(errorMsg) {
		const now = new Date().toISOString();

		// Add to local messages array for immediate display
		messages.push({
			role: 'error_event',
			content: errorMsg,
			timestamp: now,
		});
		renderMessages();

		// Persist to backend (fire-and-forget)
		if (currentConversationId) {
			apiRequest('/conversations/' + encodeURIComponent(currentConversationId) + '/share-event', {
				method: 'POST',
				body: JSON.stringify({
					type: 'error',
					message: errorMsg,
					timestamp: now,
				})
			}).catch(function(err) {
				console.warn('Failed to persist error event:', err);
			});
		}
	}

	// Close share modal handlers
	shareModalClose.addEventListener('click', function() {
		shareModal.classList.remove('visible');
	});

	shareCancel.addEventListener('click', function() {
		shareModal.classList.remove('visible');
	});

	shareErrorClose.addEventListener('click', function() {
		shareModal.classList.remove('visible');
	});

	shareModal.addEventListener('click', function(e) {
		if (e.target === shareModal) shareModal.classList.remove('visible');
	});

	// =============================================
	// CREATE TOOL MODAL
	// =============================================
	const createBtn = document.getElementById('createBtn');
	const createModal = document.getElementById('createModal');
	const createModalClose = document.getElementById('createModalClose');
	const createToolType = document.getElementById('createToolType');
	const createFormatGroup = document.getElementById('createFormatGroup');
	const createFormat = document.getElementById('createFormat');
	const createTitle = document.getElementById('createTitle');
	const createPrompt = document.getElementById('createPrompt');
	const createError = document.getElementById('createError');
	const createCancel = document.getElementById('createCancel');
	const createSubmit = document.getElementById('createSubmit');

	// Format options by tool type
	const formatOptions = {
		document_creation: [{
				value: 'docx',
				label: 'DOCX'
			},
			{
				value: 'pdf',
				label: 'PDF'
			},
			{
				value: 'md',
				label: 'Markdown'
			}
		],
		spreadsheet_creation: [{
				value: 'xlsx',
				label: 'XLSX'
			},
			{
				value: 'csv',
				label: 'CSV'
			}
		],
		image_generation: [{
			value: 'png',
			label: 'PNG'
		}]
	};

	// Update format options when tool type changes
	function updateFormatOptions() {
		const toolType = createToolType.value;
		const options = formatOptions[toolType] || [];
		createFormat.innerHTML = '';
		options.forEach(function(opt) {
			const option = document.createElement('option');
			option.value = opt.value;
			option.textContent = opt.label;
			createFormat.appendChild(option);
		});
		// Hide format group for image (only one option)
		createFormatGroup.style.display = toolType === 'image_generation' ? 'none' : 'block';
	}

	createToolType.addEventListener('change', updateFormatOptions);

	// Open create modal
	createBtn.addEventListener('click', function() {
		createToolType.value = 'document_creation';
		updateFormatOptions();
		createTitle.value = '';
		createPrompt.value = '';
		createError.classList.remove('visible');
		createError.textContent = '';
		createModal.classList.add('visible');
		createPrompt.focus();
	});

	// Close create modal
	createModalClose.addEventListener('click', function() {
		createModal.classList.remove('visible');
	});

	createCancel.addEventListener('click', function() {
		createModal.classList.remove('visible');
	});

	createModal.addEventListener('click', function(e) {
		if (e.target === createModal) createModal.classList.remove('visible');
	});

	// Submit tool creation via chat
	createSubmit.addEventListener('click', function() {
		const toolName = createToolType.value;
		const format = createFormat.value;
		const title = createTitle.value.trim();
		const prompt = createPrompt.value.trim();

		// Validate
		if (!prompt) {
			createError.textContent = t('instructionsRequired', 'Instructions are required.');
			createError.classList.add('visible');
			return;
		}

		createError.classList.remove('visible');

		// Build a chat message from the form fields
		const toolLabel = toolName === 'create_document' ? 'document' : toolName === 'create_spreadsheet' ? 'spreadsheet' : 'image';
		let chatMessage = 'Create a ' + format.toUpperCase() + ' ' + toolLabel;
		if (title) {
			chatMessage += ' titled "' + title + '"';
		}
		chatMessage += ' with these instructions:\n\n' + prompt;

		// Close modal and send through normal chat flow
		createModal.classList.remove('visible');
		createToolType.value = 'create_document';
		createFormat.value = 'pdf';
		createTitle.value = '';
		createPrompt.value = '';
		createError.classList.remove('visible');
		createFormatGroup.style.display = '';

		// Inject into composer and send
		composerInput.value = chatMessage;
		autoResize();
		sendMessage();
	});

	// Focus input on load
	composerInput.focus();
})();
