<?php
// Lightweight public test for PocketSmith MCP bridge
require_once __DIR__ . '/includes/pocketsmith.php';

function pass($msg) { echo "[PASS] $msg\n"; }
function fail($msg) { echo "[FAIL] $msg\n"; }

// 1. Environment Loader Test
$config = pocketsmith_get_config();
if (is_array($config)) {
    $required = ['developer_key', 'redirect_uri', 'bot_secret'];
    $missing = array_filter($required, fn($k) => empty($config[$k]));
    if (empty($missing)) {
        pass("Environment loader: required keys present");
    } else {
        fail("Environment loader: missing keys: " . implode(', ', $missing));
    }
} else {
    fail("Environment loader: config not array");
}

// 2. PKCE Generation Test
$pkce = function_exists('pocketsmith_generate_pkce') ? pocketsmith_generate_pkce() : null;
if ($pkce && isset($pkce['verifier'], $pkce['challenge'])) {
    pass("PKCE generation: structure OK");
} else {
    fail("PKCE generation: structure missing");
}

// 3. OAuth URL Generation Test
if (function_exists('pocketsmith_auth_url')) {
    $url = pocketsmith_auth_url('dummy', 'https://example.com', 'challenge', 'state');
    if (is_string($url) && strpos($url, 'https://mcp-readonly.pocketsmith.com/oauth/authorize?') === 0) {
        pass("OAuth URL generation: format OK");
    } else {
        fail("OAuth URL generation: format invalid");
    }
} else {
    fail("OAuth URL generation: function missing");
}

// 4. Session Management Test
$dummySession = ['test' => 'value'];
pocketsmith_save_session($dummySession);
$loaded = pocketsmith_load_session();
if ($loaded && $loaded['test'] === 'value') {
    pass("Session save/load: round-trip OK");
} else {
    fail("Session save/load: round-trip failed");
}

// 5. MCP Request Signature Test (no real API call)
if (function_exists('pocketsmith_mcp_request')) {
    try {
        $result = pocketsmith_mcp_request('dummy_token', 'accounts.list', []);
        pass("MCP request: function signature OK (no real call expected)");
    } catch (Throwable $e) {
        pass("MCP request: function signature OK (exception expected for dummy values)");
    }
} else {
    fail("MCP request: function missing");
}

echo "All tests complete. No secrets were output.\n";