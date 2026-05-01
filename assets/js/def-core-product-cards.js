/**
 * Digital Employee Framework — Result Cards Renderer (V1.2)
 *
 * Shared module for chat-native result cards. Used by Customer Chat and
 * Staff AI bundles. Builds DOM nodes for sections (heading + description +
 * card grid) returned by the DEF backend's search_products tool.
 *
 * Architecture: V1.2 spec §7.2.
 *
 * Public API: window.DefResultCards
 *   - renderSection(payload, options) → HTMLElement | null
 *   - resetTurn()
 *   - SECTION_FRONTEND_CAP (defensive cap, currently 6)
 *
 * Dependencies (must be globals): DOMPurify (for subtitle_html sanitization).
 *
 * Click handling: this module produces buttons/links but does NOT wire
 * up cart-add or other side-effect actions. The consuming bundle is
 * responsible for delegated click handling on `.def-cc-result-card-add`
 * (route through its existing wp_rest_call pipeline).
 */
(function () {
	'use strict';

	var SECTION_FRONTEND_CAP = 6; // V1.2 §22 watchlist defensive guard
	var MAX_CARDS_PER_SECTION = 4;
	var sectionsRenderedThisTurn = 0;

	// V1.2 §7.3 — DOMPurify allowlist for WC `price_html` (sale markup, etc.)
	var SUBTITLE_SANITIZE_CONFIG = {
		ALLOWED_TAGS: ['span', 'sub', 'sup', 'small', 'bdi', 'del', 'ins', 'strong', 'em'],
		ALLOWED_ATTR: ['class'],
		FORBID_ATTR: ['style']
	};

	/**
	 * Reject non-http(s) URLs (defends against javascript:, data:, vbscript:).
	 * Returns the URL unchanged if safe, or null to indicate "do not link".
	 */
	function safeLinkHref(url) {
		if (typeof url !== 'string') return null;
		var trimmed = url.trim();
		if (!trimmed) return null;
		// Absolute URL with explicit scheme: must be http or https.
		if (/^[a-z][a-z0-9+.-]*:/i.test(trimmed)) {
			try {
				var u = new URL(trimmed);
				return (u.protocol === 'http:' || u.protocol === 'https:') ? trimmed : null;
			} catch (e) {
				return null;
			}
		}
		// Relative URL is OK (e.g. /wp-admin/post.php?post=123&action=edit).
		return trimmed;
	}

	function renderSection(payload, options) {
		options = options || {};

		if (!payload || !Array.isArray(payload.result_cards) || payload.result_cards.length === 0) {
			return null;
		}
		if (sectionsRenderedThisTurn >= SECTION_FRONTEND_CAP) {
			console.warn('[DefResultCards] section frontend cap (' + SECTION_FRONTEND_CAP + ') reached; ignoring extra section');
			return null;
		}
		// V1.2 ships only wp_product. V1.3/V1.4 will dispatch on this field.
		if (payload.result_type !== 'wp_product') {
			console.warn('[DefResultCards] unknown result_type:', payload.result_type);
			return null;
		}
		// Fail closed if DOMPurify isn't available — never render raw HTML.
		if (!window.DOMPurify || typeof window.DOMPurify.sanitize !== 'function') {
			console.error('[DefResultCards] DOMPurify unavailable — refusing to render');
			return null;
		}

		// Defensive: drop cards missing required fields, validate URL, clamp count.
		var validCards = payload.result_cards.filter(function (c) {
			return c
				&& typeof c.id === 'number'
				&& c.title
				&& typeof c.url === 'string'
				&& safeLinkHref(c.url) !== null;
		}).slice(0, MAX_CARDS_PER_SECTION);

		if (validCards.length === 0) {
			return null;
		}

		var section = document.createElement('section');
		section.className = 'def-cc-result-section';

		// Only render heading element if non-empty — empty <h3> is an a11y
		// violation and the description-skip pattern below already does this.
		if (payload.section_heading && payload.section_heading.trim()) {
			var heading = document.createElement('h3');
			heading.className = 'def-cc-result-section-heading';
			heading.textContent = payload.section_heading;
			section.appendChild(heading);
		}

		if (payload.section_description && payload.section_description.trim()) {
			var desc = document.createElement('p');
			desc.className = 'def-cc-result-section-description';
			desc.textContent = payload.section_description;
			section.appendChild(desc);
		}

		var grid = document.createElement('div');
		grid.className = 'def-cc-result-cards';
		grid.setAttribute('role', 'list');
		grid.setAttribute('aria-label', payload.section_heading || 'Product results');

		validCards.forEach(function (card) {
			grid.appendChild(renderCard(card, options));
		});

		section.appendChild(grid);
		sectionsRenderedThisTurn++;
		return section;
	}

	function renderCard(card, options) {
		var article = document.createElement('article');
		article.className = 'def-cc-result-card';
		article.setAttribute('role', 'listitem');

		var safeUrl = safeLinkHref(card.url);

		// Image link — aria-hidden so the title link is the sole accessible name
		// (avoids screen-reader announcing the same destination twice per card).
		var imgLink = document.createElement('a');
		imgLink.href = safeUrl;
		imgLink.className = 'def-cc-result-card-image-link';
		imgLink.setAttribute('aria-hidden', 'true');
		imgLink.setAttribute('tabindex', '-1');

		var img = document.createElement('img');
		img.src = card.image_url || '';
		img.alt = card.image_alt || card.title;
		img.loading = 'lazy';
		imgLink.appendChild(img);
		article.appendChild(imgLink);

		// Title link — the accessible name. Rendered before price so the
		// product name reads first (per archive-page convention).
		var titleLink = document.createElement('a');
		titleLink.href = safeUrl;
		titleLink.className = 'def-cc-result-card-title';
		titleLink.textContent = card.title;
		article.appendChild(titleLink);

		// Subtitle (WC price_html with sale markup), DOMPurify-sanitised.
		var subtitle = document.createElement('div');
		subtitle.className = 'def-cc-result-card-subtitle';
		subtitle.innerHTML = window.DOMPurify.sanitize(
			card.subtitle_html || '',
			SUBTITLE_SANITIZE_CONFIG
		);
		article.appendChild(subtitle);

		article.appendChild(renderAction(card, options));
		return article;
	}

	// Product types that can be added to cart in one click from the archive
	// (no variation or option selection needed). WC Subscriptions registers
	// `subscription` for Simple Subscription; Variable Subscription
	// (`variable-subscription`) needs tier selection on the product page so
	// it falls back to View product.
	var ADD_TO_CART_TYPES = ['simple', 'subscription'];

	function renderAction(card, options) {
		if (options && options.channel === 'staff_ai') {
			return renderEditProductLink(card);
		}
		if (ADD_TO_CART_TYPES.indexOf(card.product_type) === -1 || !card.in_stock) {
			return renderViewProductLink(card);
		}
		return renderAddToCartButton(card);
	}

	function renderAddToCartButton(card) {
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'def-cc-result-card-add';
		btn.textContent = '+ Add to cart';
		btn.setAttribute('data-product-id', String(card.id));
		btn.setAttribute('data-product-name', card.title);
		btn.setAttribute('aria-label', 'Add ' + card.title + ' to cart');
		return btn;
	}

	function renderViewProductLink(card) {
		var link = document.createElement('a');
		link.href = safeLinkHref(card.url);
		link.className = 'def-cc-result-card-view';
		link.textContent = 'View product →';
		link.setAttribute('aria-label', 'View ' + card.title);
		return link;
	}

	function renderEditProductLink(card) {
		var link = document.createElement('a');
		// id was validated as number in renderSection; encode defensively anyway.
		link.href = '/wp-admin/post.php?post=' + encodeURIComponent(card.id) + '&action=edit';
		link.className = 'def-cc-result-card-edit';
		link.textContent = 'Edit product';
		link.setAttribute('aria-label', 'Edit ' + card.title);
		return link;
	}

	/**
	 * Reset the per-turn section counter. Call between user messages so the
	 * frontend cap of 6 sections applies per assistant turn, not per session.
	 */
	function resetTurn() {
		sectionsRenderedThisTurn = 0;
	}

	window.DefResultCards = {
		renderSection: renderSection,
		resetTurn: resetTurn,
		SECTION_FRONTEND_CAP: SECTION_FRONTEND_CAP
	};
})();
