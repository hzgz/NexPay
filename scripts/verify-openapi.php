<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\service\system\ConfigService;

$stores = [
    'merchant_accounts',
    'merchant_auth_users',
    'merchant_api_profiles',
    'merchant_channels',
    'orders_local',
    'fund_flows_local',
    'transfers_local',
];

$backups = backupJsonStores($stores);
$baseUrl = rtrim($argv[1] ?? ConfigService::gatewayBaseUrl(), '/');
$result = [];
$ok = false;

function getJson(string $url): array
{
    $result = @file_get_contents($url);
    if ($result === false) {
        throw new RuntimeException('Request failed: ' . $url);
    }

    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response: ' . $url . PHP_EOL . $result);
    }

    return $decoded;
}

function postForm(string $url, array $payload): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 10,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($payload),
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        throw new RuntimeException('Request failed: ' . $url);
    }

    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response: ' . $url . PHP_EOL . $result);
    }

    return $decoded;
}

function isSuccessResponse(array $payload): bool
{
    $code = $payload['code'] ?? null;
    if ($code === 0 || $code === 1 || $code === '0' || $code === '1') {
        return true;
    }

    $status = strtolower(trim((string)($payload['status'] ?? '')));
    return in_array($status, ['success', 'ok'], true);
}

try {
    $merchant = bootstrapTestMerchant('openapi');
    $merchantId = (int)$merchant['merchant_id'];
    $userId = (int)$merchant['user_id'];
    [$merchantInfo, $pid, $privateKey] = ensureScriptMerchantApiCredentials($merchantId, $userId);
    $legacyPid = '1000001';
    $md5Key = trim((string)($merchantInfo['mch_key'] ?? ''));

    $notifyUrl = $baseUrl . '/callback/success';
    $outTradeNo = 'VERIFY' . date('YmdHis');
    $transferBizNo = 'BIZ' . date('YmdHis');

    $v1Query = getJson($baseUrl . '/api.php?act=query&pid=' . rawurlencode($pid) . '&key=' . rawurlencode($md5Key) . '&page=1&limit=2');
    $v1LegacyQuery = getJson($baseUrl . '/api.php?act=query&pid=' . rawurlencode($legacyPid) . '&key=' . rawurlencode($md5Key) . '&page=1&limit=2');
    $v2MerchantInfo = postForm($baseUrl . '/api/merchant/info', signedScriptV2Payload([], $pid, $privateKey));
    $v2LegacyMerchantInfo = postForm($baseUrl . '/api/merchant/info', signedScriptV2Payload([], $legacyPid, $privateKey));

    $create = postForm($baseUrl . '/api/pay/create', signedScriptV2Payload([
        'type' => 'wechat',
        'out_trade_no' => $outTradeNo,
        'name' => 'OpenAPI create verification order',
        'money' => '10.00',
        'notify_url' => $notifyUrl,
        'return_url' => $notifyUrl,
        'clientip' => '127.0.0.1',
        'param' => 'verify-openapi',
    ], $pid, $privateKey));

    $tradeNo = (string)($create['trade_no'] ?? '');

    $result = [
        'merchant_id' => $merchantId,
        'base_url' => $baseUrl,
        'pid' => $pid,
        'v1_query' => $v1Query,
        'v1_legacy_query' => $v1LegacyQuery,
        'v2_merchant_info' => $v2MerchantInfo,
        'v2_legacy_merchant_info' => $v2LegacyMerchantInfo,
        'create' => $create,
        'merchant_orders' => postForm($baseUrl . '/api/merchant/orders', signedScriptV2Payload([
            'page' => 1,
            'limit' => 5,
        ], $pid, $privateKey)),
        'transfer_balance' => postForm($baseUrl . '/api/transfer/balance', signedScriptV2Payload([], $pid, $privateKey)),
        'transfer_submit' => postForm($baseUrl . '/api/transfer/submit', signedScriptV2Payload([
            'type' => 'alipay',
            'account' => 'demo@example.com',
            'name' => 'OpenAPI transfer receiver',
            'money' => '1.00',
            'out_biz_no' => $transferBizNo,
        ], $pid, $privateKey)),
        'transfer_query' => postForm($baseUrl . '/api/transfer/query', signedScriptV2Payload([
            'out_biz_no' => $transferBizNo,
        ], $pid, $privateKey)),
    ];

    if ($tradeNo !== '') {
        $result['query'] = postForm($baseUrl . '/api/pay/query', signedScriptV2Payload([
            'trade_no' => $tradeNo,
        ], $pid, $privateKey));
    }

    $contractChecks = [
        'v1_pid_id_success' => (string)($v1Query['pid'] ?? '') === $pid,
        'v1_legacy_pid_rejected' => (string)($v1LegacyQuery['pid'] ?? '') !== $pid,
        'v2_pid_id_success' => (string)($v2MerchantInfo['pid'] ?? '') === $pid,
        'v2_legacy_pid_rejected' => (string)($v2LegacyMerchantInfo['pid'] ?? '') !== $pid,
    ];

    $runtimeChecks = [
        'merchant_orders_ok' => isSuccessResponse($result['merchant_orders']),
        'transfer_balance_ok' => isSuccessResponse($result['transfer_balance']),
        'transfer_submit_ok' => isSuccessResponse($result['transfer_submit'])
            || str_contains(strtolower((string)($result['transfer_submit']['msg'] ?? '')), 'insufficient balance'),
        'transfer_query_ok' => isSuccessResponse($result['transfer_query'])
            || (
                str_contains(strtolower((string)($result['transfer_submit']['msg'] ?? '')), 'insufficient balance')
                && (int)($result['transfer_query']['code'] ?? 0) === 404
            ),
        'create_ok' => $tradeNo !== '' || (int)($create['code'] ?? 0) === 404,
    ];

    $result['checks'] = $contractChecks + $runtimeChecks;
    $result['ok'] = !in_array(false, $result['checks'], true);
    $ok = (bool)$result['ok'];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    restoreJsonStores($backups);
}

exit($ok ? 0 : 1);
