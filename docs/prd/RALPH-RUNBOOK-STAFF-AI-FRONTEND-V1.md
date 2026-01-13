# Ralph Wiggum Runbook — Staff AI Frontend v1
Execution Mode: Bounded autonomous loops
Agent: Claude Code + Ralph Wiggum
Target: def-core WordPress plugin (frontend only)

---

## Global Rules

- Python backend is authoritative
- WordPress is gateway only
- Do NOT invent APIs
- Do NOT store transcripts in WordPress
- Do NOT create new directories unless already present
- Stop immediately if constraints conflict

Each loop MUST:
- Modify only necessary files
- Verify behaviour
- Emit completion token
- Stop when token emitted

---

## LOOP 1 — Endpoint + Auth Gate

Objective:
- `/staff-ai` endpoint exists
- Login + capability gating enforced
- Full-screen shell renders without theme

Allowed Changes:
- Existing routing/template files only

Verification:
- Unauthenticated → login form
- Authenticated w/o capability → access denied
- Authenticated w/ capability → empty shell renders

Completion Token:
- STAFF_AI_F1_COMPLETE

Max Iterations:
- 10

---

## LOOP 2 — Chat UI (ChatGPT-style)

Objective:
- ChatGPT-like UI renders
- Sidebar + composer + message list
- Basic send/receive works

Allowed Changes:
- Existing JS/CSS assets only

Verification:
- UI renders on `/staff-ai`
- Messages send and display
- Assistant identity label visible

Completion Token:
- STAFF_AI_F2_COMPLETE

Max Iterations:
- 15

---

## LOOP 3 — History, Tools & Escalation UI

Objective:
- Conversation list loads
- Tool outputs render as cards
- Escalation UI submits successfully

Allowed Changes:
- UI components + minimal WP metadata storage

Verification:
- Conversations list per user
- Tool outputs downloadable
- Escalation confirmation shown
- Conversation remains usable

Completion Token:
- STAFF_AI_F3_COMPLETE

Max Iterations:
- 20

---

## LOOP 4 — Frontend Adapter (WP ↔ Python)

Objective:
- WP adapter calls Python API correctly
- User context + capabilities passed
- Errors surfaced cleanly

Allowed Changes:
- Existing PHP services / JS adapters

Verification:
- list/create/load/send endpoints function
- Access denied locally when missing capability
- No persistence in WP

Completion Token:
- STAFF_AI_F4_COMPLETE

Max Iterations:
- 15

---

## LOOP 5 — Full Integration & Hardening

Objective:
- All UI actions wired end-to-end
- No regressions
- Clean build

Allowed Changes:
- Bug fixes only

Verification:
- All v1 acceptance criteria met
- No console or PHP errors
- Behaviour matches PRD

Completion Token:
- STAFF_AI_F5_COMPLETE

Max Iterations:
- 10

---

## STOP CONDITIONS (Immediate Abort)

- Attempt to invent backend logic
- Attempt to store transcripts in WordPress
- Attempt to modify API contracts
- Attempt to refactor unrelated code
- Infinite loop without progress

---

END RUNBOOK
