# PocketSmith Bridge Troubleshooting Guide

## Common Issues & Fixes

### 1. Missing `$` Prefix on Variables

**Symptom:** Blank page or fatal error

**Example of WRONG:**
```php
includePath = __DIR__ . '/includes/pocketsmith.php';  // MISSING $ 
```

**CORRECT:**
```php
$includePath = __DIR__ . '/includes/pocketsmith.php';  // HAS $ 
```

**Fix:** Always verify every variable name starts with `$`

---

### 2. Function Name Typos: pck vs pkc vs pkce

**Symptom:** Fatal error - Call to undefined function.

**The correct function name is:** `pocketsmith_generate_pkce()` (PKCE = Proof Key for Code Exchange)

**WRONG:**
```php
$pck = pocketsmith_generate_pck();  // 'pck' - missing 'ce'
```

**CORRECT:**
```php
$pck = pocketsmith_generate_pkce();  // 'pkce' - proper PKCE acronym
```

**Fix:** Always use `pocketsmith_generate_pkce()` as defined in the function signature

---

### 3. Auth State Variable: arch_state vs auth_state

**Symptom:** Session state not saved properly, OAuth state mismatch

**WRONG:**
```php
$arch_state = bin2hex(random_bytes(16));  // 'arch' instead of 'auth'
```

**CORRECT:**
```php
$auth_state = bin2hex(random_bytes(16));  // 'auth' - authentication
```

**Fix:** Always use `$auth_state` (spelled "authentication state")

---

### 4. JSON-RPC Protocol: tools/call vs Direct Method

**Symptom:** MCP error "Invalid params" or "Tool not found"

**WRONG Structure:**
```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": {
    "name": "list_accounts",
    "arguments": {...}
  }
}
```

**CORRECT Structure (PocketSmith uses direct tool names):**
```json
{
  "jsonrpc": "2.0",
  "method": "list_accounts",
  "params": {...}
}
```

**Function signature:**
```php
pocketsmith_mcp_request(string $token, string $tool, array $params)
```

The `$tool` parameter is used directly as the JSON-RPC `method` field.

---

### 5. Missing Accept Header for MCP Requests

**Symptom:** MCP server rejects request or returns HTML error page

**WRONG (missing Accept header):**
```php
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
]);
```

**CORRECT (must include Accept header):**
```php
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json, text/event-stream',  // ADD THIS
    'Authorization: Bearer ' . $token
]);
```

**Fix:** Always include `'Accept: application/json, text/event-stream'` header

---

### 6. .env Loading Path Issues

**Symptom:** ".env not found" even when file exists

**Root Cause:** Script working directory vs file directory confusion

**WRONG (relative to script execution):**
```php
$envPath = SCRIPT_FILENAME . '/.env';  // Fails if run from different directory
```

**CORRECT (search multiple locations):**
```php
// Try multiple paths in order
$paths = [
    SCRIPT_FILENAME . '/includes/.env',  // includes/ dir
    SCRIPT_FILENAME . '/.env',           // script dir
    __DIR__ . '/includes/.env',         // __DIR__ relative
];
foreach ($paths as $path) {
    if (file_exists($path)) {
        $envPath = $path;
        break;
    }
}
```

**Fix:** Always search multiple paths; don't assume script runs from same directory

---

## Available Endpoints (Agent Reference)

All endpoints are at `https://your-domain.com/pocketsmith/index.php`.

| Action | Auth Required | Description |
|--------|--------------|-------------|
| `?action=health` | None | Check if the service is running |
| `?action=bot_token&secret=SECRET` | Full secret | Get a short-lived bot token (valid for `TOKEN_WINDOW` seconds, default 15 min) |
| `?action=session_status&secret=SECRET` or `&bot_token=TOKEN` | Secret or bot token | Check if OAuth session is authenticated and token expiry |
| `?action=session_debug&secret=SECRET` or `&bot_token=TOKEN` | Secret or bot token | Diagnose session file path and state (no secrets exposed) |
| `?action=auth&secret=SECRET` | Full secret only | Initiate OAuth flow — redirects to PocketSmith login |
| `?action=accounts&bot_token=TOKEN` | Secret or bot token | List accounts via MCP |
| `?action=me&bot_token=TOKEN` | Secret or bot token | Get current user via MCP |
| `?action=list_tools&bot_token=TOKEN` | Secret or bot token | List available MCP tools |
| `?action=debug&bot_token=TOKEN` | Secret or bot token | Raw MCP response for `accounts.list` |
| `?action=TOOL_NAME&bot_token=TOKEN` | Secret or bot token | Call any MCP tool by name (e.g. `categories.list`) |

### Bot Token Flow (Recommended for Agents)

1. **Obtain a token** (once, using full secret):
   ```
   GET ?action=bot_token&secret=YOUR_SECRET
   → {"bot_token": "a3f9c12d...", "expires_in": 743, "expires_at": "...", "window_seconds": 900}
   ```

2. **Use the token** for all subsequent requests:
   ```
   GET ?action=accounts&bot_token=a3f9c12d...
   GET ?action=session_status&bot_token=a3f9c12d...
   ```

3. **Token windows** are configurable via `POCKETSMITH_TOKEN_WINDOW` in `.env` (default: 900 seconds). The previous window is also accepted to avoid failures at window boundaries.

4. **`auth` always requires the full secret** — OAuth initiation is a privileged action and bot tokens cannot be used for it.

### .env Keys Reference

| Key | Required | Default | Description |
|-----|----------|---------|-------------|
| `POCKETSMITH_DEVELOPER_KEY` | Yes | — | Your PocketSmith developer key |
| `POCKETSMITH_REDIRECT_URI` | Yes | — | OAuth redirect URI (must match PocketSmith app settings) |
| `POCKETSMITH_BOT_SECRET` | Yes | — | Secret used to authenticate requests |
| `POCKETSMITH_TOKEN_WINDOW` | No | `900` | Bot token validity window in seconds |
| `POCKETSMITH_SESSION_DIR` | No | `includes/` | Directory to store `ps_session.json` |

---

## Quick Checklist Before Deploying

1. ✅ Every variable has `$` prefix
2. ✅ Function name is `pocketsmith_generate_pkce()` not `pck`
3. ✅ Variable is `$auth_state` not `$arch_state`
4. ✅ `pocketsmith_mcp_request()` uses tool name directly as method
5. ✅ MCP request includes `Accept: application/json, text/event-stream` header
6. ✅ `.env` loader searches multiple paths
7. ✅ All lines ending with `{`, `[`, `(` have semicolons
8. ✅ All opening `{` have matching `}`
9. ✅ All function calls match defined function signatures

---

## Debug Commands

### Check .env loading:
```php
echo "SCRIPT_FILENAME: " . SCRIPT_FILENAME . "\n";
echo "__DIR__: " . __DIR__ . "\n";
print_r(array_filter(glob(__DIR__ . '/.env')));
```

### Test function exists:
```php
if (!function_exists('pocketsmith_generate_pkce')) {
    die('Function pocketsmith_generate_pkce NOT FOUND');
}
```

### Verify MCP request structure:
```php
$payload = json_encode([
    'jsonrpc' => '2.0',
    'method' => $tool,  // Should be: list_accounts, get_current_user, etc.
    'params' => (object)$params,
    'id' => uniqid()
], JSON_PRETTY_PRINT);
echo $payload;
```

---

## Error Messages Reference

| Error | Likely Cause | Fix |
|-------|--------------|-----|
| `Call to undefined function pocketsmith_generate_pck()` | Typo in function name | Use `pocketsmith_generate_pkce()` |
| `Undefined variable: includePath` | Missing `$` prefix | Add `$` to variable name |
| `Invalid params` or `Tool not found` | Wrong MCP protocol | Use direct tool name as method (e.g. `accounts.list`) |
| `.env not found` | Wrong path | Search multiple locations |
| `Blank page` | PHP fatal error | Check error logs or enable display_errors |
| `Unauthorized` | Missing or wrong secret/bot_token | Check `POCKETSMITH_BOT_SECRET` in `.env` |
| `{"error":"Not authenticated"}` | No session token saved | Visit `?action=auth&secret=...` to authenticate |
| `{"status":"not_authenticated"}` | Session file missing or expired | Re-authenticate via `?action=auth&secret=...` |
| `token_expired: true` from session_debug | OAuth token expired | Re-authenticate via `?action=auth&secret=...` |
| `session_file_exists: false` from session_debug | Session file in wrong location | Check `SESSION_DIR` in `.env` matches where session was saved |

---

## 4. Session Storage Location

- The session (OAuth token and related state) is saved as a base64-encoded JSON file named `ps_session.json`.
- By default, this file is stored in the `/includes` directory, which is permission-locked for security.
- You can override the storage location by setting `SESSION_DIR` in your `.env` file:
  
  ```
  SESSION_DIR=/your/custom/path
  ```
- If not set, the default is the `includes` directory next to your code.

### Diagnosing Session Path Problems

Use the built-in debug endpoint to check exactly where your session file is being read from and written to:

```
https://your-domain.com/pocketsmith/index.php?action=session_debug&secret=YOUR_SECRET
```

This returns JSON like:
```json
{
  "session_file_path": "/home/user/pocketsmith/includes/ps_session.json",
  "session_file_exists": true,
  "session_file_readable": true,
  "session_dir_writable": true,
  "has_access_token": true,
  "has_verifier": false,
  "has_created_at": true,
  "has_expires_in": true,
  "token_expired": false,
  "php_include_dir": "/home/user/pocketsmith"
}
```

**No token values are ever exposed by this endpoint.**

If `session_file_exists` is `false`, the session file is not where the code expects it. Check your `SESSION_DIR` in `.env` and ensure you have authenticated via `?action=auth&secret=...`.

If `has_access_token` is `false` but the file exists, the session may be corrupted or from an old format. Delete `ps_session.json` and re-authenticate.

### Troubleshooting Session Issues

- **Missing or unreadable session file:**
  - Ensure the directory exists and is writable by the web server or CLI user.
  - Check file permissions if you see repeated authentication prompts or lost sessions.

- **Session file not found in expected location:**
  - Confirm the `SESSION_DIR` value in your `.env` file is correct and points to a valid directory.
  - If you move your code, double-check the session directory path.

- **Security:**
  - The default `/includes` directory is not web-accessible if your `.htaccess` is set up correctly.
  - Do not store session files in a public web directory.

### How to Reset Session

- To force a new login, simply delete the `ps_session.json` file from the session directory.
