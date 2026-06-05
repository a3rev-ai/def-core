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

		var head = el('div', 'def-draft-head');
		var title = el('div', 'def-draft-title',
			(draft.item_type || 'item') + ' #' + (draft.item_id != null ? draft.item_id : '?'));
		head.appendChild(title);
		if (draft.created_at) {
			head.appendChild(el('div', 'def-draft-date', draft.created_at.slice(0, 10)));
		}
		card.appendChild(head);

		renderDiff(card, draft);

		var actions = el('div', 'def-draft-actions');
		var approve = el('button', 'button button-primary def-draft-approve', 'Approve & publish');
		approve.addEventListener('click', function () { onApply(card, draft); });
		var dismiss = el('button', 'button def-draft-dismiss', 'Dismiss');
		dismiss.addEventListener('click', function () { onDismiss(card, draft); });
		actions.appendChild(approve);
		actions.appendChild(dismiss);
		card.appendChild(actions);

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
