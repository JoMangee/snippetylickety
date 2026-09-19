# Cooper Foodgame

A bright, single-page vanilla HTML/CSS/JavaScript game for Cooper (age 9), backed by same-origin PHP. No framework, cloud service, build step, or database is required.

## URLs and files

- Game: `https://YOUR-DOMAIN.example/foodgame/`
- API: `https://YOUR-DOMAIN.example/foodgame/api.php`
- `index.html` is the UI and `api.php` is the JSON API with local file storage.
- The public code contains only the rejected placeholder `REPLACE_WITH_A_LONG_RANDOM_TOKEN`. Never commit a real key.

## Token setup

Every API request requires the `key` query parameter. A missing or wrong key returns HTTP 401 and exactly `{"ok":false,"error":"unauthorized"}`. The API uses `hash_equals` for constant-time comparison.

Prefer a config file above the web root. The API checks the required sibling path `../foodgame-config.php` and also checks one more parent directory for a cPanel account-home config. For a typical cPanel layout with public files in `/home/ACCOUNT/public_html/foodgame/`, create the private file `/home/ACCOUNT/foodgame-config.php`:

```php
<?php
const FOODGAME_API_KEY = 'PASTE_THE_GENERATED_TOKEN_HERE';
const FOODGAME_STORAGE_DIR = '/home/ACCOUNT/foodgame-data';
```

Do not use those literal placeholder values as a live key. Generate a strong random token with:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Put the generated value only in the private config, set config permissions to `600`, create the storage directory outside `public_html`, and set its permissions to `700`. The API will create the JSON data and lock files with restrictive permissions. If no private config is present, the public fallback is intentionally unusable and all calls return unauthorized.

A config may also return an array containing `api_key`, but constants are clearer. `FOODGAME_STORAGE_DIR` is optional; the fallback is a `foodgame-data` directory beside the public `foodgame` directory. For production, set it outside the web root as shown. Same-origin deployment is the default: no permissive CORS header is emitted. If you need another origin, put it behind an explicit, trusted server-side allowlist rather than opening `*`.

## cPanel deployment

1. Select PHP 8.0+ (PHP 8.1+ is recommended) in cPanel MultiPHP Manager.
2. Upload `foodgame/index.html`, `foodgame/api.php`, and this README to `public_html/foodgame/`.
3. Create the private `/home/ACCOUNT/foodgame-config.php` and `/home/ACCOUNT/foodgame-data/` as above; do not upload either to the public repository.
4. Confirm the PHP user can write the storage directory. Do not make it world-writable.
5. Open `/foodgame/`, enter the same token in Settings, and test a meal.

The browser stores the key only in localStorage when the user chooses Save Key; every fetch includes it as `key`. tinyNature in the page refers to the same local key holder. Do not put a real token in HTML, README, GitHub Actions, or a URL that you share.

## API GET contract

All examples use `KEY` as a shell variable; it is never a repository secret.

```sh
KEY='your-private-token'
BASE='https://YOUR-DOMAIN.example/foodgame/api.php'
curl -iG "$BASE" --data-urlencode "key=$KEY" --data-urlencode 'action=stats' --data-urlencode 'player=Cooper'
curl -iG "$BASE" --data-urlencode "key=$KEY" --data-urlencode 'action=log' --data-urlencode 'player=Cooper' --data-urlencode 'meal=noodle-masterpiece' --data-urlencode 'rating=8' --data-urlencode 'new=1'
```

Supported actions are `stats` (default), `entries`, and `log`. Parameters are `player` (default `Cooper`), `meal`, `rating` from 0 through 10, and `new` as `0` or `1`. Successful responses are JSON with `ok`, `player`, `meal`, `rating`, `new`, `stats`, and `entries`; a log also includes `xp_gained`, `entry`, and `buffs_applied`.

XP is 10 for every meal, plus 15 for a new meal, plus 5 for rating 7 or more, plus 20 for `boss`. The simple level boundary is `level * level * 10` total XP. Noodle Masterpiece is spice 8. At tolerance greater than or equal to spice it is easy; tolerance 8 down to 4 scales buff chance 100% to 50%; tolerance under 4 down to 1 uses 30% and records random 0–5 heat damage every 4 seconds for 60 seconds; below 1 records a debuff. Its exact successful buffs are highly energized = 2x base energy, heated engine = +70% speed and +20% acceleration, energy consumption 2x, duration 15 minutes.

## Local test

From the repository root, put a private config one directory above the public `foodgame` directory, or temporarily set the config path as described above. Then run:

```sh
php -l foodgame/api.php
php -S 127.0.0.1:8080 -t .
```

In a second terminal, with a real local `KEY` configured:

```sh
curl -sS -iG 'http://127.0.0.1:8080/foodgame/api.php' --data-urlencode "key=$KEY" --data-urlencode 'action=stats' --data-urlencode 'player=Cooper'
```

Quick unauthorized check (should be HTTP 401 with the exact JSON above):

```sh
curl -sS -iG 'http://127.0.0.1:8080/foodgame/api.php' --data-urlencode 'action=stats' --data-urlencode 'player=Cooper'
```

The rate limiter is file based: authorized requests get a per-IP rolling limit of 60 requests per minute, stored as hashed-IP JSON files under `FOODGAME_STORAGE_DIR` and updated under `rate.lock`. Unauthorized requests are not counted, so key testing is not accidentally blocked. Game data is written under an exclusive lock and saved by temporary-file-plus-rename atomic replacement. This is intentionally small-device storage, not a multi-server database.
