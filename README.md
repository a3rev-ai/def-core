# Digital Employee Framework - Core

WordPress bridge plugin for the **[Digital Employee Framework](https://defho.ai/)** (DEF). Connects WordPress sites to AI-powered Digital Employees that can assist customers, support staff, and help configure your site.

## Features

- **Customer Chat** — AI chat widget for your site visitors (floating button or shortcode)
- **Staff AI** — Internal AI assistant for staff and management (wp-admin)
- **Setup Assistant** — Intelligent configuration agent that helps set up the plugin
- **JWT Authentication** — Secure token-based identity bridge between WordPress and DEF
- **JWKS Endpoint** — Public key endpoint for external JWT verification
- **WooCommerce Integration** — Product search, cart sync, and order tools (loads only when WooCommerce is active)
- **Real-Time Streaming** — SSE-based word-by-word text streaming across all channels
- **Knowledge Export** — Bulk content and product export endpoints for AI knowledge base indexing

## Requirements

- WordPress 6.0+
- PHP 8.0+
- A Digital Employee Framework account ([defho.ai](https://defho.ai/))

## Installation

1. Upload the `digital-employees` folder to `/wp-content/plugins/`
2. Activate the plugin via **Plugins > Installed Plugins**
3. Go to **Digital Employees > Settings** and configure your connection
4. Use the **Setup Assistant** to walk through configuration

## Connection

The plugin connects to your DEF backend API. Connection can be configured:

- **Automatically** — via DEFHO platform push (recommended)
- **Manually** — enter API URL and API Key on the Connection tab

## REST API Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/wp-json/a3-ai/v1/jwks` | GET | Public | JWKS public keys |
| `/wp-json/a3-ai/v1/context-token` | GET | WP Auth | Issue signed context token |
| `/wp-json/def-core/v1/content/export` | GET | API Key | Bulk content export |
| `/wp-json/def-core/v1/products/export` | GET | API Key | WooCommerce product export |
| `/wp-json/def-core/v1/connection-status` | GET | Public | Connection health check |

Tool endpoints (product search, cart operations, order lookup, etc.) are registered dynamically via the API registry.

## Shortcodes

- `[def_chat_button]` — Render the Customer Chat button at a specific location

## Hooks

- `def_core_chat_button` — Action hook to render the chat button in theme templates
- `def_core_register_tools` — Register additional API tools from modules
- `def_core_token_expiration` — Filter JWT token lifetime (default: 5 minutes)
- `def_core_chat_strings` — Filter Customer Chat i18n strings

## Architecture

```
WordPress (UI + Auth)
        ↓
def-core (Bridge Plugin)
        ↓
Digital Employee Framework (AI, Tools, Governance)
```

The plugin is intentionally thin — all business logic, tool execution, and governance live in the DEF backend. WordPress provides the UI surface and authentication context.

## Security

- RSA-256 signed JWT tokens
- All authority enforced server-side by the framework
- No secrets hard-coded — all credentials in WordPress options
- Bearer token authentication for API endpoints
- Origin validation for cross-domain requests
- AI disclosure notice for transparency compliance

## Contributing

Issues and pull requests welcome at [github.com/a3rev-ai/def-core](https://github.com/a3rev-ai/def-core).

## License

GPLv2 or later. See [LICENSE](LICENSE) for full text.
