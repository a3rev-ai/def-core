/**
 * Digital Employee Framework - Customer Chat Loader
 * Phase 8 Sub-PR A: Lightweight loader enqueued on every frontend page.
 *
 * Creates a Shadow DOM host, renders a CSS-only floating trigger button,
 * and lazy-loads the full chat module on first click.
 * No chat functionality — Sub-PR B adds the chat engine.
 */
(function () {
	'use strict';

	// ─── Constants ──────────────────────────────────────────────────

	var HOST_ID = 'def-customer-chat-host';
	var STORAGE_KEY = 'def:customer-chat:state';
	var HIDE_DURATION_MS = 24 * 60 * 60 * 1000; // 24 hours

	// ─── Config ─────────────────────────────────────────────────────

	var config = window.DEFCore || {};

	// Abort if widget was already initialised (SPA double-load guard).
	if (document.getElementById(HOST_ID)) {
		return;
	}

	// ─── State ──────────────────────────────────────────────────────

	var shadowRoot = null;
	var trigger = null;
	var panel = null;
	var backdrop = null;
	var isOpen = false;
	var moduleLoaded = false;
	var moduleLoading = false;
	var preloaded = false;
	var abortController = null;

	// ─── localStorage helpers ───────────────────────────────────────

	function getStoredState() {
		try {
			var raw = localStorage.getItem(STORAGE_KEY);
			if (!raw) return null;
			var data = JSON.parse(raw);
			if (data.open === false && data.hiddenUntil) {
				if (Date.now() < data.hiddenUntil) {
					return false; // still hidden
				}
				localStorage.removeItem(STORAGE_KEY);
				return null; // expired
			}
			return data.open;
		} catch (e) {
			return null;
		}
	}

	function setStoredState(open) {
		try {
			if (open) {
				localStorage.setItem(STORAGE_KEY, JSON.stringify({ open: true }));
			} else {
				localStorage.setItem(
					STORAGE_KEY,
					JSON.stringify({
						open: false,
						hiddenUntil: Date.now() + HIDE_DURATION_MS,
					})
				);
			}
		} catch (e) {
			// localStorage not available — degrade silently.
		}
	}

	// ─── Shadow DOM setup ───────────────────────────────────────────

	function createShadowHost() {
		var host = document.createElement('div');
		host.id = HOST_ID;
		host.setAttribute('aria-live', 'polite');
		document.body.appendChild(host);

		shadowRoot = host.attachShadow({ mode: 'open' });

		// Inject minimal trigger button CSS inline (full chat CSS loaded lazily).
		var style = document.createElement('style');
		style.textContent = getTriggerCSS();
		shadowRoot.appendChild(style);
	}

	function getTriggerCSS() {
		var pos = config.buttonPosition === 'left' ? 'left' : 'right';
		var opp = pos === 'left' ? 'right' : 'left';
		var btnColor = config.buttonColor || '#111827';
		var hoverColor = config.buttonHoverColor || btnColor;

		return (
			'*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }' +
			'.def-cc-trigger {' +
			'  position: fixed; bottom: 16px; ' +
			pos +
			': 16px; ' +
			opp +
			': auto;' +
			'  z-index: 999998;' +
			'  display: inline-flex; align-items: center; gap: 8px;' +
			'  width: fit-content; width: -moz-fit-content;' +
			'  padding: 10px 14px; border: none; border-radius: 24px;' +
			'  background: ' +
			btnColor +
			'; color: #fff;' +
			'  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;' +
			'  font-size: 14px; font-weight: 500; line-height: 1;' +
			'  cursor: pointer;' +
			'  box-shadow: 0 6px 20px rgba(0,0,0,0.25);' +
			'  transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;' +
			'  -webkit-tap-highlight-color: transparent;' +
			'}' +
			'.def-cc-trigger:hover {' +
			'  background: ' +
			hoverColor +
			';' +
			'  box-shadow: 0 10px 28px rgba(0,0,0,0.3);' +
			'  transform: translateY(-1px);' +
			'}' +
			'.def-cc-trigger:focus-visible {' +
			'  outline: 2px solid #3b82f6; outline-offset: 2px;' +
			'}' +
			'.def-cc-trigger--hidden { display: none !important; }' +
			'.def-cc-trigger-icon { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; }' +
			'.def-cc-trigger-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }' +
			'.def-cc-trigger-icon img { width: 20px; height: 20px; object-fit: contain; border-radius: 4px; }' +
			'.def-cc-trigger-icon--sparkle svg { fill: currentColor; stroke: none; }' +
			'.def-cc-trigger-icon--sparkle.def-cc-sparkle-intro svg { animation: def-cc-sparkle-entrance 1s ease-out 0.3s 5; }' +
			'.def-cc-trigger:hover .def-cc-trigger-icon--sparkle svg { animation: def-cc-sparkle 0.6s ease-in-out; }' +
			'@keyframes def-cc-sparkle-entrance { 0% { transform: scale(0) rotate(-30deg); opacity: 0; } 50% { transform: scale(1.3) rotate(10deg); opacity: 1; } 75% { transform: scale(0.9) rotate(-3deg); } 100% { transform: scale(1) rotate(0deg); opacity: 1; } }' +
			'@keyframes def-cc-sparkle { 0% { transform: scale(1) rotate(0deg); } 40% { transform: scale(1.2) rotate(12deg); } 70% { transform: scale(0.95) rotate(-4deg); } 100% { transform: scale(1) rotate(0deg); } }' +
			'.def-cc-trigger-dot { display: inline-block; width: 8px; height: 8px; background: #10b981; border-radius: 9999px; flex-shrink: 0; }' +
			/* Backdrop (drawer mode — invisible but clickable) */
			'.def-cc-backdrop {' +
			'  position: fixed; top: 0; left: 0; right: 0; bottom: 0;' +
			'  z-index: 999997; background: transparent;' +
			'  pointer-events: none;' +
			'}' +
			'.def-cc-backdrop--visible { pointer-events: auto; }' +
			/* Panel shell (empty until module loads) */
			'.def-cc-panel {' +
			'  position: fixed; z-index: 999999;' +
			'  display: flex; flex-direction: column;' +
			'  background: #fff;' +
			'  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;' +
			'  font-size: 14px; line-height: 1.5; color: #1f2937;' +
			'  overflow: hidden;' +
			'  transform: translateY(24px); opacity: 0; pointer-events: none;' +
			'  transition: transform 0.25s ease, opacity 0.25s ease;' +
			'}' +
			'.def-cc-panel--open {' +
			'  transform: translateY(0); opacity: 1; pointer-events: auto;' +
			'}' +
			/* Close button — positioned by header flex layout once chat module loads */
			'.def-cc-panel-close {' +
			'  width: 32px; height: 32px; border: none; border-radius: 6px;' +
			'  background: transparent; color: #374151; cursor: pointer;' +
			'  display: flex; align-items: center; justify-content: center;' +
			'  transition: background 0.15s ease, color 0.15s ease;' +
			'}' +
			'.def-cc-panel-close:hover { background: #e5e7eb; color: #111827; }' +
			'.def-cc-panel-close svg { width: 20px; height: 20px; stroke: currentColor; stroke-width: 2.5; fill: none; }' +
			/* Modal mode */
			'.def-cc-shell--modal {' +
			'  bottom: 64px; ' +
			pos +
			': 16px; ' +
			opp +
			': auto;' +
			'  width: min(450px, 92vw); height: min(560px, 78vh);' +
			'  border-radius: 16px;' +
			'  box-shadow: 0 24px 64px rgba(0,0,0,0.25);' +
			'}' +
			/* Drawer mode */
			'.def-cc-shell--drawer {' +
			'  top: 0; ' +
			pos +
			': 0; bottom: 0;' +
			'  width: 400px;' +
			(pos === 'left'
				? '  border-radius: 0 16px 16px 0;'
				: '  border-radius: 16px 0 0 16px;') +
			'  border-' + opp + ': 1px solid rgba(0,0,0,0.08);' +
			'  box-shadow: ' + (pos === 'left' ? '6px' : '-6px') + ' 0 24px rgba(0,0,0,0.12);' +
			'  transform: translate' +
			(pos === 'left' ? 'X(-100%)' : 'X(100%)') +
			'; opacity: 1;' +
			'}' +
			'.def-cc-shell--drawer.def-cc-panel--open {' +
			'  transform: translateX(0);' +
			'}' +
			'.def-cc-shell--drawer.def-cc-admin-bar { top: 32px; }' +
			/* Loading spinner */
			'.def-cc-loading {' +
			'  display: flex; align-items: center; justify-content: center;' +
			'  flex: 1; gap: 8px; color: #9ca3af; font-size: 14px;' +
			'}' +
			'.def-cc-loading-spinner {' +
			'  width: 24px; height: 24px;' +
			'  border: 3px solid #e5e7eb; border-top-color: #3b82f6;' +
			'  border-radius: 50%;' +
			'  animation: def-cc-spin 0.8s linear infinite;' +
			'}' +
			'@keyframes def-cc-spin { to { transform: rotate(360deg); } }' +
			/* Mobile */
			'@media (max-width: 480px) {' +
			'  .def-cc-trigger { padding: 10px 12px; }' +
			'  .def-cc-shell--modal { width: calc(100vw - 24px); height: min(80vh, 620px); ' +
			pos +
			': 12px; bottom: 60px; }' +
			'  .def-cc-shell--drawer { width: 100vw; border-radius: 0; }' +
			'}' +
			/* Reduced motion */
			'@media (prefers-reduced-motion: reduce) {' +
			'  .def-cc-panel, .def-cc-trigger, .def-cc-backdrop { transition: none; }' +
			'  .def-cc-loading-spinner, .def-cc-trigger-icon--sparkle svg, .def-cc-trigger:hover .def-cc-trigger-icon--sparkle svg { animation: none; }' +
			'}'
		);
	}

	// ─── Trigger button ─────────────────────────────────────────────

	function createTrigger() {
		// wp_localize_script converts booleans to strings: "1" or "".
		if (config.showFloatingButton === false || config.showFloatingButton === '' || config.showFloatingButton === '0') {
			return;
		}

		trigger = document.createElement('button');
		trigger.className = 'def-cc-trigger';
		trigger.type = 'button';
		trigger.setAttribute('aria-label', 'Open chat');
		trigger.setAttribute('aria-expanded', 'false');

		// Icon
		var iconWrap = document.createElement('span');
		iconWrap.className = 'def-cc-trigger-icon';

		if (config.buttonIcon === 'custom' && config.buttonIconUrl) {
			var img = document.createElement('img');
			img.src = config.buttonIconUrl;
			img.alt = '';
			img.setAttribute('aria-hidden', 'true');
			iconWrap.appendChild(img);
		} else if (config.buttonIcon === 'headset') {
			iconWrap.innerHTML =
				'<svg viewBox="0 0 24 24"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>';
		} else if (config.buttonIcon === 'sparkle') {
			iconWrap.classList.add('def-cc-trigger-icon--sparkle', 'def-cc-sparkle-intro');
			iconWrap.addEventListener('animationend', function () {
				iconWrap.classList.remove('def-cc-sparkle-intro');
			});
			iconWrap.innerHTML =
				'<svg viewBox="0 0 24 24" fill="currentColor" stroke="none">' +
				'<path d="M12 2C12.7 6.3 13.2 8.2 15 10C16.8 11.8 18.7 12.3 23 13C18.7 13.7 16.8 14.2 15 16C13.2 17.8 12.7 19.7 12 24C11.3 19.7 10.8 17.8 9 16C7.2 14.2 5.3 13.7 1 13C5.3 12.3 7.2 11.8 9 10C10.8 8.2 11.3 6.3 12 2Z"/>' +
				'<path d="M20 1C20.3 2.6 20.5 3.2 21 3.7C21.5 3.2 21.7 2.6 22 1C21.7 2.6 21.5 3.2 21 3.7C20.5 3.2 20.3 2.6 20 1Z"/>' +
				'<path d="M3 19C3.2 20 3.4 20.4 3.7 20.7C4 20.4 4.2 20 4.4 19C4.2 20 4 20.4 3.7 20.7C3.4 20.4 3.2 20 3 19Z"/>' +
				'</svg>';
		} else {
			// Default: chat bubble
			iconWrap.innerHTML =
				'<svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
		}

		trigger.appendChild(iconWrap);

		// Label
		var label = document.createElement('span');
		label.className = 'def-cc-trigger-label';
		label.textContent = config.buttonLabel || 'Chat';
		trigger.appendChild(label);

		// Status dot
		var dot = document.createElement('span');
		dot.className = 'def-cc-trigger-dot';
		dot.setAttribute('aria-hidden', 'true');
		trigger.appendChild(dot);

		shadowRoot.appendChild(trigger);

		// Event listeners
		trigger.addEventListener('click', togglePanel);

		// Hover preload (V1.2 spec §4.1)
		trigger.addEventListener('mouseenter', preloadModule, { once: true });
		trigger.addEventListener('touchstart', preloadModule, {
			once: true,
			passive: true,
		});
	}

	// ─── Header button listener ─────────────────────────────────────

	function initHeaderButtons() {
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-def-chat-trigger]');
			if (btn) {
				e.preventDefault();
				togglePanel();
			}
		});
	}

	// ─── Preload ────────────────────────────────────────────────────

	function preloadModule() {
		if (preloaded || moduleLoaded || !config.chatModuleUrl) {
			return;
		}
		preloaded = true;
		// Warm browser HTTP cache — fire and forget.
		fetch(config.chatModuleUrl, { method: 'GET', mode: 'no-cors' }).catch(
			function () {}
		);
	}

	// ─── Panel shell ────────────────────────────────────────────────

	function createPanel() {
		var mode = config.chatDisplayMode === 'drawer' ? 'drawer' : 'modal';
		var isDrawer = mode === 'drawer';

		// Backdrop for drawer mode (click to close).
		if (isDrawer) {
			backdrop = document.createElement('div');
			backdrop.className = 'def-cc-backdrop';
			backdrop.addEventListener('click', closePanel);
			shadowRoot.appendChild(backdrop);
		}

		panel = document.createElement('div');
		panel.className = 'def-cc-panel def-cc-shell--' + mode;
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-label', 'Chat');

		// Position class
		if (config.buttonPosition === 'left') {
			panel.classList.add('def-cc-position-left');
		}

		// Admin bar offset (drawer mode)
		if (isDrawer && document.getElementById('wpadminbar')) {
			panel.classList.add('def-cc-admin-bar');
		}

		// Close button (X) inside panel header.
		var closeBtn = document.createElement('button');
		closeBtn.className = 'def-cc-panel-close';
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', 'Close chat');
		closeBtn.innerHTML =
			'<svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
		closeBtn.addEventListener('click', closePanel);
		panel.appendChild(closeBtn);

		// Loading state
		var loading = document.createElement('div');
		loading.className = 'def-cc-loading';
		loading.innerHTML =
			'<div class="def-cc-loading-spinner"></div><span>Loading chat...</span>';
		panel.appendChild(loading);

		shadowRoot.appendChild(panel);
	}

	// ─── Open / Close ───────────────────────────────────────────────

	function togglePanel() {
		if (isOpen) {
			closePanel();
		} else {
			openPanel();
		}
	}

	function openPanel() {
		if (!panel) {
			createPanel();
		}

		var isDrawer = config.chatDisplayMode === 'drawer';

		isOpen = true;
		panel.classList.add('def-cc-panel--open');

		// Show backdrop (drawer mode).
		if (isDrawer && backdrop) {
			backdrop.classList.add('def-cc-backdrop--visible');
		}

		// Hide trigger in drawer mode (it's behind the drawer anyway).
		if (isDrawer && trigger) {
			trigger.classList.add('def-cc-trigger--hidden');
		}

		if (trigger) {
			trigger.setAttribute('aria-expanded', 'true');
		}

		setStoredState(true);

		// Lazy load chat module on first open.
		if (!moduleLoaded && !moduleLoading) {
			loadChatModule();
		}

		// iOS scroll lock
		if (isMobile()) {
			panel.classList.add('def-cc-panel--mobile-open');
		}
	}

	function closePanel() {
		if (!panel) return;

		var isDrawer = config.chatDisplayMode === 'drawer';

		isOpen = false;
		panel.classList.remove('def-cc-panel--open');
		panel.classList.remove('def-cc-panel--mobile-open');

		// Hide backdrop.
		if (isDrawer && backdrop) {
			backdrop.classList.remove('def-cc-backdrop--visible');
		}

		// Show trigger again.
		if (isDrawer && trigger) {
			trigger.classList.remove('def-cc-trigger--hidden');
		}

		if (trigger) {
			trigger.setAttribute('aria-expanded', 'false');
			trigger.focus();
		}

		setStoredState(false);
	}

	// ─── Lazy load chat module ──────────────────────────────────────

	function loadChatModule() {
		if (!config.chatModuleUrl) {
			return;
		}

		moduleLoading = true;

		// Inject full chat CSS into shadow root.
		if (config.chatStyleUrl) {
			var link = document.createElement('link');
			link.rel = 'stylesheet';
			link.href = config.chatStyleUrl;
			shadowRoot.insertBefore(link, shadowRoot.firstChild);
		}

		// Sequential script loading: marked.js → purify.js → chat module.
		// Each onload triggers the next since chat module needs window.marked
		// and window.DOMPurify available at init time.
		loadScript(config.markedUrl, function () {
			loadScript(config.purifyUrl, function () {
				loadScript(config.chatModuleUrl, function () {
					moduleLoading = false;
					moduleLoaded = true;

					if (
						window.DEFCustomerChat &&
						typeof window.DEFCustomerChat.init === 'function'
					) {
						window.DEFCustomerChat.init(shadowRoot, config);
					}
				});
			});
		});
	}

	/**
	 * Load a script tag sequentially.
	 *
	 * @param {string|null} url Script URL to load.
	 * @param {Function} onSuccess Callback on successful load.
	 */
	function loadScript(url, onSuccess) {
		if (!url) {
			// Skip missing vendor URLs — degrade gracefully.
			onSuccess();
			return;
		}

		var script = document.createElement('script');
		script.src = url;

		script.onload = onSuccess;

		script.onerror = function () {
			moduleLoading = false;
			var loading = panel && panel.querySelector('.def-cc-loading');
			if (loading) {
				loading.innerHTML =
					'<span style="color:#ef4444;">Failed to load chat. Please refresh the page.</span>';
			}
		};

		document.head.appendChild(script);
	}

	// ─── Event listeners ────────────────────────────────────────────

	function bindEvents() {
		abortController = new AbortController();
		var signal = abortController.signal;

		// Escape key closes panel.
		document.addEventListener(
			'keydown',
			function (e) {
				if (e.key === 'Escape' && isOpen) {
					closePanel();
				}
			},
			{ signal: signal }
		);

		// Click outside closes panel (modal mode — drawer uses backdrop).
		document.addEventListener(
			'click',
			function (e) {
				if (!isOpen || config.chatDisplayMode === 'drawer') {
					return;
				}
				var host = document.getElementById(HOST_ID);
				if (host && !host.contains(e.target)) {
					// Also check header trigger buttons.
					if (!e.target.closest('[data-def-chat-trigger]')) {
						closePanel();
					}
				}
			},
			{ signal: signal }
		);
	}

	// ─── Helpers ────────────────────────────────────────────────────

	function isMobile() {
		return window.innerWidth <= 480;
	}

	// ─── Destroy (for SPA cleanup) ──────────────────────────────────

	function destroy() {
		if (abortController) {
			abortController.abort();
			abortController = null;
		}

		var host = document.getElementById(HOST_ID);
		if (host) {
			host.remove();
		}

		shadowRoot = null;
		trigger = null;
		panel = null;
		backdrop = null;
		isOpen = false;
		moduleLoaded = false;
		moduleLoading = false;
		preloaded = false;
	}

	// Expose destroy for SPA/PJAX navigations.
	window.DEFCustomerChatLoader = { destroy: destroy };

	// ─── Init ───────────────────────────────────────────────────────

	function init() {
		createShadowHost();
		createTrigger();
		initHeaderButtons();
		bindEvents();

		// Restore open state from previous page view.
		var stored = getStoredState();
		if (stored === true) {
			openPanel();
		}
	}

	// Wait for DOM ready.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
