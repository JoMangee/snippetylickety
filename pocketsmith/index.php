<?php
if (isset($_GET['action']) && $_GET['action'] === 'health') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'php_version' => PHP_VERSION,
        'config_loaded' => function_exists('pocketsmith_get_config'),
        'env_exists' => file_exists(__DIR__ . '/.env'),
        'data_dir_writable' => is_writable(__DIR__ . '/data')
    ]);
    exit;
}

declare(strict_types=1);

if (file_exists(__DIR__ . '/includes/config.php')) {
    require_once __DIR__ . '/includes/config.php';
}
require_once __DIR__ . '/../includes/pocketsmith.php';

$config = pocketsmith_get_config();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// 1. Handle Auth Redirect (Initialized by visiting /pocketsmith)
if ($method === 'GET' && !isset($_GET['code']) && !isset($_GET['action'])) {
    $pck = pocketsmith_generate_pck();

    session_start();
    $_SESSION['ps_pke_verifier'] = $pck['verifier'];

    $authUrl = pocketsmith_auth_url(
        $config['developer_key'],
        $config['redirect_uri'],
        $pck['challenge']
    );

    header('Location: ' . $authUrl);
    exit;
}

// 2. Handle OAuth Callback
if ($method === 'GET' && isset($_GET['code'])) {
    session_start();
    $verifier = $_SESSION['ps_pke_verifier'] ?? '';

    $result = pocketsmith_exchange_token(
        $config['developer_key'],
        $config['redirect_uri'],
        (string)$_GET['code'],
        $verifier
    );

    if ($result['ok']) {
        pocketsmith_save_session($result['session']);
        echo json_encode(['ok' => true, 'message' => 'Authenticated!']);
    } else {
        http_response_code(500);
        echo json_encode($result);
    }
    exit;
}

// 3. Handle API Proxy
$secret = bot_secret();
$inputSecret = '';

if ($method === 'POST') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    $inputSecret = (string)($body['secret'] ?? '');
} else {
    $inputSecret = (string)$_GET['secret'] ?? '';
}

if ($secret === '' || !hash_equals($secret, $inputSecret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$action = (string)$_GET['action'] ?? 'summary';
$session = pocketsmith_load_session();

if (!$session) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'PocketSmith not authenticated']);
    exit;
}

$result = pocketsmith_mcp_request($session['access_token'], $action);
echo json_encode($result);
