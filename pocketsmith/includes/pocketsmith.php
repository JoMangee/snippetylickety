<?php
declare(strict_types=1);

/**
 * PocketSmith MCP Bridge Helpers
 */

function pocketsmith_load_env(string $path): array {
    if (!file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = strtolower(trim($name));
        $value = trim($value, " \t\n\r\0\x0B\"");

        // Store original normalized key and prefix-stripped key.
        $config[$name] = $value;
        $config[preg_replace('/^pocketsmith_/i', '', $name)] = $value;
    }

    return $config;
}

function pocketsmith_load_env_from_all_paths(): array {
    $paths = [
        __DIR__ . '/../.env',
        dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? __DIR__)) . '/.env',
        __DIR__ . '/.env',
    ];

    foreach ($paths as $path) {
        if (file_exists($path)) {
            return pocketsmith_load_env($path);
        }
    }

    return [];
}

function pocketsmith_get_config(): array {
    $env = pocketsmith_load_env_from_all_paths();
    return [
        'developer_key' => $env['developer_key'] ?? '',
        'redirect_uri' => $env['redirect_uri'] ?? '',
        'bot_secret' => $env['bot_secret'] ?? '',
        'token_window' => (int)($env['token_window'] ?? 900),
        'session_dir' => $env['session_dir'] ?? __DIR__,
    ];
}

function pocketsmith_generate_pkce(): array {
    $verifier = bin2hex(random_bytes(32));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return ['verifier' => $verifier, 'challenge' => $challenge];
}

// Supports old test usage with optional explicit arguments.
function pocketsmith_auth_url(?string $developerKey = null, ?string $redirectUri = null, ?string $challenge = null, ?string $state = null): string {
    $config = pocketsmith_get_config();
    $pkce = pocketsmith_generate_pkce();

    $params = [
        'client_id' => $developerKey ?? $config['developer_key'],
        'redirect_uri' => $redirectUri ?? $config['redirect_uri'],
        'response_type' => 'code',
        'code_challenge' => $challenge ?? $pkce['challenge'],
        'code_challenge_method' => 'S256',
        'mode' => 'readonly',
        'state' => $state ?? bin2hex(random_bytes(16)),
    ];

    return 'https://mcp-readonly.pocketsmith.com/oauth/authorize?' . http_build_query($params);
}

function pocketsmith_exchange_token(string $code): array {
    $config = pocketsmith_get_config();
    $session = pocketsmith_load_session();

    $ch = curl_init('https://mcp-readonly.pocketsmith.com/oauth/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => $config['developer_key'],
        'code' => $code,
        'redirect_uri' => $config['redirect_uri'],
        'code_verifier' => $session['verifier'] ?? '',
    ]));

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode((string)$response, true) ?? ['error' => 'Token exchange failed'];
}

function pocketsmith_mcp_request(string $token, string $method, array $params = [], bool $raw = false): array {
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => (object)$params,
        'id' => uniqid(),
    ]);

    $ch = curl_init('https://mcp-readonly.pocketsmith.com/mcp');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
        'Authorization: Bearer ' . $token,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['status' => 0, 'error' => $error];
    }

    if ($raw) {
        return ['status' => $httpCode, 'response' => $response, 'payload' => $payload, 'error' => $error];
    }

    return ['status' => $httpCode, 'response' => json_decode((string)$response, true)];
}

function pocketsmith_save_session(array $session): void {
    $config = pocketsmith_get_config();
    $dir = $config['session_dir'] ?? __DIR__;

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (!isset($session['created_at'])) {
        $session['created_at'] = time();
    }

    $filename = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ps_session.json';
    file_put_contents($filename, base64_encode(json_encode($session)));
}

function pocketsmith_load_session(): array {
    $config = pocketsmith_get_config();
    $dir = $config['session_dir'] ?? __DIR__;
    $filename = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ps_session.json';

    if (!file_exists($filename)) {
        return [];
    }

    $encoded = file_get_contents($filename);
    $decoded = base64_decode((string)$encoded, true);
    if ($decoded === false) {
        return [];
    }

    return json_decode($decoded, true) ?? [];
}

function pocketsmith_get_valid_token(): ?string {
    $session = pocketsmith_load_session();
    if (!isset($session['access_token'], $session['expires_in'], $session['created_at'])) {
        return null;
    }

    if (time() > ((int)$session['created_at'] + (int)$session['expires_in'] - 60)) {
        return null;
    }

    return (string)$session['access_token'];
}
