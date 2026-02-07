# STAFF_AI_CHAT_TOOL_INVOCATION_FIX_PROMPT.md

## 🎯 Objective (Non-Negotiable)

Enable **natural-language tool invocation** for Staff AI so that requests like:

> “Create a Word document named ‘test’ with heading ‘hello’ and body ‘Xin Chao!’”

automatically invoke the appropriate Staff AI tool and return a **downloadable file** in chat.

The existing **Create modal UI must remain**, but it must NOT be the only way to create files.

This is a **behavioural correction**, not a redesign.

---

## 🧠 Authoritative References (Open These in Cursor Tabs)

You MUST follow these documents exactly:

- `STAFF_AI_TOOLSET_V1.md`
- `DEF_TOOL_REGISTRY_V1.md`
- `CHANNEL_ROUTING_CONTRACT.md`
- `STAFF_AI_CHANNEL_OVERVIEW.md`

If any instruction conflicts, these documents win.

---

## 🧩 Current Problem (Context)

- Staff AI tools **work**, but only when manually triggered via the “Create” modal.
- When users ask in chat to create a document, the assistant replies:
  > “I’m unable to create or save files directly…”
- This means **tools are not being selected or invoked from chat messages**.
- This is incorrect for Staff AI.

---

## ✅ Required Behaviour (What You MUST Implement)

### 1. Tool Invocation from Chat (Core Fix)

When a Staff AI chat message implies creation of:
- documents (DOCX / PDF / Markdown)
- spreadsheets (XLSX / CSV)
- images

The system MUST:

1. Detect the creation intent
2. Select the correct tool from `STAFF_AI_TOOLSET_V1`
3. Invoke the tool explicitly
4. Return a **success message + download link/card** in chat

This must work **without** the user clicking the Create modal.

---

### 2. Assistant Behaviour Rules

The Staff AI assistant MUST:
- Prefer tool execution over explanatory text
- NEVER respond with “I can’t create files” when a tool is available
- Use tools explicitly (as per Tool Registry contract)
- Show tool output to the user

A short confirmation step (“I’ll create X — OK?”) is allowed but optional.

---

### 3. Tool Availability Injection

Ensure that for Staff AI chat requests:
- Available tools are injected into the model context
- Tool search / selection happens **before** response generation
- Only tools enabled in def-core are eligible

Do NOT hardcode tools.

---

## 🛠️ Implementation Scope (Minimal Diff)

You MAY:
- Update the Staff AI chat handler/router
- Update the Staff AI system prompt / policy
- Add small helper functions for intent detection or tool selection

You MUST NOT:
- Redesign UI components
- Remove the Create modal
- Introduce new tools
- Change Customer or Setup Assistant behaviour
- Create new folders unless absolutely required

---

## 🔒 Hard Guardrails (DO NOT DO)

- ❌ Do NOT invent new tool schemas
- ❌ Do NOT bypass the Tool Registry
- ❌ Do NOT let the LLM “pretend” to create files
- ❌ Do NOT move file generation into the frontend
- ❌ Do NOT store files in browser memory

---

## 🧪 Acceptance Criteria (Must All Pass)

- Asking “Create a DOCX…” in chat produces a real file
- The assistant invokes `document_creation` explicitly
- A download link/card is shown in chat
- The Create modal still works as before
- No regression to existing Staff AI functionality

---

## 🛑 Stop Condition

When complete:
- Output a short summary of files changed
- Confirm that chat-based document creation works end-to-end
- STOP — do not continue refactoring

