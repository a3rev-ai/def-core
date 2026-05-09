/**
 * Digital Employee Framework - Customer Chat Module
 * Phase 8 Sub-PR B: Full chat engine (lazy-loaded by loader).
 *
 * Behavioral port of shell.js (iframe widget) to native Shadow DOM module.
 * Auth changed from postMessage to direct fetch. All API calls use
 * config.apiBaseUrl. DOM targets shadowRoot instead of document.
 *
 * The loader script calls window.DEFCustomerChat.init(shadowRoot, config)
 * after this script and vendor libs (marked, DOMPurify) are loaded.
 */
(function () {
	'use strict';

	// ─── 1. DEFAULTS + i18n ────────────────────────────────────────

	var DEFAULT_STRINGS = {
		clearChat: 'Clear conversation & start fresh',
		clearConfirmTitle: 'Clear conversation?',
		clearConfirmDesc:
			'This will clear your current conversation. This action cannot be undone.',
		clearConfirmYes: 'Clear & start fresh',
		cancel: 'Cancel',
		typePlaceholder: 'Type your message...',
		greeting: 'Hello! How can I help you today?',
		privacyPolicy: 'Privacy Policy',
		sending: 'Sending...',
		login: 'Log in',
		loginTitle: 'Log in to continue',
		loginSubmit: 'Log in',
		sessionExpired: 'Session expired — please log in again',
		escalate: 'Request Human Support',
		escalateSubmit: 'Send',
		escalateSuccess: 'Your email has been sent.',
		uploadFailed: 'Upload failed. Please try again.',
		fileTooLarge: 'File too large — maximum 10MB',
		fileTypeNotSupported: 'File type not supported',
		offlineTitle: 'Chat is currently unavailable',
		offlineMessage: 'This feature is being set up. Please check back soon.',
		connectionError: 'Unable to connect. Please try again.',
		connectionLost: 'Connection lost. Retrying...',
		rateLimited:
			'Please wait a moment before sending another message',
	};

	var SANITIZE_CONFIG = {
		ALLOWED_TAGS: [
			'p', 'br', 'strong', 'em', 'b', 'i', 'u', 's', 'del',
			'a', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
			'blockquote', 'pre', 'code', 'table', 'thead', 'tbody', 'tr',
			'th', 'td', 'hr', 'img', 'span', 'div', 'sub', 'sup',
		],
		FORBID_TAGS: ['script', 'style', 'iframe', 'form', 'input', 'object', 'embed'],
		FORBID_ATTR: ['style', 'onerror', 'onload', 'onclick', 'onmouseover'],
		ALLOW_DATA_ATTR: false,
		ALLOWED_URI_REGEXP:
			/^(?:(?:https?|mailto|tel):|[^a-z]|[a-z+.-]+(?:[^a-z+.\-:]|$))/i,
	};

	var UPLOAD_CONFIG = {
		maxSizeBytes: 10 * 1024 * 1024, // 10MB
		allowedExtensions: [
			'.pdf', '.docx', '.xlsx', '.csv', '.md', '.txt',
			'.png', '.jpg', '.jpeg', '.gif', '.webp',
		],
		extensionToMime: {
			'.pdf': 'application/pdf',
			'.docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'.xlsx': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'.csv': 'text/csv',
			'.md': 'text/markdown',
			'.txt': 'text/plain',
			'.png': 'image/png',
			'.jpg': 'image/jpeg',
			'.jpeg': 'image/jpeg',
			'.gif': 'image/gif',
			'.webp': 'image/webp',
		},
		maxFilesPerMessage: 3,
	};

	// ─── SSE STREAMING LABELS (Phase 9 PR 2) ──────────────────────

	/**
	 * Allowed endpoints for wp_rest_call UI actions.
	 * Security hardening: only endpoints on this list will be executed.
	 * Add new entries as new tools ship that need browser-side execution.
	 */
	// Endpoints that wp_rest_call tool-outputs are allowed to hit. These
	// are WooCommerce Store API paths (wc/store/*) resolved against the
	// bare /wp-json/ root (config.wpRestRoot). Add entries as new tools
	// ship that need browser-side execution.
	var WP_REST_CALL_ALLOWLIST = [
		'wc/store/v1/cart/add-item',
	];

	var TOOL_STATUS_LABELS = {
		'get_orders':             'Looking up orders...',
		'get_order_detail':       'Getting order details...',
		'get_subscriptions':      'Checking subscriptions...',
		'get_licenses':           'Checking licenses...',
		'get_tickets':            'Looking up tickets...',
		'get_products_list':      'Browsing products...',
		'add_to_cart':            'Adding to cart...',
		'add_to_cart_by_name':    'Adding to cart...',
		'get_cart':               'Checking your cart...',
		'get_user_profile':       'Loading your profile...',
		'handle_file_upload':     'Processing upload...',
		'extract_upload_content': 'Analyzing file...',
		'escalate_to_human':      'Preparing escalation...',
	};

	var TOOL_DONE_LABELS = {
		'get_orders':             'Orders loaded',
		'get_order_detail':       'Order details loaded',
		'get_subscriptions':      'Subscriptions checked',
		'get_licenses':           'Licenses checked',
		'get_tickets':            'Tickets loaded',
		'get_products_list':      'Products loaded',
		'add_to_cart':            'Added to cart',
		'add_to_cart_by_name':    'Added to cart',
		'get_cart':               'Cart loaded',
		'get_user_profile':       'Profile loaded',
		'handle_file_upload':     'Upload processed',
		'extract_upload_content': 'File analyzed',
		'escalate_to_human':      'Escalation ready',
	};

	var SSE_TOOL_PACING_MS = 400;

	var THREAD_KEY = 'a3rev_thread_id';
	var HISTORY_KEY = 'a3rev_threads';
	var USER_KEY = 'def:customer-chat:user';
	var AUTH_STATE_KEY = 'def:auth_state';

	function t(key) {
		return (
			(config && config.strings && config.strings[key]) ||
			DEFAULT_STRINGS[key] ||
			key
		);
	}

	// Defence-in-depth: validate any admin-supplied URL right before
	// assigning to an element's `href`. The PHP sanitiser
	// (sanitize_privacy_url) is the primary line of defence; this
	// guards against future drift in that sanitiser by enforcing the
	// same allowlist client-side. Returns the URL string when safe,
	// or '' to signal "do not assign" (caller falls back to plain text).
	// Allowed: http://, https://, and same-origin paths starting with
	// "/" but not "//" (the latter is protocol-relative → cross-origin).
	function safeLinkHref(url) {
		if (typeof url !== 'string') return '';
		var trimmed = url.trim();
		if (!trimmed) return '';
		if (trimmed.length > 2048) return '';
		if (trimmed.charAt(0) === '/' && trimmed.charAt(1) !== '/') {
			return trimmed;
		}
		if (/^https?:\/\//i.test(trimmed)) return trimmed;
		return '';
	}

	// ─── 2. STATE ──────────────────────────────────────────────────

	var root = null; // shadowRoot reference
	var config = null; // DEFCore config object
	var els = {}; // cached DOM element references

	// Auth state (memory-only, never persisted).
	var contextToken = null;
	var contextPayload = null;
	var refreshTimer = null;
	var refreshPromise = null; // single-flight lock (V1.2)

	// Chat state.
	var threadId = null;
	var isContinuing = false;
	var isComposerDisabled = false;
	var dirtyInput = false;
	var lastSuggestion = null;       // Phase 10.1: last suggestion shown

	// Upload state.
	var stagedFiles = [];
	var uploadEligible = false;
	var fileIdCounter = 0;
	var dragCounter = 0;

	// Thread state.
	var localThreads = [];

	// Escalation state.
	var currentEscalationSubject = '';
	// AbortController for the in-flight escalation POST, if any. Lifted to
	// module scope so closeEscalation() can abort a pending send when the
	// user cancels or closes the overlay mid-request.
	var escalationInflightController = null;

	// Lifecycle.
	var destroyed = false;
	var activeAbortControllers = [];

	// ─── 3. DOM CONSTRUCTION ───────────────────────────────────────

	function showOfflineState() {
		// Replace greeting with offline message and disable composer.
		if (els.greeting) {
			els.greeting.style.display = 'none';
		}
		if (els.welcomeChips) {
			els.welcomeChips.style.display = 'none';
		}
		if (els.welcomeBanner) {
			els.welcomeBanner.style.display = 'none';
		}

		var offline = el('div', 'def-cc-offline');
		var icon = el('div', 'def-cc-offline-icon');
		icon.innerHTML = '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>';
		offline.appendChild(icon);

		var title = el('div', 'def-cc-offline-title');
		title.textContent = t('offlineTitle');
		offline.appendChild(title);

		var msg = el('div', 'def-cc-offline-message');
		msg.textContent = t('offlineMessage');
		offline.appendChild(msg);

		els.messages.appendChild(offline);

		// Disable the composer.
		setComposerDisabled(true);
	}

	function buildChatUI() {
		// Remove loading placeholder.
		var loading = root.querySelector('.def-cc-loading');
		if (loading) {
			loading.remove();
		}

		// Find the panel (created by loader).
		var panel = root.querySelector('.def-cc-panel');
		if (!panel) return;

		// Remove the close button temporarily so we can insert header before it.
		var closeBtn = panel.querySelector('.def-cc-panel-close');

		// ── Header ──
		var header = el('div', 'def-cc-header');

		var identity = el('div', 'def-cc-header-identity');

		// Logo.
		if (config.logoShow && config.logoUrl) {
			var logoWrap = el('span', 'def-cc-header-logo');
			var logoImg = document.createElement('img');
			logoImg.src = config.logoUrl;
			logoImg.alt = config.displayName || '';
			logoImg.style.maxHeight =
				(config.logoMaxHeight || 40) + 'px';
			logoWrap.appendChild(logoImg);
			identity.appendChild(logoWrap);
		}

		// Business name.
		var bizName = el('span', 'def-cc-header-business');
		bizName.textContent = config.displayName || '';
		identity.appendChild(bizName);
		els.headerBusiness = bizName;

		header.appendChild(identity);

		// Header actions (refresh + menu).
		var actions = el('div', 'def-cc-header-actions');

		// Refresh / new-conversation button — one-tap shortcut for the
		// "Clear chat" menu item below. Same handler. Lives left of the
		// menu so it's the most-prominent action other than close.
		var refreshBtn = el('button', 'def-cc-btn def-cc-header-refresh');
		refreshBtn.type = 'button';
		refreshBtn.setAttribute('aria-label', 'New conversation');
		refreshBtn.setAttribute('title', 'New conversation');
		refreshBtn.innerHTML =
			'<svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/></svg>';
		refreshBtn.addEventListener('click', handleClearChat);
		actions.appendChild(refreshBtn);

		var menuWrap = el('div', 'def-cc-menu-wrap');

		var menuBtn = el('button', 'def-cc-btn');
		menuBtn.type = 'button';
		menuBtn.setAttribute('aria-label', 'Menu');
		menuBtn.setAttribute('aria-expanded', 'false');
		menuBtn.innerHTML =
			'<svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>';
		menuBtn.addEventListener('click', toggleMenu);
		els.menuBtn = menuBtn;
		menuWrap.appendChild(menuBtn);

		// Menu dropdown.
		var menu = el('div', 'def-cc-menu');
		menu.setAttribute('role', 'menu');

		// Clear chat.
		var clearItem = menuItem(
			'<svg viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
			t('clearChat'),
			handleClearChat
		);
		menu.appendChild(clearItem);

		// Login.
		var loginItem = menuItem(
			'<svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>',
			t('login'),
			handleLoginMenu
		);
		els.menuLogin = loginItem;
		menu.appendChild(loginItem);

		// Logout.
		var logoutItem = menuItem(
			'<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
			'Log out',
			handleLogoutMenu
		);
		logoutItem.style.display = 'none';
		els.menuLogout = logoutItem;
		menu.appendChild(logoutItem);

		// Escalate.
		var escalateItem = menuItem(
			'<svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
			t('escalate'),
			handleEscalateMenu
		);
		els.menuEscalate = escalateItem;
		menu.appendChild(escalateItem);

		els.menu = menu;
		menuWrap.appendChild(menu);
		actions.appendChild(menuWrap);

		// Move close button into header actions (instead of absolute overlay).
		if (closeBtn) {
			closeBtn.classList.add('def-cc-header-close');
			actions.appendChild(closeBtn);
		}

		header.appendChild(actions);

		// Insert header as first child of panel.
		panel.insertBefore(header, panel.firstChild);

		// ── Messages area ──
		var messages = el('div', 'def-cc-messages');
		messages.setAttribute('role', 'log');
		messages.setAttribute('aria-live', 'polite');
		messages.setAttribute('aria-relevant', 'additions');
		els.messages = messages;
		panel.appendChild(messages);

		// Welcome banner (admin-configurable in Branding tab). Renders at
		// the top of the messages list above the greeting in all three
		// display modes. Shares the welcome-state lifecycle with the
		// greeting — hidden when conversation starts, preserved through
		// clearMessages, restored on clearConversation.
		//
		// Two variants: heroImageUrl (desktop, ~5:1 wide strip) and
		// heroImageMobileUrl (mobile, ~2.7:1 chunkier banner). Bunnings
		// uses two separate images for the two viewport classes — at
		// mobile widths, the desktop strip would shrink to ~80px tall
		// and lose impact. When both are configured, render a <picture>
		// with a viewport media query. When only one is set, that one
		// is used at all widths.
		if (config.heroImageUrl || config.heroImageMobileUrl) {
			var banner = el('div', 'def-cc-welcome-banner');
			var desktopUrl = String(config.heroImageUrl || '');
			var mobileUrl  = String(config.heroImageMobileUrl || '');
			if (desktopUrl && mobileUrl) {
				// Both set: <picture> picks the right one per viewport.
				var picture = document.createElement('picture');
				var srcDesktop = document.createElement('source');
				srcDesktop.media  = '(min-width: 481px)';
				srcDesktop.srcset = desktopUrl;
				picture.appendChild(srcDesktop);
				var bannerImg = document.createElement('img');
				bannerImg.src = mobileUrl; // <img> fallback handles mobile
				bannerImg.alt = '';
				bannerImg.setAttribute('aria-hidden', 'true');
				picture.appendChild(bannerImg);
				banner.appendChild(picture);
			} else {
				// Only one set: use it at all viewports.
				var soloImg = document.createElement('img');
				soloImg.src = desktopUrl || mobileUrl;
				soloImg.alt = '';
				soloImg.setAttribute('aria-hidden', 'true');
				banner.appendChild(soloImg);
			}
			els.welcomeBanner = banner;
			messages.appendChild(banner);
		}

		// Greeting with capabilities.
		var greetingEl = el('div', 'def-cc-message def-cc-message--assistant');
		var greetingIcon = createAssistantIcon();
		greetingEl.appendChild(greetingIcon);
		var greetingContent = el('div', 'def-cc-message-content');

		var bizName = config.displayName || '';
		var userName = config.userFirstName || '';
		var hi = userName ? 'Hi ' + userName + '!' : 'Hi!';
		var intro = document.createElement('strong');
		intro.textContent = bizName
			? hi + ' I\'m your ' + bizName + ' AI Assistant.'
			: hi + ' I\'m your AI Assistant.';
		greetingContent.appendChild(intro);
		greetingContent.appendChild(document.createElement('br'));
		greetingContent.appendChild(document.createElement('br'));

		var helpText = document.createTextNode('Here\'s what I can help you with:');
		greetingContent.appendChild(helpText);

		var ul = document.createElement('ul');
		ul.style.margin = '8px 0';
		ul.style.paddingLeft = '18px';
		var capabilities = [];
		if (config.wooActive) {
			capabilities.push('Answer questions about our products and services');
			capabilities.push('Help you find the right product');
			capabilities.push('Look up your order status');
		} else {
			capabilities.push('Answer questions about our site and services');
		}
		capabilities.push('Connect you with a human if you need extra help');
		for (var i = 0; i < capabilities.length; i++) {
			var li = document.createElement('li');
			li.textContent = capabilities[i];
			ul.appendChild(li);
		}
		greetingContent.appendChild(ul);

		var cta = document.createTextNode('What can I help you with?');
		greetingContent.appendChild(cta);

		greetingEl.appendChild(greetingContent);
		els.greeting = greetingEl;
		messages.appendChild(greetingEl);

		// Suggested-prompt chips (admin-configurable, up to 3). Render
		// below the greeting in the welcome state. Each chip has an
		// optional per-chip intro. With intro set: tap shows the intro
		// as an assistant message + awaits the visitor's reply. Without
		// intro: tap pre-fills the composer with the chip text and
		// submits (the original Bunnings "Buddy" pattern). Empty admin
		// slots are skipped — the row is hidden if no chips configured.
		var chipDefs = [];
		if (config.welcomeChip1) chipDefs.push({
			text:  String(config.welcomeChip1),
			intro: String(config.welcomeChip1Intro || ''),
		});
		if (config.welcomeChip2) chipDefs.push({
			text:  String(config.welcomeChip2),
			intro: String(config.welcomeChip2Intro || ''),
		});
		if (config.welcomeChip3) chipDefs.push({
			text:  String(config.welcomeChip3),
			intro: String(config.welcomeChip3Intro || ''),
		});
		if (chipDefs.length) {
			var chipsRow = el('div', 'def-cc-welcome-chips');
			chipsRow.setAttribute('role', 'group');
			chipsRow.setAttribute('aria-label', 'Suggested prompts');
			for (var ci = 0; ci < chipDefs.length; ci++) {
				(function (chipDef) {
					var chip = el('button', 'def-cc-welcome-chip');
					chip.type = 'button';
					chip.textContent = chipDef.text;
					chip.addEventListener('click', function () {
						if (isComposerDisabled) return;
						var intro = (chipDef.intro || '').trim();
						if (intro) {
							// Hybrid: render the configured intro as an
							// assistant message and focus the composer
							// for the visitor's reply. No network call —
							// the LLM picks up the conversation when the
							// visitor sends their first real message.
							appendMessage('assistant', intro);
							if (els.input) {
								els.input.focus();
							}
							return;
						}
						els.input.value = chipDef.text;
						els.input.classList.remove('def-cc-suggestion-text');
						autoResizeInput();
						updateSendButton();
						handleSubmit({ preventDefault: function () {} });
					});
					chipsRow.appendChild(chip);
				})(chipDefs[ci]);
			}
			els.welcomeChips = chipsRow;
			messages.appendChild(chipsRow);
		}

		// ── Staged attachments area (hidden until files staged) ──
		var staged = el('div', 'def-cc-staged');
		staged.style.display = 'none';
		els.staged = staged;
		panel.appendChild(staged);

		// ── Composer ──
		var composer = el('div', 'def-cc-composer');
		var form = el('form', 'def-cc-composer-form');
		form.addEventListener('submit', handleSubmit);

		// Attach button.
		var attachBtn = el('button', 'def-cc-composer-attach');
		attachBtn.type = 'button';
		attachBtn.setAttribute('aria-label', 'Attach file');
		attachBtn.innerHTML =
			'<svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>';
		attachBtn.style.display = 'none'; // Hidden until upload eligibility checked.
		attachBtn.addEventListener('click', handleAttachClick);
		els.attachBtn = attachBtn;
		form.appendChild(attachBtn);

		// Hidden file input.
		var fileInput = document.createElement('input');
		fileInput.type = 'file';
		fileInput.multiple = true;
		fileInput.style.display = 'none';
		fileInput.accept = UPLOAD_CONFIG.allowedExtensions.join(',');
		fileInput.addEventListener('change', handleFileSelect);
		els.fileInput = fileInput;
		form.appendChild(fileInput);

		// Text input.
		var input = document.createElement('textarea');
		input.className = 'def-cc-composer-input';
		input.placeholder = t('typePlaceholder');
		input.rows = 1;
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				handleSubmit(e);
			}
			// Auto-clear suggestion ghost text on any printable keystroke
			if (els.input.classList.contains('def-cc-suggestion-text') &&
				e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
				els.input.value = '';
				els.input.classList.remove('def-cc-suggestion-text');
				autoResizeInput();
				updateSendButton();
			}
		});
		// Click in input clears suggestion ghost text
		input.addEventListener('click', function () {
			if (els.input.classList.contains('def-cc-suggestion-text')) {
				els.input.value = '';
				els.input.classList.remove('def-cc-suggestion-text');
				autoResizeInput();
				updateSendButton();
			}
		});
		input.addEventListener('input', function () {
			dirtyInput = true;
			if (els.input.classList.contains('def-cc-suggestion-text')) {
				els.input.classList.remove('def-cc-suggestion-text');
			}
			autoResizeInput();
			updateSendButton();
		});
		// Clipboard paste — stage pasted files (images, screenshots).
		input.addEventListener('paste', function (e) {
			if (!uploadEligible) return;
			var items = (e.clipboardData || {}).items;
			if (!items) return;
			var hasFile = false;
			for (var i = 0; i < items.length; i++) {
				if (items[i].kind === 'file') {
					hasFile = true;
					var file = items[i].getAsFile();
					if (file) stageFile(file);
				}
			}
			if (hasFile) e.preventDefault();
		});

		els.input = input;
		form.appendChild(input);

		// Send button.
		var sendBtn = el('button', 'def-cc-composer-send');
		sendBtn.type = 'submit';
		sendBtn.setAttribute('aria-label', 'Send message');
		sendBtn.innerHTML =
			'<svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
		els.sendBtn = sendBtn;
		form.appendChild(sendBtn);

		composer.appendChild(form);

		// Drop overlay for drag & drop file uploads.
		var dropOverlay = el('div', 'def-cc-drop-overlay');
		var dropContent = el('div', 'def-cc-drop-overlay-content');
		dropContent.innerHTML =
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
			+ '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
			+ '<polyline points="7 10 12 15 17 10"/>'
			+ '<line x1="12" y1="15" x2="12" y2="3"/></svg>'
			+ '<span>Drop files here</span>';
		dropOverlay.appendChild(dropContent);
		composer.appendChild(dropOverlay);
		els.dropOverlay = dropOverlay;
		els.composer = composer;

		// Drag & drop event listeners (drag counter pattern for nested elements).
		composer.addEventListener('dragenter', function (e) {
			e.preventDefault();
			if (!uploadEligible || isComposerDisabled) return;
			dragCounter++;
			if (els.dropOverlay) els.dropOverlay.classList.add('visible');
		});
		composer.addEventListener('dragover', function (e) {
			e.preventDefault();
		});
		composer.addEventListener('dragleave', function (e) {
			e.preventDefault();
			dragCounter--;
			if (dragCounter <= 0) {
				dragCounter = 0;
				if (els.dropOverlay) els.dropOverlay.classList.remove('visible');
			}
		});
		composer.addEventListener('drop', function (e) {
			e.preventDefault();
			dragCounter = 0;
			if (els.dropOverlay) els.dropOverlay.classList.remove('visible');
			if (!uploadEligible || isComposerDisabled) return;
			var files = e.dataTransfer && e.dataTransfer.files;
			if (!files) return;
			for (var i = 0; i < files.length; i++) {
				stageFile(files[i]);
			}
		});

		panel.appendChild(composer);

		// Compliance footer. Two-part structure:
		//   1. Notice Text  (config.complianceText)  — the lead sentence
		//   2. Link Label   (config.privacyLinkLabel) — the trailing words
		// The Link Label is the *trailing words* of the sentence ("…agree
		// to our Privacy Policy"), so it has no meaning without the lead
		// text. The footer is gated by two conditions:
		//   - `aiNoticeEnabled` — the admin master toggle ("Show AI
		//      disclosure notice" checkbox); when off, no footer at all.
		//   - `complianceText` non-empty — clearing the lead text hides
		//      the footer too, since the label alone would be a dangling
		//      word with no surrounding context.
		if (config.aiNoticeEnabled && config.complianceText) {
			var footer = el('div', 'def-cc-compliance-footer');
			var footerText = document.createElement('span');
			footerText.className = 'def-cc-compliance-text';
			footerText.textContent = String(config.complianceText);
			footer.appendChild(footerText);
			var linkLabel = String(config.privacyLinkLabel || t('privacyPolicy') || '');
			if (linkLabel) {
				footer.appendChild(document.createTextNode(' '));
				// Footer label renders as a clickable link whenever a
				// safe Legal Link URL is configured, otherwise as plain
				// text. safeLinkHref() returns '' for protocol-relative
				// or unknown-scheme URLs that may have slipped past the
				// PHP sanitiser, falling us through to the plain-text
				// branch instead of attaching a bad href.
				var safeHref = safeLinkHref(config.privacyUrl);
				if (safeHref) {
					var privacyLink = document.createElement('a');
					privacyLink.href = safeHref;
					privacyLink.target = '_blank';
					privacyLink.rel = 'noopener noreferrer';
					privacyLink.textContent = linkLabel;
					footer.appendChild(privacyLink);
				} else {
					var labelSpan = document.createElement('span');
					labelSpan.className = 'def-cc-compliance-link-label';
					labelSpan.textContent = linkLabel;
					footer.appendChild(labelSpan);
				}
			}
			panel.appendChild(footer);
		}

		// ── Overlays (confirm, login, escalation) ──
		buildConfirmOverlay(panel);
		buildLoginOverlay(panel);
		buildEscalationOverlay(panel);

		els.panel = panel;
	}

	function buildConfirmOverlay(panel) {
		var overlay = el('div', 'def-cc-confirm-overlay');
		var dialog = el('div', 'def-cc-confirm-dialog');
		dialog.setAttribute('role', 'alertdialog');

		var title = el('div', 'def-cc-confirm-title');
		title.textContent = t('clearConfirmTitle');
		dialog.appendChild(title);

		var desc = el('div', 'def-cc-confirm-desc');
		desc.textContent = t('clearConfirmDesc');
		dialog.appendChild(desc);

		var actions = el('div', 'def-cc-confirm-actions');

		var cancelBtn = el('button', 'def-cc-confirm-btn def-cc-confirm-btn--cancel');
		cancelBtn.type = 'button';
		cancelBtn.textContent = t('cancel');
		cancelBtn.addEventListener('click', closeConfirm);
		actions.appendChild(cancelBtn);

		var confirmBtn = el('button', 'def-cc-confirm-btn def-cc-confirm-btn--danger');
		confirmBtn.type = 'button';
		confirmBtn.textContent = t('clearConfirmYes');
		confirmBtn.addEventListener('click', confirmClear);
		actions.appendChild(confirmBtn);

		dialog.appendChild(actions);
		overlay.appendChild(dialog);
		els.confirmOverlay = overlay;
		panel.appendChild(overlay);
	}

	function buildLoginOverlay(panel) {
		var overlay = el('div', 'def-cc-login-overlay');

		var container = el('div', 'def-cc-login-container');

		var title = el('div', 'def-cc-login-title');
		title.textContent = t('loginTitle');
		container.appendChild(title);

		// Error message (hidden).
		var error = el('div', 'def-cc-login-error');
		error.style.display = 'none';
		els.loginError = error;
		container.appendChild(error);

		// Form fields.
		var loginForm = el('div', 'def-cc-login-form');

		var userField = el('div', 'def-cc-login-field');
		var userLabel = el('label', 'def-cc-login-label');
		userLabel.textContent = 'Username';
		var userInput = document.createElement('input');
		userInput.type = 'text';
		userInput.className = 'def-cc-login-input';
		userInput.autocomplete = 'username';
		els.loginUsername = userInput;
		userField.appendChild(userLabel);
		userField.appendChild(userInput);
		loginForm.appendChild(userField);

		var passField = el('div', 'def-cc-login-field');
		var passLabel = el('label', 'def-cc-login-label');
		passLabel.textContent = 'Password';
		var passInput = document.createElement('input');
		passInput.type = 'password';
		passInput.className = 'def-cc-login-input';
		passInput.autocomplete = 'current-password';
		els.loginPassword = passInput;
		passField.appendChild(passLabel);
		passField.appendChild(passInput);
		loginForm.appendChild(passField);

		var submitBtn = el('button', 'def-cc-login-submit');
		submitBtn.type = 'button';
		submitBtn.textContent = t('loginSubmit');
		submitBtn.addEventListener('click', submitLogin);
		els.loginSubmitBtn = submitBtn;
		loginForm.appendChild(submitBtn);

		// Forgot password link.
		var links = el('div', 'def-cc-login-links');
		var forgot = document.createElement('a');
		forgot.href =
			(config.siteUrl || '') + '/wp-login.php?action=lostpassword';
		forgot.target = '_blank';
		forgot.rel = 'noopener noreferrer';
		forgot.textContent = 'Forgot password?';
		links.appendChild(forgot);
		loginForm.appendChild(links);

		// Cancel link.
		var cancelLink = el('div', 'def-cc-login-links');
		var cancelA = document.createElement('a');
		cancelA.href = '#';
		cancelA.textContent = t('cancel');
		cancelA.addEventListener('click', function (e) {
			e.preventDefault();
			closeLogin();
		});
		cancelLink.appendChild(cancelA);
		loginForm.appendChild(cancelLink);

		els.loginForm = loginForm;
		container.appendChild(loginForm);

		// Spinner (hidden).
		var spinner = el('div', 'def-cc-login-spinner');
		spinner.style.display = 'none';
		spinner.innerHTML = '<div class="def-cc-login-spinner-ring"></div>';
		els.loginSpinner = spinner;
		container.appendChild(spinner);

		overlay.appendChild(container);

		// Enter to submit.
		overlay.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				submitLogin();
			}
			if (e.key === 'Escape') {
				closeLogin();
			}
		});

		els.loginOverlay = overlay;
		panel.appendChild(overlay);
	}

	function buildEscalationOverlay(panel) {
		var overlay = el('div', 'def-cc-escalation-overlay');
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) closeEscalation();
		});

		var epanel = el('div', 'def-cc-escalation-panel');

		// Header + desc.
		var header = el('div', '');
		var title = el('div', 'def-cc-escalation-title');
		title.textContent = t('escalate');
		header.appendChild(title);
		var desc = el('div', 'def-cc-escalation-desc');
		desc.textContent =
			'Describe your issue below and we\'ll connect you with a human.';
		header.appendChild(desc);
		els.escalationHeader = header;
		epanel.appendChild(header);

		// Form fields.
		var formWrap = el('div', 'def-cc-escalation-form-wrap');

		// Anonymous-only fields.
		var anonFields = el('div', 'def-cc-escalation-anon-fields');

		anonFields.appendChild(
			escalationField('First name', 'text', 'escalationFirstName', true)
		);
		anonFields.appendChild(
			escalationField('Last name', 'text', 'escalationLastName', true)
		);
		anonFields.appendChild(
			escalationField('Email', 'email', 'escalationEmail', true)
		);
		anonFields.appendChild(
			escalationField('Phone', 'tel', 'escalationPhone', false)
		);

		els.escalationAnonFields = anonFields;
		formWrap.appendChild(anonFields);

		// Subject + message (always visible).
		formWrap.appendChild(
			escalationField('Subject', 'text', 'escalationSubject', true)
		);

		var msgField = el('div', 'def-cc-escalation-field');
		var msgLabel = el('label', 'def-cc-escalation-label');
		msgLabel.innerHTML = 'Message <span class="def-cc-required">*</span>';
		var msgInput = document.createElement('textarea');
		msgInput.className = 'def-cc-escalation-textarea';
		msgInput.rows = 4;
		els.escalationMessage = msgInput;
		msgField.appendChild(msgLabel);
		msgField.appendChild(msgInput);
		var msgError = el('div', 'def-cc-escalation-error');
		msgError.style.display = 'none';
		msgField.appendChild(msgError);
		formWrap.appendChild(msgField);

		// Actions.
		var actions = el('div', 'def-cc-escalation-actions');
		var cancelBtn = el('button', 'def-cc-escalation-cancel');
		cancelBtn.type = 'button';
		cancelBtn.textContent = t('cancel');
		cancelBtn.addEventListener('click', closeEscalation);
		actions.appendChild(cancelBtn);

		var submitBtn = el('button', 'def-cc-escalation-submit');
		submitBtn.type = 'button';
		submitBtn.textContent = t('escalateSubmit');
		submitBtn.addEventListener('click', handleEscalationSubmit);
		els.escalationSubmitBtn = submitBtn;
		actions.appendChild(submitBtn);
		formWrap.appendChild(actions);

		els.escalationFormWrap = formWrap;
		epanel.appendChild(formWrap);

		// Spinner state.
		var spinner = el('div', 'def-cc-escalation-spinner');
		spinner.style.display = 'none';
		spinner.innerHTML =
			'<div class="def-cc-escalation-spinner-ring"></div><span>Sending...</span>';
		els.escalationSpinner = spinner;
		epanel.appendChild(spinner);

		// Success state.
		var success = el('div', 'def-cc-escalation-success');
		success.style.display = 'none';
		success.innerHTML =
			'<div class="def-cc-escalation-success-icon">' +
			'<svg viewBox="0 0 24 24" width="28" height="28"><polyline points="20 6 9 17 4 12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
			'</div>' +
			'<div style="font-size:16px;font-weight:600;color:var(--def-cc-text);">' +
			t('escalateSuccess') +
			'</div>' +
			'<button type="button" class="def-cc-escalation-cancel" style="margin-top:8px;">Close</button>';
		success
			.querySelector('.def-cc-escalation-cancel')
			.addEventListener('click', closeEscalation);
		els.escalationSuccess = success;
		epanel.appendChild(success);

		// Error state.
		var errorState = el('div', 'def-cc-escalation-error-state');
		errorState.style.display = 'none';
		errorState.innerHTML =
			'<div class="def-cc-escalation-error-icon">' +
			'<svg viewBox="0 0 24 24" width="28" height="28"><line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/><line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>' +
			'</div>' +
			'<div style="font-size:16px;font-weight:600;color:var(--def-cc-text);">Failed to send</div>' +
			'<div style="display:flex;gap:8px;margin-top:8px;">' +
			'<button type="button" class="def-cc-escalation-submit" data-action="retry">Try again</button>' +
			'<button type="button" class="def-cc-escalation-cancel" data-action="close">Close</button>' +
			'</div>';
		errorState
			.querySelector('[data-action="retry"]')
			.addEventListener('click', function () {
				showEscalationState('form');
			});
		errorState
			.querySelector('[data-action="close"]')
			.addEventListener('click', closeEscalation);
		els.escalationError = errorState;
		epanel.appendChild(errorState);

		overlay.appendChild(epanel);
		els.escalationOverlay = overlay;
		panel.appendChild(overlay);
	}

	// ─── 4. AUTH (JWT-only, direct fetch) ──────────────────────────

	function fetchContextToken() {
		if (!config.restUrl) return Promise.resolve(null); // Offline — no silent downgrade.

		var controller = new AbortController();
		trackAbort(controller);

		return fetch(config.restUrl, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce || '',
			},
			signal: controller.signal,
		})
			.then(function (res) {
				untrackAbort(controller);
				if (!res.ok) return null;
				return res.json().then(function (data) {
					return (data && data.token) ? data.token : null;
				});
			})
			.catch(function () {
				untrackAbort(controller);
				return null; // Network error → null (don't silently downgrade to anonymous).
			});
	}

	function scheduleTokenRefresh(expiresAt) {
		if (refreshTimer) {
			clearTimeout(refreshTimer);
			refreshTimer = null;
		}
		if (!expiresAt) return;

		var msUntilExpiry = expiresAt * 1000 - Date.now();
		var refreshIn = msUntilExpiry - 60000; // 60s before expiry.
		if (refreshIn < 5000) refreshIn = 5000; // Minimum 5s.

		refreshTimer = setTimeout(function () {
			refreshTimer = null;
			getValidToken();
		}, refreshIn);
	}

	/**
	 * Single-flight token refresh (V1.2).
	 * Multiple callers get the same promise — prevents race conditions.
	 */
	function getValidToken() {
		// Token still valid — return it immediately.
		if (contextToken && contextPayload && contextPayload.exp) {
			var remaining = contextPayload.exp * 1000 - Date.now();
			if (remaining > 30000) {
				return Promise.resolve(contextToken);
			}
		}

		// Already refreshing — return the existing promise.
		if (refreshPromise) {
			return refreshPromise;
		}

		refreshPromise = fetchContextToken()
			.then(function (token) {
				refreshPromise = null;
				if (token) {
					setContextToken(token);
					return token;
				}
				// Token fetch failed — if we had a token, keep it until it expires.
				return contextToken;
			})
			.catch(function () {
				refreshPromise = null;
				return contextToken;
			});

		return refreshPromise;
	}

	function decodeJwtPayload(token) {
		try {
			var parts = token.split('.');
			if (parts.length !== 3) return null;
			var payload = parts[1]
				.replace(/-/g, '+')
				.replace(/_/g, '/');
			return JSON.parse(atob(payload));
		} catch (e) {
			return null;
		}
	}

	function setContextToken(token) {
		if (!token) {
			contextToken = null;
			contextPayload = null;
			return;
		}
		contextToken = token;
		contextPayload = decodeJwtPayload(token);
		if (contextPayload && contextPayload.exp) {
			scheduleTokenRefresh(contextPayload.exp);
		}
		updateMenuState();
		updateGreeting();
	}

	function isAuthenticated() {
		return !!contextToken && !!contextPayload;
	}

	function onAuthChange() {
		updateMenuState();
		checkUploadEligibility();
	}

	// ─── 5. LOGIN OVERLAY ──────────────────────────────────────────

	function showLogin() {
		if (!els.loginOverlay) return;
		els.loginError.style.display = 'none';
		els.loginError.textContent = '';
		els.loginUsername.value = '';
		els.loginPassword.value = '';
		els.loginForm.style.display = '';
		els.loginSpinner.style.display = 'none';
		els.loginSubmitBtn.disabled = false;
		els.loginOverlay.classList.add('def-cc-login-overlay--open');
		setTimeout(function () {
			els.loginUsername.focus();
		}, 100);
	}

	function closeLogin() {
		if (!els.loginOverlay) return;
		els.loginOverlay.classList.remove('def-cc-login-overlay--open');
	}

	function showLoginError(msg) {
		els.loginError.textContent = msg;
		els.loginError.style.display = '';
	}

	function submitLogin() {
		var username = (els.loginUsername.value || '').trim();
		var password = els.loginPassword.value || '';

		if (!username || !password) {
			showLoginError('Please enter both username and password.');
			return;
		}

		els.loginError.style.display = 'none';
		els.loginForm.style.display = 'none';
		els.loginSpinner.style.display = '';
		els.loginSubmitBtn.disabled = true;

		// Capture pre-login state for claim flow.
		var currentThreadId = threadId;

		// Invalidate prior auth state.
		if (refreshTimer) { clearTimeout(refreshTimer); refreshTimer = null; }
		refreshPromise = null;

		// Direct AJAX to WordPress login endpoint.
		var formData = new FormData();
		formData.append('action', 'def_core_inline_login');
		formData.append('log', username);
		formData.append('pwd', password);
		formData.append('_wpnonce', config.nonce || '');

		var controller = new AbortController();
		trackAbort(controller);

		fetch(config.loginUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
			signal: controller.signal,
		})
			.then(function (res) {
				untrackAbort(controller);
				return res.json();
			})
			.then(function (result) {
				if (result.success && result.data && result.data.token) {
					// Login success — set authenticated token.
					setContextToken(result.data.token);
					try { localStorage.setItem(AUTH_STATE_KEY, 'logged_in'); } catch (e) {}
					onAuthChange();

					// Schedule authenticated refresh BEFORE claim (V1.3).
					scheduleTokenRefresh(contextPayload && contextPayload.exp);

					// Claim thread if we had one from anonymous session.
					if (currentThreadId) {
						claimThread(currentThreadId);
					}

					// Load server history.
					loadServerThreads();

					closeLogin();

					// Show welcome message.
					var name =
						(contextPayload && contextPayload.first_name) ||
						(contextPayload && contextPayload.display_name) ||
						'';
					if (name) {
						appendMessage(
							'assistant',
							'Welcome back, ' + escapeHtml(name) + '! How can I help you?'
						);
					}
				} else {
					// Login failed.
					els.loginForm.style.display = '';
					els.loginSpinner.style.display = 'none';
					els.loginSubmitBtn.disabled = false;
					showLoginError(
						(result.data && result.data.message) ||
							'Login failed — please check your details and try again.'
					);
				}
			})
			.catch(function () {
				untrackAbort(controller);
				els.loginForm.style.display = '';
				els.loginSpinner.style.display = 'none';
				els.loginSubmitBtn.disabled = false;
				showLoginError(t('connectionError'));
			});
	}

	// ─── 5B. SSE STREAMING HELPERS (Phase 9 PR 2) ─────────────────

	/**
	 * Parse an SSE text buffer into JSON events.
	 * Handles comments (: keep-alive), multi-line data: payloads,
	 * and partial chunk boundaries per SSE spec.
	 */
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
				try { events.push(JSON.parse(payload)); } catch (e) { /* malformed */ }
			}
		}

		return { parsed: events, remaining: remaining };
	}

	/**
	 * Shared error handler for both sync and streaming paths.
	 */
	function handleChatError(err, thinkingEl) {
		if (err && err.name === 'AbortError') return;
		if (thinkingEl) hideThinking(thinkingEl);
		var msg = t('connectionError');
		if (err && err.status === 429) {
			msg = t('rateLimited');
		}
		appendMessage('assistant', msg);
		setComposerDisabled(false);
	}

	/**
	 * Render a tool status line with spinner during streaming.
	 */
	function renderToolStatusForStream(toolName) {
		var label = TOOL_STATUS_LABELS[toolName] || 'Processing...';
		var div = el('div', 'cc-tool-status');
		div.innerHTML = '<span class="cc-spinner"></span><span class="cc-tool-label">'
			+ escapeHtml(label) + '</span>';
		els.messages.appendChild(div);
		scrollToBottom();
		return div;
	}

	/**
	 * Statuses the backend may emit on a tool_done event that all mean
	 * "this completed successfully". Centralised here so a new backend
	 * framework using different success vocab doesn't require a per-tool
	 * patch upstream. V2 sub-agents emit "completed"; legacy tools emit
	 * "success"; file extractor previously emitted "completed" which the
	 * widget rendered as red ✗ "File analyzed (failed)" — the bug class
	 * DEF PR #202 fix #3 patched per-tool. This kills it for any tool.
	 */
	var SUCCESS_TOOL_STATUSES = ['success', 'completed', 'ok', 'done'];

	/**
	 * Mark a tool status line as complete (spinner → checkmark or ✗ on failure).
	 */
	function completeToolStatus(statusEl, toolName, status) {
		if (!statusEl) return;
		// Status is treated as success when it appears in SUCCESS_TOOL_STATUSES,
		// OR when it's empty/missing (legacy backwards-compat: some older code
		// paths omit the status field entirely on success). Centralising this
		// list at the widget avoids per-tool patches in the backend whenever
		// a new framework uses different success vocab — V2 sub-agents emit
		// "completed", legacy tools emit "success", file extractor emitted
		// "completed" causing red ✗ "File analyzed (failed)" bug class.
		var statusStr = (typeof status === 'string') ? status.toLowerCase() : '';
		var isSuccess = !statusStr || SUCCESS_TOOL_STATUSES.indexOf(statusStr) !== -1;
		var failed = !isSuccess;
		var icon = failed ? '\u2717' : '\u2713';
		var label = failed
			? (TOOL_DONE_LABELS[toolName] || 'Done') + ' (failed)'
			: (TOOL_DONE_LABELS[toolName] || 'Done');
		statusEl.innerHTML = '<span class="cc-checkmark">' + icon + '</span><span class="cc-tool-label">'
			+ escapeHtml(label) + '</span>';
		statusEl.className = failed ? 'cc-tool-status cc-tool-failed' : 'cc-tool-status cc-tool-done';
	}

	/**
	 * Shape normalisers per tool. Maps the raw WP REST response body into
	 * the same shape the synchronous version of the tool would return, so
	 * the LLM sees a consistent observation format across sync and async
	 * paths. DEF-AGENTIC-LOOP-CLOSURE-V1.2 §4.4.
	 */
	var SHAPE_NORMALISERS = {
		// Flatten the WC Store API cart/add-item response so the LLM sees
		// consistent grounding across sync/async tool paths.
		// Response shape: body.items[], body.items_count,
		// body.totals.{total_price, total_items, currency_code, currency_minor_unit}.
		//
		// `dispatchedId` is the variation_id or product_id we sent in the
		// add-item request. We use it to identify the just-added line in
		// the response — taking the last array entry would be wrong when
		// the same product is re-added (Store API bumps quantity in place,
		// so a later A→B→A-again sequence leaves items=[A,B] with A as
		// "the just-added" but B as the array tail).
		add_to_cart: function (body, dispatchedId) {
			if (!body || typeof body !== 'object') return null;
			var items     = Array.isArray(body.items) ? body.items : [];
			// Match by dispatched id; fall back to last item if no match
			// (e.g. unknown id-canonicalisation by WC, or dispatch metadata
			// missing).
			var dispatchedItem = null;
			if (dispatchedId) {
				for (var i = items.length - 1; i >= 0; i--) {
					if (items[i] && items[i].id === dispatchedId) {
						dispatchedItem = items[i];
						break;
					}
				}
			}
			var item      = dispatchedItem || (items.length ? items[items.length - 1] : null);
			var totals    = (body.totals && typeof body.totals === 'object') ? body.totals : {};
			var minorUnit = (typeof totals.currency_minor_unit === 'number') ? totals.currency_minor_unit : 2;
			function fromMinor(v) {
				if (v == null || v === '') return null;
				var n = parseInt(v, 10);
				if (isNaN(n)) return null;
				return (n / Math.pow(10, minorUnit)).toFixed(minorUnit);
			}
			return {
				message:        item && item.name ? ('"' + item.name + '" has been added to your cart.') : null,
				cart_item_key:  item ? (item.key || null) : null,
				product_id:     item ? (item.id || null) : null,
				product_name:   item ? (item.name || null) : null,
				cart_total:     fromMinor(totals.total_price),
				cart_subtotal:  fromMinor(totals.total_items),
				cart_count:     (typeof body.items_count === 'number') ? body.items_count : null,
				currency:       totals.currency_code || body.currency_code || null,
			};
		},
	};

	function normaliseToolResultShape(toolName, wpResponseBody, dispatchedId) {
		var fn = SHAPE_NORMALISERS[toolName];
		return fn ? fn(wpResponseBody, dispatchedId) : wpResponseBody;
	}

	/**
	 * POST the result of an async client-executed tool back to DEF, via the
	 * def-core BFF proxy. Closes the Reason → Act → Observe loop. Primary
	 * delivery uses fetch(keepalive: true) — survives page unload AND
	 * supports custom headers (X-WP-Nonce). sendBeacon is a fallback for
	 * anonymous cookie-auth paths where no custom headers are required.
	 */
	function postToolResultConfirm(payload) {
		if (!payload || !payload.tool_call_id || !payload.thread_id) return;
		var url   = config.wpRestUrl + 'tool-result-confirm';
		var body  = JSON.stringify(payload);
		var nonce = config.wpRestNonce || '';
		try {
			fetch(url, {
				method:      'POST',
				keepalive:   true,
				credentials: 'same-origin',
				headers:     {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   nonce,
				},
				body: body,
			}).catch(function () { /* best-effort; loop tolerates misses */ });
			return;
		} catch (_) { /* fall through to sendBeacon */ }
		if (navigator.sendBeacon) {
			navigator.sendBeacon(
				url,
				new Blob([body], { type: 'application/json' })
			);
		}
	}

	// Serialise wp_rest_call dispatches. The LLM may emit several
	// add_to_cart actions in a single turn; if we fired them in parallel,
	// each fetch would race against the same empty-cookie state, the server
	// would create a separate WC session per call, and the browser would
	// keep only the last Set-Cookie — leaving the cart populated only with
	// items from one of the sessions. Chaining ensures call N+1 starts
	// AFTER call N's response (and its cookie sync) has settled.
	var wpRestCallChain = Promise.resolve();

	// Tool ticks deferred until a wp_rest_call confirms the real outcome.
	// SSE 'tool_done' fires the moment DEF finishes server-side dispatch,
	// which for async tools (those that emit a wp_rest_call output —
	// add_to_cart / add_to_cart_by_name today) is BEFORE the browser has
	// executed the WC call. Painting the tick at tool_done time would lie
	// about a possible 4xx that arrives later (Sorin C1 / Doc 5 Phase 3).
	// When DEF sets pending_async=true on tool_done (post DEF #223), we
	// stash the tick element here and finalise it from executeWpRestCall
	// once the WC fetch resolves with the real HTTP status. Two-key lookup:
	// by tool_call_id (precise — same id flows through the wp_rest_call
	// action) and by tool_name FIFO (fallback, for the V1.2 spawn case
	// where the wp_rest_call's tool_call_id is rewritten to the outer
	// spawn id while the inner tick used the inner id). Module scope
	// because executeWpRestCall is also module-scope.
	var pendingAsyncTicks = {};       // tool_call_id -> entry
	var pendingAsyncTickQueues = {};  // tool_name -> FIFO entry array
	// entry shape: { el, name, tool_call_id, status }
	// `status` preserves the original SSE tool_done status as a fallback
	// for the safety-net flush at end-of-turn.

	function deferAsyncTick(el, name, toolCallId, status) {
		if (!el) return;
		var entry = { el: el, name: name, tool_call_id: toolCallId || null, status: status };
		if (toolCallId) pendingAsyncTicks[toolCallId] = entry;
		if (!pendingAsyncTickQueues[name]) pendingAsyncTickQueues[name] = [];
		pendingAsyncTickQueues[name].push(entry);
	}

	function takeAsyncTickEntry(name, toolCallId) {
		// Precise lookup first (DEF #223+: wp_rest_call carries tool_call_id
		// matching the tool_done event in the non-spawn path).
		if (toolCallId && pendingAsyncTicks[toolCallId]) {
			var entry = pendingAsyncTicks[toolCallId];
			delete pendingAsyncTicks[toolCallId];
			var byName = pendingAsyncTickQueues[entry.name] || [];
			var idx = byName.indexOf(entry);
			if (idx >= 0) byName.splice(idx, 1);
			return entry;
		}
		// Fallback: oldest-by-name (V1.2 spawn case rewrites wp_rest_call
		// tool_call_id to the outer spawn id while tool_done used the inner
		// id, so id lookup misses; also covers older DEF without tool_call_id
		// on the SSE event at all).
		var queue = pendingAsyncTickQueues[name];
		if (queue && queue.length) {
			var fifoEntry = queue.shift();
			if (fifoEntry.tool_call_id) delete pendingAsyncTicks[fifoEntry.tool_call_id];
			return fifoEntry;
		}
		return null;
	}

	function finaliseAsyncTick(name, toolCallId, ok) {
		var entry = takeAsyncTickEntry(name, toolCallId);
		if (!entry) return;
		completeToolStatus(entry.el, entry.name, ok ? 'success' : 'failed');
	}

	function flushUnmatchedAsyncTicks(dispatchedNamesCount) {
		// After processChatResponseMeta dispatches all wp_rest_calls, any
		// deferred ticks beyond the dispatched count had no matching action
		// and would otherwise hang as spinners forever. Finalise them with
		// their original SSE status. Should be a no-op in normal flow —
		// guards against future drift between pending_async emission and
		// wp_rest_call output emission inside DEF.
		for (var name in pendingAsyncTickQueues) {
			if (!Object.prototype.hasOwnProperty.call(pendingAsyncTickQueues, name)) continue;
			var queue = pendingAsyncTickQueues[name];
			var expected = (dispatchedNamesCount && dispatchedNamesCount[name]) || 0;
			while (queue.length > expected) {
				var entry = queue.shift();
				if (entry.tool_call_id) delete pendingAsyncTicks[entry.tool_call_id];
				completeToolStatus(entry.el, entry.name, entry.status);
			}
		}
	}

	// Cart-Token returned by WooCommerce Store API. Echoed back as the
	// Cart-Token request header on every Store API call so the server
	// loads the correct guest cart even when the WC session cookie was
	// stripped by an edge proxy or never made it to the browser. This is
	// the same mechanism the official WC Cart Block uses. Persisted to
	// localStorage so the cart survives page reloads.
	//
	// Cross-tab note: if two tabs share def:wc_cart_token they will race
	// on the rotated header value, but Store API tolerates a stale
	// Cart-Token (server reissues a fresh one in the response), so the
	// race is benign.
	var wcCartToken = '';
	try {
		wcCartToken = localStorage.getItem('def:wc_cart_token') || '';
	} catch (e) { /* localStorage may be blocked */ }

	function rememberCartToken(token) {
		if (!token || token === wcCartToken) return;
		wcCartToken = token;
		try {
			localStorage.setItem('def:wc_cart_token', token);
		} catch (e) { /* best-effort */ }
	}

	// Latest Store API nonce. Store API requires a `Nonce` request header
	// on EVERY write (including the first), using its own action name
	// 'wc_store_api' — separate from WP's wp_rest_nonce. Starts empty
	// here because `config` hasn't been assigned yet at module-parse
	// time; falls back to the bootstrap value (config.wcStoreApiNonce)
	// inside executeWpRestCall. Rotates on each response (server returns
	// a fresh nonce via the `Nonce` response header).
	var wcStoreApiNonce = '';

	/**
	 * Execute a WordPress REST call on behalf of a DEF tool (browser-side).
	 * The browser has cookies/nonce that DEF cannot access in BFF architecture.
	 * After the call resolves, posts the authoritative result back to DEF so
	 * the next turn's LLM observation reflects reality, not pre-execution
	 * guess. DEF-AGENTIC-LOOP-CLOSURE-V1.2.
	 */
	function handleWpRestCall(action) {
		if (!action.endpoint || WP_REST_CALL_ALLOWLIST.indexOf(action.endpoint) === -1) {
			return;
		}
		wpRestCallChain = wpRestCallChain.then(function () {
			return executeWpRestCall(action);
		});
	}

	function executeWpRestCall(action) {
		// All wp_rest_call actions currently target WooCommerce Store API
		// (wc/store/v1/*), which lives outside the DEF namespace — use
		// the bare /wp-json/ root. If wpRestRoot is missing from config
		// (PHP/JS version skew), fail loud instead of silently building a
		// 404 URL by falling back to the namespaced wpRestUrl.
		if (!config.wpRestRoot) {
			console.error('[def-core] wpRestRoot missing from config — cart calls cannot dispatch (PHP/JS version skew?).');
			finaliseAsyncTick(action.tool_name, action.tool_call_id, false);
			showToast(action.error_message || 'Action failed', 'error');
			return;
		}
		var url = config.wpRestRoot + action.endpoint;
		var headers = { 'Content-Type': 'application/json' };

		// Store API session: Cart-Token identifies the visitor's cart
		// across requests (server issues it via response header, we echo
		// back). Survives even when wp_woocommerce_session cookies are
		// stripped by edge proxies on /wp-json/*.
		if (wcCartToken) {
			headers['Cart-Token'] = wcCartToken;
		}
		// Store API writes require a `Nonce` header on every call —
		// action name `wc_store_api`, separate from wp_rest_nonce. Use
		// the latest server-rotated value if we have one; otherwise fall
		// back to the bootstrap nonce PHP minted at page render.
		headers['Nonce'] = wcStoreApiNonce || (config && config.wcStoreApiNonce) || '';

		var options = {
			method: action.method || 'POST',
			credentials: 'same-origin',
			headers: headers,
		};
		if (action.body) {
			options.body = JSON.stringify(action.body);
		}

		return fetch(url, options)
			.then(function (resp) {
				var httpStatus = resp.status;
				// Capture rotated Store API headers before consuming body.
				var token = resp.headers.get('Cart-Token');
				if (token) rememberCartToken(token);
				var nonce = resp.headers.get('Nonce');
				if (nonce) wcStoreApiNonce = nonce;

				return resp.json().then(function (body) {
					// Store API error responses have a WP_Error shape:
					// { code: 'woocommerce_rest_*', message, data: { status } }.
					var ok = resp.ok && body && !body.code;
					// Finalise the deferred tool tick now that we know the
					// real outcome — green ✓ when WC accepted the call, red
					// ✗ when it returned 4xx. No-op when the tick wasn't
					// deferred (older DEF without pending_async, or non-async
					// tool that somehow routed through here).
					finaliseAsyncTick(action.tool_name, action.tool_call_id, ok);
					showToast(
						ok ? (action.success_message || 'Done')
						   : (action.error_message   || 'Action failed'),
						ok ? null : 'error'
					);
					// Surface WC's own error wording as a chat bubble when
					// the server-side action failed. The LLM commits its
					// "I'm adding X" narration on the dispatch turn (from
					// pending_confirmation status) — by the time fetch
					// resolves it's too late to revise. Without this, an
					// over-confident success bubble sits next to a failed
					// toast until the user sends another message and the
					// next-turn loop closure runs.
					if (!ok && body && body.message) {
						appendMessage('assistant', body.message);
					}
					postToolResultConfirm({
						thread_id:    threadId,
						tool_call_id: action.tool_call_id,
						tool_name:    action.tool_name,
						status:       ok ? 'completed' : 'failed',
						result: {
							data:        ok ? normaliseToolResultShape(action.tool_name, body, action.body && action.body.id) : null,
							http_status: httpStatus,
							error:       ok ? null : ((body && body.message) || 'Action failed'),
						},
					});
				});
			})
			.catch(function (err) {
				finaliseAsyncTick(action.tool_name, action.tool_call_id, false);
				showToast(action.error_message || 'Action failed', 'error');
				postToolResultConfirm({
					thread_id:    threadId,
					tool_call_id: action.tool_call_id,
					tool_name:    action.tool_name,
					status:       'failed',
					result: {
						data:        null,
						http_status: 0,
						error:       String(err && err.message || err || 'Network error'),
					},
				});
			});
	}

	/**
	 * Show a brief toast notification inside the chat widget.
	 */
	function showToast(message, type) {
		if (!els.messages) return;
		var toast = el('div', 'cc-toast' + (type === 'error' ? ' cc-toast-error' : ''));
		toast.textContent = message;
		els.messages.appendChild(toast);
		scrollToBottom();
		setTimeout(function () {
			toast.classList.add('cc-toast-fade');
			setTimeout(function () { toast.remove(); }, 400);
		}, 4000);
	}

	/**
	 * Update the thinking indicator with step label text.
	 */
	function updateTypingLabel(text) {
		if (!els.messages) return;
		var indicator = els.messages.querySelector('.def-cc-message--thinking');
		if (indicator) {
			var label = indicator.querySelector('.cc-typing-label');
			if (label) {
				label.textContent = text;
			} else {
				var span = document.createElement('span');
				span.className = 'cc-typing-label';
				span.textContent = text;
				var content = indicator.querySelector('.def-cc-message-content');
				if (content) content.appendChild(span);
			}
		}
	}

	// ─── 6. MESSAGE ENGINE ─────────────────────────────────────────

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

	function handleSubmit(e) {
		if (e && e.preventDefault) e.preventDefault();
		if (destroyed || isComposerDisabled) return;

		var text = (els.input.value || '').trim();
		var hasFiles =
			stagedFiles.length > 0 &&
			stagedFiles.some(function (f) {
				return f.status === 'staged';
			});
		var hasFailedFiles = stagedFiles.some(function (f) {
			return f.status === 'failed';
		});

		if (!text && !hasFiles) return;
		if (hasFailedFiles) return; // Block send if any files failed.

		setComposerDisabled(true);
		els.input.value = '';
		autoResizeInput();

		// Upload files first if any.
		var uploadPromise = hasFiles
			? uploadStagedFiles()
			: Promise.resolve([]);

		uploadPromise
			.then(function (fileIds) {
				// Build display message.
				var displayText = text;
				if (fileIds.length > 0 && !text) {
					displayText = 'Please analyze the attached file(s).';
				}

				// Reset per-turn result-cards section counter (frontend cap of 6 per turn).
				if (window.DefResultCards) {
					window.DefResultCards.resetTurn();
				}

				// Render user message.
				appendUserMessage(displayText, fileIds);
				clearStagedFiles();

				// Track first message for escalation subject.
				if (!currentEscalationSubject && text) {
					currentEscalationSubject =
						text.length > 60 ? text.substring(0, 60) + '...' : text;
				}

				// Show thinking indicator.
				var thinkingEl = showThinking();

				// Build request body.
				var msgPayload = {
					role: 'user',
					content: text || 'Please analyze the attached file(s).',
				};
				if (fileIds.length > 0) {
					msgPayload.attachments = fileIds.map(function (id) {
						return { file_id: id };
					});
				}

				var body = {
					messages: [msgPayload],
					thread_id: threadId || null,
					continue_thread: isContinuing,
				};

				// Phase 10.1: Add suggestion feedback signal
				var suggResult = classifySuggestionOutcome(text, lastSuggestion);
				if (suggResult.outcome) {
					body.suggestion_outcome = suggResult.outcome;
					body.similarity_score = suggResult.score;
				}
				lastSuggestion = null;

				// Headers — chat goes through WP proxy (no Authorization needed).
				var headers = {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				};
				// Forward the WC Store API Cart-Token so DEF can read the
				// cart server-side (sync get_cart tool). Token is opaque,
				// scoped to one cart, and stays inside the same trust
				// boundary as cookies do (browser → WP plugin → DEF →
				// Store API). build_proxy_headers() in def-core renames
				// it to X-DEF-WC-Cart-Token before forwarding upstream.
				if (wcCartToken) {
					headers['Cart-Token'] = wcCartToken;
				}

				// Feature detection: stream if ReadableStream supported
				if (typeof ReadableStream !== 'undefined') {
					sendMessageStreaming(text, body, headers, thinkingEl);
				} else {
					sendMessageSync(text, body, headers, thinkingEl);
				}
			})
			.catch(function (err) {
				handleChatError(err, root ? root.querySelector('.def-cc-message--thinking') : null);
			});
	}

	/**
	 * Send message via sync /api/chat endpoint (existing behavior).
	 */
	function sendMessageSync(text, body, headers, thinkingEl) {
		var controller = new AbortController();
		trackAbort(controller);

		fetch(config.chatStreamUrl, {
			method: 'POST',
			credentials: 'include',
			headers: headers,
			body: JSON.stringify(body),
			signal: controller.signal,
		})
			.then(function (res) {
				untrackAbort(controller);
				if (res.status === 429) {
					hideThinking(thinkingEl);
					appendMessage('assistant', t('rateLimited'));
					setComposerDisabled(false);
					return null;
				}
				return res;
			})
			.then(function (res) {
				if (!res) return;
				return res.json().then(function (data) {
					hideThinking(thinkingEl);
					processChatResponse(data, text);
				});
			})
			.catch(function (err) {
				handleChatError(err, thinkingEl);
			});
	}

	/**
	 * Send message via SSE streaming /api/chat/stream endpoint.
	 */
	function sendMessageStreaming(text, body, headers, thinkingEl) {
		var controller = new AbortController();
		trackAbort(controller);

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

		// V2 (Spec V1.4 §6): per-turn speaker tracking. Shared logic lives in
		// def-persona.js so all three channels (Customer Chat, Staff AI,
		// Setup Assistant) render the divider + thinking-row prefix
		// consistently. The persona controller owns the currentAgent state.
		var persona = window.DefPersona.createController({
			dividerCssClass:        'def-cc-speaker-divider',
			thinkingLabelSelector:  '.cc-tool-label',
			appendDivider: function (div) {
				els.messages.appendChild(div);
				scrollToBottom();
			},
		});

		function drainNextWord() {
			if (displayedLen >= streamBuffer.length) {
				wordDrainTimer = null;
				return;
			}
			var i = displayedLen;
			while (i < streamBuffer.length && /\s/.test(streamBuffer[i])) { i++; }
			while (i < streamBuffer.length && !/\s/.test(streamBuffer[i])) { i++; }
			displayedLen = i;
			// Render as progressive markdown (same pattern as Setup Assistant
			// and Staff AI). Sanitized via DOMPurify inside renderMarkdown().
			streamEl.innerHTML = renderMarkdown(streamBuffer.slice(0, displayedLen));
			scrollToBottom();
			wordDrainTimer = setTimeout(drainNextWord, 30);
		}

		function processEventQueue() {
			if (processing || eventQueue.length === 0) return;
			processing = true;

			var evt = eventQueue.shift();
			var now = Date.now();
			var delay = 0;

			if ((evt.type === 'tool_start' || evt.type === 'tool_done') &&
				now - lastToolTime < SSE_TOOL_PACING_MS) {
				delay = SSE_TOOL_PACING_MS - (now - lastToolTime);
			}

			setTimeout(function () {
				handleSSEEvent(evt);
				if (evt.type === 'tool_start' || evt.type === 'tool_done') {
					lastToolTime = Date.now();
				}
				processing = false;
				if (eventQueue.length > 0) {
					requestAnimationFrame(processEventQueue);
				}
			}, delay);
		}

		function handleSSEEvent(evt) {
			// V2 persona signals (Spec V1.4 §6) — divider + thinking-row
			// prefix on specialist takeover. Shared logic in def-persona.js.
			persona.handleEvent(evt, thinkingStatusEl);

			switch (evt.type) {
				case 'thinking':
					hideThinking(thinkingEl);
					var thinkMsg = (evt.message || '').toString().trim() || 'Thinking\u2026';
					var rowLabel = persona.formatThinkingLabel(thinkMsg);
					if (!thinkingStatusEl) {
						var div = el('div', 'cc-tool-status');
						div.innerHTML = '<span class="cc-spinner"></span><span class="cc-tool-label"></span>';
						div.querySelector('.cc-tool-label').textContent = rowLabel;
						els.messages.appendChild(div);
						scrollToBottom();
						thinkingStatusEl = div;
					} else {
						var ccLabel = thinkingStatusEl.querySelector('.cc-tool-label');
						if (ccLabel) ccLabel.textContent = rowLabel;
					}
					break;
				case 'tool_start':
					toolStatusEls[evt.tool] = renderToolStatusForStream(evt.tool);
					break;
				case 'tool_done':
					var doneEl = toolStatusEls[evt.tool];
					if (evt.pending_async) {
						// Real outcome arrives via the wp_rest_call action's
						// fetch resolution — keep the spinner showing and
						// finalise from executeWpRestCall instead. Falls back
						// to the immediate finalise path below for any older
						// DEF deploy where pending_async is undefined.
						deferAsyncTick(doneEl, evt.tool, evt.tool_call_id, evt.status);
					} else {
						completeToolStatus(doneEl, evt.tool, evt.status);
					}
					break;
				case 'text_delta':
					if (!streamEl) {
						hideThinking(thinkingEl);
							if (thinkingStatusEl) { thinkingStatusEl.remove(); thinkingStatusEl = null; }
						var msgEl = el('div', 'def-cc-message def-cc-message--assistant def-cc-message--streaming');
						var icon = createAssistantIcon();
						msgEl.appendChild(icon);
						var contentEl = el('div', 'def-cc-message-content');
						msgEl.appendChild(contentEl);
						els.messages.appendChild(msgEl);
						streamEl = contentEl;
					}
					streamBuffer += evt.text;
					if (!wordDrainTimer) {
						drainNextWord();
					}
					break;
				case 'done':
					hideThinking(thinkingEl);
					if (thinkingStatusEl) { thinkingStatusEl.remove(); thinkingStatusEl = null; }
					if (wordDrainTimer) clearTimeout(wordDrainTimer);

					// Prefer streamBuffer (what the user actually saw render)
					// over evt.choices[0].message.content. In V2 spawn flows
					// the streamed bubble carries specialist + Concierge text
					// combined, while evt.choices[].message.content is only
					// the Concierge's final wrap-up — using it first wipes
					// the specialist text the user just watched stream
					// (Sorin C2 — Doc 1 #5). Mirror Staff AI v2.9.2 fix
					// (commit 2e16851 / PR #158) — the prior fix's commit
					// message claimed Customer Chat wasn't affected because
					// it doesn't call renderMessages(), but it missed that
					// the same wrong priority drives the in-place finalize.
					var streamedText = (streamBuffer || '').trim();
					var doneContent = '';
					if (evt.choices && evt.choices[0] && evt.choices[0].message) {
						doneContent = (evt.choices[0].message.content || '').trim();
					}
					var finalContent = streamedText || doneContent;

					if (streamEl) {
						streamEl.innerHTML = renderMarkdown(finalContent);
						streamEl.parentNode.classList.remove('def-cc-message--streaming');
					}

					// Mutate evt so downstream metadata processing sees the
					// same text the user saw, not the wrap-up-only content.
					// Persistence (upsertThread) and the non-streamed
					// appendMessage fallback both read from evt.choices —
					// without this override, page-reload rehydration would
					// show only the wrap-up, not the streamed specialist
					// reply.
					if (evt.choices && evt.choices[0] && evt.choices[0].message) {
						evt.choices[0].message.content = finalContent;
					}

					var wasStreamed = !!streamEl;
					streamBuffer = '';
					streamEl = null;
					wordDrainTimer = null;
					displayedLen = 0;
					thinkingStatusEl = null;
					persona.reset();  // V2: next turn starts with Concierge-as-default
					dirtyInput = false;

					processChatResponseMeta(evt, text, wasStreamed);
					break;
				case 'suggestions':
					lastSuggestion = evt.suggestion || null;
					if (!dirtyInput && els.input && evt.suggestion) {
						els.input.value = evt.suggestion;
						els.input.classList.add('def-cc-suggestion-text');
						autoResizeInput();
						updateSendButton();
					}
					break;
				case 'error':
					hideThinking(thinkingEl);
					if (thinkingStatusEl) { thinkingStatusEl.remove(); thinkingStatusEl = null; }
					if (wordDrainTimer) clearTimeout(wordDrainTimer);
					// Leave any already-streamed text visible. The previous
					// behaviour reset streamEl=null and pushed a generic
					// "connection error" via appendMessage, replacing what
					// the user had just watched stream — the same DOM-clobber
					// shape as Staff AI v2.9.2's done-branch bug. Surface the
					// error as a separate bubble below the streamed reply
					// instead.
					if (streamEl) {
						var partial = (streamBuffer || '').trim();
						if (partial) {
							streamEl.innerHTML = renderMarkdown(partial);
						}
						streamEl.parentNode.classList.remove('def-cc-message--streaming');
					}
					streamBuffer = '';
					streamEl = null;
					wordDrainTimer = null;
					displayedLen = 0;
					thinkingStatusEl = null;
					persona.reset();  // V2: symmetry with done-branch reset
					appendMessage('assistant', evt.message || t('connectionError'));
					setComposerDisabled(false);
					break;
			}
		}

		fetch(config.chatStreamUrl, {
			method: 'POST',
			credentials: 'include',
			headers: headers,
			body: JSON.stringify(body),
			signal: controller.signal,
		})
			.then(function (res) {
				untrackAbort(controller);
				return res;
			})
			.then(function (res) {
				if (!res || !res.ok) {
					throw { status: res ? res.status : 0 };
				}
				var reader = res.body.getReader();
				var decoder = new TextDecoder();
				var buffer = '';

				function pump() {
					return reader.read().then(function (result) {
						if (result.done) {
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
						return pump();
					});
				}

				return pump();
			})
			.catch(function (err) {
				handleChatError(err, thinkingEl);
			});
	}

	/**
	 * Process a successful chat response (shared between sync and streaming done).
	 */
	function processChatResponse(data, text) {
		if (!data || data.error) {
			appendMessage('assistant', t('connectionError'));
			setComposerDisabled(false);
			return;
		}

		// Store thread ID.
		if (data.thread_id) {
			threadId = data.thread_id;
			try {
				localStorage.setItem(THREAD_KEY, threadId);
				// Fresh thread started — clear the cleared-session marker so it
				// doesn't accumulate as dead weight in localStorage. The marker
				// only exists to suppress adoption between Clear and the next
				// real message; once we have a thread id, the !threadId guard
				// in adoptMostRecentThreadIfNone() takes over.
				localStorage.removeItem('def:cleared_session');
			} catch (e) {}
			isContinuing = true;
		}

		// Extract reply.
		var reply = '';
		if (data.choices && data.choices[0] && data.choices[0].message) {
			reply = data.choices[0].message.content || '';
		}

		if (reply) {
			appendMessage('assistant', reply);
		}

		// Process tool_outputs. Schema is a tagged-union: each output has
		// EITHER a `type` field (escalation_offer / wp_rest_call) OR a
		// `result_type` field (wp_product / future wp_post / wp_<cpt>).
		// Both fields don't co-exist on a single output.
		if (data.tool_outputs) {
			for (var i = 0; i < data.tool_outputs.length; i++) {
				var output = data.tool_outputs[i];
				if (output.type === 'escalation_offer') {
					showEscalation(output.reason);
				} else if (output.type === 'wp_rest_call') {
					handleWpRestCall(output);
				} else if (output.result_type === 'wp_product') {
					appendResultCardsSection(output);
				}
			}
		}

		// Handle session_cookie from server.
		if (data.session_cookie) {
			try {
				localStorage.setItem('def:session_cookie', data.session_cookie);
			} catch (e) {}
		}

		// Save thread to localStorage.
		upsertThread(
			threadId,
			text || 'Please analyze the attached file(s).',
			reply,
			data.tool_outputs
		);
		setComposerDisabled(false);
	}

	/**
	 * Process streaming done metadata (thread, session, escalation).
	 * If wasStreamed is true, text was already rendered progressively — skip appendMessage.
	 */
	function processChatResponseMeta(data, text, wasStreamed) {
		if (!data || data.error) {
			if (!wasStreamed) {
				appendMessage('assistant', t('connectionError'));
			}
			setComposerDisabled(false);
			return;
		}

		// Store thread ID.
		if (data.thread_id) {
			threadId = data.thread_id;
			try {
				localStorage.setItem(THREAD_KEY, threadId);
				// Fresh thread started — clear the cleared-session marker. See
				// processChatResponse for the rationale.
				localStorage.removeItem('def:cleared_session');
			} catch (e) {}
			isContinuing = true;
		}

		// Extract reply for metadata (upsertThread).
		var reply = '';
		if (data.choices && data.choices[0] && data.choices[0].message) {
			reply = data.choices[0].message.content || '';
		}

		// Only render message bubble if NOT already streamed.
		if (!wasStreamed && reply) {
			appendMessage('assistant', reply);
		}

		// Process tool_outputs. Schema is a tagged-union: each output has
		// EITHER a `type` field (escalation_offer / wp_rest_call) OR a
		// `result_type` field (wp_product / future wp_post / wp_<cpt>).
		// Both fields don't co-exist on a single output.
		var dispatchedAsyncByName = {};
		if (data.tool_outputs) {
			for (var i = 0; i < data.tool_outputs.length; i++) {
				var output = data.tool_outputs[i];
				if (output.type === 'escalation_offer') {
					showEscalation(output.reason);
				} else if (output.type === 'wp_rest_call') {
					handleWpRestCall(output);
					if (output.tool_name) {
						dispatchedAsyncByName[output.tool_name] =
							(dispatchedAsyncByName[output.tool_name] || 0) + 1;
					}
				} else if (output.result_type === 'wp_product') {
					appendResultCardsSection(output);
				}
			}
		}
		// Safety net: if DEF emitted pending_async on tool_done but no
		// matching wp_rest_call action arrived (shouldn't happen — same
		// code path in orchestrator.py — but guards against future drift),
		// finalise the surplus deferred ticks now using their original
		// SSE status. Without this, a missing action would leave the tick
		// as a stuck spinner.
		flushUnmatchedAsyncTicks(dispatchedAsyncByName);

		// Handle session_cookie from server.
		if (data.session_cookie) {
			try {
				localStorage.setItem('def:session_cookie', data.session_cookie);
			} catch (e) {}
		}

		// Save thread to localStorage.
		upsertThread(
			threadId,
			text || 'Please analyze the attached file(s).',
			reply,
			data.tool_outputs
		);
		setComposerDisabled(false);
	}

	function appendUserMessage(text, fileIds) {
		var msgEl = el('div', 'def-cc-message def-cc-message--user');
		var content = el('div', 'def-cc-message-content');
		content.textContent = text;

		// File indicators — chip style matching staged attachments.
		if (fileIds && fileIds.length > 0) {
			var filesDiv = el('div', 'def-cc-message-files');
			var staged = stagedFiles.filter(function (f) {
				return fileIds.indexOf(f.fileId) !== -1;
			});
			for (var i = 0; i < staged.length; i++) {
				var chip = el('span', 'def-cc-message-file');
				// Thumbnail for images.
				if (staged[i].thumbnailUrl) {
					var thumb = document.createElement('img');
					thumb.className = 'def-cc-message-file-thumb';
					thumb.src = staged[i].thumbnailUrl;
					thumb.alt = '';
					chip.appendChild(thumb);
				} else {
					var icon = document.createElement('span');
					icon.textContent = '\u{1F4CE}';
					chip.appendChild(icon);
				}
				var name = el('span', 'def-cc-message-file-name');
				name.textContent = staged[i].file.name;
				chip.appendChild(name);
				filesDiv.appendChild(chip);
			}
			if (filesDiv.children.length > 0) {
				content.appendChild(filesDiv);
			}
		}

		msgEl.appendChild(content);
		els.messages.appendChild(msgEl);
		scrollToBottom();
	}

	// V1.2 Result Cards — Customer Chat integration.
	// Renders the section payload via window.DefResultCards (loaded by the
	// loader before this module) and appends to the message stream.
	// Cart-add buttons go through the existing WC Store API path with
	// Cart-Token + wc_store_api Nonce headers.
	var resultCardsClickWired = false;

	function appendResultCardsSection(payload) {
		if (!window.DefResultCards || !els.messages) return;
		var section = window.DefResultCards.renderSection(payload, { channel: 'customer_chat' });
		if (!section) return;
		els.messages.appendChild(section);
		wireResultCardsClickHandlerOnce();
		scrollToBottom();
	}

	function wireResultCardsClickHandlerOnce() {
		if (resultCardsClickWired || !els.messages) return;
		resultCardsClickWired = true;
		els.messages.addEventListener('click', function (e) {
			var btn = e.target.closest('.def-cc-result-card-add');
			if (!btn || btn.disabled) return;
			var productId = parseInt(btn.getAttribute('data-product-id'), 10);
			if (!productId) return;
			handleResultCardAddToCart(btn, productId);
		});
	}

	function handleResultCardAddToCart(btn, productId) {
		var originalText = btn.textContent;
		btn.disabled = true;
		btn.textContent = 'Adding…';

		var url = (config.wpRestRoot || '') + 'wc/store/v1/cart/add-item';
		var headers = { 'Content-Type': 'application/json' };
		var nonce = wcStoreApiNonce || config.wcStoreApiNonce;
		if (nonce) headers['Nonce'] = nonce;
		try {
			var cartToken = localStorage.getItem('def:wc_cart_token') || '';
			if (cartToken) headers['Cart-Token'] = cartToken;
		} catch (e) {}

		fetch(url, {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			body: JSON.stringify({ id: productId, quantity: 1 })
		})
			.then(function (resp) {
				var rotatedNonce = resp.headers.get('Nonce');
				if (rotatedNonce) wcStoreApiNonce = rotatedNonce;
				var rotatedCartToken = resp.headers.get('Cart-Token');
				if (rotatedCartToken) {
					try { localStorage.setItem('def:wc_cart_token', rotatedCartToken); } catch (e) {}
				}
				if (!resp.ok) {
					return resp.json().then(function (body) {
						throw new Error((body && body.message) || ('HTTP ' + resp.status));
					}, function () {
						throw new Error('HTTP ' + resp.status);
					});
				}
				return resp.json();
			})
			.then(function () {
				btn.textContent = '✓ Added';
				btn.classList.add('def-cc-result-card-add--success');
				setTimeout(function () {
					btn.textContent = originalText;
					btn.classList.remove('def-cc-result-card-add--success');
					btn.disabled = false;
				}, 2000);
			})
			.catch(function (err) {
				console.error('[def-cc] result-card add-to-cart failed:', err);
				btn.textContent = 'Try again';
				btn.classList.add('def-cc-result-card-add--error');
				btn.disabled = false;
				// Surface WC's actual error wording (e.g. "we have 5 in stock and
				// you already have 5 in your cart") into the chat as an assistant
				// bubble so the user understands WHY the add failed — not just
				// that it did. err.message carries the body.message string from
				// the WC Store API JSON error response (extracted in the .then
				// chain above), or 'HTTP {code}' as fallback.
				var wcMessage = (err && err.message) ? err.message : '';
				var bubble = wcMessage
					? wcMessage
					: 'Sorry — couldn\'t add to cart. Please try again.';
				appendMessage('assistant', bubble);
				setTimeout(function () {
					btn.textContent = originalText;
					btn.classList.remove('def-cc-result-card-add--error');
				}, 3000);
			});
	}

	function appendMessage(role, content) {
		var msgEl = el('div', 'def-cc-message def-cc-message--' + role);

		if (role === 'assistant') {
			var icon = createAssistantIcon();
			msgEl.appendChild(icon);
		}

		var contentEl = el('div', 'def-cc-message-content');

		if (role === 'assistant') {
			contentEl.innerHTML = renderMarkdown(content);
		} else {
			contentEl.textContent = content;
		}

		msgEl.appendChild(contentEl);
		els.messages.appendChild(msgEl);

		// Hide greeting once we have real messages.
		if (els.greeting) {
			els.greeting.style.display = 'none';
		}
		if (els.welcomeChips) {
			els.welcomeChips.style.display = 'none';
		}
		if (els.welcomeBanner) {
			els.welcomeBanner.style.display = 'none';
		}

		scrollToBottom();
	}

	function renderMarkdown(text) {
		if (!text) return '';
		text = normalizeMarkdown(text);

		var html = '';
		if (typeof marked !== 'undefined' && marked.parse) {
			html = marked.parse(text, { gfm: true, breaks: true });
		} else {
			// Fallback if marked not loaded.
			html = escapeHtml(text).replace(/\n/g, '<br>');
		}

		// Sanitize with DOMPurify.
		if (typeof DOMPurify !== 'undefined' && DOMPurify.sanitize) {
			html = DOMPurify.sanitize(html, SANITIZE_CONFIG);
		}

		// Add target="_blank" to links.
		html = addTargetBlankToLinks(html);

		return html;
	}

	function normalizeMarkdown(text) {
		return text
			.replace(/\r\n/g, '\n')
			.replace(/\\n/g, '\n')
			.replace(/\n(#{1,6}\s)/g, '\n\n$1')
			.replace(/\n([-*]\s)/g, '\n\n$1')
			.replace(/\n(\d+\.\s)/g, '\n\n$1');
	}

	function addTargetBlankToLinks(html) {
		return html.replace(
			/<a\s/g,
			'<a target="_blank" rel="noopener noreferrer" '
		);
	}

	function showThinking() {
		var msgEl = el(
			'div',
			'def-cc-message def-cc-message--assistant def-cc-message--thinking'
		);
		var icon = createAssistantIcon();
		msgEl.appendChild(icon);

		var content = el('div', 'def-cc-message-content');
		content.innerHTML =
			'<div class="def-cc-thinking-dots">' +
			'<span></span><span></span><span></span>' +
			'</div>';
		msgEl.appendChild(content);

		els.messages.appendChild(msgEl);
		scrollToBottom();
		return msgEl;
	}

	function hideThinking(thinkingEl) {
		if (thinkingEl && thinkingEl.parentNode) {
			thinkingEl.parentNode.removeChild(thinkingEl);
		}
	}

	// ─── 7. ASSISTANT ICON ────────────────────────────────────────

	function createAssistantIcon() {
		var iconEl = el('div', 'def-cc-message-icon');
		iconEl.setAttribute('aria-hidden', 'true');
		// Use the configured logo when available — same logo from Branding
		// tab. Falls back to display-name initials, or "AI" as a last resort.
		// Independent of the header `logoShow` toggle: that toggle controls
		// whether the logo appears in the chat header, not whether it's the
		// AI's identity in message bubbles.
		if (config.logoUrl) {
			iconEl.classList.add('def-cc-message-icon--img');
			var img = document.createElement('img');
			img.src = config.logoUrl;
			img.alt = '';
			img.setAttribute('aria-hidden', 'true');
			iconEl.appendChild(img);
		} else {
			var initials = '';
			var name = (config.displayName || '').trim();
			if (name) {
				var parts = name.split(/\s+/);
				initials = (parts[0][0] || '').toUpperCase();
				if (parts.length > 1 && parts[parts.length - 1][0]) {
					initials += parts[parts.length - 1][0].toUpperCase();
				}
			}
			iconEl.textContent = initials || 'AI';
		}
		return iconEl;
	}

	// ─── 8. ESCALATION FORM ───────────────────────────────────────

	function showEscalation(reason) {
		if (!els.escalationOverlay) return;

		// Show/hide anonymous fields.
		var showAnon = !isAuthenticated();
		els.escalationAnonFields.style.display = showAnon ? '' : 'none';

		// Auto-fill subject.
		if (els.escalationSubject) {
			els.escalationSubject.value =
				reason || currentEscalationSubject || '';
		}

		showEscalationState('form');
		els.escalationOverlay.classList.add(
			'def-cc-escalation-overlay--open'
		);
	}

	function closeEscalation() {
		if (!els.escalationOverlay) return;
		// Abort any in-flight escalation send so its success handler can't
		// fire after the user closed the overlay.
		if (escalationInflightController) {
			escalationInflightController.abort();
			escalationInflightController = null;
		}
		els.escalationOverlay.classList.remove(
			'def-cc-escalation-overlay--open'
		);
		// Clear form after animation.
		setTimeout(function () {
			clearEscalationForm();
		}, 300);
	}

	function showEscalationState(state) {
		var form = els.escalationFormWrap;
		var header = els.escalationHeader;
		var spinner = els.escalationSpinner;
		var success = els.escalationSuccess;
		var error = els.escalationError;

		form.style.display = state === 'form' ? '' : 'none';
		header.style.display = state === 'form' ? '' : 'none';
		spinner.style.display = state === 'sending' ? '' : 'none';
		success.style.display = state === 'success' ? '' : 'none';
		error.style.display = state === 'error' ? '' : 'none';
	}

	function clearEscalationForm() {
		if (els.escalationFirstName) els.escalationFirstName.value = '';
		if (els.escalationLastName) els.escalationLastName.value = '';
		if (els.escalationEmail) els.escalationEmail.value = '';
		if (els.escalationPhone) els.escalationPhone.value = '';
		if (els.escalationSubject) els.escalationSubject.value = '';
		if (els.escalationMessage) els.escalationMessage.value = '';
		clearEscalationErrors();
	}

	function clearEscalationErrors() {
		var errors = els.escalationOverlay
			? els.escalationOverlay.querySelectorAll(
					'.def-cc-escalation-error'
				)
			: [];
		for (var i = 0; i < errors.length; i++) {
			errors[i].style.display = 'none';
			errors[i].textContent = '';
		}
		var inputs = els.escalationOverlay
			? els.escalationOverlay.querySelectorAll(
					'.def-cc-escalation-input--error, .def-cc-escalation-textarea--error'
				)
			: [];
		for (var j = 0; j < inputs.length; j++) {
			inputs[j].classList.remove('def-cc-escalation-input--error');
			inputs[j].classList.remove('def-cc-escalation-textarea--error');
		}
	}

	function showFieldError(input, msg) {
		if (input.tagName === 'TEXTAREA') {
			input.classList.add('def-cc-escalation-textarea--error');
		} else {
			input.classList.add('def-cc-escalation-input--error');
		}
		var errorEl = input.parentNode.querySelector(
			'.def-cc-escalation-error'
		);
		if (errorEl) {
			errorEl.textContent = msg;
			errorEl.style.display = '';
		}
	}

	function validateEscalationForm() {
		clearEscalationErrors();
		var valid = true;

		// Anonymous fields.
		if (!isAuthenticated()) {
			if (
				els.escalationFirstName &&
				!els.escalationFirstName.value.trim()
			) {
				showFieldError(els.escalationFirstName, 'Required');
				valid = false;
			}
			if (
				els.escalationLastName &&
				!els.escalationLastName.value.trim()
			) {
				showFieldError(els.escalationLastName, 'Required');
				valid = false;
			}
			if (
				els.escalationEmail &&
				!isValidEmail(els.escalationEmail.value.trim())
			) {
				showFieldError(els.escalationEmail, 'Valid email required');
				valid = false;
			}
		}

		// Always required.
		if (
			els.escalationSubject &&
			!els.escalationSubject.value.trim()
		) {
			showFieldError(els.escalationSubject, 'Required');
			valid = false;
		}
		if (
			els.escalationMessage &&
			!els.escalationMessage.value.trim()
		) {
			showFieldError(els.escalationMessage, 'Required');
			valid = false;
		}

		return valid;
	}

	function isValidEmail(email) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
	}

	function handleEscalationSubmit() {
		if (!validateEscalationForm()) return;

		showEscalationState('sending');

		var subject = els.escalationSubject.value.trim();
		var message = els.escalationMessage.value.trim();

		// Build email body client-side (Staff AI Share pattern). The server
		// only sees the final text — no cross-repo marshalling, no AI step.
		var bodyParts = [];

		if (!isAuthenticated()) {
			var firstName = els.escalationFirstName.value.trim();
			var lastName = els.escalationLastName.value.trim();
			var email = els.escalationEmail.value.trim();
			var phone = (els.escalationPhone.value || '').trim();
			bodyParts.push('From: ' + firstName + ' ' + lastName);
			bodyParts.push('Email: ' + email);
			if (phone) bodyParts.push('Phone: ' + phone);
			bodyParts.push('');
		}

		bodyParts.push(message);

		// Append recent conversation transcript.
		var recentMsgs = getRecentMessages(10);
		if (recentMsgs && recentMsgs.length > 0) {
			bodyParts.push('');
			bodyParts.push('---');
			bodyParts.push('Conversation Transcript:');
			bodyParts.push('');
			for (var i = 0; i < recentMsgs.length; i++) {
				var msg = recentMsgs[i];
				var role = msg.role === 'user' ? 'User' : 'Assistant';
				bodyParts.push(role + ': ' + msg.content);
				bodyParts.push('');
			}
		}

		var bodyText = bodyParts.join('\n');

		// Reply-To: anonymous users' form email, or empty (logged-in users
		// have their reply-to set server-side from wp_get_current_user).
		var replyTo = '';
		if (!isAuthenticated()) {
			replyTo = els.escalationEmail.value.trim();
		}

		var payload = {
			subject: subject,
			body: bodyText,
		};
		if (replyTo) {
			payload.reply_to = replyTo;
		}

		var controller = new AbortController();
		trackAbort(controller);
		escalationInflightController = controller;

		fetch(config.wpRestUrl + 'customer-chat/send-escalation-email', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.wpRestNonce || '',
			},
			body: JSON.stringify(payload),
			signal: controller.signal,
			credentials: 'same-origin',
		})
			.then(function (res) {
				untrackAbort(controller);
				if (escalationInflightController === controller) {
					escalationInflightController = null;
				}
				// Read as text first and attempt a defensive JSON parse so a
				// non-JSON error body (HTML error page, empty response) shows
				// the error state instead of throwing an opaque parse error.
				return res.text().then(function (text) {
					var data = null;
					if (text) {
						try { data = JSON.parse(text); } catch (e) { /* ignore */ }
					}
					return { ok: res.ok, data: data };
				});
			})
			.then(function (result) {
				// Bail if the user closed the overlay between the response
				// arriving and this handler running — don't flip the state
				// of a hidden overlay or flash a success banner they won't
				// see. closeEscalation() removes this class as its first
				// action, so it's a reliable "still visible" signal.
				if (
					!els.escalationOverlay ||
					!els.escalationOverlay.classList.contains(
						'def-cc-escalation-overlay--open'
					)
				) {
					return;
				}
				if (result.ok && result.data && result.data.status === 'sent') {
					showEscalationState('success');
				} else {
					showEscalationState('error');
				}
			})
			.catch(function (err) {
				untrackAbort(controller);
				if (escalationInflightController === controller) {
					escalationInflightController = null;
				}
				// Swallow aborts silently — the user closed the overlay, no
				// need to flash an error state they won't even see.
				if (err && err.name === 'AbortError') return;
				// Also bail if the overlay was closed between the error
				// arriving and this handler running.
				if (
					!els.escalationOverlay ||
					!els.escalationOverlay.classList.contains(
						'def-cc-escalation-overlay--open'
					)
				) {
					return;
				}
				showEscalationState('error');
			});
	}

	function getRecentMessages(maxMessages) {
		var msgEls = els.messages
			? els.messages.querySelectorAll(
					'.def-cc-message--user, .def-cc-message--assistant:not(.def-cc-message--thinking)'
				)
			: [];
		var result = [];
		var start = Math.max(0, msgEls.length - maxMessages);
		for (var i = start; i < msgEls.length; i++) {
			var content = msgEls[i].querySelector('.def-cc-message-content');
			if (!content) continue;
			var role = msgEls[i].classList.contains('def-cc-message--user')
				? 'user'
				: 'assistant';
			result.push({ role: role, content: content.textContent || '' });
		}
		return result;
	}

	// ─── 9. FILE UPLOAD ───────────────────────────────────────────

	function handleAttachClick() {
		if (!uploadEligible || isComposerDisabled) return;
		if (stagedFiles.length >= UPLOAD_CONFIG.maxFilesPerMessage) return;
		els.fileInput.value = '';
		els.fileInput.click();
	}

	function handleFileSelect(e) {
		var files = e.target.files;
		if (!files || files.length === 0) return;

		for (var i = 0; i < files.length; i++) {
			if (stagedFiles.length >= UPLOAD_CONFIG.maxFilesPerMessage) break;
			stageFile(files[i]);
		}
	}

	function validateFilePreflight(file) {
		if (!file || file.size === 0) {
			return 'File is empty';
		}
		if (file.size > UPLOAD_CONFIG.maxSizeBytes) {
			return t('fileTooLarge');
		}
		var ext = getFileExtension(file.name);
		if (UPLOAD_CONFIG.allowedExtensions.indexOf(ext) === -1) {
			return t('fileTypeNotSupported');
		}
		return null; // Valid.
	}

	function getFileExtension(filename) {
		var dot = filename.lastIndexOf('.');
		if (dot === -1) return '';
		return filename.substring(dot).toLowerCase();
	}

	function getMimeType(filename) {
		var ext = getFileExtension(filename);
		return UPLOAD_CONFIG.extensionToMime[ext] || 'application/octet-stream';
	}

	function stageFile(file, status, fileId, error) {
		// When called without status (drag/drop, paste), run validation.
		if (typeof status === 'undefined' || status === null) {
			error = validateFilePreflight(file);
			status = error ? 'failed' : 'staged';
		}
		fileIdCounter++;
		var entry = {
			localId: fileIdCounter,
			file: file,
			status: status,
			fileId: fileId || null,
			error: error || null,
			thumbnailUrl: null,
		};

		// Generate thumbnail for images under 20MB.
		if (!error && file.type && file.type.startsWith('image/') && file.size < 20 * 1024 * 1024) {
			var reader = new FileReader();
			reader.onload = function (e) {
				entry.thumbnailUrl = e.target.result;
				renderStagedAttachments();
			};
			reader.readAsDataURL(file);
		}

		stagedFiles.push(entry);
		renderStagedAttachments();
		updateSendButton();
	}

	function removeStagedFile(localId) {
		stagedFiles = stagedFiles.filter(function (f) {
			return f.localId !== localId;
		});
		renderStagedAttachments();
		updateSendButton();
	}

	function clearStagedFiles() {
		stagedFiles = [];
		renderStagedAttachments();
		updateSendButton();
	}

	function renderStagedAttachments() {
		if (!els.staged) return;

		if (stagedFiles.length === 0) {
			els.staged.style.display = 'none';
			els.staged.innerHTML = '';
			return;
		}

		els.staged.style.display = '';
		els.staged.innerHTML = '';

		for (var i = 0; i < stagedFiles.length; i++) {
			var f = stagedFiles[i];
			var chip = el(
				'div',
				'def-cc-attachment-chip' +
					(f.status === 'failed'
						? ' def-cc-attachment-chip--failed'
						: f.status === 'uploading'
							? ' def-cc-attachment-chip--pending'
							: f.status === 'uploaded'
								? ' def-cc-attachment-chip--uploaded'
								: '')
			);
			chip.style.position = 'relative';

			// Thumbnail or status icon.
			if (f.thumbnailUrl) {
				var thumb = document.createElement('img');
				thumb.className = 'def-cc-attachment-chip-thumb';
				thumb.src = f.thumbnailUrl;
				thumb.alt = '';
				chip.appendChild(thumb);
			} else {
				var iconSpan = el('span', 'def-cc-attachment-chip-icon');
				if (f.status === 'uploading') {
					iconSpan.classList.add('def-cc-attachment-chip-icon--pending');
					iconSpan.innerHTML = uploadStatusSVG('uploading');
				} else if (f.status === 'uploaded') {
					iconSpan.innerHTML = uploadStatusSVG('uploaded');
				} else if (f.status === 'failed') {
					iconSpan.innerHTML = uploadStatusSVG('failed');
				} else {
					iconSpan.innerHTML = uploadStatusSVG('staged');
				}
				chip.appendChild(iconSpan);
			}

			// Filename.
			var nameSpan = el('span', 'def-cc-attachment-chip-name');
			nameSpan.textContent = truncateFilename(f.file.name, 20);
			nameSpan.title = f.file.name;
			chip.appendChild(nameSpan);

			// Remove button.
			var removeBtn = el('button', 'def-cc-attachment-chip-remove');
			removeBtn.type = 'button';
			removeBtn.textContent = '\u00d7';
			removeBtn.setAttribute('aria-label', 'Remove ' + f.file.name);
			removeBtn.dataset.localId = f.localId;
			removeBtn.addEventListener('click', function (e) {
				var lid = parseInt(
					e.currentTarget.dataset.localId,
					10
				);
				removeStagedFile(lid);
			});
			chip.appendChild(removeBtn);

			// Error tooltip.
			if (f.error) {
				var tooltip = el('div', 'def-cc-attachment-chip-error');
				tooltip.textContent = f.error;
				chip.appendChild(tooltip);
			}

			els.staged.appendChild(chip);
		}
	}

	function uploadStatusSVG(status) {
		if (status === 'uploading') {
			return '<svg viewBox="0 0 16 16" width="100%" height="100%"><circle cx="8" cy="8" r="6" fill="none" stroke="#3b82f6" stroke-width="2" stroke-dasharray="25" stroke-dashoffset="5"><animateTransform attributeName="transform" type="rotate" values="0 8 8;360 8 8" dur="1s" repeatCount="indefinite"/></circle></svg>';
		}
		if (status === 'uploaded') {
			return '<svg viewBox="0 0 16 16" width="100%" height="100%"><circle cx="8" cy="8" r="7" fill="#10b981"/><polyline points="5 8 7 10 11 6" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		}
		if (status === 'failed') {
			return '<svg viewBox="0 0 16 16" width="100%" height="100%"><circle cx="8" cy="8" r="7" fill="#ef4444"/><line x1="5.5" y1="5.5" x2="10.5" y2="10.5" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/><line x1="10.5" y1="5.5" x2="5.5" y2="10.5" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>';
		}
		// staged
		return '<svg viewBox="0 0 16 16" width="100%" height="100%"><rect x="2" y="2" width="12" height="12" rx="2" fill="none" stroke="#6b7280" stroke-width="1.5"/><path d="M5 9l2 2 4-4" fill="none" stroke="#6b7280" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	}

	function truncateFilename(filename, maxLen) {
		if (filename.length <= maxLen) return filename;
		var ext = getFileExtension(filename);
		var name = filename.substring(
			0,
			filename.length - ext.length
		);
		var truncLen = maxLen - ext.length - 3; // 3 for "..."
		if (truncLen < 1) truncLen = 1;
		return name.substring(0, truncLen) + '...' + ext;
	}

	function uploadStagedFiles() {
		var filesToUpload = stagedFiles.filter(function (f) {
			return f.status === 'staged';
		});

		if (filesToUpload.length === 0) {
			return Promise.resolve([]);
		}

		// Mark all as uploading.
		for (var i = 0; i < filesToUpload.length; i++) {
			filesToUpload[i].status = 'uploading';
		}
		renderStagedAttachments();

		var conversationId = threadId || '_anonymous';

		return Promise.all(
			filesToUpload.map(function (staged) {
				return uploadSingleFile(staged, conversationId);
			})
		).then(function (results) {
			var fileIds = [];
			var anyFailed = false;

			for (var j = 0; j < results.length; j++) {
				if (results[j].success) {
					filesToUpload[j].status = 'uploaded';
					filesToUpload[j].fileId = results[j].fileId;
					fileIds.push(results[j].fileId);
				} else {
					filesToUpload[j].status = 'failed';
					filesToUpload[j].error =
						results[j].error || t('uploadFailed');
					anyFailed = true;
				}
			}

			renderStagedAttachments();

			if (anyFailed) {
				return Promise.reject(new Error('Upload failed'));
			}

			return fileIds;
		});
	}

	function uploadSingleFile(staged, conversationId) {
		// Upload goes through WordPress BFF proxy (same-origin, cookies).
		// WordPress adds X-DEF-API-Key + X-DEF-User headers.
		var headers = { 'Content-Type': 'application/json' };
		if (config.nonce) {
			headers['X-WP-Nonce'] = config.nonce;
		}

		// Step 1: Init via BFF proxy.
		var controller1 = new AbortController();
		trackAbort(controller1);

		return fetch(config.uploadInitUrl, {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			body: JSON.stringify({
				filename: staged.file.name,
				mime_type: getMimeType(staged.file.name),
				size_bytes: staged.file.size,
				conversation_id: conversationId,
			}),
			signal: controller1.signal,
		})
			.then(function (res) {
				untrackAbort(controller1);
				if (!res.ok) throw new Error('Init failed');
				return res.json();
			})
			.then(function (initData) {
				if (!initData.file_id || !initData.upload_url) {
					throw new Error('Invalid init response');
				}

				// Step 2: PUT blob.
				// Translate Docker-internal hostnames to browser-routable ones.
				var putUrl = translateDockerUrl(initData.upload_url);
				var controller2 = new AbortController();
				trackAbort(controller2);

				return fetch(putUrl, {
					method: 'PUT',
					headers: {
						'Content-Type': getMimeType(staged.file.name),
						'x-ms-blob-type': 'BlockBlob',
					},
					body: staged.file,
					signal: controller2.signal,
				}).then(function (putRes) {
					untrackAbort(controller2);
					if (!putRes.ok) throw new Error('PUT failed');
					return initData.file_id;
				});
			})
			.then(function (fileId) {
				// Step 3: Commit.
				var controller3 = new AbortController();
				trackAbort(controller3);

				return fetch(
					config.uploadCommitUrl,
					{
						method: 'POST',
						headers: headers,
						credentials: 'same-origin',
						body: JSON.stringify({ file_id: fileId }),
						signal: controller3.signal,
					}
				).then(function (commitRes) {
					untrackAbort(controller3);
					if (!commitRes.ok) throw new Error('Commit failed');
					return { success: true, fileId: fileId };
				});
			})
			.catch(function (err) {
				return {
					success: false,
					error: err.message || t('uploadFailed'),
				};
			});
	}

	function checkUploadEligibility() {
		if (!config.apiBaseUrl) {
			setUploadEligible(false);
			return;
		}

		var headers = {};
		if (contextToken) {
			headers['Authorization'] = 'Bearer ' + contextToken;
		}

		var controller = new AbortController();
		trackAbort(controller);

		fetch(
			config.apiBaseUrl +
				'/api/customer/employee-tools?employee=SalesAssistant',
			{
				method: 'GET',
				headers: headers,
				signal: controller.signal,
			}
		)
			.then(function (res) {
				untrackAbort(controller);
				if (!res.ok) {
					setUploadEligible(false);
					return;
				}
				return res.json();
			})
			.then(function (data) {
				if (data && data.tools && Array.isArray(data.tools)) {
					setUploadEligible(
						data.tools.indexOf('upload_file') !== -1
					);
				} else {
					setUploadEligible(false);
				}
			})
			.catch(function () {
				untrackAbort(controller);
				setUploadEligible(false);
			});
	}

	function setUploadEligible(eligible) {
		uploadEligible = eligible;
		if (els.attachBtn) {
			els.attachBtn.style.display = eligible ? '' : 'none';
		}
	}

	// ─── 10. THREAD MANAGEMENT ────────────────────────────────────

	function loadLocalThreads() {
		try {
			var raw = localStorage.getItem(HISTORY_KEY);
			if (raw) {
				localThreads = JSON.parse(raw);
				if (!Array.isArray(localThreads)) localThreads = [];
			}
		} catch (e) {
			localThreads = [];
		}
	}

	function saveLocalThread() {
		try {
			localStorage.setItem(HISTORY_KEY, JSON.stringify(localThreads));
		} catch (e) {}
	}

	// v3.1.1 — Replay-safe tool_outputs subset for history rehydration.
	// Only render-data outputs (result_type === 'wp_product' product cards) are
	// persisted. Transient UX prompts (escalation_offer) and action-execution
	// markers (wp_rest_call) are dropped so a page reload doesn't re-prompt the
	// user or re-trigger a side-effect.
	//
	// To extend: add new render-data result_types to the allowlist below.
	// Never persist action-execution markers or transient prompts.
	// Mirror this allowlist in the DEF backend (_REPLAY_SAFE_RESULT_TYPES in
	// app/chatbot/routes.py) — both filters must stay in sync.
	function persistableToolOutputs(outputs) {
		if (!outputs || !outputs.length) return null;
		var out = [];
		for (var i = 0; i < outputs.length; i++) {
			var o = outputs[i];
			if (o && o.result_type === 'wp_product') out.push(o);
		}
		return out.length ? out : null;
	}

	function upsertThread(tid, userMsg, assistantMsg, toolOutputs) {
		if (!tid) return;
		var now = new Date().toISOString();
		var found = false;
		var persisted = persistableToolOutputs(toolOutputs);

		for (var i = 0; i < localThreads.length; i++) {
			if (localThreads[i].id === tid) {
				found = true;
				localThreads[i].updatedAt = now;
				if (!localThreads[i].messages) {
					localThreads[i].messages = [];
				}
				if (userMsg) {
					localThreads[i].messages.push({
						role: 'user',
						content: userMsg,
						at: now,
					});
				}
				if (assistantMsg) {
					var asstMsg = {
						role: 'assistant',
						content: assistantMsg,
						at: now,
					};
					if (persisted) asstMsg.tool_outputs = persisted;
					localThreads[i].messages.push(asstMsg);
				}
				break;
			}
		}

		if (!found) {
			var thread = {
				id: tid,
				title: (userMsg || '').substring(0, 50),
				createdAt: now,
				updatedAt: now,
				messages: [],
			};
			if (userMsg)
				thread.messages.push({
					role: 'user',
					content: userMsg,
					at: now,
				});
			if (assistantMsg) {
				var newAsstMsg = {
					role: 'assistant',
					content: assistantMsg,
					at: now,
				};
				if (persisted) newAsstMsg.tool_outputs = persisted;
				thread.messages.push(newAsstMsg);
			}
			localThreads.unshift(thread);
		}

		// Sort by most recent.
		localThreads.sort(function (a, b) {
			return (b.updatedAt || '').localeCompare(a.updatedAt || '');
		});

		saveLocalThread();
	}

	function loadThreadMessages(tid) {
		if (!tid) return;
		loadLocalThreads();

		var thread = null;
		for (var i = 0; i < localThreads.length; i++) {
			if (localThreads[i].id === tid) {
				thread = localThreads[i];
				break;
			}
		}

		if (!thread || !thread.messages || thread.messages.length === 0)
			return;

		// Hide greeting.
		if (els.greeting) {
			els.greeting.style.display = 'none';
		}
		if (els.welcomeChips) {
			els.welcomeChips.style.display = 'none';
		}
		if (els.welcomeBanner) {
			els.welcomeBanner.style.display = 'none';
		}

		// Render all messages.
		for (var j = 0; j < thread.messages.length; j++) {
			var msg = thread.messages[j];
			appendMessage(msg.role, msg.content);
			// v3.1.1 — replay persisted result-cards. Snapshot data (price/stock
			// reflects render-time state, not live); the action button still
			// links to the live product so a stale price doesn't drive a bad
			// add-to-cart click — the cart endpoint is the source of truth.
			if (msg.role === 'assistant' && msg.tool_outputs && msg.tool_outputs.length) {
				for (var k = 0; k < msg.tool_outputs.length; k++) {
					var out = msg.tool_outputs[k];
					if (out && out.result_type === 'wp_product') {
						appendResultCardsSection(out);
					}
				}
			}
		}
	}

	function loadServerThreads() {
		if (!contextToken || !config.apiBaseUrl) return;

		var controller = new AbortController();
		trackAbort(controller);

		fetch(config.apiBaseUrl + '/api/my/threads?include_messages=1', {
			method: 'GET',
			headers: {
				Authorization: 'Bearer ' + contextToken,
			},
			signal: controller.signal,
		})
			.then(function (res) {
				untrackAbort(controller);
				if (!res.ok) return;
				return res.json();
			})
			.then(function (data) {
				if (!data || !data.threads) return;
				mergeThreads(data.threads);
				adoptMostRecentThreadIfNone();
			})
			.catch(function () {
				untrackAbort(controller);
			});
	}

	// Cross-device continuity: when a logged-in WP user opens the widget on a
	// new browser, localStorage is empty so threadId is null at init. After
	// /api/my/threads merges the user's prior threads, adopt the most recent
	// one with messages so the conversation continues instead of starting blank.
	// The !threadId guard makes this a no-op if the user already sent a message
	// before the server responded (processChatResponse sets threadId first).
	// The cleared_session check respects an explicit Clear & Start Fresh — a
	// cleared user reloading the page should keep seeing the welcome state, not
	// have the cleared thread silently re-adopted from the server.
	function adoptMostRecentThreadIfNone() {
		if (threadId) return;
		try {
			if (localStorage.getItem('def:cleared_session') === '1') return;
		} catch (e) {}
		if (!Array.isArray(localThreads) || !localThreads.length) return;

		var picked = null;
		for (var i = 0; i < localThreads.length; i++) {
			var t = localThreads[i];
			if (t && t.id && t.messages && t.messages.length > 0) {
				picked = t;
				break;
			}
		}
		if (!picked) return;

		threadId = picked.id;
		isContinuing = true;
		try { localStorage.setItem(THREAD_KEY, threadId); } catch (e) {}
		loadThreadMessages(threadId);
	}

	function mergeThreads(serverThreads) {
		if (!Array.isArray(serverThreads)) return;

		loadLocalThreads();

		var localMap = {};
		for (var i = 0; i < localThreads.length; i++) {
			localMap[localThreads[i].id] = localThreads[i];
		}

		// Server threads take precedence.
		for (var j = 0; j < serverThreads.length; j++) {
			var st = serverThreads[j];
			if (localMap[st.id]) {
				// Merge: server messages override if more recent.
				if (st.messages && st.messages.length > 0) {
					localMap[st.id].messages = st.messages;
					localMap[st.id].updatedAt =
						st.updatedAt || st.updated_at || localMap[st.id].updatedAt;
				}
			} else {
				localThreads.push({
					id: st.id,
					title: st.title || '',
					createdAt: st.createdAt || st.created_at || '',
					updatedAt: st.updatedAt || st.updated_at || '',
					messages: st.messages || [],
				});
			}
		}

		localThreads.sort(function (a, b) {
			return (b.updatedAt || '').localeCompare(a.updatedAt || '');
		});

		saveLocalThread();
	}

	function clearConversation() {
		// Remove thread from local history.
		if (threadId) {
			localThreads = localThreads.filter(function (t) {
				return t.id !== threadId;
			});
			saveLocalThread();
		}

		// Reset state. The cleared_session marker tells adoptMostRecentThreadIfNone()
		// to skip cross-device adoption on the next reload — without it, an explicit
		// Clear by a logged-in user would be silently undone when /api/my/threads
		// returns the (still server-side) prior threads.
		try {
			localStorage.removeItem(THREAD_KEY);
			localStorage.removeItem('def:session_cookie');
			localStorage.setItem('def:cleared_session', '1');
		} catch (e) {}
		threadId = null;
		isContinuing = false;
		currentEscalationSubject = '';

		// Clear messages area.
		clearMessages();

		// Clear staged files.
		clearStagedFiles();

		// Show greeting + welcome chips + welcome banner (parallel
		// lifecycle — all belong to the empty/welcome state and are
		// preserved by clearMessages).
		if (els.greeting) {
			els.greeting.style.display = '';
		}
		if (els.welcomeChips) {
			els.welcomeChips.style.display = '';
		}
		if (els.welcomeBanner) {
			els.welcomeBanner.style.display = '';
		}
	}

	function clearMessages() {
		if (!els.messages) return;
		var children = els.messages.children;
		for (var i = children.length - 1; i >= 0; i--) {
			// Preserve all welcome-state elements so they re-appear after
			// clearConversation rather than being lost from the DOM.
			if (children[i] !== els.greeting &&
				children[i] !== els.welcomeChips &&
				children[i] !== els.welcomeBanner) {
				els.messages.removeChild(children[i]);
			}
		}
	}

	function claimThread(tid) {
		if (!tid || !contextToken || !config.apiBaseUrl) return;

		var controller = new AbortController();
		trackAbort(controller);

		fetch(config.apiBaseUrl + '/api/thread/' + encodeURIComponent(tid) + '/claim', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Authorization: 'Bearer ' + contextToken,
			},
			body: JSON.stringify({}),
			signal: controller.signal,
		})
			.then(function (res) {
				untrackAbort(controller);
				if (!res.ok) {
					if (typeof console !== 'undefined' && console.warn) {
						console.warn('[DEF] Thread claim failed: status=' + res.status + ' thread=' + tid);
					}
				}
			})
			.catch(function () {
				untrackAbort(controller);
			});
	}

	// ─── 11. MENU ─────────────────────────────────────────────────

	function toggleMenu() {
		if (!els.menu) return;
		var isOpen = els.menu.classList.contains('def-cc-menu--open');
		if (isOpen) {
			closeMenu();
		} else {
			openMenu();
		}
	}

	function openMenu() {
		els.menu.classList.add('def-cc-menu--open');
		els.menuBtn.setAttribute('aria-expanded', 'true');
		updateMenuState();

		// Close on outside click.
		setTimeout(function () {
			root.addEventListener('click', closeMenuOnOutsideClick);
		}, 0);
	}

	function closeMenu() {
		if (!els.menu) return;
		els.menu.classList.remove('def-cc-menu--open');
		els.menuBtn.setAttribute('aria-expanded', 'false');
		root.removeEventListener('click', closeMenuOnOutsideClick);
	}

	function closeMenuOnOutsideClick(e) {
		if (els.menu && !els.menu.contains(e.target) && e.target !== els.menuBtn) {
			closeMenu();
		}
	}

	function updateMenuState() {
		if (els.menuLogin) {
			els.menuLogin.style.display = isAuthenticated() ? 'none' : '';
		}
		if (els.menuLogout) {
			els.menuLogout.style.display = isAuthenticated() ? '' : 'none';
		}
		if (els.menuEscalate) {
			els.menuEscalate.style.display = threadId ? '' : 'none';
		}
	}

	function handleClearChat() {
		closeMenu();
		openConfirm();
	}

	function handleLoginMenu() {
		closeMenu();
		showLogin();
	}

	function handleLogoutMenu() {
		closeMenu();
		// Clear auth state (memory-only token).
		setContextToken(null);
		try { localStorage.setItem(AUTH_STATE_KEY, 'logged_out'); } catch (e) {}
		onAuthChange();
		// Clear conversation so the next user doesn't see stale messages.
		clearConversation();
		appendMessage(
			'assistant',
			'You have been logged out.'
		);
	}

	function handleEscalateMenu() {
		closeMenu();
		showEscalation();
	}

	// ─── Confirm dialog ──

	function openConfirm() {
		if (els.confirmOverlay) {
			els.confirmOverlay.classList.add('def-cc-confirm-overlay--open');
		}
	}

	function closeConfirm() {
		if (els.confirmOverlay) {
			els.confirmOverlay.classList.remove(
				'def-cc-confirm-overlay--open'
			);
		}
	}

	function confirmClear() {
		closeConfirm();
		clearConversation();
	}

	// ─── 12. UI HELPERS ───────────────────────────────────────────

	/**
	 * Translate Docker-internal hostnames to browser-routable ones.
	 * E.g. http://azurite:10000/... → http://localhost:10000/...
	 */
	function translateDockerUrl(url) {
		if (!url) return url;
		try {
			var parsed = new URL(url);
			var host = parsed.hostname;
			// localhost, IPs, and real domains are already routable.
			if (
				host === 'localhost' ||
				host === '127.0.0.1' ||
				/^\d+\.\d+\.\d+\.\d+$/.test(host) ||
				/\.[a-z]{2,}$/i.test(host)
			) {
				return url;
			}
			// Docker internal hostname — swap with current page's hostname.
			parsed.hostname = window.location.hostname || 'localhost';
			return parsed.toString();
		} catch (e) {
			return url;
		}
	}

	function scrollToBottom(smooth) {
		if (!els.messages) return;
		var opts = smooth
			? { top: els.messages.scrollHeight, behavior: 'smooth' }
			: undefined;
		if (opts) {
			els.messages.scrollTo(opts);
		} else {
			els.messages.scrollTop = els.messages.scrollHeight;
		}
	}

	function setComposerDisabled(disabled) {
		isComposerDisabled = disabled;
		if (els.input) els.input.disabled = disabled;
		if (els.sendBtn) els.sendBtn.disabled = disabled;
		if (els.attachBtn) els.attachBtn.disabled = disabled;
		// Welcome chips share the composer's disabled state — without this,
		// taps during sending would silently no-op (chips look tappable
		// but the click handler early-returns).
		if (els.welcomeChips) {
			var chips = els.welcomeChips.querySelectorAll('.def-cc-welcome-chip');
			for (var i = 0; i < chips.length; i++) {
				chips[i].disabled = disabled;
			}
		}
	}

	function updateSendButton() {
		if (!els.sendBtn) return;
		var hasText = els.input && els.input.value.trim().length > 0;
		var hasFiles = stagedFiles.some(function (f) {
			return f.status === 'staged';
		});
		els.sendBtn.disabled = isComposerDisabled || (!hasText && !hasFiles);
	}

	function autoResizeInput() {
		if (!els.input) return;
		els.input.style.height = 'auto';
		els.input.style.height =
			Math.min(els.input.scrollHeight, 120) + 'px';
	}

	function updateGreeting() {
		// Intentionally a no-op. The capabilities greeting is rendered at DOM
		// construction using config.userFirstName. This function previously
		// overwrote that greeting with plain text, causing a visible flicker.
		// Do not restore — the greeting template handles personalisation.
	}

	function escapeHtml(str) {
		if (!str) return '';
		return str
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	// ─── DOM helpers ──

	function el(tag, className) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		return node;
	}

	function menuItem(iconSvg, label, handler) {
		var btn = el('button', 'def-cc-menu-item');
		btn.type = 'button';
		btn.setAttribute('role', 'menuitem');
		btn.innerHTML = iconSvg + '<span>' + escapeHtml(label) + '</span>';
		btn.addEventListener('click', handler);
		return btn;
	}

	function escalationField(label, type, elKey, required) {
		var field = el('div', 'def-cc-escalation-field');
		var labelEl = el('label', 'def-cc-escalation-label');
		labelEl.innerHTML =
			escapeHtml(label) +
			(required ? ' <span class="def-cc-required">*</span>' : '');
		var input = document.createElement('input');
		input.type = type;
		input.className = 'def-cc-escalation-input';
		els[elKey] = input;
		field.appendChild(labelEl);
		field.appendChild(input);
		var error = el('div', 'def-cc-escalation-error');
		error.style.display = 'none';
		field.appendChild(error);
		return field;
	}

	// ─── Abort controller tracking ──

	function trackAbort(controller) {
		activeAbortControllers.push(controller);
	}

	function untrackAbort(controller) {
		var idx = activeAbortControllers.indexOf(controller);
		if (idx !== -1) activeAbortControllers.splice(idx, 1);
	}

	// ─── 13. LIFECYCLE ────────────────────────────────────────────

	function init(shadowRoot, cfg) {
		root = shadowRoot;
		config = cfg;
		destroyed = false;

		// Build UI.
		buildChatUI();

		// Graceful offline state — no API URL configured.
		if (!config.apiBaseUrl) {
			showOfflineState();
			return;
		}

		// Load existing thread from localStorage FIRST (clearConversation needs threadId).
		loadLocalThreads();
		try {
			threadId = localStorage.getItem(THREAD_KEY) || null;
		} catch (e) {}

		// Detect WordPress auth state change (login/logout outside widget).
		var currentAuthState = config.isLoggedIn ? 'logged_in' : 'logged_out';
		var storedAuthState = null;
		try { storedAuthState = localStorage.getItem(AUTH_STATE_KEY); } catch (e) {}
		try { localStorage.setItem(AUTH_STATE_KEY, currentAuthState); } catch (e) {}

		if (storedAuthState === 'logged_in' && currentAuthState === 'logged_out') {
			// User logged out outside widget — clear stale authenticated thread.
			clearConversation();
		}

		if (threadId) {
			isContinuing = true;
			loadThreadMessages(threadId);
		}

		// Fetch the JWT context token for logged-in WordPress users so the
		// widget's isAuthenticated() reflects the real auth state — drives the
		// Login/Logout menu item, escalation form anonymous fields, and
		// server-side thread loading. Anonymous visitors skip this to avoid a
		// 401 from /context-token; the BFF proxy authenticates each chat
		// request from cookie + nonce, so the JWT is not strictly required for
		// the message flow.
		if (config.isLoggedIn) {
			getValidToken().then(function () {
				if (destroyed) return;
				// setContextToken() (called inside getValidToken on success)
				// already fires updateMenuState. Load server-side threads now
				// that we know the user is authenticated.
				if (isAuthenticated() && typeof loadServerThreads === 'function') {
					loadServerThreads();
				}
			});
		}

		// Check upload eligibility.
		checkUploadEligibility();

		// Focus input.
		setTimeout(function () {
			if (els.input && !destroyed) els.input.focus();
		}, 200);

		// Keyboard: Escape closes menu.
		root.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				if (
					els.menu &&
					els.menu.classList.contains('def-cc-menu--open')
				) {
					closeMenu();
					e.stopPropagation();
				}
			}
		});
	}

	function destroy() {
		destroyed = true;

		// Clear refresh timer.
		if (refreshTimer) {
			clearTimeout(refreshTimer);
			refreshTimer = null;
		}

		// Abort all in-flight fetches.
		for (var i = 0; i < activeAbortControllers.length; i++) {
			try {
				activeAbortControllers[i].abort();
			} catch (e) {}
		}
		activeAbortControllers = [];

		// Reset state.
		root = null;
		config = null;
		els = {};
		contextToken = null;
		contextPayload = null;
		refreshPromise = null;
		threadId = null;
		isContinuing = false;
		isComposerDisabled = false;
		stagedFiles = [];
		uploadEligible = false;
		localThreads = [];
		currentEscalationSubject = '';
	}

	// Expose public API.
	window.DEFCustomerChat = {
		init: init,
		destroy: destroy,
	};
})();
