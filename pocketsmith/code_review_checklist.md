# PocketSmith Bridge Code Review Checklist

## Pre-Commit Checklist

### Syntax Validation
- [ ] Every variable has `$` prefix (check for missing `$`)
- [ ] Function name is `pocketsmith_generate_pkce()` not `pck`
- [ ] Variable is `$auth_state` not `$arch_state`
- [ ] All lines ending with `{`, `[`, `(` have semicolons
- [ ] All opening `{` have matching `}`
- [ ] All opening `[` have matching `]`
- [ ] All opening `(` have matching `)`
- [ ] Every PHP statement ends with `;`

### Function Definitions
- [ ] `pocketsmith_get_config()` is defined
- [ ] `pocketsmith_generate_pkce()` is defined
- [ ] `pocketsmith_save_session()` is defined
- [ ] `pocketsmith_load_session()` is defined
- [ ] `pocketsmith_mcp_request()` is defined
- [ ] All function calls match definitions (same number of params, same order)

### MCP Protocol
- [ ] Function signature: `pocketsmith_mcp_request(string $token, string $tool, array $params)`
- [ ] JSON-RPC payload uses `$tool` directly as `method` field
- [ ] NOT: `'method' => 'tools/call'`
- [ ] IS: `'method' => $tool` (where $tool = "list_accounts", "get_current_user", etc.)
- [ ] Accept header includes: `Accept: application/json, text/event/stream`
- [ ] Authorization header: `Authorization: Bearer {token}`

### .env Loading
- [ ] Search multiple paths: `_SCRIPT_FILENAME_/includes/.env`, `_SCRIPT_FILENAME_/.env`, `__DIR__/.env`
- [ ] NOT hardcoded to single path
- [ ] Gracefully handles missing .env file

### Security
- [ ] Session tokens hashed by IP address
- [ ] Secrets validated before OAuth or sensitive operations
- [ ] No hardcoded credentials in code
- [ ] Sensitive data not logged or returned to client

### Testing
- [ ] Health endpoint returns JSON with status and env_loaded
- [ ] Auth flow includes PKCE (proof verifier, challenge, state)
- [ ] Token exchange handles both direct and session token formats
- [ ] Action handler maps: `accounts → list_accounts`, `me → get_current_user`

### Deployment Check
- [ ] Files pushed to GitHub (main branch)
- [ ] Server files updated (if separate deployment needed)
- [ ] Test endpoints in browser (bypass BitNinja if needed)
- [ ] Verify .env exists on server

---

## Error Pattern Quick Reference

| Symptom | Fix |
|---------|-----|
| `Call to undefined function pocketsmith_generate_pck()` | Change to `pocketsmith_generate_pkce()` |
| `Undefined variable: includePath` | Add `$` -> `$includePath` |
| `.env not found` | Add multiple path search |
| `Invalid params` MCP error | Use direct tool name, not `tools/call` |
| Missing Accept header | Add `Accept: application/json, text/event/stream` |

---

## Run Before Every Commit

```bash
cd pocketsmith
bash syntax_check.sh
```

This script will catch:
- Missing `$` on variables
- Wrong function names (`pck` vs `pkce`)
- Wrong variable names (`arch_state` vs `auth_state`)
- Unbalanced braces
- Wrong MCP protocol structure
