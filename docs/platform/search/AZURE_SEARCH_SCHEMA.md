AZURE_SEARCH_SCHEMA.md

Suggested path:
/docs/platform/search/AZURE_SEARCH_SCHEMA.md

# Azure AI Search Schema (DEF)

This document is the **single source of truth** for Azure AI Search field names used by DEF retrieval code.
All retrieval code MUST use these names (no hardcoding elsewhere).

---

## Index

- **index_name:** <SET_ME>
- **endpoint:** <SET_ME>  (optional, can live in env/config instead)

---

## Field Names (Authoritative)

Set the exact field names as defined in the Azure AI Search index schema:

- **tenant_id_field:** <SET_ME>         (e.g. tenant_id)
- **access_level_field:** <SET_ME>      (e.g. access_level)

- **chunk_id_field:** <SET_ME>          (e.g. chunk_id)
- **doc_id_field:** <SET_ME>            (e.g. doc_id)

- **title_field:** <SET_ME>             (e.g. title)
- **content_field:** <SET_ME>           (e.g. content)

- **vector_field:** <SET_ME>            (e.g. content_vector)

Optional (recommended if available):
- **source_type_field:** <SET_ME>       (e.g. source_type)
- **source_path_field:** <SET_ME>       (e.g. source_path)
- **updated_at_field:** <SET_ME>        (e.g. updated_at)

---

## Required Field Capabilities (Index Configuration)

The following fields MUST be filterable:
- tenant_id_field
- access_level_field

The following field MUST support vector search:
- vector_field

---

## Allowed Values

- **access_level allowed values (v1):**
  - public
  - staff
  - management

Rules:
- access_level MUST be present on every indexed chunk
- if missing at ingestion → treat as `management`

---

## Retrieval Defaults

- Default top_k: 8
- Retrieval filtering MUST be applied at query time (OData filter), not after retrieval
- Queries MUST always filter by tenant_id + allowed access_level values
