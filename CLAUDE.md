# Project Context

## Project Overview
This is **def-core** - a WordPress plugin that works together with the **Digital Employee Framework** Python backend. Both projects are tightly integrated and changes often need to be synchronized across both.

## Related Repositories

- **digital-employee-framework** (Python Backend): `/Volumes/DATA/AI/digital-employee-framework`
  - Python backend application providing Staff AI channel, tools, and API capabilities
  - When making changes that affect API endpoints, data structures, or communication protocols, check if the Python backend needs updating too

## Important Guidelines
- **Always consider both repos**: When modifying API calls, request/response formats, or shared functionality, update both repos as needed
- **Keep in sync**: The WordPress plugin and Python backend must stay compatible

## Environment Configuration
- **Azure credentials**: Located in the Python repo at `/Volumes/DATA/AI/digital-employee-framework/.env`
  - Contains Azure OpenAI API keys and endpoints
  - Azure Search service credentials
  - Azure Foundry project settings
  - JWT/JWKS configuration for WordPress bridge

## Session Continuity

### At the START of each session:
Read session notes from BOTH repos to understand recent work:
1. This repo: `CLAUDE_NOTES.md` (in this directory)
2. Python backend repo: `/Volumes/DATA/AI/digital-employee-framework/CLAUDE_NOTES.md`

### REAL-TIME updates (IMPORTANT):
Update `CLAUDE_NOTES.md` immediately after completing EACH task the user requests. Do not wait until the end of the session. Include:
- Date of session
- What was just completed
- Current status
- Next steps or pending work

This ensures no work is lost if the session ends unexpectedly.
