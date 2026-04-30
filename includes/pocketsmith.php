<?php
declare(strict_types=1);

/**
* PocketSmith MCP Bridge Helpers (OAuth 2.0 + PKCE)
*/

function pocketsmith_generate_pkce(): array {
    $verifier = bin2hex(random_bytes(32));
    $challenge = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(hash('sha256', $verifier, true)));
    return ['verifier' => $verifier, 'challenge' => $challenge];
}

function pocketsmith_auth_url(string $clientId, string $redirectUri, string $challenge): string {
    return 'https://mcp-readonly.pocketsmith.com/oauth/authorize?' . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'mode' => 'readonly'
    ]);
}

function pocketsmith_exchange_token(string $clientId, string $redirectUri, string $code, string $verifier): array {
    $params = [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'code_verifier' => $verifier
    ];

    $ch = curl_init('https://mcp-readonly.pocketsmith.com/oauth/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

    $response = curl_exec($ch);
    $decoded = json_decode((string)$response, true);
    curl_close($ch);

    if (!isset($decoded['access_token'])) {
        return ['ok' => false, 'error' => 'Token exchange failed', 'raw' => $decoded];
    }

    return ['ok' => true, 'session' => $decoded];
}

function pocketsmith_mcp_request(string $token, string $action): array {
    $url = 'https://mcp-readonly.pocketsmith.com/mcp';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Accept: application/json"]);

    $response = curl_exec($ch);
    return json_decode((string)$response, true) ?? ['ok' => false, 'error' => 'Invalid response'];
}

function pocketsmith_save_session(array $session): void {
    file_put_contents(__DIR__ . '/../data/pocketsmith_session.json', json_encode($session));
}

function pocketsmith_load_session(): ?array {
    $path = __DIR__ . '/../data/pocketsmith_session.json';
    return file_exists($path) ? json_decode(file_get_contents($path), true) : null;
}
