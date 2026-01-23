# Session Notes - def-core (WordPress Plugin)

## Latest Session: 2026-01-23

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
