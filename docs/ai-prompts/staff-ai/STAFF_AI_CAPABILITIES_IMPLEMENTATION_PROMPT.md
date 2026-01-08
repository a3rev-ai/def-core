Path: 
/docs/ai-prompts/staff-ai/STAFF_AI_CAPABILITIES_IMPLEMENTATION_PROMPT.md

STAFF_AI_CAPABILITIES_IMPLEMENTATION_PROMPT.md

SYSTEM ROLE:
You are a senior AI platform engineer implementing Staff AI capabilities in DEF.

You MUST follow the authoritative documents below exactly.
You MUST NOT enable general-purpose capabilities for Customer Chat or Setup Assistant.

AUTHORITATIVE DOCUMENTS (priority order):
1) STAFF_AI_CAPABILITY_PROFILE.md
2) STAFF_AI_TOOLSET_V1.md
3) CHANNEL_ROUTING_CONTRACT.md
4) DEF_CAPABILITIES_AND_ROLES.md
5) DOCUMENT_PERMISSIONS_TO_EMPLOYEES.md
6) LOGGING_RULES.md
7) PRIVACY_POSTURE.md

TASK:
Implement Staff AI channel behaviour that:
- allows general LLM-powered work tasks
- supports tool-based document, spreadsheet, and image creation
- enforces access-level-based retrieval
- maintains per-user conversation context

REQUIREMENTS:

1) Channel Behaviour
- Staff AI must support free-form LLM interaction
- Retrieval is optional, not mandatory for every response
- Retrieval must respect access_level rules

2) Tool Invocation
- Implement tool interfaces for:
  - document creation
  - spreadsheet creation
  - image generation
- Tools must be explicitly invoked
- Tool outputs must be returned to the user

3) User Context
- Use authenticated WordPress user identity
- Maintain per-user conversation threads
- Do not share history between users

4) Boundaries
- Do NOT apply these capabilities to:
  - Customer Chat
  - Setup Assistant
- Those channels remain bounded

5) Logging & Privacy
- Log tool invocations
- Do not log raw content unless explicitly allowed
- Respect retention rules

OUTPUT:
Implement or extend:
- Staff AI channel handler
- Tool interfaces
- Capability gating
- Minimal tests validating:
  - staff vs management behaviour
  - tool invocation
  - access enforcement

STOP CONDITION:
Stop after Staff AI capability + tool scaffolding is complete.
Do not implement customer or setup changes.
