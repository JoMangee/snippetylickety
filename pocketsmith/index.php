<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$includePath = __DIR__ . '/includes/pocketsmith.php';
if (!file_exists($includePath)) {
    die("Missing includes");
}
require_once $includePath;

$config = pocketsmith_get_config();
$action = $_GET['action'] ?? '';
$secret = $_GET['secret'] ?? '';
$botToken = $_GET['bot_token'] ?? '';
$botSecret = $config['bot_secret'] ?? '';
$tokenWindow = (int)($config['token_window'] ?? 900);

function pocketsmith_generate_bot_token(string $botSecret, int $window): string {
    $slot = (int)floor(time() / $window);
    return substr(hash_hmac('sha256', (string)$slot, $botSecret), 0, 24);
}

function pocketsmith_validate_bot_token(string $token, string $botSecret, int $window): bool {
    if ($token === '' || $botSecret === '') {
        return false;
    }
    $slot = (int)floor(time() / $window);
    foreach ([$slot, $slot - 1] as $s) {
        $expected = substr(hash_hmac('sha256', (string)$s, $botSecret), 0, 24);
        if (hash_equals($expected, $token)) {
            return true;
        }
    }
    return false;
}

function pocketsmith_is_authorized(string $secret, string $botToken, string $botSecret, int $tokenWindow): bool {
    if ($secret !== '' && hash_equals($botSecret, $secret)) {
        return true;
    }
    return pocketsmith_validate_bot_token($botToken, $botSecret, $tokenWindow);
}

if ($action === 'health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'operational', 'env_loaded' => !empty($config)]);
    exit;
}

// Issue short-lived bot token (requires full secret)
if ($action === 'bot_token') {
    if (!hash_equals($botSecret, $secret)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized - Invalid secret']);
        exit;
    }
    $slot = (int)floor(time() / $tokenWindow);
    $expiresAt = ($slot + 1) * $tokenWindow;
    header('Content-Type: application/json');
    echo json_encode([
        'bot_token' => pocketsmith_generate_bot_token($botSecret, $tokenWindow),
        'expires_in' => max(0, $expiresAt - time()),
        'expires_at' => date('c', $expiresAt),
        'window_seconds' => $tokenWindow,
    ]);
    exit;
}

// SECURITY: Check secret for non-callback requests with empty action
if ($action !== 'health' && empty($action) && !isset($_GET['code'])) {
    if (!pocketsmith_is_authorized($secret, $botToken, $botSecret, $tokenWindow)) {
        header('Content-Type: application/json');
        die(json_encode([
            'error' => 'Unauthorized - Invalid secret',
            'keys_found' => array_keys($config),
            'secret_provided_length' => strlen($secret),
            'config_secret_length' => strlen($config['bot_secret'] ?? '')
        ]));
    }
}

if ($action === 'auth' || (empty($action) && !isset($_GET['code']))) {
    // OAuth initiation remains privileged: full secret only.
    if (!hash_equals($botSecret, $secret)) {
        header('Content-Type: application/json');
        die(json_encode([
            'error' => 'Unauthorized - Invalid secret',
            'keys_found' => array_keys($config),
            'secret_provided_length' => strlen($secret),
            'config_secret_length' => strlen($config['bot_secret'] ?? '')
        ]));
    }
    $pkce = pocketsmith_generate_pkce(); // STANDARDIZED: pocketsmith_generate_pkce (PKCE)
    $auth_state = bin2hex(random_bytes(16));
    pocketsmith_save_session(['verifier' => $pkce['verifier'], 'state' => $auth_state]);
    $params = [
        'client_id' => $config['developer_key'],
        'redirect_uri' => $config['redirect_uri'],
        'response_type' => 'code',
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'S256',
        'mode' => 'readonly',
        'state' => $auth_state,
    ];
    header("Location: https://mcp-readonly.pocketsmith.com/oauth/authorize?" . http_build_query($params));
    exit;
}

if (isset($_GET['code'])) {
    $session = pocketsmith_load_session();
    $ch = curl_init("https://mcp-readonly.pocketsmith.com/oauth/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => $config['developer_key'],
        'code' => $_GET['code'],
        'redirect_uri' => $config['redirect_uri'],
        'code_verifier' => $session['verifier'] ?? '',
    ]));
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $token = $result['session']['access_token'] ?? $result['access_token'] ?? null;
    if ($token) {
        $session['access_token'] = $token;
        pocketsmith_save_session($session);
        echo "Authenticated!";
    } else {
        echo "Handshake failed";
    }
    exit;
}

if (!empty($action)) {
    if (!pocketsmith_is_authorized($secret, $botToken, $botSecret, $tokenWindow)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized - Invalid secret or bot_token']);
        exit;
    }
    $session = pocketsmith_load_session();
    if (empty($session['access_token'])) {
        die("Unauthorized - No access token. Please visit the auth link: https://ps.tinypeople.mesh.net.nz/index.php?action=auth");
    }

    header('Content-Type: application/json');
    
    // Debug mode: return raw response
    $raw_mode = ($action === 'debug');
    if ($raw_mode) $action = 'accounts';
    
    // Handle tools.list for discovery
    if (!$raw_mode) {
        if ($action === 'list_tools') {
            $method = 'tools.list';
            $args = [];
        } else {
            // Map actions to official dot-notation methods
            $method = ($action === 'accounts') ? 'accounts.list' : $action;
            if ($action === 'me') {
                $method = 'user.get';
            }
            $args = (isset($_GET['user_id'])) ? ['user_id' => (int)$_GET['user_id']] : [];
        }
        
        echo json_encode(pocketsmith_mcp_request($session['access_token'], $method, $args));
    } else {
        echo json_encode(pocketsmith_mcp_request($session['access_token'], 'accounts.list', [], true));
    }
    exit;
}