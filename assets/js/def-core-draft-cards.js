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
		name: 'Name'
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

	function setNotice(card, message, kind) {
		var existing = card.querySelector('.def-draft-notice');
		if (existing) { existing.remove(); }
		var n = el('div', 'def-draft-notice def-draft-notice-' + (kind || 'info'), message);
		card.insertBefore(n, card.querySelector('.def-draft-actions'));
	}

	function disableActions(card, disabled) {
		card.querySelectorAll('button').forEach(function (b) { b.disabled = disabled; });
	}

	// Swap each Proposed preview for a textarea holding the raw value, so the
	// reviewer can tweak the wording before publishing.
	function enterEditMode(card, draft) {
		if (card.getAttribute('data-editing') === '1') { return; }
		card.setAttribute('data-editing', '1');
		var proposed = draft.proposed || {};
		Object.keys(proposed).forEach(function (key) {
			var col = card.querySelector('.def-draft-col-proposed[data-field="' + (window.CSS && CSS.escape ? CSS.escape(key) : key) + '"]');
			if (!col) { return; }
			var preview = col.querySelector('.def-draft-preview');
			if (!preview) { return; }
			var ta = document.createElement('textarea');
			ta.className = 'def-draft-edit';
			ta.setAttribute('data-field', key);
			ta.value = proposed[key] == null ? '' : String(proposed[key]);
			col.replaceChild(ta, preview);
		});
		var editBtn = card.querySelector('.def-draft-edit-toggle');
		if (editBtn) { editBtn.remove(); }
		var approve = card.querySelector('.def-draft-approve');
		if (approve) { approve.textContent = 'Publish edited version'; }
	}

	// In edit mode, gather the textarea values into the apply body; else undefined
	// (apply the draft as-staged).
	function collectEdits(card, draft) {
		if (card.getAttribute('data-editing') !== '1') { return undefined; }
		var proposed = {};
		Object.keys(draft.proposed || {}).forEach(function (key) {
			var ta = card.querySelector('textarea.def-draft-edit[data-field="' + (window.CSS && CSS.escape ? CSS.escape(key) : key) + '"]');
			if (ta) { proposed[key] = ta.value; }
		});
		return { proposed: proposed };
	}

	function onApply(card, draft) {
		var body = collectEdits(card, draft);
		// DEF ignores blank edits (keeps the staged value), which would silently
		// diverge from what the reviewer sees. Block an empty field with a clear
		// message instead of publishing something they didn't intend.
		if (body && Object.keys(body.proposed).some(function (k) { return !String(body.proposed[k]).trim(); })) {
			setNotice(card, 'Proposed content can’t be empty — add text, or Dismiss the draft.', 'warning');
			return;
		}
		disableActions(card, true);
		setNotice(card, 'Applying…', 'info');
		api('/drafts/' + encodeURIComponent(draft.id) + '/apply', 'POST', body).then(function (res) {
			var status = res && res.status;
			if (status === 'applied') {
				card.classList.add('def-draft-card--done');
				setNotice(card, res.edited ? 'Published your edited version.' : 'Applied — your product is updated.', 'success');
				var doneActions = card.querySelector('.def-draft-actions');
				if (doneActions) { doneActions.remove(); }
			} else if (status === 'stale') {
				card.classList.add('def-draft-card--stale');
				setNotice(card, 'A field changed on the live product since this was drafted (' +
					(res.field || 'unknown') + '). Not applied — dismiss it and let the next run re-draft.', 'warning');
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

		renderDiff(card, draft);

		var checklist = renderChecklist(draft);
		if (checklist) { card.appendChild(checklist); }

		var actions = el('div', 'def-draft-actions');
		var approve = el('button', 'button button-primary def-draft-approve', 'Approve & publish');
		approve.addEventListener('click', function () { onApply(card, draft); });
		var edit = el('button', 'button def-draft-edit-toggle', 'Edit');
		edit.addEventListener('click', function () { enterEditMode(card, draft); });
		var dismiss = el('button', 'button def-draft-dismiss', 'Dismiss');
		dismiss.addEventListener('click', function () { onDismiss(card, draft); });
		actions.appendChild(approve);
		actions.appendChild(edit);
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
	});
})();
