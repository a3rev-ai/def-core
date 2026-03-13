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
	}

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

			document
				.querySelectorAll('.def-core-role-cb')
				.forEach(function (cb) {
					formData.append(
						'roles[' +
							cb.dataset.user +
							'][' +
							cb.dataset.cap +
							']',
						cb.checked ? '1' : '0'
					);
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
			var tr = document.createElement('tr');
			tr.setAttribute('data-user-id', u.id);
			tr.setAttribute('data-wp-role', (u.role || '').toLowerCase());
			var avatarHtml = u.avatar
				? '<img class="def-core-user-avatar" src="' + escHtml(u.avatar) + '" width="24" height="24" alt="" />'
				: '';
			var rowNameHtml = '<strong>' + escHtml(u.display_name) + '</strong>';
			if (u.user_login && u.user_login !== u.display_name) {
				rowNameHtml += ' <span class="def-core-user-login">(' + escHtml(u.user_login) + ')</span>';
			}
			tr.innerHTML =
				'<td>' +
				avatarHtml +
				rowNameHtml +
				'<span class="def-core-user-email">' +
				escHtml(u.email) +
				'</span>' +
				'</td>' +
				'<td>' +
				escHtml(u.role) +
				'</td>' +
				'<td class="def-core-role-col">' +
				'<input type="checkbox" class="def-core-role-cb" data-user="' +
				u.id +
				'" data-cap="def_staff_access" />' +
				'</td>' +
				'<td class="def-core-role-col">' +
				'<input type="checkbox" class="def-core-role-cb" data-user="' +
				u.id +
				'" data-cap="def_management_access" />' +
				'</td>' +
				'<td class="def-core-role-col">' +
				'<input type="checkbox" class="def-core-role-cb" data-user="' +
				u.id +
				'" data-cap="def_admin_access" />' +
				'</td>' +
				'<td class="def-core-role-col">' +
				'<button type="button" class="def-core-remove-user-btn" data-user-id="' +
				u.id +
				'" title="Remove all DEF access">&times;</button>' +
				'</td>';

			tbody.appendChild(tr);
			bindRemoveButton(tr.querySelector('.def-core-remove-user-btn'));
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
							' from all Digital Employee access?\n\nThis will revoke their Staff AI, Management, and DEF Admin permissions immediately.'
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
			// Pre-check capabilities from Setup Assistant response.
			var row = tbody.querySelector('tr[data-user-id="' + u.id + '"]');
			if (row && u.caps) {
				var cbs = row.querySelectorAll('input[type="checkbox"]');
				for (var i = 0; i < cbs.length; i++) {
					var cap = cbs[i].getAttribute('data-cap');
					if (u.caps.hasOwnProperty(cap)) {
						cbs[i].checked = !!u.caps[cap];
					}
				}
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

		if (!radios.length || !drawerOptions) {
			return;
		}

		radios.forEach(function (radio) {
			radio.addEventListener('change', function () {
				drawerOptions.style.display =
					radio.value === 'drawer' ? 'block' : 'none';
			});
		});
	}

	// ─── Button Appearance ────────────────────────────────────────

	function initButtonAppearance() {
		initIconUploader();
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
