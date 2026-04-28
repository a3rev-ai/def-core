# DEF Product Cards V1.0

**Status:** Draft for 3-AI review
**Target version:** def-core v3.0.0 (PR #156) + paired DEF (Python) PR
**Author:** Claude Opus 4.7 (drafted with Steve)
**Date:** 2026-04-28

---

## 1. Goals

Render structured product cards inline in the Customer Chat and Staff AI message stream when the Sales Assistant returns search results. Replace the current text-only result presentation with a chat-native card surface that:

1. Shows image, price, title, and a primary action per product
2. Supports `Add to cart` (Customer Chat, simple products only)
3. Supports `View product` (both channels, all products)
4. Caps at 4 cards per result; LLM narrates the count when more results exist
5. Lights up automatically on WooCommerce-active sites; structurally absent on plain WordPress sites

The reference design is the Bunnings "Buddy" implementation: chat-native cards stripped down vs. category-page cards (no ratings, no brand logo, no Compare checkbox, no Special Order badge). Image + price + title + CTA is the minimum useful card.

## 2. Non-Goals (V1.0)

- **Variation picker.** Variable products fall back to `View product` instead of an inline size/colour picker. Picker is V2 polish.
- **Cart matcher fix.** The existing `llm_select_product_and_variation` matcher bug (occasional wrong-product matches when the LLM picks from text) is not addressed here. Cards bypass the matcher entirely — the product search tool returns IDs directly, so cards are unaffected.
- **WP-only sites.** No commerce primitives exist there; the feature is structurally absent because the search tool only registers when `class_exists('WooCommerce')`.
- **Sale prices / strike-through.** Render whatever `price_html` returns from WC; don't compose our own price formatting.
- **Stock urgency badges** (e.g. "Only 2 left!"). Render `in_stock` boolean only.
- **Admin Edit-product action** (Staff AI). Future polish — V1.0 ships with View only on Staff AI.

## 3. User Story

### Customer Chat

> **Visitor:** "What robotic mowers do you have?"
>
> **AI:** *Searched products...* (tool status indicator)
>
> **AI:** "I've found a few robotic mowers that can take the hard work out of keeping your lawn tidy. To help you pick the best one, could you tell me a bit about your lawn?"
>
> [Card: Victa RM100 — $999 — Add to cart]
> [Card: Ozito Brushless — $1,250 — Add to cart]
> [Card: Eufy E15 — $2,999 — View product]  *(variable product)*
> [Card: Worx Landroid — $1,999 — View product]
>
> *(LLM tells visitor "I found 17 — here are the closest 4" if more results existed)*

### Staff AI

> **Staff member:** "What products do we sell in the lawn care category?"
>
> **AI:** *Searched products...*
>
> **AI:** "Here are the top results from your lawn care category:"
>
> [Card: Victa RM100 — $999 — View product]
> [Card: Ozito Brushless — $1,250 — View product]
> [Card: Eufy E15 — $2,999 — View product]
> [Card: Worx Landroid — $1,999 — View product]

(No `Add to cart` — staff are not shopping. View-only action set.)

## 4. Architecture

Two-PR coordination, lockstep release. Mirrors the prior cart-saga pattern (DEF #193 + def-core v2.2.x).

```
┌──────────────────────────────────────────────────────────────────────────┐
│  DEF (Python) — paired PR (lands first)                                 │
├──────────────────────────────────────────────────────────────────────────┤
│  app/tools/woocommerce/search_products.py:                              │
│  - Existing tool returns JSON of search results (LLM consumes).         │
│  - NEW: tool also emits structured `product_cards` in tool_outputs      │
│    with the per-card data shape (see §5). Capped at 4.                  │
│  - NEW: tool also emits `result_count` (total matches before cap)       │
│    so the LLM can narrate "I found 17, here are the closest 4".         │
│  ~50 LOC; no new tool registration, no schema changes.                  │
└──────────────────────────────────────────────────────────────────────────┘
                              │
                              ▼  (DEF release deployed first)
┌──────────────────────────────────────────────────────────────────────────┐
│  def-core (WordPress) — PR #156 (lands second)                          │
├──────────────────────────────────────────────────────────────────────────┤
│  assets/js/def-core-customer-chat.js:                                   │
│  - SSE event handler recognises tool_outputs.product_cards.             │
│  - New renderProductCards(cards) function called after the LLM text     │
│    reply lands. Renders a <div class="def-cc-product-cards"> below.    │
│  - Click on Add to cart: existing wp_rest_call UI action pattern        │
│    POST to /wc/store/v1/cart/add-item. Reuses cart-saga plumbing.       │
│                                                                          │
│  assets/js/def-core-staff-ai.js (or class-def-core-staff-ai.js's        │
│  enqueued bundle):                                                      │
│  - Same SSE handler shape, same renderer wired in.                      │
│  - Channel flag: action set is View-only (no Add to cart).             │
│                                                                          │
│  assets/css/def-core-customer-chat.css + def-core-staff-ai.css:         │
│  - .def-cc-product-cards container                                      │
│  - .def-cc-product-card (image, price, title, action)                   │
│  - Responsive: 2-up desktop, 1-up mobile.                               │
│                                                                          │
│  ~250 LOC + ~80 LOC CSS.                                                │
└──────────────────────────────────────────────────────────────────────────┘
```

## 5. Card Data Contract

The DEF Python tool emits the following shape in `tool_outputs.product_cards`. This is the authoritative interface between DEF and def-core for this feature.

```json
{
  "tool_outputs": {
    "product_cards": [
      {
        "id": 12345,
        "title": "Victa RM100 Robot Mower",
        "price_html": "<span class=\"woocommerce-Price-amount\">$999.00</span>",
        "image_url": "https://example.com/wp-content/uploads/...victa-rm100.jpg",
        "image_alt": "Victa RM100 Robot Mower",
        "product_url": "https://example.com/product/victa-rm100/",
        "is_variable": false,
        "in_stock": true,
        "stock_status": "instock"
      },
      {
        "id": 12389,
        "title": "Eufy Robot Lawn Mower E15",
        "price_html": "<span>$2,999.95</span>",
        "image_url": "https://...eufy-e15.jpg",
        "image_alt": "Eufy Robot Lawn Mower E15",
        "product_url": "https://example.com/product/eufy-e15/",
        "is_variable": true,
        "in_stock": true,
        "stock_status": "instock"
      }
    ],
    "result_count": 17,
    "search_query": "robotic mowers"
  }
}
```

### Field semantics

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | integer | yes | WC product ID; used for add-to-cart payload |
| `title` | string | yes | Plain text (no HTML); from `get_the_title($id)` |
| `price_html` | string | yes | WC's pre-rendered price HTML (`$product->get_price_html()`). May contain spans; passed through DOMPurify on render. |
| `image_url` | string | yes | Full URL to product featured image, `medium_large` size (768px max). Empty string if no image (renderer falls back to placeholder). |
| `image_alt` | string | yes | Alt text for accessibility; defaults to title if no explicit alt set. |
| `product_url` | string | yes | Full URL to single-product page. |
| `is_variable` | bool | yes | True for `variable` product type. Drives `Add to cart` vs `View product` action selection. |
| `in_stock` | bool | yes | True if `$product->is_in_stock()`. Out-of-stock simple products fall back to `View product` (cannot add to cart). |
| `stock_status` | string | optional | Raw WC stock status (`instock`, `outofstock`, `onbackorder`). Reserved for future use. |

### Cap & narration

- DEF caps at **4** products per response in `product_cards`.
- DEF emits `result_count` (full match count from the underlying WC search). LLM uses this in its text reply: *"I found {result_count} — here are the closest 4"*.
- LLM's text response is composed by the DEF orchestrator using the existing JSON results (richer than `product_cards`). `product_cards` is purely a display payload.

## 6. DEF (Python) Changes

### 6.1 Search tool extension

File: `app/tools/woocommerce/search_products.py` (or whatever the current path — to be confirmed during build).

The existing tool:
1. Calls WC product search via REST or DB.
2. Returns full JSON to the LLM.

The change:
1. After the WC search, build a `product_cards` array — first 4 results, mapped to the contract in §5.
2. Build `result_count` from the underlying search count (NOT the capped 4).
3. Add both to `tool_outputs` alongside the existing JSON return.

Pseudo-implementation:

```python
def search_products(query: str, ...) -> dict:
    raw_results = wc_search(query, limit=20)  # cap at 20 for LLM context

    cards = []
    for product in raw_results[:4]:
        cards.append({
            "id": product.id,
            "title": product.title,
            "price_html": product.price_html,
            "image_url": product.image_url("medium_large"),
            "image_alt": product.image_alt or product.title,
            "product_url": product.permalink,
            "is_variable": product.type == "variable",
            "in_stock": product.is_in_stock,
            "stock_status": product.stock_status,
        })

    return {
        # existing return shape — LLM gets full JSON
        "products": [...full result list...],
        # new: structured cards + count
        "tool_outputs": {
            "product_cards": cards,
            "result_count": len(raw_results),
            "search_query": query,
        }
    }
```

### 6.2 Tool prompt context update

Update the Sales Assistant's tool description / system prompt to reference the cards behaviour:
- LLM should narrate the count ("I found 17, here are the closest 4") when `result_count > 4`.
- LLM should NOT repeat the product list in text — the cards render below its reply. Text should contextualise (e.g. *"I'd love to help you pick. Could you tell me a bit about your lawn?"*).

### 6.3 No new endpoint

The cards are emitted via the existing tool-call SSE stream — same channel as `wp_rest_call`. No new REST route, no new permissions.

## 7. def-core Customer Chat Changes

### 7.1 SSE event handler

File: `assets/js/def-core-customer-chat.js`

When the SSE stream emits a `tool_done` event with a `tool_outputs.product_cards` payload, the frontend:

1. Waits for the LLM's text reply to fully stream (existing behaviour).
2. Calls `renderProductCards(cards, channel: 'customer_chat')` after the message bubble finishes.
3. Cards render as a sibling element below the assistant message bubble (NOT inside it — they have their own visual context).

### 7.2 renderProductCards function

```javascript
function renderProductCards(cards, options) {
    // options = { channel: 'customer_chat' | 'staff_ai' }

    var container = el('div', 'def-cc-product-cards');
    container.setAttribute('role', 'list');
    container.setAttribute('aria-label', 'Suggested products');

    cards.forEach(function (card) {
        var cardEl = renderProductCard(card, options);
        container.appendChild(cardEl);
    });

    els.messages.appendChild(container);
    scrollToBottom();
}

function renderProductCard(card, options) {
    var cardEl = el('article', 'def-cc-product-card');
    cardEl.setAttribute('role', 'listitem');

    // Image (clickable, navigates to product_url in same tab)
    var linkEl = document.createElement('a');
    linkEl.href = card.product_url;
    linkEl.className = 'def-cc-product-card-link';
    linkEl.setAttribute('aria-label', 'View ' + card.title);

    var imgEl = document.createElement('img');
    imgEl.src = card.image_url || PLACEHOLDER_IMG;
    imgEl.alt = card.image_alt || card.title;
    imgEl.loading = 'lazy';
    linkEl.appendChild(imgEl);

    cardEl.appendChild(linkEl);

    // Price (DOMPurify-sanitised price_html)
    var priceEl = el('div', 'def-cc-product-card-price');
    priceEl.innerHTML = DOMPurify.sanitize(card.price_html, PRICE_SANITIZE_CONFIG);
    cardEl.appendChild(priceEl);

    // Title (plain text, link to product_url, 2-line ellipsis)
    var titleEl = document.createElement('a');
    titleEl.href = card.product_url;
    titleEl.className = 'def-cc-product-card-title';
    titleEl.textContent = card.title;
    cardEl.appendChild(titleEl);

    // Action button — channel-specific
    var actionEl = renderProductCardAction(card, options);
    cardEl.appendChild(actionEl);

    return cardEl;
}

function renderProductCardAction(card, options) {
    if (options.channel === 'staff_ai') {
        return renderViewProductButton(card);
    }

    // Customer Chat
    if (card.is_variable || !card.in_stock) {
        return renderViewProductButton(card);
    }
    return renderAddToCartButton(card);
}
```

### 7.3 Add-to-cart flow (Customer Chat only)

```javascript
function renderAddToCartButton(card) {
    var btn = el('button', 'def-cc-product-card-add');
    btn.type = 'button';
    btn.innerHTML = '<svg>cart icon</svg> ' + escapeHtml('Add to cart');

    btn.addEventListener('click', function () {
        if (btn.disabled) return;
        btn.disabled = true;
        btn.textContent = 'Adding...';

        // Reuse the wp_rest_call UI action pattern from the cart saga.
        fetch(config.wpRestUrl.replace('a3-ai/v1/', '') + 'wc/store/v1/cart/add-item', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'Nonce': config.wcStoreNonce || '' },
            body: JSON.stringify({ id: card.id, quantity: 1 })
        })
        .then(function (r) {
            if (!r.ok) throw new Error('Add to cart failed: ' + r.status);
            return r.json();
        })
        .then(function (cartResult) {
            btn.textContent = '✓ Added';
            // Optional: emit cart-updated event for any cart icon refresh
            window.dispatchEvent(new CustomEvent('def-cc-cart-updated', { detail: cartResult }));
            setTimeout(function () {
                btn.textContent = 'Add to cart';
                btn.disabled = false;
            }, 2000);
        })
        .catch(function (err) {
            btn.textContent = 'Try again';
            btn.disabled = false;
            console.error('[def-cc] add to cart failed', err);
        });
    });

    return btn;
}
```

**Note on nonce:** WC Store API endpoints use a `Nonce` header (different from WP REST nonce). def-core needs to localize the WC Store nonce alongside other widget config. This may require a small admin-side change in `class-def-core.php` to expose `wcStoreNonce` via `wp_localize_script`.

### 7.4 View product button

```javascript
function renderViewProductButton(card) {
    var btn = document.createElement('a');
    btn.href = card.product_url;
    btn.className = 'def-cc-product-card-view';
    btn.textContent = 'View product →';
    btn.setAttribute('aria-label', 'View ' + card.title);
    return btn;
}
```

Plain anchor — opens in same tab by default. Future polish: `target="_blank"` toggle in admin.

## 8. def-core Staff AI Changes

The Staff AI surface is rendered server-side as a WP page (via `class-def-core-staff-ai.php`) with its own JS bundle. The relevant file for SSE handling is the equivalent of `def-core-customer-chat.js` for Staff AI — to be located during build.

### 8.1 Differences from Customer Chat

- **Action set:** ALL cards render `View product` button. No `Add to cart`.
- **CSS class:** `.def-sa-product-cards` / `.def-sa-product-card` (Staff AI's own namespace).
- **Container styling:** May use slightly larger card width because Staff AI panel is typically wider than Customer Chat modal.

### 8.2 Reuse vs. duplicate

The renderer logic is identical between channels; only the action selection differs. Two options:

1. **Duplicate** the `renderProductCards` and CSS in the Staff AI bundle. Simple, isolated, but maintenance drift risk.
2. **Shared module** loaded by both bundles via a `def-core-product-cards.js` helper enqueued in both contexts.

**Recommendation: shared module.** Channel passed as an option:

```javascript
// def-core-product-cards.js — exposed as window.DefProductCards
window.DefProductCards = {
    render: function (cards, options) { ... },
    renderCard: function (card, options) { ... }
};
```

Both `def-core-customer-chat.js` and the Staff AI bundle call `DefProductCards.render(cards, { channel: 'customer_chat' })` or `'staff_ai'`. Single source of truth for card markup. CSS file is shared (or split into `def-core-product-cards.css` + per-channel scoping overrides).

### 8.3 No add-to-cart plumbing on Staff AI

Staff AI doesn't need WC Store API access. No `wcStoreNonce` localization, no `add-to-cart` POST. Renderer simply doesn't hit those code paths because action selector returns `View product` for all cards.

## 9. UX Specification

### 9.1 Card visual design (per Bunnings reference)

```
┌──────────────────────┐
│                      │
│      [IMAGE]         │   ~160px tall, object-fit: cover, 1:1 ratio
│                      │
├──────────────────────┤
│  $999.00             │   bold, 15px
│  Victa RM100 Robot   │   2-line ellipsis, 14px
│  Mower               │
├──────────────────────┤
│  [+ Add to cart]     │   full-width button, 36px tall, primary color
└──────────────────────┘
```

### 9.2 Container layout

```
┌────────────────────────────────────────────────────┐
│  Customer Chat panel (modal width ~480px)          │
├────────────────────────────────────────────────────┤
│  AI: I've found a few robotic mowers...            │
│                                                    │
│  ┌─────────┬─────────┐                            │
│  │  Card 1 │  Card 2 │  ← 2-up grid desktop       │
│  └─────────┴─────────┘                            │
│  ┌─────────┬─────────┐                            │
│  │  Card 3 │  Card 4 │                            │
│  └─────────┴─────────┘                            │
└────────────────────────────────────────────────────┘
```

- **Desktop (≥481px):** 2-column grid, gap 8px
- **Mobile (≤480px):** 1-column stack, gap 8px
- Spotlight mode: same 2-up grid, slightly larger cards (the canvas can carry it)

### 9.3 Action button styling

| Channel | Product type | Stock | Action | Style |
| --- | --- | --- | --- | --- |
| Customer Chat | Simple | In stock | Add to cart | Primary (green/themed), full-width |
| Customer Chat | Simple | Out of stock | View product | Secondary outline, full-width |
| Customer Chat | Variable | (any) | View product | Secondary outline, full-width |
| Staff AI | (any) | (any) | View product | Secondary outline, full-width |

### 9.4 Hover / interaction states

- Card hover: `transform: scale(1.02)` + `box-shadow` lift (subtle)
- Active / focus: `outline: 2px solid var(--def-cc-primary)` + offset
- Add-to-cart loading: button disabled, text → "Adding..."
- Add-to-cart success: text → "✓ Added" for 2s, then revert
- Add-to-cart failure: text → "Try again", button re-enabled

### 9.5 Click behaviour

- Image click → product page (same tab)
- Title click → product page (same tab)
- View product button click → product page (same tab)
- Add to cart click → POST to WC Store API (no navigation)
- *Future polish:* admin toggle for "Open links in new tab"

## 10. Search Count UX

The cards render **exactly what DEF emits** (capped at 4 by DEF). The "I found 17 — here are the closest 4" wording is in the LLM's text reply, not in the renderer. This keeps the UI declarative — the renderer doesn't reason about counts.

The DEF tool emits `result_count` to give the LLM a reliable number to quote. Without this, the LLM might hallucinate a count or omit it entirely.

If `result_count <= 4`, LLM omits the count language ("Here's what I found:"). If `result_count > 4`, LLM uses the count ("I found 17 — here are the closest 4").

## 11. Conditional Rendering (WC Gate)

The feature is structurally absent on plain WordPress sites because:
1. The product search tool only registers when `class_exists('WooCommerce')` (existing behaviour).
2. If the tool isn't registered, the LLM can't call it.
3. If the LLM doesn't call it, no `tool_outputs.product_cards` is ever emitted.
4. The frontend renderer no-ops when `product_cards` is missing or empty.

**No new conditional logic is needed in def-core.** The renderer is always loaded; it just has nothing to render on WP-only sites.

## 12. Accessibility

- `<div class="def-cc-product-cards" role="list" aria-label="Suggested products">`
- Each card: `<article role="listitem">` with image link aria-labelled by product title
- Image `alt` attribute always present (defaults to title if no explicit alt)
- Buttons have `aria-label` if their visible text is ambiguous
- Focus order: card image link → title link → action button → next card
- Add-to-cart loading state announced via `aria-live="polite"` on a hidden status region
- Out-of-stock cards: visual indication (greyed image, optional badge) AND screen-reader-only "Out of stock" text

## 13. Mobile / Responsive

- Single-column stack at ≤480px
- Card width: 100% (fills container)
- Image height: 160px (same as desktop)
- Touch targets: minimum 44×44 px per WCAG (action button is 36px tall — needs adjustment to 44px on mobile)
- Tap on card image / title → product page
- No hover states on touch (`@media (hover: hover)` guards)

## 14. Security

### 14.1 XSS surface

The card data flows from DEF (Python tool) → SSE stream → `tool_outputs.product_cards` → frontend renderer. Three fields render in the DOM:

1. **`title`** — set via `textContent`. Safe.
2. **`price_html`** — set via `innerHTML` after `DOMPurify.sanitize(html, PRICE_SANITIZE_CONFIG)`. WC produces simple span/sub markup; the sanitizer config allows `<span>`, `<sub>`, `<sup>`, `<bdi>`, `<small>` (typical WC price elements) and strips everything else. Same SANITIZE pattern as live AI markdown rendering.
3. **`image_url`** + **`product_url`** — set via property assignment (`img.src`, `link.href`). Apply the existing `safeLinkHref()` helper from PR #153 to ensure http/https schemes only.

### 14.2 CSRF on add-to-cart

WC Store API uses its own nonce mechanism (`Nonce` header). def-core localizes the nonce to the widget. The fetch sends `credentials: 'include'` for cookie-based auth.

For anonymous visitors, WC Store API works without nonce validation on cart endpoints (per WC design — anonymous shopping is the default). For logged-in users, the nonce ensures session cookie + nonce match.

### 14.3 No new write paths

- Cards are display-only; no admin-configurable card content.
- The `add-to-cart` action is the existing WC Store API endpoint; no new endpoint added.
- No new options stored, no new sanitisers needed.

## 15. Test Plan

### Customer Chat (WC active)

- [ ] Search query for simple in-stock product → card renders with `Add to cart`
- [ ] Click Add to cart → cart count updates, button shows "✓ Added" → reverts
- [ ] Search query for variable product → card renders with `View product`
- [ ] Click View product → navigates to product page (same tab)
- [ ] Search query for out-of-stock simple product → card renders with `View product`
- [ ] Search returns 17 results → 4 cards render, LLM text says "I found 17 — here are the closest 4"
- [ ] Search returns 0 results → no cards, LLM text says "I couldn't find any matching products"
- [ ] Mobile viewport (≤480px) → cards stack vertically, full-width
- [ ] Anonymous + logged-in visitor: add-to-cart works for both
- [ ] Spotlight display mode: cards render correctly with the wider canvas

### Staff AI (WC active)

- [ ] Same product search query → cards render with `View product` only (no Add to cart)
- [ ] Click View product → opens product page (same tab — likely admin user wants single-tab)
- [ ] All 4 product types (simple in-stock, simple out-of-stock, variable, virtual) → all render `View product`

### WP-only site (no WC)

- [ ] Sales Assistant tool not registered → no card payload emitted → no cards render
- [ ] LLM text-only reply works as before

### Accessibility

- [ ] Keyboard navigation: tab through cards in order → image link → title link → action → next card
- [ ] Screen reader: announces "list, 4 items, suggested products" → each card title and price
- [ ] Focus indicators visible on all interactive elements
- [ ] aria-live announcement when add-to-cart completes

### Security

- [ ] Inject `<script>` into a product title → renders as text (no execution)
- [ ] Manipulate `price_html` to include `<img onerror>` → sanitised away by DOMPurify
- [ ] Inject `javascript:alert(1)` as `product_url` → blocked by `safeLinkHref()` (renders as plain text, no link)

## 16. Files Changed (estimate)

### DEF (Python) — paired PR

| File | Change | LOC |
| --- | --- | --- |
| `app/tools/woocommerce/search_products.py` | Emit `product_cards` + `result_count` in tool_outputs | ~50 |
| Sales Assistant prompt context | Add narration guidance for `result_count > 4` | ~10 |
| Tests | Cover `product_cards` shape, count logic, image_url fallback | ~80 |

### def-core — PR #156

| File | Change | LOC |
| --- | --- | --- |
| `assets/js/def-core-product-cards.js` | NEW shared renderer module (`window.DefProductCards`) | ~150 |
| `assets/js/def-core-customer-chat.js` | SSE handler hooks into product_cards; calls renderer with channel='customer_chat'; add-to-cart wiring | ~50 |
| `assets/js/def-core-staff-ai.js` (or equivalent Staff AI bundle) | Same hook with channel='staff_ai' | ~30 |
| `assets/css/def-core-product-cards.css` | NEW shared card styles | ~120 |
| `includes/class-def-core.php` | Localize `wcStoreNonce` for Customer Chat | ~5 |
| `includes/class-def-core-staff-ai.php` | Enqueue product-cards JS + CSS in Staff AI context | ~10 |
| `def-core.php` + `readme.txt` + `changelog.txt` + `README.md` | Version bump 2.9.0 → 3.0.0 | ~10 |

**Total estimate: ~370 LOC across both repos** (~140 Python + ~230 JS/CSS/PHP). Some overlap with the original 250 estimate due to the shared module + Staff AI wiring.

## 17. Open Questions

1. **Sale prices.** WC's `price_html` includes `<del>` for sale strike-through. DOMPurify config should allow `<del>` and `<ins>`. Confirm during build.

2. **WC Store API nonce on Customer Chat anonymous path.** WC Store endpoints can be called without nonce by anonymous users, but logged-in users need it. Need to test both paths during build to ensure the `Nonce` header is sent appropriately.

3. **Image fallback.** What if a product has no featured image? Render a placeholder SVG, or skip the image entirely? Recommend: skip image, render card with title + price + action only (compact card).

4. **Card click vs. button click.** When a card has an Add to cart button, clicking the image still navigates to the product page. Is this discoverable? (Possibly — the image is wrapped in `<a>`, so cursor changes to pointer on hover.) Worth user testing.

5. **Multiple product searches in one conversation.** If the user searches twice, two card containers render in the message stream. That's the expected behaviour (each search is its own tool call), but is this visually overwhelming? Cap to last 4 cards globally vs. per-call?

6. **"Searched products..." status indicator wording.** Today says "Getting product details..." — should it say "Searching..." for the new card-rendering path? Probably yes — the user is initiating a search, not requesting details. Tool name change in DEF prompt context.

7. **Cart matcher edge case.** When the LLM picks one of the 4 displayed products via natural language ("add the second one"), does that go through the matcher? It SHOULDN'T — the LLM has the IDs from `product_cards` in its context. Need to verify the prompt structures the IDs as picks.

8. **Performance: many results.** WC search with 100k products on a large site — does the search tool handle pagination/limits cleanly? Existing tool likely has a cap; confirm during build.

## 18. Out of Scope (explicit non-goals for V1.0)

- Variation picker (drawer/dropdown for variable products)
- Admin Edit-product action on Staff AI (`Edit product ✎` button)
- Sale price formatting beyond what `price_html` produces
- Stock urgency badges ("Only 2 left!")
- Compare / wishlist actions
- Product cards on Setup Assistant channel (no commerce intent there)
- Knowledge-base result cards (different feature, same renderer pattern — future)
- Search-as-you-type or autocomplete inside the chat input

## 19. Future Iterations

- **V1.1** — Variation picker drawer for variable products. Tap "View options" on the card → inline drawer slides up with size/colour selectors → "Add to cart" enabled once variation chosen.
- **V1.2** — Edit product action on Staff AI for users with `edit_products` cap.
- **V1.3** — KB result cards (same renderer, different action set: "View article", "Open in new tab").
- **V1.4** — Cart icon refresh hook (the `def-cc-cart-updated` CustomEvent) wired to a tenant-side cart counter widget.
- **V2.0** — Real-time cart sync (cards reflect "In cart: 2" badge if the visitor has already added).

## 20. 3-AI Review Checklist

For ChatGPT + Grok review of this V1.0 spec:

- [ ] Does the card data contract (§5) cover all the fields needed? Anything missing for a typical WC product?
- [ ] Is the two-PR coordination (§4) the right shape, or should this be a single big PR?
- [ ] Should the renderer be a shared module (§8.2) or duplicated?
- [ ] Is the WC Store API the right add-to-cart endpoint, or should we use the legacy admin-ajax cart_add?
- [ ] Are the open questions (§17) the right ones? What else should be locked down before build?
- [ ] Is the security review (§14) thorough enough? Any threat vectors missed?
- [ ] Is the test plan (§15) complete? Edge cases not covered?
- [ ] Is the LOC estimate (§16) realistic?
- [ ] Major architectural concerns?

---

**End of Spec V1.0 — pending 3-AI review for V1.1.**
