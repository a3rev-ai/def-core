# DEF Product Cards V1.1

**Status:** Draft for 3-AI review (incorporates V1.0 review feedback)
**Target version:** def-core v3.0.0 + paired DEF (Python) PR
**Author:** Claude Opus 4.7 (drafted with Steve)
**Date:** 2026-04-29

---

## Changes from V1.0

| # | V1.0 → V1.1 change | Source |
| --- | --- | --- |
| 1 | `image_url` size: `medium_large` → `woocommerce_thumbnail` | Review #1 |
| 2 | Staff AI action: `View product` (cap-gated) → `Edit product` (always shown; WP handles permission rejection) | Review #2 |
| 3 | Container layout: 2-up desktop / 1-up mobile → **2 cols <720px / 4 cols ≥720px** via container query | Discussion |
| 4 | **Architecture: flat 4-card grid → LLM-orchestrated sections.** Each tool call returns one thematic section (heading + description + 3-4 cards). LLM plans multiple targeted searches for broad queries. | Discussion (informed by Bunnings reverse-engineering) |
| 5 | Cap: "4 globally" → "3-4 cards per section, no global cap" | Follows from #4 |
| 6 | "View all 17 results" linkable count → **dropped.** Curation across sections replaces it. | Bunnings doesn't do this; sections render the value |
| 7 | Sale prices: confirmed via `$product->get_price_html()`; DOMPurify must allow `<del>` and `<ins>` | Review #5 |
| 8 | Image fallback: skip / custom SVG → `wc_placeholder_img_src('woocommerce_thumbnail')` | Review #6 |
| 9 | Card structure: explicit Bunnings-style HTML — separate `<a>` for image and title; action button NOT wrapped in card-link | Review #7 |
| 10 | Multiple searches per conversation: confirmed per-search container; reinforced by Bunnings example | Review #8 |
| 11 | Status indicator: *"Getting product details…"* → *"Searching now …"* | Review #9 |
| 12 | Pagination: confirmed dropped — max 4 per section, chat scroll only | Review #10 |
| 13 | `Edit product` on Staff AI: moved from Out-of-Scope → In-Scope | Review #11 |
| 14 | New §11.5 "LLM Orchestration Pattern" — explains how the LLM uses multiple tool calls to build multi-section responses | Result of #4 |
| 15 | New §22 "V2 Watch List" — features we'd add if option (c) orchestration shows specific problems in production | Result of #4 |

---

## 1. Goals

Render structured product cards inline in the Customer Chat and Staff AI message stream when the Sales Assistant returns search results. Cards organise into **thematic sections** (heading + description + card grid), with the LLM orchestrating multiple targeted searches per user query to produce multi-section responses. Replace the current text-only result presentation with chat-native, sectioned product surfaces that:

1. Show image, price, title, and a primary action per card
2. Support `Add to cart` (Customer Chat, simple in-stock products only)
3. Support `View product` (Customer Chat, for variable + out-of-stock products)
4. Support `Edit product` (Staff AI, always — WP handles permission rejection if user lacks `edit_products` cap)
5. Render 3-4 cards per section; LLM decides how many sections per response based on query breadth
6. Light up automatically on WooCommerce-active sites; structurally absent on plain WordPress sites

**Reference design:** Bunnings "Buddy" — confirmed via reverse-engineering of their multi-section response pattern. Each section in their UI is a distinct search query, and the LLM (Buddy) is the orchestrator deciding how to split a broad question into thematic sub-searches.

## 2. Non-Goals (V1.1)

- **Variation picker.** Variable products fall back to `View product` instead of an inline size/colour picker. Picker is V2 polish.
- **Cart matcher fix.** The existing `llm_select_product_and_variation` matcher bug is not addressed here. Cards bypass the matcher entirely — the product search tool returns IDs directly.
- **WP-only sites.** No commerce primitives exist there; the feature is structurally absent because the search tool only registers when `class_exists('WooCommerce')`.
- **Sale price formatting beyond `price_html`.** Render whatever WC's `$product->get_price_html()` returns; don't compose our own.
- **Stock urgency badges** (e.g. "Only 2 left!"). Render `in_stock` boolean only.
- **Compare / wishlist actions.**
- **Product cards on Setup Assistant channel** — no commerce intent there.
- **Knowledge-base result cards** — different feature, same renderer pattern (future).
- **Search-as-you-type or autocomplete inside the chat input.**

## 3. User Story

### Customer Chat — broad query produces multi-section response

> **Visitor:** "What electric drills do you have for a home handyman?"
>
> **AI:** *Searching now …* (status indicator)
>
> **AI:** "Hi there! I'm Buddy. It's great that you're looking to upgrade your home toolkit. For a home handyman, a cordless drill driver is often the best place to start. These are versatile tools that can handle everything from assembling flat-pack furniture to drilling holes in timber and plaster. If you are planning to drill into tougher materials like brick or concrete, you might want to consider a hammer drill, which adds a hammering action to help get through masonry. I've found a few excellent options for you, ranging from basic starter kits to more advanced brushless models."
>
> **🔧 Cordless Drill Driver Kits**
> *These kits include a battery and charger, which is perfect if you're just starting your collection.*
>
> [Card: Ryobi 18V Drill — $99.98 — Add to cart]
> [Card: Ryobi 18V Home Project Kit — $169.00 — Add to cart]
> [Card: Ryobi ONE+ Compact — $229.00 — Add to cart]
>
> **🔧 Multi-Tool Combo Kits**
> *If you have a few different projects on the go, a combo kit can be a great value way to get multiple tools at once.*
>
> [Card: DeWALT 2-Piece 18V Brushless — $299.00 — Add to cart]
> [Card: Ryobi 18V ONE+ 4-Piece Kit — $319.00 — Add to cart]
> [Card: Bosch 18V Professional 2-Piece — $399.00 — Add to cart]
>
> **🔧 Compact and Brushless Options**
> *Brushless motors are generally more efficient and last longer, and compact designs are handy for getting into tight spots.*
>
> [Card: Ryobi 18V ONE+ HP Brushless — $139.00 — Add to cart]
> [Card: Ryobi 18V ONE+ HP Brushless — $159.00 — Add to cart]
> [Card: Ryobi 18V ONE+ HP Brushless Compact — $229.00 — View product]  *(variable)*

**Mechanism:** The LLM ran THREE targeted search tool calls — one per section — and authored the heading + description for each.

### Customer Chat — narrow filter produces single tighter section

> **Visitor:** "Cordless hammer drill under $200, what have you got?"
>
> **AI:** *Searching now …*
>
> **AI:** "I've found some great cordless hammer drills for you that are under $200. Since you mentioned you're a home handyman, I've focused on full kits which include the battery and charger, as well as some higher-performance tool-only options if you already have a battery from a particular brand."
>
> **🔧 Hammer Drill Kits (Includes Battery & Charger)**
> *These are perfect if you want to get straight to work as they come with everything you need.*
>
> [Card: Ryobi Hammer Drill Kit — $149 — Add to cart]
> [Card: Ryobi ONE+ HP Hammer Drill — $199 — Add to cart]
> [Card: Ozito PXC Hammer Drill Kit — $99.98 — Add to cart]
> [Card: Ozito PXC Brushless Hammer Drill — $149 — Add to cart]
>
> **⚙ High-Performance Tool Only Options**
> *If you already have a battery system at home, these "skin only" units offer more power or brushless technology within your budget.*
>
> [Card: Ryobi 18V Brushless Tool Only — $159 — Add to cart]
> [Card: Ryobi ONE+ HP Brushless Tool Only — $149 — Add to cart]
> [Card: AEG 18V Brushless Tool Only — $199 — Add to cart]
> [Card: Ryobi 18V Compact Brushless Tool Only — $99 — Add to cart]

**Mechanism:** Two targeted searches (kits with battery vs. tool-only). The LLM picked the split based on query intent.

### Staff AI

> **Staff member:** "What products do we have in the lawn care category?"
>
> **AI:** *Searching now …*
>
> **AI:** "Here are the top products in your lawn care category, grouped by sub-type:"
>
> **🌿 Robotic Mowers**
> [Card: Victa RM100 — $999 — Edit product]
> [Card: Eufy E15 — $2,999 — Edit product]
>
> **🌿 Battery Push Mowers**
> [Card: Ryobi ONE+ — $399 — Edit product]
> [Card: Makita 36V — $549 — Edit product]

(All actions are `Edit product`. If the staff user lacks `edit_products` capability, clicking the link lands on WP's "You do not have permission to perform this action" screen — handled by WP core, not our code.)

## 4. Architecture

Two-PR coordination, lockstep release. Mirrors the prior cart-saga pattern (DEF #193 + def-core v2.2.x).

```
┌──────────────────────────────────────────────────────────────────────────┐
│  DEF (Python) — paired PR (lands first)                                 │
├──────────────────────────────────────────────────────────────────────────┤
│  app/tools/woocommerce/search_products.py:                              │
│  - Tool input gains `section_heading` + `section_description` fields    │
│    that the LLM provides per call.                                      │
│  - Tool output: `product_cards` (3-4 capped) + `section_heading` +      │
│    `section_description` echoed back + `search_query`. ONE section per  │
│    call.                                                                │
│  - Sales Assistant prompt updated to teach multi-search orchestration   │
│    pattern: "for broad queries, plan multiple targeted searches; for    │
│    narrow queries, one search is enough; author heading + description   │
│    per call."                                                           │
│  ~70 LOC.                                                               │
└──────────────────────────────────────────────────────────────────────────┘
                              │
                              ▼  (DEF release deployed first)
┌──────────────────────────────────────────────────────────────────────────┐
│  def-core (WordPress) — PR #157 (lands second)                          │
├──────────────────────────────────────────────────────────────────────────┤
│  assets/js/def-core-product-cards.js (NEW — shared module):             │
│  - window.DefProductCards.renderSection(payload, options)               │
│  - Renders: heading + description + responsive card grid                │
│                                                                          │
│  assets/js/def-core-customer-chat.js:                                   │
│  - SSE handler: on each tool_done event with product_cards payload,     │
│    call DefProductCards.renderSection(payload, { channel: 'customer_   │
│    chat' }) and append to message stream.                               │
│  - Multi-section responses: each tool call produces one section; they   │
│    appear sequentially as each search completes (streaming UX).         │
│  - Add-to-cart wiring via existing wp_rest_call pattern.                │
│                                                                          │
│  Staff AI bundle (location TBD during build):                           │
│  - Same SSE handler hook with channel='staff_ai'.                       │
│  - Action button is always Edit product → links to                      │
│    /wp-admin/post.php?post={id}&action=edit                             │
│                                                                          │
│  assets/css/def-core-product-cards.css (NEW shared styles):             │
│  - Container query — 2 cols <720px, 4 cols ≥720px                       │
│  - Section heading + description above grid                             │
│  - Card layout (image, price, title, action)                            │
│                                                                          │
│  ~200 JS + ~120 CSS LOC.                                                │
└──────────────────────────────────────────────────────────────────────────┘
```

## 5. Section Data Contract

The DEF Python tool emits the following shape per tool call. **One section per call.** The LLM authors the `section_heading` and `section_description`; the tool echoes them back alongside the product cards.

```json
{
  "tool_outputs": {
    "section_heading": "Cordless Drill Driver Kits",
    "section_description": "These kits include a battery and charger, which is perfect if you're just starting your collection.",
    "product_cards": [
      {
        "id": 12345,
        "title": "Ryobi 18V ONE+ Drill Driver",
        "price_html": "<span class=\"woocommerce-Price-amount\">$99.98</span>",
        "image_url": "https://example.com/wp-content/uploads/...ryobi-drill.jpg",
        "image_alt": "Ryobi 18V ONE+ Drill Driver",
        "product_url": "https://example.com/product/ryobi-drill/",
        "is_variable": false,
        "in_stock": true,
        "stock_status": "instock"
      },
      {
        "id": 12346,
        "title": "Ryobi 18V ONE+ Home Project Kit",
        "price_html": "<span>$169.00</span>",
        "image_url": "https://...ryobi-kit.jpg",
        "image_alt": "Ryobi 18V ONE+ Home Project Kit",
        "product_url": "https://example.com/product/ryobi-kit/",
        "is_variable": true,
        "in_stock": true,
        "stock_status": "instock"
      }
    ],
    "search_query": "cordless drill driver kit with battery"
  }
}
```

### Field semantics — section-level

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `section_heading` | string | yes | Plain text (no HTML), max 80 chars. LLM-authored ("Cordless Drill Driver Kits", "Hammer Drill Kits ≤ $200"). Rendered above the card grid. |
| `section_description` | string | optional | One-line context for the section, max 250 chars. LLM-authored. Rendered between heading and grid. Empty string is valid (heading-only sections). |
| `product_cards` | array | yes | 1-4 cards. Empty array = no results, renderer suppresses the section entirely. |
| `search_query` | string | optional | The actual query the tool ran. Useful for logging / debugging. Not rendered. |

### Field semantics — per-card

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | integer | yes | WC product ID; used for add-to-cart payload (Customer Chat) and Edit URL (Staff AI). |
| `title` | string | yes | Plain text (no HTML); from `get_the_title($id)`. |
| `price_html` | string | yes | WooCommerce-rendered product price HTML from `$product->get_price_html()`. May include sale markup such as `<del>` and `<ins>`, currency markup, tax suffixes, and plugin/theme-filtered price HTML. Frontend sanitizer must allow the limited safe tag/attribute set required for WooCommerce price display. |
| `image_url` | string | yes | Full URL to the product's featured/Product Image using WooCommerce's `woocommerce_thumbnail` size. Source via `$product->get_image_id()`. If the product has no featured image, use WooCommerce's placeholder image URL via `wc_placeholder_img_src( 'woocommerce_thumbnail' )`. Always a valid URL — never empty. |
| `image_alt` | string | yes | Alt text from the product image attachment. If no image/alt is set, default to the product title or a safe fallback such as `Product image`. |
| `product_url` | string | yes | Full URL to single-product page. |
| `is_variable` | bool | yes | True for `variable` product type. Drives `Add to cart` vs `View product` action selection on Customer Chat. (Staff AI ignores this — always shows Edit product.) |
| `in_stock` | bool | yes | True if `$product->is_in_stock()`. Out-of-stock simple products fall back to `View product` on Customer Chat (cannot add to cart). |
| `stock_status` | string | optional | Raw WC stock status (`instock`, `outofstock`, `onbackorder`). Reserved for future use. |

### Cap

- DEF caps at **4** products per `product_cards` array per call.
- No global cap across a response — the LLM may call the search tool N times to build N sections, with each section having up to 4 cards.

## 6. DEF (Python) Changes

### 6.1 Search tool extension

File: `app/tools/woocommerce/search_products.py` (path TBC during build).

**New signature:**

```python
def search_products(
    query: str,
    section_heading: str,
    section_description: str = "",
    limit: int = 4,
    # ...existing optional filter params (price, category, etc.)...
) -> dict:
    """Search WC products and return one thematic section.

    The LLM provides the section_heading + section_description as part of
    its multi-search orchestration. The tool runs the search and echoes
    these back alongside the resulting product cards.
    """
    raw_results = wc_search(query, limit=limit, ...)

    cards = []
    for product in raw_results[:limit]:
        cards.append({
            "id": product.id,
            "title": product.title,
            "price_html": product.price_html,  # $product->get_price_html()
            "image_url": product.thumbnail_url(),  # 'woocommerce_thumbnail' size, with placeholder fallback
            "image_alt": product.image_alt or product.title,
            "product_url": product.permalink,
            "is_variable": product.type == "variable",
            "in_stock": product.is_in_stock,
            "stock_status": product.stock_status,
        })

    return {
        "tool_outputs": {
            "section_heading": section_heading,
            "section_description": section_description,
            "product_cards": cards,
            "search_query": query,
        }
    }
```

### 6.2 Sales Assistant prompt update

Add this guidance to the Sales Assistant's system prompt / tool description:

> **Multi-section orchestration.**
>
> When a customer asks a broad product question (e.g. "what drills do you have?"), plan multiple targeted searches that group results thematically — by category (drill kits / combo kits / brushless), by use-case (home / professional / heavy-duty), by brand (Ryobi / Makita / DeWALT), or by price tier. Author a clear `section_heading` and a one-sentence `section_description` for each search. Aim for 2-4 sections per broad query.
>
> When a customer asks a narrow filtered question (e.g. "hammer drills under $200"), one or two searches usually suffice — focus the sections on the natural sub-types (e.g. "Kits with Battery" vs. "Tool Only").
>
> Each search returns up to 4 cards. The cards render as a chat-native grid below your text reply. Don't repeat the product names in your text — let the cards speak.
>
> Don't show the same product in multiple sections (track product IDs across searches in this turn).

### 6.3 No new endpoint

The cards are emitted via the existing tool-call SSE stream. Same channel as `wp_rest_call`. No new REST route, no new permissions.

## 7. def-core Customer Chat Changes

### 7.1 SSE event handler

File: `assets/js/def-core-customer-chat.js`

When the SSE stream emits a `tool_done` event with `tool_outputs.product_cards` (and the new `section_heading` field), the frontend:

1. Waits for the LLM's text reply to fully stream (existing behaviour for the lead-in text).
2. Calls `DefProductCards.renderSection(payload, { channel: 'customer_chat' })`.
3. Section renders as a sibling element below the assistant message bubble. Multi-section responses produce multiple sibling sections, one per tool call.

### 7.2 Renderer (shared module)

New file: `assets/js/def-core-product-cards.js`. Exposed as `window.DefProductCards`. Loaded by both Customer Chat and Staff AI bundles.

```javascript
window.DefProductCards = {
    renderSection: function (payload, options) {
        // payload = { section_heading, section_description, product_cards, search_query }
        // options = { channel: 'customer_chat' | 'staff_ai' }

        if (!payload.product_cards || payload.product_cards.length === 0) {
            return null;  // no results — suppress the section entirely
        }

        var section = document.createElement('section');
        section.className = 'def-cc-product-section';

        // Heading
        var heading = document.createElement('h3');
        heading.className = 'def-cc-product-section-heading';
        heading.textContent = payload.section_heading;
        section.appendChild(heading);

        // Description (optional)
        if (payload.section_description && payload.section_description.trim()) {
            var desc = document.createElement('p');
            desc.className = 'def-cc-product-section-description';
            desc.textContent = payload.section_description;
            section.appendChild(desc);
        }

        // Card grid
        var grid = document.createElement('div');
        grid.className = 'def-cc-product-cards';
        grid.setAttribute('role', 'list');
        grid.setAttribute('aria-label', payload.section_heading);

        payload.product_cards.forEach(function (card) {
            grid.appendChild(this.renderCard(card, options));
        }, this);

        section.appendChild(grid);
        return section;
    },

    renderCard: function (card, options) {
        var article = document.createElement('article');
        article.className = 'def-cc-product-card';
        article.setAttribute('role', 'listitem');

        // Image — wrapped in <a> linking to product
        var imgLink = document.createElement('a');
        imgLink.href = card.product_url;
        imgLink.className = 'def-cc-product-card-image-link';
        imgLink.setAttribute('aria-label', 'View ' + card.title);

        var img = document.createElement('img');
        img.src = card.image_url;  // woocommerce_thumbnail size, WC placeholder fallback already applied server-side
        img.alt = card.image_alt;
        img.loading = 'lazy';
        imgLink.appendChild(img);
        article.appendChild(imgLink);

        // Price (sanitised HTML — sale markup preserved)
        var price = document.createElement('div');
        price.className = 'def-cc-product-card-price';
        price.innerHTML = DOMPurify.sanitize(card.price_html, PRICE_SANITIZE_CONFIG);
        article.appendChild(price);

        // Title — separate <a>, NOT wrapping the whole card
        var titleLink = document.createElement('a');
        titleLink.href = card.product_url;
        titleLink.className = 'def-cc-product-card-title';
        titleLink.textContent = card.title;
        article.appendChild(titleLink);

        // Action button — channel-specific (NOT wrapped in card-level link)
        article.appendChild(this.renderAction(card, options));

        return article;
    },

    renderAction: function (card, options) {
        if (options.channel === 'staff_ai') {
            return this.renderEditProductLink(card);
        }
        // customer_chat
        if (card.is_variable || !card.in_stock) {
            return this.renderViewProductLink(card);
        }
        return this.renderAddToCartButton(card);
    },

    // ... renderAddToCartButton, renderViewProductLink, renderEditProductLink ...
};
```

### 7.3 PRICE_SANITIZE_CONFIG

```javascript
var PRICE_SANITIZE_CONFIG = {
    ALLOWED_TAGS: ['span', 'sub', 'sup', 'small', 'bdi', 'del', 'ins', 'strong', 'em'],
    ALLOWED_ATTR: ['class'],  // WC adds classes like 'woocommerce-Price-amount'
};
```

`<del>` and `<ins>` are allowed for WC's sale-price markup (`<del>regular</del> <ins>sale</ins>`).

### 7.4 Add-to-cart flow

Same wp_rest_call UI action pattern as the cart saga. POST to `/wc/store/v1/cart/add-item` with `{ id: card.id, quantity: 1 }`. Button states:

- Idle: `+ Add to cart`
- In flight: `Adding...` (disabled)
- Success: `✓ Added` for 2s → reverts to `+ Add to cart`
- Failure: `Try again` (re-enabled)

WC Store API uses its own `Nonce` header (not WP REST nonce). def-core localizes `wcStoreNonce` via `wp_localize_script` for Customer Chat (NOT for Staff AI — staff aren't shopping).

## 8. def-core Staff AI Changes

### 8.1 Action: always Edit product

```javascript
DefProductCards.renderEditProductLink = function (card) {
    var link = document.createElement('a');
    link.href = '/wp-admin/post.php?post=' + card.id + '&action=edit';
    link.className = 'def-cc-product-card-edit';
    link.textContent = 'Edit product';
    link.setAttribute('aria-label', 'Edit ' + card.title);
    return link;
};
```

**Permission handling: WP core does it.** If the staff user lacks `edit_products` capability, clicking the link lands on WP's *"You do not have permission to perform this action"* screen. We don't gate this client-side — the user gets the right path forward (contact admin for Shop Manager / Admin role upgrade).

### 8.2 No add-to-cart plumbing on Staff AI

Staff AI doesn't need WC Store API access. No `wcStoreNonce` localization, no `add-to-cart` POST. Renderer's action selector returns `Edit product` for all cards in this channel.

### 8.3 Shared CSS

Both channels load the same `def-core-product-cards.css`. The card markup is identical; only the action button differs. CSS scopes via `.def-cc-product-card-add` (Add to cart, primary), `.def-cc-product-card-view` (View product, secondary), `.def-cc-product-card-edit` (Edit product, admin-styled).

## 9. UX Specification

### 9.1 Section visual structure

```
┌────────────────────────────────────────────────────────┐
│  Section heading (h3, bold, 16px)                      │
│  Section description (p, regular, 14px, muted)         │
│                                                         │
│  ┌──────────┬──────────┬──────────┬──────────┐        │
│  │  Card 1  │  Card 2  │  Card 3  │  Card 4  │ ← desktop│
│  └──────────┴──────────┴──────────┴──────────┘        │
└────────────────────────────────────────────────────────┘
```

### 9.2 Container layout — responsive grid

**Container query** (because chat panel size, not viewport size, is the relevant axis):

```css
.def-cc-product-cards {
    container-type: inline-size;
    display: grid;
    grid-template-columns: repeat(2, 1fr);  /* default mobile */
    gap: 8px;
}

@container (min-width: 720px) {
    .def-cc-product-cards { grid-template-columns: repeat(4, 1fr); }
}
```

| Panel width | Columns | Up to 4 cards layout |
| --- | --- | --- |
| **≥720px** (Spotlight, mobile landscape, wide Drawer) | 4 | 1 row × 4 |
| **<720px** (Modal, default Drawer, mobile portrait) | 2 | 2 rows × 2 |

Cards always render at least 2-up — never single-column vertical stack.

### 9.3 Card visual design (per Bunnings reference)

```
┌──────────────────────┐
│                      │
│      [IMAGE]         │  ~150-180px tall, 'woocommerce_thumbnail' size, object-fit: cover
│                      │
├──────────────────────┤
│  $999.00             │  bold, 15-16px (price_html — may include <del>/<ins>)
│  Victa RM100 Robot   │  2-line ellipsis, 14px
│  Mower               │
├──────────────────────┤
│  [+ Add to cart]     │  full-width button, 36-44px tall (touch target)
└──────────────────────┘
```

### 9.4 Card HTML structure

Per Steve's review #7 — image and title link separately, action button NOT wrapped in card-level `<a>`:

```html
<article class="def-cc-product-card" role="listitem">
    <a href="{product_url}" class="def-cc-product-card-image-link" aria-label="View {title}">
        <img src="{image_url}" alt="{image_alt}" loading="lazy">
    </a>

    <div class="def-cc-product-card-price">
        {price_html}  <!-- DOMPurify-sanitised; sale markup preserved -->
    </div>

    <a href="{product_url}" class="def-cc-product-card-title">
        {title}
    </a>

    <!-- Action — NOT wrapped in card-level <a>; nested interactive elements break a11y -->
    <button type="button" class="def-cc-product-card-add">+ Add to cart</button>
    <!-- OR -->
    <a href="{product_url}" class="def-cc-product-card-view">View product →</a>
    <!-- OR (Staff AI) -->
    <a href="/wp-admin/post.php?post={id}&action=edit" class="def-cc-product-card-edit">Edit product</a>
</article>
```

### 9.5 Action button matrix

| Channel | Product type | Stock | Action | Style |
| --- | --- | --- | --- | --- |
| Customer Chat | Simple | In stock | Add to cart | Primary, full-width |
| Customer Chat | Simple | Out of stock | View product | Secondary outline, full-width |
| Customer Chat | Variable | (any) | View product | Secondary outline, full-width |
| Staff AI | (any) | (any) | Edit product | Admin/secondary, full-width |

### 9.6 Hover / interaction states

- Card hover: `transform: scale(1.02)` + subtle `box-shadow` lift
- Active / focus: `outline: 2px solid var(--def-cc-primary)` + offset
- Add-to-cart loading: button disabled, text → "Adding..."
- Add-to-cart success: text → "✓ Added" for 2s, then revert
- Add-to-cart failure: text → "Try again", button re-enabled

### 9.7 Click behaviour

Match standard WooCommerce catalog behaviour:

- Product image → product page (same tab)
- Product title → product page (same tab)
- Add to cart → POST to WC Store API (no navigation)
- View product → product page (same tab)
- Edit product (Staff AI) → wp-admin product edit page (same tab)

The image and title each have their own anchor; the action is a sibling, not nested. Avoids the accessibility / click-handling problems of nested interactive elements.

## 10. Status Indicator

Tool-call status text changes from *"Getting product details…"* to **"Searching now …"** during the search tool call. Reflects the user-initiated nature of the action.

## 11. Search Count UX

V1.0 had a "View all 17 results" link concept. **Dropped in V1.1.**

The sectioned, LLM-curated response model replaces the need for a count link. Visitors see structured options grouped by usefulness — the curation IS the value. Bunnings doesn't show counts or "view all" links, and their pattern has succeeded without them.

If a future use case demands escape-to-category-page (e.g. catalog browse on a small-result query), V2 can add a per-section *"View all in {category} →"* link. Not in V1.1 scope.

## 11.5 LLM Orchestration Pattern

This is the load-bearing architectural choice. Worth a dedicated section.

### Why LLM-orchestrated, not tool-curated

The naive design is: tool returns raw search results, tool groups them into sections (by Python rules — category, brand, price tier), tool returns multi-section payload, renderer iterates sections.

We're not doing that. Instead: **the LLM decides how to split a broad query into thematic sub-searches**, calls the search tool once per section, and authors the heading + description for each call. The tool stays simple (one section per call); the orchestration logic lives in the LLM.

**Reasons:**

1. **Mirrors how DEF already operates.** The DEF orchestrator (Customer Chat Concierge → Sales Assistant; Staff AI Concierge → Knowledge Assistant) already has the LLM as the orchestrator across multi-tool flows. This is a familiar pattern, not a new one.

2. **Mirrors how Bunnings actually does it.** Reverse-engineering their multi-section responses shows distinct search queries per section ("cordless drill driver kit", "multi-tool combo kit", "brushless compact"). They're orchestrating LLM-side, not curating tool-side.

3. **Adapts to query intent.** "Drills under $200 for a beginner" produces different section angles than "drills for masonry work" — the LLM picks per query. Tool-side rules can't do that without nested branching logic.

4. **Tool stays simple.** No grouping module, no `multi_section` data shape in the contract. Search by query, return up to 4 cards, echo back the heading/description the LLM authored.

5. **Streaming UX.** Sections appear one-by-one as each tool call completes, which feels more like *"the AI is researching for you"* than waiting 5s for one big payload.

### How the LLM orchestrates

The Sales Assistant's system prompt teaches the pattern. For broad queries:

1. Identify 2-4 thematic angles in the user's question
2. For each angle, plan a targeted search (category + filter combination)
3. Author a clear, customer-friendly `section_heading` (3-6 words)
4. Author a one-sentence `section_description` explaining what's in this section
5. Call `search_products(query, section_heading, section_description)` for each angle
6. Track product IDs returned across calls — don't repeat the same product in multiple sections

For narrow / heavily-filtered queries: one or two searches usually suffice.

### What can go wrong (and what we'll watch for)

The LLM might:

- **Pick inconsistent sections** across runs of the same query
- **Generate awkward section headings** ("Tools That Are Cordless")
- **Repeat products across sections** (same `id` in section A and section B)
- **Not call the tool enough times** for a broad query (lazy LLM, single section response)
- **Call the tool too many times** (5+ sections, response feels long)

These are observability concerns. V2 watch list (§22) covers the mitigations if any of them materialize.

## 12. Conditional Rendering (WC Gate)

The feature is structurally absent on plain WordPress sites because:

1. The product search tool only registers when `class_exists('WooCommerce')`.
2. If the tool isn't registered, the LLM can't call it.
3. If the LLM doesn't call it, no `tool_outputs.product_cards` is ever emitted.
4. The frontend renderer no-ops when `product_cards` is missing or empty.

**No new conditional logic needed in def-core.** The renderer is always loaded; it just has nothing to render on WP-only sites.

## 13. Accessibility

- Section: `<section class="def-cc-product-section">` with `<h3>` heading
- Section description: `<p>` muted text
- Card grid: `<div role="list" aria-label="{section_heading}">`
- Each card: `<article role="listitem">`
- Image link: `<a aria-label="View {title}">` wrapping `<img alt="{image_alt}">`
- Title: separate `<a>` with text content
- Action button: `<button>` or `<a>` with explicit text (no icon-only buttons)
- Focus order: image link → title link → action → next card
- Add-to-cart loading state: announced via `aria-live="polite"` on a hidden status region
- Out-of-stock cards: visual indication (greyed image, optional badge) AND screen-reader-only "Out of stock" text

## 14. Mobile / Responsive

- Container query (NOT viewport query) — chat panel size is the relevant axis
- 2-up at <720px (Modal, default Drawer, mobile portrait)
- 4-up at ≥720px (Spotlight, mobile landscape, wide Drawer)
- Touch targets: minimum 44×44 px per WCAG (action button bumps to 44px on mobile)
- No hover states on touch (`@media (hover: hover)` guard)

## 15. Security

### 15.1 XSS surface

Card data flows from DEF (Python tool) → SSE stream → `tool_outputs.product_cards` → frontend renderer. Five fields render in the DOM:

1. **`section_heading`** — `textContent`. Safe.
2. **`section_description`** — `textContent`. Safe.
3. **`title`** — `textContent`. Safe.
4. **`price_html`** — `innerHTML` after `DOMPurify.sanitize(html, PRICE_SANITIZE_CONFIG)`. Allowlist: `<span>`, `<sub>`, `<sup>`, `<bdi>`, `<small>`, `<del>`, `<ins>`, `<strong>`, `<em>` with `class` attribute. Strips everything else.
5. **`image_url`** + **`product_url`** — set via property assignment (`img.src`, `link.href`). Apply `safeLinkHref()` (from PR #153) to ensure http/https schemes only.

### 15.2 CSRF on add-to-cart

WC Store API uses its own `Nonce` header. def-core localizes the nonce. Fetch sends `credentials: 'include'` for cookie auth. Anonymous visitors don't need the nonce per WC design.

### 15.3 No new write paths

Cards are display-only. No admin-configurable card content. The add-to-cart action uses the existing WC Store API endpoint. The Edit product action uses the existing wp-admin URL pattern. No new options, no new sanitisers, no new endpoints.

## 16. Test Plan

### Customer Chat (WC active)

- [ ] Broad query ("what drills do you have?") → multiple sections render, each with heading + description + 3-4 cards
- [ ] Narrow filter ("drills under $200") → 1-2 focused sections
- [ ] Search query for simple in-stock product → card renders with `Add to cart`
- [ ] Click Add to cart → cart count updates, button shows "✓ Added" → reverts after 2s
- [ ] Search query returns variable product → card renders with `View product`
- [ ] Click View product → navigates to product page (same tab)
- [ ] Search query returns out-of-stock simple product → card renders with `View product`
- [ ] Section with empty cards array → section is suppressed (no empty heading rendered)
- [ ] Mobile portrait viewport (<720px) → cards stack 2-up
- [ ] Mobile landscape / Spotlight (≥720px) → cards render 4-up
- [ ] Anonymous + logged-in visitor: add-to-cart works for both
- [ ] Sale-priced product → `<del>` regular price + `<ins>` sale price render correctly through DOMPurify
- [ ] Product with no featured image → renders WC placeholder image

### Staff AI (WC active)

- [ ] Same broad query → cards render with `Edit product` only
- [ ] Click Edit product (user has `edit_products` cap) → navigates to wp-admin product edit page
- [ ] Click Edit product (user lacks `edit_products` cap) → WP shows "You do not have permission" — confirm graceful, no JS errors
- [ ] All 4 product types (simple in-stock, simple out-of-stock, variable, virtual) → all render `Edit product`

### LLM orchestration scenarios

- [ ] Broad query → LLM runs 2-4 search calls; sections appear sequentially as each completes
- [ ] LLM authors meaningful headings/descriptions per section (not "Section 1" / "Section 2")
- [ ] No product ID appears in two sections within the same response
- [ ] Narrow query → LLM runs 1-2 calls only (doesn't over-curate)
- [ ] Same query run twice in same conversation → either gets cached LLM response or re-runs cleanly

### WP-only site (no WC)

- [ ] Sales Assistant tool not registered → no card payload emitted → no cards render
- [ ] LLM text-only reply works as before (no regression)

### Accessibility

- [ ] Keyboard navigation: tab through sections → image link → title link → action → next card
- [ ] Screen reader: announces section heading, then "list, N items", then each card title and price
- [ ] Focus indicators visible on all interactive elements
- [ ] aria-live announcement when add-to-cart completes

### Security

- [ ] Inject `<script>` into a product title → renders as text (no execution)
- [ ] Manipulate `price_html` to include `<img onerror>` → sanitised away by DOMPurify
- [ ] Inject `javascript:` as `product_url` → blocked by `safeLinkHref()`
- [ ] Inject HTML into `section_heading` → renders as text via `textContent`

## 17. Files Changed (estimate)

### DEF (Python) — paired PR

| File | Change | LOC |
| --- | --- | --- |
| `app/tools/woocommerce/search_products.py` | Tool signature: add `section_heading` + `section_description` params; return shape includes both echoed back | ~30 |
| Sales Assistant prompt | Multi-section orchestration guidance | ~30 |
| Tests | Cover section payload shape, image fallback, sale price markup | ~80 |

**~140 Python LOC.**

### def-core — PR #157 (or whatever number, post-#156 CI bump)

| File | Change | LOC |
| --- | --- | --- |
| `assets/js/def-core-product-cards.js` | NEW shared module (`window.DefProductCards.renderSection / renderCard / renderAction`) | ~180 |
| `assets/js/def-core-customer-chat.js` | SSE handler hooks per-tool-call; calls `renderSection` with channel='customer_chat'; add-to-cart wiring | ~50 |
| `assets/js/def-core-staff-ai.js` (or equivalent) | Same hook with channel='staff_ai' | ~30 |
| `assets/css/def-core-product-cards.css` | NEW shared card styles (container query, section heading, card layout) | ~140 |
| `includes/class-def-core.php` | Localize `wcStoreNonce` for Customer Chat | ~5 |
| `includes/class-def-core-staff-ai.php` | Enqueue product-cards JS + CSS in Staff AI context | ~10 |
| `def-core.php` + `readme.txt` + `changelog.txt` + `README.md` | Version bump 2.9.0 → 3.0.0 | ~10 |

**~425 def-core LOC.**

**Total estimate: ~565 LOC across both repos.**

## 18. Open Questions

1. **`section_heading` length cap.** Spec says max 80 chars. Confirm this is generous enough for typical Bunnings-style headings ("Hammer Drill Kits (Includes Battery & Charger)" = 44 chars; "High-Performance Tool Only Options" = 34 chars). 80 should be plenty. Tool-side validation enforces.

2. **`section_description` length cap.** Spec says max 250 chars. Bunnings examples are typically 80-150 chars. 250 is a safe ceiling.

3. **WC Store API nonce on Customer Chat.** Anonymous path: WC Store endpoints work without nonce. Logged-in path: `Nonce` header required. Test both during build to confirm `wcStoreNonce` localization works correctly.

4. **Multiple search calls in one assistant turn — round-trip latency.** With LLM orchestration, a broad query produces N sequential tool calls, each adding ~500ms-1s of latency. Three sections = 1.5-3s of cumulative tool time before the final reply. Worth measuring during build; if it's slow, V2 watchlist (§22) has the `multi_search` meta-tool fix.

5. **Cart matcher edge case.** When the LLM picks one of the displayed products via natural language ("add the second drill"), does it go through the matcher? It SHOULDN'T — the LLM has the IDs from `product_cards` in its context. Verify the prompt structures the IDs as picks during build.

6. **Performance on large catalogs.** WC search with 100k products — the tool likely has a default limit; confirm during build. With limit=4 per section there's no pagination concern, but the underlying search may need an index hint for performance.

7. **Multiple searches in one conversation thread.** Each search produces its own section in the message stream — correct per Bunnings example. No global cap. Visitor scrolls through history naturally.

## 19. Out of Scope (explicit non-goals for V1.1)

- Variation picker (drawer/dropdown for variable products) — V2.0
- Sale price formatting beyond what `price_html` produces — render WC's output as-is
- Stock urgency badges ("Only 2 left!") — V2 polish
- Compare / wishlist actions — different feature
- Product cards on Setup Assistant channel — no commerce intent there
- Knowledge-base result cards — different feature, same renderer pattern (future V1.3)
- Search-as-you-type or autocomplete inside the chat input
- Per-section "View all in category →" link — V2 if needed

## 20. Future Iterations

- **V1.2** — Variation picker drawer for variable products. Tap "View options" on the card → inline drawer slides up with size/colour selectors → "Add to cart" enabled once variation chosen.
- **V1.3** — KB result cards (same renderer, different action set: "View article", "Open in new tab").
- **V2.0** — Per-section "View all in category" link if catalog-browse use case demands it; cross-section dedup cache; multi_search meta-tool for latency.
- **V2.1** — Cart icon refresh hook (the `def-cc-cart-updated` CustomEvent) wired to a tenant-side cart counter widget.
- **V3.0** — Real-time cart sync (cards reflect "In cart: 2" badge if visitor has already added).

## 21. 3-AI Review Checklist

For ChatGPT + Grok review of this V1.1 spec:

- [ ] Is the LLM-orchestration pattern (§11.5) the right call vs. tool-side curation? Are there failure modes we haven't anticipated?
- [ ] Does the section data contract (§5) cover all the fields needed? Anything missing?
- [ ] Is the responsive grid (§9.2) using container query the right tool? Browser support concerns?
- [ ] Card HTML structure (§9.4) — are there a11y issues with separate `<a>` for image and title?
- [ ] WC Store API for add-to-cart — better path than legacy admin-ajax cart_add?
- [ ] Edit product on Staff AI with WP handling permission — any edge cases where this leaves staff users confused?
- [ ] Are the open questions (§18) the right ones? What else should be locked down before build?
- [ ] Is the security review (§15) thorough enough? Especially the DOMPurify config for sale price markup?
- [ ] Is the test plan (§16) complete? Any LLM-orchestration scenarios missed?
- [ ] Is the LOC estimate (§17) realistic given the new shared module + section rendering?
- [ ] V2 watch list (§22) — are the right risks called out? Anything else worth watching?
- [ ] Major architectural concerns?

## 22. V2 Watch List

Features we'd add **only if** specific problems materialize after V1.1 ships. None are in V1.1 scope; documenting here so we don't forget what's deferred and why.

| If we observe… | We add (V2)… | LOC |
| --- | --- | --- |
| LLM picks inconsistent sections across runs (same query → different sections) | Deterministic Python grouping module the LLM can call as fallback (group by category, brand, price tier) | ~150 |
| N sequential round-trips feel slow (>3s cumulative for broad queries) | `multi_search` bundled meta-tool — takes `[query1, query2, query3]` and returns 3 sections in one trip | ~50 |
| LLM repeats products across sections within same response | Tool-side dedup cache OR LLM prompt instruction to track shown IDs | ~30-80 |
| Same query repeats often (caching opportunity) | Tool-side cache keyed on search query + grouping strategy | ~50 |
| Visitor wants to see all results, not just the curated 4 | Per-section "View all in {category} →" link | ~20 |

**Total V2 work IF all five problems materialize: ~280-350 LOC.**
**Total V2 work IF none materialize: 0 LOC.**

The honest assessment: these are speculative. We don't yet know which (if any) will surface. Ship V1.1, observe production behaviour with the one tenant (a3rev.com), prioritise V2 based on actual data.

---

**End of Spec V1.1 — pending 3-AI review for V1.2.**
