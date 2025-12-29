/**
 * Digital Employee WordPress Bridge - Admin Scripts
 */
(function() {
	'use strict';

	document.addEventListener('DOMContentLoaded', function() {
		// Handle toggle switches
		const toggleSwitches = document.querySelectorAll('.de-wp-bridge-toggle-switch input[type="checkbox"]:not(:disabled)');
		
		toggleSwitches.forEach(function(toggle) {
			// Make slider clickable - click on slider triggers checkbox
			const slider = toggle.nextElementSibling;
			if (slider && slider.classList.contains('de-wp-bridge-slider')) {
				slider.addEventListener('click', function(e) {
					e.preventDefault();
					e.stopPropagation();
					// Toggle the checkbox
					toggle.click();
				});
			}
			
			// Handle checkbox change to add/remove hidden input for value 0
			toggle.addEventListener('change', function() {
				const toggleSwitch = this.closest('.de-wp-bridge-toggle-switch');
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
				const event = new CustomEvent('de-wp-bridge-toggle-change', {
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
						row.classList.remove('de-wp-bridge-disabled');
						row.classList.add('de-wp-bridge-enabled');
					} else {
						row.classList.remove('de-wp-bridge-enabled');
						row.classList.add('de-wp-bridge-disabled');
					}
				}
			});
		});

		// Add visual state classes on page load
		toggleSwitches.forEach(function(toggle) {
			const row = toggle.closest('tr');
			if (row) {
				if (toggle.checked) {
					row.classList.add('de-wp-bridge-enabled');
				} else {
					row.classList.add('de-wp-bridge-disabled');
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
	});
})();

