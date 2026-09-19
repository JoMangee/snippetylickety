# Snippetylickety

Small standalone browser experiments and PHP utilities. The public landing page links to each experiment, including the modular Cooper Foodgame at `foodgame/`.

## Foodgame

Open `foodgame/index.html` (or deploy the `foodgame/` directory under a PHP HTTPS site). The game is vanilla HTML/CSS/JavaScript with one JSON-only PHP endpoint: no framework, build step, database, or committed secret.

### Final modular file list

- `foodgame/index.html` — small single-page shell and accessible form/panels; loads `style.css` and the three ES modules.
- `foodgame/style.css` — mobile-first, bright, bold, rounded Cooper-friendly UI; portrait and landscape layouts, large touch targets, and no hover-only behavior.
- `foodgame/js/game.js` — focused game rules and browser orchestration: XP, level curve, spice tolerance outcomes, Noodle Masterpiece buffs, key storage, and event flow.
- `foodgame/js/api.js` — focused GET client. It adds the API key to every request and requires JSON responses.
- `foodgame/js/ui.js` — focused DOM rendering for stats, history, feed, buff display, status, and activity log.
- `foodgame/api.php` — the only server file. It authenticates, rate-limits, validates, applies game rules, and reads/writes locked local JSON.
- `foodgame/.htaccess` — blocks public access to local storage and rate-limit lock files.

No token, private config, generated assets, or build output belongs in this repository.

## Token and cPanel deployment

Every request must include the `key` query parameter. The API compares it with `hash_equals`, and a missing or wrong key always returns HTTP 401 with exactly:

```json
{"ok":false,"error":"unauthorized"}
```

Put the private config outside the web root. For a typical cPanel layout, create `/home/ACCOUNT/foodgame-config.php` with mode `600`:

```php
<?php
const FOODGAME_API_KEY = 'GENERATE_A_LONG_RANDOM_TOKEN_HERE';
const FOODGAME_STORAGE_DIR = '/home/ACCOUNT/foodgame-data';
```

The public `foodgame/api.php` checks the private sibling config and one parent-level config location. If no private config is available, its deliberately unusable placeholder makes requests unauthorized. Never replace that placeholder in public code and never commit a real token. Generate one locally with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`.

Upload `foodgame/index.html`, `style.css`, `js/`, `api.php`, `.htaccess`, and this README to a PHP 8+ HTTPS site. Create the storage directory outside `public_html`, make it writable by the PHP user (normally `700`), and do not make it world-writable. Enable PHP 8.0+; PHP 8.1+ is recommended. Same-origin deployment is the default and no permissive CORS header is emitted.

The API creates `foodgame-data.json`, `foodgame-data.lock`, `rate.lock`, and hashed-IP rate files in the storage directory. Entries are append-only in the logical store: the API takes an exclusive file lock, appends a new entry, updates player stats, then atomically replaces the JSON file. Existing entries are not deleted by normal operation. Keep backups of this small local store; it is not a multi-server database.

## Exact GET API contract

Base URL: `https://YOUR-DOMAIN.example/foodgame/api.php`. All calls are GET and all responses are JSON. Parameters are URL-encoded. Every call includes `key`.

Actions are `stats`, `entries`, `feed`, `activity`, and `log`. `player` defaults to `Cooper` for `stats`, `entries`, and `log`. `feed` accepts an optional player: omitted means all players. `activity` accepts an optional player and an optional `since`.

Examples:

```sh
KEY='your-private-token'
BASE='https://YOUR-DOMAIN.example/foodgame/api.php'
curl -sG "$BASE" --data-urlencode "key=$KEY" --data-urlencode 'action=stats' --data-urlencode 'player=Cooper'
curl -sG "$BASE" --data-urlencode "key=$KEY" --data-urlencode 'action=log' --data-urlencode 'player=Cooper' --data-urlencode 'meal=noodle-masterpiece' --data-urlencode 'rating=8' --data-urlencode 'new=1'
curl -sG "$BASE" --data-urlencode "key=$KEY" --data-urlencode 'action=feed'
curl -sG "$BASE" --data-urlencode "key=$KEY" --data-urlencode 'action=activity' --data-urlencode 'player=Cooper' --data-urlencode 'since=42'
```

A successful log response includes `ok`, `player`, `meal`, `rating`, `new`, `xp_gained`, `entry`, `stats`, and recent `entries`. `stats` contains `level`, `total_xp`, `xp_progress`, `xp_needed`, `xp_to_next`, `streak`, `spice_tolerance`, and `meals_logged`.

`entries` returns `{ "ok": true, "player": "Cooper", "entries": [...] }` with that player's newest 25 entries. `feed` always returns a required `feed` key: `{ "ok": true, "feed": [...] }`. With no player it includes all players, newest first; ties are ordered by descending numeric `id` for stable ordering.

Each feed/history/activity entry has these required fields:

```json
{
  "id": 42,
  "player": "Cooper",
  "meal": "noodle-masterpiece",
  "rating": 8,
  "new": true,
  "xp_gained": 30,
  "level_after": 4,
  "timestamp_utc": "2026-09-19T08:30:00+00:00",
  "summary": {
    "meal_name": "Noodle Masterpiece",
    "spice": 8,
    "spice_result": "easy",
    "buff_chance_percent": 100,
    "buffs_applied": ["highly energized (2x base energy)", "heated engine (+70% speed, +20% acceleration)", "energy consumption 2x", "duration 15 minutes"]
  }
}
```

`summary` is optional for consumers, but is supplied by this implementation with the meal name and mechanics result. `activity` returns `{ "ok": true, "player": null, "since": null, "activity": [...], "next_since": 42 }`. If `since` is omitted, it returns the newest 25 matching entries as an initial snapshot. If `since` is an entry id, only entries with a greater id are returned. If it is an ISO-8601 timestamp, only entries with a later UTC timestamp are returned. `next_since` is the newest returned/known id and can be sent on the next poll. This makes incremental polling explicit.

## Game rules

XP is 10 per meal, plus 15 for `new=1`, plus 5 for rating 7–10, plus 20 for `boss`. The level curve starts at level 1 and advances while `total_xp >= level * level * 10`; the current level's progress is measured from `(level - 1)^2 * 10` to `level^2 * 10`.

Noodle Masterpiece is exactly spice 8. At tolerance 8 or higher it is easy and has a 100% buff chance. Tolerance 8 down to 4 scales the chance from 100% to 50%. Tolerance below 4 has a 30% chance and records random 0–5 heat damage every 4 seconds for 1 minute; tolerance below 1 records a debuff. A successful canonical roll applies all four buffs: highly energized (2x base energy), heated engine (+70% speed/+20% acceleration), energy consumption 2x, and duration 15 minutes. The API is authoritative for the random roll and stored result.

## Tests and limitations

Static checks can be run without a build step:

```sh
php -l foodgame/api.php
node --check foodgame/js/api.js
node --check foodgame/js/ui.js
node --check foodgame/js/game.js
```

For a runtime smoke test, configure a private key and storage directory, run `php -S 127.0.0.1:8080 -t .`, then use the curl examples. Verify an incorrect key is HTTP 401 with the exact JSON above, then verify `stats`, `log`, `feed`, and `activity?since=<returned id>`; do not use a real production key in a repository or shared shell history. This environment can validate source structure and commit the files, but cannot execute the cPanel PHP runtime or browser UI, so those runtime checks remain deployment-side limitations.
