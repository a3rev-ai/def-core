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

	function api(path, method) {
		return fetch(cfg.restBase + path, {
			method: method || 'GET',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin'
		}).then(function (r) {
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

		renderDiff(card, draft);

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

	api('/drafts', 'GET').then(function (res) {
		render((res && res.drafts) || []);
	}).catch(function (e) {
		root.removeAttribute('data-loading');
		root.innerHTML = '';
		root.appendChild(el('p', 'def-draft-error', 'Could not load drafts: ' + (e.message || 'error')));
	});
})();
