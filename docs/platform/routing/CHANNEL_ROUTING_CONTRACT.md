CHANNEL_ROUTING_CONTRACT.md

Path:
/docs/platform/routing/CHANNEL_ROUTING_CONTRACT.md


# Digital Employee Framework
## Channel Routing Contract (Authoritative)

**Audience:** DEF backend developers (Python app), WordPress bridge developers  
**Purpose:** Define how requests are routed to the correct Channel and Employee  
**Status:** Required (v1)

---

## 1. What Channel Routing Is

Channel routing is the process that determines:

1. **Which Channel** a request belongs to
2. **Which Digital Employee** should handle it
3. **Which knowledge and tools** are allowed to be used

Routing MUST occur before:
- any document retrieval
- any tool execution
- any response generation

Routing decisions are authoritative and must not be overridden downstream.

---

## 2. Channels Defined (v1)

The Digital Employee Framework supports the following Channels in v1:

### 2.1 Customer Channel
- Used by website visitors and logged-in customers
- Typical interfaces:
  - frontend chatbot widget
  - support chat
- Employees:
  - Sales Assistant
  - Support Assistant

---

### 2.2 Staff AI Channel
- Used by authenticated internal users (staff and management)
- Typical interfaces:
  - staff-only web UI (ChatGPT-style)
- Employees:
  - Staff Knowledge Assistant
  - Management Knowledge Assistant

---

### 2.3 Setup Assistant Channel
- Used by DEF admins inside wp-admin
- Typical interfaces:
  - DEF dashboard assistant drawer
- Employees:
  - Setup Assistant (single-purpose)

---

## 3. Channel Identification Rules

The channel MUST be identified using explicit context supplied by the caller.

Examples:
- Frontend widget → `customer`
- Staff AI page → `staff_ai`
- DEF admin UI → `setup_assistant`

Channel MUST NOT be inferred from:
- URL heuristics alone
- prompt text
- user intent guessing

---

## 4. Routing Inputs (Required)

Every routing decision MUST consider the following inputs:

- Channel identifier
- Authenticated user (if any)
- User DEF capabilities (if authenticated)
- Request source (frontend, staff UI, admin UI)
- Optional: explicit Employee override (future)

---

## 5. Capability-Based Routing (Authoritative)

Capabilities are evaluated **before Employee selection**.

### 5.1 Customer Channel
- No DEF capabilities required
- User may be anonymous or logged in
- Routing is based on customer context only

---

### 5.2 Staff AI Channel

Routing rules:

1. If user is not authenticated → deny access
2. If user has `def_management_access`:
   - Route to **Management Knowledge Assistant**
3. Else if user has `def_staff_access`:
   - Route to **Staff Knowledge Assistant**
4. Else:
   - Deny access

Notes:
- `def_management_access` implicitly includes staff-level access
- Users must never be routed to Staff AI Employees without capabilities

---

### 5.3 Setup Assistant Channel

Routing rules:

1. User must have `def_dashboard_access`
2. Route to **Setup Assistant**
3. No alternative Employees exist in this channel

Important:
- Setup Assistant access is automatic for all dashboard users
- No separate capability is required

---

## 6. Employee Selection Rules

Once routed to a Channel:

- The selected Employee becomes the **enforcement unit**
- The Employee determines:
  - which documents may be retrieved
  - which tools may be invoked
  - which escalation paths are available

Downstream systems MUST NOT:
- change the Employee
- expand document visibility
- bypass tool restrictions

---

## 7. Document Visibility Enforcement (Reference)

Routing determines Employee identity only.

Document access is enforced separately and must comply with:

- `DOCUMENT_PERMISSIONS_TO_EMPLOYEES.md`
- Visibility levels:
  - `public`
  - `staff`
  - `management`

Employee → visibility mapping:
- Customer Employees → `public`
- Staff Knowledge Assistant → `public`, `staff`
- Management Knowledge Assistant → `public`, `staff`, `management`

---

## 8. Tool Invocation Rules (High-Level)

- Tools available depend on:
  - Channel
  - Employee
- Tools MUST NOT be invoked outside their allowed Channel
- Tools MUST re-check Employee context before execution

(See `TOOL_REGISTRY_CONTRACT.md` for details.)

---

## 9. Failure Modes (Required Behaviour)

If routing cannot be completed safely:

- Access MUST be denied
- A clear, non-sensitive error MUST be returned
- The system MUST NOT fall back to another Channel or Employee

Fail closed, not open.

---

## 10. Non-Negotiable Rules

- Channel routing happens exactly once per request
- Capabilities are authoritative for Staff AI routing
- Employees are authoritative for permissions
- No implicit escalation of access is permitted

---

## 11. Related Documents

- `DEF_CAPABILITIES_AND_ROLES.md`
- `DOCUMENT_PERMISSIONS_TO_EMPLOYEES.md`
- `STAFF_AI_CHANNEL_OVERVIEW.md`
- `SETUP_ASSISTANT_PROMPT_CONTRACT.md`
- `TOOL_REGISTRY_CONTRACT.md`

---

## 12. Versioning

- v1.0 — Initial authoritative routing contract
- Future changes must preserve backward compatibility or explicitly bump major version
