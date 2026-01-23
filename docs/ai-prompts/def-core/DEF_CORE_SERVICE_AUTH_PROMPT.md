# def-core-Service-Auth-Token-(Shared-Secret)-for-Python-Calls-(Minimal Diff)

## Objective
Add a **service-to-service authentication mechanism** in def-core so that the DEF Python app
can call protected WordPress REST endpoints **even when no WP user is logged in**.

This is required for:
- GET /wp-json/a3-ai/v1/settings/escalation?channel=...
- POST /wp-json/a3-ai/v1/escalation/send-email

These endpoints MUST NOT depend on WP user login.

---

## 🔒 Implementation Constraints (Mandatory)
- Modify existing files only unless explicitly instructed
- Do NOT create new directories
- Minimal diff only
- Do NOT change existing logged-in user JWT flow (leave it intact)
- Add a **separate** service-auth path
- Do NOT expose the secret in logs or responses

---

## Reference Docs to Open in Cursor Tabs
- docs/platform/api/ESCALATION_EMAIL_BRIDGE_API_CONTRACT.md
- CUSTOMER-CHAT-BACKEND-RUNBOOK.md (if present)
- This prompt

---

## Requirements

### 1) Shared Secret Storage (wp_options)
Create/get a shared secret stored in WP options:
- option key: `def_service_auth_secret`
- if missing on first run, generate a strong random string and save

Secret must be stable across requests until rotated.

### 2) Header-based Auth
Python will send:
- Header: `X-DEF-AUTH: <secret>`

Implement a validator:
- Read `X-DEF-AUTH`
- Compare to stored `def_service_auth_secret`
- If missing/invalid: return 401/403

### 3) Apply Auth to Escalation Bridge Endpoints Only
Enforce service auth for:
- GET settings escalation endpoint
- POST send-email endpoint

Do NOT require WP login for these endpoints.
Do NOT weaken other endpoints.

### 4) Optional Rotation (not required)
Do not implement rotation UI now unless it already exists.
Just ensure the secret is generated and validatable.

---

## Deliverables (Minimal)
- A helper function/class such as:
  - `def_get_service_secret()`
  - `def_validate_service_auth($request)`
- Update the two REST route handlers to call the validator early:
  - if fail → return WP_Error with 401/403

---

## Acceptance Criteria
- Anonymous customer escalation can succeed because Python can still call:
  - GET settings
  - POST send-email
- WP endpoints remain protected from public calls
- No reliance on WP user authentication for these endpoints
- No secret printed in logs

---

## Stop Condition
Stop after:
- shared secret exists in wp_options
- validator works
- endpoints require service auth header
