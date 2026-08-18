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
	const statusUrl = StaffAIConfig.statusUrl || '';

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
		'retrieve':                   'Working on it...',
		'render_product_cards':       'Fetching products...',
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

	// HTML escape helper. Escapes QUOTES too (5.8.4, matching the customer
	// widget): the old text-node serialization left " and ' intact, which is
	// safe in text context but an attribute breakout everywhere else — the
	// utility must be safe for every call site, present and future.
	function escapeHtml(str) {
		if (!str) return '';
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
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
			// A generated-file link pasted into chat text carries DEF's
			// canonical /api/files/ path, which 404s on the WordPress origin —
			// route it through the same download endpoint as the button. Also
			// fixes links in replayed history from before this change. Guard:
			// an anchor DOMPurify stripped the href from must STAY dead —
			// writing rewriteDownloadUrl(null) back would re-arm it as "null".
			var href = a.getAttribute('href');
			if (href) {
				a.setAttribute('href', rewriteDownloadUrl(href));
			}
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
			// DEF refusals arrive as {detail:{error, message, retry_after}}
			// (FastAPI); WP-layer errors as {code, message}. Both render
			// their string message; objects never reach a banner (5.8.4).
			var detailMsg = extractServerMessage(data);
			var errorMsg = detailMsg || (typeof data.message === 'string' && data.message) || (typeof data.error === 'string' && data.error) || 'Request failed';
			var detail = data && data.detail;
			if (response.status === 429 && detail && typeof detail.retry_after === 'number') {
				errorMsg += ' ' + t('retrySuffix', '(retry in %ds)').replace('%d', String(detail.retry_after));
			}
			console.error('Staff AI API error:', {
				code: errorCode,
				message: errorMsg,
				data: data
			});
			var err = new Error(detailMsg ? errorMsg : (errorCode ? '[' + errorCode + '] ' + errorMsg : errorMsg));
			err.status = response.status;
			throw err;
		}
		return data;
	}

	// String-safe extraction of a server refusal message (5.8.4): the DEF
	// shape's detail.message, a plain-string detail, or nothing. Never
	// returns an object — "[object Object]" cannot reach a banner.
	function extractServerMessage(data) {
		var detail = data && data.detail;
		if (detail && typeof detail.message === 'string') return detail.message;
		if (typeof detail === 'string') return detail;
		return '';
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
	const shareDocumentsSection = document.getElementById('shareDocumentsSection');
	const shareDocumentsList = document.getElementById('shareDocumentsList');
	const shareDocumentsHint = document.getElementById('shareDocumentsHint');

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

	// Picking a section closes the drawer on mobile, so that dismissing the panel
	// returns you to the chat rather than to a drawer you did not leave open. NOT
	// because the panel would be obscured — `.modal-overlay` is z-index 200 and
	// the mobile drawer 100, so it already renders above it.
	// Delegated and bound ONCE here rather than inside each section's own module:
	// those four are independent and would each need their own copy, and
	// `sidebar` is in scope here and not there.
	const sidebarNav = document.querySelector('.sidebar-nav');
	if (sidebarNav) {
		sidebarNav.addEventListener('click', function (e) {
			if (e.target.closest('.sidebar-nav-item') && sidebar.classList.contains('open')) {
				toggleSidebar();
			}
		});
	}

	// Theme toggle
	const themeToggle = document.getElementById('themeToggle');
	function toggleTheme() {
		const isDark = document.documentElement.classList.toggle('dark-theme');
		localStorage.setItem('staff-ai-theme', isDark ? 'dark' : 'light');
	}
	themeToggle.addEventListener('click', toggleTheme);

	// Model switcher (per-session, sticky in localStorage — mirrors theme toggle).
	// The option list is fetched from /staff-ai/status (DEF is the single source of
	// truth); the chosen model rides in the chat request and DEF validates it.
	const modelSelect = document.getElementById('modelSelect');
	let selectedModel = localStorage.getItem('staff-ai-model') || '';
	if (modelSelect) {
		modelSelect.addEventListener('change', function () {
			selectedModel = modelSelect.value;
			localStorage.setItem('staff-ai-model', selectedModel);
		});
		if (statusUrl) {
			fetch(statusUrl, { headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (data) {
					if (!data) return;
					var models = data.available_models || [];
					if (!models.length) return;  // nothing switchable (e.g. provider not configured)
					var ids = [];
					models.forEach(function (m) {
						var opt = document.createElement('option');
						opt.value = m.id;
						opt.textContent = m.label + (m.description ? ' — ' + m.description : '');
						modelSelect.appendChild(opt);
						ids.push(m.id);
					});
					// P3-C (D-S9): the task creator's model dropdown is THIS list —
					// the tenant's own, one fetch, both dropdowns. No models = the
					// row stays hidden and the employee default applies, exactly
					// like the chat switcher.
					var taskModelSel = document.getElementById('taskModel');
					if (taskModelSel) {
						models.forEach(function (m) {
							var label = m.label + (m.description ? ' — ' + m.description : '');
							// The editor may have added this id as a raw-id
							// keep-option before this fetch resolved: upgrade it
							// in place (label + no longer a keep-option) - a
							// second append would show the same model twice for
							// the rest of the session (panel round 1).
							var existing = null;
							Array.prototype.forEach.call(taskModelSel.options, function (o) {
								if (o.value === m.id) existing = o;
							});
							if (existing) {
								existing.textContent = label;
								existing.removeAttribute('data-keep');
								return;
							}
							var o = document.createElement('option');
							o.value = m.id;
							o.textContent = label;
							taskModelSel.appendChild(o);
						});
						var taskModelRow = document.getElementById('taskModelRow');
						if (taskModelRow) taskModelRow.style.display = '';
					}
					// Restore the sticky pick if still offered, else default to the
					// first (most capable) — which is the employee default too.
					modelSelect.value = (ids.indexOf(selectedModel) !== -1) ? selectedModel : ids[0];
					selectedModel = modelSelect.value;
					modelSelect.hidden = false;
				})
				.catch(function () { /* non-fatal: switcher stays hidden, default model applies */ });
		}
	}

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

		// V1.2 Result Cards — reset per-turn section counter so the
		// SECTION_FRONTEND_CAP defensive guard applies fresh on each render
		// (Staff AI rebuilds DOM from messages[] every event; CC pre-resets
		// at user-send time which is enough for its in-place pattern).
		if (window.DefResultCards) {
			window.DefResultCards.resetTurn();
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
					// Inline web-citation pills from [src_N] markers (see done handler).
					applyCitations(content, buildCitationMap(msg.tool_outputs));
				} else {
					content.textContent = msg.content;
				}

				// Render tool outputs if present
				if (msg.tool_outputs && msg.tool_outputs.length > 0) {
					msg.tool_outputs.forEach(function(tool) {
						if (tool && tool.result_type === 'sources') return;  // rendered inline as pills
						const card = createToolOutputCard(tool);
						if (card) {
							content.appendChild(card);
						}
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
		// V1.2 Result Cards — search_products tool. Staff AI renders cards
		// with the "Edit product" action (per V1.2 §8). Renderer returns
		// null for invalid/empty payloads — caller skips nulls.
		if (tool && tool.result_type === 'wp_product' && window.DefResultCards) {
			return window.DefResultCards.renderSection(tool, { channel: 'staff_ai' });
		}

		// Web search/fetch sources are NOT a card — they render as inline
		// citation pills in the answer text (see applyCitations). A 'sources'
		// tool_output reaching here is a no-op; callers skip it explicitly.
		if (tool && tool.result_type === 'sources') {
			return null;
		}

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

	// Scheme-validate a URL to http(s) so a poisoned `javascript:`/`data:` URL
	// from untrusted provider data can't become a clickable sink.
	function safeHttpHref(url) {
		if (typeof url !== 'string') return '';
		try {
			var u = new URL(url, window.location.origin);
			return (u.protocol === 'http:' || u.protocol === 'https:') ? u.href : '';
		} catch (e) {
			return '';
		}
	}

	// =============================================
	// Inline web citations — Claude-style source pills
	// =============================================
	// The model cites web_search/web_fetch results inline as [src_N]; the tool
	// ships an id->{title,url,domain} lookup on the 'sources' tool_output. After
	// the answer's markdown is rendered AND DOMPurify-sanitized, we swap each
	// [src_N] token for a clickable pill (one click opens the source) with a
	// hover preview (title + site name). Built via the DOM (never innerHTML)
	// so untrusted title/url/domain stay inert text/attributes, and only in plain
	// text nodes (never inside code/pre or an existing link).
	var CITE_TOKEN_RE = /\[(src_\d+)\]/g;

	function buildCitationMap(toolOutputs) {
		var map = {};
		(toolOutputs || []).forEach(function (o) {
			if (!o || o.result_type !== 'sources' || !o.sources) return;
			o.sources.forEach(function (s) {
				if (s && s.source_id) map[s.source_id] = s;
			});
		});
		return map;
	}

	// Best-effort display name from a domain (e.g. lexology.com -> "Lexology").
	// The provider gives us no publisher name, so we capitalize the brand label
	// of the registrable domain. Common case (foo.com / sub.foo.org) is exact;
	// multi-part TLDs (co.uk, gov.au) step one label left. Real casing
	// (WooCommerce) and curated names aren't recoverable — falls back to the raw
	// domain on any odd shape.
	var _TLD_WORDS = { co: 1, com: 1, gov: 1, org: 1, net: 1, edu: 1, ac: 1 };
	function siteNameFromDomain(domain) {
		if (!domain) return '';
		var parts = domain.split('.').filter(Boolean);
		if (parts.length < 2) return domain;
		var idx = parts.length - 2;  // second-to-last label = brand for foo.com
		if (idx > 0 && _TLD_WORDS[parts[idx]]) idx -= 1;  // co.uk / gov.au -> step left
		var label = parts[idx];
		if (!label) return domain;
		return label.charAt(0).toUpperCase() + label.slice(1);
	}

	function buildCitationPill(src) {
		var href = safeHttpHref(src && src.url);
		if (!href) return null;  // unsafe/missing URL — caller drops the token
		var domain = (src.domain || '').trim();
		var title = (src.title || '').trim();
		var siteLabel = siteNameFromDomain(domain) || domain || href;

		// The pill IS the link — a single click opens the source.
		var pill = document.createElement('a');
		pill.className = 'def-cite';
		pill.href = href;
		pill.target = '_blank';
		pill.rel = 'noopener noreferrer';

		var label = document.createElement('span');
		label.className = 'def-cite-label';
		label.textContent = siteLabel;
		pill.appendChild(label);

		// Hover preview bubble: page title (top), site name (bottom). Content sits
		// in an inner wrapper that highlights when the pointer is on the popup
		// (clickable affordance — matches Claude's citation card).
		var bubble = document.createElement('span');
		bubble.className = 'def-cite-bubble';
		var inner = document.createElement('span');
		inner.className = 'def-cite-bubble-inner';
		if (title) {
			var bTitle = document.createElement('span');
			bTitle.className = 'def-cite-bubble-title';
			bTitle.textContent = title;
			inner.appendChild(bTitle);
		}
		var bSite = document.createElement('span');
		bSite.className = 'def-cite-bubble-site';
		bSite.textContent = siteLabel;
		inner.appendChild(bSite);
		bubble.appendChild(inner);
		pill.appendChild(bubble);

		// Edge-aware bubble: a left-anchored bubble overflows the right edge when
		// the pill sits near the end of a line. On hover/focus, measure the pill
		// and flip the bubble to right-aligned if it would clip (CSS alone can't
		// know the pill's position; the CSS anchor API isn't broadly supported).
		function placeBubble() {
			var r = pill.getBoundingClientRect();
			bubble.classList.toggle('def-cite-bubble-flip', r.left + 280 > window.innerWidth - 12);
		}
		pill.addEventListener('mouseenter', placeBubble);
		pill.addEventListener('focus', placeBubble);
		return pill;
	}

	function applyCitations(rootEl, map) {
		if (!rootEl || !map) return;
		// Collect candidate text nodes first (don't mutate during traversal).
		// Skip tokens inside code/pre (a literal "[src_1]" in shown code) and
		// inside an existing link.
		var walker = document.createTreeWalker(rootEl, NodeFilter.SHOW_TEXT, {
			acceptNode: function (node) {
				if (!node.nodeValue || node.nodeValue.indexOf('[src_') === -1) {
					return NodeFilter.FILTER_REJECT;
				}
				var p = node.parentNode;
				while (p && p !== rootEl) {
					var tag = p.nodeName;
					if (tag === 'CODE' || tag === 'PRE' || tag === 'A') {
						return NodeFilter.FILTER_REJECT;
					}
					p = p.parentNode;
				}
				return NodeFilter.FILTER_ACCEPT;
			}
		});
		var targets = [];
		var n;
		while ((n = walker.nextNode())) targets.push(n);

		targets.forEach(function (node) {
			var text = node.nodeValue;
			var frag = document.createDocumentFragment();
			var lastIndex = 0;
			var m;
			CITE_TOKEN_RE.lastIndex = 0;
			while ((m = CITE_TOKEN_RE.exec(text))) {
				var before = text.slice(lastIndex, m.index);
				if (before) frag.appendChild(document.createTextNode(before));
				var pill = map[m[1]] ? buildCitationPill(map[m[1]]) : null;
				if (pill) frag.appendChild(pill);
				// Unknown id or unsafe URL -> drop the token (no broken "[src_5]").
				lastIndex = m.index + m[0].length;
			}
			if (lastIndex === 0) return;  // no token matched
			var rest = text.slice(lastIndex);
			if (rest) frag.appendChild(document.createTextNode(rest));
			node.parentNode.replaceChild(frag, node);
		});
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
		// No client-side size ceiling (5.7.11) or file-count bound (5.8.1):
		// the server's env-tunable ceiling (UPLOAD_MAX_FILE_MB) and its
		// per-tenant upload rate limiter are the bounds, and their refusals
		// surface through the upload error path with the server's message.
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

		// Re-validate any failed files. Validation is type-only now (5.8.1),
		// so this returns upload-failed chips of valid types to 'staged' —
		// they are re-uploaded by the next send. Type-refused chips stay failed.
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
		// 'uploading' is active (5.8.3): a send during a slow transfer must
		// flow through uploadAllStagedFiles' stall-guarded wait — excluding
		// it sent the text fileless and wiped the spinning chips.
		return stagedFiles.some(function(f) {
			return f.status === 'staged' || f.status === 'uploading' || f.status === 'uploaded';
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
					// Filename tooltip is set via el.title property assignment
					// after render (5.8.4) — never interpolated into an
					// attribute, so no crafted filename can break out.
					+ '<span class="upload-chip-name">' + escapeHtml(f.file.name) + '</span>'
					+ '<span class="upload-chip-size">' + size + '</span>'
					+ '</div>'
					+ '<button type="button" class="upload-chip-remove" aria-label="Remove">&times;</button>'
					+ errorTip
					+ '</div>';
			}
		}).join('');

		// Filename tooltips via property assignment (5.8.4) — the structural
		// XSS fix: the value never passes through the HTML parser at all.
		stagedArea.querySelectorAll('.upload-chip').forEach(function(chipEl) {
			var f = stagedFiles.filter(function(x) { return x.localId === parseInt(chipEl.dataset.id, 10); })[0];
			var nameEl = chipEl.querySelector('.upload-chip-name');
			if (f && nameEl) nameEl.title = f.file.name;
		});

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
		// A settling background upload changes the active set — refresh the
		// send button so a failure can't leave it stale-enabled (5.8.3).
		updateSendButton();
	}

	async function uploadAllStagedFiles() {
		// Upload any files still in 'staged' state (e.g., if stageFile upload hasn't started yet).
		var toUpload = stagedFiles.filter(function(f) { return f.status === 'staged'; });
		if (toUpload.length > 0) {
			await Promise.all(toUpload.map(function(f) { return uploadSingleFile(f); }));
		}

		// Wait for any files still uploading (started by stageFile in background).
		// A stall guard, not a batch clock (5.8.1): with the file-count bound
		// gone, batch duration scales with what the user attached, so a flat
		// whole-batch timeout would fail honest large batches. Uploads all
		// start before this loop, so the in-flight count only ever shrinks —
		// a shrink is progress, and only 30s with NO progress fails the rest.
		var stallLimitMs = 30000;
		var pollIntervalMs = 200;
		var stalledMs = 0;
		var lastUploadingCount = -1;
		for (;;) {
			var stillUploading = stagedFiles.filter(function(f) { return f.status === 'uploading'; });
			if (stillUploading.length === 0) break;
			if (stillUploading.length !== lastUploadingCount) {
				lastUploadingCount = stillUploading.length;
				stalledMs = 0;
			}
			if (stalledMs >= stallLimitMs) break;
			await new Promise(function(resolve) { setTimeout(resolve, pollIntervalMs); });
			stalledMs += pollIntervalMs;
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
		// Insert before the typing indicator. Search directly (not via
		// `.message:last-child`) so the V2 persona divider being the last
		// sibling doesn't break the lookup.
		var typingMsg = messagesList.querySelector('.typing-indicator');
		if (typingMsg) {
			typingMsg.parentNode.insertBefore(el, typingMsg);
		}
		return el;
	}

	function completeToolStatus(el, toolName, status) {
		if (!el) return;
		var failed = status && status !== 'success';
		el.classList.add(failed ? 'failed' : 'done');
		var icon = failed ? '&#10007;' : '&#10003;';
		// Persist the in-progress label after completion — "Searching... ✓ Done"
		// rather than the in-progress text being replaced by a separate done
		// label. Mirrors the same fix in def-core-customer-chat.js.
		var inProgressLabel = TOOL_STATUS_LABELS[toolName] || (toolName + '...');
		var suffix = failed ? 'Failed' : 'Done';
		el.innerHTML = '<span class="tool-label">' + escapeHtml(inProgressLabel) + '</span>'
			+ ' <span class="tool-checkmark">' + icon + '</span> '
			+ '<span class="tool-label">' + suffix + '</span>';
	}

	function updateTypingLabel(text) {
		// Search directly so the V2 persona divider being the last sibling
		// doesn't break the lookup (same fix as addToolStatus / text_delta).
		var typingMsg = messagesList.querySelector('.typing-indicator');
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

		if (isLoading || isReadOnly) return;

		// Failure paths are loud (5.8.3): any failed chip refuses the send
		// with its reason — ABOVE the empty-input return, so failed-only +
		// empty input is refused loudly too (a background upload failure
		// leaves the send button enabled). Without this, hasActiveFiles()=
		// false skipped the upload block entirely — the text went out
		// FILELESS and the success path's clearStagedFiles() wiped the
		// failed chips, silently.
		var failedChips = stagedFiles.filter(function(f) { return f.status === 'failed'; });
		if (failedChips.length > 0) {
			showError(failedChips[0].error || t('removeFailedFiles', 'Some files failed to upload. Remove failed files and try again.'));
			return;
		}

		if (!text && !hasFiles) return;

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
			if (selectedModel) requestBody.model_id = selectedModel;
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
				// String-safe extraction (5.8.4): a DEF refusal object here
				// used to stringify to "[object Object]" in the banner.
				var errText = '';
				try {
					var errData = await response.json();
					errText = extractServerMessage(errData) ||
						(typeof errData.message === 'string' ? errData.message : '');
				} catch (e) { /* ignore */ }
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

			// V2 persona indicator (Spec V1.4 §6) — shared helper. Divider
			// appends to the messages-list root (matching Customer Chat and
			// Setup Assistant) so it sits between message bubbles, not inside
			// the last bubble — avoids layout breakage from the dashed
			// top/bottom borders inside .message-content padding, and avoids
			// the edge case where an agent-tagged event arrives before the
			// typing indicator is rendered (which would land the divider
			// inside the user's bubble).
			var persona = window.DefPersona.createController({
				dividerCssClass:        'staff-ai-speaker-divider',
				thinkingLabelSelector:  '.tool-label',
				appendDivider: function (div) {
					messagesList.appendChild(div);
					if (!_userScrolledUp) {
						messagesContainer.scrollTop = messagesContainer.scrollHeight;
					}
				},
			});

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
					persona.handleEvent(evt, thinkingStatusEl);
					if (evt.type === 'thinking') {
						var thinkMsg = persona.formatThinkingLabel(evt.message);
						if (!thinkingStatusEl) {
							var div = document.createElement('div');
							div.className = 'tool-status';
							div.innerHTML = '<span class="tool-spinner"></span><span class="tool-label"></span>';
							div.querySelector('.tool-label').textContent = thinkMsg;
							// Find the typing indicator directly (not via
							// `.message:last-child`) — V2 persona divider can be
							// the last sibling and break that selector.
							var typing = messagesList.querySelector('.typing-indicator');
							var target = typing ? typing.parentNode : null;
							if (typing) {
								typing.style.display = 'none';
								target.insertBefore(div, typing);
							}
							var oldLbl = messagesList.querySelector('.typing-label');
							if (oldLbl) oldLbl.remove();
							thinkingStatusEl = div;
						} else {
							var saLabel = thinkingStatusEl.querySelector('.tool-label');
							if (saLabel) saLabel.textContent = thinkMsg;
						}
					} else if (evt.type === 'tool_start') {
						var el = renderToolStatus(evt.tool);
						toolStatusElements[evt.tool] = el;
						await new Promise(function(r) { setTimeout(r, SSE_TOOL_PACING_MS); });
					} else if (evt.type === 'tool_done') {
						completeToolStatus(toolStatusElements[evt.tool], evt.tool, evt.status);
						await new Promise(function(r) { setTimeout(r, SSE_TOOL_PACING_MS); });
					} else if (evt.type === 'text_delta') {
						if (thinkingStatusEl) { thinkingStatusEl.remove(); thinkingStatusEl = null; }
						if (!streamEl) {
							// Take over the typing indicator message. Search for the
							// indicator directly (not `.message:last-child`) — V2 specialist
							// spawns append a persona divider as the last sibling, so the
							// typing bubble is no longer `:last-child` and the old selector
							// returned null, silently dropping all streamed text.
							var typingInd = messagesList.querySelector('.typing-indicator');
							var lastMsg = typingInd ? typingInd.closest('.message') : null;
							if (lastMsg) {
								lastMsg.classList.add('message-streaming');
								var contentDiv = lastMsg.querySelector('.message-content');
								if (contentDiv) {
									typingInd.remove();
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

						// Prefer streamBuffer (what was actually rendered — truth)
						// over done.content. In V2 spawn flows the user sees
						// specialist + Concierge text streamed into one bubble
						// (~1900 tokens combined); evt.choices[0].message.content
						// is only the Concierge's final wrap-up (~170 tokens).
						// done.content is the fallback for no-streaming cases.
						var finalMessage = evt?.choices?.[0]?.message || {};
						var streamedText = (streamBuffer || '').trim();
						var doneContent = (finalMessage.content || '').trim();
						var finalContent = streamedText || doneContent;
						if (!finalContent) {
							finalContent = t('failedToSend', 'Sorry, something went wrong. Please try again.');
						}

						var toolOutputs = finalMessage.tool_outputs || [];

						messages.push({
							role: 'assistant',
							content: finalContent,
							tool_outputs: toolOutputs
						});

						// Finalize the streamed bubble in place — match Customer
						// Chat's V2 pattern. Do NOT call renderMessages() when
						// streamEl exists: rebuild from messages[] would wipe the
						// multi-agent streaming (specialist + Concierge), corrupt
						// the persona divider's DOM position, and orphan tool-
						// status indicators. messages[] still gets the entry above
						// for state consistency; loadConversation() refetches
						// from server on conversation switch.
						if (streamEl) {
							streamEl.innerHTML = renderMarkdown(finalContent);
							if (streamEl.parentNode) {
								streamEl.parentNode.classList.remove('message-streaming');
							}
							// Web citations render inline in the answer (pills), not
							// as a card — swap [src_N] markers before appending cards.
							applyCitations(streamEl, buildCitationMap(toolOutputs));
							toolOutputs.forEach(function(tool) {
								if (tool && tool.result_type === 'sources') return;  // inline pills, not a card
								var card = createToolOutputCard(tool);
								// Skip nulls — DefResultCards.renderSection can return null
								// (empty result_cards, frontend cap reached, DOMPurify missing,
								// or all cards filtered out by validation). appendChild(null)
								// would TypeError out of the SSE event loop.
								if (card) {
									streamEl.appendChild(card);
								}
							});
						} else {
							// No streaming happened (tool-only response or empty).
							// Safe to renderMessages() — there's no streamed DOM
							// to wipe.
							renderMessages();
						}

						streamBuffer = '';
						streamEl = null;
						wordDrainTimer = null;
						lastRenderedLen = 0;
						_userScrolledUp = false;
						thinkingStatusEl = null;
						_isStreaming = false;
						persona.reset();

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
						removeTypingMessage();
						// If text already streamed, leave it visible — finalise the
						// bubble and surface the error separately. Match Customer
						// Chat's pattern: don't renderMessages() and wipe what the
						// user already saw. If nothing streamed, safe to rebuild.
						if (streamEl) {
							var partial = (streamBuffer || '').trim();
							if (partial) {
								streamEl.innerHTML = renderMarkdown(partial);
							}
							if (streamEl.parentNode) {
								streamEl.parentNode.classList.remove('message-streaming');
							}
						} else {
							renderMessages();
						}
						streamBuffer = '';
						streamEl = null;
						wordDrainTimer = null;
						lastRenderedLen = 0;
						_userScrolledUp = false;
						_isStreaming = false;
						persona.reset();
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
		shareSend.disabled = !(hasRecipient && hasSubject && hasMessage) || shareDocsOverLimit();
	}

	// --- Share document attachments ---
	// Mirror of the server cap — the server still enforces; this only gives
	// instant feedback instead of a failed send.
	const SHARE_ATTACH_MAX_BYTES = 15728640; // 15MB
	let shareDocs = [];
	let shareSelectedDocIds = [];

	function shareDocSize(bytes) {
		if (!bytes || bytes < 1024) return (bytes || 0) + ' B';
		if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
		return (bytes / 1048576).toFixed(1) + ' MB';
	}

	function shareDocsOverLimit() {
		let total = 0;
		shareDocs.forEach(function(doc) {
			if (shareSelectedDocIds.indexOf(doc.document_id) !== -1) total += doc.size_bytes || 0;
		});
		return total > SHARE_ATTACH_MAX_BYTES;
	}

	function renderShareDocuments(docs) {
		shareDocs = Array.isArray(docs) ? docs : [];
		shareSelectedDocIds = [];
		shareDocumentsList.innerHTML = '';
		shareDocumentsHint.style.display = 'none';
		if (shareDocs.length === 0) {
			shareDocumentsSection.style.display = 'none';
			return;
		}
		shareDocs.forEach(function(doc) {
			const row = document.createElement('label');
			row.className = 'share-document-row';
			const cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.value = doc.document_id;
			cb.addEventListener('change', function() {
				if (cb.checked) {
					shareSelectedDocIds.push(doc.document_id);
				} else {
					shareSelectedDocIds = shareSelectedDocIds.filter(function(id) { return id !== doc.document_id; });
				}
				const over = shareDocsOverLimit();
				shareDocumentsHint.textContent = over ? t('shareAttachLimit', 'Selected documents exceed the 15MB attachment limit.') : '';
				shareDocumentsHint.style.display = over ? '' : 'none';
				updateShareSendButton();
			});
			const name = document.createElement('span');
			name.className = 'share-document-title';
			name.textContent = doc.title || doc.document_id;
			name.title = doc.title || '';
			const size = document.createElement('span');
			size.className = 'share-document-size';
			size.textContent = shareDocSize(doc.size_bytes);
			row.appendChild(cb);
			row.appendChild(name);
			row.appendChild(size);
			shareDocumentsList.appendChild(row);
		});
		shareDocumentsSection.style.display = '';
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
			// Fetch recipients + AI summary + attachable documents in parallel.
			// Documents are best-effort: a failure just hides the attach section.
			const [settingsResp, summaryResp, docsResp] = await Promise.all([
				apiRequest('/share-settings', {
					method: 'GET'
				}).catch(function() { throw new Error('Failed to load recipients'); }),
				apiRequest('/conversations/' + encodeURIComponent(currentConversationId) + '/summarize', {
					method: 'POST'
				}),
				apiRequest('/documents').catch(function() { return null; })
			]);

			// Populate token select with recipients (use recipient_options for display)
			shareAvailableRecipients = (settingsResp && settingsResp.recipient_options) || [];
			shareSelectedRecipients = [];
			renderTokenSelect();

			// Populate subject + message from AI summary
			shareSubject.value = (summaryResp && summaryResp.suggested_subject) || '';
			shareMessage.value = (summaryResp && summaryResp.summary) || '';
			shareTranscript.checked = true;
			renderShareDocuments(docsResp && docsResp.documents);

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
			const payload = {
				to: selectedRecipients.map(function(r) { return r.email; }),
				subject: shareSubject.value,
				body: bodyText
			};
			if (shareSelectedDocIds.length > 0) {
				payload.document_ids = shareSelectedDocIds.slice();
			}
			await apiRequest('/share-send', {
				method: 'POST',
				body: JSON.stringify(payload)
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
	// CONNECTED ACCOUNTS (per-user integrations — Slice 2)
	// Each user connects their OWN aggregator account so a tool acts AS them.
	// =============================================
	(function initIntegrations() {
		const modal = document.getElementById('integrationsModal');
		if (!modal) return;

		const openBtn = document.getElementById('navConnections');
		const modalClose = document.getElementById('integrationsModalClose');
		const closeBtn = document.getElementById('integrationsClose');
		const refreshBtn = document.getElementById('integrationsRefresh');
		const statusEl = document.getElementById('integrationsStatus');
		const listEl = document.getElementById('integrationsList');
		let loading = false;
		// True only while an authorize POST is in flight, so the window-focus re-check can't
		// rebuild the list mid-connect — that would detach the row node and drop the "Finish
		// connecting" link the user still needs. See connect() and the focus handler below.
		let posting = false;

		function open() {
			modal.classList.add('visible');
			loadList();
		}
		function close() {
			modal.classList.remove('visible');
		}

		// Slug → display label: "slack" → "Slack", "google_drive" → "Google Drive".
		function prettyName(category, serverId) {
			if (category) {
				return category.replace(/[_-]+/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
			}
			return serverId;
		}

		function setStatus(message, kind) {
			statusEl.textContent = message || '';
			statusEl.className = 'integrations-status' + (message ? ' integrations-status-' + (kind || 'muted') : '');
		}

		async function loadList() {
			if (loading) return;
			loading = true;
			setStatus(t('integrationsLoading', 'Loading your connected accounts…'), 'muted');
			listEl.innerHTML = '';
			try {
				const data = await apiRequest('/user/integrations');
				if (!data.configured) {
					setStatus(t('integrationsNotConfigured', 'Integrations aren’t set up for your team yet. Ask an administrator to connect apps.'), 'muted');
					return;
				}
				const apps = Array.isArray(data.apps) ? data.apps : [];
				if (apps.length === 0) {
					setStatus(t('integrationsEmpty', 'No connected apps yet. Ask an administrator to add integrations.'), 'muted');
					return;
				}
				setStatus('', '');
				apps.forEach(function (app) { listEl.appendChild(renderRow(app)); });
			} catch (e) {
				setStatus((e && e.message) || t('integrationsLoadFailed', 'Could not load your connected accounts.'), 'error');
			} finally {
				loading = false;
			}
		}

		function renderRow(app) {
			const row = document.createElement('div');
			row.className = 'integration-row';

			const name = document.createElement('span');
			name.className = 'integration-name';
			name.textContent = prettyName(app.category, app.server_id);
			row.appendChild(name);

			const action = document.createElement('div');
			action.className = 'integration-action';

			if (app.no_auth || app.authorized) {
				const badge = document.createElement('span');
				badge.className = 'integration-badge integration-badge-ok';
				badge.textContent = app.no_auth
					? t('integrationsReady', 'Ready')
					: t('integrationsConnected', 'Connected');
				action.appendChild(badge);
				// NO Reconnect here, deliberately. It rendered only when `authorized` was
				// true — i.e. when we had just confirmed a live grant — and clicking it
				// posted an authorize that came straight back `status: 'authorized'` and
				// redrew the identical row. Visible exactly when it did nothing.
				//
				// The case it claimed to serve (the grant died at the provider) arrives as
				// `authorized: false`, so that row offers **Connect**. And in the one race
				// where Reconnect did act, it minted a SECOND grant beside the first —
				// which is where this tenant's outlook×3, slack×3 and gmail×2 came from.
				//
				// With Disconnect shipping, the honest flow is Disconnect → Connect: one
				// grant at a time, and switching which account is linked actually replaces
				// it instead of stacking another.
			} else {
				const connectBtn = document.createElement('button');
				connectBtn.type = 'button';
				connectBtn.className = 'integration-btn integration-btn-primary';
				connectBtn.textContent = t('integrationsConnect', 'Connect');
				connectBtn.addEventListener('click', function () { connect(app.server_id, action); });
				action.appendChild(connectBtn);
			}

			// Gated on has_grant — deliberately outside the branch above. has_grant is
			// EVIDENCE of a live grant: the union of a per-toolkit signal and the
			// per-auth-config `authorized`, because each misses rows the other catches. A
			// grant that outlived the server its tools point at reads authorized=false with
			// the toolkit granted (a3rev's Gmail); a stored name that does not match the
			// provider's slug reads the reverse. Evidence can be wrong in the second case,
			// which is why the handler reports honestly when the revoke finds nothing.
			// Reconnect on its own was the whole problem: it minted ANOTHER grant, and a
			// person who left, or a business moving from Gmail to M365, had no way out.
			if (app.has_grant && !app.no_auth) {
				const dis = document.createElement('button');
				dis.type = 'button';
				dis.className = 'integration-btn integration-btn-link integration-btn-danger';
				dis.textContent = t('integrationsDisconnect', 'Disconnect');
				dis.addEventListener('click', function () {
					disconnect(app.server_id, prettyName(app.category, app.server_id), action);
				});
				action.appendChild(dis);
			}

			row.appendChild(action);
			return row;
		}

		async function disconnect(serverId, appName, action) {
			// Names what it does and does NOT do. "Disconnect" next to a shared app reads
			// like it might cut the whole team off; it ends this person's access only.
			if (!window.confirm(
				t('integrationsDisconnectConfirm', 'Disconnect %s? This ends your own access. Your team’s connection to %s stays, and you can connect again later.')
					.split('%s').join(appName)
			)) return;

			const buttons = action.querySelectorAll('button');
			buttons.forEach(function (b) { b.disabled = true; });
			setStatus(t('integrationsDisconnecting', 'Disconnecting…'), 'muted');
			posting = true;
			try {
				const res = await apiRequest('/user/integrations/' + encodeURIComponent(serverId) + '/disconnect', { method: 'POST' });
				posting = false;
				// Reload FIRST, then speak. loadList() runs synchronously to its first await
				// and sets its own "Loading…" status, so setting the outcome before it is a
				// message the user never sees — the honesty chain survives DEF → DEFHO →
				// def-core and then gets overwritten one line later.
				await loadList();
				// `failed` is a grant the provider would not end. Saying "disconnected" over
				// that would be the same overclaim the old copy made in the other direction.
				if (res && res.failed) {
					setStatus(t('integrationsDisconnectPartial', 'Some of your access could not be ended. Try again, or ask an administrator.'), 'error');
				} else if (!res || !res.requested) {
					// Nothing was found to end. Saying "Disconnected" here is the overclaim
					// this whole change exists to remove: the control is offered on EVIDENCE
					// of a grant, and the evidence can be wrong (a stored app name that does
					// not match what the provider calls it). Report what actually happened.
					setStatus(t('integrationsNothingToEnd', 'No live connection to this app was found under the name we have for it. If you still have access, remove it in your account settings for that app, or ask an administrator.'), 'muted');
				} else {
					setStatus(t('integrationsDisconnected', 'Disconnected. Ending access with the provider can take a moment.'), 'muted');
				}
			} catch (e) {
				posting = false;
				buttons.forEach(function (b) { b.disabled = false; });
				setStatus((e && e.message) || t('integrationsDisconnectFailed', 'Could not disconnect that app.'), 'error');
			}
		}

		async function connect(serverId, action) {
			const buttons = action.querySelectorAll('button');
			buttons.forEach(function (b) { b.disabled = true; });
			setStatus(t('integrationsStarting', 'Starting the connection…'), 'muted');
			posting = true;
			try {
				const res = await apiRequest('/user/integrations/' + encodeURIComponent(serverId) + '/authorize', { method: 'POST' });
				posting = false;
				if (res.status === 'authorized') {
					loadList();  // already linked — the rebuilt row shows the Connected badge
					return;
				}
				const url = (typeof res.redirect_url === 'string') ? res.redirect_url : '';
				if (/^https:\/\//i.test(url)) {
					// Render the consent URL as an explicit link the user clicks (a real user
					// gesture — avoids popup blockers). DEF already host-checked it. They finish
					// in the new tab; the window-focus handler re-checks status on return.
					action.innerHTML = '';
					const link = document.createElement('a');
					link.className = 'integration-btn integration-btn-primary';
					link.href = url;
					link.target = '_blank';
					link.rel = 'noopener noreferrer';
					link.textContent = t('integrationsFinish', 'Finish connecting →');
					action.appendChild(link);
					setStatus(t('integrationsAwaiting', 'Click “Finish connecting”, approve access in the new tab, then return here — I’ll refresh automatically.'), 'muted');
				} else {
					buttons.forEach(function (b) { b.disabled = false; });
					setStatus(t('integrationsNoLink', 'Could not start the connection. Please try again.'), 'error');
				}
			} catch (e) {
				posting = false;
				buttons.forEach(function (b) { b.disabled = false; });
				setStatus((e && e.message) || t('integrationsConnectFailed', 'Could not start the connection.'), 'error');
			}
		}

		if (openBtn) openBtn.addEventListener('click', open);
		if (modalClose) modalClose.addEventListener('click', close);
		if (closeBtn) closeBtn.addEventListener('click', close);
		if (refreshBtn) refreshBtn.addEventListener('click', loadList);
		modal.addEventListener('click', function (e) { if (e.target === modal) close(); });

		// Re-check status when the user returns from the OAuth consent tab (modal open only).
		// Skip while an authorize POST is in flight (`posting`) so we don't rebuild the row the
		// connect() call is about to populate with the "Finish connecting" link.
		window.addEventListener('focus', function () {
			if (modal.classList.contains('visible') && !loading && !posting) loadList();
		});
	})();

	// =============================================
	// MY DOCUMENTS PANEL (document library — slice 4)
	// =============================================

	(function initDocuments() {
		const modal = document.getElementById('documentsModal');
		if (!modal) return;

		const openBtn = document.getElementById('navDocuments');
		const modalClose = document.getElementById('documentsModalClose');
		const closeBtn = document.getElementById('documentsClose');
		const refreshBtn = document.getElementById('documentsRefresh');
		const statusEl = document.getElementById('documentsStatus');
		const listEl = document.getElementById('documentsList');
		const searchEl = document.getElementById('documentsSearch');
		let loading = false;
		let searchTimer = null;

		function searchTerm() {
			return searchEl ? searchEl.value.trim() : '';
		}

		function open() {
			modal.classList.add('visible');
			loadList();
		}
		function close() {
			modal.classList.remove('visible');
		}

		function setStatus(message, kind) {
			statusEl.textContent = message || '';
			statusEl.className = 'documents-status' + (message ? ' documents-status-' + (kind || 'muted') : '');
		}

		function formatSize(bytes) {
			if (!bytes || bytes < 1024) return (bytes || 0) + ' B';
			if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
			return (bytes / 1048576).toFixed(1) + ' MB';
		}

		async function loadList() {
			if (loading) return;
			loading = true;
			const term = searchTerm();
			setStatus(t('documentsLoading', 'Loading your documents…'), 'muted');
			listEl.innerHTML = '';
			try {
				// apiBase can be the plain-permalink ?rest_route= form — pick the
				// separator accordingly (same idiom as def-core-product-cards.js).
				const sep = apiBase.indexOf('?') === -1 ? '?' : '&';
				const data = await apiRequest('/documents' + (term ? sep + 'q=' + encodeURIComponent(term) : ''));
				const docs = Array.isArray(data.documents) ? data.documents : [];
				if (docs.length === 0) {
					setStatus(term
						? t('documentsNoMatches', 'No documents match your search.')
						: t('documentsEmpty', 'No documents yet — ask me to create one and it will appear here.'), 'muted');
					return;
				}
				setStatus('', '');
				docs.forEach(function (doc) { listEl.appendChild(renderRow(doc)); });
			} catch (e) {
				setStatus((e && e.message) || t('documentsLoadFailed', 'Could not load your documents.'), 'error');
			} finally {
				loading = false;
				// A keystroke that landed mid-request was dropped by the
				// `loading` guard — re-run for the newest term.
				if (searchTerm() !== term) {
					clearTimeout(searchTimer);
					loadList();
				}
			}
		}

		function renderRow(doc) {
			const row = document.createElement('div');
			row.className = 'document-row';

			const info = document.createElement('div');
			info.className = 'document-info';
			const name = document.createElement('span');
			name.className = 'document-name';
			name.textContent = doc.title || doc.document_id;
			info.appendChild(name);
			const meta = document.createElement('span');
			meta.className = 'document-meta';
			meta.textContent = [
				(doc.file_type || '').toUpperCase(),
				formatSize(doc.size_bytes),
				formatTime(doc.created_at)
			].filter(Boolean).join(' · ');
			info.appendChild(meta);
			row.appendChild(info);

			const action = document.createElement('div');
			action.className = 'document-action';

			const href = safeHttpHref(doc.download_url);
			if (href) {
				const dl = document.createElement('a');
				dl.className = 'document-btn document-btn-download';
				dl.href = href;
				dl.textContent = t('download', 'Download');
				action.appendChild(dl);
			}

			const del = document.createElement('button');
			del.type = 'button';
			del.className = 'document-btn document-btn-delete';
			del.textContent = t('documentsDelete', 'Delete');
			del.addEventListener('click', function () { removeDoc(doc, del, row); });
			action.appendChild(del);

			row.appendChild(action);
			return row;
		}

		async function removeDoc(doc, btn, row) {
			// Function replacement — a title containing $& / $' would garble a
			// string-pattern replace. Fallback matches the row label's.
			const msg = t('documentsConfirmDelete', 'Delete "%s"? This permanently removes it from your library.')
				.replace('%s', function () { return doc.title || doc.document_id; });
			if (!window.confirm(msg)) return;
			btn.disabled = true;
			try {
				await apiRequest('/documents/' + encodeURIComponent(doc.document_id), { method: 'DELETE' });
				row.remove();
				setStatus('', '');
				if (!listEl.children.length) {
					setStatus(searchTerm()
						? t('documentsNoMatches', 'No documents match your search.')
						: t('documentsEmpty', 'No documents yet — ask me to create one and it will appear here.'), 'muted');
				}
			} catch (e) {
				btn.disabled = false;
				setStatus((e && e.message) || t('documentsDeleteFailed', 'Could not delete the document.'), 'error');
			}
		}

		if (openBtn) openBtn.addEventListener('click', open);
		if (modalClose) modalClose.addEventListener('click', close);
		if (closeBtn) closeBtn.addEventListener('click', close);
		if (refreshBtn) refreshBtn.addEventListener('click', loadList);
		if (searchEl) {
			// Debounced as-you-type (the native clear control fires 'input'
			// too); Enter searches immediately.
			searchEl.addEventListener('input', function () {
				clearTimeout(searchTimer);
				searchTimer = setTimeout(loadList, 300);
			});
			searchEl.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					clearTimeout(searchTimer);
					loadList();
				}
			});
		}
		modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
	})();

	// =============================================
	// MEMORIES PANEL (what the assistant remembers — privacy slice B)
	// =============================================

	(function initMemories() {
		const modal = document.getElementById('memoriesModal');
		if (!modal) return;

		const openBtn = document.getElementById('navMemories');
		const modalClose = document.getElementById('memoriesModalClose');
		const closeBtn = document.getElementById('memoriesClose');
		const refreshBtn = document.getElementById('memoriesRefresh');
		const statusEl = document.getElementById('memoriesStatus');
		const listEl = document.getElementById('memoriesList');
		let loading = false;

		// Plain-language names for DEF's categories. An unknown category falls
		// through to its own name rather than rendering a blank kind.
		const categoryLabels = {
			role: t('memoryCategoryRole', 'Your role'),
			preferences: t('memoryCategoryPreferences', 'Preference'),
			projects: t('memoryCategoryProjects', 'Project'),
			habits: t('memoryCategoryHabits', 'How you work'),
			context: t('memoryCategoryContext', 'Background')
		};

		function open() {
			modal.classList.add('visible');
			loadList();
		}
		function close() {
			modal.classList.remove('visible');
		}

		function setStatus(message, kind) {
			statusEl.textContent = message || '';
			statusEl.className = 'memories-status' + (message ? ' memories-status-' + (kind || 'muted') : '');
		}

		async function loadList() {
			if (loading) return;
			loading = true;
			setStatus(t('memoriesLoading', 'Loading what Staff AI remembers…'), 'muted');
			listEl.innerHTML = '';
			try {
				const data = await apiRequest('/memories');
				const memories = Array.isArray(data.memories) ? data.memories : [];
				if (memories.length === 0) {
					setStatus(t('memoriesEmpty', 'Staff AI has not noted anything about you yet.'), 'muted');
					return;
				}
				setStatus('', '');
				memories.forEach(function (memory) { listEl.appendChild(renderRow(memory)); });
			} catch (e) {
				// An error, never an empty list — DEF answers 503 when the store is
				// unreachable, and "nothing here" would be a lie about their data.
				setStatus((e && e.message) || t('memoriesLoadFailed', 'Could not load what Staff AI remembers.'), 'error');
			} finally {
				loading = false;
			}
		}

		function renderRow(memory) {
			const row = document.createElement('div');
			row.className = 'memory-row';

			const info = document.createElement('div');
			info.className = 'memory-info';
			const content = document.createElement('span');
			content.className = 'memory-content';
			content.textContent = memory.content || '';
			info.appendChild(content);
			const meta = document.createElement('span');
			meta.className = 'memory-meta';
			meta.textContent = [
				categoryLabels[memory.category] || memory.category || '',
				// created_at, matching the order DEF sorts by — showing updated_at
				// against a created_at sort renders dates that read out of sequence.
				formatTime(memory.created_at)
			].filter(Boolean).join(' · ');
			info.appendChild(meta);
			row.appendChild(info);

			const del = document.createElement('button');
			del.type = 'button';
			del.className = 'memory-btn-delete';
			del.textContent = t('memoriesDelete', 'Delete');
			del.addEventListener('click', function () { removeMemory(memory, del, row); });
			row.appendChild(del);

			return row;
		}

		async function removeMemory(memory, btn, row) {
			// Function replacement — remembered content containing $& / $' would
			// garble a string-pattern replace (the doclib lesson).
			const msg = t('memoriesConfirmDelete', 'Delete "%s"? It is removed now, but can be noted again from conversations you still have.')
				.replace('%s', function () { return memory.content || ''; });
			if (!window.confirm(msg)) return;
			btn.disabled = true;
			try {
				await apiRequest('/memories/' + encodeURIComponent(memory.entry_id), { method: 'DELETE' });
				row.remove();
				setStatus('', '');
				if (!listEl.children.length) {
					setStatus(t('memoriesEmpty', 'Staff AI has not noted anything about you yet.'), 'muted');
				}
			} catch (e) {
				btn.disabled = false;
				setStatus((e && e.message) || t('memoriesDeleteFailed', 'Could not delete that memory.'), 'error');
			}
		}

		if (openBtn) openBtn.addEventListener('click', open);
		if (modalClose) modalClose.addEventListener('click', close);
		if (closeBtn) closeBtn.addEventListener('click', close);
		if (refreshBtn) refreshBtn.addEventListener('click', loadList);
		modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
	})();

	// =============================================
	// SCHEDULED TASKS (Phase 3) - the full-pane landing page + the creator.
	// The list is a PAGE in the content area (D-S7), not a fifth modal; the
	// modal is now the creator/editor only, with two typed forms: the free-text
	// task (the DEFAULT type, D-S2) and the Email Triage schedule (S4b).
	// =============================================

	(function initScheduled() {
		var pane = document.getElementById('scheduledPane');
		var modal = document.getElementById('scheduleModal');
		if (!pane || !modal) return;
		var navBtn = document.getElementById('navScheduled');
		var grid = document.getElementById('taskCardGrid');
		var paneEmpty = document.getElementById('scheduledEmpty');
		var paneStatus = document.getElementById('scheduledPaneStatus');
		var newTaskBtn = document.getElementById('taskCreateBtn');
		var messagesEl = document.getElementById('messagesContainer');
		var composerEl = document.getElementById('composerContainer');

		var modalClose = document.getElementById('scheduleModalClose');
		var closeBtn = document.getElementById('scheduleClose');
		var backBtn = document.getElementById('scheduleBack');
		var saveBtn = document.getElementById('scheduleSave');
		var titleEl = document.getElementById('scheduleTitle');

		// Free-text form (the creator's default view).
		var taskView = document.getElementById('taskFormView');
		var typeRow = document.getElementById('taskTypeRow');
		var typeEl = document.getElementById('taskType');
		var nameEl = document.getElementById('taskName');
		var instructionEl = document.getElementById('taskInstruction');
		var taskEnabledEl = document.getElementById('taskEnabled');
		var taskCadenceEl = document.getElementById('taskCadence');
		var taskModelEl = document.getElementById('taskModel');
		var taskWeekdayEl = document.getElementById('taskWeekday');
		var taskWeekdayRow = document.getElementById('taskWeekdayRow');
		var taskCadenceHint = document.getElementById('taskCadenceHint');
		var taskTimeRow = document.getElementById('taskTimeRow');
		var taskTzRow = document.getElementById('taskTzRow');
		var taskTimeEl = document.getElementById('taskTime');
		var taskTzEl = document.getElementById('taskTimezone');
		var taskStatusEl = document.getElementById('taskStatus');
		var taskDestEls = {
			email: document.getElementById('taskDestEmail'),
			slack: document.getElementById('taskDestSlack'),
			teams: document.getElementById('taskDestTeams')
		};

		// Email Triage form (S4b; Phase 4a adds the mailbox picker).
		var triageView = document.getElementById('scheduleFormView');
		var statusEl = document.getElementById('scheduleStatus');
		var enabledEl = document.getElementById('scheduleEnabled');
		var timeEl = document.getElementById('scheduleTime');
		var tzEl = document.getElementById('scheduleTimezone');
		var mailboxRow = document.getElementById('scheduleMailboxRow');
		var mailboxEl = document.getElementById('scheduleMailbox');
		var mailConnections = null;  // null = not loaded / unreadable; [] = genuinely none
		var destEls = {
			email: document.getElementById('scheduleDestEmail'),
			slack: document.getElementById('scheduleDestSlack'),
			teams: document.getElementById('scheduleDestTeams')
		};

		var loading = false;
		var current = null;          // the triage schedule, or null when none/unknown
		var tasks = [];              // the user's free-text tasks
		var editingTaskId = null;    // task id under edit, null = creating
		// Which chat apps the user has actually CONNECTED (has_grant per
		// toolkit, the D-C2b predicate). The "Deliver to" list is DERIVED from
		// this rather than enumerated (runsheet §14b): a checkbox for an app
		// with no connection is a control that can only fail downstream, in a
		// log nobody reads. null = not loaded yet (rows stay visible: refusing
		// to hide on unknown beats hiding a working option).
		var connectedApps = null;

		function setStatus(el, msg, kind) {
			el.textContent = msg || '';
			el.className = 'schedule-status' + (kind ? ' schedule-status-' + kind : '');
		}

		function pad(n) { n = String(n); return n.length < 2 ? '0' + n : n; }

		function fillTimezones(selectEl, selected) {
			var zones = [];
			try { zones = Intl.supportedValuesOf('timeZone').slice(); } catch (e) { zones = []; }
			var site = (window.StaffAIConfig && StaffAIConfig.siteTimezone) || '';
			[site, selected, 'UTC'].forEach(function (z) {
				// Only IANA-shaped names: a manual-offset site tz would be an option
				// the backend can only refuse (Code panel F2).
				if (z && (z === 'UTC' || z.indexOf('/') !== -1) && zones.indexOf(z) === -1) zones.unshift(z);
			});
			selectEl.textContent = '';
			zones.forEach(function (z) {
				var opt = document.createElement('option');
				opt.value = z;
				opt.textContent = z;
				if (z === selected) opt.selected = true;
				selectEl.appendChild(opt);
			});
		}

		function applyConnectionRows(saved) {
			// Show a chat row when the app is CONNECTED, or when the saved
			// schedule already names it (honesty about stored state outranks
			// tidiness — a saved-but-disconnected destination must stay visible
			// so the user can untick it).
			var dests = saved || [];
			[['slack', 'taskDestSlackRow', 'scheduleDestSlackRow'],
			 ['teams', 'taskDestTeamsRow', 'scheduleDestTeamsRow']].forEach(function (pair) {
				var app = pair[0];
				var show = connectedApps === null || connectedApps[app] || dests.indexOf(app) !== -1;
				[pair[1], pair[2]].forEach(function (id) {
					var row = document.getElementById(id);
					if (row) row.style.display = show ? '' : 'none';
				});
			});
		}

		async function loadConnections() {
			// Best-effort: a failed read leaves connectedApps null and every row
			// visible — unknown must widen the form, never narrow it.
			try {
				var data = await apiRequest('/user/integrations');
				var apps = (data && Array.isArray(data.apps)) ? data.apps : [];
				connectedApps = { slack: false, teams: false };
				apps.forEach(function (app) {
					// Lowercased before comparing — DEF's own predicate does the same on this
					// field (routes.py: (category or '').lower()), so casing is not trusted.
					var slug = String(app.category || '').toLowerCase();
					if (app.has_grant && slug === 'slack') connectedApps.slack = true;
					if (app.has_grant && slug.indexOf('teams') !== -1) connectedApps.teams = true;
				});
			} catch (e) {
				connectedApps = null;
			}
		}

		function cadenceTime(s) {
			// The badge time. Built from the stored hour/minute rather than a
			// server string, so it follows the reader's locale.
			var d = new Date();
			d.setHours(s.send_hour_local || 0, s.send_minute_local || 0, 0, 0);
			return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
		}

		function dayName(weekday) {
			// Monday=0..Sunday=6, DEF's convention. 2024-01-01 is a Monday, so
			// the reader's locale names the day without an i18n table here.
			return new Date(Date.UTC(2024, 0, 1 + (weekday || 0))).toLocaleDateString(
				[], { weekday: 'long', timeZone: 'UTC' });
		}

		function scheduleBadgeText(s) {
			// v6.1.0: the badge follows the cadence. Absent (the triage schedule,
			// or a pre-6.1.0 task row) reads as daily - exactly what those are.
			switch (s.cadence) {
				case 'manual': return t('taskManualOnly', 'Runs when you press Run now');
				case 'hourly': return t('taskEveryHour', 'Every hour at ~:%s')
					.replace('%s', pad(s.send_minute_local || 0));
				case 'weekdays': return t('taskWeekdaysAt', 'Weekdays at ~%s').replace('%s', cadenceTime(s));
				case 'weekly': return t('taskWeeklyAt', 'Every %1$s at ~%2$s')
					.replace('%1$s', dayName(s.send_weekday)).replace('%2$s', cadenceTime(s));
				default: return t('taskEveryDay', 'Every day at ~%s').replace('%s', cadenceTime(s));
			}
		}

		function runStatusText(status) {
			// DEF's run status is a machine enum. Rendering it raw put strings
			// like "nothing_to_do" in front of the user; an unknown value still
			// shows, truncated - information, not a reason to hide the run.
			var known = {
				running: t('runStatusRunning', 'Running'),
				succeeded: t('runStatusSucceeded', 'Succeeded'),
				nothing_to_do: t('runStatusNothing', 'Nothing to do'),
				failed: t('runStatusFailed', 'Failed'),
				skipped: t('runStatusSkipped', 'Skipped'),
				expired: t('runStatusExpired', 'Did not finish')
			};
			return known[status] || String(status).slice(0, 40);
		}

		function whenText(at) {
			if (!at) return '';
			var d = new Date(at);
			if (isNaN(d.getTime())) return '';
			return d.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ' ' +
				d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
		}

		function el(tag, cls, text) {
			var n = document.createElement(tag);
			if (cls) n.className = cls;
			// textContent, never innerHTML — task names and instructions are
			// user-authored and run statuses are backend-supplied.
			if (text !== undefined) n.textContent = text;
			return n;
		}

		function iconBtn(glyph, label, onClick) {
			var b = el('button', 'task-card-icon', glyph);
			b.type = 'button';
			b.title = label;
			b.setAttribute('aria-label', label);
			b.addEventListener('click', onClick);
			return b;
		}

		function baseCard(name, desc, schedule, lastRun) {
			var card = el('div', 'task-card');
			var head = el('div', 'task-card-head');
			head.appendChild(el('span', 'task-card-name', name));
			var actions = el('div', 'task-card-actions');
			head.appendChild(actions);
			card.appendChild(head);
			card.appendChild(el('p', 'task-card-desc', desc));
			var meta = el('div', 'task-card-meta');
			meta.appendChild(el('span', 'task-badge' + (schedule.enabled ? '' : ' task-badge-off'),
				schedule.enabled
					? scheduleBadgeText(schedule)
					: t('taskPaused', 'Not scheduled')));
			if (lastRun && lastRun.status) {
				var when = whenText(lastRun.at);
				meta.appendChild(el('span', 'task-last-run',
					t('taskLastRun', 'Last run: %s').replace('%s', runStatusText(lastRun.status)) +
					(when ? ' · ' + when : '')));
			}
			card.appendChild(meta);
			return { card: card, actions: actions };
		}

		function renderTriageCard(schedule) {
			var built = baseCard(
				t('taskTriageName', 'Email Triage'),
				t('taskTriageDesc', 'Reads your mailbox, drafts routine replies, and sends you a digest of what needs attention.'),
				schedule, schedule.last_run
			);
			built.actions.appendChild(iconBtn('✎', t('taskEdit', 'Edit'), function () { openTriageForm(false); }));
			var del = iconBtn('✕', t('taskDelete', 'Remove'), function () { removeTriage(del); });
			built.actions.appendChild(del);
			// Only when the schedule is on: DEF refuses a switched-off one with
			// a 409 (D-S6) - offering a control that can only fail is worse.
			if (schedule.enabled) {
				var run = el('button', 'modal-btn modal-btn-secondary task-card-run', t('taskRunNow', 'Run now'));
				run.type = 'button';
				run.addEventListener('click', function () { runNowTriage(run); });
				built.card.appendChild(run);
			}
			return built.card;
		}

		function renderTaskCard(task) {
			var desc = String(task.instruction || '');
			if (desc.length > 140) desc = desc.slice(0, 140) + '…';
			var built = baseCard(task.name || '', desc, task, task.last_run);
			built.actions.appendChild(iconBtn('✎', t('taskEdit', 'Edit'), function () { openTaskForm(task); }));
			var del = iconBtn('✕', t('taskDelete', 'Remove'), function () { removeTask(del, task); });
			built.actions.appendChild(del);
			if (task.enabled) {
				var run = el('button', 'modal-btn modal-btn-secondary task-card-run', t('taskRunNow', 'Run now'));
				run.type = 'button';
				run.addEventListener('click', function () { runNowTask(run, task); });
				built.card.appendChild(run);
			}
			return built.card;
		}

		function renderGrid() {
			grid.textContent = '';
			var any = false;
			if (current && current.exists) {
				grid.appendChild(renderTriageCard(current));
				any = true;
			}
			tasks.forEach(function (task) {
				grid.appendChild(renderTaskCard(task));
				any = true;
			});
			paneEmpty.style.display = any ? 'none' : '';
		}

		// --- the pane swap (D-S7): Scheduled is a page, chat navigation leaves it

		var paneOpen = false;

		function showPane() {
			paneOpen = true;
			if (messagesEl) messagesEl.style.display = 'none';
			if (composerEl) composerEl.style.display = 'none';
			pane.hidden = false;
			loadAll();
		}

		function hidePane() {
			if (!paneOpen) return;
			paneOpen = false;
			pane.hidden = true;
			if (messagesEl) messagesEl.style.display = '';
			if (composerEl) composerEl.style.display = '';
		}

		async function loadAll() {
			if (loading) return;
			loading = true;
			setStatus(paneStatus, t('scheduleLoading', 'Loading your schedule…'));
			// Connections first: the forms derive their rows from it, and the
			// grid render does not depend on it.
			await loadConnections();
			var failures = [];
			try {
				var data = await apiRequest('/triage-schedule');
				var s = (data && data.schedule) || {};
				// `exists` is STATED by DEF, not inferred - a user who saved the
				// defaults unchanged is byte-identical to one with no row.
				s.last_run = (data && data.last_run) || null;
				s.exists = !!s.exists;
				current = s;
			} catch (e) {
				current = null;
				failures.push((e && e.message) || t('scheduleLoadFailed', 'Could not load your triage schedule.'));
			}
			try {
				var taskData = await apiRequest('/tasks');
				tasks = (taskData && Array.isArray(taskData.tasks)) ? taskData.tasks : [];
			} catch (e2) {
				tasks = [];
				failures.push((e2 && e2.message) || t('tasksLoadFailed', 'Could not load your scheduled tasks.'));
			}
			if (failures.length) {
				// A failed read is UNKNOWN, not empty — but render what IS known,
				// in BOTH directions: a failed triage read must not hide N tasks
				// that loaded fine, and vice versa (round-2 code leg). Only the
				// empty-state claim is suppressed, because "nothing scheduled"
				// cannot be asserted from a partial read.
				renderGrid();
				paneEmpty.style.display = 'none';
				setStatus(paneStatus, failures.join(' '), 'error');
			} else {
				renderGrid();
				setStatus(paneStatus, '');
			}
			loading = false;
		}

		// --- the creator/editor modal

		function openModal() { modal.classList.add('visible'); }
		function closeModal() {
			modal.classList.remove('visible');
			taskView.style.display = 'none';
			triageView.style.display = 'none';
		}

		function applyCadenceRows() {
			// The form follows the frequency: Weekly needs its day; Manual has no
			// send time to show (it never fires on one). Values are kept, only
			// hidden - the payload stays the complete object (full-replace).
			var cadence = taskCadenceEl ? taskCadenceEl.value : 'daily';
			if (taskWeekdayRow) taskWeekdayRow.style.display = cadence === 'weekly' ? '' : 'none';
			if (taskTimeRow) taskTimeRow.style.display = cadence === 'manual' ? 'none' : '';
			if (taskTzRow) taskTzRow.style.display = cadence === 'manual' ? 'none' : '';
			// The toggle stays VISIBLE for Manual (hiding it would trap an
			// already-disabled task), but "on its schedule" would be a false
			// label - and unticking it silently removes Run now (D-S6).
			var enabledLabel = document.getElementById('taskEnabledLabel');
			if (enabledLabel) enabledLabel.textContent = cadence === 'manual'
				? t('taskEnabledManualLabel', 'Task is active - Run now only works while this is on')
				: t('taskEnabledLabel', 'Run this task on its schedule');
			if (taskCadenceHint) {
				var hints = {
					manual: t('taskHintManual', 'This task never runs on a schedule. Use its Run now button whenever you want it.'),
					hourly: t('taskHintHourly', "Runs every hour, at the send time's minutes past the hour."),
					weekdays: t('taskHintWeekdays', 'Runs Monday to Friday at the send time.'),
					weekly: t('taskHintWeekly', 'Runs once a week, on the day you choose.')
				};
				taskCadenceHint.textContent = hints[cadence] || '';
				taskCadenceHint.style.display = hints[cadence] ? '' : 'none';
			}
		}

		function fillTaskForm(task) {
			nameEl.value = task ? (task.name || '') : '';
			instructionEl.value = task ? (task.instruction || '') : '';
			taskEnabledEl.checked = task ? !!task.enabled : true;
			if (taskCadenceEl) taskCadenceEl.value = (task && task.cadence) || 'daily';
			if (taskModelEl) {
				var wantModel = (task && task.model) || '';
				// Keep-options belong to the task they were added FOR - drop any
				// left from a previous edit so a stale id never leaks into the
				// list for a different task or a new one (panel round 1).
				Array.prototype.slice.call(taskModelEl.querySelectorAll('option[data-keep]'))
					.forEach(function (o) { if (o.value !== wantModel) o.remove(); });
				// The task's stored model may not be among the offered options
				// (list still loading, or no longer offered). Keep it VISIBLE as
				// its raw id rather than silently rewriting the task to Default
				// on the next unrelated save (the P3-B ignored-selector lesson);
				// the run itself already falls back safely DEF-side.
				if (wantModel && !Array.prototype.some.call(taskModelEl.options,
						function (o) { return o.value === wantModel; })) {
					var keep = document.createElement('option');
					keep.value = wantModel;
					keep.textContent = wantModel;
					keep.setAttribute('data-keep', '1');
					taskModelEl.appendChild(keep);
				}
				taskModelEl.value = wantModel;
			}
			if (taskWeekdayEl) taskWeekdayEl.value = String((task && task.send_weekday) || 0);
			applyCadenceRows();
			taskTimeEl.value = task
				? pad(task.send_hour_local || 0) + ':' + pad(task.send_minute_local || 0)
				: '07:00';
			fillTimezones(taskTzEl, (task && task.timezone)
				|| (window.StaffAIConfig && StaffAIConfig.siteTimezone) || 'UTC');
			var dests = task ? (task.destinations || ['email']) : ['email'];
			Object.keys(taskDestEls).forEach(function (k) {
				if (taskDestEls[k]) taskDestEls[k].checked = dests.indexOf(k) !== -1;
			});
			applyConnectionRows(dests);
			setStatus(taskStatusEl, '');
		}

		function fillMailboxRow(schedule) {
			// Phase 4a (§5). Rebuilt on every open (the abandoned-edit rule).
			// No connections, or an unreadable list, hides the row — and the
			// SAVE then carries the STORED binding through unchanged, so an
			// unrelated edit can never silently unbind a mailbox.
			if (!mailboxEl || !mailboxRow) return;
			while (mailboxEl.firstChild) mailboxEl.removeChild(mailboxEl.firstChild);
			var bound = (schedule && schedule.connected_account_id) || '';
			(mailConnections || []).forEach(function (c) {
				var o = document.createElement('option');
				o.value = c.id;
				o.textContent = c.address || (c.toolkit + ' — ' + c.id);
				mailboxEl.appendChild(o);
			});
			if (bound && !Array.prototype.some.call(mailboxEl.options,
					function (o) { return o.value === bound; })) {
				// The stored binding is not among the offered connections (list
				// still loading, or the grant ended): keep it VISIBLE as its raw
				// id rather than silently rebinding on save (the P3-C lesson);
				// the run itself refuses an invalid binding honestly DEF-side.
				var keep = document.createElement('option');
				keep.value = bound;
				keep.textContent = bound;
				mailboxEl.appendChild(keep);
			}
			if (mailboxEl.options.length) {
				mailboxEl.value = bound || mailboxEl.options[0].value;
				mailboxRow.style.display = '';
			} else {
				mailboxRow.style.display = 'none';
			}
		}

		function fillTriageForm(schedule) {
			enabledEl.checked = !!schedule.enabled;
			timeEl.value = pad(schedule.send_hour_local || 0) + ':' + pad(schedule.send_minute_local || 0);
			fillTimezones(tzEl, schedule.timezone || 'UTC');
			var dests = schedule.destinations || ['email'];
			Object.keys(destEls).forEach(function (k) {
				if (destEls[k]) destEls[k].checked = dests.indexOf(k) !== -1;
			});
			applyConnectionRows(dests);
			fillMailboxRow(schedule);
			if (mailConnections === null) {
				// First open (or a failed earlier read): fetch the caller's own
				// connections and re-render just the mailbox row when they land.
				apiRequest('/mail-connections')
					.then(function (data) {
						mailConnections = (data && data.connections) || [];
						fillMailboxRow(schedule);
					})
					.catch(function () { /* row stays hidden; the save preserves the binding */ });
			}
			setStatus(statusEl, '');
		}

		function openTaskForm(task) {
			// Re-fill every time (the abandoned-edit rule): the form must never
			// keep what was last typed into it.
			editingTaskId = task ? task.id : null;
			fillTaskForm(task || null);
			// The type selector belongs to CREATION - an existing task's type is
			// a fact, not a choice.
			typeRow.style.display = task ? 'none' : '';
			if (typeEl) typeEl.value = 'freetext';
			if (titleEl) titleEl.textContent = task
				? t('taskFormTitleEdit', 'Edit scheduled task')
				: t('taskFormTitleNew', 'New scheduled task');
			taskView.style.display = '';
			triageView.style.display = 'none';
			if (backBtn) backBtn.style.display = 'none';
			if (saveBtn) { saveBtn.style.display = ''; saveBtn.disabled = false; }
			openModal();
		}

		function openTriageForm(fromCreator) {
			fillTriageForm(current || {});
			if (titleEl) titleEl.textContent = t('scheduleEditTitle', 'Email triage schedule');
			taskView.style.display = 'none';
			triageView.style.display = '';
			// Back only exists on the creator path - it returns to the type
			// choice; editing an existing schedule has nowhere to go back to.
			if (backBtn) backBtn.style.display = fromCreator ? '' : 'none';
			if (saveBtn) { saveBtn.style.display = ''; saveBtn.disabled = false; }
			openModal();
		}

		if (typeEl) typeEl.addEventListener('change', function () {
			if (typeEl.value === 'email_triage') {
				openTriageForm(true);
				typeEl.value = 'freetext';   // the selector resets for next time
			}
		});
		if (taskCadenceEl) taskCadenceEl.addEventListener('change', applyCadenceRows);
		if (backBtn) backBtn.addEventListener('click', function () { openTaskForm(null); });

		async function saveTask() {
			var name = (nameEl.value || '').trim();
			var instruction = (instructionEl.value || '').trim();
			if (!name) { setStatus(taskStatusEl, t('taskNeedName', 'Give the task a name.'), 'error'); return; }
			if (!instruction) { setStatus(taskStatusEl, t('taskNeedInstruction', 'Tell Staff AI what the task should do.'), 'error'); return; }
			var dests = Object.keys(taskDestEls).filter(function (k) { return taskDestEls[k] && taskDestEls[k].checked; });
			if (!dests.length) { setStatus(taskStatusEl, t('scheduleNeedDestination', 'Pick at least one destination for your digest.'), 'error'); return; }
			var parts = (taskTimeEl.value || '07:00').split(':');
			// FULL-REPLACE contract, same as the triage form: always the complete
			// object - a partial body has documented defaults DEF-side.
			var payload = {
				name: name,
				instruction: instruction,
				enabled: !!taskEnabledEl.checked,
				cadence: (taskCadenceEl && taskCadenceEl.value) || 'daily',
				model: (taskModelEl && taskModelEl.value) || '',
				send_weekday: taskWeekdayEl ? (parseInt(taskWeekdayEl.value, 10) || 0) : 0,
				send_hour_local: parseInt(parts[0], 10) || 0,
				send_minute_local: parseInt(parts[1], 10) || 0,
				timezone: taskTzEl.value || 'UTC',
				destinations: dests
			};
			saveBtn.disabled = true;
			setStatus(taskStatusEl, t('scheduleSaving', 'Saving…'));
			try {
				if (editingTaskId) {
					await apiRequest('/tasks/' + encodeURIComponent(editingTaskId),
						{ method: 'PUT', body: JSON.stringify(payload) });
				} else {
					await apiRequest('/tasks', { method: 'POST', body: JSON.stringify(payload) });
				}
				closeModal();
				// AFTER the reload, or loadAll's own "Loading…" clobbers it in the
				// same tick and the user never sees the confirmation (round-2).
				await loadAll();
				setStatus(paneStatus, payload.cadence === 'manual'
					? t('taskSavedManual', 'Saved. Run it any time with its Run now button.')
					: t('taskSaved', 'Saved. Your task follows its schedule from the next send time.'), 'ok');
			} catch (e) {
				setStatus(taskStatusEl, (e && e.message) || t('taskSaveFailed', 'Could not save your task.'), 'error');
			} finally {
				saveBtn.disabled = false;
			}
		}

		async function saveTriage() {
			var parts = (timeEl.value || '07:00').split(':');
			var dests = Object.keys(destEls).filter(function (k) { return destEls[k] && destEls[k].checked; });
			if (!dests.length) {
				setStatus(statusEl, t('scheduleNeedDestination', 'Pick at least one destination for your digest.'), 'error');
				return;
			}
			// FULL-REPLACE contract: always the complete object - a partial body
			// would silently reset the omitted fields DEF-side.
			// The binding (Phase 4a): the picker's value when it rendered; the
			// STORED binding when it did not (no connections listed, or the
			// list was unreadable) - an unrelated edit never silently unbinds.
			var boundAccount = (mailboxRow && mailboxRow.style.display !== 'none' && mailboxEl)
				? mailboxEl.value
				: ((current && current.connected_account_id) || '');
			var payload = {
				enabled: !!enabledEl.checked,
				send_hour_local: parseInt(parts[0], 10) || 0,
				send_minute_local: parseInt(parts[1], 10) || 0,
				timezone: tzEl.value || 'UTC',
				destinations: dests,
				connected_account_id: boundAccount
			};
			saveBtn.disabled = true;
			setStatus(statusEl, t('scheduleSaving', 'Saving…'));
			try {
				var data = await apiRequest('/triage-schedule', { method: 'PUT', body: JSON.stringify(payload) });
				var s = (data && data.schedule) || payload;
				s.exists = true;
				s.last_run = current ? current.last_run : null;
				current = s;
				closeModal();
				renderGrid();
				setStatus(paneStatus, t('scheduleSaved', 'Saved. Your digest follows this schedule from its next send time.'), 'ok');
			} catch (e) {
				setStatus(statusEl, (e && e.message) || t('scheduleSaveFailed', 'Could not save your triage schedule.'), 'error');
			} finally {
				saveBtn.disabled = false;
			}
		}

		function save() {
			// One Save button, routed by the visible form.
			if (taskView.style.display !== 'none') { saveTask(); return; }
			saveTriage();
		}

		async function runNowTriage(btn) {
			// Deliberately does NOT save first: Run now tests the schedule that
			// is actually stored.
			btn.disabled = true;
			setStatus(paneStatus, t('scheduleRunning', 'Asking for a run…'));
			try {
				await apiRequest('/triage-schedule/run-now', { method: 'POST' });
				setStatus(paneStatus, t('scheduleRunQueued', 'Triage will run shortly. Your digest arrives the same way your daily one does.'), 'ok');
			} catch (e) {
				setStatus(paneStatus, (e && e.message) || t('scheduleRunFailed', 'Could not start a triage run.'), 'error');
			} finally {
				btn.disabled = false;
			}
		}

		async function runNowTask(btn, task) {
			btn.disabled = true;
			setStatus(paneStatus, t('scheduleRunning', 'Asking for a run…'));
			try {
				await apiRequest('/tasks/' + encodeURIComponent(task.id) + '/run-now', { method: 'POST' });
				setStatus(paneStatus, t('taskRunQueued', 'The task will run shortly. Its output arrives at the destinations you chose.'), 'ok');
			} catch (e) {
				setStatus(paneStatus, (e && e.message) || t('taskRunFailed', 'Could not start the task. Its schedule is unchanged - try again in a moment.'), 'error');
			} finally {
				btn.disabled = false;
			}
		}

		async function removeTriage(btn) {
			// Retires the SETUP. The mailbox connection is untouched and stays
			// usable by chat; disconnecting that grant is a separate control.
			if (!window.confirm(t('taskDeleteConfirm', 'Remove Email Triage? Your mailbox stays connected.'))) return;
			btn.disabled = true;
			setStatus(paneStatus, t('taskDeleting', 'Removing…'));
			try {
				await apiRequest('/triage-schedule', { method: 'DELETE' });
				current = null;
				renderGrid();
				setStatus(paneStatus, t('taskDeleted', 'Email Triage removed. Nothing is scheduled for you now.'), 'ok');
			} catch (e) {
				btn.disabled = false;
				setStatus(paneStatus, (e && e.message) || t('taskDeleteFailed', 'Could not remove your Email Triage setup.'), 'error');
			}
		}

		async function removeTask(btn, task) {
			if (!window.confirm(t('taskConfirmDeleteNamed', 'Remove "%s"? Its schedule stops now; past run history is kept.')
					.replace('%s', task.name || ''))) return;
			btn.disabled = true;
			setStatus(paneStatus, t('taskDeleting', 'Removing…'));
			try {
				await apiRequest('/tasks/' + encodeURIComponent(task.id), { method: 'DELETE' });
				tasks = tasks.filter(function (item) { return item.id !== task.id; });
				renderGrid();
				setStatus(paneStatus, t('taskDeletedNamed', 'Task removed. Its schedule has stopped.'), 'ok');
			} catch (e) {
				btn.disabled = false;
				setStatus(paneStatus, (e && e.message) || t('taskRemoveFailed', 'Could not remove your task. Nothing has changed - try again in a moment.'), 'error');
			}
		}

		if (navBtn) navBtn.addEventListener('click', showPane);
		if (newTaskBtn) newTaskBtn.addEventListener('click', function () { openTaskForm(null); });
		if (modalClose) modalClose.addEventListener('click', closeModal);
		if (closeBtn) closeBtn.addEventListener('click', closeModal);
		if (saveBtn) saveBtn.addEventListener('click', save);
		modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

		// Chat navigation leaves the page (capture phase, so the chat's own
		// handlers still run untouched).
		var newChat = document.getElementById('newChatBtn');
		if (newChat) newChat.addEventListener('click', hidePane, true);
		var convList = document.getElementById('conversationList');
		if (convList) convList.addEventListener('click', hidePane, true);
	})();

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
