<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/includes/pocketsmith.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// 1. Handle Auth Redirect (Initiated by visiting /pocketsmith)
if ($method === 'GET' && !isset($_GET['code']) && !isset($_GET['action'])) {
    $config = app_config();
    $pkce = pocketsmith_generate_pkce();

    session_start();
    $_SESSION['ps_pkce_verifier'] = $pkce['verifier'];

    $authUrl = pocketsmith_auth_url(
        $config['pocketsmith_client_id'],
        $config['pocketsmith_redirect_uri'],
        $pkce['challenge']
    );

    header('Location: ' . $authUrl);
    exit;
}

// 2. Handle OAuth Callback
if ($method === 'GET' && isset($_GET['code'])) {
    session_start();
    $verifier = $_SESSION['ps_pkce_verifier'] ?? '';
    $config = app_config();

    $result = pocketsmith_exchange_token(
        $config['pocketsmith_client_id'],
        $config['pocketsmith_redirect_uri'],
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
    $inputSecret = (string)($_GET['secret'] ?? '');
}

if ($secret === '' || !hash_equals($secret, $inputSecret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$action = (string)($_GET['action'] ?? 'summary');
$session = pocketsmith_load_session();

if (!$session) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'PocketSmith not authenticated']);
    exit;
}

$result = pocketsmith_mcp_request($session['access_token'], $action);
echo json_encode($result);
