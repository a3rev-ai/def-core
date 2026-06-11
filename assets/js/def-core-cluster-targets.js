/**
 * Content Agent — Clusters tab (Clusters UX v2).
 *
 * Master list of compact target rows (text search, needs-curation /
 * needs-review / status / type filters, needs-attention-first sort, lazy
 * render) with one-at-a-time inline-expand detail: reference-URL editor,
 * Derive/Pause/Remove, and the keyphrase queue with approve / edit / dismiss,
 * bulk "Dismiss remaining", and per-row lifecycle states (proposed → queued →
 * in review → published / dismissed) derived client-side from fields the DEF
 * API already returns.
 *
 * All model-authored text (phrase, rationale) and backend strings reach the
 * DOM via textContent only — this module has no innerHTML path.
 */
(function () {
	'use strict';

	var cfg = window.DefDraftCards || {};
	var root = document.getElementById('def-cluster-root');
	if (!root || !cfg.restBase) {
		return;
	}

	// Same fail-closed safeguard copy as the create surfaces (design §6/§7).
	var SAFEGUARD_COPY = 'AI-created posts are excluded from Company Knowledge until you expressly include them. ' +
		"Review the images and verify every claim before unchecking 'Exclude from Digital Employee knowledge' on the post.";

	var MAX_REFERENCE_URLS = 5;
	var PAGE_SIZE = 25;

	var INTENT_LABELS = {
		definitional: 'Definitional',
		capability: 'Capability',
		how_to: 'How-to',
		use_case: 'Use case',
		comparison_buying: 'Comparison / buying',
		durability_maintenance: 'Durability / maintenance'
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

	function el(tag, className, text) {
		var n = document.createElement(tag);
		if (className) { n.className = className; }
		if (text != null) { n.textContent = text; }
		return n;
	}

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

	function intentLabel(key) {
		return INTENT_LABELS[key] || String(key || '');
	}

	function intentSelect(selected) {
		var sel = document.createElement('select');
		sel.className = 'def-cluster-intent-select';
		Object.keys(INTENT_LABELS).forEach(function (k) {
			var opt = document.createElement('option');
			opt.value = k;
			opt.textContent = INTENT_LABELS[k];
			if (k === selected) { opt.selected = true; }
			sel.appendChild(opt);
		});
		return sel;
	}

	function setStatus(node, message, kind) {
		node.className = 'def-cluster-status' + (kind ? ' def-cluster-status--' + kind : '');
		node.textContent = message || '';
	}

	function num(v) {
		var n = Number(v);
		return (isFinite(n) && n > 0) ? Math.floor(n) : 0;
	}

	// ── Keyphrase lifecycle (derived client-side; design §4) ────────────────
	// proposed → awaiting curation; approved without a staged draft → queued;
	// approved WITH staged_change_id → in review (a draft awaits the human —
	// the most important state); written → published; dismissed → cleared.

	function lifecycle(row) {
		var s = (row && typeof row.status === 'string') ? row.status : 'other';
		if (s === 'approved') { return row.staged_change_id ? 'in_review' : 'queued'; }
		if (s === 'written') { return 'published'; }
		return s;
	}

	// Normalize a target's keyphrase_counts. Older DEF responses don't split
	// in_review out of approved — a missing in_review is 0, never NaN.
	function normalizeCounts(raw) {
		raw = (raw && typeof raw === 'object') ? raw : {};
		return {
			proposed: num(raw.proposed),
			approved: num(raw.approved),
			in_review: num(raw.in_review),
			written: num(raw.written),
			dismissed: num(raw.dismissed)
		};
	}

	function countsFromRows(rows) {
		var c = { proposed: 0, approved: 0, in_review: 0, written: 0, dismissed: 0 };
		rows.forEach(function (r) {
			var lc = lifecycle(r);
			if (lc === 'proposed') { c.proposed++; }
			else if (lc === 'queued') { c.approved++; }
			else if (lc === 'in_review') { c.in_review++; }
			else if (lc === 'published') { c.written++; }
			else if (lc === 'dismissed') { c.dismissed++; }
		});
		return c;
	}

	// "9 proposed · 5 approved · 1 in review · 2 published · 4 dismissed",
	// zero buckets omitted.
	function countsSummary(c) {
		var parts = [];
		if (c.proposed) { parts.push(c.proposed + ' proposed'); }
		if (c.approved) { parts.push(c.approved + ' approved'); }
		if (c.in_review) { parts.push(c.in_review + ' in review'); }
		if (c.written) { parts.push(c.written + ' published'); }
		if (c.dismissed) { parts.push(c.dismissed + ' dismissed'); }
		return parts.length ? parts.join(' · ') : 'no keyphrases yet';
	}

	function dateLabel(iso) {
		if (typeof iso !== 'string' || !iso) { return ''; }
		var d = new Date(iso);
		if (isNaN(d.getTime())) { return ''; }
		try {
			return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
		} catch (e) {
			return '';
		}
	}

	// Jump to an in-review keyphrase's draft card on the Drafts tabs. The card
	// carries data-draft-id (staged id === draft id); its tab panel tells us
	// which tab to activate via the existing tab handler.
	function jumpToDraft(stagedId) {
		var id = String(stagedId == null ? '' : stagedId).replace(/[^a-zA-Z0-9_-]/g, '');
		var card = id ? document.querySelector('.def-draft-card[data-draft-id="' + id + '"]') : null;
		var panel = card ? card.closest('.def-draft-tab-panel') : null;
		// Cluster posts are new posts, so their review cards live on the Create tab.
		var tabName = (panel && panel.id.indexOf('def-tab-') === 0) ? panel.id.slice(8) : 'create';
		var tab = document.querySelector('.def-draft-tabs [data-def-tab="' + tabName + '"]');
		if (tab) { tab.click(); }
		if (card) {
			card.scrollIntoView({ behavior: 'smooth', block: 'start' });
			card.classList.add('def-draft-card--jumped');
			setTimeout(function () { card.classList.remove('def-draft-card--jumped'); }, 2500);
		} else {
			window.scrollTo({ top: 0, behavior: 'smooth' });
		}
	}

	// ── Nominate panel: native WP item picker (pages + posts + products) ────

	function buildReferenceUrlEditor(initial) {
		// A list of URL rows (input + remove) capped at MAX_REFERENCE_URLS.
		// value() returns the trimmed, non-empty entries in order.
		var wrap = el('div', 'def-cluster-refurls');
		wrap.appendChild(el('div', 'def-cluster-refurls-label',
			'Reference URLs (optional, max ' + MAX_REFERENCE_URLS + ') — authoritative pages the derive run reads, e.g. your docs'));
		var list = el('div', 'def-cluster-refurls-list');
		wrap.appendChild(list);
		var addBtn = el('button', 'button def-cluster-refurls-add', '+ Add URL');
		addBtn.type = 'button';
		wrap.appendChild(addBtn);

		function addRow(value) {
			if (list.children.length >= MAX_REFERENCE_URLS) { return; }
			var row = el('div', 'def-cluster-refurls-row');
			var input = document.createElement('input');
			input.type = 'url';
			input.className = 'def-cluster-refurls-input regular-text';
			input.setAttribute('placeholder', 'https://…');
			if (value) { input.value = value; }
			var rm = el('button', 'button-link def-cluster-refurls-remove', '×');
			rm.type = 'button';
			rm.setAttribute('aria-label', 'Remove URL');
			rm.addEventListener('click', function () {
				row.remove();
				addBtn.disabled = false;
			});
			row.appendChild(input);
			row.appendChild(rm);
			list.appendChild(row);
			addBtn.disabled = list.children.length >= MAX_REFERENCE_URLS;
		}

		addBtn.addEventListener('click', function () { addRow(''); });
		(Array.isArray(initial) ? initial : []).forEach(function (u) {
			if (typeof u === 'string' && u) { addRow(u); }
		});

		return {
			node: wrap,
			value: function () {
				var urls = [];
				list.querySelectorAll('input').forEach(function (i) {
					var v = i.value.trim();
					if (v) { urls.push(v); }
				});
				return urls;
			}
		};
	}

	function renderNominatePanel() {
		var panel = el('div', 'def-cluster-nominate');
		panel.appendChild(el('label', 'def-cluster-nominate-label', 'Nominate a cluster target'));
		panel.appendChild(el('p', 'def-cluster-nominate-hint',
			'Search your published pages, posts and products for the cornerstone item this cluster will point at.'));

		var input = document.createElement('input');
		input.type = 'search';
		input.className = 'def-cluster-search-input regular-text';
		input.setAttribute('placeholder', 'Search your content…');
		panel.appendChild(input);

		var results = el('div', 'def-cluster-search-results');
		panel.appendChild(results);

		var selection = el('div', 'def-cluster-selection');
		selection.style.display = 'none';
		panel.appendChild(selection);

		var status = el('div', 'def-cluster-status');
		panel.appendChild(status);

		var searchTimer = null;
		var searchSeq = 0;

		function clearResults() { results.textContent = ''; }

		function showSelection(item) {
			clearResults();
			input.value = '';
			selection.textContent = '';
			selection.style.display = '';

			var head = el('div', 'def-cluster-selection-head');
			head.appendChild(el('strong', null, item.title || '(untitled)'));
			head.appendChild(el('span', 'def-cluster-item-meta',
				' ' + item.item_type + ' #' + item.item_id));
			selection.appendChild(head);
			var href = safeHref(item.url);
			if (href) {
				selection.appendChild(linkEl(item.url, href, 'def-cluster-selection-url'));
			}

			var refs = buildReferenceUrlEditor([]);
			selection.appendChild(refs.node);

			var actions = el('div', 'def-cluster-selection-actions');
			var nominate = el('button', 'button button-primary', 'Nominate target');
			nominate.type = 'button';
			var cancel = el('button', 'button', 'Cancel');
			cancel.type = 'button';
			actions.appendChild(nominate);
			actions.appendChild(cancel);
			selection.appendChild(actions);

			cancel.addEventListener('click', function () {
				selection.style.display = 'none';
				selection.textContent = '';
				setStatus(status, '');
			});

			nominate.addEventListener('click', function () {
				nominate.disabled = true;
				setStatus(status, 'Nominating…');
				api('/targets', 'POST', {
					item_type: item.item_type,
					item_id: item.item_id,
					source_route: item.source_route,
					title: item.title,
					url: item.url,
					reference_urls: refs.value()
				}).then(function () {
					selection.style.display = 'none';
					selection.textContent = '';
					setStatus(status, 'Target nominated.', 'ok');
					loadTargets();
				}).catch(function (e) {
					nominate.disabled = false;
					setStatus(status, (e && e.message) || 'Could not nominate the target.', 'warn');
				});
			});
		}

		function search(q) {
			var seq = ++searchSeq;
			api('/target-search?q=' + encodeURIComponent(q)).then(function (res) {
				if (seq !== searchSeq) { return; } // stale response
				clearResults();
				var items = (res && res.items) || [];
				if (!items.length) {
					results.appendChild(el('div', 'def-cluster-search-empty', 'No published items match.'));
					return;
				}
				items.forEach(function (item) {
					if (!item || typeof item !== 'object') { return; }
					var btn = el('button', 'def-cluster-search-result');
					btn.type = 'button';
					btn.appendChild(el('span', 'def-cluster-result-title', String(item.title || '(untitled)')));
					btn.appendChild(el('span', 'def-cluster-item-meta',
						String(item.item_type || 'item') + ' #' + String(item.item_id || '?')));
					btn.addEventListener('click', function () { showSelection(item); });
					results.appendChild(btn);
				});
			}).catch(function () {
				if (seq !== searchSeq) { return; }
				clearResults();
				results.appendChild(el('div', 'def-cluster-search-empty', 'Search failed — try again.'));
			});
		}

		input.addEventListener('input', function () {
			if (searchTimer) { clearTimeout(searchTimer); }
			var q = input.value.trim();
			if (q.length < 2) { clearResults(); return; }
			searchTimer = setTimeout(function () { search(q); }, 300);
		});

		return panel;
	}

	// "+ Add target" keeps the nominate picker at the top of the list but out
	// of the way until asked for.
	function renderAddTarget() {
		var wrap = el('div', 'def-cluster-add');
		var toggle = el('button', 'button def-cluster-add-toggle', '+ Add target');
		toggle.type = 'button';
		toggle.setAttribute('aria-expanded', 'false');
		var panel = renderNominatePanel();
		panel.style.display = 'none';
		toggle.addEventListener('click', function () {
			var open = panel.style.display !== 'none';
			panel.style.display = open ? 'none' : '';
			toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
		});
		wrap.appendChild(toggle);
		wrap.appendChild(panel);
		return wrap;
	}

	// ── Keyphrase queue ─────────────────────────────────────────────────────

	function renderQueueRow(row, target, card) {
		var lc = lifecycle(row);
		var li = el('li', 'def-cluster-kp def-cluster-kp--' + lc.replace('_', '-'));

		var main = el('div', 'def-cluster-kp-main');
		main.appendChild(el('span', 'def-cluster-kp-phrase', String(row.phrase || '')));
		main.appendChild(el('span', 'def-cluster-kp-intent', intentLabel(row.intent_type)));
		if (lc === 'queued') {
			main.appendChild(el('span', 'def-cluster-kp-state def-cluster-kp-state--queued', 'queued'));
		} else if (lc === 'in_review') {
			main.appendChild(el('span', 'def-cluster-kp-state def-cluster-kp-state--in-review', 'in review'));
		} else if (lc === 'published') {
			main.appendChild(el('span', 'def-cluster-kp-state def-cluster-kp-state--published', 'published'));
		}
		li.appendChild(main);

		if (row.rationale) {
			li.appendChild(el('div', 'def-cluster-kp-rationale', String(row.rationale)));
		}

		var actions = el('div', 'def-cluster-kp-actions');

		function act(label, path, primary) {
			var b = el('button', (primary ? 'button button-small button-primary' : 'button button-small'), label);
			b.type = 'button';
			b.addEventListener('click', function () {
				li.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
				api(path, 'POST').then(function () {
					reloadQueue(target, card);
				}).catch(function (e) {
					li.querySelectorAll('button').forEach(function (x) { x.disabled = false; });
					setStatus(card.queueStatus, (e && e.message) || 'Action failed.', 'warn');
				});
			});
			return b;
		}

		function startEdit() {
			var editor = el('div', 'def-cluster-kp-edit');
			var phraseInput = document.createElement('input');
			phraseInput.type = 'text';
			phraseInput.className = 'def-cluster-kp-edit-input regular-text';
			phraseInput.maxLength = 120;
			phraseInput.value = String(row.phrase || '');
			var intentSel = intentSelect(row.intent_type);
			var save = el('button', 'button button-small button-primary', 'Save');
			save.type = 'button';
			var cancel = el('button', 'button button-small', 'Cancel');
			cancel.type = 'button';
			editor.appendChild(phraseInput);
			editor.appendChild(intentSel);
			editor.appendChild(save);
			editor.appendChild(cancel);
			li.appendChild(editor);
			actions.style.display = 'none';

			cancel.addEventListener('click', function () {
				editor.remove();
				actions.style.display = '';
			});
			save.addEventListener('click', function () {
				var phrase = phraseInput.value.trim();
				if (!phrase) {
					setStatus(card.queueStatus, 'Enter a phrase.', 'warn');
					return;
				}
				save.disabled = true;
				api('/keyphrases/' + encodeURIComponent(row.id), 'PATCH', {
					phrase: phrase,
					intent_type: intentSel.value
				}).then(function () {
					reloadQueue(target, card);
				}).catch(function (e) {
					save.disabled = false;
					setStatus(card.queueStatus, (e && e.message) || 'Edit failed.', 'warn');
				});
			});
		}

		if (lc === 'proposed') {
			actions.appendChild(act('Approve', '/keyphrases/' + encodeURIComponent(row.id) + '/approve', true));
		}
		if (lc === 'proposed' || lc === 'queued') {
			var edit = el('button', 'button button-small', 'Edit');
			edit.type = 'button';
			edit.addEventListener('click', startEdit);
			actions.appendChild(edit);
			actions.appendChild(act('Dismiss', '/keyphrases/' + encodeURIComponent(row.id) + '/dismiss'));
		}
		if (lc === 'in_review') {
			// The most important state: the draft sits in Content Drafts for the
			// human — link straight to its review card.
			var review = el('button', 'button-link def-cluster-kp-review-link', 'Review draft →');
			review.type = 'button';
			review.addEventListener('click', function () { jumpToDraft(row.staged_change_id); });
			actions.appendChild(review);
		}
		if (lc === 'published') {
			var editHref = safeHref(row.edit_url);
			var viewHref = safeHref(row.view_url);
			if (editHref) { actions.appendChild(linkEl('Edit post ↗', editHref, 'def-cluster-kp-link')); }
			if (viewHref) { actions.appendChild(linkEl('View post ↗', viewHref, 'def-cluster-kp-link')); }
		}
		if (actions.children.length) { li.appendChild(actions); }

		return li;
	}

	// A "▾ Label (N)" collapsible section head + its list, with optional extra
	// head content (e.g. the Dismiss-remaining button).
	function collapsibleGroup(box, label, count, ul, startOpen, extraHead) {
		var head = el('div', 'def-cluster-kp-group-row');
		var toggle = el('button', 'def-cluster-kp-group-toggle',
			(startOpen ? '▾ ' : '▸ ') + label + ' (' + count + ')');
		toggle.type = 'button';
		toggle.setAttribute('aria-expanded', startOpen ? 'true' : 'false');
		ul.style.display = startOpen ? '' : 'none';
		toggle.addEventListener('click', function () {
			var open = ul.style.display !== 'none';
			ul.style.display = open ? 'none' : '';
			toggle.textContent = (open ? '▸ ' : '▾ ') + label + ' (' + count + ')';
			toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
		});
		head.appendChild(toggle);
		if (extraHead) { head.appendChild(extraHead); }
		box.appendChild(head);
		box.appendChild(ul);
	}

	// Bulk-clear of the un-chosen proposals (design §3). Not a delete — the
	// phrases land in the collapsed Dismissed group and keep their slot, so
	// the next Derive won't re-propose them.
	function buildDismissRemaining(target, card, count) {
		var btn = el('button', 'button button-small def-cluster-dismiss-remaining',
			'Dismiss remaining (' + count + ')');
		btn.type = 'button';
		btn.addEventListener('click', function () {
			btn.disabled = true;
			setStatus(card.queueStatus, 'Dismissing remaining proposals…');
			api('/targets/' + encodeURIComponent(target.id) + '/keyphrases/dismiss-remaining', 'POST').then(function (res) {
				var n = num(res && res.dismissed);
				reloadQueue(target, card).then(function (rows) {
					if (!rows) { return; } // reload failed — keep its warn over a stale queue
					setStatus(card.queueStatus,
						'Dismissed ' + n + ' proposed ' + (n === 1 ? 'phrase' : 'phrases') +
						" — they won't be re-proposed on the next Derive.", 'ok');
				});
			}).catch(function (e) {
				btn.disabled = false;
				setStatus(card.queueStatus, (e && e.message) || 'Could not dismiss the remaining proposals.', 'warn');
			});
		});
		return btn;
	}

	function renderQueue(card, target, rows) {
		var box = card.queueBox;
		box.textContent = '';

		// Keep the row's at-a-glance counts AND the master list's filter/sort
		// data fresh (target is the live allTargets entry).
		var counts = countsFromRows(rows);
		target.keyphrase_counts = counts;
		card.countsEl.textContent = countsSummary(counts);

		var groups = {};
		rows.forEach(function (r) {
			if (!r || typeof r !== 'object') { return; }
			(groups[lifecycle(r)] = groups[lifecycle(r)] || []).push(r);
		});

		function listOf(rs) {
			var ul = el('ul', 'def-cluster-kp-list');
			rs.forEach(function (r) { ul.appendChild(renderQueueRow(r, target, card)); });
			return ul;
		}

		// Proposed: collapsible, open by default — curation is the work to do.
		var proposed = groups.proposed;
		if (proposed && proposed.length) {
			var extra = el('span', 'def-cluster-kp-group-extra');
			extra.appendChild(buildDismissRemaining(target, card, proposed.length));
			extra.appendChild(el('span', 'def-cluster-kp-group-hint',
				'Approve 5–10 picks for a healthy cluster, then clear the rest.'));
			collapsibleGroup(box, 'Proposed', proposed.length, listOf(proposed), true, extra);
		}

		// The rest of the lifecycle, top to bottom (design §4).
		[['queued', 'Queued'], ['in_review', 'In review'], ['published', 'Published']].forEach(function (g) {
			var list = groups[g[0]];
			if (!list || !list.length) { return; }
			box.appendChild(el('div', 'def-cluster-kp-group-head', g[1] + ' (' + list.length + ')'));
			box.appendChild(listOf(list));
		});

		// Dismissed: collapsed — slots stay claimed but they're noise day-to-day.
		var dismissed = groups.dismissed;
		if (dismissed && dismissed.length) {
			collapsibleGroup(box, 'Dismissed', dismissed.length, listOf(dismissed), false, null);
		}

		if (!rows.length) {
			box.appendChild(el('p', 'def-cluster-kp-empty',
				'No keyphrases queued yet. Click Derive to have the Content Agent propose some, or add your own below.'));
		}

		// Manual add (born approved — human-added IS curation).
		var addRow = el('div', 'def-cluster-kp-add');
		var phraseInput = document.createElement('input');
		phraseInput.type = 'text';
		phraseInput.className = 'def-cluster-kp-add-input regular-text';
		phraseInput.maxLength = 120;
		phraseInput.setAttribute('placeholder', 'Add a keyphrase, e.g. how does a quote become an order');
		var intentSel = intentSelect('how_to');
		var addBtn = el('button', 'button', 'Add');
		addBtn.type = 'button';
		addRow.appendChild(phraseInput);
		addRow.appendChild(intentSel);
		addRow.appendChild(addBtn);
		box.appendChild(addRow);

		addBtn.addEventListener('click', function () {
			var phrase = phraseInput.value.trim();
			if (!phrase) {
				setStatus(card.queueStatus, 'Enter a phrase first.', 'warn');
				return;
			}
			addBtn.disabled = true;
			api('/targets/' + encodeURIComponent(target.id) + '/keyphrases', 'POST', {
				phrase: phrase,
				intent_type: intentSel.value
			}).then(function () {
				reloadQueue(target, card);
			}).catch(function (e) {
				addBtn.disabled = false;
				setStatus(card.queueStatus, (e && e.message) || 'Could not add the keyphrase.', 'warn');
			});
		});
	}

	function reloadQueue(target, card) {
		setStatus(card.queueStatus, '');
		return api('/targets/' + encodeURIComponent(target.id) + '/keyphrases').then(function (res) {
			var rows = (res && res.keyphrases) || [];
			renderQueue(card, target, rows);
			return rows;
		}).catch(function (e) {
			setStatus(card.queueStatus, (e && e.message) || 'Could not load the queue.', 'warn');
			return null;
		});
	}

	// ── Derive: enqueue-and-ack, then poll for new proposed rows ────────────

	function startDerive(target, card, btn) {
		btn.disabled = true;
		setStatus(card.queueStatus, 'Requesting derive…');
		api('/targets/' + encodeURIComponent(target.id) + '/derive', 'POST').then(function () {
			setStatus(card.queueStatus,
				'Deriving — the agent is reading the target, your reference URLs and the market. New suggestions appear as proposed rows (15–60s)…');
			// Snapshot current row ids via a fresh fetch, then poll for additions.
			var knownIds = {};
			api('/targets/' + encodeURIComponent(target.id) + '/keyphrases').then(function (res) {
				((res && res.keyphrases) || []).forEach(function (r) {
					if (r && r.id != null) { knownIds[String(r.id)] = 1; }
				});
				var deadline = Date.now() + 120000;
				var timer = setInterval(function () {
					if (Date.now() > deadline) {
						clearInterval(timer);
						btn.disabled = false;
						setStatus(card.queueStatus,
							'Still working — new suggestions will appear in the queue; refresh in a moment.');
						return;
					}
					api('/targets/' + encodeURIComponent(target.id) + '/keyphrases').then(function (res2) {
						var rows = (res2 && res2.keyphrases) || [];
						var hasNew = rows.some(function (r) {
							return r && r.id != null && !knownIds[String(r.id)];
						});
						if (hasNew) {
							clearInterval(timer);
							btn.disabled = false;
							renderQueue(card, target, rows);
							setStatus(card.queueStatus, 'New suggestions added — review them below.', 'ok');
						}
					}).catch(function () { /* transient — next tick retries until the deadline */ });
				}, 5000);
			});
		}).catch(function (e) {
			btn.disabled = false;
			setStatus(card.queueStatus, (e && e.message) || 'Could not start derive.', 'warn');
		});
	}

	// ── Inline-expand detail (the v1 card body, one target at a time) ───────

	function renderTargetDetail(target, countsEl) {
		var card = el('div', 'def-cluster-detail');

		var top = el('div', 'def-cluster-detail-top');
		top.appendChild(el('span', 'def-cluster-item-meta',
			String(target.item_type || 'item') + ' #' + String(target.item_id || '?')));
		var href = safeHref(target.url);
		if (href) { top.appendChild(linkEl('View target ↗', href, 'def-cluster-kp-link')); }
		card.appendChild(top);

		if (target.focus_keyphrase) {
			var kp = el('div', 'def-cluster-focus');
			kp.appendChild(el('span', 'def-cluster-focus-label', 'Target focus keyphrase'));
			kp.appendChild(el('span', 'def-cluster-focus-value', String(target.focus_keyphrase)));
			card.appendChild(kp);
		}

		// Reference URLs — editable; Save PATCHes the full list.
		var refs = buildReferenceUrlEditor(Array.isArray(target.reference_urls) ? target.reference_urls : []);
		card.appendChild(refs.node);
		var saveRefs = el('button', 'button button-small def-cluster-refurls-save', 'Save reference URLs');
		saveRefs.type = 'button';
		refs.node.appendChild(saveRefs);

		var actions = el('div', 'def-cluster-card-actions');
		var derive = el('button', 'button button-primary', 'Derive keyphrases');
		derive.type = 'button';
		if (target.status === 'paused') {
			derive.disabled = true;
			derive.title = 'Resume the target to derive.';
		}
		var pause = el('button', 'button', target.status === 'paused' ? 'Resume' : 'Pause');
		pause.type = 'button';
		var remove = el('button', 'button-link-delete def-cluster-remove', 'Remove target');
		remove.type = 'button';
		actions.appendChild(derive);
		actions.appendChild(pause);
		actions.appendChild(remove);
		card.appendChild(actions);

		var queueStatus = el('div', 'def-cluster-status');
		card.appendChild(queueStatus);
		var queueBox = el('div', 'def-cluster-queue');
		card.appendChild(queueBox);

		// The list row owns the at-a-glance counts; queue loads keep it fresh.
		card.countsEl = countsEl;
		card.queueBox = queueBox;
		card.queueStatus = queueStatus;

		derive.addEventListener('click', function () { startDerive(target, card, derive); });

		pause.addEventListener('click', function () {
			pause.disabled = true;
			var next = target.status === 'paused' ? 'active' : 'paused';
			api('/targets/' + encodeURIComponent(target.id), 'PATCH', { status: next }).then(function () {
				loadTargets();
			}).catch(function (e) {
				pause.disabled = false;
				setStatus(queueStatus, (e && e.message) || 'Could not update the target.', 'warn');
			});
		});

		remove.addEventListener('click', function () {
			if (!window.confirm('Remove this cluster target? Its keyphrase queue is deleted; published posts are untouched.')) {
				return;
			}
			remove.disabled = true;
			api('/targets/' + encodeURIComponent(target.id), 'DELETE').then(function () {
				loadTargets();
			}).catch(function (e) {
				remove.disabled = false;
				setStatus(queueStatus, (e && e.message) || 'Could not remove the target.', 'warn');
			});
		});

		saveRefs.addEventListener('click', function () {
			saveRefs.disabled = true;
			api('/targets/' + encodeURIComponent(target.id), 'PATCH', {
				reference_urls: refs.value()
			}).then(function () {
				saveRefs.disabled = false;
				setStatus(queueStatus, 'Reference URLs saved.', 'ok');
			}).catch(function (e) {
				saveRefs.disabled = false;
				setStatus(queueStatus, (e && e.message) || 'Could not save the URLs.', 'warn');
			});
		});

		reloadQueue(target, card);
		return card;
	}

	// ── Master list: compact rows + filters + needs-attention sort ──────────

	var allTargets = [];
	var openTargetId = null;   // accordion: the one expanded target (survives re-renders)
	var openHandle = null;     // live nodes of the expanded row
	var shownLimit = PAGE_SIZE;
	var filters = { q: '', needsCuration: false, needsReview: false, status: 'all', itemType: 'all' };
	var listBox = null;
	var typeSelect = null;

	// Needs review (a draft awaits the human) beats needs curation (proposals
	// await approve/dismiss) beats the rest; most recent first within groups.
	function attentionRank(c) {
		if (c.in_review > 0) { return 0; }
		if (c.proposed > 0) { return 1; }
		return 2;
	}

	function recency(t) {
		var d = new Date(t.last_derived_at || t.created_at || 0);
		var ms = d.getTime();
		return isNaN(ms) ? 0 : ms;
	}

	function visibleTargets() {
		var q = filters.q.toLowerCase();
		var out = allTargets.filter(function (t) {
			var c = normalizeCounts(t.keyphrase_counts);
			if (q && String(t.title || '').toLowerCase().indexOf(q) === -1) { return false; }
			if (filters.needsCuration && !c.proposed) { return false; }
			if (filters.needsReview && !c.in_review) { return false; }
			if (filters.status !== 'all' && t.status !== filters.status) { return false; }
			if (filters.itemType !== 'all' && t.item_type !== filters.itemType) { return false; }
			return true;
		});
		out.sort(function (a, b) {
			var r = attentionRank(normalizeCounts(a.keyphrase_counts)) -
				attentionRank(normalizeCounts(b.keyphrase_counts));
			return r || (recency(b) - recency(a));
		});
		return out;
	}

	function collapseOpen() {
		if (!openHandle) { return; }
		openHandle.detail.remove();
		openHandle.caret.textContent = '▸';
		openHandle.head.setAttribute('aria-expanded', 'false');
		openHandle.row.classList.remove('def-cluster-row--open');
		openHandle = null;
		openTargetId = null;
	}

	function expandRow(target, parts) {
		collapseOpen();
		var detail = renderTargetDetail(target, parts.counts);
		parts.row.appendChild(detail);
		parts.caret.textContent = '▾';
		parts.head.setAttribute('aria-expanded', 'true');
		parts.row.classList.add('def-cluster-row--open');
		openHandle = { row: parts.row, head: parts.head, caret: parts.caret, detail: detail };
		openTargetId = target.id;
	}

	function renderRow(target) {
		var row = el('div', 'def-cluster-row');
		var head = el('button', 'def-cluster-row-head');
		head.type = 'button';
		head.setAttribute('aria-expanded', 'false');

		var caret = el('span', 'def-cluster-row-caret', '▸');
		head.appendChild(caret);
		head.appendChild(el('span', 'def-cluster-row-title', String(target.title || '(untitled)')));
		head.appendChild(el('span', 'def-cluster-item-meta', String(target.item_type || 'item')));
		var paused = target.status === 'paused';
		var dot = el('span', 'def-cluster-dot def-cluster-dot--' + (paused ? 'paused' : 'active'));
		dot.title = paused ? 'Paused' : 'Active';
		head.appendChild(dot);
		var counts = el('span', 'def-cluster-row-counts',
			countsSummary(normalizeCounts(target.keyphrase_counts)));
		head.appendChild(counts);
		var derived = dateLabel(target.last_derived_at);
		var added = dateLabel(target.created_at);
		head.appendChild(el('span', 'def-cluster-row-date',
			derived ? ('derived ' + derived) : (added ? ('added ' + added) : '')));
		row.appendChild(head);

		var parts = { row: row, head: head, caret: caret, counts: counts };
		head.addEventListener('click', function () {
			if (openHandle && openHandle.row === row) { collapseOpen(); return; }
			expandRow(target, parts);
		});

		if (openTargetId != null && String(target.id) === String(openTargetId)) {
			expandRow(target, parts);
		}
		return row;
	}

	function renderList() {
		openHandle = null; // nodes below are replaced; openTargetId re-expands its row
		listBox.textContent = '';
		if (!allTargets.length) {
			listBox.appendChild(el('p', 'def-cluster-empty',
				'No cluster targets yet. Nominate a cornerstone page, post or product above to start a cluster.'));
			return;
		}
		var visible = visibleTargets();
		if (!visible.length) {
			listBox.appendChild(el('p', 'def-cluster-empty', 'No targets match the current filters.'));
			return;
		}
		visible.slice(0, shownLimit).forEach(function (t) { listBox.appendChild(renderRow(t)); });
		var hidden = visible.length - shownLimit;
		if (hidden > 0) {
			var more = el('button', 'button def-cluster-show-more',
				'Show ' + Math.min(PAGE_SIZE, hidden) + ' more (' + hidden + ' hidden)');
			more.type = 'button';
			more.addEventListener('click', function () {
				shownLimit += PAGE_SIZE;
				renderList();
			});
			listBox.appendChild(more);
		}
	}

	function rebuildTypeOptions() {
		var types = [];
		allTargets.forEach(function (t) {
			var ty = (t && typeof t.item_type === 'string') ? t.item_type : '';
			if (ty && types.indexOf(ty) === -1) { types.push(ty); }
		});
		types.sort();
		typeSelect.textContent = '';
		var all = document.createElement('option');
		all.value = 'all';
		all.textContent = 'Any type';
		typeSelect.appendChild(all);
		types.forEach(function (ty) {
			var opt = document.createElement('option');
			opt.value = ty;
			opt.textContent = ty;
			typeSelect.appendChild(opt);
		});
		if (filters.itemType !== 'all' && types.indexOf(filters.itemType) === -1) {
			filters.itemType = 'all';
		}
		typeSelect.value = filters.itemType;
	}

	function onFilterChange() {
		shownLimit = PAGE_SIZE;
		renderList();
	}

	function renderToolbar() {
		var bar = el('div', 'def-cluster-toolbar');

		var search = document.createElement('input');
		search.type = 'search';
		search.className = 'def-cluster-filter-search';
		search.setAttribute('placeholder', 'Filter targets…');
		var searchTimer = null;
		search.addEventListener('input', function () {
			if (searchTimer) { clearTimeout(searchTimer); }
			searchTimer = setTimeout(function () {
				filters.q = search.value.trim();
				onFilterChange();
			}, 250);
		});
		bar.appendChild(search);

		function toggleChip(label, key) {
			var b = el('button', 'def-cluster-chip', label);
			b.type = 'button';
			b.setAttribute('aria-pressed', 'false');
			b.addEventListener('click', function () {
				filters[key] = !filters[key];
				b.classList.toggle('def-cluster-chip--on', filters[key]);
				b.setAttribute('aria-pressed', filters[key] ? 'true' : 'false');
				onFilterChange();
			});
			return b;
		}
		bar.appendChild(toggleChip('Needs curation', 'needsCuration'));
		bar.appendChild(toggleChip('Needs review', 'needsReview'));

		var statusSel = document.createElement('select');
		statusSel.className = 'def-cluster-filter-select';
		[['all', 'Any status'], ['active', 'Active'], ['paused', 'Paused']].forEach(function (o) {
			var opt = document.createElement('option');
			opt.value = o[0];
			opt.textContent = o[1];
			statusSel.appendChild(opt);
		});
		statusSel.addEventListener('change', function () {
			filters.status = statusSel.value;
			onFilterChange();
		});
		bar.appendChild(statusSel);

		typeSelect = document.createElement('select');
		typeSelect.className = 'def-cluster-filter-select';
		typeSelect.addEventListener('change', function () {
			filters.itemType = typeSelect.value;
			onFilterChange();
		});
		bar.appendChild(typeSelect);
		rebuildTypeOptions();

		return bar;
	}

	function loadTargets() {
		return api('/targets').then(function (res) {
			allTargets = ((res && res.targets) || []).filter(function (t) {
				return t && typeof t === 'object';
			});
			rebuildTypeOptions();
			renderList();
		}).catch(function (e) {
			listBox.textContent = '';
			listBox.appendChild(el('p', 'def-cluster-error',
				'Could not load targets: ' + ((e && e.message) || 'error')));
		});
	}

	// ── Init ────────────────────────────────────────────────────────────────

	root.removeAttribute('data-loading');
	root.textContent = '';
	root.appendChild(el('div', 'def-draft-safeguard', SAFEGUARD_COPY));
	root.appendChild(renderAddTarget());
	root.appendChild(renderToolbar());
	listBox = el('div', 'def-cluster-targets');
	root.appendChild(listBox);
	loadTargets();
})();
