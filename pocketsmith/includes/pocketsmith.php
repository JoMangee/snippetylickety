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
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\""); 
        
        // Normalize key: lowercase
        $lowerKey = strtolower($name);
        $config[$lowerKey] = $value;
        
        // Also store without POCKETSMITH_ prefix (handles POCKETSMITH_, pocketsmith_, etc.)
        $noPrefix = str_replace('pocketsmith_', '', $lowerKey);
        $config[$noPrefix] = $value;
        
        // Also try without any prefix variations
        $alternate = strtolower(str_replace('POCKETSMITH_', '', $name));
        $config[trim($alternate)] = $value;
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
    
    $method = 'tools/call';
    $params = [
        'name' => ($action === 'accounts') ? 'list_accounts' : $action,
        'arguments' => (object)[]
    ];
    
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => $params,
        'id' => uniqid()
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    // Add a timeout and follow redirects
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'status' => $httpCode,
            'error' => 'cURL Error: ' . $error
        ];
    }

    return [
        'status' => $httpCode,
        'response' => json_decode($response, true)
    ];
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
