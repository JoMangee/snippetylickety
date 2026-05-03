/**
 * Get a valid (non-expired) access token from session, or null if expired/missing.
 * Handles all expiry logic for the end user.
 */
function pocketsmith_get_valid_token(): ?string {
    $session = pocketsmith_load_session();
    if (!$session || !isset($session['access_token'], $session['expires_in'], $session['created_at'])) {
        return null;
    /**
     * Save session to a configurable directory (default: /includes, permission locked)
     */
    function pocketsmith_save_session(array $session): void {
        $config = pocketsmith_get_config();
        $dir = $config['session_dir'] ?? (__DIR__);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ps_session.json';
        // Add created_at if not present
        if (!isset($session['created_at'])) {
            $session['created_at'] = time();
        }
        // Encode session as base64 JSON for simple obfuscation
        $encoded = base64_encode(json_encode($session));
        file_put_contents($filename, $encoded);
    }

/**
 * PocketSmith MCP Bridge
 * Core functions for connecting to PocketSmith API
 */

/**
 * Load environment variables from .env file
 * EXACT user-provided function with case-insensitive prefix stripping
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
        
        list($name, $value) = explode('=', $line, 2);
        $name = strtolower(trim($name));
        $value = trim($value, " \t\n\r\0\x0B\"");
        
        // Remove both POCKETSMITH_ and pocketsmith_ prefixes (case-insensitive)
        $noPrefix = preg_replace('/^pocketsmith_/i', '', $name);
        
        $config[$name] = $value;
        $config[$noPrefix] = $value;
    }
    
    return $config;
}

/**
 * Load environment from most likely paths
 * Uses __DIR__ relative paths for reliability across index.php and test_pocketsmith.php
 */
function pocketsmith_load_env_from_all_paths(): array {
    $parentEnv = __DIR__ . '/../.env';
    $scriptEnv = $_SERVER['SCRIPT_FILENAME'] . '/../.env';
    $includedEnv = __DIR__ . '/includes/.env';
    
    foreach ([$parentEnv, $scriptEnv, $includedEnv] as $path) {
        if (file_exists($path)) {
            return pocketsmith_load_env($path);
        }
    }
    
    return [];
}

/**
 * Get configuration from environment or defaults
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
    // Add created_at if not present
    if (!isset($session['created_at'])) {
        $session['created_at'] = time();
    }
    file_put_contents($filename, json_encode($session));
}

/**
 * Load session from a configurable directory (default: /includes)
 */
function pocketsmith_load_session(): array {
    $config = pocketsmith_get_config();
    $dir = $config['session_dir'] ?? (__DIR__);
    $filename = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ps_session.json';
    if (!file_exists($filename)) {
        return [];
    }
    $encoded = file_get_contents($filename);
    $data = json_decode(base64_decode($encoded), true);
    return $data ?? [];
}