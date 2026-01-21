# Digital Employee Framework
## Human Escalation Rules (Authoritative)

Path:
/docs/platform/ESCALATION_RULES.md

**Scope:** Platform-wide escalation behaviour  
**Applies to:** All Channels, All Digital Employees  
**Status:** Required (v1)

Version: v1.0
Status: Authoritative
Last Updated: 2026-01-12

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

## 6. Payload Requirements

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

## 7. Escalation Terminality by Channel (v1.1)

Escalation behaviour differs by channel. This is intentional.

### Customer Channel (Terminal)
- Escalation is treated as a **handoff to support**.
- Once escalated, the AI should stop actively progressing the same customer issue to avoid duplicated/conflicting responses.
- The UI should indicate escalation has occurred and guide the user to the human workflow.

### Setup Assistant Channel (Terminal)
- Escalation is treated as a **handoff to a human implementer/partner**.
- Once escalated, the Setup Assistant should stop and avoid continuing configuration guidance in the same thread.
- The UI should indicate escalation has occurred and provide next steps.

### Staff AI Channel (Non-Terminal)
- Escalation is treated as a **review / approval request**, not a support handoff.
- After escalation, the conversation **remains active and usable**.
- The UI should show a confirmation state (e.g., “Escalated for review”) while allowing the user to continue working.
- The escalation payload should preferably include a **share link** to the conversation and an AI summary.

---

### Non-Negotiable Rules

- Channel terminality rules must be enforced consistently across UI and backend.
- Escalation events must always be logged.
- Email fallback remains available for all channels.


---

## 8. Logging

Escalation events must be logged (event-based):
- escalation triggered
- method used
- outcome (sent/failed)
- fallback used (yes/no)

(See LOGGING_RULES.md)

---
