PRIVACY_POSTURE.md

Path:
/docs/platform/PRIVACY_POSTURE.md

# DEF Privacy & GDPR Posture (Short Note)

DEF is designed with privacy-by-design principles aligned with GDPR and common regulated-industry expectations.

## Core principles
- **Data minimisation:** DEF stores only what it needs to operate safely.
- **Purpose limitation:** Support and escalation data is used only for support and escalation workflows.
- **Least privilege:** Access to internal data and tools is governed by capabilities and boundaries.
- **Transparency:** Security & Access logs provide auditable, read-only event records.

## Conversations and transcripts
- DEF **does not store full chat transcripts in platform logs by default**.
- Full transcripts may be:
  - included in **escalation payloads** (email/ticket/CRM) when configured, and/or
  - provided to the **user on request** via transcript download.

## Logging
- DEF logs are **event-based** (e.g., access checks, escalations, outcomes) and designed to avoid sensitive data.
- Contact details are **masked in logs**.
- Logs are **immutable** via UI and are subject to automatic retention limits.

## Retention
- DEF enforces retention limits to prevent unbounded storage.
- Retention applies to DEF logs only, not external systems (email/helpdesk/CRM).

## Future “Insights” features
Any future analytics/insights features should prefer **derived signals** (counts, tags, failure reasons) over raw transcript storage, unless explicitly enabled with warnings and short retention.

> This note is not legal advice. Partners and customers remain responsible for configuring DEF in accordance with their policies and applicable laws.
