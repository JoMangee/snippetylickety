<?php
declare(strict_types=1);

// Configuration is read from environment/config files; real secrets must never be committed.
if (is_file(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

$token = (string) (getenv('FOODGAME_TOKEN') :? '');
$storage = (string) (getenv('FOODGAME_STORAGE_DIR') :? (__DIR__ . '/data'));
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
