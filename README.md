# Digital Employee Framework - Core

## Purpose

This repository contains the WordPress bridge plugin for the Digital Employee Framework.

The bridge provides:
- Authentication between WordPress and the Digital Employee Framework
- UI integration for chat and internal tools
- Request routing from WordPress to the framework API

**This plugin does not implement business logic, autonomy rules, or execution authority. All decision-making and actions are governed exclusively by the Digital Employee Framework.**

## What This Plugin Is

- A delivery and integration layer for WordPress
- A secure client for the Digital Employee Framework API
- A UI surface for customer-facing and staff-facing experiences

## What This Plugin Is Not (Non-Negotiable)

This plugin must not:
- Contain business rules or workflows
- Implement autonomy tiers or execution logic
- Decide whether actions are allowed
- Call third-party systems directly
- Bypass the framework's tool contracts
- Persist operational state outside WordPress UI needs

**If logic is required, it belongs in the Digital Employee Framework, not here.**

## Architecture Overview

```
WordPress (UI + Auth Context)
        ↓
WordPress Bridge Plugin
        ↓
Digital Employee Framework (Authority, Tools, Execution)
```

**The bridge is intentionally thin.**

## Responsibilities

The bridge plugin is responsible for:
- JWT handling and session context
- Secure API communication with the framework
- Rendering chat or assistant UI components
- Passing user identity, role, and tenant context
- Displaying results returned by the framework

## Explicit Non-Responsibilities

The bridge plugin does not:
- Make decisions
- Execute actions
- Modify business data
- Enforce autonomy rules
- Log or audit execution (handled by the framework)

## Security Model

- All authority lives server-side in the framework
- WordPress acts as a client and UI surface only
- No secrets are hard-coded
- All requests are authenticated and scoped

## Repository Boundaries

- This repository is part of the a3rev-ai organization.
- Framework code lives in: `digital-employee-framework`
- Client-specific plugins and addons live in private repositories
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
