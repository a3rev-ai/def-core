STAFF_AI_CHANNEL_OVERVIEW.md

Path:
/docs/channels/staff/STAFF_AI_CHANNEL_OVERVIEW.md

# Digital Employee Framework  
## Staff AI Channel — Overview (Authoritative)

**Channel ID:** `staff`  
**Audience:** Internal staff and management  
**Status:** First-class execution channel

---

## 1. Purpose

The **Staff AI Channel** provides an authenticated, internal ChatGPT-style interface for company employees to query **private, non-public business knowledge**.

This channel is designed to:
- support staff onboarding
- answer internal “how do we do X?” questions
- provide fast access to policies, procedures, and internal documentation
- reduce reliance on managers and support teams for routine questions

The Staff AI Channel is **not** a customer chatbot and is **not** used for platform configuration.

---

## 2. Who Uses This Channel

The Staff AI Channel is used by:
- employees
- supervisors
- managers
- business owners

All users must be authenticated.

Access is controlled via DEF capabilities, not WordPress role names.

---

## 3. Why This Is a Channel (Not a Tool)

The Staff AI Channel is a **distinct execution context** with:

- required authentication
- access to private/internal data
- capability-based routing
- stricter data boundaries than customer chat
- different escalation behaviour

Because these rules must be enforced server-side, Staff AI is implemented as a **channel**, not a tool or UI feature.

---

## 4. Digital Employees Used by This Channel

The Staff AI Channel routes requests to one of two Digital Employees based on user capabilities:

### 4.1 Staff Knowledge Assistant

Used when the user has:
- `def_staff_access`

This assistant may access:
- staff-safe internal documents
- procedures and SOPs
- onboarding and training material
- general HR policies

It must **not** access management-only or sensitive documents.
Customer-facing Digital Employees must never use the Staff AI document
permission model.

---

### 4.2 Management Knowledge Assistant

Used when the user has:
- `def_management_access`

This assistant may access:
- all staff-safe content
- management-only documentation
- sensitive internal policies
- operational and strategic materials

---

## 5. How Access Is Enforced (Summary)

The Staff AI Channel does not rely on WordPress role names (Administrator, Editor, etc).

Access is enforced by DEF using two layers:
1. **DEF user capabilities** to decide which Digital Employee responds (Staff vs Management)
2. **Document access labels** applied at retrieval time to control which internal content is visible

In short:
- Users with staff access see staff-safe internal content
- Users with management access see both staff and management-only content
- These rules are enforced server-side and cannot be bypassed by UI
- Any internal document without an explicit access label is treated as
  management-only by default.

Once routing has selected a Digital Employee, that Employee’s allowed
document visibility levels are authoritative and enforced at retrieval time.

Implementation details are defined in:
- DEF_CAPABILITIES_AND_ROLES.md
- DOCUMENT_PERMISSIONS_TO_EMPLOYEES.md


## 6. Routing Logic (High Level)

At runtime, the backend resolves routing as follows:

1. Verify the user is authenticated
2. Check DEF capabilities
3. Route to:
   - Management Knowledge Assistant if `def_management_access` is present
   - Staff Knowledge Assistant if `def_staff_access` is present
4. Deny access if no valid capability exists and do not fall back to customer or public channels.

Routing is enforced by the Python API and cannot be overridden by UI.

---

## 7. User Interface

The Staff AI Channel is presented as:
- a browser-based internal chat interface
- visually similar to ChatGPT or Copilot
- accessible only to authenticated users

The UI is shared between Staff and Management users; access differences are enforced backend-side.

---

## 8. Escalation & Logging

- Human escalation is enabled for this channel
- Default escalation method is email (with fallback)
- Escalation behaviour follows `ESCALATION_RULES.md`
- Logging follows `LOGGING_RULES.md`

---

## 9. Related Documentation

This document defines **what the Staff AI Channel is**.

See also:
- `DEF_CAPABILITIES_AND_ROLES.md` — how access is granted
- `DOCUMENT_PERMISSIONS_TO_EMPLOYEES.md` — what content each assistant can see
- `CHANNEL_ROUTING_CONTRACT.md` — how channels are routed backend-side

---

## 9. Non-Negotiable Rules

- Staff AI is a channel, not a tool
- Authentication is mandatory
- Capability checks are authoritative
- UI must not bypass backend routing
- Staff and Management assistants must remain distinct

---

This document is authoritative.  
If implementation or documentation conflicts with this overview, this overview wins.
