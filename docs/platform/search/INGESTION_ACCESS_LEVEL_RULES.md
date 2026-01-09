INGESTION_ACCESS_LEVEL_RULES.md

Path:
/docs/platform/search/INGESTION_ACCESS_LEVEL_RULES.md


# Digital Employee Framework  
## Ingestion Rules — access_level (Authoritative)

**Audience:** DEF ingestion pipeline developers  
**Purpose:** Guarantee correct access control enforcement in retrieval  
**Status:** Required (v1)

---

## 1. Purpose

DEF enforces document visibility at **retrieval time** using the `access_level` field.

Therefore:
> Ingestion MUST always set `access_level` correctly for every indexed document chunk.

If `access_level` is missing or incorrect, retrieval enforcement cannot be trusted.

---

## 2. Allowed Values (v1)

Every indexed chunk MUST include:

- `access_level` ∈ { `public`, `staff`, `management` }

No other values are permitted.

---

## 3. Mandatory Ingestion Rule (Non-Negotiable)

For every chunk written to Azure AI Search:

- `access_level` MUST be present
- `access_level` MUST be one of the allowed values
- If `access_level` is missing or invalid:
  - it MUST be set to `management` (safe default)

This rule applies to all sources:
- website pages
- uploaded PDFs
- DOCX files
- Drive / Dropbox / OneDrive sources
- KB exports

---

## 4. Source-to-access_level Mapping

Recommended default mapping:

- Customer-facing content (website KB, public pages) → `public`
- Internal staff docs (SOPs, onboarding, internal procedures) → `staff`
- Sensitive/internal restricted docs (finance, disciplinary HR, strategy) → `management`

If a source is ambiguous, default to `management`.

---

## 5. Folder / Collection Defaults (Recommended)

If ingesting from folders or collections:
- each folder/collection MUST define a default `access_level`
- all files inherit it unless explicitly overridden

This prevents accidental unlabeled ingestion.

---

## 6. Metadata Requirements

Each chunk must store:
- `tenant_id`
- `access_level`
- `doc_id`
- `chunk_id`

Optional but recommended:
- `source_type`
- `source_path`
- `updated_at`

`tenant_id` and `access_level` MUST be filterable fields in Azure AI Search.

---

## 7. Update Behaviour (Re-ingestion)

When documents are updated:
- existing chunks MUST be updated with the same `access_level`
- changes to `access_level` MUST trigger re-indexing of the document

---

## 8. Validation & QA Requirements

Ingestion must include a validation step:

- Reject or auto-correct invalid `access_level`
- Emit a warning log when defaulting to `management`
- Provide a simple report:
  - counts of chunks by access_level
  - counts of auto-corrections

---

## 9. Non-Negotiable Rules

- Never leave `access_level` unset
- Never infer visibility at retrieval time
- Always fail safe (`management`) when uncertain

---

**This document is authoritative.**  
If ingestion code conflicts with these rules, these rules win.
