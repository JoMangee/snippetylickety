<?php
// Debug output first to catch parse errors
header('X-Debug: PocketSmith Index');
echo '<!-- PHP Initialized -->' . PHP_EOL;

echo '<!-- Debug: Line 1 -->' . PHP_EOL;
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
echo '<!-- Debug: Line 2 -->' . PHP_EOL;
error_reporting(E_ALL);
echo '<!-- Debug: Line 3 -->' . PHP_EOL;

// FIXED: Added $ prefix before includePath
$includePath = __DIR__ . '/includes/pocketsmith.php';
echo '<!-- Debug: Include path: ' . htmlspecialchars($includePath) . ' -->' . PHP_EOL;

if (!file_exists($includePath)) {
    die('FATAL: Missing ' . htmlspecialchars($includePath));
}
echo '<!-- Debug: Line 5 -->' . PHP_EOL;

require_once $includePath;
echo '<!-- Debug: Includes loaded successfully -->' . PHP_EOL;
echo '<!-- Debug: Line 6 -->' . PHP_EOL;

$config = pocketsmith_get_config();
$secret = $_GET['secret'] ?? '';
$action = $_GET['action'] ?? '';

if (empty($config['developer_key'] ?? '') || empty($config['redirect_uri'] ?? '')) {
    header('Content-Type: application/json');
    $envPath = dirname(__DIR__) . '/.env';
    echo json_encode([
        'ok' => false,
        'error' => 'Missing POCKETSMITH_DEVELOPER_KEY or POCKETSMITH_REDIRECT_URI',
        'env_path' => $envPath,
        'file_exists' => file_exists($envPath),
        'env_content_preview' => file_exists($envPath) ? substr(file_get_contents($envPath), 0, 200) : 'N/A',
        'keys_found' => array_keys($config),
        'config_count' => count($config)
    ]);
    exit;
}

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

$botSecret = $config['bot_secret'] ?? '';
$allowed = false;

if ($botSecret && hash_equals($botSecret, $secret)) {
    $allowed = true;
}

if (isset($_GET['code'])) {
    try {
        $sessionData = pocketsmith_load_session();
        if ($sessionData && isset($sessionData['auth_state'])) {
            if (hash_equals($sessionData['auth_state'], $_GET['state'] ?? '')) {
                $allowed = true;
            }
        }
    } catch (Throwable $e) {
        // Silent - session load error
    }
}

if (!$allowed) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if (isset($_GET['code'])) {
    try {
        $sessionData = pocketsmith_load_session();
        $result = pocketsmith_exchange_token(
            $config['developer_key'] ?? '',
            $config['redirect_uri'] ?? '',
            $_GET['code'],
            $sessionData['verifier'] ?? ''
        );
        $accessToken = $result['session']['access_token'] ?? $result['access_token'] ?? null;
        $refreshToken = $result['session']['refresh_token'] ?? $result['refresh_token'] ?? null;
        if ($accessToken) {
            $sessionData['access_token'] = $accessToken;
            if ($refreshToken) {
                $sessionData['refresh_token'] = $refreshToken;
            }
            pocketsmith_save_session($sessionData);
            echo 'Authenticated!';
        } else {
            echo 'Authentication failed. Result keys: ' . implode(', ', array_keys($result));
            echo '<br />' . PHP_EOL;
            echo '<pre>' . htmlspecialchars(print_r($result, true)) . '</pre>' . PHP_EOL;
        }
    } catch (Throwable $e) {
        echo 'OAuth callback error: ' . htmlspecialchars($e->getMessage());
    }
    exit;
}

if (!empty($action)) {
    $session = pocketsmith_load_session();
    if (empty($session['access_token'] ?? null)) {
        $pkc = pocketsmith_generate_pkc();
        $auth_state = bin2hex(random_bytes(16));
        $pkc['auth_state'] = $auth_state;
        pocketsmith_save_session($pkc);
        $url = pocketsmith_auth_url(
            $config['developer_key'] ?? '',
            $config['redirect_uri'] ?? '',
            $pkc['challenge'],
            $auth_state
        );
        header("Location: " . $url);
        exit;
    }
    
    $result = pocketsmith_mcp_request($session['access_token'], $action);
    header('Content-Type: application/json');
    if (isset($result['response'])) {
        echo json_encode($result['response']);
    } else {
        echo json_encode($result);
    }
    exit;
}

try {
    $pkc = pocketsmith_generate_pkc();
    $auth_state = bin2hex(random_bytes(16));
    $pkc['auth_state'] = $auth_state;
    pocketsmith_save_session($pkc);
    $url = pocketsmith_auth_url(
        $config['developer_key'] ?? '',
        $config['redirect_uri'] ?? '',
        $pkc['challenge'],
        $auth_state
    );
    header("Location: " . $url);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}