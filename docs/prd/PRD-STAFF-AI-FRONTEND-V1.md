# PRD — Staff AI Frontend v1
Product: Digital Employee Framework (DEF)
Component: Staff_AI Frontend
Version: v1
Audience: Claude Code + Ralph Wiggum (agent execution)

---

## 1. Goal

Implement the **Staff AI frontend** inside the `def-core` WordPress plugin as a
**full-screen ChatGPT-style application**, acting only as a UI + gateway to the
existing Python backend.

The backend is already implemented and is the **single source of truth** for:
- conversations
- messages
- tools
- escalation
- permissions
- logging

This PRD covers **frontend only**.

---

## 2. Definition of Done (Hard Gates)

The Staff AI frontend v1 is complete when:

- `/staff-ai` endpoint renders as a full-screen application
- WordPress theme header/footer are NOT loaded
- Authentication gate enforced
- Capability gate enforced:
  - `def_staff_access` OR `def_management_access`
- Chat UI renders and functions end-to-end
- Conversations load/save via Python API
- Conversation history is per-user and private by default
- Sharing is read-only by default
- Tool outputs render as downloadable cards
- Escalation can be triggered and is **non-terminal**
- No chat transcripts are stored in WordPress
- No backend contracts are modified

---

## 3. Architecture (Non-Negotiable)

- **Python app** = source of truth  
- **Browser** = UI state only  
- **WordPress** = auth + routing gateway only  

Explicit prohibitions:
- No PHP sessions
- No WP transients for chat
- No storing transcripts in WP tables
- No role logic via WP roles (capabilities only)

---

## 4. Scope (MUST)

### 4.1 Endpoint & Access
- Stable endpoint: `/staff-ai`
- Redirect unauthenticated users to WP login, then back
- Access denied screen for authenticated users without capability
- Minimal UI shell rendered first

### 4.2 UI Layout (ChatGPT-style)
- Left sidebar:
  - “New chat”
  - Conversation list (per-user)
- Main panel:
  - Message stream
  - Composer (multiline)
- Assistant identity label:
  - “Staff Knowledge Assistant” OR
  - “Management Knowledge Assistant”

### 4.3 Conversations
- Create, load, resume conversations
- Conversation metadata may be stored minimally in WP
- Message history stored **only** in Python backend

### 4.4 Sharing
- Explicit user action
- Read-only by default
- Logged as access events

### 4.5 Tools
- Render backend tool outputs as cards:
  - file name
  - file type
  - download link
- No raw file content stored client-side

### 4.6 Escalation
- “Escalate for review” UI
- Sends escalation request to backend
- Conversation remains active (non-terminal)
- Confirmation banner shown

### 4.7 Branding
- Header: site logo or site name
- Footer: “Powered by DEF”

---

## 5. Non-Goals (Explicit)

The Staff AI frontend must NOT:

- Act as sales or customer support
- Auto-share conversations
- Allow role switching
- Persist transcripts in WordPress
- Invent backend endpoints or payloads
- Introduce new directories or architectures
- Add UI elements not explicitly required

---

## 6. Constraints (Hard Guardrails)

- Modify existing files unless explicitly required
- Produce smallest possible diff
- No speculative abstractions
- No refactors outside scope
- Stop and ask if integration point is unclear

---

## 7. Acceptance Signals (Objective)

- Visiting `/staff-ai` unauthenticated shows login form
- Authenticated without capability shows access denied
- Authenticated with capability loads UI
- Messages send and receive successfully
- History list reflects backend state
- Escalation submits and confirms
- No WP theme elements present
- No PHP errors or JS console errors

---

## 8. External Authorities (Reference Only)

The following documents are authoritative but must NOT be reimplemented:
- Channel routing
- Capability definitions
- Escalation semantics
- Logging & privacy rules

All such rules are already enforced by the backend.

---

END PRD
