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

	// SSE streaming config — BFF proxy (WordPress handles auth)
	const chatStreamUrl = StaffAIConfig.chatStreamUrl || '';

	// SSE buffer parser — handles comments, multi-line data:, partial chunks
	function parseSSEBuffer(buffer) {
		var events = [];
		var parts = buffer.split('\n\n');
		var remaining = parts.pop();
		for (var i = 0; i < parts.length; i++) {
			var block = parts[i];
			var lines = block.split('\n');
			var dataLines = [];
			for (var j = 0; j < lines.length; j++) {
				var line = lines[j];
				if (line.charAt(0) === ':') continue;
				if (line.indexOf('data: ') === 0) {
					dataLines.push(line.substring(6));
				} else if (line.indexOf('data:') === 0) {
					dataLines.push(line.substring(5));
				}
			}
			if (dataLines.length > 0) {
				var payload = dataLines.join('\n');
				try { events.push(JSON.parse(payload)); } catch (e) { /* skip malformed */ }
			}
		}
		return { parsed: events, remaining: remaining };
	}

	// Tool status labels for SSE streaming
	const TOOL_STATUS_LABELS = {
		'retrieve_company_knowledge': 'Searching knowledge base...',
		'create_document': 'Creating document...',
		'create_spreadsheet': 'Creating spreadsheet...',
		'generate_image': 'Generating image...',
		'ingest_document': 'Ingesting document...',
		'spawn_sub_agent': 'Running sub-agent...',
		'escalate_to_human': 'Preparing handoff...',
		'extract_upload_content': 'Analyzing file...',
	};
	const TOOL_DONE_LABELS = {
		'retrieve_company_knowledge': 'Knowledge retrieved',
		'create_document': 'Document created',
		'create_spreadsheet': 'Spreadsheet created',
		'generate_image': 'Image generated',
		'ingest_document': 'Document ingested',
		'spawn_sub_agent': 'Sub-agent complete',
		'escalate_to_human': 'Handoff prepared',
		'extract_upload_content': 'File analyzed',
	};
	const SSE_TOOL_PACING_MS = 400;

	// HTML escape helper
	function escapeHtml(str) {
		const div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	// Markdown rendering — converts assistant markdown to sanitized HTML.
	// Uses marked.js + DOMPurify (loaded in staff-ai-shell.php).
	// SECURITY: Fails closed — if either library is missing, returns escaped text.
	function renderMarkdown(text) {
		if (!text) return '';

		// Normalize CRLF only — do not mutate content (code, JSON, regex).
		text = text.replace(/\r\n/g, '\n');

		// Fail closed: BOTH parser and sanitizer must be present.
		// If either is missing/broken, return safe escaped text.
		if (typeof marked === 'undefined' || !marked.parse ||
			typeof DOMPurify === 'undefined' || !DOMPurify.sanitize) {
			return escapeHtml(text).replace(/\n/g, '<br>');
		}

		var html = marked.parse(text, { gfm: true, breaks: true });
		html = DOMPurify.sanitize(html);

		// Add target="_blank" to links via DOM manipulation (safer than regex).
		var temp = document.createElement('div');
		temp.innerHTML = html;
		temp.querySelectorAll('a').forEach(function(a) {
			a.setAttribute('target', '_blank');
			a.setAttribute('rel', 'noopener noreferrer');
		});

		return temp.innerHTML;
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

	// Upload DOM references
	const uploadBtn     = document.getElementById('uploadBtn');
	const fileInput     = document.getElementById('uploadFileInput');
	const stagedArea    = document.getElementById('uploadStagedArea');
	const dropOverlay   = document.getElementById('uploadDropOverlay');

	// State
	let conversations = [];
	let currentConversationId = null;
	let messages = [];
	let isLoading = false;
	let isReadOnly = false;
	let dirtyInput = false;
	let lastSuggestion = null;       // Phase 10.1: last suggestion shown

	// Upload state
	const UPLOAD_MAX_FILES = StaffAIConfig.upload.maxFiles;
	const UPLOAD_MAX_SIZE = StaffAIConfig.upload.maxSizeBytes;
	const UPLOAD_ALLOWED_EXT = StaffAIConfig.upload.allowedExtensions;

	const UPLOAD_EXT_TO_MIME = {
		'.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
		'.gif': 'image/gif', '.webp': 'image/webp',
		'.pdf': 'application/pdf', '.txt': 'text/plain',
		'.md': 'text/markdown', '.csv': 'text/csv',
		'.docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'.xlsx': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	};

	// Retry config for blob uploads (V1.1: G2)
	var UPLOAD_BLOB_MAX_RETRIES = 2;
	var UPLOAD_BLOB_BACKOFF_MS = [1000, 2000];

	// stagedFiles: [{localId, file, status, fileId, error, thumbnailUrl}]
	// status: 'staged' | 'uploading' | 'uploaded' | 'failed'
	var stagedFiles = [];
	var uploadIdCounter = 0;

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
	function toggleTheme() {
		const isDark = document.documentElement.classList.toggle('dark-theme');
		localStorage.setItem('staff-ai-theme', isDark ? 'dark' : 'light');
	}
	themeToggle.addEventListener('click', toggleTheme);

	// Header overflow menu (mobile)
	const overflowToggle = document.getElementById('headerOverflowToggle');
	const overflowMenu = document.getElementById('headerOverflowMenu');
	if (overflowToggle && overflowMenu) {
		overflowToggle.addEventListener('click', function(e) {
			e.stopPropagation();
			overflowMenu.classList.toggle('open');
		});
		document.addEventListener('click', function() {
			overflowMenu.classList.remove('open');
		});
		overflowMenu.addEventListener('click', function(e) {
			e.stopPropagation();
		});
		var overflowExport = document.getElementById('overflowExport');
		if (overflowExport) {
			overflowExport.addEventListener('click', function() {
				overflowMenu.classList.remove('open');
				document.getElementById('exportBtn').click();
			});
		}
		var overflowShare = document.getElementById('overflowShare');
		if (overflowShare) {
			overflowShare.addEventListener('click', function() {
				overflowMenu.classList.remove('open');
				shareBtn.click();
			});
		}
		var overflowCreate = document.getElementById('overflowCreate');
		if (overflowCreate) {
			overflowCreate.addEventListener('click', function() {
				overflowMenu.classList.remove('open');
				document.getElementById('createBtn').click();
			});
		}
		var overflowTheme = document.getElementById('overflowThemeToggle');
		if (overflowTheme) {
			overflowTheme.addEventListener('click', function() {
				overflowMenu.classList.remove('open');
				toggleTheme();
			});
		}
		var overflowInstallBtn = document.getElementById('overflowInstall');
		if (overflowInstallBtn) {
			overflowInstallBtn.addEventListener('click', function() {
				overflowMenu.classList.remove('open');
				triggerPwaInstall();
			});
		}
	}

	// ─── PWA Install ────────────────────────────────────────────
	var deferredInstallPrompt = null;
	var installBtn = document.getElementById('installBtn');
	var overflowInstall = document.getElementById('overflowInstall');

	function showInstallButtons() {
		if (installBtn) installBtn.classList.add('available');
		if (overflowInstall) overflowInstall.style.display = '';
	}

	function hideInstallButtons() {
		if (installBtn) installBtn.classList.remove('available');
		if (overflowInstall) overflowInstall.style.display = 'none';
		deferredInstallPrompt = null;
	}

	function triggerPwaInstall() {
		if (!deferredInstallPrompt) return;
		deferredInstallPrompt.prompt();
		deferredInstallPrompt.userChoice.then(function(result) {
			if (result.outcome === 'accepted') {
				hideInstallButtons();
			}
			deferredInstallPrompt = null;
		});
	}

	window.addEventListener('beforeinstallprompt', function(e) {
		e.preventDefault();
		deferredInstallPrompt = e;
		showInstallButtons();
	});

	window.addEventListener('appinstalled', function() {
		hideInstallButtons();
	});

	if (installBtn) {
		installBtn.addEventListener('click', triggerPwaInstall);
	}

	// Register service worker.
	if ('serviceWorker' in navigator) {
		navigator.serviceWorker.register(
			StaffAIConfig.homeUrl + 'staff-ai/sw.js',
			{ scope: StaffAIConfig.homeUrl + 'staff-ai/' }
		).catch(function(err) {
			console.warn('SW registration failed:', err);
		});
	}

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

	// Auto-resize textarea — grows uncapped, .composer-scroll wrapper scrolls.
	var composerScroll = document.getElementById('composerScroll');
	function autoResize() {
		composerInput.style.height = 'auto';
		composerInput.style.height = composerInput.scrollHeight + 'px';
		if (composerScroll) composerScroll.scrollTop = composerScroll.scrollHeight;
	}

	composerInput.addEventListener('input', function() {
		dirtyInput = true;
		if (composerInput.classList.contains('staff-ai-suggestion-text')) {
			composerInput.classList.remove('staff-ai-suggestion-text');
		}
		autoResize();
		updateSendButton();
	});

	// Update send button state
	function updateSendButton() {
		sendBtn.disabled = (!composerInput.value.trim() && !hasActiveFiles()) || isLoading || isReadOnly;
	}

	// Keyboard handler: Enter to send, Shift+Enter for newline
	composerInput.addEventListener('keydown', function(e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			if (!sendBtn.disabled) {
				sendMessage();
			}
		}
		// Auto-clear suggestion ghost text on any printable keystroke
		if (composerInput.classList.contains('staff-ai-suggestion-text') &&
			e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
			composerInput.value = '';
			composerInput.classList.remove('staff-ai-suggestion-text');
			autoResize();
			updateSendButton();
		}
	});
	// Click in input clears suggestion ghost text
	composerInput.addEventListener('click', function() {
		if (composerInput.classList.contains('staff-ai-suggestion-text')) {
			composerInput.value = '';
			composerInput.classList.remove('staff-ai-suggestion-text');
			autoResize();
			updateSendButton();
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

	// Smart scroll — only auto-scroll during streaming if user hasn't scrolled up
	var _isStreaming = false;
	var _userScrolledUp = false;

	function isNearBottom(threshold) {
		threshold = threshold || 80;
		return messagesContainer.scrollHeight - messagesContainer.scrollTop
			- messagesContainer.clientHeight < threshold;
	}

	// Single persistent scroll listener — avoids per-message listener accumulation
	messagesContainer.addEventListener('scroll', function() {
		if (_isStreaming) {
			_userScrolledUp = !isNearBottom(80);
		}
	});

	// Show full welcome on first visit, random tip on subsequent new chats
	function showWelcomeOrTip() {
		var welcomed = false;
		try { welcomed = localStorage.getItem('staff-ai-welcome-v1'); } catch (e) { /* blocked */ }

		var tips = StaffAIConfig.tips || [];
		var fullEl = document.getElementById('welcomeFull');
		var tipEl = document.getElementById('welcomeTip');

		if (!welcomed || tips.length === 0) {
			// First visit or no tips available: show full welcome
			try { localStorage.setItem('staff-ai-welcome-v1', '1'); } catch (e) { /* blocked */ }
			welcomeMessage.style.display = 'block';
			if (fullEl) fullEl.style.display = '';
			if (tipEl) tipEl.style.display = 'none';
		} else {
			// Returning user: show random tip
			welcomeMessage.style.display = 'block';
			if (fullEl) fullEl.style.display = 'none';
			if (tipEl) {
				tipEl.textContent = tips[Math.floor(Math.random() * tips.length)];
				tipEl.style.display = '';
			}
		}
	}

	// Remove typing placeholder from messages array (targeted, not blind pop)
	function removeTypingMessage() {
		for (var i = messages.length - 1; i >= 0; i--) {
			if (messages[i].isTyping) {
				messages.splice(i, 1);
				return;
			}
		}
	}

	// Render messages
	function renderMessages() {
		if (messages.length === 0) {
			showWelcomeOrTip();
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
				const span = document.createElement('span');
				span.className = 'message-share-event-content';
				span.textContent = t('sharedWith', 'Shared with') + ' ' +
					msg.content + ' \u00b7 ' + dateStr + ' ' + timeStr;
				el.appendChild(span);
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
				// Render markdown for assistant messages, plain text for user messages.
				if (msg.role === 'assistant') {
					content.innerHTML = renderMarkdown(msg.content);
				} else {
					content.textContent = msg.content;
				}

				// Render tool outputs if present
				if (msg.tool_outputs && msg.tool_outputs.length > 0) {
					msg.tool_outputs.forEach(function(tool) {
						const card = createToolOutputCard(tool);
						content.appendChild(card);
					});
				}

				// File indicators for uploaded files.
				if (msg.fileAttachments && msg.fileAttachments.length > 0) {
					appendFileAttachments(content, msg.fileAttachments);
				} else if (msg.fileNames && msg.fileNames.length > 0) {
					appendFileIndicators(content, msg.fileNames);
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

	// =============================================
	// UPLOAD MODULE — file staging, validation, upload
	// =============================================

	function getFileExtension(filename) {
		var dot = filename.lastIndexOf('.');
		return dot >= 0 ? filename.substring(dot).toLowerCase() : '';
	}

	function getMimeFromExtension(filename) {
		return UPLOAD_EXT_TO_MIME[getFileExtension(filename)] || '';
	}

	function validateFile(file) {
		var ext = getFileExtension(file.name);
		if (UPLOAD_ALLOWED_EXT.indexOf(ext) === -1) {
			return t('unsupportedType', 'Unsupported file type: ' + ext);
		}
		if (file.size > UPLOAD_MAX_SIZE) {
			return t('fileTooLarge', 'File exceeds 10MB limit');
		}
		if (stagedFiles.filter(function(f) { return f.status !== 'failed'; }).length >= UPLOAD_MAX_FILES) {
			return t('tooManyFiles', 'Maximum ' + UPLOAD_MAX_FILES + ' files per message');
		}
		return null;
	}

	function stageFile(file) {
		var error = validateFile(file);
		var localId = ++uploadIdCounter;
		var entry = {
			localId: localId,
			file: file,
			status: error ? 'failed' : 'staged',
			fileId: null,
			error: error,
			thumbnailUrl: null,
		};

		// Show validation error as banner immediately.
		if (error) {
			showError(error);
		}

		// Generate thumbnail for images.
		if (!error && file.type && file.type.startsWith('image/') && file.size < 20 * 1024 * 1024) {
			var reader = new FileReader();
			reader.onload = function(e) {
				entry.thumbnailUrl = e.target.result;
				renderStagedFiles();
			};
			reader.readAsDataURL(file);
		}

		stagedFiles.push(entry);
		renderStagedFiles();
		updateSendButton();

		// Start upload immediately in background (not on send).
		// By the time user hits Send, file_id is already ready.
		if (!error) {
			uploadSingleFile(entry);
		}
	}

	function removeStagedFile(localId) {
		stagedFiles = stagedFiles.filter(function(f) { return f.localId !== localId; });
		hideError();

		// Re-validate any failed files — they may now pass (e.g. count dropped below max).
		stagedFiles.forEach(function(f) {
			if (f.status === 'failed') {
				var err = validateFile(f.file);
				if (!err) {
					f.status = 'staged';
					f.error = null;
				}
			}
		});

		renderStagedFiles();
		updateSendButton();
	}

	function clearStagedFiles() {
		stagedFiles = [];
		renderStagedFiles();
		updateSendButton();
	}

	function hasActiveFiles() {
		return stagedFiles.some(function(f) {
			return f.status === 'staged' || f.status === 'uploaded';
		});
	}

	function renderStagedFiles() {
		if (!stagedArea) return;
		if (stagedFiles.length === 0) {
			stagedArea.style.display = 'none';
			stagedArea.innerHTML = '';
			return;
		}

		// Layout rule: single image → large tile, multiple attachments → compact row
		var activeFiles = stagedFiles.filter(function(f) { return f.status !== 'removed'; });
		var isSingleImage = activeFiles.length === 1
			&& activeFiles[0].thumbnailUrl
			&& activeFiles[0].file.type && activeFiles[0].file.type.startsWith('image/');
		var layoutClass = isSingleImage ? 'staged-layout-single' : 'staged-layout-compact';

		stagedArea.style.display = 'flex';
		stagedArea.className = 'staged-files ' + layoutClass;
		stagedArea.innerHTML = activeFiles.map(function(f) {
			var isImage = f.thumbnailUrl && f.file.type && f.file.type.startsWith('image/');
			var spinnerHtml = '<div class="upload-spinner"></div>';

			if (isImage) {
				// Image tile: thumbnail preview with remove button overlay
				var tileStatus = 'upload-tile--' + f.status;
				var tileClass = isSingleImage ? 'upload-tile upload-tile--large' : 'upload-tile upload-tile--compact';
				return '<div class="' + tileClass + ' ' + tileStatus + '" data-id="' + f.localId + '">'
					+ '<img class="upload-tile-img" src="' + f.thumbnailUrl + '" alt="" />'
					+ spinnerHtml
					+ '<button type="button" class="upload-tile-remove" aria-label="Remove">&times;</button>'
					+ '</div>';
			} else {
				// File chip: icon + name + size
				var chipStatus = 'upload-chip--' + f.status;
				var errorTip = f.error
					? '<span class="upload-chip-error">' + escapeHtml(f.error) + '</span>'
					: '';
				var size = (f.file.size / 1024).toFixed(0) + 'KB';
				return '<div class="upload-chip ' + chipStatus + '" data-id="' + f.localId + '">'
					+ '<div class="upload-chip-icon-wrap">'
					+ '<svg class="upload-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
					+ '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
					+ '<polyline points="14 2 14 8 20 8"/></svg>'
					+ spinnerHtml
					+ '</div>'
					+ '<div class="upload-chip-text">'
					+ '<span class="upload-chip-name" title="' + escapeHtml(f.file.name) + '">' + escapeHtml(f.file.name) + '</span>'
					+ '<span class="upload-chip-size">' + size + '</span>'
					+ '</div>'
					+ '<button type="button" class="upload-chip-remove" aria-label="Remove">&times;</button>'
					+ errorTip
					+ '</div>';
			}
		}).join('');

		// Attach remove handlers (tiles and chips).
		stagedArea.querySelectorAll('.upload-chip-remove, .upload-tile-remove').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var el = btn.closest('.upload-chip') || btn.closest('.upload-tile');
				var id = parseInt(el.dataset.id, 10);
				removeStagedFile(id);
			});
		});
	}

	async function uploadSingleFile(entry) {
		entry.status = 'uploading';
		renderStagedFiles();

		try {
			// Step 1: Init — get presigned URL via WordPress proxy.
			var initResult = await apiRequest('/uploads/init', {
				method: 'POST',
				body: JSON.stringify({
					filename: entry.file.name,
					mime_type: getMimeFromExtension(entry.file.name),
					size_bytes: entry.file.size,
					conversation_id: currentConversationId || '_pending',
				}),
			});

			if (!initResult || !initResult.upload_url) {
				throw new Error('Failed to initialize upload');
			}

			entry.fileId = initResult.file_id;

			// Step 2: Upload binary directly to Azure presigned URL (with retry — V1.1: G2).
			var blobResponse = null;
			var lastError = null;

			for (var attempt = 0; attempt <= UPLOAD_BLOB_MAX_RETRIES; attempt++) {
				try {
					blobResponse = await fetch(initResult.upload_url, {
						method: 'PUT',
						headers: {
							'Content-Type': getMimeFromExtension(entry.file.name),
							'x-ms-blob-type': 'BlockBlob',
						},
						body: entry.file,
					});

					if (blobResponse.ok) break;
					lastError = new Error('Blob upload failed: ' + blobResponse.status);
				} catch (err) {
					lastError = err;
				}

				// Retry with backoff if not the last attempt.
				if (attempt < UPLOAD_BLOB_MAX_RETRIES) {
					await new Promise(function(resolve) { setTimeout(resolve, UPLOAD_BLOB_BACKOFF_MS[attempt]); });
				}
			}

			if (!blobResponse || !blobResponse.ok) {
				throw lastError || new Error('Blob upload failed after retries');
			}

			// Step 3: Commit via WordPress proxy.
			var commitResult = await apiRequest('/uploads/commit', {
				method: 'POST',
				body: JSON.stringify({ file_id: entry.fileId }),
			});

			if (!commitResult || commitResult.status === 'error') {
				throw new Error('Commit failed');
			}

			entry.status = 'uploaded';
		} catch (err) {
			entry.status = 'failed';
			entry.error = err.message || t('uploadFailed', 'Upload failed');
		}

		renderStagedFiles();
	}

	async function uploadAllStagedFiles() {
		// Upload any files still in 'staged' state (e.g., if stageFile upload hasn't started yet).
		var toUpload = stagedFiles.filter(function(f) { return f.status === 'staged'; });
		if (toUpload.length > 0) {
			await Promise.all(toUpload.map(function(f) { return uploadSingleFile(f); }));
		}

		// Wait for any files still uploading (started by stageFile in background).
		var maxWaitMs = 30000;
		var pollIntervalMs = 200;
		var waited = 0;
		while (waited < maxWaitMs) {
			var stillUploading = stagedFiles.filter(function(f) { return f.status === 'uploading'; });
			if (stillUploading.length === 0) break;
			await new Promise(function(resolve) { setTimeout(resolve, pollIntervalMs); });
			waited += pollIntervalMs;
		}

		// Treat any files still stuck in 'uploading' after timeout as failed
		stagedFiles.forEach(function(f) {
			if (f.status === 'uploading') {
				f.status = 'failed';
				f.error = t('uploadTimeout', 'Upload timed out. Please try again.');
			}
		});

		var uploaded = stagedFiles.filter(function(f) { return f.status === 'uploaded'; });
		var failed   = stagedFiles.filter(function(f) { return f.status === 'failed'; });

		return {
			success: failed.length === 0,
			fileIds: uploaded.map(function(f) { return f.fileId; }),
		};
	}

	function appendFileIndicators(container, fileNames) {
		fileNames.forEach(function(name) {
			var indicator = document.createElement('div');
			indicator.className = 'message-file-indicator';
			indicator.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
				+ '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
				+ '<polyline points="14 2 14 8 20 8"/></svg> '
				+ escapeHtml(name);
			container.appendChild(indicator);
		});
	}

	function dataUrlToBlob(dataUrl) {
		var parts = dataUrl.split(',');
		var mime = parts[0].match(/:(.*?);/)[1];
		var raw = atob(parts[1]);
		var arr = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
		return new Blob([arr], { type: mime });
	}

	// Safe raster types for click-to-open (no SVG — different security profile)
	var SAFE_OPEN_TYPES = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/bmp'];

	function appendFileAttachments(container, attachments) {
		attachments.forEach(function(att) {
			var isImage = att.type && att.type.startsWith('image/')
				&& att.thumbnailUrl && att.thumbnailUrl.startsWith('data:image/');

			if (isImage) {
				// Image preview: 384px max-width, click to open full size in new tab
				var wrapper = document.createElement('div');
				wrapper.className = 'message-image-preview';
				var img = document.createElement('img');
				img.src = att.thumbnailUrl;
				img.alt = att.name;
				img.className = 'message-image-thumb';
				// Only allow click-to-open for safe raster types (not SVG)
				var canOpen = SAFE_OPEN_TYPES.indexOf(att.type) !== -1;
				if (canOpen) {
					img.style.cursor = 'pointer';
					img.addEventListener('click', function() {
						var blob = dataUrlToBlob(att.thumbnailUrl);
						var blobUrl = URL.createObjectURL(blob);
						window.open(blobUrl, '_blank');
						// Revoke after new tab has loaded to prevent memory leak
						setTimeout(function() { URL.revokeObjectURL(blobUrl); }, 1000);
					});
				}
				wrapper.appendChild(img);
				container.appendChild(wrapper);
			} else {
				// Non-image file: show file indicator chip
				var indicator = document.createElement('div');
				indicator.className = 'message-file-indicator';
				indicator.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
					+ '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
					+ '<polyline points="14 2 14 8 20 8"/></svg> '
					+ escapeHtml(att.name);
				container.appendChild(indicator);
			}
		});
	}

	// =============================================
	// SSE STREAMING UI HELPERS (Phase 9 PR 3)
	// =============================================

	function renderToolStatus(toolName) {
		var label = TOOL_STATUS_LABELS[toolName] || (toolName + '...');
		var el = document.createElement('div');
		el.className = 'tool-status';
		el.setAttribute('data-tool', toolName);
		el.innerHTML = '<span class="tool-spinner"></span><span class="tool-label">' + escapeHtml(label) + '</span>';
		// Insert before the typing indicator message
		var typingMsg = messagesList.querySelector('.message:last-child .typing-indicator');
		if (typingMsg) {
			typingMsg.parentNode.insertBefore(el, typingMsg);
		}
		return el;
	}

	function completeToolStatus(el, toolName) {
		if (!el) return;
		var doneLabel = TOOL_DONE_LABELS[toolName] || (toolName + ' done');
		el.classList.add('done');
		el.innerHTML = '<span class="tool-checkmark">&#10003;</span><span class="tool-label">' + escapeHtml(doneLabel) + '</span>';
	}

	function updateTypingLabel(text) {
		var typingMsg = messagesList.querySelector('.message:last-child .typing-indicator');
		if (!typingMsg) return;
		var labelEl = typingMsg.parentNode.querySelector('.typing-label');
		if (!labelEl) {
			labelEl = document.createElement('span');
			labelEl.className = 'typing-label';
			typingMsg.parentNode.insertBefore(labelEl, typingMsg.nextSibling);
		}
		labelEl.textContent = text;
	}

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

	// =============================================
	// SEND MESSAGE — with streaming/sync split
	// =============================================

	async function sendMessage() {
		const text = composerInput.value.trim();
		var hasFiles = hasActiveFiles();

		if (!text && !hasFiles) return;
		if (isLoading || isReadOnly) return;

		// Phase 10.1: Classify suggestion outcome before clearing input
		var suggResult = classifySuggestionOutcome(text, lastSuggestion);
		var pendingOutcome = suggResult.outcome;
		var pendingScore = suggResult.score;
		lastSuggestion = null;

		hideError();
		hideInfo();

		// Upload staged files first.
		var fileIds = [];
		if (hasFiles) {
			var uploadResult = await uploadAllStagedFiles();
			if (!uploadResult.success) {
				var failedFile = stagedFiles.filter(function(f) { return f.status === 'failed'; })[0];
				var failMsg = (failedFile && failedFile.error) || t('removeFailedFiles', 'Some files failed to upload. Remove failed files and try again.');
				showError(failMsg);
				return;
			}
			fileIds = uploadResult.fileIds;
		}

		// Build user message display content.
		var displayText = text || (fileIds.length > 0 ? t('analyzeFiles', 'Please analyze the attached file(s).') : '');
		var fileAttachments = null;
		if (fileIds.length > 0) {
			fileAttachments = stagedFiles
				.filter(function(f) { return f.status === 'uploaded'; })
				.map(function(f) {
					return {
						name: f.file.name,
						type: f.file.type || '',
						thumbnailUrl: f.thumbnailUrl || null,
					};
				});
		}
		messages.push({
			role: 'user',
			content: displayText,
			fileNames: fileAttachments ? fileAttachments.map(function(f) { return f.name; }) : null,
			fileAttachments: fileAttachments,
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

		// Feature detection: streaming vs sync fallback (V1.1)
		if (chatStreamUrl && typeof ReadableStream !== 'undefined') {
			console.info('[Staff AI] Using streaming path');
			await sendMessageStreaming(text, fileIds, pendingOutcome, pendingScore);
		} else {
			console.info('[Staff AI] Using sync fallback' +
				(!chatStreamUrl ? ' (no chatStreamUrl)' : ' (no ReadableStream)'));
			await sendMessageSync(text, fileIds);
		}
	}

	// Sync fallback — existing PHP proxy behavior (zero change)
	async function sendMessageSync(text, fileIds) {
		try {
			var requestBody = { message: text };
			if (currentConversationId) {
				requestBody.thread_id = currentConversationId;
			}
			if (fileIds.length > 0) {
				requestBody.file_ids = fileIds;
			}

			const result = await apiRequest('/chat', {
				method: 'POST',
				body: JSON.stringify(requestBody)
			});

			clearStagedFiles();

			removeTypingMessage();
			messages.push({
				role: 'assistant',
				content: result.message?.content || '',
				tool_outputs: result.message?.tool_outputs || []
			});
			renderMessages();

			if (result.thread_id) {
				currentConversationId = result.thread_id;
			}

			loadConversations();
			updateReadOnlyState();
		} catch (err) {
			removeTypingMessage();
			renderMessages();
			console.error('Failed to send message:', err);
			showError(err.message || t('failedToSend', 'Failed to send message. Please try again.'));
		} finally {
			isLoading = false;
			updateSendButton();
		}
	}

	// Streaming — direct-to-DEF with JWT + SSE
	async function sendMessageStreaming(text, fileIds, pendingOutcome, pendingScore) {
		var retried = false;

		async function attemptStream() {
			// Build messages array for DEF (full conversation history)
			var reqMessages = [];
			for (var i = 0; i < messages.length; i++) {
				var m = messages[i];
				if (m.isTyping) continue;
				if (m.role !== 'user' && m.role !== 'assistant') continue;
				var msgObj = { role: m.role, content: m.content };
				// Attach file references on the latest user message
				if (m.role === 'user' && i === messages.length - 2 && fileIds.length > 0) {
					msgObj.attachments = fileIds.map(function(fid) { return { file_id: fid }; });
				}
				reqMessages.push(msgObj);
			}

			var requestBody = { messages: reqMessages };
			if (currentConversationId) {
				requestBody.thread_id = currentConversationId;
				requestBody.continue_thread = true;
			}
			// Phase 10.1: Add suggestion feedback signal
			if (pendingOutcome) {
				requestBody.suggestion_outcome = pendingOutcome;
				requestBody.similarity_score = pendingScore;
			}

			var response = await fetch(chatStreamUrl, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': nonce,
					'Content-Type': 'application/json',
				},
				body: JSON.stringify(requestBody),
				credentials: 'same-origin',
			});

			if (!response.ok) {
				var errText = '';
				try { errText = (await response.json()).detail || ''; } catch (e) { /* ignore */ }
				throw new Error(errText || 'Stream request failed (' + response.status + ')');
			}

			// Check if response is JSON (extraction early-exit) vs SSE
			var ct = response.headers.get('content-type') || '';
			if (ct.indexOf('application/json') !== -1) {
				var jsonResult = await response.json();
				clearStagedFiles();
				removeTypingMessage();
				messages.push({
					role: 'assistant',
					content: jsonResult.choices?.[0]?.message?.content || '',
					tool_outputs: jsonResult.choices?.[0]?.message?.tool_outputs || []
				});
				renderMessages();
				if (jsonResult.thread_id) {
					currentConversationId = jsonResult.thread_id;
				}
				loadConversations();
				updateReadOnlyState();
				return;
			}

			// SSE stream processing
			var reader = response.body.getReader();
			var decoder = new TextDecoder();
			var buffer = '';
			var toolStatusElements = {};
			var eventQueue = [];
			var processing = false;

			// Progressive markdown rendering state.
			var streamBuffer = '';
			var streamEl = null;
			var wordDrainTimer = null;
			var lastRenderedLen = 0;
			var thinkingStatusEl = null;
			_isStreaming = true;
			_userScrolledUp = false;

			// Streaming render constants.
			var STREAM_RENDER_INTERVAL = 120;
			var STREAM_RENDER_INTERVAL_LARGE = 200;
			var STREAM_LARGE_THRESHOLD = 8000;
			var STREAM_MIN_DELTA = 30;

			function renderStreamChunk() {
				// Early return if nothing new
				if (streamBuffer.length === lastRenderedLen) {
					wordDrainTimer = null;
					return;
				}

				var delta = streamBuffer.length - lastRenderedLen;
				var hasNewline = streamBuffer.indexOf('\n', lastRenderedLen) !== -1;

				// Only re-render if enough new content or newline received
				if (delta < STREAM_MIN_DELTA && !hasNewline) {
					var interval = streamBuffer.length > STREAM_LARGE_THRESHOLD
						? STREAM_RENDER_INTERVAL_LARGE : STREAM_RENDER_INTERVAL;
					wordDrainTimer = setTimeout(renderStreamChunk, interval);
					return;
				}

				lastRenderedLen = streamBuffer.length;
				streamEl.innerHTML = renderMarkdown(streamBuffer);

				if (!_userScrolledUp) {
					messagesContainer.scrollTop = messagesContainer.scrollHeight;
				}

				var interval = streamBuffer.length > STREAM_LARGE_THRESHOLD
					? STREAM_RENDER_INTERVAL_LARGE : STREAM_RENDER_INTERVAL;
				wordDrainTimer = setTimeout(renderStreamChunk, interval);
			}

			async function processEventQueue() {
				if (processing) return;
				processing = true;
				while (eventQueue.length > 0) {
					var evt = eventQueue.shift();
					if (evt.type === 'thinking') {
						var thinkMsg = (evt.message || '').toString().trim();
						if (!thinkMsg) {
							// No LLM-authored message — suppress (V1.2)
						} else if (!thinkingStatusEl) {
							var div = document.createElement('div');
							div.className = 'tool-status';
							div.innerHTML = '<span class="tool-spinner"></span><span class="tool-label"></span>';
							div.querySelector('.tool-label').textContent = thinkMsg;
							var target = messagesList.querySelector('.message:last-child .message-content');
							var typing = target ? target.querySelector('.typing-indicator') : null;
							if (typing) {
								typing.style.display = 'none';
								target.insertBefore(div, typing);
							}
							var oldLbl = messagesList.querySelector('.message:last-child .typing-label');
							if (oldLbl) oldLbl.remove();
							thinkingStatusEl = div;
						} else {
							var saLabel = thinkingStatusEl.querySelector('.tool-label');
							if (saLabel) saLabel.textContent = thinkMsg;
						}
					} else if (evt.type === 'tool_start') {
						if (thinkingStatusEl) { thinkingStatusEl.remove(); thinkingStatusEl = null; }
						var el = renderToolStatus(evt.tool);
						toolStatusElements[evt.tool] = el;
						await new Promise(function(r) { setTimeout(r, SSE_TOOL_PACING_MS); });
					} else if (evt.type === 'tool_done') {
						completeToolStatus(toolStatusElements[evt.tool], evt.tool);
						await new Promise(function(r) { setTimeout(r, SSE_TOOL_PACING_MS); });
					} else if (evt.type === 'text_delta') {
						if (thinkingStatusEl) { thinkingStatusEl.remove(); thinkingStatusEl = null; }
						if (!streamEl) {
							// Take over the typing indicator message.
							var lastMsg = messagesList.querySelector('.message:last-child');
							if (lastMsg) {
								lastMsg.classList.add('message-streaming');
								var contentDiv = lastMsg.querySelector('.message-content');
								if (contentDiv) {
									var typingInd = contentDiv.querySelector('.typing-indicator');
									if (typingInd) typingInd.remove();
									var labelEl = contentDiv.querySelector('.typing-label');
									if (labelEl) labelEl.remove();
									streamEl = contentDiv;
								}
							}
						}
						if (streamEl) {
							streamBuffer += evt.text;
							if (!wordDrainTimer) {
								renderStreamChunk();
							}
						}
					} else if (evt.type === 'done') {
						if (thinkingStatusEl) { thinkingStatusEl.remove(); thinkingStatusEl = null; }
						if (wordDrainTimer) clearTimeout(wordDrainTimer);
						clearStagedFiles();
						removeTypingMessage();

						// Use done payload content, fall back to streamed buffer
						var finalMessage = evt?.choices?.[0]?.message || {};
						var finalContent = (finalMessage.content || streamBuffer || '').trim();
						if (!finalContent) {
							finalContent = t('failedToSend', 'Sorry, something went wrong. Please try again.');
						}

						messages.push({
							role: 'assistant',
							content: finalContent,
							tool_outputs: finalMessage.tool_outputs || []
						});

						// Always re-render from messages array for DOM/array consistency
						renderMessages();

						streamBuffer = '';
						streamEl = null;
						wordDrainTimer = null;
						lastRenderedLen = 0;
						_userScrolledUp = false;
						thinkingStatusEl = null;
						_isStreaming = false;

						if (evt.thread_id) {
							currentConversationId = evt.thread_id;
						}
						dirtyInput = false;
						loadConversations();
						updateReadOnlyState();
					} else if (evt.type === 'suggestions') {
						lastSuggestion = evt.suggestion || null;
						if (!dirtyInput && composerInput && evt.suggestion) {
							composerInput.value = evt.suggestion;
							composerInput.classList.add('staff-ai-suggestion-text');
							autoResize();
							updateSendButton();
						}
					} else if (evt.type === 'error') {
						if (thinkingStatusEl) { thinkingStatusEl.remove(); thinkingStatusEl = null; }
						if (wordDrainTimer) clearTimeout(wordDrainTimer);
						streamBuffer = '';
						streamEl = null;
						wordDrainTimer = null;
						lastRenderedLen = 0;
						_userScrolledUp = false;
						_isStreaming = false;
						removeTypingMessage();
						renderMessages();
						showError(evt.message || 'An error occurred.');
					}
				}
				processing = false;
			}

			// Read SSE chunks
			while (true) {
				var result = await reader.read();
				if (result.done) break;
				buffer += decoder.decode(result.value, { stream: true });
				var parsed = parseSSEBuffer(buffer);
				buffer = parsed.remaining;
				for (var k = 0; k < parsed.parsed.length; k++) {
					eventQueue.push(parsed.parsed[k]);
				}
				await processEventQueue();
			}
			// Process any remaining events
			if (buffer.trim()) {
				var finalParsed = parseSSEBuffer(buffer + '\n\n');
				for (var k = 0; k < finalParsed.parsed.length; k++) {
					eventQueue.push(finalParsed.parsed[k]);
				}
				await processEventQueue();
			}
		}

		try {
			await attemptStream();
		} catch (err) {
			removeTypingMessage();
			renderMessages();
			console.error('[Staff AI] Streaming error:', err);
			showError(err.message || t('failedToSend', 'Failed to send message. Please try again.'));
		} finally {
			_isStreaming = false;
			_userScrolledUp = false;
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

		// Add chips for selected recipients — {email, name} objects
		shareSelectedRecipients.forEach(function(r) {
			var chip = document.createElement('span');
			chip.className = 'token-select-chip';

			// Label text via textContent (safe — no innerHTML)
			var label = document.createTextNode(
				r.name ? r.name + ' (' + r.email + ')' : r.email
			);
			chip.appendChild(label);

			// Remove button as separate element
			var removeBtn = document.createElement('span');
			removeBtn.className = 'token-select-chip-remove';
			removeBtn.dataset.email = r.email;
			removeBtn.textContent = '\u00d7';
			chip.appendChild(removeBtn);

			shareTokensContainer.insertBefore(chip, shareTokenPlaceholder);
		});

		// Render dropdown options (only unselected)
		shareDropdown.innerHTML = '';
		var selectedEmails = shareSelectedRecipients.map(function(r) { return r.email; });
		var unselected = shareAvailableRecipients.filter(function(r) {
			return selectedEmails.indexOf(r.email) === -1;
		});
		if (unselected.length === 0) {
			var empty = document.createElement('div');
			empty.className = 'token-select-empty';
			empty.textContent = t('allRecipientsSelected', 'All recipients selected');
			shareDropdown.appendChild(empty);
		} else {
			unselected.forEach(function(r) {
				var opt = document.createElement('div');
				opt.className = 'token-select-option';
				opt.textContent = r.name ? r.name + ' (' + r.email + ')' : r.email;
				opt.dataset.email = r.email;
				shareDropdown.appendChild(opt);
			});
		}

		updateShareSendButton();
	}

	// Open/close dropdown
	shareTokensContainer.addEventListener('click', function(e) {
		if (e.target.classList.contains('token-select-chip-remove')) {
			// Remove chip — filter by email property
			var email = e.target.dataset.email;
			shareSelectedRecipients = shareSelectedRecipients.filter(function(r) { return r.email !== email; });
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
		// Find the full recipient object by email
		var recipient = shareAvailableRecipients.find(function(r) { return r.email === email; });
		if (recipient && !shareSelectedRecipients.some(function(r) { return r.email === email; })) {
			shareSelectedRecipients.push(recipient);
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

			// Populate token select with recipients (use recipient_options for display)
			shareAvailableRecipients = (settingsResp && settingsResp.recipient_options) || [];
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

			// Extract email strings for the API call.
			// channel is forced server-side — not sent from client.
			await apiRequest('/share-send', {
				method: 'POST',
				body: JSON.stringify({
					to: selectedRecipients.map(function(r) { return r.email; }),
					subject: shareSubject.value,
					body: bodyText
				})
			});

			shareModal.classList.remove('visible');
			// Pass display-friendly names for the share event banner
			addShareEvent(selectedRecipients.map(function(r) {
				return r.name ? r.name + ' (' + r.email + ')' : r.email;
			}));
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

	// =============================================
	// UPLOAD EVENT HANDLERS
	// =============================================

	// File picker.
	if (uploadBtn && fileInput) {
		uploadBtn.addEventListener('click', function() {
			if (!isLoading && !isReadOnly) fileInput.click();
		});
		fileInput.addEventListener('change', function(e) {
			Array.from(e.target.files).forEach(function(f) { stageFile(f); });
			e.target.value = '';
		});
	}

	// Drag and drop — scoped to composer-container (V1.1: C7).
	var composerDropTarget = document.querySelector('.composer-container');
	if (composerDropTarget && dropOverlay) {
		var dragCounter = 0;

		composerDropTarget.addEventListener('dragenter', function(e) {
			e.preventDefault();
			dragCounter++;
			dropOverlay.classList.add('visible');
		});

		composerDropTarget.addEventListener('dragover', function(e) {
			e.preventDefault();
		});

		composerDropTarget.addEventListener('dragleave', function(e) {
			e.preventDefault();
			dragCounter--;
			if (dragCounter <= 0) {
				dragCounter = 0;
				dropOverlay.classList.remove('visible');
			}
		});

		composerDropTarget.addEventListener('drop', function(e) {
			e.preventDefault();
			dragCounter = 0;
			dropOverlay.classList.remove('visible');
			if (!isLoading && !isReadOnly) {
				Array.from(e.dataTransfer.files).forEach(function(f) { stageFile(f); });
			}
		});
	}

	// Clipboard paste on composer.
	if (composerInput) {
		composerInput.addEventListener('paste', function(e) {
			var items = Array.from((e.clipboardData && e.clipboardData.items) || []);
			var fileItems = items.filter(function(item) { return item.kind === 'file'; });
			if (fileItems.length === 0) return; // Let normal text paste through.
			e.preventDefault();
			fileItems.forEach(function(item) {
				var file = item.getAsFile();
				if (file) stageFile(file);
			});
		});
	}

	// Focus input on load
	composerInput.focus();
})();
