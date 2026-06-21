<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\service\OrderProcessService;
use app\service\payment\CallbackTrustService;
use app\service\payment\LocalOrderStore;
use app\service\payment\OrderService;
use app\service\system\JsonStoreService;

$stores = [
    'orders_local',
    'fund_flows_local',
    'callback_queue_local',
    'plugin_notify_logs',
    'merchant_auth_users',
    'merchant_accounts',
    'merchant_api_profiles',
];
$backup = [];
foreach ($stores as $store) {
    $backup[$store] = JsonStoreService::load($store, []);
}

$result = [
    'ok' => false,
    'checks' => [],
    'failed' => [],
    'details' => [],
];

try {
    JsonStoreService::save('orders_local', []);
    JsonStoreService::save('fund_flows_local', []);
    JsonStoreService::save('callback_queue_local', []);
    JsonStoreService::save('plugin_notify_logs', []);

    $merchant = bootstrapTestMerchant('trustgate');

    $order = LocalOrderStore::createOrder([
        'trade_no' => 'TRUSTGATE' . date('YmdHis'),
        'out_trade_no' => 'TRUST-GATE-OUT-001',
        'merchant_id' => (int)$merchant['merchant_id'],
        'merchant_channel_id' => 88,
        'channel_code' => 'alipay',
        'channel_category' => 1,
        'subject' => 'Trust Gate Test',
        'amount' => '12.34',
        'payable_amount' => '12.34',
        'status' => OrderService::STATUS_PENDING,
        'notify_url' => 'https://merchant.test/callback',
        'return_url' => 'https://merchant.test/return',
        'client_ip' => '127.0.0.1',
        'param' => '',
        'expire_time' => date('Y-m-d H:i:s', time() + 600),
        'request_payload' => [
            '_meta' => ['source_protocol' => 'v1'],
            '_legacy_channel' => [
                'merchant_channel_id' => 88,
                'channel_code' => 'alipay',
                'plugin_code' => 'alipay',
                'plugin_kind' => 'payment',
                'config' => [
                    'plugin_code' => 'alipay',
                    'method_code' => 'alipay',
                ],
            ],
        ],
        'notify_payload' => [],
        'payment_address' => '',
    ]);

    $channel = [
        'id' => 88,
        'merchant_id' => (int)$merchant['merchant_id'],
        'plugin' => 'alipay',
        'plugin_code' => 'alipay',
        'channel_code' => 'alipay',
        'type' => 'alipay',
    ];
    $legacyOrder = [
        'trade_no' => (string)$order->trade_no,
        'merchant_id' => (int)$merchant['merchant_id'],
        'uid' => (int)$merchant['merchant_id'],
        'typename' => 'alipay',
        'realmoney' => '12.34',
    ];

    $service = new OrderProcessService($channel, $legacyOrder);

    $blockedMessage = '';
    try {
        $service->processNotify('ALI-RAW-001', 'buyer-raw');
    } catch (Throwable $exception) {
        $blockedMessage = $exception->getMessage();
    }

    $trustedOrder = CallbackTrustService::beginTrusted([
        'scope' => 'notify',
        'action' => 'notify',
        'plugin_code' => 'alipay',
        'channel_id' => 88,
        'merchant_id' => (int)$merchant['merchant_id'],
        'source' => 'test',
        'verification' => 'unit-test',
    ], static function () use ($service) {
        return $service->processNotify('ALI-TRUST-001', 'buyer-trusted', 'bill-001', 'merchant-bill-001', '2026-06-21 12:34:56');
    });

    $reloaded = OrderService::findByTradeNo((string)$order->trade_no);
    $notifyPayload = is_array($reloaded->notify_payload ?? null) ? $reloaded->notify_payload : [];
    $callbackTrust = is_array($notifyPayload['callback_trust'] ?? null) ? $notifyPayload['callback_trust'] : [];

    $result['checks'] = [
        'untrusted_blocked' => str_contains($blockedMessage, 'Untrusted order notify blocked'),
        'trusted_completed' => (int)($trustedOrder->status ?? 0) === OrderService::STATUS_SUCCESS,
        'txid_saved' => (string)($reloaded->txid ?? '') === 'ALI-TRUST-001',
        'callback_trust_saved' => (string)($callbackTrust['verification'] ?? '') === 'unit-test',
        'callback_queue_created' => count(JsonStoreService::load('callback_queue_local', [])) === 1,
    ];
    $result['failed'] = array_keys(array_filter($result['checks'], static fn(bool $ok): bool => !$ok));
    $result['ok'] = $result['failed'] === [];
    $result['details'] = [
        'blocked_message' => $blockedMessage,
        'trade_no' => (string)$order->trade_no,
        'order_status' => (int)($reloaded->status ?? 0),
        'notify_payload' => $notifyPayload,
        'callback_queue' => JsonStoreService::load('callback_queue_local', []),
    ];
} finally {
    foreach ($stores as $store) {
        JsonStoreService::save($store, $backup[$store] ?? []);
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
