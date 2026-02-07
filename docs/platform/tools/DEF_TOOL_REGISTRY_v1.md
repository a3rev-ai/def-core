# DEF Tool Registry — v1

**Status:** Authoritative  
**Version:** 1.0  
**Last Updated:** 2026-01-16

---

## 1. Purpose

This document defines the **authoritative tool registry model** for the Digital Employee Framework (DEF).

The Tool Registry establishes:
- How tools are **identified**
- How tools are **discovered**
- How tools are **activated / deactivated**
- How tools are **governed and enforced**
- How tools are **invoked at runtime**

This contract applies across all DEF channels and is enforced **server-side by the DEF Python control plane**.

---

## 2. What Is a Tool (Definition)

A **Tool** is a discrete, server-executed capability that:
- Performs an action beyond pure LLM text generation
- Is invoked **explicitly** (never implicitly)
- Is governed by DEF capabilities and channel scope
- Returns a structured result that must be shown to the user

Examples:
- Create a document (DOCX / PDF / Markdown)
- Generate a spreadsheet (XLSX / CSV)
- Generate an image
- Add items to cart (Customer channel)
- Fetch account/order data (Customer channel)

---

## 3. Tool Identity & Naming

### 3.1 Tool ID (Required)

Each tool MUST have a globally unique ID using the format:

def.<channel>.<tool_name>


Examples:
- `def.staff_ai.document_create`
- `def.staff_ai.spreadsheet_create`
- `def.staff_ai.image_generate`
- `def.customer.add_to_cart`

Tool IDs are **stable**, **version-agnostic**, and **never reused**.

---

## 4. Channels & Scope

Tools are always scoped to **exactly one channel**.

Supported channels (v1):

| Channel ID | Description |
|-----------|-------------|
| `staff_ai` | Internal staff & management workflows |
| `customer` | Customer-facing chat assistants |
| `setup_assistant` | Admin setup & configuration assistance |

A tool registered under one channel **MUST NOT** be callable from another channel.

---

## 5. Tool Registration Metadata

Each tool MUST be registered with the following metadata:

```yaml
tool_id: def.staff_ai.document_create
channel: staff_ai
name: Document Creation
description: Create a DOCX, PDF, or Markdown document from user instructions
version: 1.0
enabled: true

allowed_capabilities:
  - def_staff_access
  - def_management_access

input_schema:
  type: object
  required:
    - format
    - content
  properties:
    format:
      type: string
      enum: [docx, pdf, markdown]
    content:
      type: string

output_schema:
  type: object
  properties:
    file_url:
      type: string
    file_type:
      type: string

## 6. Activation & Enablement Rules

### 6.1 Default Behaviour (v1)

- Tools are enabled by default unless explicitly disabled.
- Activation state is stored server-side and enforced by the Python control plane.
- def-core (WordPress) MAY expose UI controls to toggle tools, but Python is authoritative.

### 6.2 Enforcement

Before executing a tool, the Python app MUST verify:
- Tool exists
- Tool is enabled
- Requesting channel matches tool channel
- User has at least one required capability

If any check fails, execution MUST be denied.

---

## 7. Capability Governance
 
Tools MUST declare required DEF capabilities.

Supported capabilities (v1):

- def_staff_access
- def_management_access

Rules:

- def_management_access implicitly includes all staff-level access
- def_staff_access does NOT grant management-only tools
- Capabilities are assigned directly to users (no WP role inference)

---

## 8. Tool Discovery (v1)

The Python app MUST expose a discovery endpoint per channel.

Example (Staff AI):

```bash
GET /api/staff-ai/tools

Response MUST include:

- tool_id
- name
- description
- enabled state
- required capabilities

Discovery MUST:

- Filter tools by channel
- Filter tools by user capability
- Never expose disabled or unauthorized tools

---

## 9. Tool Invocation (Explicit Only)

Tools MUST be invoked explicitly by ID.
Example:

```swift
POST /api/staff-ai/tools/invoke

Rules:

- LLM MUST NOT auto-run tools
- UI MUST show tool invocation clearly
- Tool output MUST be shown to the user
- Tool invocation MUST be logged

---

## 10. Logging & Auditing (High-Level)

Each tool invocation MUST generate an immutable log event:

- tool_id
- channel
- user_id (or anonymous marker)
- timestamp
- success / failure
- execution duration

Transcript storage is governed by logging rules outside this document.

---

## 11. v1 Implementation Reality

As of v1:

- Staff AI tools are implemented and discoverable via: 
 - GET /api/staff-ai/tools
 - POST /api/staff-ai/tools/invoke
- Customer channel tools are planned but not yet registered
- This registry is forward-compatible across channels

---

## 12. Non-Goals (v1)

This document does NOT define:

- Tool billing or quotas
- Tool approval workflows
- Tool version migration strategy
- Cross-channel tool sharing

---

## 13. Tool Search Policy (v1.1)

### 13.1 Purpose

DEF MUST NOT inject all registered tools into the LLM context by default.

Instead, DEF uses a **Tool Search → Selective Injection → Explicit Invocation** model to:
- Minimise token usage
- Improve model reasoning quality
- Scale safely as the tool catalog grows

This policy applies to **all channels**.

---

### 14.2 Tool Search Phase (Required)

Before exposing any tools to the LLM, the Python control plane MUST perform a **tool search step**.

Inputs to tool search:
- Channel (`staff_ai`, `customer`, `setup_assistant`)
- User capabilities (`def_staff_access`, `def_management_access`)
- Natural language query (derived from the user message)
- Tool activation state (enabled / disabled)

Search MUST return:
- A **small, relevant subset** of tools (recommended: 1–3)
- Ordered by relevance

Tools that are:
- Disabled
- Outside the current channel
- Not permitted by user capability  
MUST NOT be returned.

---

### 14.3 Selective Tool Injection

Only tools returned by the Tool Search phase MAY be injected into the LLM context.

Injected tool metadata SHOULD be minimal:
- `tool_id`
- `name`
- `description`
- Minimal input schema summary
- Explicit invocation rules

Full schemas or unrelated tools MUST NOT be injected.

---

### 14.4 Explicit Invocation Rule (Reinforced)

Even after selective injection:
- Tools MUST NOT auto-execute
- The LLM MUST request explicit invocation
- The Python control plane remains authoritative for execution

Tool invocation without a preceding search result is prohibited.

---

### 14.5 Fallback Behaviour

If Tool Search returns no relevant tools:
- The LLM proceeds with pure text response
- No tools are injected
- No error is raised

This ensures graceful degradation.

---

### 14.6 v1 Compatibility Note

For v1 implementations:
- `GET /api/{channel}/tools` MAY act as both discovery and search
- Future versions MAY introduce a dedicated search endpoint:

GET /api/{channel}/tools/search


This policy is forward-compatible and does not require immediate endpoint changes.

---

### 14.7 Governance & Cost Control

Tool Search is a **cost control mechanism**:
- Reduces prompt size
- Prevents unnecessary schema tokens
- Limits model exposure to unsafe or irrelevant tools

Partners and tenants MUST NOT bypass this policy.

---

End of Document

Recommended Path:
/docs/platform/tools/DEF_TOOL_REGISTRY_V1.md






