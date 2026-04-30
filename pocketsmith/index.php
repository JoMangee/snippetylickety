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

// Pre-check include path
echo '<!-- Debug: Line 4 -->' . PHP_EOL;
$includePath = __DIR__ . '/includes/pocketsmith.php';
echo '<!-- Debug: Include path: ' . htmlspecialchars($includePath) . ' -->' . PHP_EOL;

if(!file_exists($includePath)) {
    die('FAILED: Missing ' . htmlspecialchars($includePath));
}
echo '<!-- Debug: Line 5 -->' . PHP_EOL;

require_once $includePath;
echo '<!-- Debug: Includes loaded successfully -->' . PHP_EOL;

// Load config
echo'<!-- Debug: Loading config... -->' . PHP_EOL;
try {
    $config = pocketsmith_get_config();
    echo'<!-- Debug: Config loaded, keys: ' . implode(', ', array_keys($config)) . ' -->' . PHP_EOL;
} catch(Throwable $e) {
    echo'<!-- Error loading config: ' . htmlspecialchars($e->getMessage()) . ' -->' . PHP_EOL;
    die('FAILED: Error loading config: ' . htmlspecialchars($e->getMessage()));
}
echo'<!-- Debug: Line 7 -->' . PHP_EOL;

// Pre-flight check with proper error message (no line breaks in string)
if(empty($config['developer_key'] ?? '') || empty($config['redirect_uri'] ?? '')) {
    die('Error: Missing POCKETSMITH_DEVELOPER_KEY or POCKETSMITH_REDIRECT_URI in .env file.');
}
echo'<!-- Debug: Pre-flight check passed -->' . PHP_EOL;
echo'<!-- Debug: Line 8 -->' . PHP_EOL;

$secret = $_GET['secret']??'';
echo'<!-- Debug: secret val: ' . htmlspecialchars($secret) . ' -->' . PHP_EOL;
$action = $_GET['action']??'';

// 1. Health check
if($action ==='health') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'operational',
        'env_loaded' => !empty($config),
        'secret_set' => !empty($config['bot_secret']??''),
        'keys' => array_keys($config)
    ]);
    exit;
}
echo'<!-- Debug: Line 9 -->' . PHP_EOL;

// 2. Security Check
$botSecret = $config['bot_secret']??'';
$allowed = false;

if($botSecret && hash_equals($botSecret, $secret)) {
    $allowed = true;
}

if(isset($_GET['code'])) {
    try {
        $sessionData = pocketsmith_load_session();
        if($sessionData && isset($sessionData['auth_state'])) {
            if(hash_equals($sessionData['auth_state'], $_GET['state']??'')) {
                $allowed = true;
            }
        }
    } catch(Throwable $e) {
        echo'<!-- Session load error: ' . htmlspecialchars($e->getMessage()) . ' -->' . PHP_EOL;
    }
}

if(!$allowed) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}
echo'<!-- Debug: Line 10 -->' . PHP_EOL;

// 3. OAuth Callback
if(isset($_GET['code'])) {
    try {
        $sessionData = pocketsmith_load_session();
        $verifierExists = isset($sessionData['verifier']) ? 'yes' : 'no';
        $codeVerifierValue = $sessionData['verifier']??'NOT_SET';
        
        echo'<!-- Debug: Loaded session for token exchange. Verifier exists: ' . $verifierExists . ' -->' . PHP_EOL;
        echo'<!-- Debug: Code verifier value: ' . htmlspecialchars($codeVerifierValue) . ' -->' . PHP_EOL;
        
        $result = pocketsmith_exchange_token(
            $config['developer_key']??'',
            $config['redirect_uri']??'',
            $_GET['code'],
            $sessionData['verifier']??'' // Use verifier key name
        );
        
        echo'<!-- Debug: Token exchange result type: ' . gettype($result) . ' -->' . PHP_EOL;
        echo'<!-- Debug: Result keys: ' . implode(', ', array_keys($result)) . ' -->' . PHP_EOL;
        
        if(isset($result['access_token'])) {
            $sessionData['access_token'] = $result['access_token'];
            pocketsmith_save_session($sessionData);
            echo'Authenticated!';
        } else {
            echo'Authentication failed. Token exchange error response:<br/>' . PHP_EOL;
            echo'<pre>' . htmlspecialchars(print_r($result, true)) . '</pre>' . PHP_EOL;
        }
    } catch(Throwable $e) {
        echo'<!-- OAuth callback error: ' . htmlspecialchars($e->getMessage()) . ' -->' . PHP_EOL;
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
echo'<!-- Debug: Line 11 -->' . PHP_EOL;

// 4. API Requests
if(!empty($action)) {
    $session = pocketsmith_load_session();
    if(empty($session['access_token']??null)) {
        // Call CURLRCT function name: pocketsmith_generate_pkc (NOT pkce)
        $pkc = pocketsmith_generate_pkc();
        $auth_state = bin2hex(random_bytes(16));
        $pkc['auth_state'] = $auth_state;
        pocketsmith_save_session($pkc);
        $url = pocketsmith_auth_url(
            $config['developer_key']??'',
            $config['redirect_uri']??'',
            $pkc['challenge'],
            $auth_state
        );
        header("Location: " . $url);
        exit;
    }
    
    // Use CURLRCT function name: pocketsmith_mcp_request (NOT mpc)
    $result = pocketsmith_mcp_request($session['access_token'], $action);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
echo'<!-- Debug: Line 12 -->' . PHP_EOL;

// 5. Default: OAuth Flow
try {
    // Call CURLRCT function name
    $pkc = pocketsmith_generate_pkc();
    $auth_state = bin2hex(random_bytes(16));
    $pkc['auth_state'] = $auth_state;
    pocketsmith_save_session($pkc);
    $url = pocketsmith_auth_url(
        $config['developer_key']??'',
        $config['redirect_uri']??'',
        $pkc['challenge'],
        $auth_state
    );
    header("Location: " . $url);
} catch(Throwable $e) {
    echo'<!-- OAuth init error: ' . htmlspecialchars($e->getMessage()) . ' -->' . PHP_EOL;
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}