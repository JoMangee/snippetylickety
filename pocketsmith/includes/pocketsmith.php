<?php
declare(strict_types=1);

/**
 * PocketSmith MCP Bridge Helpers (OAuth 2.0 + PKCE)
 */

function pocketsmith_load_env(string $path): array {
    if (!file_exists($path)) {
        return [];
    }
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
        
        // Also store without the POCKETSMITH_ prefix for easy access
        $noPrefix = str_replace('pocketsmith_', '', $lowerKey);
        $config[$noPrefix] = $value;
    }
    return $config;
}

function pocketsmith_get_config(): array {
    $envPath = dirname(__DIR__) . '/.env';
    return pocketsmith_load_env($envPath);
}

function pocketsmith_generate_pkc(): array {
    $verifier = bin2hex(random_bytes(32));
    $challenge = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(hash('sha256', $verifier, true)));
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

function pocketsmith_mcp_request(string $token, string $action): array {
    $url = 'https://mcp-readonly.pocketsmith.com/mcp';
    
    // Build JSON-RPC 2.0 request body
    $requestBody = json_encode([
        'jsonrpc' => '2.0',
        'id' => uniqid(),
        'method' => 'tools/call',
        'params' => [
            'name' => $action,
            'arguments' => []
        ]
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($token . ':'),
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $decoded = json_decode($response, true);
    
    // Handle MCP response format
    if ($decoded && isset($decoded['result'])) {
        return ['success' => true, 'data' => $decoded['result']];
    } elseif ($decoded && isset($decoded['error'])) {
        return ['success' => false, 'error' => $decoded['error']['message'] ?? 'MCP error', 'raw' => $decoded];
    } else {
        return ['success' => false, 'error' => 'Invalid response', 'raw' => $response];
    }
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
