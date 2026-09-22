# What this folder is

These are the standing engineering rules for the Digital Employees plugin: what contributors, and the AI agents that work on this code, must follow. They are dated when adopted and change only when a rule changes, so a file untouched for months is a rule that has held, not a document that was forgotten.

- `platform/` — the rules the plugin is built to: logging, escalation, document permissions, privacy posture, and the API, routing, search, security and tools contracts.
- `ai-agents/` — the code-review and security-policy agents run on every change, and the workflow that requires both to pass before a merge.
- `channels/` — the design of the Staff AI channel: overview, capability profile, toolset and the desktop app.
- `TESTING.md` — the test tiers and how to run them.
- `LOGGING.md` — the logging reference for the plugin.

What is current, and when it changed, is in [changelog.txt](../changelog.txt): one dated entry per release, in plain words. The implementation prompts and product requirement documents used while building are kept in the private framework repository, not here.
