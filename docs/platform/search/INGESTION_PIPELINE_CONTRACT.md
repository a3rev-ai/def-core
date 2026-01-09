INGESTION_PIPELINE_CONTRACT.md

Version: v1.0
Status: Authoritative
Last Updated: 2026-01-08

Path:
/docs/platform/search/INGESTION_PIPELINE_CONTRACT.md

# Digital Employee Framework  
## Ingestion Pipeline Contract (Authoritative)

**Audience:** DEF ingestion / connector developers  
**Purpose:** Define the canonical ingestion output format for indexing and retrieval  
**Status:** Required (v1)

---

## 1. Purpose

The ingestion pipeline converts source documents into indexed chunks suitable for:
- secure retrieval (with access control)
- RAG answering
- auditability

All ingestion connectors MUST output a consistent chunk schema.

---

## 2. Ingestion Stages (High Level)

1) Source acquisition  
2) Text extraction  
3) Normalisation  
4) Chunking  
5) Metadata attachment  
6) Validation  
7) Indexing (Azure AI Search)

This contract governs stages 4–7.

---

## 3. Canonical Chunk Schema (v1)

Every indexed chunk MUST be represented as a record containing at minimum:

### Required fields

- `tenant_id` (string)
- `doc_id` (string)
- `chunk_id` (string)
- `title` (string)
- `content` (string)
- `access_level` (string: `public` | `staff` | `management`)
- `updated_at` (string or datetime)

### Vector fields (required for vector retrieval)

- `<vector_field>` (float array)
  - Field name must match `AZURE_SEARCH_SCHEMA.md`

### Optional (recommended)

- `source_type` (string: upload | url | drive | sharepoint | dropbox | etc.)
- `source_path` (string)
- `source_url` (string)
- `department` (string)
- `tags` (string[])

---

## 4. ID Rules (Non-Negotiable)

### doc_id
- Stable identifier for the document across re-ingestion
- If source provides a stable ID, use it
- Otherwise derive from a stable hash of source_path/source_url + tenant_id

### chunk_id
- Must be stable where possible
- Recommended pattern:
  - `{doc_id}:{chunk_index}:{chunk_hash}`

These IDs ensure updates can upsert rather than duplicate.

---

## 5. access_level Rules (Required)

All chunks MUST include `access_level`.

Allowed values:
- public
- staff
- management

Missing or invalid access_level MUST default to `management`.

See:
- `INGESTION_ACCESS_LEVEL_RULES.md`

---

## 6. Validation Rules (Required)

Before indexing, the pipeline MUST validate:
- required fields exist and are non-empty
- access_level is valid (or corrected to management)
- tenant_id is present
- vector field exists and is correct shape (if vector indexing enabled)

Validation failures must:
- be logged (event-based)
- fail safe (do not index invalid chunks)

---

## 7. Indexing Behaviour (Azure AI Search)

The pipeline MUST:
- upsert chunks by `chunk_id`
- ensure `tenant_id` and `access_level` are filterable fields in the index
- ensure vector field supports vector search

Field names MUST match:
- `AZURE_SEARCH_SCHEMA.md`

---

## 8. Update / Re-ingestion

When a document changes:
- chunks MUST be updated (upsert)
- removed chunks MUST be deleted if document shrinks (optional v1)
- access_level changes MUST trigger re-indexing

---

## 9. Privacy & Logging

Ingestion logs must:
- not include raw document content
- record counts, IDs, and outcomes only
- mask any user contact fields if present (rare in ingestion)

See:
- `PRIVACY_POSTURE.md`
- `LOGGING_RULES.md`

---

## 10. Non-Negotiable Rules

- Do not index chunks without tenant_id and access_level
- Do not infer access at retrieval time
- Always fail safe

---

**This document is authoritative.**  
If connector implementations conflict with this contract, this contract wins.


