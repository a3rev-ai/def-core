# STAFF AI — Tool Search Retrofit Prompt (v1)

You are modifying an existing DEF Python application.
This is a **retrofit**, not a greenfield build.

Your task is to implement **Tool Search + Selective Tool Injection**
according to the authoritative DEF contracts.

---

## 🔒 Implementation Constraints (Mandatory)

You MUST follow these constraints exactly:

- Modify existing files only unless explicitly required
- Do NOT introduce new directories unless they already exist
- Do NOT rewrite routing, channel logic, or escalation logic
- Do NOT change tool execution semantics
- Do NOT inject all tools into the LLM context
- Stop after producing the minimal diff required
- If uncertain, ask for clarification instead of guessing

---

## 📚 Authoritative Reference Documents (Open These)

You MUST open and follow these documents exactly:

1. `docs/platform/tools/DEF_TOOL_REGISTRY_V1.md`
2. `docs/platform/routing/CHANNEL_ROUTING_CONTRACT.md`
3. `docs/platform/security/DEF_CAPABILITIES_AND_ROLES.md`
4. `docs/channels/staff/STAFF_AI_CHANNEL_OVERVIEW.md`
5. `docs/channels/staff/STAFF_AI_TOOLSET_V1.md`

If there is any conflict:
**Tool Registry → Routing Contract → Channel Overview wins.**

---

## 🎯 Objective

Retrofit **Tool Search** into the Staff AI channel so that:

- Tools are NOT all injected into the LLM
- Only a small, relevant subset of tools (1–3) is injected
- Tool eligibility is enforced by:
  - channel
  - activation state
  - user capabilities
- Execution rules remain unchanged

---

## ✅ Required Behaviour

### 1. Tool Search Phase (New Logic)

Before preparing an LLM request:

- Load registered tools (metadata only)
- Filter tools by:
  - channel = `staff_ai`
  - enabled = true
  - required capabilities ⊆ user capabilities
- Rank tools by relevance to the user message
  - Simple keyword match on name + description is sufficient for v1
- Select top 1–3 tools only

---

### 2. Selective Tool Injection

Only tools returned from Tool Search may be injected into the LLM context.

Injected tool metadata MUST be minimal:
- tool_id
- name
- description
- minimal input schema summary
- explicit invocation rule

Do NOT inject:
- full schemas
- unrelated tools
- disabled tools

If no tools match:
- Inject an empty tool list
- Proceed with pure text response

---

### 3. Execution Enforcement (Unchanged but Re-checked)

When `/tools/invoke` is called:

- Re-validate:
  - tool exists
  - tool enabled
  - channel matches
  - user capabilities sufficient
- Execute server-side only
- Log invocation as before

---

## 🧪 Verification Checklist

After implementation:

- Staff AI chat with no tool-relevant prompt injects **zero tools**
- “Create a document” injects only `document_create`
- Tools unavailable to the user never appear
- Tool execution still works exactly as before
- No customer or setup channels are affected

---

## 🚫 Do Not Do (Hard Guardrails)

- Do NOT add new tools
- Do NOT change tool IDs
- Do NOT change endpoints
- Do NOT move tool code
- Do NOT implement billing, quotas, or approvals
- Do NOT optimise prematurely
- Do NOT refactor unrelated logic

---

## 🛑 Stop Condition

Once Tool Search + Selective Injection works for **Staff AI only**, stop.
Do NOT continue beyond the scope defined above.

---

END OF PROMPT

Path
/docs/ai-prompts/staff-ai/STAFF_AI_TOOL_SEARCH_RETROFIT_PROMPT.md
