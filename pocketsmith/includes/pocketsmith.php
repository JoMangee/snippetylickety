<?php
declare(strict_types=1);

/**
 * Pocketsmith MCP Bridge Helpers (OAuth 2.0 + PKCE)
 */

function pocketsmith_load_env(string $path): array {
    if (!file_exists($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpbrk($line, '#') === 0) continue;
        if (strpbrk($line, '=') === false) continue;
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\n\r\x0B\"");  
        
        $lowerKey = strtolower($name);
        $config[$lowerKey] = $value;
        
        $noPrefix = str_replace('pocketsmith_', '', $lowerKey);
        $config[$noPrefix] = $value;
    }
    return $config;
}

function pocketsmith_get_config(): array {
    // Try multiple paths to find .env file
    $paths = [
        // Path relative to index.php location
        dirname($_SERVER['SCRIPT_FILENAME']) . '/.env',
        // Path if pocketsmith.php is in includes/ directory
        __DIR__ . '/../.env',
        // Fallback
        __DIR__ . '/.env'
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            return pocketsmith_load_env($path);
        }
    }
    return [];
}

function pocketsmith_generate_pkce(): array {
    $verifier = bin2hex(random_bytes(32));
    $challenge = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(hash('SHA256', $verifier, true)));
    return ['verifier' => $verifier, 'challenge' => $challenge];
}

function pocketsmith_auth_url(string $developerKey, string $redirectUri, string $challenge, string $state): string {
    $params = [
        'client_id' => $developerKey,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'mode' => 'readonly',
        'state' => $state
    ];
    return 'https://mcp-readonly.pocketsmith.com/oauth/authorize?' . http_build_query($params);
}

function pocketsmith_exchange_token(string $developerKey, string $redirectUri, string $code, string $verifier): array {
    $params = [
        'client_id' => $developerKey,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'code_verifier' => $verifier
    ];
    $ch = curl_init('https://mcp-readonly.pocketsmith.com/oauth/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $response = curl_exec($ch);
    $decoded = json_decode((string)$response, true);
    curl_close($ch);
    if (!isset($decoded['access_token'])) {
        return ['ok' => false, 'error' => 'Token exchange failed', 'raw' => $decoded];
    }
    return ['ok' => true, 'session' => $decoded];
}

function pocketsmith_mcp_request(string $token, string $method, array $params = [], bool $raw = false): array {
    // If method is a direct tool name (not 'tools/call' or 'list_tools'), wrap it
    if ($method !== 'tools/call' && $method !== 'tools/list' && !str_starts_with($method, 'tools/')) {
        // Wrap as tools/call
        $method = 'tools/call';
        $params = [
            'name' => $method,
            'arguments' => (object)$params
        ];
    }
    
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => (object)$params,
        'id' => uniqid()
    ]);
    
    if ($raw) {
        // For debug mode, return the payload and response as strings
        $ch = curl_init("https://mcp-readonly.pocketsmith.com/mcp");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        return ['status' => $httpCode, 'response' => $response, 'payload' => $payload, 'error' => $error];
    }
    
    // Standard mode: parse JSON response
    $ch = curl_init("https://mcp-readonly.pocketsmith.com/mcp");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) return ['ok' => false, 'error' => $error];
    return ['status' => $httpCode, 'response' => json_decode($response, true)];
}

function pocketsmith_save_session(array $session): void {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($dir . '/pocketsmith_session.json', json_encode($session));
}

function pocketsmith_load_session(): ?array {
    $path = __DIR__ . '/../data/pocketsmith_session.json';
    return file_exists($path) ? json_decode(file_get_contents($path), true) : null;
}