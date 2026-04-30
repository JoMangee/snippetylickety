<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/pocketsmith.php';

$config = pocketsmith_get_config();
$secret = $_GET['secret'] ?? '';
$action = $_GET['action'] ?? '';

// 1. Health check (generic)
if ($action === 'health') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'operational',
        'env_loaded' => !empty($config),
        'secret_set' => !empty($config['bot_secret'] ?? ''),
        'keys' => array_keys($config)
    ]);
    exit;
}

// 2. Security Check - enhanced with OAuth state validation
$botSecret = $config['bot_secret'] ?? '';
$allowed = false;

// Check explicit secret first
if ($botSecret && hash_equals($botSecret, $secret)) {
    $allowed = true;
}

// For OAuth callbacks, also validate oauth_state from session
if (isset($_GET['code'])) {
    $sessionData = pocketsmith_load_session();
    if ($sessionData && isset($sessionData['oauth_state'])) {
        if (hash_equals($sessionData['oauth_state'], $_GET['state'] ?? '')) {
            $allowed = true;
        }
    }
}

if (!$allowed) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// 3. Handle OAuth Callback (moved after security check)
if (isset($_GET['code'])) {
    $sessionData = pocketsmith_load_session();
    $result = pocketsmith_exchange_token(
        $config['developer_key'],
        $config['redirect_uri'],
        $_GET['code'],
        $sessionData['code_verifier'] ?? ''
    );
    
    if (isset($result['access_token'])) {
        $sessionData['access_token'] = $result['access_token'];
        pocketsmith_save_session($sessionData);
        echo "Authenticated!";
    } else {
        echo "Authentication failed.";
    }
    exit;
}

// 4. Handle API Requests
if (!empty($action)) {
    $session = pocketsmith_load_session();
    if (empty($session['access_token'])) {
        $pkce = pocketsmith_generate_pkc();
        $oauth_state = bin2hex(random_bytes(16));
        $pkce['oauth_state'] = $oauth_state;
        pocketsmith_save_session($pkce);
        $url = pocketsmith_auth_url($config['developer_key'], $config['redirect_uri'], $pkce['challenge'], $oauth_state);
        header("Location: $url");
        exit;
    }
    
    $result = pocketsmith_mcp_request($session['access_token'], $action);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 5. Default: Start OAuth flow
$pkce = pocketsmith_generate_pkc();
$oauth_state = bin2hex(random_bytes(16));
$pkce['oauth_state'] = $oauth_state;
pocketsmith_save_session($pkce);
$url = pocketsmith_auth_url($config['developer_key'], $config['redirect_uri'], $pkce['challenge'], $oauth_state);
header("Location: $url");
