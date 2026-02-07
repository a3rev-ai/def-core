LOGGING_RULES.md

Suggested Path:

/docs/platform/LOGGING_RULES.md

# Digital Employee Framework  
## Logging Rules & Audit Behaviour (Authoritative)

**Scope:** Platform-wide logging  
**Applies to:** All Channels, Employees, Tools, Escalation  
**UI Reference:** DEF_ADMIN_UI_PATTERNS.md  
**Security Reference:** Security & Access → Logs

---

## 1. Purpose

Logs provide:
- auditability
- transparency
- trust
- post-incident analysis

Logs are **not analytics**, **not debugging output**, and **not chat history**.

---

## 2. Ownership Model

- Logging is owned by **Security & Access**
- Logs are platform-wide
- Logs are immutable in UI

---

## 3. What Must Be Logged

The system MUST log the following events:

### Access & Security
- Channel accessed
- Capability check performed
- Access granted
- Access denied
- Data boundary enforced

### Behaviour
- Digital Employee routed
- Tool invoked
- Tool blocked

### Escalation
- Escalation triggered
- Escalation failed
- Escalation fallback used
- Test escalation sent

### Configuration
- Configuration changed
- Escalation override saved
- Capability granted / revoked

---

## 4. Required Log Fields

Every log entry MUST include:
- Event ID
- Timestamp
- Event type
- Channel
- Digital Employee (if applicable)
- Outcome (allowed / blocked / escalated / failed)

Contextual fields (as applicable):
- User (authenticated or anonymous)
- Capability involved
- Escalation method used
- Integration target (masked)

---

## 5. Sensitive Data Handling

Rules:
- Do NOT store raw AI prompts
- Do NOT store full transcripts by default
- Mask email addresses and phone numbers
- Never store secrets, tokens, or credentials

Logs must be safe to export.

---

## 6. Immutability Rules

- Logs MUST NOT be editable
- Logs MUST NOT be deletable via UI
- Logs MUST NOT be suppressed
- Log ordering must be preserved

---

## 7. Privacy & GDPR Alignment

DEF logging follows a strict data-minimisation approach. Logs are event-based and intentionally avoid storing conversational content or sensitive personal data. Where identifiers are required, they are masked. Retention limits are enforced automatically. For a broader explanation of DEF’s privacy-by-design approach and GDPR posture, see `PRIVACY_POSTURE.md`.

---

## 8. Log Retention Policy (Authoritative)

DEF logs are subject to automatic retention limits to prevent unbounded growth and reduce privacy risk.

Retention applies **only to logs stored by DEF**.  
It does **not** apply to escalation emails, tickets, CRM records, or external systems.

---

### 8.1 Default Retention (v1)

Unless otherwise specified, DEF MUST enforce the following retention limits:

- **Standard log events**
  - Retention: **30 days**
  - Includes access, routing, configuration, and capability events

- **Escalation-related metadata**
  - Retention: **7 days**
  - Includes escalation triggers, methods used, and outcomes
  - Does NOT include full transcripts

- **Full transcripts**
  - Retention: **0 days**
  - Full transcripts MUST NOT be stored in logs by default

---

### 8.2 Transcript Logging (Advanced / Explicit Only)

If transcript logging is explicitly enabled in a future release:

- Maximum retention: **7 days**
- Must display a clear warning in the UI
- Must be labelled as **sensitive data**
- Must never be enabled silently or by default

---

### 8.3 Enforcement Rules

- Log retention MUST be enforced automatically
- Cleanup MUST run as a scheduled task
- Logs older than the retention window MUST be permanently deleted
- Retention rules MUST NOT be bypassed via UI
- Retention failures MUST NOT block platform operation

---

### 8.4 Non-Negotiable Rules

- Retention applies platform-wide
- Retention cannot be disabled
- Retention settings are not escalation settings
- Retention affects logs only, not escalation payloads


## 9. Export Behaviour

- Export only filtered results
- Default format: CSV
- Export action itself must be logged
- Export must respect masking rules

---

## 10. Performance & Safety

- Logs must be paginated
- Detail payloads loaded lazily
- Table views must avoid heavy payloads

---

## 11. What Logs Are NOT

Logs must not:
- act as chat transcripts
- replace analytics dashboards
- expose internal model reasoning
- provide replay functionality

---

## 12. Non-Negotiable Rules

- Logging cannot be disabled
- Logging failures must not block platform usage
- Logging must never expose sensitive data
- Security & Access owns log visibility

---

**This document is authoritative.**  
If UI or implementation conflicts with these rules, these rules win.
