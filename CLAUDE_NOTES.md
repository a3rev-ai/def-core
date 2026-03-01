# Session Notes - def-core (WordPress Plugin)

## Latest Session: 2026-03-01

### Phase 8 Sub-PR A: Foundation — Floating Button + Panel Shell + Branding — BUILT

**Branch:** `phase8-subpr-a-native-chat-foundation`

**Files created:**
- `assets/css/def-core-customer-chat.css` (1,428 lines) — Full Shadow DOM scoped CSS adapted from shell.css + popup.css
- `assets/js/def-core-customer-chat-loader.js` (503 lines) — Lightweight IIFE loader: Shadow DOM host, floating trigger button, panel shell, lazy-load infrastructure
- `assets/js/def-core-customer-chat.js` (20 lines) — Placeholder chat module (Sub-PR B fills this in)

**Files modified:**
- `includes/class-def-core-admin.php` — 5 new `$tab_allowlists` entries (button position/color/icon/icon_id/show_floating) + 3 sanitizer methods + button settings data in render_settings_page()
- `templates/admin-settings.php` — New "Chat Button Appearance" card in Chat Settings tab (position radio, color picker, icon radio with SVG previews, custom icon upload, floating toggle with warning)
- `assets/js/def-core-admin.js` — initButtonAppearance() with icon uploader toggle, floating warning toggle, color preview
- `assets/css/def-core-admin.css` — Color field + icon preview styles
- `includes/class-def-core.php` — 3 new asset registrations, expanded enqueue_frontend_assets() with branding/chat/button/API/i18n data in window.DEFCore, shortcode [def_chat_button] + action hook, 4 private helpers (get_logo_url_for_frontend, get_customer_chat_api_url, get_button_icon_url, get_chat_strings)
- `includes/class-def-core-tools.php` — Cache-Control: no-store on context-token response

**What was built:**
- Shadow DOM host with CSS-only floating trigger button (zero chat JS on page load)
- Trigger button reads config from window.DEFCore: position, color, icon (chat/headset/custom)
- Panel shell (modal or drawer mode) created on first click
- Lazy-load infrastructure: hover preload, script/CSS injection on first open
- 24-hour hide via localStorage (same key as popup.js for continuity)
- Header trigger buttons via [data-def-chat-trigger] attribute (shortcode/action hook)
- Escape key closes, click-outside closes (modal mode)
- Admin bar offset for drawer mode
- iOS scroll lock, prefers-reduced-motion, mobile responsive
- destroy() for SPA/PJAX cleanup
- Full CSS: trigger, panel, header, messages, composer, attachments, overlays, dialogs

**Status:** Code complete, PHP syntax verified, awaiting commit + PR + Docker testing.

---

## MUST DO — Post Phase 7: Native Customer Chat Widget

**Priority:** Immediately after Phase 7 completion
**Scope:** Replace iframe-based Customer Chat with native JS widget + Chat Settings appearance

**Why:** Current Customer Chat loads an iframe from the Python backend — triggers a full second page load (HTML + CSS + JS + API calls) from a separate origin. Kills Core Web Vitals (LCP, FID/INP). Branding settings can't reach the iframe content. Style/theme isolation means no visual consistency with the host site.

**Architecture:**
- **Floating button only** on page load — pure CSS, zero JS execution, zero CWV impact
- **Fully lazy** — chat JS module loaded on first click only (not on page load)
- **Direct API calls** — `fetch()` to Python backend REST API with JWT from existing bridge auth
- **Native DOM** — chat UI as DOM elements in parent page, reads branding from `window.DEFCore`, inherits site fonts/theme
- **Tiny footprint** — ~15-20KB JS module loaded on demand

**Combined with Chat Settings appearance:**
- Chat bubble position (bottom-right, bottom-left)
- Chat bubble background colour
- Chat bubble icon
- Floating button position (existing `data-position` setting, moved to Chat Settings tab)
- All settings read from `window.DEFCore` by the native widget

**Files:** New `assets/js/def-core-chat.js` (lazy-loaded module), update `def-core.js` (bridge), update `class-def-core.php` (enqueue + localize settings), update Chat Settings tab UI.

---

## Latest Session: 2026-03-01

### Phase 7 Sub-PR D-III: Setup Assistant REST Endpoints + HMAC Auth — BUILT
**Files created:**
- `includes/class-def-core-setup-assistant.php` (~750 lines) — Complete Setup Assistant REST controller
- `tests/test-setup-assistant.php` (~800 lines) — 161 tests, all passing

**Files modified:**
- `includes/class-def-core.php` — Added require_once + `DEF_Core_Setup_Assistant::init()` call

**What was built:**
- 10 REST endpoints under `def-core/v1/setup/` namespace (separate from `a3-ai/v1` tool namespace)
- Dual authentication: Mode A (WP nonce + def_admin_access) and Mode B (HMAC-SHA256 server-to-server)
- Mixed-mode auth rejection (both nonce AND HMAC → 400)
- HMAC validation: timestamp freshness (300s), body hash, canonical route, timing-safe comparison
- 9-setting allowlist with per-setting validation (URL, email, enum, integer range, attachment image)
- Secret redaction: `def_core_api_key` never returns raw value (configured_only mode)
- Chat proxy: forwards to `{api_url}/api/setup_assistant/chat` with API key auth (Mode A only)
- Connection test: reuses health check pattern from admin class
- User listing: all users with any DEF capability
- User role management: add/remove capabilities with lockout prevention
- Thread CRUD: user meta storage for conversation persistence
- Rate limiting: 30 writes/minute per user via transient sliding window
- Audit log: 100-entry FIFO in wp_options, API key values redacted
- Standard response envelope: `{success, data, error, ui_actions}`

**Tests:** 161 tests across 45 test sections — auth, HMAC, settings, status, users, chat proxy, connection, threads, rate limiting, audit log, validation, envelope structure.

**Full suite:** 400 tests pass (161 new + 239 existing), 0 failures.

**Status:** Code complete, tested, awaiting commit + PR. D-II still awaiting commit separately.

---

### Phase 7 Sub-PR D-II: Settings Tabs — BUILT (awaiting commit)
**Branch:** `phase7-subpr-d2-settings-tabs`

**D-II builds 4 settings tabs:** Branding, Chat Settings, Escalation, User Roles + `def_admin_access` capability infrastructure.

**Dashboard tweaks applied (pre-commit):**
1. User Roles UX rework — replaced "show all users" table with search-and-add model:
   - Only users with DEF capabilities shown on load
   - Locked admin row (non-removable)
   - AJAX search to find and add users by email/name
   - Remove button with confirmation dialog per user
   - New endpoints: `ajax_search_users`, `ajax_remove_user_roles`
2. Remove button X icon vertical centering fix
3. Confirmation dialog on user remove
4. Help text rewrite: Staff/Management = Staff AI access tiers (document authority levels), DEF Admin = settings page access
5. Removed Staff AI escalation email (replaced by Share feature, sender selects email)
6. Setup Assistant help text: "Enter your DEF Partner's email address here for Setup Assistant human escalation."

**Status:** All code complete, Docker-tested, awaiting commit + PR.

---

## Previous Session: 2026-02-28

### Phase 7 Sub-PR B: File Upload UX — MERGED
**PR:** https://github.com/a3rev-ai/def-core/pull/17 (branch: `phase7-subpr-b-upload-ux`)
**Companion DEF PR:** https://github.com/a3rev-ai/digital-employee-framework/pull/33

**4 commits + 1 UX fix commit:**

1. **PHP proxy endpoints** (`includes/class-def-core-staff-ai.php`):
   - `POST /staff-ai/uploads/init` — proxy to DEF with PHP pre-validation
   - `POST /staff-ai/uploads/commit` — proxy to DEF commit endpoint
   - MIME allowlist (10 types) → 415, size limit 10MB → 413, filename sanitization
   - Modified `rest_send_message()`: accepts `file_ids[]`, validates format, forwards as `attachments`, allows empty message when files present

2. **CSS upload styles** (`assets/css/staff-ai.css`):
   - ~180 lines added: upload button, drop overlay, staged area, file chips with status variants (staged/uploading/uploaded/failed), pulse animation, error tooltip, file indicators in messages
   - All using existing CSS custom properties → automatic light/dark theme

3. **JS upload module** (`assets/js/staff-ai.js`):
   - ~200 lines: validation helpers, file staging with image thumbnails, 3-step upload flow (init → PUT to Azure → commit), blob PUT retry (2 retries, 1s/2s backoff)
   - Event handlers: file picker, drag-and-drop with overlay, clipboard paste
   - Modified `sendMessage()`: uploads staged files first, collects file_ids, includes in request
   - Modified `updateSendButton()`: enables when files staged even without text

4. **HTML template changes** (`templates/staff-ai-shell.php`):
   - Hidden file input with accept filter, upload button (paperclip SVG), staged files area with `aria-live="polite"`, drop overlay
   - `StaffAIConfig.upload` config block + 8 new i18n strings

5. **UX fixes** (from manual Docker testing):
   - Specific validation error in banner when staging fails (not generic "Some files failed")
   - Re-validation of failed files when removing a staged file (count may now be under limit)
   - Auto-fill "Please analyze the attached file(s)." shown in user bubble for files-only sends

**Tests:** 14 new test sections (50 assertions) in `tests/test-staff-ai.php`. Added `sanitize_file_name()` and `get_current_user_id()` stubs to `tests/wp-stubs.php`.
**Total:** 239 tests pass, 0 fail.

**Manual testing:** Docker integration — upload via paperclip, drag-drop, paste all working. Light + dark theme verified. File-aware AI responses confirmed.

**ChatGPT code review:** Approved, no blockers. All integrity checks passed.

**Status:** MERGED to main (2026-02-28)

---

### Phase 7 Sub-PR A: Staff AI Refactor — MERGED
**PR:** https://github.com/a3rev-ai/def-core/pull/16 (branch: `phase7-subpr-a-staff-ai-refactor`)

**3 atomic commits:**

1. **Stub removal:** Removed `rest_export_conversation()` and `rest_escalate()` stub endpoints + route registrations. Fixed pre-existing test data bugs (wrong route names `share`/`revoke` → `share-settings`/`share-send`/`share-event`/`summarize`).

2. **Cache bug fixes:** `invalidate_user()` now accepts optional `$prefix` parameter for selective invalidation. `on_product_changed()` uses `self::build_key(0, 'products_list')` instead of wrong literal `'de_products_list'`. Added `tests/test-cache.php` (14 assertions).

3. **CSS/JS/HTML extraction:** Extracted `render_shell()` from ~2,200 lines to ~45 lines:
   - `assets/css/staff-ai.css` (1,067 lines) — verbatim CSS
   - `assets/js/staff-ai.js` (907 lines) — IIFE with StaffAIConfig references
   - `templates/staff-ai-shell.php` (276 lines) — standalone HTML document
   - `render_shell()` now prepares variables + includes template

**Monolith:** 3,641 → 1,407 lines
**Tests:** 185 pass, 0 fail
**Visual testing:** Docker integration stack — CSS/JS load as external files, StaffAIConfig populated, all DOM IDs present, no PHP errors

**Status:** MERGED to main (2026-02-28)

---

## Previous Session: 2026-02-25

### Staff AI Share Form Overhaul — MERGED
**PR:** https://github.com/a3rev-ai/def-core/pull/14 (branch: `staff-ai-share-form-overhaul`)

**What was built (1 file: class-def-core-staff-ai.php, 5 commits):**
- Share modal overhauled: 3 states (loading/error/form), AI-generated summary + editable subject/message
- Token/chip multi-select recipient selector (WooCommerce Chosen-style vanilla JS)
- Proxy routes (`/share-settings`, `/share-send`) bridging cookie/nonce auth → JWT escalation endpoints
- Persistent share/error event banners in thread (stored in WP options `def_core_share_events_{thread_id}`)
- Events merged into message list sorted by timestamp, rendered as green (share) / red (error) banners
- `POST /staff-ai/conversations/{id}/share-event` endpoint for persisting events
- `POST /staff-ai/conversations/{id}/summarize` route proxying to Python backend
- Security: 100-event cap per thread, field whitelist on share-send proxy, sanitized thread_id inputs

### Staff AI Light/Dark Mode Toggle — MERGED
**PR:** https://github.com/a3rev-ai/def-core/pull/15 (branch: `staff-ai-light-dark-mode`)

**What was built (1 file: class-def-core-staff-ai.php):**
- Refactored all ~110 hardcoded CSS colors to ~45 CSS custom properties
- `:root` = light theme (ChatGPT-style white, default), `.dark-theme` = dark theme overrides
- Theme toggle button in header (sun/moon SVG icons), stays visible on mobile
- Early inline `<script>` reads `localStorage` / `prefers-color-scheme` before paint (no flash)
- Preference persisted via `localStorage.setItem('staff-ai-theme', 'dark'|'light')`
- Smooth 0.2s CSS transitions on major layout elements (sidebar, header, composer, modal, inputs)
- Responsive fix: only Export/Share buttons hide on mobile, theme toggle stays visible

**CSS custom property groups:**
- Backgrounds: `--bg-main`, `--bg-sidebar`, `--bg-input`, `--bg-modal`
- Text: `--text-primary`, `--text-heading`, `--text-secondary`, `--text-tertiary`, `--text-placeholder`, `--text-faint`
- Borders: `--border-light`, `--border-medium`, `--border-hover`, `--border-focus`
- Interactive: `--hover-bg`, `--active-bg`
- Accents (theme-stable): `--accent-green`, `--accent-green-hover`, `--accent-indigo`, `--avatar-user`
- Banners, warning, chips, modal/overlay, spinner, tool output, typing dots — all tokenized

---

## Previous Session: 2026-02-22

### Testing Ramp-Up — MERGED ✓

**Branch:** `testing-ramp-up` → merged to `main`
**PR:** https://github.com/a3rev-ai/def-core/pull/10
**Commit:** `e67a73b`

**What was built (A-F):**

A. **Foundation:** `package.json` (wp-env + npm scripts), `.wp-env.json` (WP test env), `composer.json` (PHPUnit 9.6, PHPCS, PHPStan), `.gitignore` updated (vendor/, composer.lock, .phpunit.result.cache)

B. **Smoke Harness:** `tests/smoke/smoke-test.sh` — 7 checks: WP boots, plugin activates, REST routes registered, JWKS responds, auth enforced (401), no PHP errors, WC routes absent

C. **PHPUnit Infrastructure:** `phpunit.xml.dist` (scans `tests/wpunit/` only, excludes `@group woocommerce`), `tests/wpunit/bootstrap.php` (loads from `/wordpress-phpunit/`), `tests/wp-tests-config-template.php` (DB config for container)

D. **PHPUnit Integration Tests (28 tests, 170 assertions):**
- D1: `test-route-registration.php` (6 tests) — core/staff-ai/escalation routes, WC routes absent, permission callbacks, HTTP methods
- D2: `test-permission-callbacks.php` (10 tests) — JWKS public, context-token auth, staff-ai capability gates, admin status, escalation auth
- D3: `test-bridge-security.php` (5 tests) — no JWT leaks, no stack traces, JWKS structure, filename sanitization, MIME type policy
- D4: `test-woocommerce-optionality.php` (3 tests) — plugin loads, core routes work, WC routes absent
- D5: `test-jwt-integration.php` (4 tests) — keypair generation, issue/verify roundtrip, JWKS endpoint, context token for auth user

E. **Static Checks (report-only):** `phpcs.xml.dist` (WPCS + PHPCompatibility 8.0+), `phpstan.neon.dist` (level 3 with WordPress extension)

**All tests pass:**
- `npm run smoke` — 8/8 passed
- `npm run test:phpunit` — 28/28 passed (170 assertions)
- `php tests/run.php` — 174/174 passed (existing tests unchanged)
- `npm run lint:phpcs` — report produced
- `npm run lint:phpstan` — report produced (32 errors, all expected plugin-constant-not-found)

**Container notes:** wp-env used container names `tests-cli`, `tests-wordpress`, `cli`, `wordpress`. WP test library installed from WP 6.7.2 develop (newer than the cached WP 6.4.1 core, but forward-compatible with PHPUnit 9.6 polyfills). DNS issue on this machine requires seeding `~/.wp-env/<hash>/wp-env-cache.json` with `latestWordPressVersion`.

**Status:** Merged via PR #10. Follow-up PR #11 added README updates and docs/TESTING.md.

---

## Previous Session: 2026-02-06

### Updated Python Backend README and Environment Examples

**Status:** ✅ COMPLETE

**Cross-repo work:** Python Backend documentation updates

**What Was Done:**

1. **Updated README.md** with current project structure:
   - Complete directory tree with new folders (channels, employees, v2, config, extractors)
   - New features section (Digital Employees, file uploads, extraction)
   - Updated architecture section (employees, channels, shared utilities)
   - New sections for Digital Employees and File Upload systems
   - Updated installation steps with Azurite setup
   - Reorganized documentation section by categories

2. **Updated environment variable example files** (`.env.example` and `.env.dev.example`):
   - Added upload/storage configuration
   - Added AZURE_FOUNDRY_API_KEY
   - Added DALL-E deployment configuration
   - Comprehensive documentation for all variables

**Impact on def-core:**
No changes needed in WordPress plugin. These are Python backend documentation updates that help developers understand the full system architecture and configuration.

---

### Updated Python Backend Environment Variable Examples

**Status:** ✅ COMPLETE

**Cross-repo work:** Python Backend environment configuration documentation

**What Was Done:**
Updated `.env.example` and `.env.dev.example` in the Python backend to include recently added upload and storage configuration variables:

**Added to `.env.example`:**
- `AZURE_STORAGE_CONNECTION_STRING` - Required for file upload feature
- `AZURE_STORAGE_CONTAINER` - Blob container name (default: def-uploads)
- `UPLOAD_MAX_FILE_MB` - Max file size (default: 10)
- `UPLOAD_MAX_FILES_PER_MESSAGE` - Max files per message (default: 3)
- `UPLOAD_RETENTION_DAYS` - File retention period (default: 7)

**Added to `.env.dev.example`:**
- Same storage/upload variables for development testing
- WordPress bridge configuration examples (for local testing)
- Azurite Docker setup instructions for local blob storage emulation

**Variables Introduced By:**
- Upload tool implementation (commit `900286a`, Feb 2, 2026)
- Local storage Docker setup (commit `5bbd0a4`, Feb 2, 2026)

**Impact on def-core:**
No changes needed in WordPress plugin. These are Python backend configuration variables that support the upload feature already integrated in the customer chat UI.

---

### Enhanced File Selection & Upload UI Fixes

**Status:** ✅ COMPLETE

**Objective:** Fix missing parameters error when extract tool is called without explicit file references.

**Status:** ✅ COMPLETE (Python backend changes only)

**What Was Done:**

**Python Backend Changes:**

1. **Parameter Resolution:**
   - Updated `extract_upload_content_tool` to resolve missing `file_id`/`conversation_id` from thread context
   - Updated dispatcher to automatically pass `thread_state` to tools that need it
   - Tool handles both explicit parameters (auto-extraction) and implicit references (LLM intent)
   - Extraction payload caching retained as requested

2. **Smart File Selection:**
   - Enhanced filename matching to understand numeric selections ("1", "2", "3")
   - Added keyword support ("latest", "newest", "first", "last")
   - Improved partial filename matching (multi-word match)
   - Users can now easily select files from clarification lists

**Python Files Modified:**
- `app/chatbot/tools/extract_upload.py` - Parameter resolution
- `app/chatbot/core/tool_dispatcher.py` - Auto-pass thread_state
- `app/chatbot/core/recent_uploads.py` - Enhanced filename matching

**WordPress Frontend Changes:**

3. **Fixed Upload Icon After Clear Conversation:**
   - Added `checkUploadEligibility()` call in `clearConversation()` function
   - Upload icon now properly hides when resetting to Sales Assistant (default)
   - Icon visibility correctly reflects active employee's capabilities

**WordPress Files Modified:**
- `app/chatbot/v2/static/js/shell.js` - Added upload eligibility check after clear

**Result:** Upload icon visibility now works correctly in all scenarios (employee switching, conversation clearing, file selection).

**See Python repo CLAUDE_NOTES.md for full technical details.**

---

## Previous Session: 2026-02-02

### Python Backend: Governed Upload Tool + Tool Search Implemented

**Upload Tool (v1.0):** The Python backend now has REST API endpoints for file uploads:
- `POST /api/{channel}/uploads/init` - Initialize upload, get presigned URL
- `POST /api/{channel}/uploads/commit` - Finalize upload
- `GET /api/{channel}/uploads/{file_id}/status` - Check status

**Tool Search Pipeline (v1.0):** Server-side tool discovery with 3-stage filtering:
- Stage 1: Hard filtering (channel, auth, profile, capabilities)
- Stage 2: Relevance scoring (keyword matching)
- Stage 3: Selection cap (max 3 tools per turn)
- Upload tool special rules (never proactive)
- Token savings: ~70% reduction (500 → 150 tokens per turn)

**Available for channels:** `customer` and `staff_ai`

**WordPress Plugin Status:**
- ✅ Backend REST API complete (Python)
- ✅ Tool search integrated into LLM orchestration
- ⚠️ Frontend UI integration pending (no changes to WordPress plugin yet)
- 📋 Future: May need JavaScript changes in `def-core.js` for file picker UI
- 📋 Future: May need PHP changes for upload-related admin settings

**See Python repo `CLAUDE_NOTES.md` for full implementation details.**

---

## Previous Session: 2026-01-30

### RALPH-REBUILD-LOOP-5B.1: Post-Login Auth Rehydration (NO RELOAD)

**Issue:** After inline login (without page reload), the `/context-token` endpoint was returning 403 "rest_cookie_invalid_nonce" due to browser cookie/nonce synchronization timing issues.

**Solution:** Frontend (Python repo) now uses token from login response directly and implements 5-second cooldown to prevent premature `/context-token` calls while cookies/nonces sync.

**WordPress plugin changes for silent token refresh:**

**File:** `assets/js/def-core.js`

Added handler for silent page reload (triggered when token refresh fails before expiry):

```javascript
// Handle silent reload request (token refresh before expiry)
if (data?.type === "a3ai:silent-reload") {
  console.log("[DEF-BRIDGE] Silent page reload requested (token refresh)");
  window.location.reload();
}
```

**Why needed:**
- JWT tokens expire after 5 minutes
- Frontend attempts to refresh token at 4 minutes
- If refresh fails (WordPress nonce not synced yet), trigger silent reload
- Reload gets fresh cookies/nonces, user stays authenticated
- Chatbot state preserved (user doesn't notice)

**Result:** After inline login, auth upgrade happens immediately without page reload. Conversation continues on same thread with authenticated capabilities. User stays continuously authenticated via proactive token refresh or silent reload fallback.

### Branch: `staff-ai-frontend`

---

## Previous Session: 2026-01-26

### Completed Task: Loop 6 Fix - Post-Login Token Issue

**Issue:** After successful inline login, the widget couldn't fetch a fresh JWT token because `wp_set_auth_cookie()` sets cookies in HTTP response headers, but the JS `fetchToken()` call happened before the browser processed the response.

**Fix:** Generate the JWT token directly in the AJAX login response.

#### 1) PHP: Include Token in Login Response
**File:** `includes/class-def-core.php`

Modified `ajax_inline_login()` to generate the JWT token after successful login and include it in the response:
```php
$token = DEF_Core_JWT::issue_token( $claims, 300 );
wp_send_json_success( array( 'user_id' => $user->ID, 'token' => $token ) );
```

#### 2) JS Bridge: Use Token from Response
**File:** `assets/js/def-core.js`

Modified `performLogin()` to use the token directly from the AJAX response instead of making another fetch call.

### Branch: `staff-ai-frontend`

---

### Previous Task: Loop 6 - Inline Login Support

**Objective:** Add WordPress-side support for inline login in the Customer Chat widget iframe.

**Reference Documents:**
- `digital-employee-framework/docs/channels/customer/RALPH-LOOP-6-CUSTOMER-CHAT-FRONTEND-INLINE-LOGIN-(NON-BLOCKING)-CONTINUE-THREAD.md`

### Implementation Summary:

#### 1) Bridge Script Updates
**File:** `assets/js/def-core.js`

- Added `performLogin()` function - calls AJAX endpoint for authentication
- Added handler for `a3ai:login-request` message from iframe
- Added `a3ai:site-config` message to send siteUrl to iframe on context request
- Bridge now responds to both `a3ai:request-context` and `a3ai:login-request`

#### 2) PHP Configuration Updates
**File:** `includes/class-def-core.php`

- Added `loginUrl` (admin-ajax.php) to DEFCore JS configuration
- Added `siteUrl` (home_url) to DEFCore JS configuration
- Registered AJAX handlers:
  - `wp_ajax_nopriv_def_core_inline_login` (for logged-out users)
  - `wp_ajax_def_core_inline_login` (for already logged-in users)

#### 3) AJAX Login Handler
**File:** `includes/class-def-core.php`

Added `ajax_inline_login()` method:
- Verifies wp_rest nonce
- Gets username/password from POST
- Uses `wp_signon()` for standard WordPress authentication
- This respects any login plugins (2FA, reCAPTCHA, SSO, etc.)
- On success: sets auth cookie via `wp_set_auth_cookie()`
- On failure: returns sanitized error message
- Returns JSON response for AJAX

### PostMessage Protocol:

**Login Request (iframe → parent):**
```javascript
{ type: "a3ai:login-request", username: "...", password: "..." }
```

**Login Result (parent → iframe):**
```javascript
// Success
{ type: "a3ai:login-result", success: true, token: "..." }

// Failure
{ type: "a3ai:login-result", success: false, error: "..." }
```

**Site Config (parent → iframe):**
```javascript
{ type: "a3ai:site-config", siteUrl: "https://..." }
```

### Security Notes:
- Uses standard `wp_signon()` - compatible with all login security plugins
- Nonce verified via `check_ajax_referer()`
- Error messages sanitized to avoid leaking sensitive info
- Cookie set with SSL flag based on site configuration

### Branch: `staff-ai-frontend`

---

## Previous Session: 2026-01-23

### Completed Task: Service Auth for Escalation Endpoints

**Objective:** Add service-to-service authentication for escalation endpoints so Python can call them for anonymous users (without WP user JWT).

**Reference Documents:**
- `docs/ai-prompts/def-core/DEF_CORE_SERVICE_AUTH_PROMPT.md`
- `docs/platform/api/ESCALATION-EMAIL-BRIDGE-API-CONTRACT.md`

### Problem:
Anonymous users don't have a JWT token, so escalation endpoints (which required JWT auth) would fail for anonymous customer escalation.

### Solution:
Added a **shared secret** authentication mechanism that runs **alongside** (not replacing) the existing JWT auth:
- If `X-DEF-AUTH` header matches stored secret → allow access
- Otherwise → fall back to JWT authentication (for logged-in users)

### Implementation Details:

**File Modified:** `includes/class-def-core-escalation.php`

1. **New Constants:**
   - `SERVICE_SECRET_OPTION = 'def_service_auth_secret'` - WP option key
   - `SERVICE_AUTH_HEADER = 'X-DEF-AUTH'` - Header name for service auth

2. **New Methods:**
   - `get_service_secret()` - Gets or generates the shared secret (stored in wp_options)
   - `validate_service_auth()` - Validates X-DEF-AUTH header against stored secret

3. **Updated `permission_check()`:**
   - Now accepts EITHER service auth (X-DEF-AUTH) OR JWT auth
   - Service auth is checked first, then falls back to JWT

### How to Configure:

1. **WordPress Admin UI:**
   - Go to Settings → Digital Employees
   - Find "Service Authentication" section
   - The secret is displayed in a read-only field (auto-generated on first access)
   - Click "Copy" to copy to clipboard
   - Click "Generate New Secret" to regenerate (shows confirmation dialog)

2. **Python (.env):**
   - Add to `.env`: `DEF_SERVICE_AUTH_SECRET=<secret_from_wordpress>`

### Admin UI Implementation:

**File Modified:** `includes/class-def-core-admin.php`
- Added `wp_ajax_def_core_regenerate_service_secret` action
- Added `render_service_auth_section()` - Section description
- Added `render_service_auth_secret_field()` - Read-only input with Copy button
- Added `ajax_regenerate_service_secret()` - AJAX handler for regeneration

**File Modified:** `assets/js/def-core-admin.js`
- Added click handler for regenerate button
- Shows confirmation dialog before regenerating
- Updates UI after successful regeneration
- Shows reminder alert to update Python .env

### Bug Fix: Secret Generation (2026-01-23)

**Issue:** `wp_generate_password(64, true, true)` generates secrets with special characters that get altered by `sanitize_text_field()` and `wp_unslash()` during HTTP header validation, causing auth failures.

**Fix:** Changed to `bin2hex(random_bytes(32))` which generates 64 hex characters (alphanumeric only, HTTP-header safe).

**Also fixed:** AJAX handler now uses `get_service_secret(true)` instead of separate generation code.

### Security Notes:
- Uses constant-time comparison (`hash_equals`) to prevent timing attacks
- Secret is never logged or returned in responses
- Existing JWT flow remains unchanged for logged-in users
- Secret uses alphanumeric characters only (hex) to avoid HTTP header sanitization issues

### Current Status:
- ✅ Service auth implemented in def-core
- ✅ Python updated to use X-DEF-AUTH for anonymous users
- ✅ Backward compatible (JWT auth still works for logged-in users)

### Branch: `staff-ai-frontend`

---

## Previous Session: 2026-01-21

### Bug Fix: Escalation Email Formatting

**Issue:** Escalation email content was hard to read - displayed as one paragraph.

**Root Cause:**
`send_escalation_email()` used `wp_kses_post()` to sanitize the email body, but this function is designed for HTML content and can mangle plain text newlines.

**Fix Applied:**
- `includes/class-def-core-escalation.php` line 225
- Changed: `wp_kses_post( $body['body'] ?? '' )`
- To: `sanitize_textarea_field( $body['body'] ?? '' )`

`sanitize_textarea_field()` properly preserves newlines for plain text emails while still sanitizing input.

---

### Completed Task: Escalation Email Bridge Implementation

**Objective:** Implement def-core REST endpoints for escalation settings and email sending per ESCALATION_EMAIL_BRIDGE_API_CONTRACT.md

**Reference Documents:**
- `docs/platform/api/ESCALATION-EMAIL-BRIDGE-API-CONTRACT.md`
- `docs/platform/ESCALATION_RULES.md`

### Files Created:
- `includes/class-def-core-escalation.php` - New escalation handler class

### Files Modified:
- `includes/class-def-core.php` - Added escalation class loading and initialization

### Endpoints Implemented:

#### A) GET /wp-json/a3-ai/v1/settings/escalation?channel=<channel_id>
- Returns channel-scoped escalation settings
- Validates channel (customer, staff_ai, setup_assistant)
- Returns to/cc/bcc email arrays, sender_email, reply_to_mode, etc.
- Defaults to WP admin_email for recipients
- Customer channel reply_to_mode forced to "user_email"
- Staff AI channel includes allowed_recipients array

#### B) POST /wp-json/a3-ai/v1/escalation/send-email
- Sends escalation email via wp_mail()
- Validates required fields (channel, subject, body)
- Populates recipients from settings if not provided
- Respects reply_to header for customer channel
- Sends user copy with "Copy:" prefix if requested
- Returns {"status": "sent"} or {"status": "failed", "error": "..."}

### Authentication:
- Both endpoints use existing def-core JWT authentication (DEF_Core_Tools::permission_check())

### Settings Storage:
- Settings stored in WP options with key: `def_core_escalation_{channel}`
- Includes helper method `save_channel_settings()` for future admin UI

### Current Status:
- ✅ Both endpoints implemented per API contract
- ✅ Settings fetch with defaults
- ✅ Email sending via wp_mail()
- ✅ User copy support
- ✅ Staff AI allowed_recipients constraint
- ⚠️ Admin UI for settings not implemented (not in scope per prompt)

### Branch: `staff-ai-frontend`

---

## Previous Session: 2026-01-16

### What was done:
- User asked about Azure models used in Python app
- Reviewed `.env` file from `digital-employee-framework` repo
- Confirmed Azure OpenAI configuration:
  - Main model: `gpt-4o-mini` (for chat, agent, intent)
  - Embeddings: `text-embedding-ada-002`
  - Image: `dall-e-3`
  - API version: `2024-12-01-preview`
  - Endpoint: `a3-ai-foundry.openai.azure.com`

### Current status:
- Linked to Python backend repo
- Session notes system established
- Real-time logging of conversations enabled

### Next steps:
- Check for any WordPress plugin updates needed to match Python backend changes

---

## Previous Session: 2026-01-16

### What was done:
- Set up cross-repo connection with `digital-employee-framework`
- Created `CLAUDE.md` for project context
- Configured session notes system for continuity across sessions

---
<!-- Previous sessions will be logged above this line -->
