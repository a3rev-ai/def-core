/**
 * Digital Employee Framework - Core - Admin Scripts
 * Phase 7 D-I/D-II: Tab switching, AJAX save, connection status test, keyboard navigation,
 * media uploader, user roles, escalation test, chat mode toggle.
 */
(function () {
	'use strict';

	var STORAGE_KEY = 'def_core_active_tab';
	var TOAST_DURATION = 4000;
	var tabs, panels;
	var _addUserRowFn = null; // Set by initUserRoles(), used by DEFAdmin bridge.

	document.addEventListener('DOMContentLoaded', init);

	function init() {
		tabs = document.querySelectorAll('.def-core-tab');
		panels = document.querySelectorAll('.def-core-panel');

		if (!tabs.length) {
			return;
		}

		initTabs();
		initSaveButtons();
		initConnectionTest();
		initManualConnection();
		initCopyButtons();
		initToggleSwitches();
		autoTestConnection();
		// D-II features.
		initMediaUploader();
		initUserRoles();
		initTestEmail();
		initChatMode();
		initButtonAppearance();
		initOAuth();

		// Bridge for Setup Assistant drawer — must be after initUserRoles()
		// so _addUserRowFn is set.
		window.DEFAdmin = {
			switchTab: switchTab,
			showToast: showToast,
			addUserRow: _addUserRowFn
		};
	}

	// ─── Tab Switching ────────────────────────────────────────────

	function initTabs() {
		// Determine initial tab.
		var initialTab = getInitialTab();

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				switchTab(tab.id.replace('tab-', ''));
			});

			tab.addEventListener('keydown', function (e) {
				handleTabKeydown(e, tab);
			});
		});

		switchTab(initialTab);
	}

	function getInitialTab() {
		// 1. URL hash.
		if (window.location.hash) {
			var hash = window.location.hash.substring(1);
			if (document.getElementById('panel-' + hash)) {
				return hash;
			}
		}
		// 2. localStorage.
		try {
			var stored = localStorage.getItem(STORAGE_KEY);
			if (stored && document.getElementById('panel-' + stored)) {
				return stored;
			}
		} catch (e) {
			// localStorage not available.
		}
		// 3. First tab.
		return 'branding';
	}

	function switchTab(tabId) {
		tabs.forEach(function (tab) {
			var isActive = tab.id === 'tab-' + tabId;
			tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
			tab.setAttribute('tabindex', isActive ? '0' : '-1');
		});

		panels.forEach(function (panel) {
			var isActive = panel.id === 'panel-' + tabId;
			if (isActive) {
				panel.removeAttribute('hidden');
			} else {
				panel.setAttribute('hidden', '');
			}
		});

		// Update URL hash without scrolling.
		if (history.replaceState) {
			history.replaceState(null, null, '#' + tabId);
		}

		// Update localStorage.
		try {
			localStorage.setItem(STORAGE_KEY, tabId);
		} catch (e) {
			// Ignore.
		}
	}

	function handleTabKeydown(e, currentTab) {
		var tabsArray = Array.prototype.slice.call(tabs);
		var index = tabsArray.indexOf(currentTab);
		var newIndex;

		switch (e.key) {
			case 'ArrowRight':
			case 'ArrowDown':
				e.preventDefault();
				newIndex = (index + 1) % tabsArray.length;
				tabsArray[newIndex].focus();
				switchTab(tabsArray[newIndex].id.replace('tab-', ''));
				break;
			case 'ArrowLeft':
			case 'ArrowUp':
				e.preventDefault();
				newIndex = (index - 1 + tabsArray.length) % tabsArray.length;
				tabsArray[newIndex].focus();
				switchTab(tabsArray[newIndex].id.replace('tab-', ''));
				break;
			case 'Home':
				e.preventDefault();
				tabsArray[0].focus();
				switchTab(tabsArray[0].id.replace('tab-', ''));
				break;
			case 'End':
				e.preventDefault();
				tabsArray[tabsArray.length - 1].focus();
				switchTab(tabsArray[tabsArray.length - 1].id.replace('tab-', ''));
				break;
		}
	}

	// ─── AJAX Save ────────────────────────────────────────────────

	function initSaveButtons() {
		document.querySelectorAll('.def-core-save-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				saveTab(btn.dataset.tab, btn);
			});
		});
	}

	function saveTab(tabId, btn) {
		var panel = document.getElementById('panel-' + tabId);
		if (!panel || !defCoreAdmin) {
			return;
		}

		var spinner = btn.parentElement.querySelector('.spinner');
		btn.disabled = true;
		if (spinner) {
			spinner.classList.add('is-active');
		}

		var formData = new FormData();
		formData.append('action', 'def_core_save_settings');
		formData.append('nonce', defCoreAdmin.saveNonce);
		formData.append('tab', tabId);

		// Collect settings from this tab.
		collectTabData(tabId, panel, formData);

		fetch(defCoreAdmin.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (data) {
				if (data.success) {
					showToast(data.data.message || 'Settings saved.', 'success');
					// Rebuild the AI Disclosure Notice preview from current
					// form values so the admin sees the saved state without
					// reloading. Only relevant for the chat-settings tab —
					// the helper is a no-op on other tabs.
					if (tabId === 'chat-settings') {
						refreshAiNoticePreview();
					}
				} else {
					showToast(
						(data.data && data.data.message) || 'Save failed.',
						'error'
					);
				}
			})
			.catch(function () {
				showToast('Network error. Please try again.', 'error');
			})
			.finally(function () {
				btn.disabled = false;
				if (spinner) {
					spinner.classList.remove('is-active');
				}
			});
	}

	// Rebuilds the AI Disclosure Notice preview block from the current
	// form values. Mirrors the chat widget's footer-render logic:
	//   - checkbox off ⇒ render a muted "disabled" stub
	//   - checkbox on + non-empty disclosure text ⇒ render text + label
	//     (label as link if URL set, plain text otherwise)
	//   - checkbox on + empty text + empty label ⇒ fall back to placeholder
	//     defaults so the preview always shows what the chat will render
	// The preview is rebuilt via DOM APIs (createElement / textContent)
	// — never innerHTML on user-supplied strings — so admin-entered text
	// can't break out into HTML. URL is run through the same scheme
	// allowlist used by the widget runtime (http/https/same-origin path,
	// not protocol-relative, ≤2048 chars) before being assigned to .href.
	function refreshAiNoticePreview() {
		var preview = document.getElementById('def-core-ai-notice-preview');
		if (!preview) return;
		var p = preview.querySelector('p');
		if (!p) return;

		var checkbox = document.getElementById('def_core_chat_ai_notice');
		var textInput = document.getElementById('def_core_chat_compliance_text');
		var labelInput = document.getElementById('def_core_chat_privacy_link_label');
		var urlInput = document.getElementById('def_core_chat_privacy_url');

		// Clear preview, keep the leading "Preview:" strong tag.
		while (p.firstChild) {
			p.removeChild(p.firstChild);
		}
		var heading = document.createElement('strong');
		heading.textContent = 'Preview:';
		p.appendChild(heading);
		p.appendChild(document.createTextNode(' '));

		// Checkbox off → muted disabled state, mirrors actual chat (no footer).
		if (checkbox && !checkbox.checked) {
			var disabled = document.createElement('em');
			disabled.textContent = 'Notice disabled — not shown in chat.';
			p.appendChild(disabled);
			return;
		}

		// Compose text + label, falling back to placeholders so preview
		// matches what an empty-config chat would render at runtime.
		var text = (textInput && textInput.value.trim())
			|| (textInput && textInput.placeholder)
			|| '';
		var label = (labelInput && labelInput.value.trim())
			|| (labelInput && labelInput.placeholder)
			|| '';
		var url = urlInput ? safeAdminLinkHref(urlInput.value) : '';

		p.appendChild(document.createTextNode(text));
		if (label) {
			p.appendChild(document.createTextNode(' '));
			if (url) {
				var a = document.createElement('a');
				a.href = url;
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				a.textContent = label;
				p.appendChild(a);
			} else {
				p.appendChild(document.createTextNode(label));
			}
		}
	}

	// Same scheme allowlist the chat widget enforces at runtime — kept
	// here as a small duplicate so the preview can't ever attach a
	// hostile href even if a future admin entry slips past server-side
	// validation. Returns '' to signal "render as plain text".
	function safeAdminLinkHref(url) {
		if (typeof url !== 'string') return '';
		var trimmed = url.trim();
		if (!trimmed || trimmed.length > 2048) return '';
		if (trimmed.charAt(0) === '/' && trimmed.charAt(1) !== '/') return trimmed;
		if (/^https?:\/\//i.test(trimmed)) return trimmed;
		return '';
	}

	function collectTabData(tabId, panel, formData) {
		// Generic data-setting handler for all tabs.
		// Handles text, url, password, hidden, number, checkbox, radio, textarea.
		panel
			.querySelectorAll('[data-setting]')
			.forEach(function (input) {
				var key = input.dataset.setting;
				if (input.type === 'radio') {
					if (input.checked) {
						formData.append('settings[' + key + ']', input.value);
					}
				} else if (input.type === 'checkbox') {
					formData.append(
						'settings[' + key + ']',
						input.checked ? '1' : '0'
					);
				} else {
					formData.append('settings[' + key + ']', input.value);
				}
			});
	}

	// ─── Connection Test ──────────────────────────────────────────

	function initConnectionTest() {
		var btn = document.getElementById('def-core-test-connection');
		if (btn) {
			btn.addEventListener('click', testConnection);
		}
	}

	function testConnection() {
		var btn = document.getElementById('def-core-test-connection');
		var resultEl = document.getElementById('def-core-connection-result');
		if (!btn || !resultEl || !defCoreAdmin) {
			return;
		}

		btn.disabled = true;
		btn.textContent = 'Testing...';
		resultEl.className = 'def-core-connection-result';
		resultEl.innerHTML = '';

		var formData = new FormData();
		formData.append('action', 'def_core_test_connection');
		formData.append('nonce', defCoreAdmin.testNonce);

		fetch(defCoreAdmin.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (data) {
				var result = data.data || {};
				showConnectionResult(resultEl, result);
			})
			.catch(function () {
				showConnectionResult(resultEl, {
					status: 'error',
					message: 'Network error',
				});
			})
			.finally(function () {
				btn.disabled = false;
				btn.textContent = 'Test Connection';
			});
	}

	function showConnectionResult(el, result) {
		var isOk = result.status === 'ok';
		el.className = 'def-core-connection-result ' + (isOk ? 'ok' : 'error');

		var icon = isOk ? 'dashicons-yes-alt' : 'dashicons-dismiss';
		var text = result.message || (isOk ? 'Connected' : 'Failed');
		var extra = '';

		if (result.response_time) {
			extra += ' (' + result.response_time + 'ms)';
		}
		if (result.timestamp) {
			var date = new Date(result.timestamp);
			if (!isNaN(date.getTime())) {
				extra +=
					' &middot; ' +
					date.toLocaleTimeString([], {
						hour: '2-digit',
						minute: '2-digit',
					});
			}
		}

		el.innerHTML =
			'<span class="dashicons ' +
			icon +
			'"></span> ' +
			escapeHtml(text) +
			extra;
	}

	function autoTestConnection() {
		if (!defCoreAdmin) {
			return;
		}

		var resultEl = document.getElementById('def-core-connection-result');
		if (!resultEl) {
			return;
		}

		// If cached result exists, show it.
		if (defCoreAdmin.cachedConnection) {
			showConnectionResult(resultEl, defCoreAdmin.cachedConnection);
		}
	}

	// ─── Manual Connection ────────────────────────────────────────

	function initManualConnection() {
		var btn = document.getElementById('def-core-save-manual-connection');
		if (!btn) {
			return;
		}
		btn.addEventListener('click', saveManualConnection);
	}

	function saveManualConnection() {
		var btn = document.getElementById('def-core-save-manual-connection');
		var resultEl = document.getElementById('def-core-manual-conn-result');
		var apiKey = document.getElementById('def_core_manual_api_key');
		var spinner = btn ? btn.parentNode.querySelector('.spinner') : null;

		if (!btn || !apiKey) {
			return;
		}

		if (!apiKey.value.trim()) {
			if (resultEl) {
				resultEl.className = 'def-core-connection-result error';
				resultEl.innerHTML = '<span class="dashicons dashicons-warning"></span> API Key is required.';
			}
			return;
		}

		btn.disabled = true;
		if (spinner) {
			spinner.classList.add('is-active');
		}
		if (resultEl) {
			resultEl.className = 'def-core-connection-result';
			resultEl.innerHTML = '';
		}

		var formData = new FormData();
		formData.append('action', 'def_core_save_manual_connection');
		formData.append('nonce', defCoreAdmin.connNonce);
		formData.append('api_key', apiKey.value.trim());

		fetch(defCoreAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (data) {
				btn.disabled = false;
				if (spinner) {
					spinner.classList.remove('is-active');
				}
				if (data.success) {
					if (resultEl) {
						resultEl.className = 'def-core-connection-result ok';
						resultEl.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> ' + data.data.message;
					}
					// Reload page after short delay to show connected state.
					setTimeout(function () {
						window.location.hash = '#connection';
						window.location.reload();
					}, 1500);
				} else {
					if (resultEl) {
						resultEl.className = 'def-core-connection-result error';
						resultEl.innerHTML = '<span class="dashicons dashicons-dismiss"></span> ' + (data.data && data.data.message ? data.data.message : 'Save failed.');
					}
				}
			})
			.catch(function () {
				btn.disabled = false;
				if (spinner) {
					spinner.classList.remove('is-active');
				}
				if (resultEl) {
					resultEl.className = 'def-core-connection-result error';
					resultEl.innerHTML = '<span class="dashicons dashicons-dismiss"></span> Request failed.';
				}
			});
	}

	// ─── Copy Buttons ─────────────────────────────────────────────

	function initCopyButtons() {
		document
			.querySelectorAll('.def-core-copy-btn')
			.forEach(function (btn) {
				btn.addEventListener('click', function () {
					var text = btn.dataset.copy;
					if (!text) {
						return;
					}

					navigator.clipboard.writeText(text).then(function () {
						var original = btn.textContent;
						btn.textContent = 'Copied!';
						setTimeout(function () {
							btn.textContent = original;
						}, 2000);
					});
				});
			});
	}

	// ─── Toggle Switches ──────────────────────────────────────────

	function initToggleSwitches() {
		document
			.querySelectorAll(
				'.def-core-toggle-switch input[type="checkbox"]:not(:disabled)'
			)
			.forEach(function (toggle) {
				// Make slider clickable.
				var slider = toggle.nextElementSibling;
				if (slider && slider.classList.contains('def-core-slider')) {
					slider.addEventListener('click', function (e) {
						e.preventDefault();
						e.stopPropagation();
						toggle.click();
					});
				}

				// Update row visual state on change.
				toggle.addEventListener('change', function () {
					var row = toggle.closest('tr');
					if (row) {
						row.classList.toggle('def-core-enabled', toggle.checked);
						row.classList.toggle(
							'def-core-disabled',
							!toggle.checked
						);
					}
				});
			});
	}

	// ─── D-II: Media Uploader ─────────────────────────────────────

	function initMediaUploader() {
		if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
			return;
		}

		var selectBtn = document.getElementById('def-core-select-logo');
		var removeBtn = document.getElementById('def-core-remove-logo');
		var preview = document.getElementById('def-core-logo-preview');
		var logoInput = document.getElementById('def_core_logo_id');

		if (!selectBtn || !logoInput) {
			return;
		}

		var frame;

		selectBtn.addEventListener('click', function (e) {
			e.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: 'Select Logo',
				button: { text: 'Use as Logo' },
				multiple: false,
				library: { type: 'image' },
			});

			frame.on('select', function () {
				var attachment = frame
					.state()
					.get('selection')
					.first()
					.toJSON();
				logoInput.value = attachment.id;

				if (preview) {
					var size =
						attachment.sizes && attachment.sizes.medium
							? attachment.sizes.medium
							: attachment;
					preview.innerHTML =
						'<img src="' +
						escapeHtml(size.url) +
						'" style="max-height: 120px; width: auto;" />';
				}

				if (removeBtn) {
					removeBtn.style.display = 'inline-block';
				}
			});

			frame.open();
		});

		if (removeBtn) {
			removeBtn.addEventListener('click', function (e) {
				e.preventDefault();
				logoInput.value = '0';
				if (preview) {
					preview.innerHTML =
						'<span class="def-core-no-logo">No logo selected</span>';
				}
				removeBtn.style.display = 'none';
			});
		}

		// App icon uploader (same pattern as logo).
		var appIconSelect = document.getElementById('def-core-select-app-icon');
		var appIconRemove = document.getElementById('def-core-remove-app-icon');
		var appIconPreview = document.getElementById('def-core-app-icon-preview');
		var appIconInput = document.getElementById('def_core_app_icon_id');

		if (appIconSelect && appIconInput) {
			var appIconFrame;

			appIconSelect.addEventListener('click', function (e) {
				e.preventDefault();
				if (appIconFrame) {
					appIconFrame.open();
					return;
				}
				appIconFrame = wp.media({
					title: 'Select App Icon',
					button: { text: 'Use as App Icon' },
					multiple: false,
					library: { type: 'image' },
				});
				appIconFrame.on('select', function () {
					var attachment = appIconFrame
						.state()
						.get('selection')
						.first()
						.toJSON();
					appIconInput.value = attachment.id;
					if (appIconPreview) {
						var size =
							attachment.sizes && attachment.sizes.medium
								? attachment.sizes.medium
								: attachment;
						appIconPreview.innerHTML =
							'<img src="' +
							escapeHtml(size.url) +
							'" style="max-width: 128px; max-height: 128px; border-radius: 16px;" />';
						appIconPreview.style.display = '';
					}
					if (appIconRemove) {
						appIconRemove.style.display = 'inline-block';
					}
				});
				appIconFrame.open();
			});

			if (appIconRemove) {
				appIconRemove.addEventListener('click', function (e) {
					e.preventDefault();
					appIconInput.value = '0';
					if (appIconPreview) {
						appIconPreview.style.display = 'none';
						appIconPreview.innerHTML = '<img src="" alt="" style="max-width: 128px; max-height: 128px; border-radius: 16px;" />';
					}
					appIconRemove.style.display = 'none';
				});
			}
		}

		// Welcome banner uploaders (desktop + mobile, same pattern as logo).
		// Factored as a helper since we have two variants — desktop (wide)
		// and mobile (tall). Same media library, same wp.media flow, just
		// different DOM IDs and preview dimensions.
		function bindBannerUploader(selectId, removeId, previewId, inputId, title, previewMaxW, previewMaxH) {
			var sel = document.getElementById(selectId);
			var rem = document.getElementById(removeId);
			var prev = document.getElementById(previewId);
			var inp = document.getElementById(inputId);
			if (!sel || !inp) return;
			var frame;
			sel.addEventListener('click', function (e) {
				e.preventDefault();
				if (frame) { frame.open(); return; }
				frame = wp.media({
					title: title,
					button: { text: 'Use as Banner' },
					multiple: false,
					library: { type: 'image' },
				});
				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					inp.value = attachment.id;
					if (prev) {
						var size =
							attachment.sizes && attachment.sizes.large
								? attachment.sizes.large
								: (attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium : attachment);
						prev.innerHTML =
							'<img src="' + escapeHtml(size.url) + '" alt="" style="max-width: ' + previewMaxW + 'px; max-height: ' + previewMaxH + 'px; object-fit: cover; border-radius: 8px; display: block;" />';
						prev.style.display = '';
					}
					if (rem) { rem.style.display = 'inline-block'; }
				});
				frame.open();
			});
			if (rem) {
				rem.addEventListener('click', function (e) {
					e.preventDefault();
					inp.value = '0';
					if (prev) {
						prev.style.display = 'none';
						prev.innerHTML = '<img src="" alt="" style="max-width: ' + previewMaxW + 'px; max-height: ' + previewMaxH + 'px; object-fit: cover; border-radius: 8px; display: block;" />';
					}
					rem.style.display = 'none';
				});
			}
		}

		bindBannerUploader(
			'def-core-select-hero-image',
			'def-core-remove-hero-image',
			'def-core-hero-image-preview',
			'def_core_chat_hero_image_id',
			'Select Desktop Banner',
			480, 120
		);
		bindBannerUploader(
			'def-core-select-hero-image-mobile',
			'def-core-remove-hero-image-mobile',
			'def-core-hero-image-mobile-preview',
			'def_core_chat_hero_image_mobile_id',
			'Select Mobile Banner',
			320, 140
		);
	}

	// ─── S3: User Access — access level, role chips, filter ───────────
	//
	// One row per person. The capability state a row carries lives where it has
	// always lived — a .def-core-role-cb input per capability, read by the save
	// handler below — and these functions are the visible controls over it. The
	// role inputs are hidden and driven by the chips; Staff/Management are hidden
	// and driven by the two-way control; DEF Admin is its own visible checkbox.
	//
	// Nothing here builds markup by string concatenation: every chip, option and
	// label is created as an element with its text set through textContent, so a
	// role name out of the DEFHO catalog cannot reach the page as markup.

	// The tenant's role catalog, server-resolved. A slug the browser invents is
	// not in it, and the save handler on the PHP side does not iterate it either.
	function accessCatalog() {
		return (window.defCoreAdmin && window.defCoreAdmin.rolesCatalog) || [];
	}

	// Every visible string this block writes, from the server. The checkbox grid
	// put all of them in the markup where esc_html_e() reached them, so building
	// the controls in JS without this would have shipped English into a
	// translated page. The fallback is the English, so a missing key degrades to
	// what the old grid said rather than to nothing.
	function accessText(key, fallback) {
		var map = (window.defCoreAdmin && window.defCoreAdmin.userAccessI18n) || {};
		return map[key] || fallback;
	}

	// The one substitution these strings take. Written as a function replacement
	// so a name containing $& or $' goes in as itself.
	function accessFill(template, value) {
		return String(template).replace(/%[sd]/, function () { return String(value); });
	}

	function accessRoleName(slug) {
		var catalog = accessCatalog();
		for (var i = 0; i < catalog.length; i++) {
			if (catalog[i].slug === slug) return catalog[i].name || slug;
		}
		return slug;
	}

	function accessCapInput(row, cap) {
		return row.querySelector('.def-core-role-cb[data-cap="' + cap + '"]');
	}

	// ── Access level: choosing one always leaves exactly one ──
	//
	// Clicking a pill writes BOTH inputs, so the control cannot express "both"
	// and a reader cannot click their way to "neither". What it must NOT do is
	// invent a level for a row that stores none: a DEF Admin who never uses the
	// console is a supported setup, not a gap (class-def-core-staff-roster.php:
	// "DEF-Admin alone is NOT a roster row — it is an access grant, not a
	// Staff-AI seat"). Defaulting those rows to Staff would hand every one of
	// them a console login the first time anyone pressed Save, for someone else.
	// So '' is a real state here: rendered as neither pill selected, left alone
	// until a person picks one.
	function accessLevelOf(row) {
		var staff = accessCapInput(row, 'def_staff_access');
		var mgmt = accessCapInput(row, 'def_management_access');
		// Management wins if a row somehow carries both — the same precedence
		// the roster builder and the save handler already apply.
		if (mgmt && mgmt.checked) return 'management';
		if (staff && staff.checked) return 'staff';
		return '';
	}

	function setAccessLevel(row, level) {
		var staff = accessCapInput(row, 'def_staff_access');
		var mgmt = accessCapInput(row, 'def_management_access');
		if (staff) staff.checked = level === 'staff';
		if (mgmt) mgmt.checked = level === 'management';
		var opts = row.querySelectorAll('.def-core-access-opt');
		for (var i = 0; i < opts.length; i++) {
			var on = level !== '' && opts[i].dataset.level === level;
			opts[i].classList.toggle('is-selected', on);
			opts[i].setAttribute('aria-checked', on ? 'true' : 'false');
			// One stop in the tab order per group — on the selected option, or
			// on the first one when nothing is selected yet, so a keyboard can
			// still reach a group that has never been chosen.
			opts[i].tabIndex = on || (level === '' && 0 === i) ? 0 : -1;
		}
	}

	function buildAccessLevel(row) {
		var cell = row.querySelector('.def-core-cell-access');
		if (!cell || cell.querySelector('.def-core-access-level')) return;
		var group = document.createElement('div');
		group.className = 'def-core-access-level';
		group.setAttribute('role', 'radiogroup');
		// Named for the person, not just the column: a list of rows all
		// announcing "Access level" tells a screen reader whose it is nowhere.
		var who = row.querySelector('.def-core-cell-user strong');
		group.setAttribute('aria-label',
			who && who.textContent
				? accessFill(accessText('accessFor', 'Access level for %s'), who.textContent)
				: accessText('accessLevel', 'Access level'));
		[
			['staff', accessText('staff', 'Staff')],
			['management', accessText('management', 'Management')]
		].forEach(function (pair) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'def-core-access-opt';
			btn.dataset.level = pair[0];
			btn.setAttribute('role', 'radio');
			btn.textContent = pair[1];
			btn.addEventListener('click', function () {
				setAccessLevel(row, pair[0]);
			});
			btn.addEventListener('keydown', function (e) {
				if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' &&
					e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
				e.preventDefault();
				// From Staff to Management, from anything else to Staff — which
				// makes an arrow on a row that has neither pick the lower one.
				var next = accessLevelOf(row) === 'staff' ? 'management' : 'staff';
				setAccessLevel(row, next);
				var target = row.querySelector('.def-core-access-opt[data-level="' + next + '"]');
				if (target) target.focus();
			});
			group.appendChild(btn);
		});
		cell.appendChild(group);
		setAccessLevel(row, accessLevelOf(row));
	}

	// ── Roles: the chips, and the roles still available to add ──

	function accessRolesOf(row) {
		var held = [];
		accessCatalog().forEach(function (role) {
			var cb = accessCapInput(row, 'def_role_' + role.slug);
			if (cb && cb.checked) held.push(role.slug);
		});
		return held;
	}

	function accessRolesAvailable(row) {
		var held = accessRolesOf(row);
		return accessCatalog().filter(function (role) {
			return held.indexOf(role.slug) === -1;
		});
	}

	// Announced, not redrawn in place. Calling renderRolesCell() here would draw
	// the right chips and leave the FILTER and its count describing a state that
	// is no longer true — remove a Finance chip while filtering by Finance and
	// the person stays on a list of people who hold it. One door: the row
	// listener redraws the cell and re-runs the filter together.
	function setAccessRole(row, slug, on) {
		var cb = accessCapInput(row, 'def_role_' + slug);
		if (!cb) return;
		cb.checked = !!on;
		// window.Event, not a bare Event: the block already takes window as its
		// one global handle (accessCatalog reads window.defCoreAdmin), and a
		// bare Event picks up the HOST realm's constructor when this runs
		// outside a browser, which the DOM then refuses.
		cb.dispatchEvent(new window.Event('change', { bubbles: true }));
	}

	// The Roles cell, redrawn from the inputs. data-roles is what the filter
	// reads, so it is written here and nowhere else: the chips and the filter
	// cannot disagree about who holds what.
	function renderRolesCell(row) {
		var cell = row.querySelector('.def-core-cell-roles');
		if (!cell) return;
		// A menu open on this row is about to be detached along with its wrap,
		// and its outside-click listener is on the DOCUMENT — it would outlive
		// the element it closes over. Close it before the cell goes.
		if (row._defCloseRoleMenu) {
			row._defCloseRoleMenu(false);
		}
		var held = accessRolesOf(row);
		row.dataset.roles = held.join(' ');

		var old = cell.querySelector('.def-core-chips');
		if (old) old.remove();
		var oldAdd = cell.querySelector('.def-core-add-role-wrap');
		if (oldAdd) oldAdd.remove();

		var chips = document.createElement('div');
		chips.className = 'def-core-chips';
		held.forEach(function (slug) {
			var name = accessRoleName(slug);
			var chip = document.createElement('span');
			chip.className = 'def-core-chip';
			chip.dataset.role = slug;
			var label = document.createElement('span');
			label.className = 'def-core-chip-label';
			label.textContent = name;
			var x = document.createElement('button');
			x.type = 'button';
			x.className = 'def-core-chip-remove';
			x.setAttribute('aria-label', accessFill(accessText('removeRole', 'Remove role %s'), name));
			x.textContent = '×';
			x.addEventListener('click', function () {
				setAccessRole(row, slug, false);
				var add = row.querySelector('.def-core-add-role');
				if (add) add.focus();
			});
			chip.appendChild(label);
			chip.appendChild(x);
			chips.appendChild(chip);
		});
		cell.appendChild(chips);
		cell.appendChild(buildAddRole(row));
	}

	// ── + Add role: a hand-rolled listbox, keyboard usable ──
	//
	// Escape closes, Arrow Up/Down move the active option, Enter takes it, and
	// focus lands back on + Add role every way it closes — selected, escaped, or
	// clicked away from. No framework, no <select>: the same control has to sit
	// among wrapping chips on a phone.
	function buildAddRole(row) {
		var wrap = document.createElement('div');
		wrap.className = 'def-core-add-role-wrap';

		var available = accessRolesAvailable(row);
		if (!available.length) {
			var done = document.createElement('span');
			done.className = 'def-core-roles-all-on';
			done.textContent = accessText('allRolesOn', 'Every role is on');
			wrap.appendChild(done);
			return wrap;
		}

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'def-core-add-role';
		btn.textContent = accessText('addRole', '+ Add role');
		btn.setAttribute('aria-haspopup', 'listbox');
		btn.setAttribute('aria-expanded', 'false');

		var menu = document.createElement('div');
		menu.className = 'def-core-role-menu';
		menu.setAttribute('role', 'listbox');
		menu.hidden = true;

		var active = -1;
		var options = available.map(function (role, i) {
			var opt = document.createElement('button');
			opt.type = 'button';
			opt.className = 'def-core-role-option';
			opt.setAttribute('role', 'option');
			opt.setAttribute('aria-selected', 'false');
			// Out of the tab order: an option is reached with the arrows, never
			// by Tab. Tabbing INTO the menu left focus and `active` on different
			// options, and Enter took the one the arrows had highlighted.
			opt.tabIndex = -1;
			opt.dataset.role = role.slug;
			opt.textContent = role.name || role.slug;
			opt.addEventListener('click', function () {
				choose(i);
			});
			menu.appendChild(opt);
			return opt;
		});

		function setActive(i) {
			active = i;
			options.forEach(function (opt, j) {
				opt.classList.toggle('is-active', j === i);
				opt.setAttribute('aria-selected', j === i ? 'true' : 'false');
			});
		}

		function open() {
			menu.hidden = false;
			btn.setAttribute('aria-expanded', 'true');
			// Nothing highlighted yet. Seeding 0 here while focus stayed on the
			// button made the first Arrow Down step PAST the option it had just
			// highlighted; the arrow branch seeds it instead, in its direction.
			setActive(-1);
			document.addEventListener('click', onOutside, true);
			// So a redraw under an open menu can close it — the listener above
			// is on the document and outlives this wrap otherwise.
			row._defCloseRoleMenu = close;
		}

		function close(refocus) {
			menu.hidden = true;
			btn.setAttribute('aria-expanded', 'false');
			setActive(-1);
			document.removeEventListener('click', onOutside, true);
			if (row._defCloseRoleMenu === close) {
				row._defCloseRoleMenu = null;
			}
			if (refocus && btn.isConnected) btn.focus();
		}

		function onOutside(e) {
			if (!wrap.contains(e.target)) close(false);
		}

		// A selection redraws the cell, so this wrap and its button are detached
		// by the time focus is placed: the focus goes to the + Add role of the
		// REDRAWN row, which is a different element with the same job.
		function choose(i) {
			var slug = available[i] && available[i].slug;
			close(false);
			if (!slug) return;
			setAccessRole(row, slug, true);
			var live = row.querySelector('.def-core-add-role');
			if (live) live.focus();
		}

		btn.addEventListener('click', function () {
			if (menu.hidden) open(); else close(true);
		});

		wrap.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				if (menu.hidden) return;
				e.preventDefault();
				close(true);
				return;
			}
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				if (menu.hidden) { open(); return; }
				var step = e.key === 'ArrowDown' ? 1 : -1;
				if (active < 0) {
					setActive(step > 0 ? 0 : options.length - 1);
				} else {
					setActive((active + step + options.length) % options.length);
				}
				options[active].focus();
				return;
			}
			if (e.key === 'Enter' || e.key === ' ') {
				if (menu.hidden) return;
				// Focus is the truth when it is on an option; `active` is what
				// the arrows last highlighted. Reading focus first means the two
				// can never disagree about which role is being taken.
				var focused = options.indexOf(document.activeElement);
				var pick = focused > -1 ? focused : active;
				if (pick < 0) return;
				e.preventDefault();
				choose(pick);
			}
		});

		wrap.appendChild(btn);
		wrap.appendChild(menu);
		return wrap;
	}

	// One row, decorated. Both the PHP-rendered rows and a row added by the
	// search go through this, so there is one renderer and no second copy of it
	// to drift.
	function renderAccessRow(row) {
		buildAccessLevel(row);
		renderRolesCell(row);
	}

	// The screen, wired. Every capability write on a row — a chip, the Setup
	// Assistant drawer, the DEF Admin tick — arrives here as one 'change', and
	// this is the only place that decides what the screen does about it. Returns
	// the refresher so the paths that add and remove whole rows can re-run it.
	function initAccessRows(tbody, filterEl, countEl) {
		function refresh() {
			applyRoleFilter(tbody, filterEl ? filterEl.value : '', countEl);
		}
		if (tbody) {
			tbody.querySelectorAll('tr.def-core-user-row').forEach(renderAccessRow);
			tbody.addEventListener('change', function (e) {
				var row = e.target.closest && e.target.closest('tr.def-core-user-row');
				if (!row || !e.target.classList.contains('def-core-role-cb')) return;
				// setAccessLevel writes inputs, but assigning .checked fires
				// nothing, so this cannot re-enter.
				setAccessLevel(row, accessLevelOf(row));
				renderRolesCell(row);
				refresh();
			});
		}
		if (filterEl) {
			filterEl.addEventListener('change', refresh);
		}
		refresh();
		return refresh;
	}

	// ── The filter, and the count that follows it ──
	//
	// Client-side: every row is already on the page, and hiding one changes no
	// capability. A hidden row still submits — filtering is not deselecting.
	function applyRoleFilter(tbody, slug, countEl) {
		if (!tbody) return 0;
		var rows = tbody.querySelectorAll('tr.def-core-user-row');
		var shown = 0;
		for (var i = 0; i < rows.length; i++) {
			var held = (rows[i].dataset.roles || '').split(' ').filter(Boolean);
			var match = !slug || held.indexOf(slug) !== -1;
			rows[i].hidden = !match;
			if (match) shown++;
		}
		if (countEl) {
			countEl.textContent = 1 === shown
				? accessText('onePerson', '1 person')
				: accessFill(accessText('manyPeople', '%d people'), shown);
		}
		return shown;
	}

	// ── The save payload ──
	//
	// The shape the checkbox grid submitted, unchanged: roles[<user>][<cap>] =
	// '1'|'0' for every capability input on the page, hidden ones included.
	// Returned as pairs so the payload can be read without a FormData.
	function accessPayload(root) {
		var pairs = [];
		(root || document).querySelectorAll('.def-core-role-cb').forEach(function (cb) {
			pairs.push([
				'roles[' + cb.dataset.user + '][' + cb.dataset.cap + ']',
				cb.checked ? '1' : '0'
			]);
		});
		return pairs;
	}

	// ─── end S3: User Access ──────────────────────────────────────────

	// ─── D-II: User Roles ─────────────────────────────────────────

	function initUserRoles() {
		var saveBtn = document.querySelector('.def-core-save-roles-btn');
		var searchInput = document.getElementById('def-core-user-search');
		var searchResults = document.getElementById(
			'def-core-user-search-results'
		);
		var tbody = document.getElementById('def-core-roles-tbody');
		if (!saveBtn || !defCoreAdmin) {
			return;
		}

		var searchTimer = null;

		// ── Save handler ──
		saveBtn.addEventListener('click', function () {
			var spinner = saveBtn.parentElement.querySelector('.spinner');
			saveBtn.disabled = true;
			if (spinner) {
				spinner.classList.add('is-active');
			}

			var formData = new FormData();
			formData.append('action', 'def_core_save_user_roles');
			formData.append('nonce', defCoreAdmin.rolesNonce);

			// Every capability input on the page, hidden ones included — the
			// chips and the two-way access control write these, so the payload
			// is the same shape the checkbox grid submitted.
			accessPayload(document).forEach(function (pair) {
				formData.append(pair[0], pair[1]);
			});

			fetch(defCoreAdmin.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			})
				.then(function (res) {
					return res.json();
				})
				.then(function (data) {
					if (data.success) {
						showToast(
							data.data.message || 'User roles updated.',
							'success'
						);
					} else {
						showToast(
							(data.data && data.data.message) || 'Save failed.',
							'error'
						);
					}
				})
				.catch(function () {
					showToast('Network error. Please try again.', 'error');
				})
				.finally(function () {
					saveBtn.disabled = false;
					if (spinner) {
						spinner.classList.remove('is-active');
					}
				});
		});

		// ── Search handler ──
		if (searchInput && searchResults) {
			searchInput.addEventListener('input', function () {
				clearTimeout(searchTimer);
				var term = searchInput.value.trim();
				if (term.length < 2) {
					searchResults.hidden = true;
					searchResults.innerHTML = '';
					return;
				}
				searchTimer = setTimeout(function () {
					doSearch(term);
				}, 300);
			});

			// Close results when clicking outside.
			document.addEventListener('click', function (e) {
				if (
					!searchInput.contains(e.target) &&
					!searchResults.contains(e.target)
				) {
					searchResults.hidden = true;
				}
			});
		}

		// ── The rows, wired ──
		//
		// One call: decorate every row, listen for capability writes, and keep the
		// filter and its count following them. initAccessRows() lives in the S3
		// block above so a harness runs this exact listener.
		var countEl = document.getElementById('def-core-access-count');
		var filterEl = document.getElementById('def-core-role-filter');
		var refreshFilter = initAccessRows(tbody, filterEl, countEl);

		function doSearch(term) {
			var formData = new FormData();
			formData.append('action', 'def_core_search_users');
			formData.append('nonce', defCoreAdmin.searchUsersNonce);
			formData.append('term', term);

			fetch(defCoreAdmin.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			})
				.then(function (res) {
					return res.json();
				})
				.then(function (data) {
					if (!data.success || !data.data.users.length) {
						searchResults.innerHTML =
							'<div class="def-core-search-no-results">No users found</div>';
						searchResults.hidden = false;
						return;
					}
					renderSearchResults(data.data.users);
				})
				.catch(function () {
					searchResults.hidden = true;
				});
		}

		function renderSearchResults(users) {
			searchResults.innerHTML = '';
			users.forEach(function (u) {
				// Check if user is already in the table.
				var existingRow = tbody.querySelector(
					'tr[data-user-id="' + u.id + '"]'
				);
				var item = document.createElement('div');
				item.className = 'def-core-search-result-item';
				if (existingRow) {
					item.classList.add('is-existing');
				}

				var nameHtml = '<strong>' + escHtml(u.display_name) + '</strong>';
				if (u.user_login && u.user_login !== u.display_name) {
					nameHtml += ' <span class="def-core-user-login">(' + escHtml(u.user_login) + ')</span>';
				}

				item.innerHTML =
					'<img class="def-core-search-result-avatar" src="' +
					escHtml(u.avatar) +
					'" alt="" />' +
					'<div class="def-core-search-result-info">' +
					'<div class="def-core-search-result-name">' +
					nameHtml +
					'</div>' +
					'<div class="def-core-search-result-email">' +
					escHtml(u.email) +
					'</div>' +
					'</div>' +
					'<span class="def-core-search-result-role">' +
					escHtml(u.role) +
					'</span>' +
					(existingRow
						? '<span class="def-core-search-result-badge">Already added</span>'
						: '');

				if (!existingRow) {
					item.addEventListener('click', function () {
						addUserRow(u);
						searchResults.hidden = true;
						searchInput.value = '';
					});
				}

				searchResults.appendChild(item);
			});
			searchResults.hidden = false;
		}

		function addUserRow(u) {
			// The id becomes part of a POST field name (roles[<id>][<cap>]), and
			// it arrives from the search response or — through the DEFAdmin
			// bridge — from the Setup Assistant stream. A non-numeric one could
			// close the bracket and name a second key, so it is an integer here
			// or the row is not built at all.
			var uid = parseInt(u.id, 10);
			if (!uid || uid < 1) {
				return;
			}

			var tr = document.createElement('tr');
			tr.className = 'def-core-user-row';
			tr.setAttribute('data-user-id', uid);
			tr.setAttribute('data-wp-role', (u.role || '').toLowerCase());
			tr.setAttribute('data-roles', '');

			// A cell with the label the phone layout reveals. The six columns are
			// the six the PHP renders, in the same order.
			function cell(cls, label) {
				var td = document.createElement('td');
				td.className = cls;
				td.setAttribute('data-label', label);
				tr.appendChild(td);
				return td;
			}

			// One hidden capability input. These ARE the row's state — the chips
			// and the access control read and write them, the save reads them all.
			function capInput(td, cap, hidden) {
				var input = document.createElement('input');
				input.type = 'checkbox';
				input.className = 'def-core-role-cb';
				input.dataset.user = uid;
				input.dataset.cap = cap;
				if (hidden) input.hidden = true;
				td.appendChild(input);
				return input;
			}

			var userCell = cell('def-core-cell-user', accessText('colUser', 'User'));
			if (u.avatar) {
				var img = document.createElement('img');
				img.className = 'def-core-user-avatar';
				img.src = u.avatar;
				img.width = 24;
				img.height = 24;
				img.alt = '';
				userCell.appendChild(img);
			}
			var name = document.createElement('strong');
			name.textContent = u.display_name || '';
			userCell.appendChild(name);
			if (u.user_login && u.user_login !== u.display_name) {
				userCell.appendChild(document.createTextNode(' '));
				var login = document.createElement('span');
				login.className = 'def-core-user-login';
				login.textContent = '(' + u.user_login + ')';
				userCell.appendChild(login);
			}
			var email = document.createElement('span');
			email.className = 'def-core-user-email';
			email.textContent = u.email || '';
			userCell.appendChild(email);

			cell('def-core-cell-wp', accessText('colWpRole', 'WordPress role')).textContent = u.role || '';

			// A new row grants NOTHING until someone picks a level — which is what
			// the checkbox grid did too (every box started unticked). Pre-selecting
			// Staff here would stage a console login on a row that was added for a
			// vault role, and the reader never asked for it.
			var accessCell = cell('def-core-cell-access', accessText('accessLevel', 'Access level'));
			capInput(accessCell, 'def_staff_access', true);
			capInput(accessCell, 'def_management_access', true);

			// Custom roles (R4): one hidden input per catalog role, none held yet.
			var rolesCell = cell('def-core-cell-roles', accessText('colRoles', 'Roles'));
			accessCatalog().forEach(function (role) {
				capInput(rolesCell, 'def_role_' + role.slug, true);
			});

			var adminCell = cell('def-core-cell-admin', accessText('colDefAdmin', 'DEF Admin'));
			var adminCb = capInput(adminCell, 'def_admin_access', false);
			adminCb.classList.add('def-core-admin-cb');
			adminCb.setAttribute('aria-label', accessText('colDefAdmin', 'DEF Admin'));

			var actionsCell = cell('def-core-cell-actions', accessText('colActions', 'Actions'));
			var removeBtn = document.createElement('button');
			removeBtn.type = 'button';
			removeBtn.className = 'def-core-remove-user-btn';
			removeBtn.dataset.userId = uid;
			removeBtn.title = accessText('removeAccess', 'Remove all DEF access');
			removeBtn.innerHTML = '&times;';
			actionsCell.appendChild(removeBtn);

			tbody.appendChild(tr);
			renderAccessRow(tr);
			bindRemoveButton(removeBtn);
			// A new row holds no roles, so any role filter would hide the person
			// who was just added. The filter goes back to All roles rather than
			// swallowing them.
			if (filterEl) {
				filterEl.value = '';
			}
			refreshFilter();
		}

		// ── Remove handler ──
		function bindRemoveButton(btn) {
			btn.addEventListener('click', function () {
				var userId = btn.dataset.userId;
				var row = btn.closest('tr');

				// Lockout prevention: cannot remove the last DEF Admin.
				var adminCheckbox = row.querySelector('input[data-cap="def_admin_access"]');
				if (adminCheckbox && adminCheckbox.checked) {
					var tbody = row.closest('tbody');
					var allAdminBoxes = tbody
						? tbody.querySelectorAll('input[data-cap="def_admin_access"]:checked')
						: [];
					if (allAdminBoxes.length <= 1) {
						showToast(
							'Cannot remove the last DEF Admin. At least one user must have DEF Admin access.',
							'error'
						);
						return;
					}
				}

				// Get user display name from the row.
				var nameEl = row.querySelector('td');
				var userName = nameEl
					? nameEl.textContent.replace(/\s+/g, ' ').trim().split(' ')[0]
					: 'this user';

				if (
					!confirm(
						'Remove ' +
							userName +
							' from all Digital Employee access?\n\nThis will revoke their Staff, Management, custom-role and DEF Admin permissions immediately.'
					)
				) {
					return;
				}

				btn.disabled = true;

				var formData = new FormData();
				formData.append('action', 'def_core_remove_user_roles');
				formData.append('nonce', defCoreAdmin.rolesNonce);
				formData.append('user_id', userId);

				fetch(defCoreAdmin.ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin',
				})
					.then(function (res) {
						return res.json();
					})
					.then(function (data) {
						if (data.success) {
							row.remove();
							refreshFilter();
							showToast(data.data.message, 'success');
						} else {
							showToast(
								(data.data && data.data.message) ||
									'Remove failed.',
								'error'
							);
							btn.disabled = false;
						}
					})
					.catch(function () {
						showToast(
							'Network error. Please try again.',
							'error'
						);
						btn.disabled = false;
					});
			});
		}

		// Bind existing remove buttons.
		document
			.querySelectorAll('.def-core-remove-user-btn')
			.forEach(bindRemoveButton);

		// HTML escape helper.
		function escHtml(str) {
			var div = document.createElement('div');
			div.appendChild(document.createTextNode(str || ''));
			return div.innerHTML;
		}

		// Expose for Setup Assistant drawer (via DEFAdmin bridge created later).
		_addUserRowFn = function (u) {
			// Skip if already in table.
			if (tbody.querySelector('tr[data-user-id="' + u.id + '"]')) return;
			addUserRow({
				id: u.id,
				display_name: u.display_name || '',
				email: u.user_email || u.email || '',
				role: u.wp_role || '',
				avatar: u.avatar || '',
			});
			// Pre-check capabilities from Setup Assistant response, then redraw
			// the row's controls over them — the drawer writes the inputs, the
			// chips and the access level are what the reader sees of them.
			var row = tbody.querySelector('tr[data-user-id="' + u.id + '"]');
			if (row && u.caps) {
				var cbs = row.querySelectorAll('input[type="checkbox"]');
				for (var i = 0; i < cbs.length; i++) {
					var cap = cbs[i].getAttribute('data-cap');
					if (u.caps.hasOwnProperty(cap)) {
						cbs[i].checked = !!u.caps[cap];
					}
				}
				setAccessLevel(row, accessLevelOf(row));
				renderRolesCell(row);
				refreshFilter();
			}
		};
	}

	// ─── D-II: Test Escalation Email ──────────────────────────────

	function initTestEmail() {
		document
			.querySelectorAll('.def-core-test-email-btn')
			.forEach(function (btn) {
				btn.addEventListener('click', function () {
					if (!defCoreAdmin) {
						return;
					}

					var channel = btn.dataset.channel;
					var originalText = btn.textContent;

					btn.disabled = true;
					btn.textContent = 'Sending...';

					var formData = new FormData();
					formData.append(
						'action',
						'def_core_test_escalation_email'
					);
					formData.append('nonce', defCoreAdmin.testEmailNonce);
					formData.append('channel', channel);

					fetch(defCoreAdmin.ajaxUrl, {
						method: 'POST',
						body: formData,
						credentials: 'same-origin',
					})
						.then(function (res) {
							return res.json();
						})
						.then(function (data) {
							if (data.success) {
								showToast(
									data.data.message || 'Test email sent.',
									'success'
								);
							} else {
								showToast(
									(data.data && data.data.message) ||
										'Failed to send.',
									'error'
								);
							}
						})
						.catch(function () {
							showToast('Network error.', 'error');
						})
						.finally(function () {
							btn.disabled = false;
							btn.textContent = originalText;
						});
				});
			});
	}

	// ─── D-II: Chat Mode Toggle ───────────────────────────────────

	function initChatMode() {
		var radios = document.querySelectorAll(
			'input[data-setting="def_core_chat_display_mode"]'
		);
		var drawerOptions = document.getElementById('def-core-drawer-options');
		var spotlightOptions = document.getElementById('def-core-spotlight-options');

		if (!radios.length) {
			return;
		}

		radios.forEach(function (radio) {
			radio.addEventListener('change', function () {
				if (drawerOptions) {
					drawerOptions.style.display =
						radio.value === 'drawer' ? 'block' : 'none';
				}
				if (spotlightOptions) {
					spotlightOptions.style.display =
						radio.value === 'spotlight' ? 'block' : 'none';
				}
			});
		});
	}

	// ─── Button Appearance ────────────────────────────────────────

	function initButtonAppearance() {
		initIconUploader();
		initLoadingMarkUploader();
		initFloatingToggle();
		initColorPreview();
	}

	function initIconUploader() {
		var iconRadios = document.querySelectorAll(
			'input[data-setting="def_core_chat_button_icon"]'
		);
		var uploadWrap = document.getElementById('def-core-custom-icon-upload');

		if (!iconRadios.length || !uploadWrap) {
			return;
		}

		// Show/hide custom icon upload when radio changes.
		iconRadios.forEach(function (radio) {
			radio.addEventListener('change', function () {
				uploadWrap.style.display =
					radio.value === 'custom' ? '' : 'none';
			});
		});

		// Media uploader requires wp.media (loaded by wp_enqueue_media).
		if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
			return;
		}

		var selectBtn = document.getElementById('def-core-select-icon');
		var removeBtn = document.getElementById('def-core-remove-icon');
		var preview = document.getElementById('def-core-icon-preview');
		var iconInput = document.getElementById('def_core_chat_button_icon_id');

		if (!selectBtn || !iconInput) {
			return;
		}

		var frame;

		selectBtn.addEventListener('click', function (e) {
			e.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: 'Select Button Icon',
				button: { text: 'Use as Icon' },
				multiple: false,
				library: { type: 'image' },
			});

			frame.on('select', function () {
				var attachment = frame
					.state()
					.get('selection')
					.first()
					.toJSON();
				iconInput.value = attachment.id;

				if (preview) {
					var size =
						attachment.sizes && attachment.sizes.thumbnail
							? attachment.sizes.thumbnail
							: attachment;
					preview.innerHTML =
						'<img src="' +
						escapeHtml(size.url) +
						'" style="max-height: 48px; width: auto;" />';
				}

				if (removeBtn) {
					removeBtn.style.display = 'inline-block';
				}
			});

			frame.open();
		});

		if (removeBtn) {
			removeBtn.addEventListener('click', function (e) {
				e.preventDefault();
				iconInput.value = '0';
				if (preview) {
					preview.innerHTML =
						'<span class="def-core-no-logo">No icon selected</span>';
				}
				removeBtn.style.display = 'none';
			});
		}
	}

	// Loading-mark uploader — same wp.media flow as the custom icon.
	function initLoadingMarkUploader() {
		if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
			return;
		}

		var selectBtn = document.getElementById('def-core-select-loading-mark');
		var removeBtn = document.getElementById('def-core-remove-loading-mark');
		var preview = document.getElementById('def-core-loading-mark-preview');
		var markInput = document.getElementById('def_core_chat_loading_mark_id');

		if (!selectBtn || !markInput) {
			return;
		}

		var frame;

		selectBtn.addEventListener('click', function (e) {
			e.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: 'Select Loading Mark',
				button: { text: 'Use as Loading Mark' },
				multiple: false,
				library: { type: 'image' },
			});

			frame.on('select', function () {
				var attachment = frame
					.state()
					.get('selection')
					.first()
					.toJSON();
				markInput.value = attachment.id;

				if (preview) {
					// Original URL, not a resized thumbnail — a resized
					// animated GIF would preview as a static frame.
					preview.innerHTML =
						'<img src="' +
						escapeHtml(attachment.url) +
						'" style="max-height: 48px; width: auto;" />';
				}

				if (removeBtn) {
					removeBtn.style.display = 'inline-block';
				}
			});

			frame.open();
		});

		if (removeBtn) {
			removeBtn.addEventListener('click', function (e) {
				e.preventDefault();
				markInput.value = '0';
				if (preview) {
					preview.innerHTML =
						'<span class="def-core-no-logo">Default spinner</span>';
				}
				removeBtn.style.display = 'none';
			});
		}
	}

	function initFloatingToggle() {
		var checkbox = document.getElementById('def_core_chat_show_floating');
		var warning = document.getElementById('def-core-floating-warning');

		if (!checkbox || !warning) {
			return;
		}

		checkbox.addEventListener('change', function () {
			warning.style.display = checkbox.checked ? 'none' : '';
		});
	}

	function initColorPreview() {
		var colorInputs = document.querySelectorAll('.def-core-color-field input[type="color"]');
		colorInputs.forEach(function (colorInput) {
			var valueSpan = colorInput.parentElement.querySelector(
				'.def-core-color-value'
			);
			colorInput.addEventListener('input', function () {
				if (valueSpan) {
					valueSpan.textContent = colorInput.value;
				}
			});
		});
	}

	// ─── OAuth Connect / Disconnect ──────────────────────────────

	function initOAuth() {
		var startBtn = document.getElementById('def-core-oauth-start-btn');
		var disconnectBtn = document.getElementById('def-core-disconnect-btn');

		if (startBtn) {
			startBtn.addEventListener('click', startOAuth);
		}
		if (disconnectBtn) {
			disconnectBtn.addEventListener('click', disconnectOAuth);
		}
	}

	function startOAuth() {
		var btn = document.getElementById('def-core-oauth-start-btn');
		var resultEl = document.getElementById('def-core-oauth-result');

		if (!btn || !defCoreAdmin) {
			return;
		}

		btn.disabled = true;
		btn.textContent = 'Connecting...';
		if (resultEl) {
			resultEl.className = 'def-core-connection-result';
			resultEl.innerHTML = '';
		}

		var formData = new FormData();
		formData.append('action', 'def_core_oauth_start');
		formData.append('nonce', defCoreAdmin.oauthStartNonce);

		fetch(defCoreAdmin.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (data) {
				if (data.success && data.data.redirect_url) {
					// Redirect to DEFHO authorization page.
					window.location.href = data.data.redirect_url;
				} else {
					btn.disabled = false;
					btn.textContent = 'Connect to DEFHO';
					if (resultEl) {
						resultEl.className = 'def-core-connection-result error';
						resultEl.innerHTML =
							'<span class="dashicons dashicons-dismiss"></span> ' +
							escapeHtml(
								(data.data && data.data.message) ||
									'Failed to start connection.'
							);
					}
				}
			})
			.catch(function () {
				btn.disabled = false;
				btn.textContent = 'Connect to DEFHO';
				if (resultEl) {
					resultEl.className = 'def-core-connection-result error';
					resultEl.innerHTML =
						'<span class="dashicons dashicons-dismiss"></span> Network error. Please try again.';
				}
			});
	}

	function disconnectOAuth() {
		var btn = document.getElementById('def-core-disconnect-btn');
		if (!btn || !defCoreAdmin) {
			return;
		}

		if (
			!confirm(
				'Disconnect from DEFHO?\n\nThis will remove all connection credentials. Your Digital Employees will stop working until you reconnect.'
			)
		) {
			return;
		}

		btn.disabled = true;
		btn.textContent = 'Disconnecting...';

		var formData = new FormData();
		formData.append('action', 'def_core_oauth_disconnect');
		formData.append('nonce', defCoreAdmin.oauthDisconnectNonce);

		fetch(defCoreAdmin.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (data) {
				if (data.success) {
					showToast(
						data.data.message || 'Disconnected.',
						'success'
					);
					// Reload page after short delay to show disconnected state.
					setTimeout(function () {
						window.location.hash = '#connection';
						window.location.reload();
					}, 1500);
				} else {
					showToast(
						(data.data && data.data.message) ||
							'Disconnect failed.',
						'error'
					);
					btn.disabled = false;
					btn.textContent = 'Disconnect';
				}
			})
			.catch(function () {
				showToast('Network error. Please try again.', 'error');
				btn.disabled = false;
				btn.textContent = 'Disconnect';
			});
	}

	// ─── Toast Notifications ──────────────────────────────────────

	function showToast(message, type) {
		var container = document.getElementById('def-core-toast-container');
		if (!container) {
			return;
		}

		var toast = document.createElement('div');
		toast.className = 'def-core-toast ' + type;
		toast.textContent = message;

		container.appendChild(toast);

		// Trigger animation.
		requestAnimationFrame(function () {
			toast.classList.add('show');
		});

		// Auto-dismiss.
		setTimeout(function () {
			toast.classList.remove('show');
			setTimeout(function () {
				if (toast.parentNode) {
					toast.parentNode.removeChild(toast);
				}
			}, 300);
		}, TOAST_DURATION);
	}

	// ─── Utility ──────────────────────────────────────────────────

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

})();
