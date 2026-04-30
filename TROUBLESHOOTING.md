# PocketSmith MCP Bridge - Troubleshooting Guide

## Common Issues and Solutions

### 1. **"Blank page" or 500 errors**
**Cause:** Missing `$` on variables or syntax errors
**Fix:** Check `pocketsmith/includes/pocketsmith.php` and `pocketsmith/index.php`
- Every variable must have a `$` prefix
- Every line must end with a `;`
- Use `php -l pocketsmith.php` to lint check

### 2. **"Call to undefined function pocketsmith_generate_pkce()"**
**Cause:** Function name typo
**Fix:** The function is EXACTLY named `pocketsmith_generate_pkc` (3 letters: `pkc`, NOT `pkce`, NOT `pck`)
- Check that both `index.php` and `pocketsmith.php` use `pocketsmith_generate_pkc`
- The comment explicitly states: `DEFINITIVE NAME: pocketsmith_generate_pkc`

### 3. **"Method not found" error or -32601**
**Cause:** Using wrong JSON-RPC method format
**Fix**: 
- **NO `tools/call` wrapper** - PocketSmith MCP-readonly uses direct method names
- The `method` field should be the raw method name: `accounts.list`, `user.get`, `tools.list`
- Example payload: `{"jsonrpc":"2.0","method":"accounts.list","params":{},"id":"123"}`

### 4. **"ERR_TUNNEL_CONNECTION_FAILED" or BitNinja CAPTCHA**
**Cause:** The host `ps.tinypeople.mesh.net.nz` often requires authentication or is unreachable
**Fix:** Test against the public endpoint `https://mcp-readonly.pocketsmith.com/mcp` instead

### 5. **"Undefined constant SCRIPT_FILENAME"**
**Cause:** Using `SCRIPT_FILENAME` without $ sign
**Fix:** Always use `$_SERVER['SCRIPT_FILENAME']` (the superglobal array)
- `SCRIPT_FILENAME` alone is NOT a PHP constant
- Use `$_SERVER['SCRIPT_FILENAME']` for the script path

### 6. **"No access token found in session"**
**Cause:** User hasn't completed OAuth flow or session expired
**Fix:** Visit the auth link again with your secret key

## File Structure
- `pocketsmith/index.php` - Main entry point (handles HTTP requests)
- `pocketsmith/includes/pocketsmith.php` - Core functions (OAuth, JSON-RPC)

## Quick Syntax Check
```bash
php -l pocketsmith/index.php
php -l pocketsmith/includes/pocketsmith.php
```

## Protocol Summary
**IMPORTANT:** PocketSmith MCP-readonly does NOT use standard MCP 'tools/call' wrappers. You MUST use the tool name (e.g. 'accounts.list', 'user.get', 'tools.list') directly as the JSON-RPC 'method' field.