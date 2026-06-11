=== Digital Employee Framework - Core ===
Contributors: a3rev
Tags: ai, chat, digital employee, ai assistant, customer support
Requires at least: 6.2
Tested up to: 6.9.4
Requires PHP: 8.0
Stable tag: 4.9.1
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

= 4.9.1 - 2026-06-12 =
* Tweak: Clusters tab polish — uniform filter-toolbar control heights with "+ Add target" at the toolbar's right end, colour-coded lifecycle counts on target rows (proposed amber, in review blue, published green, dismissed muted), reference-URL remove × inside the input with matched button sizes, a divider before the Derive/Pause/Remove action row, and an "Add your own" heading on the manual keyphrase row.

= 4.9.0 - 2026-06-12 =
* Feature: Clusters UX v2 — the Clusters tab is now a master list with inline detail. Each target is a compact one-line row (title · type · status dot · keyphrase counts · last-derived date) instead of a fully-expanded card, so the tab stays usable with many targets. Click a row to expand the full detail in place, accordion-style (one open at a time): reference URLs, Derive / Pause / Remove, and the keyphrase queue. Text search plus "Needs curation" / "Needs review" / status / type filters surface the targets that need you, sorted needs-attention-first (drafts in review, then proposals awaiting curation, then the rest, most recent first); long lists render 25 rows at a time with a Show-more button. The nominate picker now sits behind a "+ Add target" button at the top of the list.
* Feature: per-keyphrase lifecycle states. Each phrase now shows where it is on its journey: proposed (awaiting your curation) → queued (the next scheduled run will write it) → in review (the post is written and sits in Content Drafts for you — click "Review draft" to jump straight to its review card) → published (links to edit/view the live post) → dismissed (collapsed group, kept — not deleted). The list row's counts are this lifecycle aggregated.
* Feature: "Dismiss remaining (N)" — after approving your picks, one click at the top of the Proposed section clears every still-proposed phrase into the collapsed Dismissed group — not deleted, and the agent won't re-propose them on the next Derive (dismissed phrases keep their slot). Backed by a new bridge route (POST /staff-ai/content/targets/{id}/keyphrases/dismiss-remaining) proxying the DEF backend.
* Tweak: guidance baked into the tab copy — clusters are for cornerstones (realistically 5–20 targets, not every product), and a healthy cluster is the cornerstone plus 6–12 supporting posts.

= 4.8.0 - 2026-06-11 =
* Feature: the Content Drafts page is now organized into three tabs. "Improve" holds everything for your existing content (coverage strip, needs-a-keyphrase panel, improvement drafts — unchanged). "Create" holds the on-demand post generator and its review cards, plus a new optional notes box for the angle, audience or points you want the writer to cover. "Clusters" is new: build topic clusters around your cornerstone content.
* Feature: Clusters tab (Content Agent Engine 2.5). Search your published pages, posts and products and nominate one as a cluster target, optionally attaching up to 5 reference URLs (e.g. your docs) the agent reads when deriving. Click Derive and the Content Agent proposes supporting keyphrases for the target — each with an intent label and a one-line rationale — into a curation queue where you approve, edit or dismiss every phrase (you can also add your own, which count as already approved). Approved phrases are the only ones the agent will ever write posts for, and written phrases link to their posts so you can see cluster progress at a glance. Targets can be paused, and removing a target never touches published posts. The same Company-Knowledge safeguard notice from the create surfaces appears here too.

= 4.7.2 - 2026-06-11 =
* Safety: posts created by the Content Agent are now EXCLUDED from Digital Employee knowledge by default (fail-closed). An AI-created draft can no longer flow into Customer Chat answers automatically — a human must review the images, verify every claim, and then expressly include the post by unchecking "Exclude from Digital Employee knowledge" in the post editor (the existing checkbox — no new setting). The Content Drafts create box and every "New post" review card now carry a notice spelling this out.

= 4.7.1 - 2026-06-10 =
* Fix: in the block editor, the "Exclude from Digital Employee knowledge" option appeared TWICE in the sidebar — once as the proper editor panel and once as a duplicate classic meta box. The classic box is now flagged as the panel's back-compat fallback, so the block editor shows only the panel; classic-editor screens (e.g. WooCommerce products) still show the classic box exactly as before.
* Fix: the block-editor panel's help text was outdated — it still said an already-indexed item is removed on the next "Sync Now — Full" from the Tenant Portal. Since v3.5.2 exclusion is picked up by the normal sync for that item alone; the help text now says so, matching the classic box.
* Fix: after clicking Generate on the Content Drafts page, the automatic refresh gave up after 3 minutes — but creating a post with images takes around 4, so the new draft card could silently never appear without a manual reload. The page now keeps checking for up to 10 minutes, and if generation takes even longer it says so honestly ("Still generating — your draft will appear under Content Drafts when ready; reload in a minute.") instead of going quiet.

= 4.7.0 - 2026-06-10 =
* Feature: Content Agent created posts now include real images. When you Generate a new post, the images the agent generates are sideloaded into your WordPress Media Library through a new hardened bridge (strict image-only validation on the actual bytes — PNG/JPEG/WebP only, never SVG or scripts, 10MB cap, safe filenames), the post gets a featured image, and the body's image spots become real Gutenberg image blocks with alt text. Your theme serves its own registered featured-image sizes automatically (WordPress generates them on upload from the high-res source), and every sideloaded image also gets an exact 1200×630 crop that's written to Yoast's Open Graph / Twitter share-image fields, so social shares look right everywhere. The "New post" review card now previews the featured image and inline images before you approve, and image cleanup is scoped so the agent can only ever remove images it created itself that no post is using — your own Media Library uploads are untouchable. Tenants without an image key keep the previous labelled placeholders — nothing breaks.
* Fix: after clicking Generate, the Content Drafts page now refreshes itself and shows the new draft card when it's ready (it previously said the draft "will appear shortly" but never refreshed — you had to reload the page).

= 4.6.1 - 2026-06-10 =
* Fix: a "New post" review card (Content Agent Create New) showed "(untitled)" with empty SEO title, meta description and body preview, even though the generated post was complete. The card was reading those fields from the wrong place in the draft data; it now reads the post's title, slug, SEO title, meta description and body from the proposed-content envelope, so the full draft is visible for review before you approve. Display-only — the created post and its content were always correct.

= 4.6.0 - 2026-06-09 =
* Feature: the Content Agent can now CREATE brand-new posts on demand. On the Content Drafts page, enter a focus keyphrase and click Generate — the agent writes a fully-optimized new post (title, SEO title, meta description, focus keyphrase, and a structured body) and it appears in the review queue as a "New post" card. Review the proposed title, slug, SEO meta, body preview and the agent's Yoast-style self-assessment, then Approve to create it as a WordPress DRAFT (nothing is published — you get an editor link to finish and publish), or Dismiss. The new post's body is built from safe core Gutenberg blocks and the authored content is sanitized before it's stored. (Images, scheduling and social are later waves.)

= 4.5.1 - 2026-06-09 =
* Fix (infrastructure): the Content Agent's content-edit bridge now honors the per-item "exclude from ingestion" flag at every live-write boundary. An excluded item can no longer be body-edited or have its SEO meta written/published by the agent: the editable-text manifest reports the item as excluded so the agent skips it before generating, and both write endpoints — the block-edit apply and the SEO-meta write (used by metadata-only drafts) — refuse an excluded item outright, before anything is parsed or written. This is the authoritative server-side guard, reusing the existing exclusion flag (no new setting); the read paths and non-excluded items are unaffected.

= 4.5.0 - 2026-06-09 =
* Feature: Content Drafts page goes per-content-type. The top status strip now shows your CURRENT content coverage broken down by type (Products / Posts / Pages / custom types) — e.g. "Products: ✅ 9 good · ✍️ 2 optimized · 📋 3 awaiting review · 🔑 20 need a keyphrase" — plus an overall line, with zero buckets hidden to reduce noise. This replaces the previous per-run counts, which froze at the last run's numbers and drifted from reality as drafts were applied/dismissed; a small "Last run <time>" freshness line remains. As always it shows discrete counts, never a ratio. The "needs a focus keyphrase" panel is now grouped by content type into collapsible sections (collapsed by default) with a count header each — e.g. "▸ Products (20)", "▸ Posts (10)", "▸ Pages (10)" — so it stays compact as the list grows; expand a section to see and jump to each item's editor.

= 4.4.0 - 2026-06-09 =
* Feature: Content Drafts now shows a "last run" status strip at the top of the page summarizing the Content Agent's most recent audit run — e.g. "Last run <time>: audited 84 · ✍️ 2 new drafts · 🔑 1 needs a keyphrase". It shows the run's activity breakdown (what was audited, how many new drafts were staged, how many items need a focus keyphrase, and any errors), never a ratio — most audited items legitimately pass and need no work, so a fraction would imply false pending work. Shows "Running…" while a run is in flight and "No runs yet" before the first run.

= 4.3.2 - 2026-06-09 =
* Tweak: DEF backend traffic is now exempt from Wordfence throttling. When a bridge request passes HMAC signature verification, its egress IP is automatically added to the Wordfence allowlist — using Wordfence's own IP resolver and developer API, so it matches exactly what the firewall keys rate-limiting on. This keeps DEF's bursty authenticated audit/apply calls (which arrive from a large pool of rotating cloud egress IPs that a static allowlist can't track) from being rate-limited or blocked by the WAF. The allowlist is self-populating and de-duped — each IP is written to Wordfence config only the first time it's seen. It's a no-op on sites without Wordfence (no hard dependency), and the allowlisting is best-effort and isolated so it can never affect the authentication result. No pruning of rotated-out IPs (Wordfence exposes no clean removal API; the staleness risk is low and bounded by DEF's egress pool).

= 4.3.1 - 2026-06-08 =
* Tweak: Content Drafts review card now shows the *current* meta description / SEO title (previously blank) and renders body changes as per-node "current → proposed" text diffs, with a note that the page's design blocks, images and layout are preserved. Removed the inline "Edit" button (body edits are now applied block-safely; tweak in the WordPress editor instead).

= 4.3.0 - 2026-06-08 =
* Feature (infrastructure): block-safe content edit bridge for the Content Agent (Adapter G — Gutenberg). New internal endpoints let the agent edit an item's body WITHOUT corrupting Gutenberg block markup: it never rewrites serialized blocks — instead it receives an editable-text manifest and applies surgical text patches that preserve every block wrapper, attribute, custom block (e.g. a3 BlockPress) and inner markup byte-for-byte, validated and re-serialized on your site. Content built with classic editor / Elementor / Divi / other builders is detected and left untouched (the agent does SEO metadata only for those). No visible change on its own — it backs the upcoming safe body optimization and content creation.

= 4.2.2 - 2026-06-06 =
* Tweak: Content Drafts now shows a "needs a focus keyphrase" panel listing the products the Content Agent skipped because no focus keyphrase is set — each links straight to the product editor so you can set one. Once you do, the next scheduled run optimizes it automatically.

= 4.2.1 - 2026-06-06 =
* Tweak: Content Drafts now show the focus keyphrase each draft is optimized for and a Yoast-style SEO checklist (green/red) the agent self-assessed for the proposed content — so you can see at a glance what was optimized and how. Shown when the Content Agent provides them (keyphrase-anchored optimization, paired with the DEF backend).

= 4.2.0 - 2026-06-05 =
* Feature (infrastructure): SEO-meta bridge for the Content Agent. A new internal endpoint lets the Content Agent read an item's focus keyphrase + meta description / SEO title from the active SEO plugin (Yoast first; Rank Math next) and write back the optimized meta description / SEO title plus a private "optimized" stamp. The focus keyphrase and slug are never changed by the agent. No visible change on its own — it backs the upcoming keyphrase-anchored optimization.

= 4.1.0 - 2026-06-05 =
* Feature: Content Drafts — edit before publishing. An "Edit" button on each draft turns the Proposed content into editable fields, so you can tweak the AI's wording before approving. Approving then publishes your edited version. You can only change the values of the fields the draft touched (no new fields), and the live write still runs as you.

= 4.0.1 - 2026-06-05 =
* Polish: Content Drafts review queue. Each card now shows the product name (linked to the product editor, with a "View live" link to the public page) instead of just the product ID, and a collapsible "Why this change?" strip surfaces a short explanation of the proposed change when the Content Agent provides one.

= 4.0.0 - 2026-06-05 =
* Feature: Content Agent review queue. A new "Content Drafts" page under Digital Employees lists the product-content improvements the Content Agent has drafted on its scheduled runs, shows the current-vs-proposed diff for each touched field, and lets staff approve (publish live) or dismiss. Nothing is written to your site until you approve it; the live write runs as you, so your WordPress permissions govern it. Draft content is sanitized before display.

= 3.14.4 - 2026-06-03 =
* Fix: Staff AI web-search citation pills (and result cards) disappeared on switching conversations or reopening Staff AI. The conversation-load proxy was discarding the backend's tool_outputs (always sent an empty array), so the widget had nothing to rebuild citations from on reload. It now passes them through, so citations and cards persist. (Pairs with the DEF backend change.)

= 3.14.3 - 2026-06-03 =
* Change: removed the Staff AI web-search composer toggle. Web search is now governed solely by the workspace (tenant) master switch — when it's on, the assistant decides for itself when to search the web, like every other tool, instead of the user arming a per-session toggle first. So it can answer questions needing current info without flipping a switch. No change when the master switch is off.

= 3.14.2 - 2026-06-03 =
* Polish: the web-citation hover bubble now matches Claude's citation card — it stays open when you move the pointer onto it (closes when you move away), the inner row highlights while the pointer is on the popup, the drop shadow is removed, and the colours use the app's light/dark theme.

= 3.14.1 - 2026-06-03 =
* Fix: the inline web-citation hover bubble (3.14.0) never showed — hovering a citation pill only underlined it. The pill's `overflow: hidden` (used to truncate long labels) was clipping the pop-up bubble, which sits above the pill. Truncation moved to the label and the clip removed, so the bubble now appears on hover as intended.

= 3.14.0 - 2026-06-03 =
* Polish: Staff AI web search results now appear as inline citations in the answer text (Claude-style) instead of a separate "Sources" list. Each cited source is a small pill placed right after the claim it supports — hover it to preview the page title and site; click it to open the source in a new tab. Only the sources the answer actually used are shown, exactly where they're used. Source links remain scheme-validated (http/https only) and all titles/sites render as plain text, since web content is untrusted.

= 3.13.1 - 2026-06-02 =
* Polish: the Staff AI "Web Search" control is now a proper iOS-style ON/OFF sliding toggle with a "Web Search" label (was a pill button), and its hover tooltip is state-aware (says whether it's ON or OFF). Behaviour unchanged: off by default, in-memory only, shown only when the workspace's Web Search master switch is enabled.

= 3.13.0 - 2026-06-02 =
* Feature: Staff AI web search (off by default). With a Web Search provider key + the master switch enabled on the Tenant Portal, a "Search" toggle appears in the Staff AI composer. Turn it on for a session and the assistant can search the live web, returning a "Sources" block with the pages used. The toggle is per-session and in-memory only — it resets OFF on every reload / new chat. Source links are scheme-validated (http/https only) and titles render as plain text, since web content is untrusted. Customer Chat (Joe) is unaffected.

= 3.12.1 - 2026-05-28 =
* Fix: auto-sync incremental + delete-tracking exports returned zero rows on sites whose WordPress timezone is not UTC. The DEF backend sends the "modified after" watermark as ISO-8601 with an explicit timezone offset; WordPress's `WP_Date_Query` was converting that value into the site's local timezone before building the SQL, but the column it compared against (`post_modified_gmt`) stays in UTC, so recently-edited content silently failed to match the filter on sites running in any non-UTC timezone. The export endpoints now normalize the watermark to a plain UTC datetime string before handing it to `date_query`. Affects content, products, forums, search, and trashed-posts queries.

= 3.12.0 - 2026-05-28 =
* Feature: Customer Chat now shows a Podium-style pop-up greeting bubble next to the floating launcher, so visitors notice the assistant straight away instead of having to discover the button. The bubble appears 5 seconds after page load, carries a configurable greeting (default "Hi! I'm your AI assistant / I'm here to help!"), and points at the launcher with a small tail. Clicking the bubble opens the chat; clicking × dismisses it for 24 hours on that browser. Auto-suppresses when the chat is already open, the launcher is hidden, the text is empty, or it's been dismissed in the last 24 hours. Default ON for new installs — toggle in Settings → Customer Chat → Greeting Bubble (enabled checkbox + 200-char text field). Mobile: narrower with the same tail. Honours `prefers-reduced-motion`.

= 3.11.1 - 2026-05-28 =
* Polish: dropped the per-message Joe avatar from the chat stream entirely — the widget header already carries the avatar at the top of the panel for the whole conversation, so repeating it above every reply (and especially below the header on the welcome) was visual noise. v3.11.0's grouping rule didn't actually help in real alternating chats where every Joe turn follows a user message; v3.11.1 makes the header the sole Joe indicator. The header avatar is untouched.

= 3.11.0 - 2026-05-28 =
* Polish: Customer Chat avatar grouping — when Joe sends two or more replies in a row, only the FIRST shows the brand avatar; follow-up replies in the same turn read as continuations, cutting visual repetition in long threads. The welcome/first message also skips its avatar (the widget header already shows Joe's avatar right above it). Tool-status pills mid-turn don't break the grouping (still Joe speaking); a user message does. Applies on live streaming and on history rehydration. (Skipping 3.10.0 — WordPress version_compare mis-orders "10" segments.)

= 3.9.0 - 2026-05-28 =
* Feature: Customer Chat button label is now free-text — set any label you like (e.g. "Ask Joe", "AI Assist", "Get Help"). Previously limited to a "Chat" / "AI" radio; now a text input (≤30 chars) in Chat Settings → Button Label, applied to both the floating button and any `[def_chat_button]` shortcode. Existing "Chat" / "AI" values persist; no action required.

= 3.8.0 - 2026-05-27 =
* Feature: Auto-sync push "nudge". When you add, edit, or delete content (posts, pages, products, or other synced types), the plugin now pings the Digital Employee backend so the knowledge index refreshes within about a minute, instead of waiting for the periodic background sync. A burst of edits (e.g. a bulk product update) is debounced into a single nudge via a ~45-second delayed WP-Cron event, so 50 edits trigger one incremental sync, not 50. The ping carries no content — just the site signal, authenticated with the existing connection API key (`X-DEF-API-Key`) over the same channel Customer Chat / Staff AI already use — and is best-effort: a missed ping is still caught by the scheduled background sync and the Full Re-sync reconcile.

= 3.7.2 - 2026-05-28 =
* Improvement: refreshed the Customer Chat visual design — the conversation sits on a soft tinted background with assistant replies in white cards and visitor messages in a light-grey bubble, so replies and rich content (tables, product cards) stand out. Plus clearer markdown, a compact tool-status pill, a slim streaming caret, a subtle message entrance, and tighter mobile spacing.
* Improvement: the "Button Color" branding setting now themes the whole chat widget (send button, accents, focus states), not just the floating launcher button. Message text and links stay neutral for readability.
* Fix: product card sale prices no longer show WooCommerce's screen-reader text run-together; just the original (struck-through) and sale price now show.

= 3.7.1 - 2026-05-27 =
* Fix: chat product cards showed subscription prices run together ("$8.25 / monthBilled annually at $99.00"); the recurring-price line now sits on its own line, smaller and muted.

= 3.7.0 - 2026-05-26 =
* Feature: Staff AI model switcher. A dropdown in the chat composer toolbar lets staff pick which AI model handles the current session — Opus 4.7 (most capable), Sonnet 4.6 (responsive everyday work), or Haiku 4.5 (fastest / cheapest) — so they can match model cost to the task (e.g. route bulk catalogue grunt-work to Haiku). The choice is remembered per browser (localStorage) and sent with each chat request as `model_id`; the DEF backend validates it against the registered, switcher-eligible models and falls back to the channel default if unset or unrecognised. The dropdown is populated from the `/staff-ai/status` endpoint's new `available_models` list, so it only ever offers models the backend actually has configured.

= 3.6.3 - 2026-05-26 =
* Improvement: raised the Site Intelligence REST passthrough response cap (used by Staff AI's `wc_rest_call` / `wp_rest_call` tools) from 100KB to 250KB (~60K tokens), so catalogue/list operations complete in a single pass instead of being truncated too aggressively for large-context models. Oversized responses still truncate gracefully with a paginate note.

= 3.6.2 - 2026-05-26 =
* Fix: products flagged "Exclude from Digital Employee knowledge" could still be added to cart. The chatbot resolves a product name → ID for add-to-cart via the live tool endpoint `/tools/wc/products` (`wc_get_products_list()`), which had NO exclusion filter — so an excluded product was still resolvable even though it was correctly hidden from the search index. The endpoint now filters out `_def_exclude_from_ingestion` products (meta_query + in-loop guard), and toggling the flag busts the cached product list immediately (covers Bulk / Quick-Edit, which don't fire the WC product hooks). Staff AI's `wc/v3` passthrough and the knowledge/chunk export endpoints are intentionally unchanged.

= 3.6.1 - 2026-05-25 =
* Fix: marking an item "Exclude from Digital Employee knowledge" now removes it from the live indexes on the next sync — this item only, no Full Sync needed. Previously the flag only filtered the item out of future export pulls, so anything already indexed before being excluded lingered in DEF's `search` index and the chatbot kept recommending it (across recommend / add-to-cart / all tools). The change is now caught at the meta write itself (Gutenberg, classic editor, Quick Edit, bulk action, programmatic), reported in the `/content/deleted` feed under a new `excluded_ids` array (net-latest state — a re-included item drops out), and `post_modified` is bumped so the incremental content/products export re-fetches it (the knowledge/chunk index deindexes the flagged item, or re-ingests it when re-included). Pairs with the DEF backend's new search-index delete pass. Admin copy updated to reflect per-item, no-Full-Sync behaviour.

= 3.6.0 - 2026-05-25 =
* Feature: Chat-driven sale attribution — stamps the originating Customer Chat thread id onto WooCommerce orders (`_def_chat_id` order meta) so chat-influenced sales can be attributed later. A product-card click carries the id (read once, then stripped); an in-chat add-to-cart sends it via a header; both store it in the WooCommerce session and the order hooks copy it onto the order. Internal analytics marker only — never shown to customers or used for authentication.

= 3.5.1 - 2026-05-25 =
* UI: Customer Chat shows "Searching..." while the `search` tool runs, instead of the generic "Processing..." — an accurate status label alongside the existing per-tool ones (e.g. "Fetching products..."). One-line addition to the Customer Chat tool-status map; no behavioural change.

= 3.5.0 - 2026-05-22 =
* Feature: Search-index export endpoint (`GET /wp-json/def-core/v1/search/export`) for the DEF Search Tool. Serves a metadata-shaped feed — products, posts, pages, CPTs, and taxonomy terms (categories/tags/brands as their own result objects) — that DEF indexes into the deterministic catalogue/site `search` index, separate from the content-heavy knowledge/chunk export. Emits `object_type`, taxonomy names + terms, focus keywords, and (for products) SKU, rolled-up variation SKUs, price/sale/stock, and attributes. Honours `_def_exclude_from_ingestion` at the source and reuses the existing pull + `before_delete_post` delete-tracking path. New class `DEF_Core_Search_Export`; `DEF_Core_Export::collect_taxonomy_terms()` made public for reuse.

= 3.2.0 - 2026-05-16 =
* Feature: Customer Chat handoff-ceremony bubbles removed, completing DEF Orchestrator V3.0 on the def-core side. The "Calling our Sales Specialist..." per-call status pill (v3.1.8) and the "You are now talking with our X Specialist!" persona banner no longer render. V3.0 collapses each channel to one unified employee — Joe handles every Customer Chat turn end-to-end, so there is no specialist takeover moment to announce. The persona controller is preserved (still a no-op on the SSE path); only the V2 specialist override + banner template + role-name map + dynamic spawn label are removed. Staff AI and Setup Assistant are unaffected.
* Fix: `DEF_CORE_VERSION` constant in `def-core.php` was stale at '3.1.1'; now matches the plugin-header Version (3.2.0).

= 3.1.11 - 2026-05-13 =
* (note: 3.1.10 skipped — PHP version_compare bug treats "10" as "1")
* Fix: `DEF_Core::get_def_api_url()` now applies the Docker-hostname rewrite to the `DEF_API_URL` constant value, not just to the `def_core_staff_ai_api_url` option. When a local-dev setup pins `DEF_API_URL` to a Docker-internal hostname (e.g. `http://def-api:8000`) the browser used to receive `def-api:8000` and throw `net::ERR_NAME_NOT_RESOLVED` on calls to `/api/customer/employee-tools` and `/api/my/threads`. Now the browser-facing helper rewrites Docker hostnames to the WP site host (e.g. `localhost`) regardless of source, while the server-side `get_def_api_url_internal()` continues to return the raw Docker hostname for PHP cURL. Production with public-domain DEF URLs is unaffected (real domains exit the rewrite early).

= 3.1.9 - 2026-05-13 =
* Polish: Customer Chat welcome greeting is now tenant-generic. The capability list no longer branches on WooCommerce being active — Sales and Support are universal Digital Employee roles, so "Look up your order status" / "Help you find the right product" are replaced with role-based bullets that apply to any tenant: "Be your personal Sales consultant", "Be your Support specialist", "Connect you with a human if you need extra help".
* Polish: greeting punctuation and lead-in. "Hi! I'm Joe your AI Assistant." → "Hi! I'm Joe, your AI Assistant." (comma after the brand name, applies to both logged-in and logged-out variants). "Here's what I can help you with:" → "Here's how I can help you:". Closing CTA "What can I help you with?" → "What can I do for you today?".

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
