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
	// 8.5.0: a question handed over by a trigger before the chat module was ready.
	var pendingPrompt = null;
	// 8.7.1: the element that opened the chat; closing hands focus back to it.
	var returnFocusTo = null;
	// 8.7.1: the page's own inline overflow styles, held while the chat locks its scroll.
	var scrollLock = null;

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

	// --def-cc-primary feeds a color-mix() for the focus ring, so an invalid
	// override computes the ring away entirely instead of falling back.
	// Registering the property makes the browser reject a bad value at the
	// custom-property level and use initial-value instead. This has to run
	// against the document: an @property inside a shadow root is ignored, so
	// it cannot sit with the other tokens in def-core-customer-chat.css.
	function registerThemeProps() {
		var reg = document.createElement('style');
		reg.textContent =
			'@property --def-cc-primary {' +
			'  syntax: "<color>"; inherits: true; initial-value: #1d4ed8;' +
			'}';
		document.head.appendChild(reg);
	}

	// Mirror of the chat module's partsFrom() — see def-core-customer-chat.js.
	// Part values must always be literals; never pass config or stream data.
	function partsFrom(className) {
		return className.replace(/(^|\s)(?:def-)?cc-/g, '$1');
	}

	function setClass(node, className) {
		node.className = className;
		node.setAttribute('part', partsFrom(className));
	}

	// Rebuilt from the class list, not via the reflected Element.part
	// DOMTokenList — that property is much younger than ::part() itself
	// (Safari 16.4 vs 13.1) and would throw on older iOS during bootstrap.
	function setState(node, className, on) {
		if (!node) return;
		node.classList.toggle(className, on);
		node.setAttribute('part', partsFrom(node.className));
	}

	function createShadowHost() {
		registerThemeProps();

		var host = document.createElement('div');
		host.id = HOST_ID;
		host.setAttribute('aria-live', 'polite');
		document.body.appendChild(host);

		shadowRoot = host.attachShadow({ mode: 'open' });

		// Brand the whole widget from the tenant's button colour, not just the
		// launcher. Injected as --def-cc-brand rather than --def-cc-primary: an
		// inline custom property beats every stylesheet rule, so writing primary
		// here made it unthemable. The stylesheet derives primary/btn-color from
		// brand, so the admin's colour still flows AND a theme can override.
		var accent = config.buttonColor || '#111827';
		host.style.setProperty('--def-cc-brand', accent);
		host.style.setProperty('--def-cc-brand-hover', config.buttonHoverColor || accent);

		// Inject minimal trigger button CSS inline (full chat CSS loaded lazily).
		var style = document.createElement('style');
		style.textContent = getTriggerCSS();
		shadowRoot.appendChild(style);
	}

	// Defense-in-depth clamp for admin-configured dimensions. PHP
	// sanitizers already clamp on save and the values are emitted
	// via wp_localize_script as JSON numbers (not strings) thanks to
	// the (int) cast at the PHP boundary — re-clamp at runtime
	// anyway to guard against future drift, future code paths that
	// might emit strings, or external config injection.
	function clampInt(value, min, max, fallback) {
		var n = parseInt(value, 10);
		if (isNaN(n)) return fallback;
		if (n < min) return min;
		if (n > max) return max;
		return n;
	}

	function getTriggerCSS() {
		var pos = config.buttonPosition === 'left' ? 'left' : 'right';
		var opp = pos === 'left' ? 'right' : 'left';
		// Literals kept ONLY as var() fallbacks — the tokens are the source of truth.
		var btnColor = config.buttonColor || '#111827';
		var hoverColor = config.buttonHoverColor || btnColor;
		// Drawer width is admin-configurable (300–600); previously hard-coded
		// to 400 in the CSS. Spotlight width/height are admin-configurable
		// (600–1200 × 500–800). The min(...) on-screen clamp inside each CSS
		// rule below protects against overflow on smaller viewports.
		var drawerWidth     = clampInt(config.chatDrawerWidth,    300,  600, 400);
		var spotlightWidth  = clampInt(config.chatSpotlightWidth, 600, 1200, 960);
		var spotlightHeight = clampInt(config.chatSpotlightHeight, 500,  800, 600);

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
			/* No length cap on the label (5.7.11): the launcher lives in a
			   shadow root, so the document-level trigger CSS cannot reach it —
			   the containment must live HERE. A long label ellipsizes inside a
			   viewport-bounded button instead of growing across the screen. */
			'  max-width: min(320px, calc(100vw - 32px));' +
			'  padding: 10px 14px; border: none; border-radius: 24px;' +
			'  background: var(--def-cc-btn-color, ' +
			btnColor +
			'); color: #fff;' +
			'  font-family: var(--def-cc-font, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif);' +
			'  font-size: var(--def-cc-font-size, 14px); font-weight: var(--def-cc-font-weight-medium, 500); line-height: 1;' +
			'  cursor: pointer;' +
			'  box-shadow: 0 6px 20px rgba(0,0,0,0.25);' +
			'  transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;' +
			'  -webkit-tap-highlight-color: transparent;' +
			'}' +
			'.def-cc-trigger-label {' +
			/* The ellipsis must live ON the label span: text-overflow on a flex
			   container never reaches a flex item, and without min-width: 0 the
			   item refuses to shrink below its content. */
			'  min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;' +
			'}' +
			'.def-cc-trigger:hover {' +
			'  background: var(--def-cc-btn-hover, ' +
			hoverColor +
			');' +
			'  box-shadow: 0 10px 28px rgba(0,0,0,0.3);' +
			'  transform: translateY(-1px);' +
			'}' +
			'.def-cc-trigger:focus-visible {' +
			'  outline: 2px solid #3b82f6; outline-offset: 2px;' +
			'}' +
			'.def-cc-trigger--hidden { display: none !important; }' +
			'.def-cc-trigger-icon { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; }' +
			'.def-cc-trigger-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }' +
			'.def-cc-trigger-icon img { width: 20px; height: 20px; object-fit: contain; border-radius: var(--def-cc-radius-xs, 4px); }' +
			'.def-cc-trigger-icon--sparkle svg { fill: currentColor; stroke: none; }' +
			'.def-cc-trigger-icon--sparkle.def-cc-sparkle-intro svg { animation: def-cc-sparkle-entrance 1s ease-out 0.3s 5; }' +
			'.def-cc-trigger:hover .def-cc-trigger-icon--sparkle svg { animation: def-cc-sparkle 0.6s ease-in-out; }' +
			'@keyframes def-cc-sparkle-entrance { 0% { transform: scale(0) rotate(-30deg); opacity: 0; } 50% { transform: scale(1.3) rotate(10deg); opacity: 1; } 75% { transform: scale(0.9) rotate(-3deg); } 100% { transform: scale(1) rotate(0deg); opacity: 1; } }' +
			'@keyframes def-cc-sparkle { 0% { transform: scale(1) rotate(0deg); } 40% { transform: scale(1.2) rotate(12deg); } 70% { transform: scale(0.95) rotate(-4deg); } 100% { transform: scale(1) rotate(0deg); } }' +
			'.def-cc-trigger-dot { display: inline-block; width: 8px; height: 8px; background: #10b981; border-radius: 9999px; flex-shrink: 0; }' +
			/* Backdrop. Transparent by default (drawer mode, click-to-close).
			 * Spotlight mode adds .def-cc-backdrop--dim for a visible scrim. */
			'.def-cc-backdrop {' +
			'  position: fixed; top: 0; left: 0; right: 0; bottom: 0;' +
			'  z-index: 999997; background: transparent;' +
			'  pointer-events: none;' +
			'  transition: background 0.25s ease;' +
			'}' +
			'.def-cc-backdrop--visible { pointer-events: auto; }' +
			'.def-cc-backdrop--dim.def-cc-backdrop--visible {' +
			'  background: rgba(0, 0, 0, 0.5);' +
			'}' +
			/* Panel shell (empty until module loads). The transform/opacity
			 * here is the closed-state default for Modal mode. Mode shells
			 * below (--drawer / --spotlight) override transform via source
			 * order — keep this rule above them or the entrance animation
			 * for non-Modal modes will break. */
			'.def-cc-panel {' +
			'  position: fixed; z-index: 999999;' +
			'  display: flex; flex-direction: column;' +
			'  background: var(--def-cc-surface, #fff);' +
			'  font-family: var(--def-cc-font, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif);' +
			'  font-size: var(--def-cc-font-size, 14px); line-height: var(--def-cc-line-height, 1.5); color: var(--def-cc-text, #1f2937);' +
			'  overflow: hidden;' +
			'  transform: translateY(24px); opacity: 0; pointer-events: none;' +
			'  transition: transform 0.25s ease, opacity 0.25s ease;' +
			'}' +
			'.def-cc-panel--open {' +
			'  transform: translateY(0); opacity: 1; pointer-events: auto;' +
			'}' +
			/* 8.7.1: the panel takes focus itself (tabindex="-1") while the chat
			   module loads. It is a container, not a control, so it keeps the look
			   it always had rather than growing a ring round the whole chat. */
			'.def-cc-panel:focus { outline: none; }' +
			/* Close button — positioned by header flex layout once chat module loads */
			'.def-cc-panel-close {' +
			'  width: 32px; height: 32px; border: none; border-radius: var(--def-cc-radius-control, 6px);' +
			'  background: transparent; color: var(--def-cc-header-icon, #374151); cursor: pointer;' +
			'  display: flex; align-items: center; justify-content: center;' +
			'  transition: background 0.15s ease, color 0.15s ease;' +
			'}' +
			'.def-cc-panel-close:hover { background: var(--def-cc-header-icon-hover-bg, #e5e7eb); color: var(--def-cc-header-icon-hover, #111827); }' +
			'.def-cc-panel-close svg { width: 20px; height: 20px; stroke: currentColor; stroke-width: 2.5; fill: none; }' +
			/* Modal mode */
			'.def-cc-shell--modal {' +
			'  bottom: 64px; ' +
			pos +
			': 16px; ' +
			opp +
			': auto;' +
			'  width: min(450px, 92vw); height: min(560px, 78vh);' +
			'  border-radius: var(--def-cc-radius-lg, 16px);' +
			'  box-shadow: var(--def-cc-shadow, 0 24px 64px rgba(0,0,0,0.25));' +
			'}' +
			/* Drawer mode */
			'.def-cc-shell--drawer {' +
			'  top: 0; ' +
			pos +
			': 0; bottom: 0;' +
			'  width: min(' + drawerWidth + 'px, 100vw);' +
			(pos === 'left'
				? '  border-radius: 0 var(--def-cc-radius-lg, 16px) var(--def-cc-radius-lg, 16px) 0;'
				: '  border-radius: var(--def-cc-radius-lg, 16px) 0 0 var(--def-cc-radius-lg, 16px);') +
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
			/* Spotlight mode — large centered overlay with dimmed backdrop.
			 * Like the WC Cart Block / Bunnings "Buddy" full-attention
			 * pattern. Sized from admin options (clamped above) and further
			 * constrained to 92vw × 85vh so it never overflows on smaller
			 * desktops. Transform-origin centered for a subtle scale-in. */
			'.def-cc-shell--spotlight {' +
			'  top: 50%; left: 50%; right: auto; bottom: auto;' +
			'  width: min(' + spotlightWidth + 'px, 92vw);' +
			'  height: min(' + spotlightHeight + 'px, 85vh);' +
			'  border-radius: var(--def-cc-radius-lg, 16px);' +
			'  box-shadow: 0 24px 64px rgba(0,0,0,0.35);' +
			'  transform: translate(-50%, calc(-50% + 24px)); opacity: 0;' +
			'}' +
			'.def-cc-shell--spotlight.def-cc-panel--open {' +
			'  transform: translate(-50%, -50%); opacity: 1;' +
			'}' +
			/* Loading spinner */
			'.def-cc-loading {' +
			'  display: flex; align-items: center; justify-content: center;' +
			'  flex: 1; gap: 8px; color: #9ca3af; font-size: var(--def-cc-font-size, 14px);' +
			'}' +
			'.def-cc-loading-spinner {' +
			'  width: 24px; height: 24px;' +
			'  border: 3px solid #e5e7eb; border-top-color: #3b82f6;' +
			'  border-radius: 50%;' +
			'  animation: def-cc-spin 0.8s linear infinite;' +
			'}' +
			/* Tenant-supplied loading mark (T-D5) — rendered instead of the
			 * spinner when config.loadingMarkUrl is set. Size themable via
			 * --def-cc-loading-mark-size; the fallback applies during boot,
			 * before the chat stylesheet (and its :host default) has loaded. */
			'.def-cc-loading-mark {' +
			'  width: var(--def-cc-loading-mark-size, 48px);' +
			'  height: var(--def-cc-loading-mark-size, 48px);' +
			'  object-fit: contain;' +
			'}' +
			'@keyframes def-cc-spin { to { transform: rotate(360deg); } }' +
			/* Greeting Bubble (v3.12.0) — proactive pop-up above the launcher */
			'.def-cc-greeting-bubble {' +
			'  position: fixed; bottom: 72px; right: 16px; z-index: 999997;' +
			'  max-width: 280px;' +
			'  background: #ffffff; border-radius: 14px;' +
			'  box-shadow: 0 10px 28px rgba(0,0,0,0.18);' +
			'  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;' +
			'  font-size: 14px; line-height: 1.45; color: #1f2937;' +
			'  cursor: pointer;' +
			'  animation: def-cc-greeting-enter 0.3s ease both;' +
			'}' +
			/* 8.7.1: the "open chat" button fills the bubble — the padding lives
			   here, not on the box, so its focus ring traces the bubble's own
			   outline exactly as the box's ring did. Everything a button resets is
			   put back to what the box inherited, so the text renders unchanged. */
			'.def-cc-greeting-bubble-open {' +
			'  display: block; width: 100%; padding: 14px 16px;' +
			'  -webkit-appearance: none; appearance: none;' +
			'  border: 0; border-radius: inherit; background: none; color: inherit;' +
			'  font: inherit; letter-spacing: inherit; word-spacing: inherit;' +
			'  text-align: inherit; text-indent: inherit; text-transform: inherit; text-shadow: inherit;' +
			'  cursor: pointer;' +
			'}' +
			/* No length cap on the greeting text (5.7.11): a long greeting
			   scrolls INSIDE this wrapper, never past the viewport top. The
			   scroll cannot live on the bubble itself — the dismiss × overhangs
			   the bubble's corner, and absolute descendants of a scroll
			   container are clipped by it (#277 panel, box-model). */
			'.def-cc-greeting-bubble-scroll {' +
			'  display: flex; align-items: flex-start; gap: 10px;' +
			'  max-height: 60vh; overflow-y: auto;' +
			'}' +
			'.def-cc-greeting-bubble--left { right: auto; left: 16px; }' +
			'.def-cc-greeting-bubble-open:focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; }' +
			'.def-cc-greeting-bubble-logo { flex-shrink: 0; width: 32px; height: 32px; border-radius: 6px; overflow: hidden; }' +
			'.def-cc-greeting-bubble-logo img { width: 100%; height: 100%; object-fit: contain; display: block; }' +
			'.def-cc-greeting-bubble-text { flex: 1; white-space: pre-line; overflow-wrap: break-word; margin: 0; }' +
			'.def-cc-greeting-bubble-close {' +
			'  position: absolute; top: -8px; right: -8px;' +
			'  width: 22px; height: 22px; padding: 0;' +
			'  background: #ffffff; border: 1px solid #e5e7eb; border-radius: 50%;' +
			'  cursor: pointer; font-size: 14px; line-height: 1; color: #6b7280;' +
			'  display: flex; align-items: center; justify-content: center;' +
			'  box-shadow: 0 2px 6px rgba(0,0,0,0.1);' +
			'  opacity: 0; transition: opacity 0.15s ease;' +
			'}' +
			'.def-cc-greeting-bubble:hover .def-cc-greeting-bubble-close,' +
			'.def-cc-greeting-bubble-close:focus-visible { opacity: 1; }' +
			/* Tail pointing down to the launcher */
			'.def-cc-greeting-bubble::after {' +
			'  content: ""; position: absolute; bottom: -7px; right: 28px;' +
			'  width: 14px; height: 14px;' +
			'  background: #ffffff; transform: rotate(45deg);' +
			'  box-shadow: 3px 3px 6px rgba(0,0,0,0.04);' +
			'}' +
			'.def-cc-greeting-bubble--left::after { right: auto; left: 28px; }' +
			'@keyframes def-cc-greeting-enter {' +
			'  from { opacity: 0; transform: translateY(8px); }' +
			'  to { opacity: 1; transform: translateY(0); }' +
			'}' +
			/* Mobile */
			'@media (max-width: 480px) {' +
			'  .def-cc-trigger { padding: 10px 12px; }' +
			'  .def-cc-shell--modal { width: calc(100vw - 24px); height: min(80vh, 620px); ' +
			pos +
			': 12px; bottom: 60px; }' +
			'  .def-cc-shell--drawer { width: 100vw; border-radius: 0; }' +
			/* Spotlight goes full-screen on mobile — same intent as Modal. */
			'  .def-cc-shell--spotlight { top: 0; left: 0; width: 100vw; height: 100vh; border-radius: 0; transform: translate(0, 24px); }' +
			'  .def-cc-shell--spotlight.def-cc-panel--open { transform: translate(0, 0); }' +
			'  .def-cc-greeting-bubble { max-width: calc(100vw - 88px); font-size: 13px; }' +
			'  .def-cc-greeting-bubble-open { padding: 12px 14px; }' +
			'  .def-cc-greeting-bubble-close { opacity: 1; }' +
			'}' +
			/* Reduced motion */
			'@media (prefers-reduced-motion: reduce) {' +
			'  .def-cc-panel, .def-cc-trigger, .def-cc-backdrop, .def-cc-greeting-bubble-close { transition: none; }' +
			'  .def-cc-greeting-bubble, .def-cc-loading-spinner, .def-cc-trigger-icon--sparkle svg, .def-cc-trigger:hover .def-cc-trigger-icon--sparkle svg { animation: none; }' +
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
		setClass(trigger, 'def-cc-trigger');
		trigger.type = 'button';
		trigger.setAttribute('aria-label', 'Open chat');
		trigger.setAttribute('aria-expanded', 'false');

		// Icon
		var iconWrap = document.createElement('span');
		setClass(iconWrap, 'def-cc-trigger-icon');

		if (config.buttonIcon === 'custom' && config.buttonIconUrl) {
			// Its only styling is a descendant rule (.def-cc-trigger-icon img),
			// which ::part() cannot express — without its own part a tenant
			// using a custom launcher icon could not resize it at all.
			var img = document.createElement('img');
			img.setAttribute('part', 'trigger-icon-image');
			img.src = config.buttonIconUrl;
			img.alt = '';
			img.setAttribute('aria-hidden', 'true');
			iconWrap.appendChild(img);
		} else if (config.buttonIcon === 'headset') {
			iconWrap.innerHTML =
				'<svg viewBox="0 0 24 24"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>';
		} else if (config.buttonIcon === 'sparkle') {
			setState(iconWrap, 'def-cc-trigger-icon--sparkle', true);
			setState(iconWrap, 'def-cc-sparkle-intro', true);
			iconWrap.addEventListener('animationend', function () {
				setState(iconWrap, 'def-cc-sparkle-intro', false);
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
		setClass(label, 'def-cc-trigger-label');
		label.textContent = config.buttonLabel || 'Chat';
		trigger.appendChild(label);

		// Status dot
		var dot = document.createElement('span');
		setClass(dot, 'def-cc-trigger-dot');
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
				// 8.5.0: a trigger may carry the question to ask. "Ask Joe what Widrow
				// could do for you" opens the chat AND asks it, so the visitor gets an
				// answer, not an empty composer. Without a prompt, the old toggle.
				var prompt = (btn.getAttribute('data-def-chat-prompt') || '').trim();
				if (prompt) {
					askWhenReady(prompt);
				} else {
					togglePanel();
				}
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
		// Resolve display mode. Default to modal for unknown values.
		var mode;
		if (config.chatDisplayMode === 'drawer') {
			mode = 'drawer';
		} else if (config.chatDisplayMode === 'spotlight') {
			mode = 'spotlight';
		} else {
			mode = 'modal';
		}
		var isDrawer = mode === 'drawer';
		var isSpotlight = mode === 'spotlight';

		// Backdrop for drawer + spotlight modes (click-to-close).
		// Spotlight gets a visible dim via the --dim modifier; drawer's
		// backdrop stays transparent (just an interaction layer).
		if (isDrawer || isSpotlight) {
			backdrop = document.createElement('div');
			setClass(backdrop, 'def-cc-backdrop' + (isSpotlight ? ' def-cc-backdrop--dim' : ''));
			backdrop.addEventListener('click', closePanel);
			shadowRoot.appendChild(backdrop);
		}

		panel = document.createElement('div');
		setClass(panel, 'def-cc-panel def-cc-shell--' + mode);
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-label', 'Chat');
		// 8.7.1: modal in every display mode. Modal and spotlight say so in
		// their names. The drawer is modal too: its backdrop covers the whole
		// page and takes every click (a click there only closes the drawer),
		// the launcher is hidden while it is open, and on a phone it is the
		// full width of the screen — a pointer cannot use the page behind it,
		// so a keyboard or a screen reader must not either.
		panel.setAttribute('aria-modal', 'true');
		// Focus lands on the panel itself while the chat module is loading.
		panel.setAttribute('tabindex', '-1');
		// Closed, the panel is only faded out — without inert its controls
		// would stay in the Tab order and in the accessibility tree, invisible.
		panel.toggleAttribute('inert', true);

		// Position class
		if (config.buttonPosition === 'left') {
			setState(panel, 'def-cc-position-left', true);
		}

		// Admin bar offset (drawer mode)
		if (isDrawer && document.getElementById('wpadminbar')) {
			setState(panel, 'def-cc-admin-bar', true);
		}

		// Close button (X) inside panel header.
		var closeBtn = document.createElement('button');
		setClass(closeBtn, 'def-cc-panel-close');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', 'Close chat');
		closeBtn.innerHTML =
			'<svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
		closeBtn.addEventListener('click', closePanel);
		panel.appendChild(closeBtn);

		// Loading state. A tenant-supplied loading mark (T-D5) replaces the
		// CSS spinner when configured — built via DOM APIs, never innerHTML,
		// because the URL is config data.
		var loading = document.createElement('div');
		setClass(loading, 'def-cc-loading');
		if (config.loadingMarkUrl) {
			var mark = document.createElement('img');
			mark.className = 'def-cc-loading-mark';
			mark.setAttribute('part', 'loading-mark');
			mark.src = config.loadingMarkUrl;
			mark.alt = '';
			mark.setAttribute('aria-hidden', 'true');
			loading.appendChild(mark);
			var loadingText = document.createElement('span');
			loadingText.setAttribute('part', 'loading-text');
			loadingText.textContent = 'Loading chat...';
			loading.appendChild(loadingText);
		} else {
			loading.innerHTML =
				'<div class="def-cc-loading-spinner" part="loading-spinner"></div><span part="loading-text">Loading chat...</span>';
		}
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

	// 8.5.0: open the chat and ask the question once the module can take it. A second
	// click before the module is ready replaces the pending question, never queues two.
	function askWhenReady(text) {
		pendingPrompt = text;
		if (!isOpen) {
			openPanel();
		}
		// A panel left open by a failed load never retries on its own; a prompt trigger does.
		if (!moduleLoaded && !moduleLoading) {
			loadChatModule();
		}
		if (moduleLoaded) {
			flushPendingPrompt();
		}
	}

	function flushPendingPrompt() {
		if (!pendingPrompt) return;
		var text = pendingPrompt;
		pendingPrompt = null;
		if (window.DEFCustomerChat && typeof window.DEFCustomerChat.ask === 'function') {
			window.DEFCustomerChat.ask(text);
		}
	}

	function openPanel() {
		// 8.7.1: remember who opened the chat before anything moves — the
		// greeting bubble may be the opener, and it goes next.
		if (!isOpen) {
			returnFocusTo = focusedElement();
		}

		// Tear down the greeting bubble on any open path (launcher click, header
		// trigger, restored state) — opening the chat is engagement, no need to
		// re-prompt. Idempotent if the bubble isn't showing or was already dismissed.
		dismissGreetingBubble();

		if (!panel) {
			createPanel();
		}

		var isDrawer = config.chatDisplayMode === 'drawer';

		isOpen = true;
		// data-open means exactly "the chat is open" and nothing more. The
		// backdrop and trigger states below are drawer-only and the mobile
		// state is viewport-only, so they are NOT derivable from this
		// attribute — each carries its own part instead.
		shadowRoot.host.toggleAttribute('data-open', true);
		panel.toggleAttribute('inert', false);
		setState(panel, 'def-cc-panel--open', true);
		lockPageScroll();

		// Show backdrop (drawer mode).
		if (isDrawer && backdrop) {
			setState(backdrop, 'def-cc-backdrop--visible', true);
		}

		// Hide trigger in drawer mode (it's behind the drawer anyway).
		if (isDrawer && trigger) {
			setState(trigger, 'def-cc-trigger--hidden', true);
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
			setState(panel, 'def-cc-panel--mobile-open', true);
		}

		focusIntoPanel();
	}

	function closePanel() {
		if (!panel || !isOpen) return;

		var isDrawer = config.chatDisplayMode === 'drawer';
		// 8.7.1: read before inert blurs the panel. Focus the visitor has put on
		// the page — a link or field they clicked to dismiss the chat — stays
		// there; only focus the chat held, or lost, goes back to the opener.
		var active = document.activeElement;
		var restoreFocus = !active || active === document.body || active === shadowRoot.host;

		isOpen = false;
		shadowRoot.host.toggleAttribute('data-open', false);
		setState(panel, 'def-cc-panel--open', false);
		setState(panel, 'def-cc-panel--mobile-open', false);
		panel.toggleAttribute('inert', true);
		unlockPageScroll();

		// Hide backdrop.
		if (isDrawer && backdrop) {
			setState(backdrop, 'def-cc-backdrop--visible', false);
		}

		// Show trigger again.
		if (isDrawer && trigger) {
			setState(trigger, 'def-cc-trigger--hidden', false);
		}

		if (trigger) {
			trigger.setAttribute('aria-expanded', 'false');
		}

		// Back to whoever opened the chat. The launcher stands in when there
		// was no opener (a chat restored open on page load), it has left the
		// page (the greeting bubble, removed as the chat opened), or it cannot
		// take focus now (a menu item in a phone menu that closed behind it).
		var opener = returnFocusTo;
		returnFocusTo = null;
		if (restoreFocus) {
			if (opener && opener.isConnected) {
				opener.focus();
			}
			if (trigger && (!opener || focusedElement() !== opener)) {
				trigger.focus();
			}
		}

		setStoredState(false);
		pendingPrompt = null; // closing the chat cancels a question still waiting for the module
	}

	// ─── Focus and scroll while open (8.7.1) ────────────────────────

	// The element that has focus, looking through shadow roots — inside the
	// widget document.activeElement is only ever the host.
	function focusedElement() {
		var el = document.activeElement;
		while (el && el.shadowRoot && el.shadowRoot.activeElement) {
			el = el.shadowRoot.activeElement;
		}
		return el === document.body ? null : el;
	}

	// The composer when the chat module has built it and it can take focus,
	// otherwise the panel itself. On the first open the module is still
	// loading here; its init() focuses the composer once it has built it
	// (def-core-customer-chat.js), which moves focus on from the panel.
	function focusIntoPanel() {
		var input = panel.querySelector('.def-cc-composer-input');
		var target = input && isTabStop(input) ? input : panel;
		target.focus({ preventScroll: true });
	}

	var TAB_STOP_SELECTOR =
		'a[href], area[href], button, input, select, textarea, summary, iframe,' +
		' audio[controls], video[controls], [contenteditable], [tabindex]';

	function isTabStop(el) {
		if (el.disabled || el.tabIndex < 0 || el.closest('[inert]')) return false;
		if (el.type === 'hidden' || el.getAttribute('contenteditable') === 'false') return false;
		// Rendered and visible: display:none anywhere above leaves no boxes, and
		// the chat hides its closed overlays and menu with visibility:hidden.
		return el.getClientRects().length > 0 && getComputedStyle(el).visibility === 'visible';
	}

	function tabStops() {
		var all = panel.querySelectorAll(TAB_STOP_SELECTOR);
		var stops = [];
		for (var i = 0; i < all.length; i++) {
			if (isTabStop(all[i])) stops.push(all[i]);
		}
		return stops;
	}

	// Tab and Shift+Tab go round the panel. Between its first and last stops
	// the browser moves focus as it always does — including onto a stop of
	// its own making, such as a message list it lets the keyboard scroll —
	// and only the two ends wrap. Focus found outside the panel is brought
	// back in.
	function trapTab(e) {
		if (e.key !== 'Tab' || e.ctrlKey || e.altKey || e.metaKey || !isOpen || !panel) return;
		var stops = tabStops();
		var active = focusedElement();
		var first = stops[0];
		var last = stops[stops.length - 1];
		var to = null;
		if (!stops.length) {
			to = panel;
		} else if (!active || !panel.contains(active)) {
			to = e.shiftKey ? last : first;
		} else if (e.shiftKey) {
			// The panel itself precedes its first stop, so it wraps too.
			if (active === first || first.compareDocumentPosition(active) & Node.DOCUMENT_POSITION_PRECEDING) {
				to = last;
			}
		} else if (active === last || last.compareDocumentPosition(active) & Node.DOCUMENT_POSITION_FOLLOWING) {
			to = first;
		}
		if (to) {
			e.preventDefault();
			// A plain focus(): a wrapped-to link may sit scrolled out of sight in
			// the message list, which must scroll to show it. The panel is fixed,
			// so the page itself does not move.
			to.focus();
		}
	}

	// While the chat is open the page behind it does not scroll. overflow:hidden
	// goes on whichever element the viewport takes its overflow from — <body>
	// when <html> leaves it visible (CSS Overflow 3 §3.3), else <html> — so
	// <body> never becomes a scroll container of its own, which would unstick a
	// theme's sticky header. The scroll offset is untouched, so closing needs to
	// put back only the page's own inline values. Where the page showed a
	// classic scrollbar, scrollbar-gutter keeps its width so nothing shifts.
	function lockPageScroll() {
		if (scrollLock) return;
		var html = document.documentElement;
		var body = document.body;
		var htmlStyle = getComputedStyle(html);
		var viewportFromBody =
			body && htmlStyle.overflowX === 'visible' && htmlStyle.overflowY === 'visible';
		var target = viewportFromBody ? body : html;
		var hadScrollbar = window.innerWidth - html.clientWidth > 0;
		scrollLock = {
			target: target,
			overflow: saveInline(target, 'overflow'),
			gutter: hadScrollbar ? saveInline(html, 'scrollbar-gutter') : null,
		};
		target.style.setProperty('overflow', 'hidden', 'important');
		if (hadScrollbar) {
			html.style.setProperty('scrollbar-gutter', 'stable', 'important');
		}
	}

	function unlockPageScroll() {
		if (!scrollLock) return;
		restoreInline(scrollLock.target, 'overflow', scrollLock.overflow);
		if (scrollLock.gutter) {
			restoreInline(document.documentElement, 'scrollbar-gutter', scrollLock.gutter);
		}
		scrollLock = null;
	}

	// One property at a time, not the whole style attribute, so a theme script
	// that changes <body>'s inline style while the chat is open keeps its change.
	function saveInline(el, prop) {
		return {
			value: el.style.getPropertyValue(prop),
			priority: el.style.getPropertyPriority(prop),
			hadStyle: el.hasAttribute('style'),
		};
	}

	function restoreInline(el, prop, saved) {
		if (saved.value) {
			el.style.setProperty(prop, saved.value, saved.priority);
		} else {
			el.style.removeProperty(prop);
		}
		if (!saved.hadStyle && el.getAttribute('style') === '') {
			el.removeAttribute('style');
		}
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

		// V1.2 Result Cards CSS — same shadow root as chat CSS. Order: this
		// link is inserted at firstChild AFTER chat CSS, so in the cascade
		// chat CSS comes second and wins on identical-specificity collisions.
		// In practice the .def-cc-result-* namespace doesn't collide.
		if (config.productCardsStyleUrl) {
			var cardsLink = document.createElement('link');
			cardsLink.rel = 'stylesheet';
			cardsLink.href = config.productCardsStyleUrl;
			shadowRoot.insertBefore(cardsLink, shadowRoot.firstChild);
		} else {
			console.warn('[def-cc] productCardsStyleUrl missing from DEFCore — PHP/JS version skew?');
		}

		// Sequential script loading: marked.js → purify.js → product-cards
		// renderer → chat module. Each onload triggers the next since the
		// chat module needs window.marked, window.DOMPurify, AND
		// window.DefResultCards available at init time.
		if (!config.productCardsScriptUrl) {
			console.warn('[def-cc] productCardsScriptUrl missing from DEFCore — PHP/JS version skew?');
		}
		// The voice module (7.7.8) sits before the chat module; an older PHP without
		// voiceModuleUrl skips it and the widget simply shows no mic.
		loadScript(config.markedUrl, function () {
			loadScript(config.purifyUrl, function () {
				loadScript(config.productCardsScriptUrl, function () {
					loadScript(config.voiceModuleUrl, function () {
						loadScript(config.chatModuleUrl, function () {
							moduleLoading = false;
							moduleLoaded = true;

							if (
								window.DEFCustomerChat &&
								typeof window.DEFCustomerChat.init === 'function'
							) {
								window.DEFCustomerChat.init(shadowRoot, config);
							}
							flushPendingPrompt();
						});
					});
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
			pendingPrompt = null;
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

		// 8.7.1: Tab stays inside the open panel.
		document.addEventListener('keydown', trapTab, { signal: signal });

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

		unlockPageScroll();
		returnFocusTo = null;
		shadowRoot = null;
		trigger = null;
		panel = null;
		backdrop = null;
		isOpen = false;
		moduleLoaded = false;
		moduleLoading = false;
		pendingPrompt = null;
		preloaded = false;
	}

	// Expose destroy for SPA/PJAX navigations.
	window.DEFCustomerChatLoader = { destroy: destroy };

	// ─── Greeting Bubble (v3.12.0) ─────────────────────────────────

	var GREETING_DISMISSED_KEY = 'def:greeting_dismissed_at';
	var GREETING_TTL_MS = 24 * 60 * 60 * 1000; // 24 hours
	var GREETING_DELAY_MS = 5000;

	function maybeShowGreetingBubble() {
		if (!config.greetingBubbleEnabled) return;
		if (!config.greetingBubbleText) return;
		if (!config.showFloatingButton) return;

		// 24h TTL on dismissal — survives cross-session within the window.
		try {
			var dismissedAt = parseInt(localStorage.getItem(GREETING_DISMISSED_KEY) || '0', 10);
			if (dismissedAt && Date.now() - dismissedAt < GREETING_TTL_MS) return;
		} catch (e) { /* localStorage unavailable — fall through */ }

		setTimeout(function () {
			if (isOpen) return; // user opened the chat during the delay → skip
			showGreetingBubble();
		}, GREETING_DELAY_MS);
	}

	function showGreetingBubble() {
		if (!shadowRoot) return;
		if (shadowRoot.querySelector('.def-cc-greeting-bubble')) return; // already showing
		shadowRoot.appendChild(createGreetingBubble());
	}

	function createGreetingBubble() {
		var pos = config.buttonPosition === 'left' ? 'left' : 'right';
		// 8.7.1: the bubble is a plain box holding two buttons side by side —
		// "open chat", and the dismiss × beside it. The box used to be
		// role="button" itself with the × inside it: a control nested in a
		// control, which a screen reader cannot present (axe nested-interactive).
		var bubble = document.createElement('div');
		setClass(bubble, 'def-cc-greeting-bubble' + (pos === 'left' ? ' def-cc-greeting-bubble--left' : ''));

		var openBtn = document.createElement('button');
		setClass(openBtn, 'def-cc-greeting-bubble-open');
		openBtn.type = 'button';
		openBtn.setAttribute('aria-label', 'Open chat');
		bubble.appendChild(openBtn);

		// Scroll wrapper: logo + text live here so a long greeting scrolls
		// without the bubble's overhanging x being clipped by the scrollbox.
		// Spans, not divs: a button holds phrasing content only. The flex
		// wrapper blockifies its children, so they lay out as the divs did.
		var scrollWrap = document.createElement('span');
		setClass(scrollWrap, 'def-cc-greeting-bubble-scroll');
		openBtn.appendChild(scrollWrap);

		if (config.logoUrl) {
			var logoWrap = document.createElement('span');
			setClass(logoWrap, 'def-cc-greeting-bubble-logo');
			var img = document.createElement('img');
			img.setAttribute('part', 'greeting-bubble-logo-image');
			img.src = config.logoUrl;
			img.alt = '';
			img.setAttribute('aria-hidden', 'true');
			logoWrap.appendChild(img);
			scrollWrap.appendChild(logoWrap);
		}

		var text = document.createElement('span');
		setClass(text, 'def-cc-greeting-bubble-text');
		text.textContent = config.greetingBubbleText; // CSS white-space: pre-line preserves newlines
		scrollWrap.appendChild(text);

		var close = document.createElement('button');
		setClass(close, 'def-cc-greeting-bubble-close');
		close.type = 'button';
		close.setAttribute('aria-label', 'Dismiss greeting');
		close.textContent = '×'; // ×
		close.addEventListener('click', function (e) {
			e.stopPropagation();
			// The × is about to leave the page. Focus it held goes to the
			// launcher the bubble pointed at, not back to the top of the page.
			var hadFocus = shadowRoot.activeElement === close;
			dismissGreetingBubble();
			if (hadFocus && trigger) {
				trigger.focus();
			}
		});
		bubble.appendChild(close);

		// Click anywhere else on the bubble → open the chat (openPanel tears the
		// bubble down). On the box, not the button, so its tail opens it too;
		// Enter and Space are the button's own and arrive here as a click.
		bubble.addEventListener('click', openPanel);

		return bubble;
	}

	function dismissGreetingBubble() {
		if (!shadowRoot) return;
		var bubble = shadowRoot.querySelector('.def-cc-greeting-bubble');
		if (bubble) bubble.remove();
		try {
			localStorage.setItem(GREETING_DISMISSED_KEY, String(Date.now()));
		} catch (e) { /* best-effort dismissal */ }
	}

	// ─── Init ───────────────────────────────────────────────────────

	function init() {
		createShadowHost();
		createTrigger();
		initHeaderButtons();
		bindEvents();
		maybeShowGreetingBubble();

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
