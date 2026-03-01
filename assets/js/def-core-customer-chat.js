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

	var THREAD_KEY = 'a3rev_thread_id';
	var HISTORY_KEY = 'a3rev_threads';

	function t(key) {
		return (
			(config && config.strings && config.strings[key]) ||
			DEFAULT_STRINGS[key] ||
			key
		);
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

	// Upload state.
	var stagedFiles = [];
	var uploadEligible = false;
	var fileIdCounter = 0;

	// Thread state.
	var localThreads = [];

	// Escalation state.
	var currentEscalationSubject = '';

	// Lifecycle.
	var destroyed = false;
	var activeAbortControllers = [];

	// ─── 3. DOM CONSTRUCTION ───────────────────────────────────────

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

		// Header actions (menu).
		var actions = el('div', 'def-cc-header-actions');

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
		header.appendChild(actions);

		// Insert header as first child of panel (before close button).
		panel.insertBefore(header, panel.firstChild);
		// Re-append close button after header so it overlays correctly.
		if (closeBtn) {
			panel.appendChild(closeBtn);
		}

		// ── Messages area ──
		var messages = el('div', 'def-cc-messages');
		messages.setAttribute('role', 'log');
		messages.setAttribute('aria-live', 'polite');
		messages.setAttribute('aria-relevant', 'additions');
		els.messages = messages;
		panel.insertBefore(messages, closeBtn);

		// Greeting.
		var greetingEl = el('div', 'def-cc-message def-cc-message--assistant');
		var greetingIcon = createAssistantIcon();
		greetingEl.appendChild(greetingIcon);
		var greetingContent = el('div', 'def-cc-message-content');
		greetingContent.textContent = t('greeting');
		greetingEl.appendChild(greetingContent);
		els.greeting = greetingEl;
		messages.appendChild(greetingEl);

		// ── Staged attachments area (hidden until files staged) ──
		var staged = el('div', 'def-cc-staged');
		staged.style.display = 'none';
		els.staged = staged;
		panel.insertBefore(staged, closeBtn);

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
		});
		input.addEventListener('input', autoResizeInput);
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
		panel.insertBefore(composer, closeBtn);

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
		if (!config.restUrl) {
			return Promise.resolve(null);
		}

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
				return res.json();
			})
			.then(function (data) {
				if (data && data.token) {
					return data.token;
				}
				return null;
			})
			.catch(function () {
				untrackAbort(controller);
				return null;
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

		var wasAnonymous = !isAuthenticated();
		var currentThreadId = threadId;

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
					// Login success — set token directly from response.
					setContextToken(result.data.token);
					onAuthChange();

					// Claim anonymous thread if we had one.
					if (
						wasAnonymous &&
						currentThreadId &&
						currentThreadId !== '_anonymous'
					) {
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

	// ─── 6. MESSAGE ENGINE ─────────────────────────────────────────

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

				// Headers.
				var headers = {
					'Content-Type': 'application/json',
				};
				if (contextToken) {
					headers['Authorization'] = 'Bearer ' + contextToken;
				}

				var controller = new AbortController();
				trackAbort(controller);

				return fetch(config.apiBaseUrl + '/api/chat', {
					method: 'POST',
					headers: headers,
					body: JSON.stringify(body),
					signal: controller.signal,
				})
					.then(function (res) {
						untrackAbort(controller);
						if (res.status === 401 && contextToken) {
							// Token expired — try refresh and retry once.
							return getValidToken().then(function (newToken) {
								if (newToken && newToken !== contextToken) {
									headers['Authorization'] =
										'Bearer ' + newToken;
								}
								var controller2 = new AbortController();
								trackAbort(controller2);
								return fetch(
									config.apiBaseUrl + '/api/chat',
									{
										method: 'POST',
										headers: headers,
										body: JSON.stringify(body),
										signal: controller2.signal,
									}
								).then(function (r) {
									untrackAbort(controller2);
									return r;
								});
							});
						}
						if (res.status === 429) {
							hideThinking(thinkingEl);
							appendMessage(
								'assistant',
								t('rateLimited')
							);
							setComposerDisabled(false);
							return null;
						}
						return res;
					})
					.then(function (res) {
						if (!res) return;
						return res.json().then(function (data) {
							hideThinking(thinkingEl);

							if (!data || data.error) {
								appendMessage(
									'assistant',
									t('connectionError')
								);
								setComposerDisabled(false);
								return;
							}

							// Store thread ID.
							if (data.thread_id) {
								threadId = data.thread_id;
								try {
									localStorage.setItem(
										THREAD_KEY,
										threadId
									);
								} catch (e) {}
								isContinuing = true;
							}

							// Extract reply.
							var reply = '';
							if (
								data.choices &&
								data.choices[0] &&
								data.choices[0].message
							) {
								reply = data.choices[0].message.content || '';
							}

							if (reply) {
								appendMessage('assistant', reply);
							}

							// Check for escalation offer.
							if (data.tool_outputs) {
								for (
									var i = 0;
									i < data.tool_outputs.length;
									i++
								) {
									if (
										data.tool_outputs[i].type ===
										'escalation_offer'
									) {
										showEscalation(
											data.tool_outputs[i].reason
										);
										break;
									}
								}
							}

							// Save thread to localStorage.
							upsertThread(
								threadId,
								text ||
									'Please analyze the attached file(s).',
								reply
							);

							setComposerDisabled(false);
						});
					});
			})
			.catch(function (err) {
				// Upload or network failure.
				if (err && err.name === 'AbortError') return;
				var thinkingEl = root.querySelector(
					'.def-cc-message--thinking'
				);
				if (thinkingEl) hideThinking(thinkingEl);
				appendMessage(
					'assistant',
					t('connectionError')
				);
				setComposerDisabled(false);
			});
	}

	function appendUserMessage(text, fileIds) {
		var msgEl = el('div', 'def-cc-message def-cc-message--user');
		var content = el('div', 'def-cc-message-content');
		content.textContent = text;

		// File indicators.
		if (fileIds && fileIds.length > 0) {
			var filesDiv = el('div', 'def-cc-message-files');
			var staged = stagedFiles.filter(function (f) {
				return fileIds.indexOf(f.fileId) !== -1;
			});
			for (var i = 0; i < staged.length; i++) {
				var chip = el('span', 'def-cc-message-file');
				chip.textContent = '\u{1F4CE} ' + staged[i].file.name;
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
		iconEl.textContent = 'DE';
		iconEl.setAttribute('aria-hidden', 'true');
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

		var conversationId = threadId || '_anonymous';
		var payload = {
			reason: 'User requested human support',
			subject: els.escalationSubject.value.trim(),
		};

		if (!isAuthenticated()) {
			payload.first_name = els.escalationFirstName.value.trim();
			payload.last_name = els.escalationLastName.value.trim();
			payload.email = els.escalationEmail.value.trim();
			payload.phone = (els.escalationPhone.value || '').trim();
			conversationId = '_anonymous';
		}

		// Add recent messages as transcript snippet.
		var recentMsgs = getRecentMessages(10);
		if (recentMsgs.length > 0) {
			payload.transcript_snippet = recentMsgs;
		}

		// Add the form message content.
		payload.message = els.escalationMessage.value.trim();

		var headers = {
			'Content-Type': 'application/json',
		};
		if (contextToken) {
			headers['Authorization'] = 'Bearer ' + contextToken;
		}

		var controller = new AbortController();
		trackAbort(controller);

		fetch(
			config.apiBaseUrl +
				'/api/customer/conversations/' +
				encodeURIComponent(conversationId) +
				'/escalate',
			{
				method: 'POST',
				headers: headers,
				body: JSON.stringify(payload),
				signal: controller.signal,
			}
		)
			.then(function (res) {
				untrackAbort(controller);
				return res.json();
			})
			.then(function (data) {
				if (data && !data.error && !data.detail) {
					showEscalationState('success');
				} else {
					showEscalationState('error');
				}
			})
			.catch(function () {
				untrackAbort(controller);
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

			var validation = validateFilePreflight(files[i]);
			if (validation) {
				stageFile(files[i], 'failed', null, validation);
			} else {
				stageFile(files[i], 'staged', null, null);
			}
		}

		renderStagedAttachments();
		updateSendButton();
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
		fileIdCounter++;
		stagedFiles.push({
			localId: fileIdCounter,
			file: file,
			status: status,
			fileId: fileId,
			error: error,
		});
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

			// Status icon.
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
			return '<svg viewBox="0 0 16 16" width="16" height="16"><circle cx="8" cy="8" r="6" fill="none" stroke="#3b82f6" stroke-width="2" stroke-dasharray="25" stroke-dashoffset="5"><animateTransform attributeName="transform" type="rotate" values="0 8 8;360 8 8" dur="1s" repeatCount="indefinite"/></circle></svg>';
		}
		if (status === 'uploaded') {
			return '<svg viewBox="0 0 16 16" width="16" height="16"><circle cx="8" cy="8" r="7" fill="#10b981"/><polyline points="5 8 7 10 11 6" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		}
		if (status === 'failed') {
			return '<svg viewBox="0 0 16 16" width="16" height="16"><circle cx="8" cy="8" r="7" fill="#ef4444"/><line x1="5.5" y1="5.5" x2="10.5" y2="10.5" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/><line x1="10.5" y1="5.5" x2="5.5" y2="10.5" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>';
		}
		// staged
		return '<svg viewBox="0 0 16 16" width="16" height="16"><rect x="2" y="2" width="12" height="12" rx="2" fill="none" stroke="#6b7280" stroke-width="1.5"/><path d="M5 9l2 2 4-4" fill="none" stroke="#6b7280" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
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
		var headers = { 'Content-Type': 'application/json' };
		if (contextToken) {
			headers['Authorization'] = 'Bearer ' + contextToken;
		}

		// Step 1: Init.
		var controller1 = new AbortController();
		trackAbort(controller1);

		return fetch(config.apiBaseUrl + '/api/customer/uploads/init', {
			method: 'POST',
			headers: headers,
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
				var controller2 = new AbortController();
				trackAbort(controller2);

				return fetch(initData.upload_url, {
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
					config.apiBaseUrl + '/api/customer/uploads/commit',
					{
						method: 'POST',
						headers: headers,
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

	function upsertThread(tid, userMsg, assistantMsg) {
		if (!tid) return;
		var now = new Date().toISOString();
		var found = false;

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
					localThreads[i].messages.push({
						role: 'assistant',
						content: assistantMsg,
						at: now,
					});
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
			if (assistantMsg)
				thread.messages.push({
					role: 'assistant',
					content: assistantMsg,
					at: now,
				});
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

		// Render all messages.
		for (var j = 0; j < thread.messages.length; j++) {
			var msg = thread.messages[j];
			appendMessage(msg.role, msg.content);
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
			})
			.catch(function () {
				untrackAbort(controller);
			});
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

		// Reset state.
		try {
			localStorage.removeItem(THREAD_KEY);
		} catch (e) {}
		threadId = null;
		isContinuing = false;
		currentEscalationSubject = '';

		// Clear messages area.
		clearMessages();

		// Clear staged files.
		clearStagedFiles();

		// Show greeting.
		if (els.greeting) {
			els.greeting.style.display = '';
		}
	}

	function clearMessages() {
		if (!els.messages) return;
		var children = els.messages.children;
		for (var i = children.length - 1; i >= 0; i--) {
			if (children[i] !== els.greeting) {
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
			signal: controller.signal,
		})
			.then(function () {
				untrackAbort(controller);
				// Claim success — log silently per V1.2 spec.
			})
			.catch(function () {
				untrackAbort(controller);
				// Claim failure — log silently per V1.2 spec.
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
		onAuthChange();
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
		if (!els.greeting) return;
		var content = els.greeting.querySelector('.def-cc-message-content');
		if (!content) return;

		var name =
			(contextPayload && contextPayload.first_name) ||
			(contextPayload && contextPayload.display_name) ||
			'';
		if (name) {
			content.textContent =
				'Hello, ' + name + '! How can I help you today?';
		} else {
			content.textContent = t('greeting');
		}
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

		// Fetch context token (async — don't block UI).
		fetchContextToken()
			.then(function (token) {
				if (destroyed) return;
				if (token) {
					setContextToken(token);
					onAuthChange();
				}

				// Load existing thread.
				loadLocalThreads();
				try {
					threadId = localStorage.getItem(THREAD_KEY) || null;
				} catch (e) {}

				if (threadId) {
					isContinuing = true;
		loadThreadMessages(threadId);
				}

				// Load server threads if authenticated.
				if (isAuthenticated()) {
					loadServerThreads();
				}

				// Check upload eligibility.
				checkUploadEligibility();
			})
			.catch(function () {
				// Auth failed — continue as anonymous.
				loadLocalThreads();
				try {
					threadId = localStorage.getItem(THREAD_KEY) || null;
				} catch (e) {}
				if (threadId) {
					isContinuing = true;
		loadThreadMessages(threadId);
				}
				checkUploadEligibility();
			});

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
