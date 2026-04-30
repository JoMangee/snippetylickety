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
    echo json_encode(['status' => 'operational']);
    exit;
}

// 2. Security Check (stable secret from .env)
$botSecret = $config['bot_secret'] ?? '';
if (empty($botSecret) || !hash_equals($botSecret, $secret)) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// 3. Handle OAuth Callback
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
        pocketsmith_save_session($pkce);
        $url = pocketsmith_auth_url($config['developer_key'], $config['redirect_uri'], $pkce['code_challenge']);
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
pocketsmith_save_session($pkce);
$url = pocketsmith_auth_url($config['developer_key'], $config['developer_key'], $pkce['code_challenge']);
header("Location: $url");