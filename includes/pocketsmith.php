<?php
function pocketsmith_load_env(string $path): array {
    if (!file_exists($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'"); 
        $lowerKey = strtolower($name);
        $config[$lowerKey] = $value;
        $config[str_replace('pocketsmith_', '', $lowerKey)] = $value;
    }
    return $config;
}

function pocketsmith_get_config(): array {
    $paths = [dirname($_SERVER['SCRIPT_FILENAME']) . '/.env', __DIR__ . '/../.env', __DIR__ . '/.env'];
    foreach ($paths as $path) { if (file_exists($path)) return pocketsmith_load_env($path); }
    return pocketsmith_load_env($paths[0]);
}

function pocketsmith_generate_pkc(): array {
    $verifier = bin2hex(random_bytes(32));
    return ['verifier' => $verifier, 'challenge' => str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(hash('sha256', $verifier, true)))];
}

function pocketsmith_save_session(array $data): bool {
    $sessionFile = sys_get_temp_dir() . '/ps_session_' . md5($_SERVER['REMOTE_ADDR']);
    return file_put_contents($sessionFile, json_encode($data)) !== false;
}

function pocketsmith_load_session(): array {
    $sessionFile = sys_get_temp_dir() . '/ps_session_' . md5($_SERVER['REMOTE_ADDR']);
    return file_exists($sessionFile) ? json_decode(file_get_contents($sessionFile), true) : [];
}

function pocketsmith_mcp_request(string $token, string $method, array $params = []): array {
    $ch = curl_init("https://mcp-readonly.pocketsmith.com/mcp");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => (object)$params, 'id' => uniqid()]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'Authorization: Bearer ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) return ['ok' => false, 'error' => $error];
    return ['status' => $httpCode, 'response' => json_decode($response, true)];
}