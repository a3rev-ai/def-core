=== Digital Employee Framework - Core ===
Contributors: a3rev
Tags: ai, chat, digital employee, ai assistant, customer support
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.8.1
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
