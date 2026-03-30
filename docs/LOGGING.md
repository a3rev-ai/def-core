# Connection Logging

Digital Employees includes a built-in connection logging system for diagnosing sync pipeline issues between your WordPress site and the DEF backend. It follows the same pattern as WooCommerce's gateway logging — structured, queryable logs stored in your WordPress database.

## Overview

The logger captures what flows between def-core and the DEF backend:

- **Sync requests and responses** — what was requested, what WordPress queried, what was returned
- **Auth handshakes** — connection tests, JWT validation
- **Tool calls** — employee tool execution via WordPress endpoints
- **Connection errors** — timeouts, HTTP errors, network failures

It does **not** log general WordPress activity, theme/plugin behavior, or user actions.

## Viewing Logs

Navigate to **Digital Employees > Logs** in wp-admin. The Logs page provides:

- **Log table** — newest first, color-coded by level (Debug=gray, Info=blue, Warning=amber, Error=red)
- **Filters** — Level dropdown, Source dropdown, free-text search across message, context, and request ID
- **Context inline** — structured key=value data displayed beneath each log message
- **Request ID** — truncated UUID for cross-system correlation (match with DEF backend logs)
- **Pagination** — 50 entries per page
- **Download CSV** — export filtered results for sharing or support tickets
- **Clear All** — delete all log entries with confirmation

**Requires:** `manage_options` capability (WordPress administrators only).

## Log Level Setting

Navigate to **Digital Employees > Settings > Connection** tab. At the bottom you'll find the **Logging** card with a Log Level dropdown:

| Level | What's logged | When to use |
|-------|--------------|-------------|
| **Debug** | Everything — including WP_Query details and SQL | Diagnosing sync issues (temporary) |
| **Info** | Requests received + responses sent | Normal operation (default) |
| **Warning** | Unexpected conditions | Monitoring |
| **Error** | Failures only | Quiet mode |

**Default: Info.** Set to Debug when diagnosing a sync problem, then revert to Info.

## What Gets Logged

### Sync Export Endpoints (at Debug level)

Each export request (`/content/export`, `/products/export`, `/forums/export`) generates up to 3 log entries:

1. **Request received** (INFO) — content type, page, per_page, modified_after
2. **WP_Query executed** (DEBUG) — the diagnostic entry:
   - `requested_per_page` — what the DEF backend asked for
   - `actual_posts_per_page` — what WordPress actually used (may differ if a plugin overrides it)
   - `per_page_modified` — `true` if a theme or plugin changed the query via `pre_get_posts`
   - `found_posts` / `post_count` — total items vs items on this page
   - `sql` — the actual SQL query WordPress executed
3. **Response sent** (INFO) — items returned, totals, page number

### Request ID Correlation

Every request from the DEF backend includes an `X-DEF-Request-ID` header (UUID). This ID is:
- Logged with every entry for that request
- Echoed back in the response header
- Logged on the DEF backend side too

This allows matching logs across both systems for a single request.

## Storage

Logs are stored in the `{prefix}def_core_logs` database table with indexed columns for fast filtering:

| Column | Purpose |
|--------|---------|
| `timestamp` | When the entry was created (UTC) |
| `level` | debug, info, warning, error |
| `source` | sync, auth, connection, tools |
| `message` | Human-readable summary |
| `context` | JSON-encoded structured data |
| `request_id` | UUID for cross-system correlation |

## Log Rotation

- **Daily cleanup** via WordPress cron (`def_core_log_cleanup`)
- **Retention**: 30 days (filterable via `def_core_log_retention_days`)
- **Row cap**: 50,000 entries (filterable via `def_core_log_max_entries`)
- **Emergency fallback**: probabilistic trim if cron hasn't fired

## Fail-Open Design

The logger is designed to **never break your site**:

- All database operations are wrapped in try/catch
- `$wpdb->insert()` return values are explicitly checked
- On any failure, the logger falls back to PHP's `error_log()` and returns silently
- A recursion guard prevents infinite loops
- Context JSON is always valid (fields truncated before encoding, safe fallbacks on encode failure)

## Customization

### Filters

```php
// Change retention period (default: 30 days)
add_filter( 'def_core_log_retention_days', function() { return 14; } );

// Change max entries (default: 50,000)
add_filter( 'def_core_log_max_entries', function() { return 25000; } );
```

### Using the Logger in Custom Code

```php
// Log a connection event
DEF_Core_Logger::info( DEF_Core_Logger::SOURCE_SYNC, 'My custom log message', [
    'key'        => 'value',
    'request_id' => $request_id,
] );

// Available levels: debug(), info(), warning(), error()
// Available sources: SOURCE_SYNC, SOURCE_AUTH, SOURCE_CONNECTION, SOURCE_TOOLS
```

## Diagnosing Common Issues

### Sync returning fewer items than expected

1. Set Log Level to **Debug**
2. Trigger a full sync from the DEFHO dashboard
3. Check the logs for `WP_Query executed` entries
4. Look for `per_page_modified: true` — this means a theme or plugin is overriding the query
5. Check the `actual_posts_per_page` vs `requested_per_page` values
6. The `sql` field shows the exact query WordPress ran

### Connection failures

Look for entries with level `error` and source `connection` or `auth`. The context will include error codes, status codes, and timing information.

## Requirements

- WordPress 6.2+ (required for `$wpdb->prepare('%i')` identifier placeholders)
- PHP 8.0+
