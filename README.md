# Digital Employees

AI-powered Digital Employees for your WordPress site. Customer-facing chat, internal staff assistant, and intelligent setup — all connected to the [Digital Employee Framework](https://defho.ai/).

[![Download Plugin](https://img.shields.io/badge/Download_Plugin-v1.2.4-blue?style=for-the-badge&logo=wordpress)](https://github.com/a3rev-ai/def-core/releases/download/v1.2.4/digital-employees.zip) [![License: GPL v2+](https://img.shields.io/badge/License-GPL_v2+-green?style=for-the-badge)](https://www.gnu.org/licenses/gpl-2.0.html) [![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0+-21759b?style=for-the-badge&logo=wordpress)](https://wordpress.org/)

## What Are Digital Employees?

Digital Employees are AI agents that work alongside your team. They understand your business context, follow governance rules, and operate across multiple channels:

### Customer Chat
A chat widget for your site visitors. Floating button or embedded via shortcode. Answers questions using your site's content, products, and knowledge base. Streams responses in real-time with word-by-word rendering.

**Digital Sales Assistant**
- Product inquiries, pricing, features, and comparisons
- Personalised product recommendations
- Add-to-cart and checkout assistance
- Knowledge base search across your site content
- File upload and image understanding
- Escalation to human support

**Digital Support Assistant**
- Order lookup and status tracking
- Subscription and license management
- Support ticket retrieval
- Refund and cancellation assistance
- Account and billing inquiries
- File upload for troubleshooting (screenshots, error logs, documents)
- Document extraction (PDF, DOCX, XLSX, CSV)
- Escalation to human support

### Staff AI
An internal AI assistant in wp-admin for your team. Available to users with the appropriate role.

**Digital Staff Assistant**
- Document creation (DOCX, PDF, Markdown)
- Spreadsheet and data file creation (XLSX, CSV)
- Image generation from text descriptions
- General writing, ideation, and research assistance
- Staff-level knowledge base retrieval
- File upload and content extraction
- Cross-chat user memory (remembers context across conversations)
- Escalation to human support
- Sub-agent delegation (analyst, researcher, writer)

**Digital Management Assistant**
- All Staff Assistant capabilities
- Management-level knowledge base access
- Access to confidential and management documents

### Setup Assistant
An intelligent configuration agent that lives in your wp-admin settings. Guides you through plugin setup conversationally — configures branding, chat settings, user roles, and connection status. Knows the current state of every setting.

- Full setup status overview
- Read and update any plugin setting
- Connection testing and troubleshooting
- User role and capability management (search, assign, remove)
- Theme color detection for chat button styling
- Guided setup flow (connection, branding, escalation, user roles, chat settings)
- File upload and content extraction
- Escalation to your DEF Partner for hands-on help

## Requirements

- WordPress 6.0+
- PHP 8.0+
- A [DEFHO](https://defho.ai/) account (Digital Employee Framework platform)

## Installation

### From GitHub Releases

1. Download [digital-employees.zip](https://github.com/a3rev-ai/def-core/releases/latest/download/digital-employees.zip) from the latest release
2. In WordPress, go to **Plugins > Add New > Upload Plugin**
3. Upload the .zip and click **Install Now**
4. Activate the plugin

The plugin checks GitHub for updates automatically — you'll see standard WordPress update notifications when a new version is available.

### Manual

1. Clone or download this repository
2. Upload the `def-core` folder to `/wp-content/plugins/`
3. Activate via **Plugins > Installed Plugins**

## Getting Started

1. **Sign up** at [defho.ai](https://defho.ai/) and create a Tenant for your site
2. **Install** the plugin on your WordPress site
3. **Connect** — push the connection from your DEFHO Tenant Portal (or enter credentials manually on the Connection tab)
4. **Configure** — the Setup Assistant will guide you through branding, chat settings, and user roles

Once connected, Customer Chat is available on your frontend and Staff AI is available in wp-admin.

## How It Works

```
WordPress (UI + Authentication)
        |
    def-core (this plugin)
        |
Digital Employee Framework (AI, Tools, Governance)
```

This plugin is the bridge. All AI logic, tool execution, employee orchestration, and governance enforcement happen server-side in the DEF backend. WordPress provides the user interface and authentication context.

## Admin Settings

Five tabs under **Digital Employees** in wp-admin:

| Tab | What It Does |
|-----|-------------|
| **Branding** | Display name, logo, app icon |
| **Chat Settings** | Display mode, button position/color/icon/label, AI disclosure notice |
| **Escalation** | Email recipients for Customer Chat and Setup Assistant escalations |
| **User Roles** | Assign DEF capabilities (Staff AI access, Management access) per user |
| **Connection** | Connection status indicator (read-only when push-configured) |

## WooCommerce Integration

When WooCommerce is active, additional tools load automatically:

- Product search and browsing
- Cart synchronization
- Order lookup and status
- Knowledge export for product catalog indexing

## Shortcodes & Hooks

**Shortcode:**
- `[def_chat_button]` — Render the Customer Chat button at a specific location

**Hooks:**
- `def_core_chat_button` — Action to render the chat button in theme templates
- `def_core_register_tools` — Register additional API tools
- `def_core_token_expiration` — Filter JWT token lifetime (default: 5 minutes)
- `def_core_chat_strings` — Filter Customer Chat UI strings for i18n

## REST API

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/wp-json/a3-ai/v1/jwks` | GET | Public | JWKS public keys for JWT verification |
| `/wp-json/a3-ai/v1/context-token` | GET | WP Auth | Issue signed context token |
| `/wp-json/def-core/v1/content/export` | GET | API Key | Bulk content export for knowledge indexing |
| `/wp-json/def-core/v1/products/export` | GET | API Key | WooCommerce product export |
| `/wp-json/def-core/v1/connection-status` | GET | Public | Connection health check |

Tool endpoints (product search, cart operations, order lookup) are registered dynamically via the API registry.

## Security

- RSA-256 signed JWT tokens (5-minute expiry)
- All authority enforced server-side by the framework
- No secrets hard-coded — credentials stored in WordPress options
- Bearer token authentication for API endpoints
- Origin validation for cross-domain requests
- AI disclosure notice for visitor transparency
- WordPress nonce + cookie auth for admin endpoints

## External Services

This plugin connects to the Digital Employee Framework (DEF) API to power its AI features. Chat messages and user context are sent to the configured DEF server only when a user actively sends a message. No data is transmitted when chat features are not in use. See the [DEFHO Privacy Policy](https://defho.ai/privacy) and [Terms of Service](https://defho.ai/terms).

## Contributing

Issues and pull requests welcome at [github.com/a3rev-ai/def-core](https://github.com/a3rev-ai/def-core).

## License

GPLv2 or later. See [LICENSE](LICENSE) for full text.
