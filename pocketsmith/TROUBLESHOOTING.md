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

## Filtering, Search & Pagination

Any query param besides `action`, `secret`, and `bot_token` is forwarded directly as an
argument to the MCP tool (see `pocketsmith_build_tool_args()` in `includes/pocketsmith.php`).
`user_id`, `page`, `interval`, `account_id`, `category_id`, `transaction_account_id`,
`uncategorised`, `needs_review`, `since_id`, `per_page`, and `limit` are cast to integers;
everything else is passed as a trimmed string.

### Finding account IDs to filter with

```
?action=list_transaction_accounts&user_id=85571&bot_token=<TOKEN>
```

Each item in `transaction_accounts` has two relevant IDs:

- `id` (e.g. `218775`) — the transaction account's own ID. Use this for `account_id` when
  filtering `list_transaction_accounts` itself.
- `account_id` (e.g. `126196`) — the parent account ID. Use this for `account_id` when
  filtering `list_transactions`.

```
# 1. Find the account (look for "name": "BNZ Classic Visa" in the response)
?action=list_transaction_accounts&user_id=85571&bot_token=<TOKEN>&search=visa

# 2. Use its "id" (218775) to filter the accounts list itself
?action=list_transaction_accounts&user_id=85571&bot_token=<TOKEN>&account_id=218775

# 3. Use its "account_id" (126196) to filter that account's transactions
?action=list_transactions&user_id=85571&bot_token=<TOKEN>&account_id=126196&start_date=2026-08-01&end_date=2026-08-28
```

### `list_transactions`

Supported filters (server-side, confirmed live): `start_date`, `end_date`, `search`,
`page`, `type` (`debit`/`credit`), `uncategorised`, `needs_review`, `updated_since`,
and `account_id`.

```
?action=list_transactions&user_id=85571&bot_token=<TOKEN>&start_date=2026-08-01&end_date=2026-08-28
?action=list_transactions&user_id=85571&bot_token=<TOKEN>&start_date=2026-08-01&end_date=2026-08-28&search=groceries
?action=list_transactions&user_id=85571&bot_token=<TOKEN>&start_date=2026-08-01&end_date=2026-08-28&account_id=126196
```

**Important:** `account_id` must be the *parent account* ID — i.e. the
`transaction_account.account_id` field in a transaction's response (e.g. `126196`), not
`transaction_account.id` (e.g. `218775`). Using the latter returns
`"Error: That resource was not found"`.

Responses are paginated by the MCP server itself and come back as
`"Page X of Y (N total)\n\n<json array>"` inside `response.result.content[0].text` — use
narrow date ranges (the wider the range, the larger the text payload, even with few
results) to avoid truncation over the Tailscale relay.

### `get_budget_summary`

All four params are required: `period` (`weeks`/`months`/`years`/`event`), `interval`,
`start_date`, `end_date`.

```
?action=get_budget_summary&user_id=85571&bot_token=<TOKEN>&period=months&interval=1&start_date=2026-07-01&end_date=2026-07-31
```

### `list_transaction_accounts`

The underlying PocketSmith endpoint has no server-side filters, so the bridge fetches the
full list and filters/paginates it in PHP (`pocketsmith_filter_transaction_accounts()`).
Response shape is normalized to `{"status": ..., "count": ..., "transaction_accounts": [...]}`.

```
?action=list_transaction_accounts&user_id=85571&bot_token=<TOKEN>&limit=5
?action=list_transaction_accounts&user_id=85571&bot_token=<TOKEN>&account_id=218775
?action=list_transaction_accounts&user_id=85571&bot_token=<TOKEN>&search=visa
?action=list_transaction_accounts&user_id=85571&bot_token=<TOKEN>&page=2&per_page=10
```

Note: unlike `list_transactions`, `account_id` here matches the transaction account's own
`id` field (e.g. `218775`), since it's filtering the transaction-accounts list itself.

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
