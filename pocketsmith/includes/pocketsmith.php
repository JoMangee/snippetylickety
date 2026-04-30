<?php
declare(strict_types=1);

/**
 * PocketSmith MCP Bridge
 * Core functions for connecting to PocketSmith API
 */

/**
 * Load environment variables from .env file
 * Improved: Supports export prefix, inline comments, and key normalization
 */
function pocketsmith_load_env_from_all_paths(): array {
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
    
    return pocketsmith_load_env($envPath);
}

/**
 * Load environment variables from .env file
 * Improved: Supports export prefix, inline comments, and key normalization
 * 
 * @param string $path Path to the .env file
 * @return array Normalized environment config
 */
function pocketsmith_load_env(string $path): array {
    if (!file_exists($path)) {
        return [];
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip empty lines and comments
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        if (strpos($line, '=') === false) {
            continue;
        }
        
        // Handle "export " prefix
        if (stripos($line, 'export ') === 0) {
            $line = substr($line, 7);
            $line = trim($line);
        }
        
        // Handle inline comments (e.g., "KEY=value # comment")
        if (strpos($line, ' #') !== false) {
            $line = explode(' #', $line)[0];
            $line = trim($line);
        }
        
        list($name, $value) = explode('=', $line, 2);
        
        $name = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        
        // Normalize key: lowercase and strip 'pocketsmith_' prefix (case-insensitive)
        $key = strtolower($name);
        $key = preg_replace('/^pocketsmith_/', '', $key);
        
        $config[$key] = $value;
    }
    
    return $config;
}

/**
 * Get configuration from environment or defaults
 * Now expects normalized keys: 'developer_key', 'redirect_uri', 'bot_secret'
 */
function pocketsmith_get_config(): array {
    $env = pocketsmith_load_env_from_all_paths();
    return [
        'developer_key' => $env['developer_key'] ?? '',
        'redirect_uri' => $env['redirect_uri'] ?? '',
        'bot_secret' => $env['bot_secret'] ?? '',
    ];
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
 * Load session from temp directory
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
