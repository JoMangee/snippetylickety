# PocketSmith Bridge Troubleshooting Guide

## Endpoint Summary

Base URL:
`https://your-domain.com/pocketsmith/index.php`

Local actions handled by the bridge:

- `action=health`
- `action=bot_token` (requires `secret`)
- `action=session_status` (requires `secret` or `bot_token`)
- `action=session_debug` (requires `secret` or `bot_token`)
- `action=auth` (requires `secret`)
- `action=debug` (requires `secret` or `bot_token`)
- `action=list_tools` (requires `secret` or `bot_token`)

All other actions are treated as PocketSmith MCP tool names and passed through directly.

## Tool Naming (Important)

Use PocketSmith MCP tool names exactly as documented, using underscore style.

Examples:

- `list_accounts`
- `get_current_user`
- `list_transactions`
- `list_categories`
- `get_budget_summary`

Do not use dot notation here:

- Wrong: `accounts.list`
- Correct: `list_accounts`

## Authentication Flow

### 1. Get short-lived bot token

Request:
`?action=bot_token&secret=YOUR_SECRET`

Response includes:

- `bot_token`
- `expires_in`
- `expires_at`
- `window_seconds`

### 2. Authenticate with PocketSmith OAuth

Request:
`?action=auth&secret=YOUR_SECRET`

This redirects to PocketSmith OAuth and back with `?code=...`.

### 3. Verify saved session

Request:
`?action=session_status&bot_token=YOUR_BOT_TOKEN`

Expected success:

```json
{
  "status": "authenticated",
  "token_expires_at": "...",
  "token_valid": true
}
```

## Session Debug Endpoint

Use this when token/session behavior is unclear:

`?action=session_debug&bot_token=YOUR_BOT_TOKEN`

It shows:

- where `ps_session.json` is expected
- whether it exists and is readable
- whether token metadata exists
- whether token is expired

No token values are returned.

## Common Errors

### `Unauthorized - Invalid secret`

Cause:

- wrong or missing `secret` for actions that require it

Fix:

- verify `POCKETSMITH_BOT_SECRET` in `.env`
- for non-privileged actions, use `bot_token`

### `Unauthorized - Invalid secret or bot_token`

Cause:

- no valid credential provided

Fix:

- get a fresh bot token via `action=bot_token`
- retry with `bot_token=...`

### `Unauthorized - No access token`

Cause:

- OAuth callback did not complete or session file not found

Fix:

- run `action=auth`
- check `action=session_debug`

### `Method not found`

Cause:

- action name is not a valid PocketSmith MCP tool

Fix:

- call `action=list_tools` to see currently supported list in the bridge
- use official PocketSmith tool names (underscore style)

### `Invalid action format`

Cause:

- action contains unsupported characters or wrong format

Fix:

- use one of local actions or MCP tool name format: `^[a-z][a-z0-9_]*$`

## .env Keys

Required:

- `POCKETSMITH_DEVELOPER_KEY`
- `POCKETSMITH_REDIRECT_URI`
- `POCKETSMITH_BOT_SECRET`

Optional:

- `POCKETSMITH_TOKEN_WINDOW` (default: `900` seconds)
- `POCKETSMITH_SESSION_DIR` (default: `includes/` path used by helper)

## Quick Checks Before Deploy

1. `action=health` returns JSON status
2. `action=bot_token` returns a token
3. `action=auth` completes and returns `Authenticated!`
4. `action=session_status` shows `authenticated`
5. `action=list_accounts` returns MCP response JSON
