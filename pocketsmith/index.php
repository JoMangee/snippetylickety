<?php
require_once 'vendor/autoload.php';

use PocketSmith\PocketSmith;
use PocketSmith\APIClient;
use PocketSmith\OAuth2Client;

require_once 'helpers.php';

/**
 * Generate PKC (Proof Key for Code Exchange) parameters
 * @return array PKC parameters with code_verifier and code_challenge
 */
function pocketsmith_generate_pkc() {
    $code_verifier = bin2hex(random_bytes(32));
    $code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
    
    return [
        'code_verifier' => $code_verifier,
        'code_challenge' => $code_challenge,
        'code_challenge_method' => 'S256'
    ];
}

/**
 * Handle MCP request to PocketSmith API
 * @param string $endpoint API endpoint
 * @param string $method HTTP method
 * @param array $data Request data
 * @return array API response
 */
function pocketsmith_mcp_request($endpoint, $method = 'GET', $data = []) {
    $client = new APIClient();
    
    $endpoint = '/api/v1/' . trim($endpoint, '/');
    
    switch ($method) {
        case 'GET':
            return $client->get($endpoint);
        case 'POST':
            return $client->post($endpoint, $data);
        case 'PUT':
            $endpoint .= '?id=' . $data['id'];
            unset($data['id']);
            return $client->put($endpoint, $data);
        case 'DELETE':
            $endpoint .= '?id=' . $data['id'];
            return $client->delete($endpoint);
        default:
            throw new InvalidArgumentException("Unsupported HTTP method: {$method}");
    }
}

/**
 * Initialize OAuth2 flow
 * @return array OAuth URL and state
 */
function pocketsmith_oauth_init() {
    $client = new PocketSmith();
    
    $pkc = pocketsmith_generate_pkc();
    $state = bin2hex(random_bytes(16));
    $_SESSION['pocketsmith_pkc_verifier'] = $pkc['code_verifier'];
    $_SESSION['pocketsmith_state'] = $state;
    
    $authorization_url = $client->getAuthorizationUrl([
        'pkc' => $pkc,
        'state' => $state,
        'scope' => 'read write'
    ]);
    
    return ['url' => $authorization_url, 'state' => $state];
}

/**
 * Complete OAuth2 flow and get access token
 * @param string $code Authorization code
 * @return array Access token information
 */
function pocketsmith_oauth_complete($code) {
    $pkc_verifier = $_SESSION['pocketsmith_pkc_verifier'];
    $state = $_SESSION['pocketsmith_state'];
    
    if (isset($_GET['state']) && $_GET['state'] !== $state) {
        throw new InvalidArgumentException("Invalid state parameter");
    }
    
    $client = new OAuth2Client();
    return $client->getAccessToken([
        'code' => $code,
        'grant_type' => 'authorization_code',
        'pkc' => ['pkc_verifier' => $pkc_verifier],
        'redirect_uri' => $_GET['redirect_uri'] ?? null
    ]);
}

/**
 * Fetch account list from PocketSmith
 * @return array List of accounts
 */
function pocketsmith_get_accounts() {
    return pocketsmith_mcp_request('accounts', 'GET');
}

/**
 * Fetch account transactions
 * @param int $account_id Account ID
 * @return array List of transactions
 */
function pocketsmith_get_account_transactions($account_id) {
    return pocketsmith_mcp_request("accounts/{$account_id}/transactions", 'GET');
}

/**
 * Fetch account balance
 * @param int $account_id Account ID
 * @return array Balance information
 */
function pocketsmith_get_account_balance($account_id) {
    return pocketsmith_mcp_request("accounts/{$account_id}/balance", 'GET');
}

/**
 * Search transactions
 * @param array $filters Search filters
 * @return array Search results
 */
function pocketsmith_search_transactions($filters = []) {
    return pocketsmith_mcp_request('transactions/search', 'POST', $filters);
}

/**
 * Create a transaction
 * @param array $data Transaction data
 * @return array Created transaction
 */
function pocketsmith_create_transaction($data) {
    $data['type'] = $data['type'] ?? 'transaction';
    return pocketsmith_mcp_request('transactions', 'POST', $data);
}

/**
 * Create a budget
 * @param array $data Budget data
 * @return array Created budget
 */
function pocketsmith_create_budget($data) {
    $data['type'] = $data['type'] ?? 'budget';
    return pocketsmith_mcp_request('budgets', 'POST', $data);
}

/**
 * Get transaction categories
 * @return array List of categories
 */
function pocketsmith_get_categories() {
    return pocketsmith_mcp_request('categories', 'GET');
}

/**
 * Update transaction
 * @param int $transaction_id Transaction ID
 * @param array $data Updated data
 * @return array Updated transaction
 */
function pocketsmith_update_transaction($transaction_id, $data) {
    return pocketsmith_mcp_request("transactions/{$transaction_id}", 'PUT', array_merge(['id' => $transaction_id], $data));
}

/**
 * Delete transaction
 * @param int $transaction_id Transaction ID
 * @return bool Success status
 */
function pocketsmith_delete_transaction($transaction_id) {
    try {
        pocketsmith_mcp_request("transactions/{$transaction_id}", 'DELETE', ['id' => $transaction_id]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get user information
 * @return array User data
 */
function pocketsmith_get_user() {
    return pocketsmith_mcp_request('user', 'GET');
}

/**
 * Get account summary
 * @param int $account_id Account ID
 * @return array Account summary
 */
function pocketsmith_get_account_summary($account_id) {
    return pocketsmith_mcp_request("accounts/{$account_id}/summary", 'GET');
}

/**
 * Get transaction reports
 * @param array $options Report options
 * @return array Report data
 */
function pocketsmith_get_reports($options = []) {
    return pocketsmith_mcp_request('reports', 'POST', $options);
}

/**
 * Get budget reports
 * @param int $budget_id Budget ID
 * @return array Budget report data
 */
function pocketsmith_get_budget_report($budget_id) {
    return pocketsmith_mcp_request("budgets/{$budget_id}/report", 'GET');
}

/**
 * Get transaction trends
 * @param array $options Trend options
 * @return array Trend data
 */
function pocketsmith_get_trends($options = []) {
    return pocketsmith_mcp_request('trends', 'POST', $options);
}

/**
 * Get alerts
 * @return array List of alerts
 */
function pocketsmith_get_alerts() {
    return pocketsmith_mcp_request('alerts', 'GET');
}

/**
 * Create alert
 * @param array $data Alert data
 * @return array Created alert
 */
function pocketsmith_create_alert($data) {
    return pocketsmith_mcp_request('alerts', 'POST', $data);
}

/**
 * Delete alert
 * @param int $alert_id Alert ID
 * @return bool Success status
 */
function pocketsmith_delete_alert($alert_id) {
    try {
        pocketsmith_mcp_request("alerts/{$alert_id}", 'DELETE', ['id' => $alert_id]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get planning data
 * @param array $options Planning options
 * @return array Planning data
 */
function pocketsmith_get_planning($options = []) {
    return pocketsmith_mcp_request('planning', 'POST', $options);
}

/**
 * Get forecasts
 * @param int $account_id Account ID or null for all
 * @return array Forecast data
 */
function pocketsmith_get_forecasts($account_id = null) {
    if ($account_id) {
        return pocketsmith_mcp_request("accounts/{$account_id}/forecasts", 'GET');
    }
    return pocketsmith_mcp_request('forecasts', 'GET');
}

/**
 * Get investment accounts
 * @return array List of investment accounts
 */
function pocketsmith_get_investment_accounts() {
    return pocketsmith_mcp_request('investment-accounts', 'GET');
}

/**
 * Get investment transactions
 * @param int $account_id Account ID
 * @return array Investment transactions
 */
function pocketsmith_get_investment_transactions($account_id) {
    return pocketsmith_mcp_request("investment-accounts/{$account_id}/transactions", 'GET');
}

/**
 * Synchronize accounts
 * @return array Sync status
 */
function pocketsmith_synchronize_accounts() {
    return pocketsmith_mcp_request('accounts/sync', 'POST');
}

/**
 * Synchronize transactions
 * @param int $account_id Account ID
 * @return array Sync status
 */
function pocketsmith_synchronize_transactions($account_id) {
    return pocketsmith_mcp_request("accounts/{$account_id}/transactions/sync", 'POST');
}

/**
 * Get notifications
 * @return array List of notifications
 */
function pocketsmith_get_notifications() {
    return pocketsmith_mcp_request('notifications', 'GET');
}

/**
 * Mark notification as read
 * @param int $notification_id Notification ID
 * @return bool Success status
 */
function pocketsmith_mark_notification_read($notification_id) {
    return pocketsmith_mcp_request("notifications/{$notification_id}/read", 'POST');
}

/**
 * Get settings
 * @return array User settings
 */
function pocketsmith_get_settings() {
    return pocketsmith_mcp_request('settings', 'GET');
}

/**
 * Update settings
 * @param array $data Updated settings
 * @return array Updated settings
 */
function pocketsmith_update_settings($data) {
    return pocketsmith_mcp_request('settings', 'PUT', $data);
}

/**
 * Get currency rates
 * @param string $base_base Base currency
 * @param array $currencies Target currencies
 * @return array Currency rates
 */
function pocketsmith_get_currency_rates($base_currency = 'USD', $currencies = []) {
    return pocketsmith_mcp_request('currency-rates', 'GET', [
        'base_currency' => $base_currency,
        'currencies' => $currencies
    ]);
}

/**
 * Get connected accounts
 * @return array List of connected accounts
 */
function pocketsmith_get_connected_accounts() {
    return pocketsmith_mcp_request('connected-accounts', 'GET');
}

/**
 * Reconnect account
 * @param int $account_id Account ID
 * @return array Reconnection status
 */
function pocketsmith_reconnect_account($account_id) {
    return pocketsmith_mcp_request("connected-accounts/{$account_id}/reconnect", 'POST');
}

/**
 * Get webhooks
 * @return array List of webhooks
 */
function pocketsmith_get_webhooks() {
    return pocketsmith_mcp_request('webhooks', 'GET');
}

/**
 * Create webhook
 * @param array $data Webhook data
 * @return array Created webhook
 */
function pocketsmith_create_webhook($data) {
    return pocketsmith_mcp_request('webhooks', 'POST', $data);
}

/**
 * Delete webhook
 * @param int $webhook_id Webhook ID
 * @return bool Success status
 */
function pocketsmith_delete_webhook($webhook_id) {
    try {
        pocketsmith_mcp_request("webhooks/{$webhook_id}", 'DELETE', ['id' => $webhook_id]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Example usage for testing
if (!empty($_REQUEST['test']) && $_REQUEST['test'] === '1') {
    header('Content-Type: application/json');
    
    // Test PKC generation
    $pkc = pocketsmith_generate_pkc();
    echo json_encode(['pkc_generated' => true, 'code_verifier_preview' => substr($pkc['code_verifier'], 0, 8)]);
    exit;
}

echo "PocketSmith MCP Library loaded successfully";