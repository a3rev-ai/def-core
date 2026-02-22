# Digital Employee Framework - Core

## Overview

WordPress bridge plugin for the **Digital Employee Framework (DEF)**. Connects WordPress sites to AI digital employees running in the DEF backend. The plugin handles authentication, UI rendering, and request routing — all business logic, tool execution, and governance live in the framework.

Optional native integrations (WooCommerce, bbPress, etc.) are loaded only when the respective plugin is active. WooCommerce is not a dependency.

## Architecture

```
WordPress (UI + Auth Context)
        ↓
def-core (Bridge Plugin)
        ↓
Digital Employee Framework (Authority, Tools, Execution)
```

The bridge is intentionally thin.

## What This Plugin Does

- JWT handling and session context
- Secure API communication with the framework
- Chat and assistant UI components (customer-facing and staff-facing)
- User identity, role, and tenant context passthrough
- Optional integration endpoints (WooCommerce tools, etc.) when plugins are present

## What This Plugin Does Not Do

- Contain business rules or workflows
- Implement autonomy tiers or execution logic
- Decide whether actions are allowed
- Call third-party systems directly
- Bypass the framework's tool contracts

**If logic is required, it belongs in the Digital Employee Framework, not here.**

## Development & Testing

Quick start:

```bash
php tests/run.php          # Unit tests (no Docker needed)
npm run env:start          # Start WordPress Docker environment
npm run smoke              # Smoke test on latest WP
npm run env:stop           # Stop containers
gitleaks detect            # Secret scanning
```

See [docs/TESTING.md](docs/TESTING.md) for the full testing policy, all npm scripts, and the PR Gate / compatibility / static-check tiers.

## Security Model

- All authority lives server-side in the framework
- WordPress acts as a client and UI surface only
- No secrets are hard-coded
- All requests are authenticated and scoped

## Repository Boundaries

- This repository is part of the a3rev-ai organization
- Framework code lives in: `digital-employee-framework`
- Client-specific plugins and modules live in private repositories
- This repo must remain reusable across clients

## Contribution Rules

- Changes must not introduce business logic
- Any request for "just handling it here" is a red flag
- Refactors must not expand responsibility
- Architecture questions belong in the framework repo

## Status

- Active development
- Architecture frozen
- Authority layer enforced by the framework

## License

Private, proprietary software. No rights are granted without explicit agreement.
