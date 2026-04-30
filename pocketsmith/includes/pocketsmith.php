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
        $value = trim($value, " \t\n\r\0\x0B\"");  
        
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

function pocketsmith_mcp_request(string $token, string $action): array {
    $url = "https://mcp-readonly.pocketsmith.com/mcp";
    
    // Map internal actions to MCP tool calls
    if ($action === 'accounts') {
        // For accounts, we need user_id first
        // This function will be called with action='me' to get user_id
        // Or we can pass user_id as part of the action string
        $toolName = 'list_accounts';
    } elseif ($action === 'me') {
        $toolName = 'get_me';
    } else {
        $toolName = $action;
    }
    
    // If action contains 'with_user:', extract user_id
    $user_id = null;
    if (strpos($action, 'with_user:') === 0) {
        $user_id = str_replace('with_user:', '', $action);
    }
    
    $params = [
        'name' => $toolName,
        'arguments' => new stdClass()
    ];
    
    // Add user_id parameter if available and needed
    if ($user_id !== null) {
        $params['arguments']->userId = $user_id;
    }
    
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'method' => 'tools/call',
        'params' => $params,
        'id' => uniqid()
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $error !== '') {
        return [
            'ok' => false,
            'status' => $httpCode,
            'error' => 'cURL Error: ' . ($error ?: 'No response')
        ];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'ok' => false,
            'status' => $httpCode,
            'error' => 'JSON decode error: ' . json_last_error_msg()
        ];
    }
    
    return $decoded;
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