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
			/* Close button inside panel */
			'.def-cc-panel-close {' +
			'  position: absolute; top: 10px; right: 10px; z-index: 10;' +
			'  width: 36px; height: 36px; border: none; border-radius: 50%;' +
			'  background: #f3f4f6; color: #374151; cursor: pointer;' +
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
			'  .def-cc-loading-spinner { animation: none; }' +
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
		} else {
			// Default: chat bubble
			iconWrap.innerHTML =
				'<svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
		}

		trigger.appendChild(iconWrap);

		// Label
		var label = document.createElement('span');
		label.className = 'def-cc-trigger-label';
		label.textContent = 'Chat';
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

		// Load the chat module script (runs in document scope).
		var script = document.createElement('script');
		script.src = config.chatModuleUrl;

		script.onload = function () {
			moduleLoading = false;
			moduleLoaded = true;

			// Sub-PR B will expose window.DEFCustomerChat.init().
			if (
				window.DEFCustomerChat &&
				typeof window.DEFCustomerChat.init === 'function'
			) {
				window.DEFCustomerChat.init(shadowRoot, config);
			}
		};

		script.onerror = function () {
			moduleLoading = false;
			// Show error in panel.
			var loading = panel.querySelector('.def-cc-loading');
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
