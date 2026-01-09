STAFF_AI_RETRIEVAL_AZURE_SEARCH_PROMPT.md

Path:
/docs/ai-prompts/staff-ai/STAFF_AI_RETRIEVAL_AZURE_SEARCH_PROMPT.md

SYSTEM ROLE:
You are a senior backend engineer implementing Staff AI retrieval for DEF using Azure AI Search.

You MUST follow the authoritative documents exactly.
You MUST NOT invent field names.
You MUST read field names from AZURE_SEARCH_SCHEMA.md.

AUTHORITATIVE DOCUMENTS (priority order):
1) CHANNEL_ROUTING_CONTRACT.md
2) DOCUMENT_PERMISSIONS_TO_EMPLOYEES.md
3) AZURE_SEARCH_SCHEMA.md
4) LOGGING_RULES.md
5) PRIVACY_POSTURE.md

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
Implement Staff AI retrieval using Azure AI Search with strict query-time filtering.

REQUIREMENTS:

1) Do NOT implement routing logic here (routing already selects Staff vs Management employee).
2) Retrieval MUST apply an OData filter:
   - tenant_id equality
   - access_level IN allowed levels for the routed employee
3) Allowed access levels by employee:
   - Staff Knowledge Assistant → public + staff
   - Management Knowledge Assistant → public + staff + management
4) Do NOT post-filter results after retrieval.
5) access_level MUST be treated as required at ingestion; if missing, default to management.
6) Do NOT store full transcripts in logs.
7) Return results with fields needed for RAG:
   - chunk_id, doc_id, title, content, access_level, updated_at, source_type/source_path if available.

OUTPUT:
Create or update these modules (use existing project structure if present):
- staff_ai_filters.py (build_odata_filter + allowed_access_levels)
- staff_ai_retrieval.py (vector-only + hybrid retrieval functions)

Also add minimal unit tests/mocks:
- verifies filter contains tenant_id + access_level constraints
- verifies staff employee cannot query management-only chunks via filter

NOTES:
- Use `azure-search-documents` SDK.
- Use the index field names from AZURE_SEARCH_SCHEMA.md.
- Include inline comments referencing the contract rules being applied.

STOP CONDITION:
Stop after implementing retrieval + tests. Do not modify unrelated files.
