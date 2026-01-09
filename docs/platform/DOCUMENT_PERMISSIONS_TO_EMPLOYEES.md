# Digital Employee Framework
## Document Permissions → Employees Mapping (Authoritative)

**Audience:** DEF architects & backend developers  
**Purpose:** Define how internal knowledge visibility is enforced for Staff AI  
**Status:** Required (v1)

---

## 1. Core Principle (Non-Negotiable)

**Document access is enforced at retrieval time by Employee context — never by WordPress roles or UI state.**

- Capabilities determine *which Employee a user is routed to*
- Employees determine *which documents may be retrieved*
- WordPress roles are irrelevant to document visibility

This design prevents accidental disclosure caused by:
- custom admin roles
- agency administrators
- non-standard WordPress setups

---

## 2. Content Visibility Levels (v1)

Every knowledge item (document, page, chunk, record) MUST be tagged with exactly one visibility level:

- `public`      — customer-visible content
- `staff`       — internal staff-only content
- `management`  — restricted management-only content

### 2.1 Visibility Inheritance Rules

- Content marked as `public` is readable by:
  - Customer-facing Digital Employees
  - Staff Knowledge Assistant
  - Management Knowledge Assistant

- `staff` content is readable by:
  - Staff Knowledge Assistant
  - Management Knowledge Assistant

- `management` content is readable by:
  - Management Knowledge Assistant only


### 2.2 Safety Default
If a document or chunk is missing a visibility tag, it MUST be treated as **`management`**.

---

## 3. Employees → Allowed Visibility Matrix

### Customer-Facing Employees
- Allowed visibility:
  - `public`
- MUST NEVER retrieve:
  - `staff`
  - `management`

---

### Staff Knowledge Assistant
- Allowed visibility:
  - `public`
  - `staff`
- MUST NEVER retrieve:
  - `management`

### Management Knowledge Assistant
- Allowed visibility:
  - `public`
  - `staff`
  - `management`

---

## 4. Enforcement Model (Required)

All access control MUST be enforced at **retrieval time**.

Rules:
- Vector queries MUST include a visibility filter
- Tool-based retrieval MUST apply the same filter
- Post-retrieval filtering is NOT permitted

Filtering inputs are:
- Employee identity (Staff vs Management)
- Employee’s allowed visibility levels

UI state MUST NOT influence retrieval results.

---

## 5. Capability-Driven Employee Routing (Context)

This document assumes routing has already occurred.

Routing is defined elsewhere:
- User capabilities (`def_staff_access`, `def_management_access`)
- Channel routing logic
- Employee selection

Once routed:
- The Employee identity is authoritative
- Document permissions are enforced strictly by visibility level

---

## 6. Required Metadata Contract (v1)

Every document or chunk indexed for Staff AI MUST include:

- `doc_id`
- `title`
- `source_type` (upload, url, drive, sharepoint, etc.)
- `access_level` (`public`, `staff`, or `management`)
- `updated_at`

Note: `public` content is shared across Customer, Staff, and Management channels
and is always readable by internal Employees.

Optional (future-safe):
- `department`
- `owner`
- `source_path`

---

## 7. Guardrails & Safety Rules

- No Employee may bypass visibility rules
- No “super user” access by default
- No inheritance from WordPress Administrator role
- No manual visibility override in Staff AI UI
- Missing metadata MUST result in restricted access

All failure modes MUST favor **non-disclosure**.

---

## 8. Future Extensions (Not v1)

This model is intentionally simple for v1.

Planned future extensions may include:
- department-scoped visibility
- project-based access
- per-user access rules

These MUST extend — not replace — the v1 visibility model.

---
