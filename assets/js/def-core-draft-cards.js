/**
 * Content Agent review queue — draft cards (def-core PR-6).
 *
 * Renders pending staged content changes from the Staff-AI BFF (which proxies the
 * DEF backend), shows the per-field diff (current -> proposed), and dispatches
 * approve / dismiss. All draft content is untrusted backend HTML and is sanitized
 * with DOMPurify before it touches the DOM.
 */
(function () {
	'use strict';

	var cfg = window.DefDraftCards || {};
	var root = document.getElementById('def-draft-cards-root');
	if (!root || !cfg.restBase) {
		return;
	}

	// Fields we render as rich HTML previews (Gutenberg/product content). Anything
	// else is shown as escaped plain text.
	var HTML_FIELDS = { description: 1, short_description: 1 };
	var FIELD_LABELS = {
		description: 'Description',
		short_description: 'Short description',
		name: 'Name',
		// SEO-plugin meta — the backend now captures the current value into `source`
		// so the "Current" column shows the live Yoast meta (Bug B), not (empty).
		meta_description: 'Meta description',
		seo_title: 'SEO title'
	};

	function api(path, method, body) {
		var opts = {
			method: method || 'GET',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin'
		};
		if (body !== undefined) { opts.body = JSON.stringify(body); }
		return fetch(cfg.restBase + path, opts).then(function (r) {
			return r.json().then(function (data) {
				if (!r.ok) {
					throw new Error((data && (data.message || data.detail)) || ('HTTP ' + r.status));
				}
				return data;
			});
		});
	}

	function sanitize(html) {
		if (window.DOMPurify) {
			return window.DOMPurify.sanitize(String(html == null ? '' : html));
		}
		// No sanitizer available — fail closed to escaped text, never raw HTML.
		var d = document.createElement('div');
		d.textContent = String(html == null ? '' : html);
		return d.innerHTML;
	}

	function el(tag, className, text) {
		var n = document.createElement(tag);
		if (className) { n.className = className; }
		if (text != null) { n.textContent = text; }
		return n;
	}

	function fieldLabel(key) {
		return FIELD_LABELS[key] || key;
	}

	// Validate a URL to http(s) before using it as an href (the title/view links
	// come from the BFF, but we never trust a string into href without a scheme check).
	function safeHref(url) {
		if (typeof url !== 'string' || !url) { return null; }
		try {
			var u = new URL(url, window.location.origin);
			return (u.protocol === 'http:' || u.protocol === 'https:') ? u.href : null;
		} catch (e) {
			return null;
		}
	}

	function linkEl(text, href, className) {
		var a = document.createElement('a');
		if (className) { a.className = className; }
		a.textContent = text;
		a.href = href;
		a.target = '_blank';
		a.rel = 'noopener noreferrer';
		return a;
	}

	// Header: product title (linked to the editor when the user can edit), the
	// item id, and a "View live" link to the public product page.
	function renderHead(draft) {
		var head = el('div', 'def-draft-head');
		var left = el('div', 'def-draft-head-left');

		var name = (draft.title && String(draft.title).trim()) ||
			((draft.item_type || 'item') + ' #' + (draft.item_id != null ? draft.item_id : '?'));
		var editHref = safeHref(draft.edit_url);
		left.appendChild(editHref
			? linkEl(name, editHref, 'def-draft-title def-draft-title-link')
			: el('div', 'def-draft-title', name));

		var sub = el('div', 'def-draft-sub');
		sub.appendChild(el('span', 'def-draft-sub-id',
			(draft.item_type || 'item') + ' #' + (draft.item_id != null ? draft.item_id : '?')));
		var viewHref = safeHref(draft.view_url);
		if (viewHref) {
			sub.appendChild(document.createTextNode(' · '));
			sub.appendChild(linkEl('View live ↗', viewHref, 'def-draft-view-link'));
		}
		left.appendChild(sub);
		head.appendChild(left);

		if (draft.created_at) {
			head.appendChild(el('div', 'def-draft-date', String(draft.created_at).slice(0, 10)));
		}
		return head;
	}

	// "Focus keyphrase: …" badge — the human-set SEO target this draft optimizes
	// for. Value set via textContent. Hidden when absent (older drafts).
	function renderKeyphrase(draft) {
		var kp = draft.focus_keyphrase && String(draft.focus_keyphrase).trim();
		if (!kp) { return null; }
		var wrap = el('div', 'def-draft-keyphrase');
		wrap.appendChild(el('span', 'def-draft-keyphrase-label', 'Focus keyphrase'));
		wrap.appendChild(el('span', 'def-draft-keyphrase-value', kp));
		return wrap;
	}

	// Yoast-style SEO checklist (green ✓ / red ✗) the agent self-assessed for the
	// proposed content. Labels are untrusted LLM text → textContent. Hidden when
	// the backend supplied none.
	function renderChecklist(draft) {
		var items = Array.isArray(draft.checklist) ? draft.checklist : null;
		if (!items || !items.length) { return null; }
		var wrap = el('div', 'def-draft-checklist');
		wrap.appendChild(el('div', 'def-draft-checklist-head', 'SEO checklist'));
		var ul = document.createElement('ul');
		ul.className = 'def-draft-checklist-list';
		items.forEach(function (c) {
			if (!c || typeof c !== 'object') { return; }
			var pass = !!c.pass;
			var li = el('li', 'def-draft-check ' + (pass ? 'def-draft-check--pass' : 'def-draft-check--fail'));
			li.appendChild(el('span', 'def-draft-check-mark', pass ? '✓' : '✗'));
			li.appendChild(el('span', 'def-draft-check-label', String(c.label || c.id || '')));
			ul.appendChild(li);
		});
		wrap.appendChild(ul);
		return wrap;
	}

	// Collapsible "Why this change?" strip (collapsed by default). Only rendered
	// when the backend supplied a rationale; the text is set via textContent.
	function renderExplainer(draft) {
		var rationale = draft.rationale && String(draft.rationale).trim();
		if (!rationale) { return null; }
		var wrap = el('div', 'def-draft-explainer');
		var btn = el('button', 'def-draft-why-toggle', 'Why this change?');
		btn.type = 'button';
		btn.setAttribute('aria-expanded', 'false');
		var body = el('div', 'def-draft-why-body');
		body.appendChild(el('p', null, rationale));
		body.style.display = 'none';
		btn.addEventListener('click', function () {
			var open = body.style.display !== 'none';
			body.style.display = open ? 'none' : 'block';
			btn.setAttribute('aria-expanded', open ? 'false' : 'true');
			btn.classList.toggle('def-draft-why-open', !open);
		});
		wrap.appendChild(btn);
		wrap.appendChild(body);
		return wrap;
	}

	function renderPreview(value, isHtml) {
		var box = el('div', 'def-draft-preview');
		if (value == null || value === '') {
			box.appendChild(el('em', 'def-draft-empty', '(empty)'));
		} else if (isHtml) {
			box.innerHTML = sanitize(value); // sanitized untrusted HTML
		} else {
			box.textContent = String(value); // escaped
		}
		return box;
	}

	function renderDiff(card, draft) {
		var proposed = draft.proposed || {};
		var source = draft.source || {};
		Object.keys(proposed).forEach(function (key) {
			var isHtml = !!HTML_FIELDS[key];
			var row = el('div', 'def-draft-field');
			row.appendChild(el('div', 'def-draft-field-label', fieldLabel(key)));
			var cols = el('div', 'def-draft-cols');

			var cur = el('div', 'def-draft-col');
			cur.appendChild(el('div', 'def-draft-col-head', 'Current'));
			cur.appendChild(renderPreview(source[key], isHtml));
			cols.appendChild(cur);

			var prop = el('div', 'def-draft-col def-draft-col-proposed');
			prop.setAttribute('data-field', key);
			prop.appendChild(el('div', 'def-draft-col-head', 'Proposed'));
			prop.appendChild(renderPreview(proposed[key], isHtml));
			cols.appendChild(prop);

			row.appendChild(cols);
			card.appendChild(row);
		});
	}

	// Body text edits (Adapter G): the agent rewrote individual text nodes only —
	// the page's design blocks, images and layout are preserved untouched. Show each
	// node's before -> after so the reviewer sees exactly what changes.
	function renderBodyEdits(card, draft) {
		var be = draft.block_edit;
		var patches = (be && Array.isArray(be.patches)) ? be.patches : null;
		if (!patches || !patches.length) { return; }
		var wrap = el('div', 'def-draft-body-edits');
		wrap.appendChild(el('div', 'def-draft-field-label',
			'Body text edits (' + patches.length + ')'));
		wrap.appendChild(el('div', 'def-draft-body-note',
			'Only the text below changes — the page’s design blocks, images and layout are preserved.'));
		patches.forEach(function (p) {
			if (!p || typeof p !== 'object') { return; }
			var isAlt = p.field === 'alt';
			var row = el('div', 'def-draft-field');
			if (isAlt) { row.appendChild(el('div', 'def-draft-node-label', 'Image alt text')); }
			var cols = el('div', 'def-draft-cols');
			var cur = el('div', 'def-draft-col');
			cur.appendChild(el('div', 'def-draft-col-head', 'Current'));
			cur.appendChild(renderPreview(p.before, !isAlt)); // inner_html sanitized; alt as text
			cols.appendChild(cur);
			var prop = el('div', 'def-draft-col def-draft-col-proposed');
			prop.appendChild(el('div', 'def-draft-col-head', 'Proposed'));
			prop.appendChild(renderPreview(p.after, !isAlt));
			cols.appendChild(prop);
			row.appendChild(cols);
			wrap.appendChild(row);
		});
		card.appendChild(wrap);
	}

	function setNotice(card, message, kind) {
		var existing = card.querySelector('.def-draft-notice');
		if (existing) { existing.remove(); }
		var n = el('div', 'def-draft-notice def-draft-notice-' + (kind || 'info'), message);
		card.insertBefore(n, card.querySelector('.def-draft-actions'));
	}

	function disableActions(card, disabled) {
		card.querySelectorAll('button').forEach(function (b) { b.disabled = disabled; });
	}

	function onApply(card, draft) {
		disableActions(card, true);
		setNotice(card, 'Applying…', 'info');
		api('/drafts/' + encodeURIComponent(draft.id) + '/apply', 'POST').then(function (res) {
			var status = res && res.status;
			if (status === 'applied') {
				card.classList.add('def-draft-card--done');
				setNotice(card, 'Applied — your product is updated.', 'success');
				var doneActions = card.querySelector('.def-draft-actions');
				if (doneActions) { doneActions.remove(); }
			} else if (status === 'stale') {
				card.classList.add('def-draft-card--stale');
				// SEO-meta drift returns `field`; body drift returns `stale_paths`.
				var what = res.field ||
					((Array.isArray(res.stale_paths) && res.stale_paths.length) ? 'the body text' : 'a field');
				setNotice(card, 'The live product changed since this was drafted (' + what +
					'). Not applied — dismiss it and let the next run re-draft.', 'warning');
				disableActions(card, false);
				toApplyDisabled(card);
			} else if (status === 'apply_failed') {
				card.classList.add('def-draft-card--failed');
				setNotice(card, 'Could not write to the product: ' + (res.error || 'unknown error') + '.', 'error');
				disableActions(card, false);
			} else {
				setNotice(card, 'Unexpected response.', 'error');
				disableActions(card, false);
			}
		}).catch(function (e) {
			setNotice(card, e.message || 'Apply failed.', 'error');
			disableActions(card, false);
		});
	}

	// After a stale result, applying again will just re-stale — leave only Dismiss.
	function toApplyDisabled(card) {
		var apply = card.querySelector('.def-draft-approve');
		if (apply) { apply.remove(); }
	}

	function onDismiss(card, draft) {
		disableActions(card, true);
		setNotice(card, 'Dismissing…', 'info');
		api('/drafts/' + encodeURIComponent(draft.id) + '/dismiss', 'POST').then(function () {
			card.classList.add('def-draft-card--done');
			setNotice(card, 'Dismissed.', 'info');
			var actions = card.querySelector('.def-draft-actions');
			if (actions) { actions.remove(); }
		}).catch(function (e) {
			setNotice(card, e.message || 'Dismiss failed.', 'error');
			disableActions(card, false);
		});
	}

	function renderCard(draft) {
		var card = el('div', 'def-draft-card');

		card.appendChild(renderHead(draft));

		var keyphrase = renderKeyphrase(draft);
		if (keyphrase) { card.appendChild(keyphrase); }

		renderDiff(card, draft);          // SEO meta (current -> proposed)
		renderBodyEdits(card, draft);     // body text node edits (Adapter G)

		var checklist = renderChecklist(draft);
		if (checklist) { card.appendChild(checklist); }

		var actions = el('div', 'def-draft-actions');
		var approve = el('button', 'button button-primary def-draft-approve', 'Approve & publish');
		approve.addEventListener('click', function () { onApply(card, draft); });
		var dismiss = el('button', 'button def-draft-dismiss', 'Dismiss');
		dismiss.addEventListener('click', function () { onDismiss(card, draft); });
		actions.appendChild(approve);
		actions.appendChild(dismiss);
		card.appendChild(actions);

		var explainer = renderExplainer(draft);
		if (explainer) { card.appendChild(explainer); }

		return card;
	}

	function render(drafts) {
		root.removeAttribute('data-loading');
		root.innerHTML = '';
		if (!drafts.length) {
			root.appendChild(el('p', 'def-draft-empty-state',
				'No drafts waiting for review. The Content Agent stages improvements here after each scheduled run.'));
			return;
		}
		drafts.forEach(function (d) { root.appendChild(renderCard(d)); });
	}

	// Compact "needs a focus keyphrase" panel — items the agent skipped because no
	// keyphrase is set. Rendered above the drafts; each links to its editor.
	function renderNeedsKeyphrase(items) {
		if (!Array.isArray(items) || !items.length) { return; }
		var panel = el('div', 'def-draft-needs-kp');
		panel.appendChild(el('div', 'def-draft-needs-kp-head',
			items.length + (items.length === 1 ? ' item needs' : ' items need') +
			' a focus keyphrase before the Content Agent can optimize it. Set one in your SEO plugin:'));
		var ul = document.createElement('ul');
		ul.className = 'def-draft-needs-kp-list';
		items.forEach(function (it) {
			if (!it || typeof it !== 'object') { return; }
			var li = document.createElement('li');
			var name = (it.title && String(it.title).trim()) ||
				((it.item_type || 'item') + ' #' + (it.item_id != null ? it.item_id : '?'));
			var editHref = safeHref(it.edit_url);
			li.appendChild(editHref ? linkEl(name, editHref, 'def-draft-needs-kp-link')
				: el('span', null, name));
			ul.appendChild(li);
		});
		panel.appendChild(ul);
		root.insertBefore(panel, root.firstChild);
	}

	// Coerce a backend count to a non-negative integer for display. The counts
	// are ints over the wire, but never trust that — a string/float/NaN becomes 0.
	function num(v) {
		var n = Number(v);
		return (isFinite(n) && n > 0) ? Math.floor(n) : 0;
	}

	// Format an ISO timestamp to a short, readable local time. Returns '' (caller
	// hides the time) when the value isn't a usable date.
	function formatRunTime(iso) {
		if (typeof iso !== 'string' || !iso) { return ''; }
		var d = new Date(iso);
		if (isNaN(d.getTime())) { return ''; }
		try {
			return d.toLocaleString(undefined, {
				month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
			});
		} catch (e) {
			return '';
		}
	}

	// "Last run" status strip: a readable ACTIVITY BREAKDOWN of the most recent
	// Content Agent audit run, pinned to the top of the page.
	//
	// Hard requirement: never render a ratio like "1/84 optimized". Most audited
	// items legitimately PASS and need no work, so a fraction implies false
	// pending work. We only ever show discrete activity counts.
	//
	// Untrusted-data discipline: every value goes in via el()'s textContent or
	// Number() — backend strings/ints are never written as innerHTML.
	function renderLastRun(lastRun) {
		var strip = el('div', 'def-draft-last-run');

		// No run has happened yet.
		if (!lastRun || typeof lastRun !== 'object') {
			strip.classList.add('def-draft-last-run--empty');
			strip.appendChild(el('span', 'def-draft-last-run-label', 'No runs yet'));
			strip.appendChild(el('span', 'def-draft-last-run-detail',
				'No scheduled audit has run yet.'));
			root.insertBefore(strip, root.firstChild);
			return;
		}

		var startedAt = (typeof lastRun.started_at === 'string') ? lastRun.started_at : '';
		var finishedAt = (typeof lastRun.finished_at === 'string') ? lastRun.finished_at : '';
		var status = (typeof lastRun.status === 'string') ? lastRun.status.toLowerCase() : '';
		// A terminal status lets us leave the spinner even if the backend never
		// wrote finished_at (e.g. an aborted/failed/crashed run) — otherwise such a
		// run would show "Running…" forever.
		var terminal = /^(complete|finish|done|fail|error|abort|cancel|timed)/.test(status);

		// In flight: started, not finished, and not reported terminal. A null
		// finished_at is the backend's own in-flight signal.
		if (startedAt && !finishedAt && !terminal) {
			strip.classList.add('def-draft-last-run--running');
			strip.appendChild(el('span', 'def-draft-last-run-label', 'Running…'));
			strip.appendChild(el('span', 'def-draft-last-run-detail',
				'The Content Agent is auditing your content now.'));
			root.insertBefore(strip, root.firstChild);
			return;
		}

		// Finished (or terminal) run → activity breakdown.
		var counts = (lastRun.counts && typeof lastRun.counts === 'object') ? lastRun.counts : {};
		var audited = num(counts.audited);
		var staged = num(counts.staged);
		var needsKp = num(counts.needs_keyphrase);
		var errored = num(counts.errored);

		// Prefer the finish time; fall back to the start time for a terminal run
		// that never recorded finished_at.
		var when = formatRunTime(finishedAt || startedAt);
		strip.appendChild(el('span', 'def-draft-last-run-label',
			(when ? ('Last run ' + when) : 'Last run') + ':'));

		var parts = el('span', 'def-draft-last-run-parts');

		// Always show what was looked at and what was produced.
		parts.appendChild(el('span', 'def-draft-last-run-stat',
			'audited ' + audited));
		parts.appendChild(el('span', 'def-draft-last-run-stat',
			'✍️ ' + staged + (staged === 1 ? ' new draft' : ' new drafts')));

		// Only surface these when non-zero — zero is the happy path, and "0 errored"
		// would just be noise.
		if (needsKp > 0) {
			parts.appendChild(el('span', 'def-draft-last-run-stat',
				'🔑 ' + needsKp + (needsKp === 1 ? ' needs a keyphrase' : ' need a keyphrase')));
		}
		if (errored > 0) {
			parts.appendChild(el('span', 'def-draft-last-run-stat def-draft-last-run-stat--warn',
				'⚠️ ' + errored + ' errored'));
		}

		// Optional: content types whose audit couldn't run (transport error).
		var failedTypes = Array.isArray(counts.audit_failed_types) ? counts.audit_failed_types : null;
		if (failedTypes && failedTypes.length) {
			parts.appendChild(el('span', 'def-draft-last-run-stat def-draft-last-run-stat--warn',
				'⚠️ couldn’t audit ' + failedTypes.length +
				(failedTypes.length === 1 ? ' content type' : ' content types')));
		}

		strip.appendChild(parts);
		root.insertBefore(strip, root.firstChild);
	}

	api('/drafts', 'GET').then(function (res) {
		render((res && res.drafts) || []);
	}).catch(function (e) {
		root.removeAttribute('data-loading');
		root.innerHTML = '';
		root.appendChild(el('p', 'def-draft-error', 'Could not load drafts: ' + (e.message || 'error')));
	}).then(function () {
		// Best-effort, after the drafts render — a failure here must not block the queue.
		return api('/needs-keyphrase', 'GET').then(function (res) {
			renderNeedsKeyphrase((res && res.items) || []);
		}).catch(function () { /* ignore — the queue still works */ });
	}).then(function () {
		// Last-run status strip — best-effort, pinned to the very top (inserted
		// last so it sits above the needs-keyphrase panel and the cards).
		return api('/last-run', 'GET').then(function (res) {
			renderLastRun(res && res.last_run);
		}).catch(function () { /* ignore — the queue still works */ });
	});
})();
