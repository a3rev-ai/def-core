/**
 * Cart Sync Script
 * 
 * Listens for postMessage from chatbot iframe to sync WooCommerce session cookie
 * in the parent window (WordPress site).
 */
(function() {
	'use strict';

	// Listen for messages from iframe
	window.addEventListener('message', function(event) {
		// Verify message type
		if (event.data && event.data.type === 'a3ai:set-cookie') {
			const cookie = event.data.cookie;
			
			if (!cookie || !cookie.name || !cookie.value) {
				console.warn('[SESSION COOKIE SYNC] Invalid cookie data received');
				return;
			}

			// Build cookie string
			let cookieString = cookie.name + '=' + cookie.value;
			
			if (cookie.expires) {
				cookieString += '; expires=' + cookie.expires;
			}
			
			if (cookie.path) {
				cookieString += '; path=' + cookie.path;
			} else {
				cookieString += '; path=/';
			}
			
			if (cookie.domain) {
				cookieString += '; domain=' + cookie.domain;
			}
			
			cookieString += '; SameSite=Lax';
			
			// Set cookie in parent window
			document.cookie = cookieString;
			
			console.log('[SESSION COOKIE SYNC] Cookie set in parent window:', cookie.name);
			console.log('[SESSION COOKIE SYNC] Cookie value:', cookie.value.substring(0, 50) + '...');
			
			// Trigger WooCommerce cart fragment refresh if available
			if (typeof jQuery !== 'undefined' && jQuery.fn.trigger) {
				jQuery(document.body).trigger('wc_fragment_refresh');
				console.log('[SESSION COOKIE SYNC] Triggered WooCommerce cart fragment refresh');
			}
		}
	}, false);
})();

