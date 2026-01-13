---
active: true
iteration: 1
max_iterations: 0
completion_promise: "STAFF_AI_F1_COMPLETE"
started_at: "2026-01-13T06:58:12Z"
---

You are Claude Code running in the def-core WordPress plugin repo.

Open and follow these two files as the only source of requirements:
- docs/prd/PRD-STAFF-AI-FRONTEND-V1.md
- docs/prd/RALPH-RUNBOOK-STAFF-AI-FRONTEND-V1.md

Execute ONLY: LOOP 1 — Endpoint + Auth Gate.

Hard requirements for this run:
- Implement /staff-ai full-screen endpoint (no WP theme header/footer)
- Authentication gate:
  - If not logged in, render WP login form inside the Staff AI shell OR redirect to wp-login.php with redirect back to /staff-ai (use whichever matches existing def-core patterns)
- Capability gate:
  - Allow only users with def_staff_access OR def_management_access
  - If authenticated but lacking capability, show a minimal “Access denied” view and DO NOT render the chat UI
- Render a minimal HTML container for the chat UI (shell only; do not implement Chat UI yet)
- Ensure backend calls include channel=staff_ai and user identity context is available for later wiring (do not invent new API contracts)

Conts (must obey):
- Smallest possible diff
- Modify existing files only unless absolutely necessary
- Do NOT create new directories
- Do NOT refactor unrelated code
- Do NOT store chat history/transcripts in WordPress (no sessions/transients/custom tables)

Verification:
- Explain how to manually verify the three states:
  1) unauthenticated
  2) authenticated without capability
  3) authenticated with capability

Stop when complete and output exactly this token on its own line:
STAFF_AI_F1_COMPLETE

