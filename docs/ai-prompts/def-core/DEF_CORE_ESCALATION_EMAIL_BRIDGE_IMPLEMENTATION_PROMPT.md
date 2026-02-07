# def-core — Escalation Email Bridge Implementation (API Contract Driven)

You are implementing two def-core REST endpoints exactly as defined in:
`ESCALATION_EMAIL_BRIDGE_API_CONTRACT.md`.

Do not invent new fields, routes, or behaviours.

---

## 🔒 Implementation Constraints (Mandatory)

- Modify existing files only unless explicitly instructed to create new ones.
- Do NOT introduce new directories unless they already exist.
- Minimal diff only.
- Do NOT change chat UI.
- Do NOT implement Partner Portal logic.
- Do NOT log full email bodies/transcripts.

---

## Reference Docs to Open in Cursor Tabs (Required)

1) `docs/platform/api/ESCALATION_EMAIL_BRIDGE_API_CONTRACT.md`
2) `docs/platform/ESCALATION_RULES.md` (if present)
3) `docs/platform/LOGGING_RULES.md` (if present)
4) This prompt

---

## Objective

Implement:

A) `GET /wp-json/a3-ai/v1/settings/escalation?channel=<channel_id>`
B) `POST /wp-json/a3-ai/v1/escalation/send-email`

Both MUST require authentication (reuse existing def-core API auth mechanism if present).

---

## Required Behaviour

### A) GET Settings Endpoint
- Validate `channel` in {customer, staff_ai, setup_assistant}
- Return JSON shape exactly as contract
- Defaults:
  - customer/setup_assistant `to` default = WP `admin_email`
  - staff_ai `allowed_recipients` default = [WP `admin_email`]
  - customer `reply_to_mode` MUST be "user_email"
- `allowed_recipients`:
  - null for customer/setup_assistant
  - array for staff_ai

Settings must be stored in WP options (channel-scoped keys).

### B) POST Send Email Endpoint
- Validate required fields: channel, subject, body
- If `to` omitted, populate from stored settings for that channel
- Respect `reply_to` header if provided
- If `send_copy_to_user` true and `user_copy_email` provided:
  - send a second email with subject prefixed "Copy:"
- Use `wp_mail()` only
- Return JSON:
  - success: `{ "status": "sent" }`
  - failure: `{ "status": "failed", "error": "..." }`

---

## Auth Requirement
- Reuse existing def-core authentication pattern for WP REST routes.
- If none exists, add a simple shared secret header check (minimal).

---

## Deliverables
- REST route registration
- Settings storage (WP options)
- Email send handler
- Minimal validation + sanitization

---

## Stop Condition
Stop once both endpoints work and match the contract.
Do not implement admin UI settings screens in this prompt.
