<?php
// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include PocketSmith library
includePath = __DIR__ . '/includes/pocketsmith.php';

if(!file_exists($includePath)) {
    die('FAILED: Missing ' . htmlspecialchars($includePath));
}

require_once $includePath;

// Load config
try {
    $config = pocketsmith_get_config();
} catch(Throwable $e) {
    die('FAILED: Error loading config: ' . htmlspecialchars($e->getMessage()));
}

// Pre-flight check
if(empty($config['developer_key'] ?? '') || empty($config['redirect_uri'] ?? '')) {
    die('Error: Missing POCKETSМИTH_DEVELOPER_KEY or POCKETSМИTH_REDIRECT_URI in .env file.');
}

$secret = $_GET['secret'] ?? '';
$action = $_GET['action'] ?? '';

// 1. Health check
if($action === 'health') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'operational',
        'env_loaded' => !empty($config),
        'secret_set' => !empty($config['bot_secret'] ?? ''),
        'keys' => array_keys($config)
    ]);
    exit;
}

// 2. Security Check
$botSecret = $config['bot_secret'] ?? '';
$allowed = false;

if($botSecret && hash_equals($botSecret, $secret)) {
    $allowed = true;
}

if(isset($_GET['code'])) {
    try {
        $sessionData = pocketsmith_load_session();
        
        if($sessionData && isset($sessionData['auth_state'])) {
            if(hash_equals($sessionData['auth_state'], $_GET['state'] ?? '')) {
                $allowed = true;
            }
        }
    } catch(Throwable $e) {
        // Silent - session load error
    }
}

if(!$allowed) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// 3. OAuth Callback
if(isset($_GET['code'])) {
    try {
        $sessionData = pocketsmith_load_session();
        
        $result = pocketsmith_exchange_token(
            $config['developer_key'] ?? '',
            $config['redirect_uri'] ?? '',
            $_GET['code'],
            $sessionData['verifier'] ?? ''
        );
        
        // Extract token from nested 'session' array (standard) or top-level (fallback)
        $accessToken = $result['session']['access_token'] ?? $result['access_token'] ?? null;
        $refreshToken = $result['session']['refresh_token'] ?? $result['refresh_token'] ?? null;
        
        if($accessToken) {
            $sessionData['access_token'] = $accessToken;
            if($refreshToken) {
                $sessionData['refresh_token'] = $refreshToken;
            }
            pocketsmith_save_session($sessionData);
            echo 'Authenticated!';
        } else {
            echo 'Authentication failed. Result keys: ' . implode(', ', array_keys($result));
            echo '<br />' . PHP_EOL;
            echo '<pre>' . htmlspecialchars(print_r($result, true)) . '</pre>' . PHP_EOL;
        }
    } catch(Throwable $e) {
        echo 'OAuth callback error: ' . htmlspecialchars($e->getMessage());
    }
    exit;
}

// 4. API Requests
if(!empty($action)) {
    $session = pocketsmith_load_session();
    if(empty($session['access_token']??null)) {
        $PKC = pocketsmith_generate_pkc();
        $auth_state = bin2hex(random_bytes(16));
        $PKC['auth_state'] = $auth_state;
        pocketsmith_save_session($PKC);
        $url = pocketsmith_auth_url(
            $config['developer_key'] ?? '',
            $config['redirect_uri'] ?? '',
            $PKC['challenge'],
            $auth_state
        );
        header("Location: " . $url);
        exit;
    }
    
    $result = pocketsmith_mcp_request($session['access_token'], $action);
    // Extract data from MCP response wrapper
    if(isset($result['data'])) {
        $finalResult = $result['data'];
    } elseif(isset($result['success']) && $result['success']) {
        $finalResult = $result['data'];
    } else {
        $finalResult = $result;
    }
    header('Content-Type: application/json');
    echo json_encode($finalResult);
    exit;
}

// 5. Default: OAuth Flow
try {
    $PKC = pocketsmith_generate_pkc();
    $auth_state = bin2hex(random_bytes(16));
    $PKC['auth_state'] = $auth_state;
    pocketsmith_save_session($PKC);
    $url = pocketsmith_auth_url(
        $config['developer_key'] ?? '',
        $config['redirect_uri'] ?? '',
        $PKC['challenge'],
        $auth_state
    );
    header("Location: " . $url);
} catch(Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
