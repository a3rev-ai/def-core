/**
 * Digital Employee Framework - Core - Admin Scripts
 */
(function() {
	'use strict';

	document.addEventListener('DOMContentLoaded', function() {
		// Handle toggle switches
		const toggleSwitches = document.querySelectorAll('.def-core-toggle-switch input[type="checkbox"]:not(:disabled)');
		
		toggleSwitches.forEach(function(toggle) {
			// Make slider clickable - click on slider triggers checkbox
			const slider = toggle.nextElementSibling;
			if (slider && slider.classList.contains('def-core-slider')) {
				slider.addEventListener('click', function(e) {
					e.preventDefault();
					e.stopPropagation();
					// Toggle the checkbox
					toggle.click();
				});
			}
			
			// Handle checkbox change to add/remove hidden input for value 0
			toggle.addEventListener('change', function() {
				const toggleSwitch = this.closest('.def-core-toggle-switch');
				const hiddenInput = toggleSwitch.querySelector('input[type="hidden"]');
				
				if (this.checked) {
					// Remove hidden input when checked (value 1 will be sent)
					if (hiddenInput) {
						hiddenInput.remove();
					}
				} else {
					// Add hidden input when unchecked (value 0 will be sent)
					if (!hiddenInput) {
						const newHiddenInput = document.createElement('input');
						newHiddenInput.type = 'hidden';
						newHiddenInput.name = this.name;
						newHiddenInput.value = '0';
						toggleSwitch.insertBefore(newHiddenInput, this);
					}
				}
			});
			
			// Add click event listener for custom event
			toggle.addEventListener('click', function(e) {
				const toolKey = this.name.match(/\[([^\]]+)\]/);
				const toolName = this.closest('tr')?.querySelector('label')?.textContent?.trim() || '';
				const isEnabled = this.checked;
				
				// Trigger custom event
				const event = new CustomEvent('def-core-toggle-change', {
					detail: {
						toolKey: toolKey ? toolKey[1] : '',
						toolName: toolName,
						enabled: isEnabled,
						element: this,
						row: this.closest('tr')
					},
					bubbles: true,
					cancelable: true
				});
				
				// Dispatch event on the toggle element and document
				this.dispatchEvent(event);
				document.dispatchEvent(event);
			});
			
			// Add change event listener for visual feedback
			toggle.addEventListener('change', function() {
				// Visual feedback
				const row = this.closest('tr');
				if (row) {
					if (this.checked) {
						row.classList.remove('def-core-disabled');
						row.classList.add('def-core-enabled');
					} else {
						row.classList.remove('def-core-enabled');
						row.classList.add('def-core-disabled');
					}
				}
			});
		});

		// Add visual state classes on page load
		toggleSwitches.forEach(function(toggle) {
			const row = toggle.closest('tr');
			if (row) {
				if (toggle.checked) {
					row.classList.add('def-core-enabled');
				} else {
					row.classList.add('def-core-disabled');
				}
			}
		});

		// Handle collapsible widget guide sections
		const widgetGuideToggles = document.querySelectorAll('.widget-guide-toggle');
		widgetGuideToggles.forEach(function(toggle) {
			toggle.addEventListener('click', function() {
				const content = this.nextElementSibling;
				if (content && content.classList.contains('widget-guide-content')) {
					const isHidden = content.style.display === 'none';
					content.style.display = isHidden ? 'block' : 'none';
					this.classList.toggle('active', isHidden);
				}
			});
		});

		// Handle regenerate service auth secret button
		const regenerateBtn = document.getElementById('def-core-regenerate-secret-btn');
		if (regenerateBtn) {
			regenerateBtn.addEventListener('click', function() {
				// Show confirmation dialog
				const confirmed = confirm(
					'⚠️ WARNING: Generating a new secret will invalidate the current one!\n\n' +
					'You MUST update your Python app\'s .env file immediately after generating a new secret, ' +
					'or anonymous customer escalation will stop working.\n\n' +
					'Are you sure you want to generate a new secret?'
				);

				if (!confirmed) {
					return;
				}

				const btn = this;
				const nonce = btn.dataset.nonce;
				const secretInput = document.getElementById('def_service_auth_secret');
				const originalText = btn.textContent;

				// Disable button and show loading state
				btn.disabled = true;
				btn.textContent = 'Generating...';

				// Make AJAX request
				const formData = new FormData();
				formData.append('action', 'def_core_regenerate_service_secret');
				formData.append('nonce', nonce);

				fetch(ajaxurl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin'
				})
				.then(response => response.json())
				.then(data => {
					if (data.success) {
						// Update the secret field
						secretInput.value = data.data.secret;

						// Update the copy button's onclick
						const copyBtn = btn.previousElementSibling;
						if (copyBtn) {
							copyBtn.onclick = function() {
								navigator.clipboard.writeText(data.data.secret);
								this.textContent = 'Copied!';
								setTimeout(() => this.textContent = 'Copy', 2000);
							};
						}

						// Update the code snippet
						const codeSnippet = btn.closest('.def-core-service-auth-field').querySelector('code');
						if (codeSnippet) {
							codeSnippet.textContent = 'DEF_SERVICE_AUTH_SECRET=' + data.data.secret;
						}

						// Show success message
						btn.textContent = 'Generated!';
						setTimeout(() => {
							btn.textContent = originalText;
							btn.disabled = false;
						}, 2000);

						// Show alert with reminder
						alert('✅ New secret generated!\n\nIMPORTANT: Copy the new secret and update your Python app\'s .env file NOW.');
					} else {
						alert('Error: ' + (data.data?.message || 'Failed to generate new secret'));
						btn.textContent = originalText;
						btn.disabled = false;
					}
				})
				.catch(error => {
					console.error('Error:', error);
					alert('Error: Failed to generate new secret. Check console for details.');
					btn.textContent = originalText;
					btn.disabled = false;
				});
			});
		}
	});
})();

