/**
 * def-core-page-context.js
 *
 * Page Context Build Plan V1.1 Sub-PR C. Client-side half of the page-
 * context capture pipeline:
 *
 *   - On every page mount: read `window.DefCorePageContext` (PHP-localized
 *     by DEF_Core_Page_Context::build_payload), override `canonical_path`
 *     from `window.location.pathname`, append a compact trail entry to
 *     sessionStorage (with consecutive-dedupe + 20-cap).
 *
 *   - On each chat-message submit: `DefCorePageContextHelper.build(threadId)`
 *     returns the payload to splice into the chat-message body. The
 *     accumulated pre_chat_trail is shipped ONCE per thread (thread-aware
 *     + ACK-aware via `DefCorePageContextHelper.markShipped(thread_id)`).
 *
 * Sub-PR B (DEF backend) already accepts this payload shape and validates
 * via Pydantic; this file is what makes def-core start emitting it.
 *
 * Spec: DEF-PAGE-CONTEXT-V1.2.md §3.1.4.
 */

(function () {
	'use strict';

	var TRAIL_KEY = 'def_core_pre_chat_trail';
	var SHIPPED_KEY = 'def_core_pre_chat_trail_shipped_threads';
	var TRAIL_MAX = 20;
	var SHIPPED_THREADS_MAX = 10;

	// ─── sessionStorage wrappers (defensive against quota / privacy mode) ──

	function safeGet(key) {
		try {
			return sessionStorage.getItem(key);
		} catch (e) {
			return null;
		}
	}

	function safeSet(key, value) {
		try {
			sessionStorage.setItem(key, value);
		} catch (e) {
			// Quota exceeded OR access denied (e.g. Safari private mode).
			// Silent degrade — page context still ships on the next chat
			// message, the trail is just not persisted.
		}
	}

	function loadJSON(key) {
		var raw = safeGet(key);
		if (!raw) return null;
		try {
			return JSON.parse(raw);
		} catch (e) {
			return null;
		}
	}

	// ─── Path helpers ────────────────────────────────────────────────

	function normalisePath(path) {
		if (!path || path === '/') return '/';
		// Strip trailing slash unless root; decode percent-encoded ASCII.
		var p = path.replace(/\/+$/, '');
		try {
			p = decodeURIComponent(p);
		} catch (e) {
			// Keep raw if decode fails.
		}
		return p || '/';
	}

	/**
	 * Same-origin document.referrer → path. Spec §3.1.4: avoid the V1.0
	 * cached-PHP-payload pollution by deriving referrer JS-side at submit
	 * time. External-origin referrers → null (DEF server-side also rejects
	 * scheme-prefixed values defensively).
	 */
	function getReferrerPath() {
		try {
			var ref = document.referrer;
			if (!ref) return null;
			var u = new URL(ref, window.location.origin);
			if (u.origin !== window.location.origin) return null;
			return u.pathname || null;
		} catch (e) {
			return null;
		}
	}

	// ─── Trail accumulator ───────────────────────────────────────────

	/**
	 * Build the current page entry shape. Mirrors PreChatTrailEntry shape
	 * in the DEF Pydantic schema (`page_context_schemas.py`).
	 */
	function buildCurrentTrailEntry() {
		var ctx = window.DefCorePageContext || {};
		var nowIso = new Date().toISOString();
		return {
			path: normalisePath(window.location.pathname || '/'),
			page_type: ctx.page_type || 'other',
			page_id: typeof ctx.page_id === 'number' ? ctx.page_id : 0,
			product_id: typeof ctx.product_id === 'number' ? ctx.product_id : null,
			queried_taxonomy: ctx.queried_taxonomy || null,
			language_code: ctx.language_code || 'en',
			first_seen_ts: nowIso,
			last_seen_ts: nowIso,
		};
	}

	/**
	 * Apply consecutive-duplicate dedupe + 20-cap to the trail. Matches
	 * the server-side rule in `page_context_ingest._dedupe_and_cap`.
	 */
	function dedupeAndAppend(trail, entry) {
		if (!Array.isArray(trail)) trail = [];
		var prev = trail.length > 0 ? trail[trail.length - 1] : null;
		if (prev && prev.path === entry.path && prev.language_code === entry.language_code) {
			// Consecutive duplicate — update last_seen_ts in place, keep first_seen_ts.
			prev.last_seen_ts = entry.last_seen_ts;
			return trail;
		}
		trail.push(entry);
		if (trail.length > TRAIL_MAX) trail = trail.slice(-TRAIL_MAX);
		return trail;
	}

	/**
	 * Page-mount tick: build the current trail entry and persist it.
	 * Called once when this script loads on each page.
	 */
	function tickOnMount() {
		var ctx = window.DefCorePageContext;
		if (!ctx) return; // No PHP-localized payload — page-context disabled for this page.
		var trail = loadJSON(TRAIL_KEY);
		if (!Array.isArray(trail)) trail = [];
		var entry = buildCurrentTrailEntry();
		trail = dedupeAndAppend(trail, entry);
		safeSet(TRAIL_KEY, JSON.stringify(trail));
	}

	// ─── Shipped-flag (thread-aware + ACK-aware) ─────────────────────

	function getShippedThreads() {
		var list = loadJSON(SHIPPED_KEY);
		return Array.isArray(list) ? list : [];
	}

	function hasShipped(threadKey) {
		if (!threadKey) return false;
		return getShippedThreads().indexOf(threadKey) !== -1;
	}

	function markShipped(threadKey) {
		if (!threadKey) return;
		var shipped = getShippedThreads();
		if (shipped.indexOf(threadKey) !== -1) return;
		shipped.push(threadKey);
		// Bound the set — sessionStorage isn't infinite, and we don't realistically
		// host more than ~10 threads per tab.
		if (shipped.length > SHIPPED_THREADS_MAX) shipped = shipped.slice(-SHIPPED_THREADS_MAX);
		safeSet(SHIPPED_KEY, JSON.stringify(shipped));
	}

	// ─── Payload builder (called at chat-message submit time) ────────

	/**
	 * Build the page_context payload that splices into the chat-message
	 * body. Always returns an object with `current` populated. Includes
	 * `pre_chat_trail` ONLY when this thread hasn't been ACK'd yet —
	 * after the first successful server response on a thread, the trail
	 * is suppressed for subsequent messages on that thread.
	 *
	 * @param {string|null} threadId - The thread the message is going to.
	 *   Pass null for the very first message of a new thread (DEF will
	 *   assign the thread_id and return it in the response; caller then
	 *   calls `markShipped(threadId)` to suppress the trail on subsequent
	 *   messages).
	 *
	 * @returns {Object} { current: {...}, pre_chat_trail?: [...] }
	 */
	function build(threadId) {
		var ctx = window.DefCorePageContext || {};
		var submitPath = normalisePath(window.location.pathname || '/');
		var current = {
			canonical_path: submitPath,
			language_code: ctx.language_code || 'en',
			page_type: ctx.page_type || 'other',
			page_id: typeof ctx.page_id === 'number' ? ctx.page_id : 0,
			product_id: typeof ctx.product_id === 'number' ? ctx.product_id : null,
			queried_taxonomy: ctx.queried_taxonomy || null,
			terms: Array.isArray(ctx.terms) ? ctx.terms : [],
			title: typeof ctx.title === 'string' ? ctx.title : '',
			referrer_path: getReferrerPath(),
		};
		var payload = { current: current };

		// Pre-chat trail: ship only if this thread hasn't been ACK'd yet.
		// Use `__pending__` as the sentinel for the first message of a
		// brand-new thread (no thread_id assigned yet). Caller MUST call
		// markShipped(thread_id) on successful response so the trail
		// doesn't re-ship.
		var threadKey = threadId || '__pending__';
		if (!hasShipped(threadKey)) {
			var trail = loadJSON(TRAIL_KEY);
			if (Array.isArray(trail) && trail.length > 0) {
				payload.pre_chat_trail = trail;
			}
		}

		return payload;
	}

	// ─── Public surface ──────────────────────────────────────────────

	window.DefCorePageContextHelper = {
		build: build,
		markShipped: markShipped,
		hasShipped: hasShipped,
		// Exposed for tests + observability.
		_normalisePath: normalisePath,
		_getReferrerPath: getReferrerPath,
		_dedupeAndAppend: dedupeAndAppend,
		_buildCurrentTrailEntry: buildCurrentTrailEntry,
		_TRAIL_KEY: TRAIL_KEY,
		_SHIPPED_KEY: SHIPPED_KEY,
		_TRAIL_MAX: TRAIL_MAX,
	};

	// Run the mount-tick on script load.
	tickOnMount();
})();
