<?php
declare(strict_types=1);

// Configuration is read from environment/.env files; real secrets must never be committed.
if (is_file(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

$token = (string) (getenv('FOODGAME_TOKEN') ?: '');
$storage = (string) (getenv('FOODGAME_STORAGE_DIR') ?: (__DIR__ . '/data'));
$mealsFile = __DIR__ . '/meals.json';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo $json === false ? '{"ok":false,"error":"server_error"}' : $json;
    exit;
}

function fail(string $error, int $status = 400): never
{
    respond(['ok' => false, 'error' => $error], $status);
}

function read_json_file(string $file, array $fallback): array
{
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return $fallback;
    $value = json_decode($raw, true);
    return is_array($value) ? $value : $fallback;
}

/** Return the valid public meal catalog without changing the source document. */
function meal_catalog(array $document): array
{
    $meals = is_array($document['meals'] ?? null) ? $document['meals'] : [];
    $catalog = [];
    foreach ($meals as $key => $meal) {
        if (!is_array($meal)) continue;
        $id = isset($meal['id']) && is_string($meal['id']) ? $meal['id'] : (string) $key;
        $name = isset($meal['name']) && is_string($meal['name']) ? trim($meal['name']) : '';
        $spice = $meal['spice'] ?? null;
        if (!preg_match('/^[a-z0-9-]{1,64}$/', $id) || $name === '') continue;
        if (is_int($spice)) {
            $spiceValue = $spice;
        } elseif (is_string($spice) && preg_match('/^(?:0|[1-9][0-9]*)$/', $spice)) {
            $spiceValue = (int) $spice;
        } else {
            continue;
        }
        if ($spiceValue < 0 || $spiceValue > 10) continue;
        $catalog[$id] = [
            'id' => $id,
            'name' => $name,
            'spice' => $spiceValue,
            'buffs' => is_array($meal['buffs'] ?? null) ? array_values($meal['buffs']) : [],
            'xp_base' => max(0, (int) ($meal['xp_base'] ?? 10)),
        ];
    }
    return $catalog;
}

/** Persist the complete meals document while holding a lock and replacing atomically. */
function persist_meals(string $file, array $document): bool
{
    $lock = @fopen($file . '.lock', 'c+');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) @fclose($lock);
        return false;
    }
    $temporary = @tempnam(dirname($file), '.meals-');
    $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ok = $temporary !== false && $encoded !== false && @file_put_contents($temporary, $encoded . PHP_EOL, LOCK_EX) !== false;
    if ($ok) {
        @chmod($temporary, 0600);
        $ok = @rename($temporary, $file);
    }
    if (!$ok && $temporary !== false) @unlink($temporary);
    @flock($lock, LOCK_UN);
    @fclose($lock);
    return $ok;
}

function request_string(string $name): ?string
{
    $value = $_POST[$name] ?? $_GET[$name] ?? null;
    return is_string($value) ? $value : null;
}

$action = request_string('action') ?? 'stats';
$key = request_string('key') ?? '';
if ($token === '' || $key === '' || !hash_equals($token, $key)) {
    fail('unauthorized', 401);
}

$document = read_json_file($mealsFile, []);
$catalog = meal_catalog($document);

if ($action === 'meals') {
    respond(['ok' => true, 'meals' => array_values($catalog)]);
}

if ($action === 'meals_add') {
    $id = request_string('id');
    $name = trim(request_string('name') ?? '');
    $spiceRaw = request_string('spice');
    $xpBaseRaw = request_string('xp_base');
    if ($id === null || !preg_match('/^[a-z0-9-]{1,64}$/', $id)) fail('invalid_meal_id');
    if ($name === '' || strlen($name) > 120) fail('invalid_meal_name');
    if ($spiceRaw === null || !preg_match('/^(?:0|[1-9][0-9]*)$/', $spiceRaw)) fail('invalid_spice');
    if ($xpBaseRaw !== null && !preg_match('/^(?:0|[1-9][0-9]*)$/', $xpBaseRaw)) fail('invalid_xp_base');
    $spice = (int) $spiceRaw;
    $xpBase = $xpBaseRaw === null ? 10 : (int) $xpBaseRaw;
    if ($spice < 0 || $spice > 10) fail('invalid_spice');
    if (array_key_exists($id, $catalog)) fail('meal_exists', 409);

    $meals = is_array($document['meals'] ?? null) ? $document['meals'] : [];
    $meals[$id] = [
        'id' => $id,
        'name' => $name,
        'spice' => $spice,
        'buffs' => [],
        'xp_base' => $xpBase,
    ];
    $document['version'] = isset($document['version']) ? (int) $document['version'] : 1;
    $document['meals'] = $meals;
    if (!persist_meals($mealsFile, $document)) fail('meal_persist_failed', 500);
    $updated = meal_catalog($document);
    respond(['ok' => true, 'meals' => array_values($updated)]);
}

if ($action === 'meals_delete') {
    $id = request_string('id');
    if ($id === null || !preg_match('/^[a-z0-9-]{1,64}$/', $id)) fail('invalid_meal_id');
    $meals = is_array($document['meals'] ?? null) ? $document['meals'] : [];
    if (!array_key_exists($id, $meals)) {
        $updated = meal_catalog($document);
        respond(['ok' => true, 'meals' => array_values($updated)]);
    }
    unset($meals[$id]);
    $document['version'] = isset($document['version']) ? (int) $document['version'] : 1;
    $document['meals'] = $meals;
    if (!persist_meals($mealsFile, $document)) fail('meal_persist_failed', 500);
    $updated = meal_catalog($document);
    respond(['ok' => true, 'meals' => array_values($updated)]);
}

// The remaining actions retain the lightweight game API and always validate meal IDs against meals.json.
if (!in_array($action, ['stats', 'entries', 'feed', 'activity', 'log'], true)) fail('invalid_action');
if ($action === 'log') {
    $meal = request_string('meal') ?? '';
    if (!array_key_exists($meal, $catalog)) fail('invalid_meal');
    $ratingRaw = request_string('rating') ?? '';
    if (!preg_match('/^(?:10|[1-9])$/', $ratingRaw)) fail('invalid_rating');
    $player = request_string('player') ?: 'CJLBer';
    if (!preg_match('/^[A-Za-z0-9 _-]{1,32}$/', $player)) fail('invalid_player');
    if (!is_dir($storage) && !@mkdir($storage, 0700, true) && !is_dir($storage)) {
        fail('storage_unavailable', 500);
    }
    $dataFile = $storage . DIRECTORY_SEPARATOR . 'foodgame-data.json';
    $lock = @fopen($dataFile . '.lock', 'c+');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        fail('storage_unavailable', 500);
    }
    $data = read_json_file($dataFile, ['version' => 1, 'players' => [], 'entries' => []]);
    $data['entries'] = is_array($data['entries'] ?? null) ? $data['entries'] : [];
    $lastEntry = $data['entries'][count($data['entries']) - 1] ?? null;
    if (
        is_array($lastEntry)
        && ($lastEntry['player'] ?? null) === $player
        && ($lastEntry['meal'] ?? null) === $meal
    ) {
        $lastTimestamp = $lastEntry['timestamp_utc'] ?? null;
        $lastEpoch = false;
        if (is_string($lastTimestamp) && $lastTimestamp !== '') {
            try {
                $lastEpoch = (new DateTimeImmutable(
                    $lastTimestamp,
                    new DateTimeZone('UTC')
                ))->getTimestamp();
            } catch (Exception $exception) {
                $lastEpoch = false;
            }
        }
        if ($lastEpoch !== false && abs(time() - $lastEpoch) <= 30) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
            fail('cooldown', 429);
        }
    }
    $data['entries'][] = [
        'id' => count($data['entries']) + 1,
        'player' => $player,
        'meal' => $meal,
        'rating' => (int) $ratingRaw,
        'timestamp_utc' => gmdate('c'),
    ];
    $tmp = @tempnam($storage, '.foodgame-');
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $ok = $tmp !== false
        && $encoded !== false
        && @file_put_contents($tmp, $encoded . PHP_EOL, LOCK_EX) !== false
        && @rename($tmp, $dataFile);
    if (!$ok && $tmp !== false) @unlink($tmp);
    @flock($lock, LOCK_UN);
    @fclose($lock);
    if (!$ok) fail('storage_write_failed', 500);
    respond([
        'ok' => true,
        'meal' => $meal,
        'rating' => (int) $ratingRaw,
    ]);
}
$data = read_json_file(
    $storage . DIRECTORY_SEPARATOR . 'foodgame-data.json',
    ['version' => 1, 'players' => [], 'entries' => []]
);
$entries = is_array($data['entries'] ?? null) ? array_values($data['entries']) : [];
if ($action === 'entries' || $action === 'feed' || $action === 'activity') {
    respond([
        'ok' => true,
        'entries' => array_slice($entries, -25),
    ]);
}
if ($action === 'stats') {
    $player = request_string('player') ?: 'CJLBee';
    $playerEntries = [];
    $seenMeals = [];
    $totalXp = 0;
    foreach ($entries as $entry) {
        if (!is_array($entry) || ($entry['player'] ?? null) !== $player) {
            continue;
        }
        $playerEntries[] = $entry;
        $mealId = is_string($entry['meal'] ?? null) ? $entry['meal'] : '';
        $xpBase = 10;
        if (isset($catalog[$mealId]) && is_array($catalog[$mealId])) {
            $xpBase = (int) ($catalog[$mealId]['xp_base'] ?? 10);
        }
        $entryXp = $xpBase;
        if (!array_key_exists($mealId, $seenMeals)) {
            $entryXp += 15;
            $seenMeals[$mealId] = true;
        }
        if ((int) ($entry['rating'] ?? 0) >= 7) {
            $entryXp += 5;
        }
        if ($mealId === 'boss') {
            $entryXp += 20;
        }
        $totalXp += $entryXp;
    }
    $level = 1;
    while ($totalXp >= ($level * $level * 10)) {
        $level++;
    }
    $floor = (($level - 1) * ($level - 1)) * 10;
    $ceiling = ($level * $level) * 10;
    $timezone = new DateTimeZone('Pacific/Auckland');
    $daysWithEntries = [];
    foreach ($playerEntries as $entry) {
        $timestamp = $entry['timestamp_utc'] ?? null;
        if (!is_string($timestamp) || $timestamp === '') {
            continue;
        }
        try {
            $localDay = (new DateTimeImmutable(
                $timestamp,
                new DateTimeZone('UTC')
            ))->setTimezone($timezone)->format('Y-m-d');
            $daysWithEntries[$localDay] = true;
        } catch (Exception $exception) {
            continue;
        }
    }
    $streak = 0;
    $day = new DateTimeImmutable('now', $timezone);
    if (isset($daysWithEntries[$day->format('Y-m-d')])) {
        while (isset($daysWithEntries[$day->format('Y-m-d')])) {
            $streak++;
            $day = $day->modify('-1 day');
        }
    }
    respond([
        'ok' => true,
        'player' => $player,
        'stats' => [
            'level' => $level,
            'total_xp' => $totalXp,
            'xp_progress' => $totalXp - $floor,
            'xp_needed' => $ceiling - $floor,
            'xp_to_next' => $ceiling - $totalXp,
            'streak' => $streak,
            'spice_tolerance' => 8,
            'meals_logged' => count($playerEntries),
        ],
    ]);
}
fail('invalid_action');
