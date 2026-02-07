Suggested Path:
/docs/platform/api/ESCALATION_EMAIL_BRIDGE_API_CONTRACT.md


# Escalation Email Bridge — API Contract (Authoritative)

## Purpose
Defines the def-core REST API endpoints used by the DEF Python app to:
1) Retrieve channel-scoped escalation settings
2) Send escalation emails via WordPress (`wp_mail()`)

This avoids re-implementing SMTP providers in Python and allows site-local deliverability controls.

---

## Authentication (Required)
All endpoints MUST require authentication.

Preferred:
- reuse existing def-core API auth scheme (shared secret/JWT/header)

Minimum acceptable:
- shared secret header validated server-side

If auth is missing or invalid:
- return HTTP 401 or 403

---

## Channel IDs (v1)
Allowed `channel` values:
- `customer`
- `staff_ai`
- `setup_assistant`

If invalid or missing:
- return HTTP 400 with JSON error.

---

## Endpoint A — Get Escalation Settings

### Request
`GET /wp-json/a3-ai/v1/settings/escalation?channel=<channel_id>`

### Response (Success 200)
```json
{
  "channel": "customer",
  "to": ["admin@example.com"],
  "cc": [],
  "bcc": [],
  "sender_email": "no-reply@example.com",
  "send_copy_to_user": false,
  "include_transcript": false,
  "reply_to_mode": "user_email",
  "allowed_recipients": null
}

### Field Rules

- to/cc/bcc are arrays of emails (may be empty)
- sender_email must be a valid email (prefer same domain)
- send_copy_to_user boolean
- include_transcript boolean
- reply_to_mode one of:
  - user_email
  - none
- allowed_recipients:
  - null for customer and setup_assistant
  - array of allowed recipient emails for staff_ai

### Defaults
- Customer + Setup Assistant:
  - default to = WP admin_email
- Staff AI:
  - default allowed_recipients = [WP admin_email]
- Customer reply-to mode MUST be user_email

### Response (Errors)
400:
`{ "error": "INVALID_CHANNEL", "message": "Invalid channel value." }`

401/403::
`{ "error": "UNAUTHORIZED", "message": "Authentication required." }`

---

## Endpoint B — Send Escalation Email

### Request
`POST /wp-json/a3-ai/v1/escalation/send-email`

```json
{
  "channel": "customer",
  "to": ["admin@example.com"],
  "cc": [],
  "bcc": [],
  "sender_email": "no-reply@example.com",
  "reply_to": "customer@example.com",
  "subject": "Escalation: Customer Chat",
  "body": "Plain text or HTML",
  "send_copy_to_user": false,
  "user_copy_email": "customer@example.com"
}

### Field Rules

- channel required
- subject required
- body required
- `to` optional:
  - if omitted, def-core MUST populate from stored settings for the channel
- `cc/bcc` optional:
  - if omitted, def-core MAY populate from stored settings
- `sender_email` optional:
  - if omitted, def-core MUST populate from stored settings
- `reply_to` optional:
  - if present, def-core MUST set Reply-To header
  - Customer channel MUST pass reply_to=user email (Python responsibility)
- `send_copy_to_user` optional:
  - if true and `user_copy_email` provided, def-core sends a second email copy to user
  - “Copy:” prefix must be applied to subject in the user copy

### Response (Success 200)
```json
{ "status": "sent" }

### Response (Failure 500)
```json
{ "status": "failed", "error": "wp_mail failed" }

### Response (Errors 400)
```json
{ "error": "VALIDATION_ERROR", "message": "Missing subject/body or invalid email." }

---

## Logging Notes

- def-core should avoid logging raw email body or transcript content
- Python is responsible for escalation event logging per LOGGING_RULES

---

## Non-Negotiable Rules

- Recipients are admin-configured; user cannot override To/CC/BCC
- Customer channel Reply-To must be the customer’s email
- Staff AI recipients must be constrained to `allowed_recipients`
- Endpoint must be stable and backward compatible

