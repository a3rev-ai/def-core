Path: 
/docs/channels/staff/STAFF_AI_TOOLSET_V1.md


STAFF_AI_TOOLSET_V1.md

# Digital Employee Framework  
## Staff AI Tool Set v1

This document defines the **initial tool set** available to the Staff AI Channel.

---

## 1. Tool Design Principles

- Tools perform **side effects**
- Tools are auditable
- Tools are tenant-scoped
- Tools are optional and configurable

No tool is assumed to exist unless explicitly enabled.

---

## 2. Core Tools (v1)

### 2.1 Document Creation Tool

**Purpose:** Create written documents for staff.

Capabilities:
- Accepts title + content
- Supports formats:
  - DOCX
  - PDF
  - Markdown
- Returns downloadable file link

---

### 2.2 Spreadsheet Creation Tool

**Purpose:** Create structured data files.

Capabilities:
- Accepts table schema + rows
- Supports formats:
  - XLSX
  - CSV
- Returns downloadable file link

---

### 2.3 Image Generation Tool

**Purpose:** Generate images using the configured LLM provider.

Capabilities:
- Prompt-based generation
- Supports common image formats
- Returns image file link

---

### 2.4 File Export / Download Tool

**Purpose:** Package and deliver generated outputs.

Capabilities:
- Secure download links
- Time-limited URLs
- Tenant-scoped storage

---

## 3. Optional Business Tools (Future / Configured)

Examples:
- CRM lookup
- Order search
- Ticket creation
- Knowledge base update

These are **not required** for v1.

---

## 4. Tool Invocation Rules

- Tools MUST be invoked explicitly
- Staff AI must explain what it is about to do before execution
- Tool output must be shown to the user

---

## 5. Relationship to Other Channels

- Customer Chat: no tools
- Setup Assistant: setup-only tools
- Staff AI: work tools

---

## 6. Summary

Staff AI tools turn DEF from “chatbot” into a **work platform**.

This tool set is intentionally small and expandable.
