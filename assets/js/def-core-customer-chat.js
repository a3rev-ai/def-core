/**
 * Digital Employee Framework - Customer Chat Module
 * Phase 8 Sub-PR B: Full chat engine (lazy-loaded by loader).
 *
 * Placeholder — Sub-PR B implements the chat engine.
 * The loader script calls window.DEFCustomerChat.init(shadowRoot, config)
 * after this script loads.
 */
window.DEFCustomerChat = {
	init: function (shadowRoot, config) {
		// Remove loading spinner.
		var loading = shadowRoot.querySelector('.def-cc-loading');
		if (loading) {
			loading.innerHTML =
				'<span style="color:#9ca3af;font-size:14px;text-align:center;padding:20px;">' +
				'Chat engine loading in Sub-PR B.' +
				'</span>';
		}
	},
};
