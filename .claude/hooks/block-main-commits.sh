#!/bin/bash
# Hard gate: prevent Claude from committing or pushing directly to main/master.
# Exit code 2 = deny permission (command never executes).

# Read stdin (hook input JSON) and extract the command using grep/sed (no jq dependency)
INPUT=$(cat)
COMMAND=$(echo "$INPUT" | grep -o '"command":"[^"]*"' | head -1 | sed 's/"command":"//;s/"$//')

if echo "$COMMAND" | grep -qE '^\s*git\s+(commit|push)'; then
  CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null)
  if [ "$CURRENT_BRANCH" = "main" ] || [ "$CURRENT_BRANCH" = "master" ]; then
    echo "{\"hookSpecificOutput\":{\"hookEventName\":\"PreToolUse\",\"permissionDecision\":\"deny\",\"permissionDecisionReason\":\"BLOCKED: Cannot commit or push directly to $CURRENT_BRANCH. Create a feature branch first.\"}}"
    exit 2
  fi
fi

exit 0
