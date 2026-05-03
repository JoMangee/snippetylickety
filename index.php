<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$includePath = __DIR__ . '/includes/pocketsmith.php';
if (!file_exists($includePath)) die("Missing includes");
require_once $includePath;

$config = pocketsmith_get_config();
$action = $_GET['action'] ?? '';
$secret = $_GET['secret'] ?? '';
$bot_token = $_GET['bot_token'] ?? '';

// Bot token: time-windowed HMAC digest (configurable window, stateless)
// Token = first 24 chars of HMAC-SHA256(bot_secret, floor(time/window))
// Accepts current and previous window to avoid boundary failures
$token_window = (int)($config['token_window'] ?? 900); // seconds, default 15 min
function pocketsmith_generate_bot_token(string $bot_secret, int $window = 900): string {
    $slot = (int)floor(time() / $window);
    return substr(hash_hmac('sha256', (string)$slot, $bot_secret), 0, 24);
}
function pocketsmith_validate_bot_token(string $token, string $bot_secret, int $window = 900): bool {
    if (empty($token) || empty($bot_secret)) return false;
    $slot = (int)floor(time() / $window);
    // Check current and previous window to avoid boundary edge failures
    foreach ([$slot, $slot - 1] as $s) {
        $expected = substr(hash_hmac('sha256', (string)$s, $bot_secret), 0, 24);
        if (hash_equals($expected, $token)) return true;
    }
    return false;
}

// Helper: check if request is authorised via secret OR valid bot_token
function pocketsmith_is_authorised(string $secret, string $bot_token, string $bot_secret): bool {
    if (!empty($secret) && hash_equals($bot_secret, $secret)) return true;
    if (!empty($bot_token) && pocketsmith_validate_bot_token($bot_token, $bot_secret)) return true;
    return false;
}

$bot_secret = $config['bot_secret'] ?? '';

// Issue a time-limited bot token (requires full secret to obtain)
if ($action === 'bot_token') {
    if (!hash_equals($bot_secret, $secret)) {
        http_response_code(403);
        die(json_encode(['error' => 'Unauthorized']));
    }
    header('Content-Type: application/json');
    $token = pocketsmith_generate_bot_token($bot_secret, $token_window);
    $slot = (int)floor(time() / $token_window);
    $expires_in = ($slot + 1) * $token_window - time();
    echo json_encode([
        'bot_token' => $token,
        'expires_in' => $expires_in,
        'expires_at' => date('c', ($slot + 1) * $token_window),
        'window_seconds' => $token_window,
    ]);
    exit;
}

// Health check
if ($action === 'health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'operational', 'env_loaded' => !empty($config)]);
    exit;
}

// User-facing session status check
if ($action === 'session_status') {
    if (!pocketsmith_is_authorised($secret, $bot_token, $bot_secret)) die("Unauthorized");
    $session = pocketsmith_load_session();
    $token = $session['access_token'] ?? null;
    $created = $session['created_at'] ?? null;
    $expires = $session['expires_in'] ?? null;
    header('Content-Type: application/json');
    if ($token && $created && $expires) {
        $expiry = $created + $expires;
        echo json_encode([
            'status' => 'authenticated',
            'token_expires_at' => date('c', $expiry),
            'token_valid' => time() < $expiry,
        ]);
    } else {
        echo json_encode(['status' => 'not_authenticated']);
    }
    exit;
}

// Session path diagnostic - never exposes token values
if ($action === 'session_debug') {
    if (!pocketsmith_is_authorised($secret, $bot_token, $bot_secret)) die("Unauthorized");
    $config_check = pocketsmith_get_config();
    $session_dir = $config_check['session_dir'] ?? (__DIR__ . '/includes');
    $session_file = rtrim($session_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ps_session.json';
    $session = pocketsmith_load_session();
    header('Content-Type: application/json');
    echo json_encode([
        'session_file_path' => $session_file,
        'session_file_exists' => file_exists($session_file),
        'session_file_readable' => is_readable($session_file),
        'session_dir_writable' => is_writable(dirname($session_file)),
        'has_access_token' => !empty($session['access_token']),
        'has_verifier' => !empty($session['verifier']),
        'has_created_at' => isset($session['created_at']),
        'has_expires_in' => isset($session['expires_in']),
        'token_expired' => isset($session['created_at'], $session['expires_in'])
            ? time() > ($session['created_at'] + $session['expires_in'] - 60)
            : null,
        'php_include_dir' => __DIR__,
    ]);
    exit;
}

// OAuth: Initiate auth flow (requires full secret, not bot_token)
if ($action === 'auth' || (empty($action) && !isset($_GET['code']))) {
    if (!hash_equals($bot_secret, $secret)) die("Unauthorized");
    $pk = pocketsmith_generate_pkce();
    $auth_state = bin2hex(random_bytes(16));
    pocketsmith_save_session(['verifier' => $pk['verifier'], 'state' => $auth_state]);
    $params = [
        'client_id'             => $config['developer_key'],
        'redirect_uri'          => $config['redirect_uri'],
        'response_type'         => 'code',
        'code_challenge'        => $pk['challenge'],
        'code_challenge_method' => 'S256',
        'mode'                  => 'readonly',
        'state'                 => $auth_state,
    ];
    header("Location: https://mcp-readonly.pocketsmith.com/oauth/authorize?" . http_build_query($params));
    exit;
}

// OAuth: Handle callback and exchange code for token
if (isset($_GET['code'])) {
    $session = pocketsmith_load_session();
    $ch = curl_init("https://mcp-readonly.pocketsmith.com/oauth/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type'    => 'authorization_code',
        'client_id'     => $config['developer_key'],
        'code'          => $_GET['code'],
        'redirect_uri'  => $config['redirect_uri'],
        'code_verifier' => $session['verifier'] ?? '',
    ]));
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $token = $result['session']['access_token'] ?? $result['access_token'] ?? null;
    if ($token) {
        $session['access_token'] = $token;
        $session['expires_in'] = $result['expires_in'] ?? 3600;
        $session['created_at'] = time();
        pocketsmith_save_session($session);
        echo "Authenticated! Session token saved. You can now use the API.";
    } else {
        echo "Handshake failed. Please try authenticating again.";
    }
    exit;
}

// API proxy: handle action requests
if (!empty($action)) {
    if (!pocketsmith_is_authorised($secret, $bot_token, $bot_secret)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized', 'hint' => 'Use ?secret= or obtain a ?bot_token= via ?action=bot_token&secret=...']);
        exit;
    }
    $session = pocketsmith_load_session();
    if (empty($session['access_token'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Not authenticated',
            'message' => 'No access token found. Please authenticate first.',
        ]);
        exit;
    }

    header('Content-Type: application/json');

    // Debug mode: return raw response
    if ($action === 'debug') {
        echo json_encode(pocketsmith_mcp_request($session['access_token'], 'accounts.list', [], true));
        exit;
    }

    // Map friendly action names to MCP method names
    $method_map = [
        'accounts'   => 'accounts.list',
        'me'         => 'user.get',
        'list_tools' => 'tools.list',
    ];
    $tool = $method_map[$action] ?? $action;
    $args = isset($_GET['user_id']) ? ['user_id' => (int)$_GET['user_id']] : [];

    echo json_encode(pocketsmith_mcp_request($session['access_token'], $tool, $args));
    exit;
}