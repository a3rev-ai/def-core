#!/usr/bin/env bash
# =============================================================================
# def-core Smoke Test Harness
#
# 7 smoke checks against a running wp-env instance (no WooCommerce).
# Usage:
#   npm run smoke          # latest WP (PR Gate)
#   npm run smoke:wp60     # WP 6.0 minimum (Compatibility)
#
# Requires: wp-env running (npm run env:start)
# =============================================================================
set -euo pipefail

PASS=0
FAIL=0
ERRORS=""

pass() {
  PASS=$((PASS + 1))
  echo "  PASS: $1"
}

fail() {
  FAIL=$((FAIL + 1))
  ERRORS="${ERRORS}\n  FAIL: $1"
  echo "  FAIL: $1"
}

# Helper: run WP-CLI command inside the tests-cli container.
wp_cli() {
  npx wp-env run tests-cli -- wp "$@" 2>/dev/null
}

# Helper: run curl from the host against the WP test instance.
# wp-env test site runs on port 8889.
host_curl() {
  curl -s "$@" 2>/dev/null
}

# Detect test site port (default: 8889 for wp-env tests).
TEST_PORT="${WP_ENV_TESTS_PORT:-8889}"
TEST_URL="http://localhost:${TEST_PORT}"

echo "=== def-core Smoke Tests ==="
echo "Test site: ${TEST_URL}"
echo ""

# --- Check 1: WP boots ---
echo "[1/7] WordPress boots"
SITE_URL=$(wp_cli option get siteurl 2>/dev/null || echo "")
if [ -n "$SITE_URL" ]; then
  pass "WP boots — siteurl=$SITE_URL"
else
  fail "WP boots — could not get siteurl"
fi

# --- Check 2: def-core activates ---
echo "[2/7] def-core activates"
ACTIVATE_OUTPUT=$(wp_cli plugin activate def-core 2>&1 || echo "ACTIVATE_FAILED")
if echo "$ACTIVATE_OUTPUT" | grep -qi "activated\|already active"; then
  pass "def-core activates"
else
  fail "def-core activation failed: $ACTIVATE_OUTPUT"
fi

# --- Check 3: REST routes exist ---
echo "[3/7] REST routes registered"
# Get the REST route index for our namespace (from host, hitting test site).
# Unescape JSON forward slashes (\/) for reliable grep matching.
ROUTES_JSON=$(host_curl "${TEST_URL}/wp-json/a3-ai/v1" | sed 's|\\\/|/|g' || echo "{}")

# Check namespace exists.
if echo "$ROUTES_JSON" | grep -q '"namespace":"a3-ai/v1"'; then
  pass "Namespace a3-ai/v1 exists"
else
  fail "Namespace a3-ai/v1 not found in REST index"
fi

# Check expected routes are present.
EXPECTED_ROUTES=(
  "/a3-ai/v1/context-token"
  "/a3-ai/v1/jwks"
  "/a3-ai/v1/tools/me"
  "/a3-ai/v1/staff-ai/conversations"
  "/a3-ai/v1/staff-ai/chat"
  "/a3-ai/v1/staff-ai/status"
  "/a3-ai/v1/staff-ai/tools"
  "/a3-ai/v1/staff-ai/tools/invoke"
  "/a3-ai/v1/settings/escalation"
  "/a3-ai/v1/escalation/send-email"
)
ROUTE_PASS=true
for ROUTE in "${EXPECTED_ROUTES[@]}"; do
  if echo "$ROUTES_JSON" | grep -q "\"$ROUTE\""; then
    : # route found
  else
    fail "Route missing: $ROUTE"
    ROUTE_PASS=false
  fi
done
if [ "$ROUTE_PASS" = true ]; then
  pass "All expected routes registered"
fi

# --- Check 4: JWKS responds ---
echo "[4/7] JWKS endpoint responds"
JWKS_RESPONSE=$(host_curl "${TEST_URL}/wp-json/a3-ai/v1/jwks" || echo "{}")
if echo "$JWKS_RESPONSE" | grep -q '"keys"'; then
  pass "JWKS returns {\"keys\":[...]}"
else
  fail "JWKS did not return expected structure: $JWKS_RESPONSE"
fi

# --- Check 5: Auth enforced ---
echo "[5/7] Auth enforced on protected endpoints"
AUTH_RESPONSE=$(host_curl -o /dev/null -w "%{http_code}" "${TEST_URL}/wp-json/a3-ai/v1/staff-ai/conversations" || echo "000")
if [ "$AUTH_RESPONSE" = "401" ]; then
  pass "staff-ai/conversations returns 401 for anonymous"
else
  fail "staff-ai/conversations returned $AUTH_RESPONSE (expected 401)"
fi

# --- Check 6: No PHP errors in debug.log ---
echo "[6/7] No PHP errors in debug.log"
DEBUG_LOG=$(wp_cli eval "echo defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? (file_exists(WP_CONTENT_DIR . '/debug.log') ? file_get_contents(WP_CONTENT_DIR . '/debug.log') : 'NO_LOG_FILE') : 'DEBUG_LOG_DISABLED';" 2>/dev/null || echo "EVAL_FAILED")
if [ "$DEBUG_LOG" = "NO_LOG_FILE" ] || [ "$DEBUG_LOG" = "DEBUG_LOG_DISABLED" ]; then
  pass "No debug.log (no errors)"
elif echo "$DEBUG_LOG" | grep -qiE "Fatal|Parse error"; then
  fail "Fatal/Parse errors found in debug.log"
elif echo "$DEBUG_LOG" | grep -qiE "Warning|Notice"; then
  # Warnings/Notices are reported but not fatal for smoke.
  echo "  WARN: Warnings/Notices found in debug.log (non-blocking)"
  pass "No fatal errors in debug.log (warnings present)"
else
  pass "debug.log clean"
fi

# --- Check 7: WC routes absent ---
echo "[7/7] WooCommerce routes absent (no WC installed)"
if echo "$ROUTES_JSON" | grep -q '"/a3-ai/v1/tools/wc/'; then
  fail "WC routes registered but WooCommerce is not installed"
else
  pass "WC routes correctly absent"
fi

# --- Summary ---
echo ""
echo "=== Results: $PASS passed, $FAIL failed ==="
if [ "$FAIL" -gt 0 ]; then
  echo -e "\nFailures:$ERRORS"
  exit 1
fi
echo ""
echo "All smoke tests passed."
exit 0
