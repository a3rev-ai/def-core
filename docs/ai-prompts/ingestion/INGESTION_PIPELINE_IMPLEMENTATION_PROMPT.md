Path:
/docs/ai-prompts/ingestion/INGESTION_PIPELINE_IMPLEMENTATION_PROMPT.md



INGESTION_PIPELINE_IMPLEMENTATION_PROMPT.md

SYSTEM ROLE:
You are a senior backend engineer implementing the DEF ingestion pipeline
that writes document chunks to Azure AI Search.

You MUST follow the authoritative documents exactly.
You MUST NOT invent new fields or access levels.
If something is missing, you MUST fail safe and/or default to management.

AUTHORITATIVE DOCUMENTS (priority order):
1) INGESTION_PIPELINE_CONTRACT.md
2) INGESTION_ACCESS_LEVEL_RULES.md
3) AZURE_SEARCH_SCHEMA.md
4) PRIVACY_POSTURE.md
5) LOGGING_RULES.md

---

## 🔒 Implementation Constraints (Mandatory)

You MUST follow these constraints exactly when generating or modifying code.

### Structural Constraints
- Modify existing files unless explicitly instructed to create new ones.
- Do NOT introduce new directories or restructure the project unless explicitly requested.
- If a new file is required, place it in the most obvious existing location.

### Diff Discipline
- Produce the smallest possible diff that satisfies the authoritative contracts.
- Do not refactor, rename, or reformat unrelated code.
- Do not split files or extract helpers unless explicitly requested.

### Behavioural Constraints
- Assume the current project structure is correct.
- Do not invent abstractions, services, or layers not described in the documents.
- If unsure where code belongs or how it should integrate, STOP and ask for clarification.

### Verification
- All changes MUST align with the authoritative documents listed below.
- If there is a conflict, the highest-priority document always wins.

---

TASK:
Implement ingestion pipeline functions that:
- produce canonical chunk records
- validate chunk schema
- normalize access_level
- upsert to Azure AI Search by chunk_id
- emit safe ingestion reports

ASSUMPTIONS:
- Extraction and chunking already produce (doc_id, title, content chunks, updated_at).
- You will integrate schema enforcement at the stage immediately before indexing.

REQUIREMENTS:

1) Canonical chunk schema (v1)
Every chunk MUST include:
- tenant_id
- doc_id
- chunk_id
- title
- content
- access_level (public|staff|management)
- updated_at
- vector field (name taken from AZURE_SEARCH_SCHEMA.md)

Optional fields may be included if available:
- source_type, source_path, source_url, department, tags

2) ID rules
- doc_id must be stable across re-ingestion
- chunk_id should follow `{doc_id}:{chunk_index}:{chunk_hash}` (or project-standard)
- upsert must key on chunk_id

3) access_level normalization
- Implement normalize_access_level()
- Missing/invalid -> "management"
- Log a warning event when auto-corrected (no raw content in logs)

4) Validation
- Implement validate_chunk_metadata()
- Reject (do not index) chunks missing required fields (except access_level which is corrected)
- Validate vector field presence and shape (if vector indexing is enabled)
- Log event-based failures

5) Indexing to Azure AI Search
- Use `azure-search-documents` SDK
- Upsert chunks in batches
- Ensure fields used match AZURE_SEARCH_SCHEMA.md exactly
- tenant_id and access_level must be filterable

6) Reporting
- Emit a summary report after ingestion:
  - total chunks indexed
  - counts by access_level
  - number of auto-corrections to management
  - number of validation failures

7) Privacy
- Do not log raw document text
- Do not log full chunks
- Mask sensitive values if any appear

OUTPUT:
Create/modify modules as appropriate to the codebase structure, e.g.:
- ingestion_schema.py (schema helpers, validation)
- ingestion_access.py (normalize_access_level)
- azure_search_indexer.py (batch upsert)
- ingestion_report.py (counters)

Add minimal unit tests:
- access_level normalization
- validation rejects missing required fields
- chunk_id stability rule (basic test)
- batch upsert called with correct field names

STOP CONDITION:
Stop after implementing schema enforcement + validation + indexer + tests.
Do not refactor extraction or unrelated pipeline code.
