# Digital Employee Framework
## Human Escalation Rules (Authoritative)

Path:
/docs/platform/ESCALATION_RULES.md

**Scope:** Platform-wide escalation behaviour  
**Applies to:** All Channels, All Digital Employees  
**Status:** Required (v1)

Version: v1.2
Status: Authoritative
Last Updated: 2026-01-22

---

## Changelog

### v1.2 — 2026-01-22
- Removed terminal escalation from all channels
- Clarified escalation as non-terminal and user-confirmed
- Added explicit escalation trigger policy (no hallucination, transparency-first)

### v1.0 — 2026-01-12
- Initial escalation rules formalised


---

## 1. Purpose

Human Escalation ensures users are never blocked by AI limitations.
Escalation is a platform safety feature and must always be available.

---

## 2. Ownership & Overrides

- Platform defaults are configured under Security & Access → Human Escalation.
- Employees may optionally override escalation targets/method.
- Channels must not override escalation behaviour.
- Email is always the safe fallback.

---

## 3. Escalation Hierarchy (Non-Negotiable)

Escalation MUST follow this order:

1) Employee-level override (if defined)  
2) Platform defaults  
3) Email fallback (always)

Escalation MUST NOT be disabled entirely.

---

## 4. Methods (v1)

- Email escalation is mandatory and must work immediately after install.
- Other methods (ticketing, Teams/Slack, CRM) are future/optional and must fall back to email on failure.

---

## 5. Anonymous Escalation Required Fields

If escalation is triggered by an anonymous user, require:
- First name (required)
- Last name (required)
- Email (required)
- Telephone (required)

Escalation must not proceed without all fields.

---

## 6. Anonymous Customer Escalation — Recipient Fallback (Authoritative)

Customer Channel escalation supports anonymous users, but anonymous callers cannot rely on fetching escalation recipients from authenticated def-core settings endpoints.

Therefore, anonymous escalation MUST resolve recipients using the following priority:

1) **def-core configured recipients** (if accessible and returned successfully)
2) **Anonymous fallback recipient** (required)
   - A site-level fallback support email must be configured for anonymous escalations
   - This can be provided via environment variable or server config (e.g., `ESCALATION_FALLBACK_EMAIL`)
3) If no recipients are available:
   - Escalation MUST fail with `no_recipients`
   - UI must show a clear error: “Support email not configured.”

Rules:
- The fallback recipient is used **only for anonymous customer escalation**
- Authenticated escalation must continue to use def-core configured recipients
- This policy does not weaken def-core settings endpoint authentication


## 7. Payload Requirements

Escalation payload MUST include:
- Channel
- Employee name
- Reason for escalation
- AI-generated summary (required)
- Timestamp

Optional (payload):
- Full transcript (if enabled)
- Full user contact details

Logging rules:
- Contact details must be masked in logs
- Transcripts must not be stored in logs by default

---

## 8. Escalation Continuity (Authoritative)

Escalation is **non-terminal in all channels**.

After escalation:
- The conversation remains active and usable
- The user may continue chatting
- The UI must show confirmation that escalation was sent
- Escalation does not change routing, tool eligibility, or permissions

Rationale:
- Escalation is a safety and support path, not a session termination mechanism.

---

## 9. Escalation Triggers (Authoritative)

Escalation may be triggered in two ways:

### 9.1 User-Initiated Escalation (Primary)
Escalation MUST be available via UI action at all times.
If the user requests a human, escalation must proceed immediately.

### 9.2 Assistant-Suggested Escalation (Secondary)
The assistant should suggest escalation when:
- It cannot answer confidently without guessing
- It lacks required information or permissions
- A request is blocked by policy/guardrails
- The user asks for something outside scope

In these cases, the assistant MUST:
- be transparent about why it cannot proceed
- offer escalation as the next step
- avoid hallucinating or guessing

Important:
- The assistant MUST NOT silently escalate without user confirmation.
- Escalation is a user-visible action (button/confirm).

---

### Non-Negotiable Rules

- Channel terminality rules must be enforced consistently across UI and backend.
- Escalation events must always be logged.
- Email fallback remains available for all channels.


---

## 10. Logging

Escalation events must be logged (event-based):
- escalation triggered
- method used
- outcome (sent/failed)
- fallback used (yes/no)

(See LOGGING_RULES.md)

---
