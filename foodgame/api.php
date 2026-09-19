<?php
declare(strict_types=1);

// .env loader - server config, never commit real values
if (file_exists(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}
$TOKEN = getenv('FOODGAME_TOKEN') ?: '';
$DATA_DIR = getenv('FOODGAME_DATA_DIR') ?: __DIR__ . '/data';
$RATE_LIMIT = (int)(getenv('FOODGAME_RATE_LIMIT') ?: 60);
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(array $body, int $status = 200, array $headers = []): never {
    http_response_code($status);
    foreach ($headers as $name => $value) header($name . ': ' . $value);
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo $json === false ? '{"ok":false,"error":"server_error"}' : $json;
    exit;
}
function fail(string $error, int $status = 400, array $headers = []): never {
    respond(['ok' => false, 'error' => $error], $status, $headers);
}
function atomic_write(string $file, string $contents): bool {
    $tmp = @tempnam(dirname($file), '.foodgame-');
    if ($tmp === false) return false;
    if (@file_put_contents($tmp, $contents, LOCK_EX) === false) { @unlink($tmp); return false; }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}
function blank_player(): array {
    return ['level' => 1, 'total_xp' => 0, 'streak' => 0, 'spice_tolerance' => 8, 'meals_logged' => 0];
}
function player_stats(array $player): array {
    $level = max(1, (int)($player['level'] ?? 1));
    $xp = max(0, (int)($player['total_xp'] ?? 0));
    while ($xp >= $level * $level * 10) $level++;
    $floor = ($level - 1) * ($level - 1) * 10;
    $ceiling = $level * $level * 10;
    return [
        'level' => $level,
        'total_xp' => $xp,
        'xp_progress' => max(0, $xp - $floor),
        'xp_needed' => max(1, $ceiling - $floor),
        'xp_to_next' => max(0, $ceiling - $xp),
        'streak' => max(0, (int)($player['streak'] ?? 0)),
        'spice_tolerance' => max(0, min(8, (int)($player['spice_tolerance'] ?? 8))),
        'meals_logged' => max(0, (int)($player['meals_logged'] ?? 0)),
    ];
}
function sort_newest(array &$entries): void {
    usort($entries, static function (array $a, array $b): int {
        $time = strcmp((string)($b['timestamp_utc'] ?? ''), (string)($a['timestamp_utc'] ?? ''));
        return $time !== 0 ? $time : ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
    });
}
function public_entry(array $entry): array {
    $public = [
        'id' => (int)($entry['id'] ?? 0),
        'player' => (string)($entry['player'] ?? ''),
        'meal' => (string)($entry['meal'] ?? ''),
        'rating' => (int)($entry['rating'] ?? 0),
        'new' => (bool)($entry['new'] ?? false),
        'xp_gained' => (int)($entry['xp_gained'] ?? 0),
        'level_after' => (int)($entry['level_after'] ?? 1),
        'timestamp_utc' => (string)($entry['timestamp_utc'] ?? ''),
    ];
    if (isset($entry['summary']) && is_array($entry['summary'])) $public['summary'] = $entry['summary'];
    return $public;
}
function normalize_store($raw): array {
    $store = is_array($raw) ? $raw : [];
    $store['version'] = 2;
    $store['players'] = is_array($store['players'] ?? null) ? $store['players'] : [];
    $store['entries'] = is_array($store['entries'] ?? null) ? $store['entries'] : [];
    if (count($store['entries']) === 0) {
        foreach ($store['players'] as $player => $data) {
            foreach (is_array($data['entries'] ?? null) ? $data['entries'] : [] as $old) {
                if (!is_array($old)) continue;
                $old['player'] = (string)$player;
                $old['id'] = (int)($old['id'] ?? 0);
                $old['timestamp_utc'] = (string)($old['timestamp_utc'] ?? gmdate('c'));
                $store['entries'][] = public_entry($old);
            }
        }
    }
    $maxId = 0;
    foreach ($store['entries'] as $entry) $maxId = max($maxId, (int)($entry['id'] ?? 0));
    $store['next_id'] = max($maxId + 1, (int)($store['next_id'] ?? 1));
    return $store;
}
function meal_catalog(): array {
    $file = __DIR__ . '/meals.json';
    $raw = @file_get_contents($file);
    $config = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($config) || !is_array($config['meals'] ?? null)) fail('meal_config_unavailable', 500);
    $catalog = [];
    foreach ($config['meals'] as $key => $meal) {
        if (!is_array($meal)) continue;
        $id = (string)($meal['id'] ?? $key);
        $name = trim((string)($meal['name'] ?? ''));
        if ($id === '' || $name === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) continue;
        $buffs = [];
        foreach (is_array($meal['buffs'] ?? null) ? $meal['buffs'] : [] as $buff) {
            if (is_string($buff) && $buff !== '') $buffs[] = $buff;
        }
        $catalog[$id] = [
            'id' => $id,
            'name' => $name,
            'spice' => max(0, min(8, (int)($meal['spice'] ?? 0))),
            'buffs' => $buffs,
            'xp_base' => max(0, (int)($meal['xp_base'] ?? 10)),
        ];
    }
    if (!$catalog) fail('meal_config_unavailable', 500);
    return $catalog;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') fail('method_not_allowed', 405, ['Allow' => 'GET']);
$placeholder = 'REPLACE_WITH_A_LONG_RANDOM_TOKEN';
$configPaths = [__DIR__ . DIRECTORY_SEPARATOR . 'foodgame-config.php', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'foodgame-config.php'];
foreach ($configPaths as $configPath) {
    if (!is_file($configPath)) continue;
    $config = require $configPath;
    if (is_array($config) && isset($config['api_key']) && !defined('FOODGAME_API_KEY')) define('FOODGAME_API_KEY', (string)$config['api_key']);
    if (is_array($config) && isset($config['storage_dir']) && !defined('FOODGAME_STORAGE_DIR')) define('FOODGAME_STORAGE_DIR', (string)$config['storage_dir']);
    if (defined('FOODGAME_API_KEY')) break;
}
$secret = $TOKEN !== '' ? $TOKEN : (defined('FOODGAME_API_KEY') ? (string)constant('FOODGAME_API_KEY') : $placeholder);
$key = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
if ($secret === '' || $secret === $placeholder || $key === '' || !hash_equals($secret, $key)) respond(['ok' => false, 'error' => 'unauthorized'], 401);

$catalog = meal_catalog();
$action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : 'stats';
if ($action === 'meals' || $action === 'meals_add') {
    $addIds = is_array(json_decode((string)@file_get_contents(__DIR__ . '/meals.json'), true)['meals_add'] ?? null) ? json_decode((string)@file_get_contents(__DIR__ . '/meals.json'), true)['meals_add'] : [];
    $added = [];
    foreach ($addIds as $id) if (is_string($id) && isset($catalog[$id])) $added[] = $catalog[$id];
    respond(['ok' => true, 'meals' => array_values($catalog), 'meals_add' => $added]);
}
if (!in_array($action, ['stats', 'entries', 'feed', 'activity', 'log'], true)) fail('invalid_action', 400);

$storage = defined('FOODGAME_STORAGE_DIR') ? (string)constant('FOODGAME_STORAGE_DIR') : $DATA_DIR;
if (!is_dir($storage) && !@mkdir($storage, 0700, true)) fail('storage_unavailable', 500);
if (!is_writable($storage)) fail('storage_unavailable', 500);
@chmod($storage, 0700);
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateLock = @fopen($storage . DIRECTORY_SEPARATOR . 'rate.lock', 'c+');
if ($rateLock === false || !@flock($rateLock, LOCK_EX)) { if (is_resource($rateLock)) @fclose($rateLock); fail('rate_limit_unavailable', 503); }
$rateFile = $storage . DIRECTORY_SEPARATOR . 'rate-' . hash('sha256', $ip) . '.json';
$rateRaw = @file_get_contents($rateFile);
$hits = json_decode(is_string($rateRaw) ? $rateRaw : '[]', true);
$hits = is_array($hits) ? array_values(array_filter($hits, static fn($hit): bool => is_numeric($hit) && (int)$hit > time() - 60)) : [];
if (count($hits) >= $RATE_LIMIT) { @flock($rateLock, LOCK_UN); @fclose($rateLock); fail('rate_limited', 429, ['Retry-After' => '60']); }
$hits[] = time();
if (@file_put_contents($rateFile, json_encode($hits, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) { @flock($rateLock, LOCK_UN); @fclose($rateLock); fail('rate_limit_unavailable', 503); }
@chmod($rateFile, 0600);
@flock($rateLock, LOCK_UN); @fclose($rateLock);

$dataFile = $storage . DIRECTORY_SEPARATOR . 'foodgame-data.json';
$dataLock = @fopen($storage . DIRECTORY_SEPARATOR . 'foodgame-data.lock', 'c+');
if ($dataLock === false || !@flock($dataLock, LOCK_EX)) { if (is_resource($dataLock)) @fclose($dataLock); fail('storage_unavailable', 500); }
$raw = @file_get_contents($dataFile);
$store = normalize_store(json_decode(is_string($raw) ? $raw : '', true));
$player = isset($_GET['player']) && is_string($_GET['player']) && $_GET['player'] !== '' ? $_GET['player'] : 'CJLBe';
$playerProvided = isset($_GET['player']) && is_string($_GET['player']) && $_GET['player'] !== '';
if (!preg_match('/^[A-Za-z0-9 _-]{1,32}$/', $player)) { @flock($dataLock, LOCK_UN); @fclose($dataLock); fail('invalid_player', 400); }
if ($action === 'feed') {
    $feed = $playerProvided ? array_values(array_filter($store['entries'], static fn(array $entry): bool => (string)($entry['player'] ?? '') === $player)) : $store['entries'];
    sort_newest($feed); $feed = array_map('public_entry', array_slice($feed, 0, 25));
    @flock($dataLock, LOCK_UN); @fclose($dataLock); respond(['ok' => true, 'feed' => $feed]);
}
if ($action === 'activity') {
    $activity = $playerProvided ? array_values(array_filter($store['entries'], static fn(array $entry): bool => (string)($entry['player'] ?? '') === $player)) : $store['entries'];
    $since = isset($_GET['since']) && is_string($_GET['since']) ? trim($_GET['since']) : '';
    if ($since !== '') {
        if (ctype_digit($since)) $activity = array_values(array_filter($activity, static fn(array $entry): bool => (int)($entry['id'] ?? 0) > (int)$since));
        elseif (($sinceTime = strtotime($since)) !== false) $activity = array_values(array_filter($activity, static fn(array $entry): bool => (int)strtotime((string)($entry['timestamp_utc'] ?? '')) > $sinceTime));
        else { @flock($dataLock, LOCK_UN); @fclose($dataLock); fail('invalid_since', 400); }
    }
    sort_newest($activity); $nextSince = 0; foreach ($store['entries'] as $entry) $nextSince = max($nextSince, (int)($entry['id'] ?? 0));
    $activity = array_map('public_entry', array_slice($activity, 0, 25));
    @flock($dataLock, LOCK_UN); @fclose($dataLock); respond(['ok' => true, 'player' => $playerProvided ? $player : null, 'since' => $since === '' ? null : $since, 'activity' => $activity, 'next_since' => $nextSince]);
}
$current = is_array($store['players'][$player] ?? null) ? $store['players'][$player] : blank_player();
if ($action === 'stats' || $action === 'entries') {
    $stats = player_stats($current);
    $entries = array_values(array_filter($store['entries'], static fn(array $entry): bool => (string)($entry['player'] ?? '') === $player));
    sort_newest($entries); $entries = array_map('public_entry', array_slice($entries, 0, 25));
    @flock($dataLock, LOCK_UN); @fclose($dataLock);
    if ($action === 'entries') respond(['ok' => true, 'player' => $player, 'entries' => $entries]);
    respond(['ok' => true, 'player' => $player, 'stats' => $stats, 'entries' => $entries]);
}

$meal = isset($_GET['meal']) && is_string($_GET['meal']) ? $_GET['meal'] : '';
if (!isset($catalog[$meal])) { @flock($dataLock, LOCK_UN); @fclose($dataLock); fail('invalid_meal', 400); }
$ratingRaw = isset($_GET['rating']) && is_string($_GET['rating']) ? $_GET['rating'] : '';
if (!preg_match('/^(10|[0-9])$/', $ratingRaw)) { @flock($dataLock, LOCK_UN); @fclose($dataLock); fail('invalid_rating', 400); }
$rating = (int)$ratingRaw;
$newRaw = isset($_GET['new']) && is_string($_GET['new']) ? strtolower($_GET['new']) : '0';
$isNew = in_array($newRaw, ['1', 'true', 'yes', 'on'], true);
$currentStats = player_stats($current);
$spice = (int)$catalog[$meal]['spice'];
$tolerance = max(0, min(8, (int)($current['spice_tolerance'] ?? 8)));
$chance = 0; $resultName = 'debuffed'; $heat = 0; $duration = 0; $debuffed = false;
if ($tolerance >= $spice) { $resultName = 'easy'; $chance = 100; }
elseif ($tolerance >= 4) { $resultName = 'hot'; $chance = (int)round(50 + ($tolerance - 4) * 12.5); }
elseif ($tolerance > 1) { $resultName = 'low-tolerance'; $chance = 30; $heat = random_int(0, 5); $duration = 60; }
else { $debuffed = true; }
$success = $chance > 0 && random_int(1, 100) <= $chance;
$buffs = $success ? $catalog[$meal]['buffs'] : [];
$xp = (int)$catalog[$meal]['xp_base'] + ($isNew ? 15 : 0) + ($rating >= 7 ? 5 : 0) + ($meal === 'boss' ? 20 : 0);
$totalXp = max(0, (int)($current['total_xp'] ?? 0)) + $xp;
$level = max(1, (int)($current['level'] ?? 1));
while ($totalXp >= $level * $level * 10) $level++;
$entry = [
    'id' => (int)$store['next_id'], 'player' => $player, 'meal' => $meal, 'rating' => $rating, 'new' => $isNew,
    'xp_gained' => $xp, 'level_after' => $level, 'timestamp_utc' => gmdate('c'),
    'summary' => ['meal_name' => $catalog[$meal]['name'], 'spice' => $spice, 'spice_result' => $resultName, 'buff_chance_percent' => $chance, 'buffs_applied' => $buffs, 'heat_damage_per_4_seconds' => $heat, 'heat_duration_seconds' => $duration, 'debuffed' => $debuffed],
];
$store['next_id']++;
$current['level'] = $level; $current['total_xp'] = $totalXp; $current['streak'] = max(0, (int)($current['streak'] ?? 0)) + 1;
$current['spice_tolerance'] = $tolerance; $current['meals_logged'] = max(0, (int)($current['meals_logged'] ?? 0)) + 1;
$store['players'][$player] = $current; $store['entries'][] = public_entry($entry);
$encoded = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (!atomic_write($dataFile, $encoded . PHP_EOL)) { @flock($dataLock, LOCK_UN); @fclose($dataLock); fail('storage_write_failed', 500); }
$latest = array_values(array_filter($store['entries'], static fn(array $item): bool => (string)($item['player'] ?? '') === $player));
sort_newest($latest); $latest = array_map('public_entry', array_slice($latest, 0, 25));
$stats = player_stats($current);
@flock($dataLock, LOCK_UN); @fclose($dataLock);
respond(['ok' => true, 'player' => $player, 'meal' => $meal, 'rating' => $rating, 'new' => $isNew, 'xp_gained' => $xp, 'entry' => public_entry($entry), 'stats' => $stats, 'entries' => $latest]);
