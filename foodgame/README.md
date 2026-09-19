# Foodgame

A bright, single-page vanilla HTML/CSS/JavaScript game backed by a same-origin PHP JSON API. No framework, cloud service, build step, or database is required.

## URLs and files

- Game: `https://YOUR-DOMAIN.example/foodgame/`
- API: `https://YOUR-DOMAIN.example/foodgame/api.php`
- `index.html` is the UI and `api.php` is the JSON API with local file storage.
- The public code contains only the rejected placeholder `REPLACE_WITH_A_LONG_RANDOM_TOKEN`. Never commit a real key.

## HTTPS deployment (REQUIRED)

**HTTPS is REQUIRED. Deploy the game only to an HTTPS-served URL. Never serve the game or API over plain HTTP.**

1. Select PHP 8.0+ (PHP 8.1+ recommended) in cPanel MultiPHP Manager.
2. Upload `foodgame/index.html`, `foodgame/api.php`, `foodgame/.htaccess`, this README, the JavaScript files, and the stylesheet to `public_html/foodgame/`.
3. Create the private `/home/ACCOUNT/foodgame-config.php` and `/home/ACCOUNT/foodgame-data/` paths described below; do not upload either to the public repository.
4. Confirm that cPanel AutoSSL/Let's Encrypt covers `wp.mesh.net.nz`. Deploy at an HTTPS-served URL, such as `https://wp.mesh.net.nz/foodgame/`.
5. If HTTPS is not already enforced by the host, keep the included `.htaccess` HTTPS redirect enabled (or add an equivalent `.htaccess` redirect) so every request is forced to HTTPS.
6. Never serve the game over plain HTTP, including test or shared production links.

The API client is deliberately pinned to the HTTPS API URL `https://wp.mesh.net.nz/foodgame/api.php`; it will not construct or fetch a plain-HTTP URL.

## Token setup

Every API request requires the `key` query parameter. A missing or incorrect key returns HTTP 401 and `{"ok":false,"error":"unauthorized"}`. The API uses `hash_equals` for constant-time comparison.

Prefer a config file above the web root. The API checks `../foodgame-config.php` and one more parent directory for a cPanel account-home config. For a typical layout with public files in `/home/ACCOUNT/public_html/foodgame/`, create `/home/ACCOUNT/foodgame-config.php`:

```php
<?php
const FOODGAME_API_KEY = 'PASTE_THE_GENERATED_TOKEN_HERE';
const FOODGAME_STORAGE_DIR = '/home/ACCOUNT/foodgame-data';
```

Do not use those literal placeholder values as a live key. Generate a strong token with:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Put the generated value only in the private config, set the config permissions to `600`, create the storage directory outside `public_html`, and set its permissions to `700`. The API creates JSON data and lock files with restrictive permissions. If no private config is present, the public fallback is intentionally unusable and all calls return unauthorized.

The browser stores the key only in its own `localStorage`; every fetch includes it as `key`. Never put a real token in HTML, README, GitHub Actions, or a URL that you share.

## API GET contract

All examples use the internal API player id `cooper`; the UI displays the editable shared name constant `CJLBee` for that player.

```sh
KEY='your-private-token'
BASE='https://YOUR-DOMAIN.example/foodgame/api.php'
curl -sS -i "$BASE?key=$KEY&action=stats&player=cooper"
curl -sS -i "$BASE?key=$KEY&action=log&player=cooper&meal=noodle-masterpiece&rating=8&new=1"
```

Supported actions are `stats` (default), `entries`, `feed`, `activity`, and `log`. Parameters are `player` (the internal id `cooper`), `meal`, `rating` from 0 through 10, and `new` as `0` or `1`.

## Local test

Use an HTTPS-serving local reverse proxy or another HTTPS test server; do not expose the game through plain HTTP. With a private config in place, verify the API with the HTTPS URL for that server, for example:

```sh
curl -sS -i 'https://127.0.0.1:8080/foodgame/api.php' --data-urlencode "key=$KEY" --data-urlencode 'action=stats' --data-urlencode 'player=cooper'
```

The rate limiter is file based: authorized requests receive a per-IP rolling limit of 60 requests per minute. Game data is written under an exclusive lock and saved by temporary-file-plus-rename atomic replacement. This is intentionally small-device storage, not a multi-server database.
