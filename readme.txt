=== Digital Employee Framework - Core ===
Contributors: a3rev
Tags: ai, chat, digital employee, ai assistant, customer support
Requires at least: 6.2
Tested up to: 6.9.4
Requires PHP: 8.0
Stable tag: 3.1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered Digital Employees for WordPress — customer chat, staff assistant, and intelligent setup, all connected to the Digital Employee Framework.

== Description ==

Digital Employees are AI agents that work alongside your team. They understand your business context, follow governance rules, and operate across multiple channels on your WordPress site.

= Three AI Channels =

* **Customer Chat** — AI chat widget for site visitors. Floating button or shortcode. Real-time streaming responses powered by your site's content and knowledge base.
* **Staff AI** — Internal AI assistant in wp-admin for your team. Product lookups, order queries, customer context, and knowledge base searches.
* **Setup Assistant** — Intelligent configuration agent that guides you through plugin setup conversationally. Knows the current state of every setting.

= Key Features =

* Real-time SSE streaming with word-by-word text rendering
* WooCommerce integration — product search, cart sync, order lookup (loads only when WooCommerce is active)
* JWT authentication bridge — secure token-based identity between WordPress and the DEF backend
* Knowledge export endpoints for AI knowledge base indexing
* Shadow DOM widget — zero CSS conflicts with your theme
* Light and dark mode with automatic theme color detection
* Configurable AI disclosure notice for visitor transparency
* Drag-and-drop file upload in Customer Chat
* User memory — Digital Employees remember context across conversations

= How It Works =

This plugin is the bridge between WordPress and the Digital Employee Framework (DEF) backend. All AI logic, tool execution, employee orchestration, and governance enforcement happen server-side. WordPress provides the UI surface and authentication context.

1. Sign up at [defho.ai](https://defho.ai/) and create a Tenant for your site
2. Install this plugin on your WordPress site
3. Connect — push config from DEFHO Tenant Portal or enter credentials manually
4. The Setup Assistant guides you through the rest

= WooCommerce =

When WooCommerce is active, additional tools load automatically — product search, cart synchronization, order lookup, and product catalog export for knowledge base indexing.

== Installation ==

= Minimum Requirements =

* WordPress 6.0 or greater
* PHP version 8.0 or greater
* A [DEFHO](https://defho.ai/) account (Digital Employee Framework platform)

= From GitHub =

1. Download the latest release from [GitHub Releases](https://github.com/a3rev-ai/def-core/releases)
2. In WordPress, go to **Plugins > Add New > Upload Plugin**
3. Upload the .zip file and click **Install Now**
4. Activate the plugin

The plugin checks GitHub for updates automatically — standard WordPress update notifications appear when a new version is available.

= Manual Installation =

1. Upload the `def-core` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress

= Configuration =

1. Go to **Digital Employees** in your wp-admin sidebar
2. Use the **Setup Assistant** tab to configure your site conversationally, or configure manually:
3. **Connection** — push from DEFHO Tenant Portal, or enter API URL and API Key manually
4. **Branding** — set display name, logo, and app icon
5. **Chat Settings** — configure button position, colors, display mode, and AI disclosure notice
6. **Escalation** — set email recipients for Customer Chat and Setup Assistant
7. **User Roles** — assign Staff AI and Management access per user

== Frequently Asked Questions ==

= What is a Digital Employee? =

A Digital Employee is an AI agent that operates within governance rules set by the platform. Unlike generic chatbots, Digital Employees have defined roles, tool access, and behavioral boundaries. They can search your knowledge base, look up products and orders, and escalate to humans when needed.

= Do I need a DEFHO account? =

Yes. This plugin is the WordPress connector — the AI intelligence runs on the Digital Employee Framework backend. Sign up at [defho.ai](https://defho.ai/) to create your account and connect your site.

= Is the Customer Chat visible to all visitors? =

By default, yes. You can configure the display mode (floating button or drawer), position, and appearance in Chat Settings. The AI disclosure notice (configurable in Chat Settings) informs visitors that the chat is AI-powered before they start a conversation.

= Can I control which staff members access Staff AI? =

Yes. The User Roles tab lets you assign DEF capabilities per user. You can grant Staff-level access (basic tools) or Management-level access (additional tools and permissions).

= Does this work with WooCommerce? =

Yes. When WooCommerce is active, additional tools load automatically — product search, cart synchronization, order lookup, and product catalog export for AI knowledge base indexing.

= Is this plugin secure? =

Yes. The plugin uses RSA-256 signed JWT tokens, all authority is enforced server-side by the framework, and no secrets are hard-coded. Bearer token authentication, origin validation, and WordPress nonce verification protect all endpoints.

= How do I customize the chat button? =

Go to **Digital Employees > Chat Settings**. You can set button position (left/right), colors (auto-detected from your theme or custom), icon style, and button label text.

= What data is sent to the AI service? =

Chat messages, user display name, and session context — only when a user actively sends a message. No data is transmitted when chat features are not in use. See the External Services section below.

== Screenshots ==

1. Customer Chat — AI chat widget on the frontend with real-time streaming
2. Staff AI — Internal assistant in wp-admin for team members
3. Setup Assistant — Conversational configuration in the admin settings drawer
4. Admin Settings — Branding, Chat Settings, Escalation, User Roles, and Connection tabs

== Changelog ==

= 3.1.8 - 2026-05-12 =
* Polish: tool-status pills now show meaningful labels for the three V2 orchestrator tools that previously fell through to "Processing...": `retrieve` → "Working on it...", `render_product_cards` → "Fetching products...", `spawn_sub_agent` → "Calling our {Sales|Support|Setup} Specialist..." (dynamic based on which specialist is being called).
* Polish: specialist handoff banner restyled in Customer Chat. New wording: "You are now talking with our Sales Specialist!" (and Support / Setup) — replaces the muted "SALES ASSISTANT IS HELPING". Pale-blue rounded pill with bolder font so the handoff is clearly signposted without feeling like a system alert.
* Internal: `def-persona.js` gains a `bannerTemplate` option for channel-specific handoff wording. Customer Chat overrides the default "{name} is helping" with the new framing; Staff AI keeps the default. `def-core-customer-chat.js` exposes `SPECIALIST_ROLE_NAMES` for the spawn-tool label.
* Polish: Staff AI tool labels add `retrieve` and `render_product_cards` so the V2 orchestrator tools render meaningful labels in the staff console too.

= 3.1.7 - 2026-05-12 =
* Polish: tool-status pill now persists the in-progress label after completion. Visitor sees "Searching... ✓ Done" / "Searching... ✗ Failed" instead of the in-progress text being replaced. Context across the spinner→checkmark transition.
* Polish: Customer Chat greeting reordered. "Hi! I'm your Joe AI Assistant." → "Hi! I'm Joe your AI Assistant." (and the logged-in variant). The brand name reads better before the "your" possessive.
* Polish: Staff AI greeting reframed. "Hi Steve! I'm your Joe Management Assistant." → "Hi Steve! I am your personal AI Management Assistant." (or "personal AI Assistant" for non-management roles). Staff AI is a personal assistant — the brand-name framing belongs in Customer Chat, not the staff console.
* Polish: Customer Chat now appends a clickable cart link as a separate assistant bubble after a successful add-to-cart. Visitor sees the green ✓ status, then "Here is a link to your cart: View cart" pointing at /cart/. The link is the obvious next step.
* Polish: chat message links (Customer Chat and Staff AI both) are now WP blue (`#2271b1`) with no underline; hover restores the underline. Makes links readable as links without the visual noise of underlining every URL.

= 3.1.6 - 2026-05-11 =
* Feature: new server-side `get_cart` tool for the DEF Customer Chat. Pairs with the DEF backend's matching get_cart implementation so the assistant can answer "what's in my cart?" / "what's my cart total?" in the same SSE turn — single user message, real cart contents narrated back — instead of the prior two-turn async flow. Implementation forwards the visitor's WC Store API Cart-Token through the BFF proxy chain (widget → def-core → DEF → Store API) so the cart lookup runs server-side from DEF with the visitor's real session, then renders the answer inline.
* Internal: `build_proxy_headers()` validates the inbound Cart-Token to JWT shape (`<base64url>.<base64url>.<base64url>`, ≤4096 chars) before renaming it to the DEF-namespaced `X-DEF-WC-Cart-Token` header. The namespace rename prevents an external caller hitting DEF directly from injecting the header; the shape gate prevents a hostile widget payload from smuggling CRLF / header splits / oversized junk through the proxy.
* Internal: new `wc_get_cart()` REST method + `/tools/wc/cart` route registration. Logged-in fallback for the rare case where a visitor's browser has no Cart-Token in localStorage yet but their `_woocommerce_persistent_cart_*` user meta has items. Auth gated by the existing HMAC `permission_check`. Defensively short-circuits on `current_user_id <= 0`.

= 3.1.5 - 2026-05-07 =
* Fix: Clear & Start Fresh now survives a page reload for logged-in WP users. Previously, after clicking Clear, the welcome state appeared correctly — but reloading the page or opening a new tab silently re-adopted the cleared thread because v3.1.2's cross-device adoption couldn't tell "first time on this browser" apart from "user just cleared." A `def:cleared_session` localStorage marker now signals an explicit fresh-start intent so it survives the reload. The marker is per-browser, matching Clear's existing local-only semantics — a clear on Device A does not affect Device B's continuity — and is bypassed automatically when the user starts a new conversation.

= 3.1.4 - 2026-05-07 =
* Fix: Customer Chat streamed reply no longer disappears mid-render. After the assistant's text streamed in correctly, the bubble would suddenly empty out leaving only a ✓ Done tick (Sorin C2 — "Show my last order just in case"). Cause was the same wrong-priority bug Staff AI hit in v2.9.2: the done handler picked the final `content` (often a near-empty Concierge wrap-up post DEF #213/#214 wrap-up suppression) over `streamBuffer` — the buffer that captured what the user saw render. The fix inverts the priority. The streamed reply also now survives an SSE error event mid-stream — error appears as a separate bubble below the streamed text instead of clobbering it.
* Internal: persistence path also reads the corrected text. The done handler mutates `evt.choices[0].message.content` to the resolved final content so `processChatResponseMeta` → `upsertThread` writes what the user actually saw to localStorage. Without this, page-reload rehydration would show only the Concierge wrap-up, not the specialist reply.

= 3.1.3 - 2026-05-06 =
* Fix: Customer Chat tool-status tick reflects the real outcome of browser-executed cart actions instead of just the server-side dispatch. Previously the widget painted ✓ "Added to cart" the moment DEF emitted the dispatch event — even when the underlying WooCommerce call later returned a 4xx (e.g. validation refusal on a duplicate add of a subscription product), producing a contradictory UI: green tick next to red error toast. The widget now defers finalising the tick when DEF marks the dispatch event as `pending_async` and writes ✓ green / ✗ red based on the actual `wc/store/v1/cart/add-item` HTTP response. Surfaced by Sorin's break-test (Doc 5 Phase 3).
* Internal: tick correlation by `tool_call_id` (precise) with a tool-name FIFO fallback that covers the V1.2 spawn case. Falls back further to immediate finalise (current behaviour) when older DEF doesn't emit `pending_async` at all — no regression. Pairs with DEF backend changes that emit `tool_call_id` + `pending_async` SSE fields.
* Internal: safety-net flush in `processChatResponseMeta` finalises any deferred tick beyond the dispatched count of `wp_rest_call` actions. Should be a no-op in normal flow — guards against future drift between `pending_async` emission and `wp_rest_call` output emission inside DEF.

= 3.1.2 - 2026-05-06 =
* Fix: cross-device thread continuity for logged-in WordPress users. When a logged-in visitor opened the chat widget on a second browser, prior conversations were correctly fetched but the widget rendered a blank "new conversation" instead of continuing where they left off. The widget now adopts the most-recent prior thread that has messages, persists `threadId`, and re-emits its assistant messages — including replay-safe `wp_product` cards via the v3.1.1 persist path. Completes v3.1.1's promise: the v3.1.1 changelog claimed cards survive "a fresh-browser cross-device hydration" — without this fix the cross-device half didn't actually fire because the widget never adopted the server thread.
* Internal: `!threadId` guard makes adoption a no-op if the user already sent a message before the server response arrived (so a fresh local thread isn't overwritten by a stale server one). The "thread with messages" filter avoids stranding the user on an empty thread when a more recent meaningful conversation exists. Pairs with no DEF backend change — the backend was already returning the right data on `/api/my/threads?include_messages=1`.

= 3.1.1 - 2026-05-04 =
* Fix: chat-native product cards now persist across in-page navigation. When a Customer Chat conversation contained product cards and the visitor navigated to another page on the site, the assistant text re-rendered from history but the cards were silently dropped — only plain text remained. Cards are now snapshot-persisted on the assistant message in localStorage and re-rendered on rehydration. The action button still links to the live product, so a stale snapshot price never drives a bad add-to-cart click — the cart endpoint is the source of truth.
* Fix: DEF list-column status indicator. Post / page / product list tables now show a small green or red dot to indicate inclusion / exclusion from Digital Employee knowledge ingestion, instead of the literal text "Excluded" / em-dash. Tooltip + screen-reader label preserve discoverability.
* Internal: only `wp_product` result-card outputs are persisted; `escalation_offer` and `wp_rest_call` outputs are intentionally dropped on persist (replaying them would re-prompt the visitor or could re-trigger a side-effect). Pairs with DEF backend changes that persist the same filtered `tool_outputs` into `chat_messages.metadata_json` and surface them on the thread-fetch endpoints — cards now survive in-page navigation AND fresh-browser cross-device hydration.

= 3.1.0 - 2026-05-01 =
* Feature: per-item exclusion from Digital Employee knowledge. Mark any post / page / product / CPT to be skipped during knowledge ingestion via a checkbox in the Gutenberg sidebar, classic editor meta box, Quick Edit, or bulk action. Items already indexed are removed on the next Sync Now — Full from the Tenant Portal. Pairs with DEF backend changes that read the new `_def_exclude_from_ingestion` post meta.

= 3.0.1 - 2026-05-01 =
* Fix: chat-native product cards — column count, layout, action button. Four issues caught in V1.2 live verification on a3rev.com:
* Fix: container query never matched. `container-type: inline-size` was on `.def-cc-result-cards` and the `@container` rule queried the same element, but container queries match the nearest containment ANCESTOR — not self. The grid stayed at 2 columns at all widths. Moved `container-type` to `.def-cc-result-section` so the cards grid correctly queries its parent's inline-size and expands to 4 columns at ≥720px.
* Fix: card title now renders before price (matches archive-page convention). Title is bold (font-weight 600); price/subtitle is regular weight. Previously the price rendered first in bold and the title second in light — visually backwards.
* Fix: Simple Subscription products (WC Subscriptions plugin, type `subscription`) now show `+ Add to cart` instead of `View product →`. Variable Subscription (type `variable-subscription`) correctly continues to fall back to View product since it requires tier selection on the product page (same as variable products). Add-to-cart eligible types are now `simple` and `subscription`.

= 3.0.0 - 2026-04-30 =
* Feature: chat-native product cards (V1.2). Sales Assistant (Customer Chat) and Staff/Management Knowledge Assistants (Staff AI) now surface product results as a chat-native grid of cards — image, price, title, action button — instead of plain text. The LLM plans 1-4 thematic searches per turn and authors a heading + description for each, producing Bunnings-style multi-section responses for broad customer questions and tighter 1-2 section responses for narrow filtered questions.
* Feature: shared renderer module (`def-core-product-cards.js` + `def-core-product-cards.css`) loaded by both Customer Chat and Staff AI. Container-query responsive (2 cols < 720px, 4 cols ≥ 720px) sized to the chat panel width. Equal-row-height across each row via CSS Grid stretch + flex column with `margin-top: auto` on the action button — no JS, no truncation, faithful to how the customer's WC archive page renders the same titles.
* Feature: Customer Chat add-to-cart wiring. Tapping `+ Add to cart` on a simple in-stock product POSTs to `/wp-json/wc/store/v1/cart/add-item` with the existing Cart-Token + wc_store_api Nonce headers (rotated on response, persisted to localStorage — same pattern as v2.4.0). Variable / grouped / external / out-of-stock products fall back to `View product →`.
* Feature: Staff AI Edit Product action. Cards render with a single "Edit product" anchor pointing to wp-admin; WordPress core handles permission rejection for staff without `edit_products` capability.
* Security: DOMPurify allowlist for WC `price_html` (sale markup `<del>` + `<ins>` preserved; `style` forbidden). URL fields pass through `safeLinkHref()` to reject `javascript:` / `data:` / `vbscript:`. Renderer fails closed if DOMPurify unavailable.
* Accessibility: image link `aria-hidden="true"` so title link is the sole accessible name (no duplicate screen-reader announcements). Action button is a sibling, never nested in card-level link.
* Defensive: frontend cap of 6 sections per turn; cards missing `id`/`title`/`url` silently dropped; card count clamped to 4.
* Pairs with DEF backend PR #215 (`feat(v2): search_products tool — vector + WC Store API hybrid`).

= 2.9.2 - 2026-04-29 =
* Fix (architectural): Staff AI streaming brought into structural parity with Customer Chat's V2 pattern. The `done` handler was rebuilding DOM from a messages[] array via `renderMessages()`, which wiped the multi-agent streamed content (specialist text + Concierge wrap-up), corrupted the persona divider's DOM position, and orphaned tool-status indicators. Now finalises the streamed bubble in place via `streamEl.innerHTML` (matching Customer Chat). Only falls back to renderMessages when no text streamed (tool-only/empty responses). Same alignment applied to the error branch.

= 2.9.1 - 2026-04-29 =
* Fix: Staff AI specialist text streaming. When a Concierge spawned a V2 specialist, the specialist's response text never rendered — the persona divider ("MANAGEMENT KNOWLEDGE IS HELPING") was inserted as a sibling of `.message` divs, breaking the `.message:last-child` typing-takeover selector. Replaced with a direct `.typing-indicator` lookup so the takeover works regardless of sibling order. Customer Chat (V2 native) was unaffected.

= 2.9.0 - 2026-04-28 =
* Feature: per-chip introduction text — hybrid welcome-chip behaviour. Each suggestion chip can have an optional Introduction textarea (max 1000 chars). When set, clicking the chip renders the intro as an assistant message and waits for the visitor's reply (Bunnings "Buddy" pattern). When empty, the chip falls back to the v2.7.0 behaviour (pre-fill composer + submit). Mix-and-match per chip.
* Polish: help text under Chip 1's intro field points admins to the Setup Assistant for help drafting introductions.
* Internal: three new options (`def_core_chat_welcome_chip_1_intro`/`_2_intro`/`_3_intro`) registered in the admin sanitiser registry, REST API allowlist with validate + sanitize callbacks, `wp_localize_script`, and Setup Assistant FIELD_MAP. Intro text sanitised via `trim(sanitize_textarea_field())` and capped at 1000 chars. Rendered client-side through the same DOMPurify pipeline as normal AI messages.

= 2.8.0 - 2026-04-28 =
* Feature: welcome banner — two image uploads in Branding tab. **Desktop Banner** (recommended ~5:1 aspect, e.g. 2400×480px) renders as a wide strip above the greeting on viewports ≥481px wide. **Mobile Banner** (recommended ~2.7:1 aspect, e.g. 860×320px) is a chunkier banner shown on phones, where the wide desktop strip would otherwise shrink to a thin sliver and lose impact. Both optional — if only one is uploaded, it's used at all viewports. Widget renders a `<picture>` element with viewport `(min-width: 481px)` source for browser-native viewport selection.
* Fix: assistant message bubbles now render the configured Logo (from Branding tab) in the round avatar instead of a hardcoded "DE" badge. The logo flows through `config.logoUrl`. Falls back to display-name initials, then "AI", when no logo is set.
* Internal: new `def_core_chat_hero_image_id` and `def_core_chat_hero_image_mobile_id` options (attachment IDs) registered in the admin sanitiser registry, REST API allowlist with sanitize callbacks, and Setup Assistant settings registry. Reuses the existing `validate_logo_id` / `sanitize_logo_id` callbacks.

= 2.7.0 - 2026-04-28 =
* Feature: welcome-state suggestion chips. Up to three admin-configurable opening prompts render as tappable pills below the greeting in Customer Chat. Tap pre-fills the composer and submits. Empty admin slots are hidden.
* Feature: AI Disclosure Notice at the bottom of the chat panel. A single notice line (max 500 chars, default *"AI responses may be inaccurate. By using this assistant, you agree to our"*) followed by the Legal Link Label (max 50 chars, default *"Terms & Conditions"*). The label renders as a clickable link when a Legal Link URL is configured. Default reads: *"AI responses may be inaccurate. By using this assistant, you agree to our Terms & Conditions"*. Clearing the notice text hides the entire line. Master toggle: "Show AI disclosure notice in Customer Chat".
* Feature: one-tap refresh button in the chat header. Same handler as the "Clear chat" menu item but reachable in one click.
* Feature: configurable Legal Link Label. The "Privacy Policy URL" field is renamed "Legal Link URL" and gains a sibling "Legal Link Label" text field. Defaults to "Privacy Policy" when empty.
* Polish: text-readability pass. Body messages 14→15px (16px in Spotlight), composer 16px (prevents iOS zoom-on-focus), line-height 1.5→1.6.
* Fix: whitespace-only AI Disclosure Notice text now collapses to empty so the line hides as expected.
* Security: Legal Link URL sanitiser hardened — http/https-only, scheme+host required, same-origin paths allowed (protocol-relative `//evil.com` rejected), 2048-char cap, invalid input drops to empty string.
* Security: client-side scheme allowlist (`safeLinkHref()`) applied at the DOM sink (immediately before assigning to `<a>.href`) in the AI Disclosure Notice line, as a second layer over the PHP sanitiser. A bad URL falls through to plain-text label rendering instead of attaching a hostile href.
* Internal: five new tenant-config options registered in the admin API + Setup Assistant settings registry (`def_core_chat_welcome_chip_1`–`_3`, `def_core_chat_compliance_text`, `def_core_chat_privacy_link_label`).

= 2.6.0 - 2026-04-28 =
* Feature: new "Spotlight" Display Mode option for Customer Chat. A large overlay centered on the page with a dimmed backdrop, inspired by the Bunnings "Buddy" / WC Cart Block full-attention pattern. Best for tenants who want the chat to feel like a focused experience rather than a peek-mode bubble. Default 960×600 desktop size, configurable in admin (width 600–1200, height 500–800), goes full-screen on mobile.
* Feature: existing Modal and Drawer modes unchanged. Spotlight is purely additive — picking it does not affect tenants on Modal/Drawer. The same chat content (header, messages, composer, file upload, AI disclosure, V2 persona indicator, etc.) renders identically across all three modes; only the panel shell differs.
* Fix: the Drawer Width admin option (300–600px) is now actually applied to the rendered drawer. Previously the drawer was hard-coded to 400px regardless of the setting — the value flowed through the admin save path but was ignored by the loader CSS. The setting now drives a `width: min(<configured>px, 100vw)` rule.
* Fix: the Modal description in admin Chat Settings was inaccurate ("Chat opens in a centered overlay window") — Modal mode has always been corner-anchored, not centered. Updated to "Compact panel anchored next to the trigger button (bottom corner)."
* Internal: new `def_core_chat_spotlight_width` and `def_core_chat_spotlight_height` options registered in the admin API + Setup Assistant settings registry. Defense-in-depth runtime clamps in the loader (in addition to the existing PHP sanitizers on save) protect against drift or external config injection.

= 2.5.1 - 2026-04-27 =
* Feature: the `[def_chat_button]` shortcode and `def_core_chat_button` action hook now render a properly styled trigger button that visually matches the floating chat button. Previously the rendered `<button>` had no styling — sites that disabled the floating button and placed the trigger via shortcode/hook saw an unstyled browser-default button. The Chat Settings options (Button Color, Button Hover Color, Button Icon — Chat bubble / Headset / AI sparkle / Custom — and Button Label) now drive both the floating button and any shortcode/hook placement, identically.
* Internal: shortcode/hook button receives main-document CSS via a new `def-core-chat-trigger` stylesheet enqueued on every frontend page, with color values injected as CSS variables (`--def-chat-btn-color`, `--def-chat-btn-hover`) from the existing options via `wp_add_inline_style`. The floating button continues to be styled inside its Shadow DOM by the loader; the two buttons now share the same icon set, sparkle hover animation, and reduced-motion handling, but their styles live in independent scopes (Shadow DOM vs. main document) by design.
* Behavior change: shortcode default for `label` and `icon` attributes now falls through to the saved Chat Settings options when omitted, instead of hard-coded `Chat` / no-icon. Existing shortcode invocations like `[def_chat_button label="Help"]` are unaffected — explicit attributes always override.

= 2.5.0 - 2026-04-27 =
* Feature: Orchestrator V2 agent-persona indicator now ships across all three channels (Customer Chat, Staff AI, Setup Assistant). When the Concierge dispatches a specialist (e.g. Sales Assistant in Customer Chat, Knowledge Assistant in Staff AI), the chat renders a subtle "X is helping" divider in the message stream and prefixes the thinking-status row with the active speaker's name. Concierge / unlabelled events render no divider — keeps the calm of the front-door voice.
* Refactor: Persona logic factored into a shared `def-persona.js` helper consumed by all three channel widgets via `window.DefPersona.createController(...)`. Replaces the inline copy that previously lived only in Customer Chat. Default specialist labels (sales/support/staff/management/AP/setup) live in the helper; channels override on demand. Future Concierge roster changes (e.g. adding `sales_assistant` to Staff Coordinator) need no def-core update — the agent_id is already covered.

= 2.4.0 - 2026-04-25 =
* Feature: Customer Chat add-to-cart now calls WooCommerce's Store API (`/wp-json/wc/store/v1/cart/add-item`) directly from the widget instead of the custom `tools/wc/add-to-cart` REST endpoint. Store API is the same endpoint the WC Cart Block uses — it manages guest sessions via a `Cart-Token` response/request header pair (separate from the `wp_woocommerce_session` cookie that edge proxies routinely strip on `/wp-json/*`), and uses its own `wc_store_api` Nonce. The browser tracks the rotated `Cart-Token` in `localStorage` so the cart survives reloads and proxy churn. Logged-in and guest visitors both flow through the same code path.
* Removed: legacy `wc_add_to_cart` PHP endpoint (~340 LOC), `permission_check_add_to_cart`, `parse_wc_session_cookie_from_request`, and the `/tools/wc/add-to-cart` route registration. Browser-side dispatch via `handleWpRestCall` is unchanged in shape — only the URL and headers it sends differ. The new path eliminates the REST-context session-init timing bug that produced "second add wipes cart" symptoms for guests under any cookie-stripping proxy (Cloudflare, hosting CDNs).
* Compatibility: `def-core-cart-sync.js` (iframe integration helper) is still enqueued whenever WC is active — its gating no longer references the removed endpoint. No JS-side breaking changes for iframe deployments.

= 2.3.2 - 2026-04-24 =
* Fix: Customer Chat tool_done status rendering is now centralised at the widget. `completeToolStatus()` treats success-shaped statuses (`success`, `completed`, `ok`, `done`) as success regardless of which backend framework emitted the event. Kills the `Done (failed)` and `File analyzed (failed)` bug class for any tool, not just per-tool. Companion to DEF #204.

= 2.3.1 - 2026-04-23 =
* Fix: Customer Chat add-to-cart now narrates from WooCommerce's actual response instead of a hardcoded "Product added to cart" string. The PHP endpoint clears WC notices before dispatch, captures `wc_get_notices('error')` on failure (e.g. `Sorry, you cannot add another "X" to your cart.`), and otherwise generates the success message via `wc_add_to_cart_message()`. The trailing "View cart" link bleed-through from WC's HTML messages is stripped so the final string reads as natural English.
* Fix: When `handleWpRestCall` gets an error response from a `wp_rest_call` endpoint, the JS now appends WC's actual error wording as a real assistant chat bubble (in addition to the existing red toast). Without this, the LLM's pre-execution "I'm adding it" narration was the only visible message — the user had no in-conversation evidence of WC's rejection.
* Fix: When the request was for a variation, the success message now uses the variant's display name (e.g. *"WP Email Template — Pro"*) instead of the parent product name. Matches WC's own notice wording on a normal page submit.

= 2.3.0 - 2026-04-23 =
* Feature: V2 Orchestrator frontend — agent persona badge for Customer Chat. When the Concierge dispatches a specialist, the chat widget renders a subtle "Sales Assistant is helping" divider so the user understands which role is speaking. Companion to the DEF backend Orchestrator V2 Customer Chat migration.
* Compatibility: purely additive — no change to tool events or text rendering. SSE event handler degrades gracefully on older backend events that lack the `agent` tag.

= 2.2.10 - 2026-04-22 =
* Fix: Customer Chat add-to-cart no longer wipes existing items from a logged-in user's cart. v2.2.6 stopped sending `X-WP-Nonce` on `wp_rest_call` actions to fix anonymous-visitor 403s, but WordPress REST cookie auth refuses to set the current user from the auth cookie without a matching nonce — so logged-in users were treated as anonymous, `wc_add_to_cart` skipped its persistent-cart merge, and the next add overwrote the existing cart in the session. `handleWpRestCall` now sends the nonce when `config.isLoggedIn` is true (in addition to the existing `auth: true` opt-in). Anonymous visitors still skip the nonce and continue to work.
* Fix: Customer Chat widget now reflects the WordPress login state on init for logged-in users. Since v2.0.3 (#108) the JWT context-token fetch was removed from init to suppress a 401 console error for anonymous visitors. As a side effect, `isAuthenticated()` always returned false for logged-in users — the widget showed the "Log in" menu item, the escalation form rendered anonymous fields, and server-side thread history was not loaded. Init now calls `getValidToken()` only when `config.isLoggedIn` is true, restoring the pre-v2.0.3 behaviour for logged-in users while keeping the no-401 path for anonymous visitors.

= 2.2.9 - 2026-04-21 =
* Feature: Agentic loop closure for async client-executed tools. The browser now POSTs the authoritative result of `wp_rest_call` UI actions (like add-to-cart) back to DEF after they resolve, so the next turn's AI response reflects the real outcome rather than a pre-execution guess. New WP REST endpoint `POST /wp-json/a3-ai/v1/tool-result-confirm`. See DEF PR #200 for the backend half.

= 2.1.7 - 2026-04-15 =
* Security: Per-IP rate limit on the anonymous Customer Chat escalation endpoint (`/wp-json/a3-ai/v1/customer-chat/send-escalation-email`). Previously the only gate was the `wp_rest` nonce, which has a 12–24h lifetime — a scripted attacker with a scraped nonce could loop sends to spam `def_core_escalation_customer`. Now capped at 5 requests per 60 seconds per IP, returning HTTP 429 when exceeded. Transient-backed, hashed so IPs aren't stored cleartext in `wp_options`.

= 2.1.6 - 2026-04-15 =
* Fix: Customer Chat escalation — closing or cancelling the form now aborts an in-flight send via AbortController, so an email can't go out after the user explicitly backed out. Previously the `trackAbort` helpers only bookkept the controller list; they did not call `.abort()`, so a mid-flight POST would complete in the background.
* Fix: Customer Chat escalation — response parsing is now defensive. A non-JSON error body (HTML error page, empty response) surfaces a clean error state instead of throwing `Unexpected token <`.

= 2.1.5 - 2026-04-15 =
* Fix: Setup Assistant escalation now works end-to-end. Previously the Accept button re-prompted the LLM in an infinite loop and no form ever rendered. Replaced with a real inline form (Subject + Message + Send/Cancel) that posts directly to a new def-core route `/wp-json/a3-ai/v1/setup-assistant/send-escalation-email`, which forces `channel=setup_assistant`, reads the authenticated user's name + email from `wp_get_current_user()` server-side, and calls `wp_mail()` via the existing `send_escalation_email` handler. Recipient comes from the `def_core_escalation_setup_assistant` option (DEF partner email).

= 2.1.4 - 2026-04-15 =
* Fix: Customer Chat escalation emails now send successfully. Previously failed with "Failed to send" because the send was routed through DEF, which couldn't authenticate back to def-core after the BFF proxy migration (logged-in users carry no JWT). Rewritten to mirror Staff AI Share: browser POSTs the final email body directly to a new def-core route (`/customer-chat/send-escalation-email`), which calls `wp_mail()` in-process. No cross-repo auth, no DEF involvement in the send path.

= 2.1.3 - 2026-04-15 =
* Fix: Setup Assistant escalation form now renders when the AI offers to escalate. The tool-output handler was checking the wrong field name (`output.escalation`) instead of the payload shape the `escalate_to_human` tool actually emits (`output.type === 'escalation_offer'`), so `renderEscalationCard()` was never invoked. The card function itself was already fully implemented. One-line condition fix in setup-assistant-drawer.js.

= 2.1.2 - 2026-04-14 =
* Fix: Customer Chat now renders progressive markdown during SSE streaming — bullets, bold, and headings display as formatted HTML as tokens arrive, instead of showing raw `**bold**` and `-` text until the response completes. Matches the pattern already used by Staff AI and Setup Assistant.

= 2.1.1 - 2026-04-14 =
* Chore: Add `.next/` to `.gitignore` to prevent Next.js build artifacts appearing as untracked noise

= 2.0.9 - 2026-04-09 =
* Fix: Staff AI conversations, threads, uploads, and tools endpoints now use BFF proxy auth (API key) instead of JWT — fixes 401 errors after v2.0.8 backend deployment

= 2.0.8 - 2026-04-09 =
* Feature: Staff AI and Setup Assistant now route through WordPress BFF proxy — browser no longer makes direct API calls or handles JWT tokens
* Feature: WordPress validates user session and forwards identity + capabilities via trusted headers (X-DEF-User-Capabilities)
* Feature: New REST endpoints: staff-ai/chat/stream, staff-ai/status, setup-assistant/chat/stream
* Feature: Server-side API URL resolver for Docker-aware proxy routing (get_def_api_url_internal)
* Removed: JWT token fetch from Staff AI and Setup Assistant JavaScript

= 2.0.6 - 2026-04-08 =
* Fix: Cache-busting on lazy-loaded JS/CSS assets — browser no longer serves stale widget code after plugin updates
* Fix: Auth state detection call order — load thread before checking, so conversation cleanup works correctly

= 2.0.5 - 2026-04-08 =
* Fix: Clear stale chat thread on WordPress logout — prevents wrong employee routing when user logs out via wp-admin instead of the chat widget logout button
* Fix: Add `isLoggedIn` server-rendered flag to widget config for reliable auth state detection on page load
* Fix: Clear session cookie from localStorage on conversation reset to prevent stale routing state

= 1.5.0 - 2026-03-24 =
* Feature: Staff AI welcome message shows full capability list on first visit only — subsequent new chats show a random "Did you know?" tip
* Feature: Progressive markdown streaming — AI responses render formatted text during streaming
* Feature: Smart scroll — auto-scroll only when user is near the bottom
* Fix: Blank AI bubble no longer appears before the actual response
* Improved: Expanded capability list from 6 to 15 items (capability-filtered)
* Improved: Adaptive streaming render intervals for performance

= 1.2.4 - 2026-03-16 =
* Fix: OAuth PKCE storage — replace set_transient() with update_option() to fix silent write failure in AJAX context
* Fix: Fail-closed expiry check — reject PKCE data immediately if expires_at is missing, malformed, or expired
* New: Daily WP-Cron cleanup for abandoned PKCE options (def_core_cleanup_pkce)

= 1.2.0 - 2026-03-13 =
* New: One-Click Connect — OAuth 2.0 Authorization Code + PKCE (S256) flow for zero-manual-entry DEFHO connection
* New: DEF_Core_OAuth class — PKCE verifier/challenge generation, transient-based state storage, REST callback endpoint
* New: "Connect to DEFHO" hero button on Connection tab with manual connection as accordion fallback
* Security: OAuth callback session-bound — verifies logged-in admin with def_admin_access matches the initiating user
* Security: PKCE verifier stored server-side in WordPress transient (5-min TTL), consumed immediately on use
* Security: Authorization code exchanged server-side — no secrets exposed to browser
* Improved: Connection tab UI redesign — primary one-click action, manual entry as fallback
* Improved: Success/error admin notices after OAuth redirect
* Fix: Staff AI menu now visible to all DEF roles (management and admin, not just staff)

= 1.1.0 - 2026-03-13 =
* Security: Encrypted storage for connection secrets (API key, service auth secret)
* Security: Authenticated encryption at rest using sodium (XSalsa20-Poly1305) with AES-256-GCM fallback
* Security: Automatic migration of existing plaintext secrets on first read
* Security: Salt rotation detection with admin notice for recovery
* New: Environment-aware API URL resolution (wp-config.php constant, stored option, or platform default)
* New: Complete plugin cleanup on uninstall (all options, transients, and capabilities removed)
* Improved: Admin connection panel shows masked credential status instead of raw values
* Improved: Removed manual API URL input field (managed by platform connection push)
* Improved: Connection config push now stores WordPress site URL correctly
* Refactor: Renamed internal admin API controller class for architectural clarity
* Tests: 35 new encryption tests covering round-trip, auto-migration, malformed data, and salt rotation

= 1.0.0 - 2026-03-09 =
* Initial public release
* Customer Chat — Shadow DOM widget with floating button, drawer mode, drag-and-drop upload
* Staff AI — wp-admin assistant with share-to-email feature
* Setup Assistant — intelligent configuration agent with tool-based settings management
* JWT authentication bridge with JWKS endpoint
* WooCommerce integration — product search, cart sync, order lookup
* Real-time SSE streaming across all three channels
* Light/dark mode with automatic theme color detection
* AI disclosure notice with configurable Privacy Policy URL
* Manual connection fallback form
* Knowledge export endpoints for content and product indexing
* GitHub Releases auto-updater for interim distribution

== Upgrade Notice ==

= 1.1.0 =
Security update: Connection secrets are now encrypted at rest. Existing plaintext secrets are automatically migrated on first use. No action required — update and re-push connection config from DEFHO portal.

= 1.0.0 =
Initial public release of Digital Employee Framework - Core.

== Additional Info ==

**Support**: For support, visit [defho.ai](https://defho.ai/) or open an issue on [GitHub](https://github.com/a3rev-ai/def-core/issues)

**Documentation**: See the plugin [README on GitHub](https://github.com/a3rev-ai/def-core) for full documentation

**Security**: Report security issues to security@a3rev.com

== External Services ==

This plugin connects to an external AI service to power the Customer Chat and Staff AI features.

= Digital Employee Framework API =

When the Customer Chat widget or Staff AI panel is used, chat messages are sent to the configured Digital Employee Framework (DEF) API server for processing by AI models. This connection is required for the chat features to function.

* **What is sent:** Chat messages, user display name, and session context.
* **When:** Only when a user or visitor actively sends a message via the chat interface.
* **Service URL:** Configured by the site administrator on the Connection tab (typically `https://api.defho.ai`).
* **Service provider:** [DEFHO](https://defho.ai/) by a3rev Software.
* **Terms of Service:** [https://defho.ai/terms](https://defho.ai/terms)
* **Privacy Policy:** [https://defho.ai/privacy](https://defho.ai/privacy)

No data is sent to external services when the chat features are not in use. Site administrators can configure a Privacy Policy URL in Chat Settings to display an AI disclosure notice to visitors before they begin chatting.

== Privacy Policy ==

This plugin does not collect or store any personal data beyond what WordPress already collects. JWT tokens contain only the minimum necessary user information and are short-lived by design. When chat features are used, messages are processed by the external AI service described in the External Services section above.

== License ==

This plugin is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or any later version.
