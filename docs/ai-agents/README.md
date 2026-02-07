# DEF Cursor Agent Pack

This directory contains the authoritative AI agents used during DEF development.

These agents enforce:
- Contract compliance
- Security boundaries
- Permission integrity
- Escalation and logging rules

## Agents

### Code Review Agent
Run after any implementation prompt.
Blocks non-compliant code and excessive diffs.

### Security & Policy Agent
Run after code review.
Ensures no access, escalation, logging, or privacy violations.

## Required Workflow

1. Run implementation prompt
2. Run Code Review Agent
3. Run Security & Policy Agent
4. Only merge when both agents pass

These agents are non-creative and contract-bound.
