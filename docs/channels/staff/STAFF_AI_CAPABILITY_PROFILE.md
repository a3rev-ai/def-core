Repo Path:
/docs/channels/staff/STAFF_AI_CAPABILITY_PROFILE.md


STAFF_AI_CAPABILITY_PROFILE.md

# Digital Employee Framework  
## Staff AI Capability Profile (Authoritative)

**Channel:** Staff AI  
**Applies to:**  
- Staff Knowledge Assistant  
- Management Knowledge Assistant  

---

## 1. Purpose

The Staff AI Channel is a **general-purpose AI work assistant** for internal staff and management.

Unlike Customer Chatbots and the Setup Assistant, Staff AI is intentionally broad:
- It supports document retrieval **and**
- Provides general LLM-powered work capabilities

The goal is adoption: Staff AI should feel comparable to ChatGPT / Copilot,
but grounded in company knowledge and DEF governance.

---

## 2. Capability Scope by Channel

| Capability | Customer Chat | Setup Assistant | Staff AI |
|----------|---------------|-----------------|----------|
| Public knowledge retrieval | ✅ | ❌ | ✅ |
| Staff knowledge retrieval | ❌ | ❌ | ✅ |
| Management-only knowledge | ❌ | ❌ | ✅ (management only) |
| General writing / ideation | ❌ | ❌ | ✅ |
| Document generation | ❌ | ❌ | ✅ |
| Spreadsheet generation | ❌ | ❌ | ✅ |
| Image generation | ❌ | ❌ | ✅ |
| Business tools (CRM, tickets, orders) | ❌ | ❌ | ✅ (configured tools only) |
| Memory / preferences | ❌ | ❌ | ✅ (opt-in, per user) |
| Human escalation | ✅ | ✅ | ✅ |

Customer Chat and Setup Assistant are **bounded assistants**.  
Staff AI is a **work assistant**.

---

## 3. Knowledge Access Rules (Non-Negotiable)

Staff AI retrieval MUST follow DEF access rules:

- Staff users:
  - `public`
  - `staff`
- Management users:
  - `public`
  - `staff`
  - `management`

No Staff AI user may retrieve content above their access level.

---

## 4. General LLM Capabilities (Allowed)

Staff AI MAY perform:

- Writing and editing:
  - emails
  - documents
  - SOPs
  - proposals
  - policies
- Analysis and planning:
  - summaries
  - comparisons
  - outlines
  - task planning
- Creative work:
  - brainstorming
  - copywriting
  - image generation

These capabilities are **not restricted to retrieved documents**.

---

## 5. Tools vs Free Text

All non-trivial actions MUST be performed via **tools**, not raw model output.

Examples:
- File creation → document/spreadsheet/image tool
- CRM lookup → CRM tool
- Ticket creation → support tool

Free-text responses are allowed only for:
- explanation
- ideation
- drafting prior to tool execution

---

## 6. User Context & Memory

Staff AI operates with:
- per-user identity
- per-user conversation history
- optional per-user memory (future)

Memory MUST:
- be opt-in
- be user-visible
- be clearable
- respect access level boundaries

---

## 7. Safety & Guardrails

Staff AI MUST:
- never leak management-only content to staff
- never act on behalf of the business without explicit tool invocation
- always respect escalation rules

---

## 8. Summary

Staff AI is:
- a **general-purpose AI work assistant**
- governed by DEF access, logging, and escalation rules
- intentionally more powerful than Customer and Setup channels

This distinction is foundational to DEF.
