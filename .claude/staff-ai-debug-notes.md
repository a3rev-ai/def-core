# Staff AI Debug Notes

## Issue
User gets `staff_ai_backend_error` when logged in as management staff.

## Changes Made

### 1. Fixed missing method error
- Replaced `DEF_Core_JWT::issue_context_token()` (didn't exist) with inline JWT token generation using `DEF_Core_JWT::issue_token()`
- Removed unused `get_user_context()` method

### 2. Improved error messages
Error messages now include HTTP status codes and backend details:
- **Not configured**: "Staff AI backend URL is not configured. Go to Settings > Digital Employees to set the Staff AI API URL."
- **401/403**: "Backend auth failed (HTTP 401). The backend may need JWKS configuration. Detail: [message]"
- **404**: "Backend endpoint not found (HTTP 404): /api/my/threads"
- **500+**: "Backend service error (HTTP 500). The service may be temporarily unavailable."

### 3. Added status endpoint
`GET /wp-json/a3-ai/v1/staff-ai/status` shows:
- User info and capabilities
- API URL configuration
- JWKS URL (for backend to verify tokens)
- Token generation status
- Health check result
- Threads endpoint test result

### 4. UI shows errors
`loadConversations()` now displays errors in the UI banner instead of silently failing.

## User Should Check

1. **Refresh /staff-ai page** - Error banner should now show specific error with HTTP status code

2. **Check status endpoint**: `/wp-json/a3-ai/v1/staff-ai/status`
   - Is `config.api_url` set?
   - Is `threads_check.status` showing 200 or an error code?

3. **Check WordPress Settings**: Settings > Digital Employees
   - Is "Staff AI API URL" set? (e.g., `https://a3revai.azurewebsites.net`)

4. **Backend Configuration**:
   - Backend needs JWKS URL to verify WordPress JWT tokens
   - JWKS URL format: `https://your-site.com/wp-json/a3-ai/v1/jwks`

## Possible Causes

| Error Message | Cause | Solution |
|--------------|-------|----------|
| "not configured" | API URL not set | Set URL in Settings > Digital Employees |
| HTTP 401/403 | Backend rejects JWT | Configure backend with WordPress JWKS URL |
| HTTP 404 | Wrong endpoint path | Backend may use different API paths |
| HTTP 500 | Backend error | Check backend logs |
