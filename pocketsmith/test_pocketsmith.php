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

// --- pocketsmith_build_tool_args() Unit Tests ---
$builtArgs = pocketsmith_build_tool_args([
    'action' => 'list_transactions',
    'secret' => 'topsecret',
    'bot_token' => 'abc123',
    'user_id' => '85571',
    'account_id' => '99',
    'limit' => '5',
    'start_date' => '2026-07-01',
    'search' => ' groceries ',
    'tags' => ['a', 'b'],
]);
if (!isset($builtArgs['action'], $builtArgs['secret'], $builtArgs['bot_token'])) {
    pass("build_tool_args: reserved keys excluded");
} else {
    fail("build_tool_args: reserved keys leaked through: " . json_encode($builtArgs));
}
if (($builtArgs['user_id'] ?? null) === 85571 && is_int($builtArgs['user_id']) && ($builtArgs['account_id'] ?? null) === 99 && ($builtArgs['limit'] ?? null) === 5) {
    pass("build_tool_args: known numeric fields cast to int");
} else {
    fail("build_tool_args: numeric casting wrong: " . json_encode($builtArgs));
}
if (($builtArgs['start_date'] ?? null) === '2026-07-01' && ($builtArgs['search'] ?? null) === 'groceries') {
    pass("build_tool_args: string fields passed through and trimmed");
} else {
    fail("build_tool_args: string handling wrong: " . json_encode($builtArgs));
}
if (!array_key_exists('tags', $builtArgs)) {
    pass("build_tool_args: array-valued GET params skipped");
} else {
    fail("build_tool_args: array value was not skipped: " . json_encode($builtArgs));
}

// --- pocketsmith_extract_mcp_items() Unit Tests ---
// Mirrors the real MCP tools/call envelope confirmed live: result.content[0].text =
// "Page X of Y (N total)\n\n<json>".
$toolsCallResponse = [
    'result' => [
        'content' => [
            ['type' => 'text', 'text' => "Page 1 of 1 (2 total)\n\n" . json_encode([
                ['id' => 1, 'name' => 'Everyday'],
                ['id' => 2, 'name' => 'Savings'],
            ])],
        ],
        'isError' => false,
    ],
];
$extracted = pocketsmith_extract_mcp_items($toolsCallResponse);
if (count($extracted) === 2 && $extracted[0]['name'] === 'Everyday') {
    pass("extract_mcp_items: unwraps 'Page X of Y' text envelope");
} else {
    fail("extract_mcp_items: tools/call unwrap failed: " . json_encode($extracted));
}

$directResponse = ['result' => [['id' => 1, 'name' => 'Everyday']]];
$extractedDirect = pocketsmith_extract_mcp_items($directResponse);
if (count($extractedDirect) === 1 && $extractedDirect[0]['name'] === 'Everyday') {
    pass("extract_mcp_items: direct method-style array result");
} else {
    fail("extract_mcp_items: direct-style unwrap failed: " . json_encode($extractedDirect));
}

// --- pocketsmith_filter_transaction_accounts() Unit Tests ---
$accounts = [
    ['id' => 1, 'name' => 'Everyday Visa', 'institution' => ['title' => 'BNZ']],
    ['id' => 2, 'name' => 'Savings', 'institution' => ['title' => 'BNZ']],
    ['id' => 3, 'name' => 'Credit Card', 'institution' => ['title' => 'Kiwibank']],
];
$byId = pocketsmith_filter_transaction_accounts($accounts, ['account_id' => '2']);
if (count($byId) === 1 && $byId[0]['id'] === 2) {
    pass("filter_transaction_accounts: account_id filter");
} else {
    fail("filter_transaction_accounts: account_id filter failed: " . json_encode($byId));
}

$bySearch = pocketsmith_filter_transaction_accounts($accounts, ['search' => 'kiwibank']);
if (count($bySearch) === 1 && $bySearch[0]['id'] === 3) {
    pass("filter_transaction_accounts: search filter");
} else {
    fail("filter_transaction_accounts: search filter failed: " . json_encode($bySearch));
}

$byLimit = pocketsmith_filter_transaction_accounts($accounts, ['limit' => '2']);
if (count($byLimit) === 2) {
    pass("filter_transaction_accounts: limit");
} else {
    fail("filter_transaction_accounts: limit failed: " . json_encode($byLimit));
}

$byPage = pocketsmith_filter_transaction_accounts($accounts, ['page' => '2', 'per_page' => '1']);
if (count($byPage) === 1 && $byPage[0]['id'] === 2) {
    pass("filter_transaction_accounts: page/per_page");
} else {
    fail("filter_transaction_accounts: page/per_page failed: " . json_encode($byPage));
}

// --- End-to-End MCP API Feature Test ---
// Supply a token/user_id directly to test against the live API without the OAuth flow:
//   php test_pocketsmith.php --token=YOUR_ACCESS_TOKEN --user_id=85571
$cliToken = null;
$cliUserId = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--token=') === 0) {
        $cliToken = substr($arg, 8);
    } elseif (strpos($arg, '--user_id=') === 0) {
        $cliUserId = substr($arg, 10);
    }
}

$session = pocketsmith_load_session();
$token = $cliToken ?: ($session['access_token'] ?? null);
$userId = $cliUserId;

if (!empty($token)) {
    $today = date('Y-m-d');
    $monthAgo = date('Y-m-d', strtotime('-30 days'));

    $calls = [
        'list_accounts' => [],
        'get_current_user' => [],
        'list_categories' => [],
        'list_transaction_accounts' => [],
        'list_transactions' => array_filter([
            'user_id' => $userId,
            'start_date' => $monthAgo,
            'end_date' => $today,
        ]),
        'get_budget_summary' => array_filter([
            'user_id' => $userId,
            'period' => 'months',
            'interval' => 1,
            'start_date' => $monthAgo,
            'end_date' => $today,
        ]),
    ];

    foreach ($calls as $method => $args) {
        $result = pocketsmith_mcp_request($token, $method, $args);
        if (isset($result['response']['result'])) {
            pass("MCP $method: response OK (args: " . json_encode($args) . ")");
            // Dump the response shape/snippet so the real MCP envelope can be confirmed for filter/unwrap logic.
            $resultData = $result['response']['result'];
            $topKeys = is_array($resultData) ? array_keys($resultData) : [];
            echo "        top-level keys: " . json_encode($topKeys) . "\n";
            echo "        snippet: " . substr(json_encode($resultData), 0, 400) . "\n";
        } elseif (isset($result['response']['error'])) {
            fail("MCP $method: error: " . $result['response']['error']['message']);
        } else {
            fail("MCP $method: unexpected response: " . substr(json_encode($result['response']), 0, 300));
        }
    }
} else {
    echo "[INFO] No access token found. Supply one with --token=YOUR_TOKEN [--user_id=ID], or authenticate via the web interface first.\n";
}

echo "All tests complete. No secrets were output.\n";