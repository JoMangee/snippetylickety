<?php
$includePath = __DIR__ . '/includes/pocketsmith.php';
if (!file_exists($includePath)) die("Missing includes");
require_once $includePath;

$config = pocketsmith_get_config();
$action = $_GET['action'] ?? '';
$secret = $_GET['secret'] ?? '';

if ($action === 'health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'operational', 'env_loaded' => !empty($config)]);
    exit;
}

if ($action === 'auth' || (empty($action) && !isset($_GET['code']))) {
    if ($secret !== ($config['bot_secret'] ?? '')) die("Unauthorized");
    $pck = pocketsmith_generate_pck();
    $auth_state = bin2hex(random_bytes(16));
    pocketsmith_save_session(['verifier' => $pck['verifier'], 'state' => $auth_state]);
    $params = ['client_id' => $config['developer_key'], 'redirect_uri' => $config['redirect_uri'], 'response_type' => 'code', 'code_challenge' => $pck['challenge'], 'code_challenge_method' => 'S256', 'mode' => 'readonly', 'state' => $auth_state];
    header("Location: https://mcp-readonly.pocketsmith.com/oauth/authorize?" . http_build_query($params));
    exit;
}

if (isset($_GET['code'])) {
    $session = pocketsmith_load_session();
    $ch = curl_init("https://mcp-readonly.pocketsmith.com/oauth/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['grant_type' => 'authorization_code', 'client_id' => $config['developer_key'], 'code' => $_GET['code'], 'redirect_uri' => $config['redirect_uri'], 'code_verifier' => $session['verifier']]));
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
    $session = pocketsmith_load_session();
    if (empty($session['access_token'])) die("Unauthorized");

    header('Content-Type: application/json');
    
    // Map 'accounts' to 'list_accounts'
    $tool = ($action === 'accounts') ? 'list_accounts' : $action;
    if ($action === 'me') $tool = 'get_current_user';
    
    $args = (isset($_GET['user_id'])) ? ['user_id' => (int)$_GET['user_id']] : [];
    
    echo json_encode(pocketsmith_mcp_request($session['access_token'], $tool, $args));
    exit;
}
