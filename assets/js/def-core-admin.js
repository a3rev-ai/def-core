/**
 * Digital Employee Framework - Core - Admin Scripts
 * Phase 7 D-I: Tab switching, AJAX save, connection test, keyboard navigation.
 */
(function () {
	'use strict';

	var STORAGE_KEY = 'def_core_active_tab';
	var TOAST_DURATION = 4000;
	var tabs, panels;

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
		initPasswordToggle();
		initServiceAuth();
		initCopyButtons();
		initToggleSwitches();
		initWidgetGuide();
		autoTestConnection();
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
		return 'connection';
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
		if (tabId === 'connection') {
			// Collect text/url/password inputs with data-setting attribute.
			panel
				.querySelectorAll('[data-setting]')
				.forEach(function (input) {
					formData.append(
						'settings[' + input.dataset.setting + ']',
						input.value
					);
				});
		} else if (tabId === 'employees-tools') {
			// Collect tool toggle checkboxes.
			panel
				.querySelectorAll('.def-core-tool-toggle')
				.forEach(function (checkbox) {
					if (!checkbox.disabled) {
						formData.append(
							'settings[def_core_tools_status][' +
								checkbox.dataset.route +
								']',
							checkbox.checked ? '1' : '0'
						);
					}
				});
		}
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
			return;
		}

		// If API URL is configured, auto-test.
		var apiUrlInput = document.getElementById(
			'def_core_staff_ai_api_url'
		);
		if (apiUrlInput && apiUrlInput.value) {
			testConnection();
		}
	}

	// ─── Password Toggle ─────────────────────────────────────────

	function initPasswordToggle() {
		document
			.querySelectorAll('.def-core-password-toggle')
			.forEach(function (btn) {
				btn.addEventListener('click', function () {
					var wrap = btn.closest('.def-core-password-wrap');
					var input = wrap.querySelector('input');
					var icon = btn.querySelector('.dashicons');

					if (input.type === 'password') {
						input.type = 'text';
						icon.className = 'dashicons dashicons-hidden';
						btn.setAttribute('aria-label', 'Hide API key');
					} else {
						input.type = 'password';
						icon.className = 'dashicons dashicons-visibility';
						btn.setAttribute('aria-label', 'Show API key');
					}
				});
			});
	}

	// ─── Service Auth Regeneration ────────────────────────────────

	function initServiceAuth() {
		var btn = document.getElementById('def-core-regenerate-secret-btn');
		if (!btn) {
			return;
		}

		btn.addEventListener('click', function () {
			var confirmed = confirm(
				'WARNING: Generating a new secret will invalidate the current one!\n\n' +
					"You MUST update your Python app's .env file immediately after generating a new secret, " +
					'or anonymous customer escalation will stop working.\n\n' +
					'Are you sure you want to generate a new secret?'
			);

			if (!confirmed) {
				return;
			}

			btn.disabled = true;
			var originalText = btn.textContent;
			btn.textContent = 'Generating...';

			var formData = new FormData();
			formData.append('action', 'def_core_regenerate_service_secret');
			formData.append('nonce', defCoreAdmin.secretNonce);

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
						var secretInput = document.getElementById(
							'def_service_auth_secret'
						);
						if (secretInput) {
							secretInput.value = data.data.secret;
						}

						// Update copy button data attribute.
						var copyBtn = document.getElementById(
							'def-core-copy-secret-btn'
						);
						if (copyBtn) {
							copyBtn.dataset.copy = data.data.secret;
						}

						// Update env line.
						var envLine = document.getElementById(
							'def-core-secret-env-line'
						);
						if (envLine) {
							envLine.textContent =
								'DEF_SERVICE_AUTH_SECRET=' + data.data.secret;
						}

						showToast(data.data.message, 'success');
					} else {
						showToast(
							(data.data && data.data.message) ||
								'Failed to generate new secret.',
							'error'
						);
					}
				})
				.catch(function () {
					showToast('Network error. Please try again.', 'error');
				})
				.finally(function () {
					btn.textContent = originalText;
					btn.disabled = false;
				});
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

	// ─── Widget Guide Collapsibles ────────────────────────────────

	function initWidgetGuide() {
		document
			.querySelectorAll('.widget-guide-toggle')
			.forEach(function (toggle) {
				toggle.addEventListener('click', function () {
					var content = toggle.nextElementSibling;
					if (
						content &&
						content.classList.contains('widget-guide-content')
					) {
						var isHidden = content.style.display === 'none';
						content.style.display = isHidden ? 'block' : 'none';
						toggle.classList.toggle('active', isHidden);
					}
				});
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
