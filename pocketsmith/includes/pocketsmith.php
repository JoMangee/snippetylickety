<?php
declare(strict_types=1);

/**
 * PocketSmith MCP Bridge
 * Core functions for connecting to PocketSmith API
 */

/**
 * Load environment variables from .env file
 * Enhanced to work from both index.php and test_pocketsmith.php locations
 */
function pocketsmith_load_env(): array {
    // Primary path: .env in the parent directory (works for both index.php and test_pocketsmith.php)
    $parentEnv = __DIR__ . '/../.env';
    
    // Fallback paths
    $scriptEnv = $_SERVER['SCRIPT_FILENAME'] . '/../.env';
    $includedEnv = __DIR__ . '/includes/.env';
    
    $paths = [$parentEnv, $scriptEnv, $includedEnv];
    
    $envPath = null;
    foreach ($paths as $path) {
        if (file_exists($path)) {
            $envPath = $path;
            break;
        }
    }
    
    if (!$envPath) {
        return [];
    }
    
    $config = [];
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $config[trim($name)] = trim($value);
    }
    return $config;
}

/**
 * Get configuration from environment or defaults
 */
function pocketsmith_get_config(): array {
    $env = pocketsmith_load_env();
    return array_merge([
        'developer_key' => $env['DEVELOPER_KEY'] ?? '',
        'redirect_uri' => $env['REDIRECT_URI'] ?? '',
        'bot_secret' => $env['BOT_SECRET'] ?? '',
    ], $env);
}

/**
 * Generate PKCE verifier and challenge
 * STANDARDIZED NAME: pocketsmith_generate_pkce (PKCE = Proof Key for Code Exchange)
 */
function pocketsmith_generate_pkce(): array {
    $verifier = bin2hex(random_bytes(32));
    $challenge = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(hash('SHA256', $verifier, true)));
    return ['verifier' => $verifier, 'challenge' => $challenge];
}

/**
 * Generate OAuth authorization URL
 */
function pocketsmith_auth_url(): string {
    $config = pocketsmith_get_config();
    $pkce = pocketsmith_generate_pkce();
    $params = [
        'client_id' => $config['developer_key'],
        'redirect_uri' => $config['redirect_uri'],
        'response_type' => 'code',
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'S256',
        'mode' => 'readonly',
        'state' => bin2hex(random_bytes(16)),
    ];
    return 'https://mcp-readonly.pocketsmith.com/oauth/authorize?' . http_build_query($params);
}

/**
 * Exchange authorization code for access token
 */
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
    
    return json_decode($response, true) ?? ['error' => 'Token exchange failed'];
}

/**
 * Make MCP request to PocketSmith MCP-readonly endpoint
 * 
 * CRITICAL: Method name is used DIRECTLY as JSON-RPC method field.
 * No 'tools/call' wrapper. Direct method names only.
 * 
 * @param string $token OAuth access token
 * @param string $method Method name (e.g. 'accounts.list', 'user.get', 'tools.list')
 * @param array $params Method parameters
 * @param bool $raw Return raw response string instead of parsed JSON
 * @return array
 */
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
    
    return ['status' => $httpCode, 'response' => json_decode($response, true)];
}

/**
 * Save session to temp directory
 */
function pocketsmith_save_session(array $session): void {
    $dir = sys_get_temp_dir();
    $hash = md5($_SERVER['REMOTE_ADDR'] ?? 'default');
    $filename = "{$dir}/ps_session_{$hash}";
    file_put_contents($filename, json_encode($session));
}

/**
 * Load session to temp directory
 */
function pocketsmith_load_session(): array {
    $dir = sys_get_temp_dir();
    $hash = md5($_SERVER['REMOTE_ADDR'] ?? 'default');
    $filename = "{$dir}/ps_session_{$hash}";
    
    if (!file_exists($filename)) {
        return [];
    }
    
    $data = file_get_contents($filename);
    return json_decode($data, true) ?? [];
}