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

	// Engine 2.5 fail-closed safeguard copy (design §6/§7) — shown on every
	// create surface. Set via textContent only, never innerHTML.
	var SAFEGUARD_COPY = 'AI-created posts are excluded from Company Knowledge until you expressly include them. ' +
		"Review the images and verify every claim before unchecking 'Exclude from Digital Employee knowledge' on the post.";

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
		var isCreate = draft && draft.kind === 'create';
		disableActions(card, true);
		setNotice(card, isCreate ? 'Creating…' : 'Applying…', 'info');
		api('/drafts/' + encodeURIComponent(draft.id) + '/apply', 'POST').then(function (res) {
			var status = res && res.status;
			if (isCreate) {
				// Create success: DEF orchestrated the create-post call; a new WP draft
				// now exists. Show its editor link. (Success = a created/applied status
				// or an edit_link in the response.)
				if (status === 'created' || status === 'applied' || (res && res.edit_link)) {
					card.classList.add('def-draft-card--done');
					setNotice(card, 'Draft created.', 'success');
					var editHref = res && safeHref(res.edit_link);
					if (editHref) {
						var notice = card.querySelector('.def-draft-notice');
						if (notice) {
							notice.appendChild(document.createTextNode(' '));
							notice.appendChild(linkEl('Edit the new post ↗', editHref, 'def-draft-edit-link'));
						}
					}
					var createdActions = card.querySelector('.def-draft-actions');
					if (createdActions) { createdActions.remove(); }
				} else if (status === 'create_failed') {
					card.classList.add('def-draft-card--failed');
					setNotice(card, 'Could not create the post: ' + (res.reason || res.error || 'unknown error') + '.', 'error');
					disableActions(card, false);
				} else {
					setNotice(card, 'Unexpected response.', 'error');
					disableActions(card, false);
				}
				return;
			}
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

	// A single proposed-meta field for a CREATE card (no current→proposed diff —
	// it's a brand-new post). Plain text via textContent.
	function createField(label, value) {
		var row = el('div', 'def-draft-field');
		row.appendChild(el('div', 'def-draft-field-label', label));
		var box = el('div', 'def-draft-preview');
		if (value == null || String(value) === '') {
			box.appendChild(el('em', 'def-draft-empty', '(empty)'));
		} else {
			box.textContent = String(value); // escaped — meta is plain text
		}
		row.appendChild(box);
		return row;
	}

	// Render the proposed body of a CREATE draft from its semantic-block JSON. The
	// STRUCTURE (which tag) comes from the trusted JSON shape; the per-node TEXT is
	// untrusted authored content, so it's sanitized with DOMPurify (sanitize()).
	function renderCreateBody(body) {
		var wrap = el('div', 'def-draft-create-body');
		if (!Array.isArray(body) || !body.length) {
			wrap.appendChild(el('em', 'def-draft-empty', '(no body content)'));
			return wrap;
		}
		body.forEach(function (node) {
			if (!node || typeof node !== 'object') { return; }
			var type = node.type;
			if (type === 'heading') {
				var lvl = num(node.level) || 2;
				if (lvl < 1 || lvl > 6) { lvl = 2; }
				var h = document.createElement('h' + lvl);
				h.className = 'def-draft-create-h';
				h.innerHTML = sanitize(node.text); // sanitized authored text
				wrap.appendChild(h);
			} else if (type === 'list') {
				var items = Array.isArray(node.items) ? node.items : [];
				var listEl = document.createElement(node.ordered ? 'ol' : 'ul');
				listEl.className = 'def-draft-create-list';
				items.forEach(function (it) {
					if (typeof it !== 'string') { return; }
					var li = document.createElement('li');
					li.innerHTML = sanitize(it); // sanitized authored text
					listEl.appendChild(li);
				});
				wrap.appendChild(listEl);
			} else if (type === 'image' || type === 'image-placeholder') {
				// Wave 2: DEF stages the sideloaded image's WP attachment URL on the
				// node. The src is ONLY ever that URL (scheme-checked via safeHref) —
				// never model text; the alt goes through the attribute (DOM-escaped).
				// No/invalid URL → the labelled placeholder (tenant without an image
				// key still reviews a placeholder paragraph, matching the WP draft).
				var imgSrc = safeHref(node.url);
				if (imgSrc) {
					var fig = el('figure', 'def-draft-create-figure');
					var img = document.createElement('img');
					img.src = imgSrc;
					img.alt = node.alt ? String(node.alt) : '';
					img.loading = 'lazy';
					fig.appendChild(img);
					if (node.alt) {
						fig.appendChild(el('figcaption', 'def-draft-create-figcaption', String(node.alt)));
					}
					wrap.appendChild(fig);
				} else {
					wrap.appendChild(el('div', 'def-draft-create-img',
						'🖼 ' + (node.alt ? String(node.alt) : 'Image') + ' (placeholder)'));
				}
			} else {
				var text = (typeof node.text === 'string') ? node.text : '';
				if (!text.trim()) { return; }
				var p = document.createElement('p');
				p.innerHTML = sanitize(text); // sanitized authored text
				wrap.appendChild(p);
			}
		});
		return wrap;
	}

	// CREATE review card: the full PROPOSED new post (no diff — it's new).
	function renderCreateCard(draft) {
		// The new post's content (title/slug/SEO/body) lives in draft.proposed — the
		// same envelope edit drafts use; only keyphrase/checklist/rationale are
		// top-level. (Reading these off the top level left the card blank.)
		var p = (draft && draft.proposed) || {};
		var card = el('div', 'def-draft-card def-draft-card--create');

		var head = el('div', 'def-draft-head');
		var left = el('div', 'def-draft-head-left');
		left.appendChild(el('span', 'def-draft-create-badge', 'New post'));
		left.appendChild(el('div', 'def-draft-title',
			(p.title && String(p.title).trim()) || '(untitled)'));
		if (p.slug) {
			var sub = el('div', 'def-draft-sub');
			sub.appendChild(el('span', 'def-draft-sub-id', '/' + String(p.slug)));
			left.appendChild(sub);
		}
		head.appendChild(left);
		card.appendChild(head);

		// Featured image (Wave 2): DEF stages the display URL alongside the
		// proposed envelope — either featured_image_url (string) or a
		// featured_image {url, alt} object. The src is the WP attachment URL
		// only, scheme-checked via safeHref; alt is set via the attribute.
		var fi = p.featured_image;
		var fiUrl = safeHref(p.featured_image_url || (fi && typeof fi === 'object' ? fi.url : null));
		if (fiUrl) {
			var fwrap = el('div', 'def-draft-create-featured');
			var fimg = document.createElement('img');
			fimg.src = fiUrl;
			fimg.alt = (fi && typeof fi === 'object' && fi.alt) ? String(fi.alt) : '';
			fimg.loading = 'lazy';
			fwrap.appendChild(fimg);
			fwrap.appendChild(el('div', 'def-draft-create-featured-label', 'Featured image'));
			card.appendChild(fwrap);
		}

		var keyphrase = renderKeyphrase(draft); // reads draft.focus_keyphrase
		if (keyphrase) { card.appendChild(keyphrase); }

		card.appendChild(createField('SEO title', p.seo_title));
		card.appendChild(createField('Meta description', p.meta_description));

		card.appendChild(el('div', 'def-draft-field-label', 'Body preview'));
		card.appendChild(renderCreateBody(p.body));

		var checklist = renderChecklist(draft);
		if (checklist) { card.appendChild(checklist); }

		// Engine 2.5 safeguard signpost: the created draft is born excluded from
		// Company Knowledge (fail-closed, set server-side at create); the WP-editor
		// checkbox is the single control — this copy only points at it.
		card.appendChild(el('div', 'def-draft-safeguard', SAFEGUARD_COPY));

		var actions = el('div', 'def-draft-actions');
		var approve = el('button', 'button button-primary def-draft-approve', 'Approve & create draft');
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

	function renderCard(draft) {
		if (draft && draft.kind === 'create') {
			return renderCreateCard(draft);
		}

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

	// Draft ids seen in the last render — the post-Generate poll uses this to
	// detect when the requested create draft has landed.
	var knownDraftIds = {};
	function rememberDraftIds(drafts) {
		drafts.forEach(function (d) {
			if (d && d.id != null) { knownDraftIds[String(d.id)] = 1; }
		});
	}

	// Replace only the draft cards (and empty/error states), leaving the
	// coverage strip / needs-keyphrase panel at the top intact — used by the
	// post-Generate refresh, where those panels are already rendered.
	function renderDraftsOnly(drafts) {
		root.querySelectorAll('.def-draft-card, .def-draft-empty-state, .def-draft-error')
			.forEach(function (n) { n.remove(); });
		rememberDraftIds(drafts);
		if (!drafts.length) {
			root.appendChild(el('p', 'def-draft-empty-state',
				'No drafts waiting for review. The Content Agent stages improvements here after each scheduled run.'));
			return;
		}
		drafts.forEach(function (d) { root.appendChild(renderCard(d)); });
	}

	function render(drafts) {
		root.removeAttribute('data-loading');
		root.innerHTML = '';
		renderDraftsOnly(drafts);
	}

	// After Generate, the create draft appears whenever DEF finishes generating
	// — there's no push channel, so poll the drafts list every 10s for up to
	// 10 minutes (image generation alone runs ~4 minutes) and re-render when a
	// draft we haven't seen shows up (then stop). Hitting the deadline calls
	// onDeadline so the status line can say so instead of silently going stale.
	// Poll errors are swallowed: a transient failure just means the next tick
	// tries again until the deadline.
	var refreshTimer = null;
	function stopDraftsRefresh() {
		if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
	}
	function startDraftsRefresh(onNewDraft, onDeadline) {
		stopDraftsRefresh();
		var deadline = Date.now() + 600000;
		refreshTimer = setInterval(function () {
			if (Date.now() > deadline) {
				stopDraftsRefresh();
				if (onDeadline) { onDeadline(); }
				return;
			}
			api('/drafts', 'GET').then(function (res) {
				var drafts = (res && res.drafts) || [];
				var hasNew = drafts.some(function (d) {
					return d && d.id != null && !knownDraftIds[String(d.id)];
				});
				if (hasNew) {
					stopDraftsRefresh();
					renderDraftsOnly(drafts);
					if (onNewDraft) { onNewDraft(); }
				}
			}).catch(function () { /* transient — keep polling until the deadline */ });
		}, 10000);
	}

	// Friendly plural label for a content type. Known core types are mapped; an
	// unknown CPT slug is title-cased and lightly pluralized (the count is the
	// actionable part, not perfect grammar).
	var TYPE_LABELS = { product: 'Products', post: 'Posts', page: 'Pages' };
	function typeLabel(key) {
		if (TYPE_LABELS[key]) { return TYPE_LABELS[key]; }
		var k = String(key || 'other');
		var titled = k.charAt(0).toUpperCase() + k.slice(1);
		return /s$/i.test(titled) ? titled : (titled + 's');
	}

	// Stable, friendly ordering: product, post, page, then the rest alphabetically.
	function typeOrder(keys) {
		var pref = ['product', 'post', 'page'];
		var known = pref.filter(function (k) { return keys.indexOf(k) !== -1; });
		var rest = keys.filter(function (k) { return pref.indexOf(k) === -1; }).sort();
		return known.concat(rest);
	}

	// One collapsible "▸ Products (20)" section for the needs-keyphrase panel,
	// collapsed by default. Item titles link to the WP editor (textContent only).
	function buildNeedsKpSection(label, groupItems) {
		var section = el('div', 'def-draft-needs-kp-section');

		var btn = el('button', 'def-draft-needs-kp-toggle');
		btn.type = 'button';
		btn.setAttribute('aria-expanded', 'false');
		var arrow = el('span', 'def-draft-needs-kp-arrow', '▸');
		btn.appendChild(arrow);
		btn.appendChild(el('span', 'def-draft-needs-kp-toggle-label',
			label + ' (' + groupItems.length + ')'));

		var ul = document.createElement('ul');
		ul.className = 'def-draft-needs-kp-list';
		ul.style.display = 'none';
		groupItems.forEach(function (it) {
			var li = document.createElement('li');
			var name = (it.title && String(it.title).trim()) ||
				((it.item_type || 'item') + ' #' + (it.item_id != null ? it.item_id : '?'));
			var editHref = safeHref(it.edit_url);
			li.appendChild(editHref ? linkEl(name, editHref, 'def-draft-needs-kp-link')
				: el('span', null, name));
			ul.appendChild(li);
		});

		btn.addEventListener('click', function () {
			var open = ul.style.display !== 'none';
			ul.style.display = open ? 'none' : 'block';
			arrow.textContent = open ? '▸' : '▾';
			btn.setAttribute('aria-expanded', open ? 'false' : 'true');
		});

		section.appendChild(btn);
		section.appendChild(ul);
		return section;
	}

	// "Needs a focus keyphrase" panel — items the agent skipped because no keyphrase
	// is set, grouped by content type into collapsible sections (collapsed by
	// default so the panel stays compact as the list grows). Rendered above drafts.
	function renderNeedsKeyphrase(items) {
		if (!Array.isArray(items) || !items.length) { return; }

		// Group by item_type, preserving each item's order within its group.
		var groups = {};
		var seen = [];
		var total = 0;
		items.forEach(function (it) {
			if (!it || typeof it !== 'object') { return; }
			total++;
			var type = (typeof it.item_type === 'string' && it.item_type) ? it.item_type : 'other';
			if (!groups[type]) { groups[type] = []; seen.push(type); }
			groups[type].push(it);
		});
		if (!total) { return; }

		var panel = el('div', 'def-draft-needs-kp');
		panel.appendChild(el('div', 'def-draft-needs-kp-head',
			total + (total === 1 ? ' item needs' : ' items need') +
			' a focus keyphrase before the Content Agent can optimize ' +
			(total === 1 ? 'it' : 'them') + '. Set one in your SEO plugin:'));

		typeOrder(seen).forEach(function (type) {
			var groupItems = groups[type];
			if (groupItems && groupItems.length) {
				panel.appendChild(buildNeedsKpSection(typeLabel(type), groupItems));
			}
		});

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

	// Current-state coverage buckets, in display order. Each is hidden when 0.
	// Labels are static UI strings; only the count comes from the backend.
	var COVERAGE_BUCKETS = [
		{ key: 'good',            icon: '✅', text: function (n) { return n + ' good'; } },
		{ key: 'optimized',       icon: '✍️', text: function (n) { return n + ' optimized'; } },
		{ key: 'awaiting_review', icon: '📋', text: function (n) { return n + ' awaiting review'; } },
		{ key: 'needs_work',      icon: '🔧', text: function (n) { return n + (n === 1 ? ' needs work' : ' need work'); } },
		{ key: 'needs_keyphrase', icon: '🔑', text: function (n) { return n + (n === 1 ? ' needs a keyphrase' : ' need a keyphrase'); } },
		{ key: 'dismissed',       icon: '🚫', text: function (n) { return n + ' dismissed'; } },
		{ key: 'errored',         icon: '⚠️', text: function (n) { return n + ' errored'; }, warn: true }
	];

	// Build the "· ✅ 9 good · 🔑 20 need a keyphrase" run of stat chips for one
	// bucket map, hiding every zero bucket. Returns null when nothing is > 0.
	function buildBucketParts(buckets) {
		buckets = (buckets && typeof buckets === 'object') ? buckets : {};
		var parts = el('span', 'def-draft-coverage-parts');
		var shown = 0;
		COVERAGE_BUCKETS.forEach(function (b) {
			var n = num(buckets[b.key]);
			if (n <= 0) { return; }
			shown++;
			parts.appendChild(el('span',
				'def-draft-coverage-stat' + (b.warn ? ' def-draft-coverage-stat--warn' : ''),
				b.icon + ' ' + b.text(n)));
		});
		return shown ? parts : null;
	}

	// Small "Last run <time>" freshness line (from /last-run). Deliberately NO
	// per-run count breakdown — those froze stale against the live queue. Shows
	// "Running…" in flight and "No runs yet" before the first run.
	function buildFreshnessLine(lastRun) {
		var line = el('div', 'def-draft-coverage-fresh');
		if (!lastRun || typeof lastRun !== 'object') {
			line.textContent = 'No runs yet';
			return line;
		}
		var startedAt = (typeof lastRun.started_at === 'string') ? lastRun.started_at : '';
		var finishedAt = (typeof lastRun.finished_at === 'string') ? lastRun.finished_at : '';
		var status = (typeof lastRun.status === 'string') ? lastRun.status.toLowerCase() : '';
		// Terminal status leaves the spinner even if finished_at was never written.
		var terminal = /^(complete|finish|done|fail|error|abort|cancel|timed)/.test(status);
		if (startedAt && !finishedAt && !terminal) {
			line.classList.add('def-draft-coverage-fresh--running');
			line.textContent = 'Running…';
			return line;
		}
		var when = formatRunTime(finishedAt || startedAt);
		line.textContent = when ? ('Last run ' + when) : 'Last run';
		return line;
	}

	// Status strip headline: the CURRENT-STATE coverage breakdown per content type
	// (from /content/summary), plus a small last-run freshness line.
	//
	// Hard requirement: never a ratio/fraction. We show discrete bucket counts
	// only — most content legitimately needs no work, so "N/total optimized" would
	// imply false pending work. The only sanctioned denominator is "items
	// reviewed", and we don't even surface that here.
	//
	// Untrusted-data discipline: every value reaches the DOM via el()'s textContent
	// and num() — backend strings/ints are never written as innerHTML.
	function renderCoverageStrip(summary, lastRun) {
		var strip = el('div', 'def-draft-coverage');

		var byType = (summary && typeof summary === 'object' && summary.by_type &&
			typeof summary.by_type === 'object') ? summary.by_type : null;

		if (byType) {
			typeOrder(Object.keys(byType)).forEach(function (t) {
				var buckets = byType[t];
				if (!buckets || typeof buckets !== 'object') { return; }
				var parts = buildBucketParts(buckets);
				var total = num(buckets.total);
				// Skip only a genuinely empty type (nothing to show AND nothing
				// reviewed). Gating on `total` alone would drop a type that has
				// displayable buckets but a missing/zero total.
				if (!parts && total <= 0) { return; }
				if (!parts) {
					// Reviewed content exists but lands entirely outside the displayed
					// buckets (e.g. all 'other') — show a minimal reviewed count rather
					// than silently dropping the type. A discrete count, not a ratio.
					parts = el('span', 'def-draft-coverage-parts');
					parts.appendChild(el('span', 'def-draft-coverage-stat', total + ' reviewed'));
				}
				var row = el('div', 'def-draft-coverage-row');
				row.appendChild(el('span', 'def-draft-coverage-type', typeLabel(t) + ':'));
				row.appendChild(parts);
				strip.appendChild(row);
			});

			// Overall line (totals) — only meaningful when more than one type row
			// was shown; otherwise it just duplicates the single type row.
			if (strip.querySelectorAll('.def-draft-coverage-row').length > 1) {
				var overallParts = buildBucketParts(summary.totals);
				if (overallParts) {
					var orow = el('div', 'def-draft-coverage-row def-draft-coverage-row--overall');
					orow.appendChild(el('span', 'def-draft-coverage-type', 'Overall:'));
					orow.appendChild(overallParts);
					strip.appendChild(orow);
				}
			}
		}

		// Freshness line always renders (it's the one thing we always know).
		strip.appendChild(buildFreshnessLine(lastRun));

		root.insertBefore(strip, root.firstChild);
	}

	// "Create a post" control — a keyphrase input + Generate button that asks DEF
	// to generate a new optimized post. The draft then appears in the queue (kind
	// 'create'). Rendered into its own host outside the cards root, so the drafts
	// fetch's root.innerHTML reset never wipes it. Only shown when the user can
	// create posts (the bridge re-checks the capability server-side).
	function renderCreateControl() {
		if (!cfg.canCreate) { return; }
		var host = document.getElementById('def-draft-create');
		if (!host) { return; }

		var panel = el('div', 'def-draft-create-panel');
		panel.appendChild(el('label', 'def-draft-create-label', 'Create a new post'));

		var rowEl = el('div', 'def-draft-create-row');
		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'def-draft-create-input regular-text';
		input.setAttribute('placeholder', 'Focus keyphrase, e.g. best running shoes');
		var btn = el('button', 'button button-primary def-draft-create-btn', 'Generate');
		btn.type = 'button';
		var status = el('div', 'def-draft-create-status');

		function submit() {
			var kp = input.value.trim();
			if (!kp) {
				status.className = 'def-draft-create-status def-draft-create-status--warn';
				status.textContent = 'Enter a keyphrase first.';
				return;
			}
			btn.disabled = true;
			input.disabled = true;
			status.className = 'def-draft-create-status';
			status.textContent = 'Requesting…';
			api('/create', 'POST', { keyphrase: kp }).then(function () {
				status.className = 'def-draft-create-status def-draft-create-status--ok';
				status.textContent = 'Your draft is being generated and will appear below shortly.';
				input.value = '';
				// Generation is async on the DEF side — poll until the new card
				// lands (or 10 minutes pass), so no manual reload is needed.
				startDraftsRefresh(function () {
					status.textContent = 'Your new draft is ready below.';
				}, function () {
					status.textContent = 'Still generating — your draft will appear under Content Drafts when ready; reload in a minute.';
				});
			}).catch(function (e) {
				status.className = 'def-draft-create-status def-draft-create-status--warn';
				status.textContent = (e && e.message) || 'Could not start generation.';
			}).then(function () {
				btn.disabled = false;
				input.disabled = false;
			});
		}
		btn.addEventListener('click', submit);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); submit(); }
		});

		rowEl.appendChild(input);
		rowEl.appendChild(btn);
		panel.appendChild(rowEl);
		panel.appendChild(status);
		panel.appendChild(el('div', 'def-draft-safeguard', SAFEGUARD_COPY));
		host.appendChild(panel);
	}

	renderCreateControl();

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
		// Coverage status strip — best-effort, pinned to the very top (inserted last
		// so it sits above the needs-keyphrase panel and the cards). Pull current
		// coverage (/summary) and last-run freshness (/last-run) independently; each
		// resolves to null on failure so a missing endpoint just omits its part and
		// the queue still renders.
		return Promise.all([
			api('/summary', 'GET').then(function (res) { return res && res.summary; }, function () { return null; }),
			api('/last-run', 'GET').then(function (res) { return res && res.last_run; }, function () { return null; })
		]).then(function (vals) {
			renderCoverageStrip(vals[0], vals[1]);
		}).catch(function () { /* ignore — the queue still works */ });
	});
})();
