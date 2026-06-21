<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\service\payment\CallbackService;
use app\service\payment\LocalFundStore;
use app\service\payment\LocalOrderStore;
use app\service\payment\LocalTransferStore;
use app\service\system\DashboardDataService;
use app\service\system\JsonStoreService;

$stores = [
    'merchant_accounts',
    'merchant_auth_users',
    'merchant_api_profiles',
    'merchant_channels',
    'orders_local',
    'callback_queue_local',
    'fund_flows_local',
    'refunds_local',
    'transfers_local',
    'announcements',
];

$backups = backupJsonStores($stores);
$result = [];
$ok = false;

try {
    foreach ($stores as $store) {
        JsonStoreService::save($store, []);
    }

    $merchant = bootstrapTestMerchant('dashmetric');
    $merchantId = (int)($merchant['merchant_id'] ?? 0);

    $paidOrder = LocalOrderStore::createOrder([
        'trade_no' => 'BIZ' . date('His') . '001',
        'out_trade_no' => 'OUT' . date('His') . '001',
        'merchant_id' => $merchantId,
        'merchant_channel_id' => 11,
        'channel_code' => 'alipay',
        'subject' => 'Dashboard paid order',
        'amount' => '100.00',
        'status' => 1,
        'notify_url' => 'https://merchant.example/callback/success',
        'request_payload' => [
            '_meta' => [
                'business' => 'merchant_order',
            ],
        ],
        'notify_payload' => [],
        'created_at' => date('Y-m-d H:i:s', strtotime('-1 day 09:15:00')),
        'pay_time' => date('Y-m-d H:i:s'),
    ]);
    LocalFundStore::recordOrderSuccess($paidOrder, (object)['id' => $merchantId]);

    $pendingOrder = LocalOrderStore::createOrder([
        'trade_no' => 'BIZ' . date('His') . '002',
        'out_trade_no' => 'OUT' . date('His') . '002',
        'merchant_id' => $merchantId,
        'merchant_channel_id' => 12,
        'channel_code' => 'wxpay',
        'subject' => 'Dashboard pending order',
        'amount' => '50.00',
        'status' => 0,
        'notify_url' => 'https://merchant.example/callback/pending',
        'request_payload' => [
            '_meta' => [
                'business' => 'merchant_order',
            ],
        ],
        'notify_payload' => [],
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    CallbackService::enqueueOrder($paidOrder, (object)['id' => $merchantId, 'mch_key' => 'test-key']);
    CallbackService::enqueueOrder($pendingOrder, (object)['id' => $merchantId, 'mch_key' => 'test-key']);

    $paidOrderCallback = LocalOrderStore::findCallbackByOrderId((int)($paidOrder->id ?? 0));
    if ($paidOrderCallback) {
        LocalOrderStore::updateCallback((int)($paidOrderCallback->id ?? 0), [
            'status' => 1,
            'retry_count' => 5,
            'max_retry' => 5,
            'next_time' => date('Y-m-d H:i:s', strtotime('-5 minutes')),
            'last_error' => 'notify exhausted',
        ]);
        LocalOrderStore::updateOrder((string)$paidOrder->trade_no, [
            'notify_payload' => [
                'callback' => [
                    'status' => 'failed',
                    'response' => 'notify exhausted',
                    'runtime_exception' => true,
                    'notified_at' => date('Y-m-d H:i:s', strtotime('-5 minutes')),
                ],
            ],
        ]);
    }

    $pendingOrderCallback = LocalOrderStore::findCallbackByOrderId((int)($pendingOrder->id ?? 0));
    if ($pendingOrderCallback) {
        LocalOrderStore::updateCallback((int)($pendingOrderCallback->id ?? 0), [
            'status' => 0,
            'retry_count' => 1,
            'max_retry' => 5,
            'next_time' => date('Y-m-d H:i:s', strtotime('-2 minutes')),
            'last_error' => 'pending retry',
        ]);
    }

    LocalTransferStore::createRefund([
        'merchant_id' => $merchantId,
        'trade_no' => (string)$paidOrder->trade_no,
        'out_trade_no' => (string)$paidOrder->out_trade_no,
        'refund_no' => 'RF' . date('His') . '001',
        'out_refund_no' => 'ORF' . date('His') . '001',
        'money' => '10.00',
        'reducemoney' => '10.00',
        'status' => 0,
        'result' => 'manual_refund_pending',
        'last_error' => 'awaiting manual refund',
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    LocalTransferStore::createTransfer([
        'merchant_id' => $merchantId,
        'biz_no' => 'TR' . date('His') . '001',
        'out_biz_no' => 'OTR' . date('His') . '001',
        'type' => 'bank',
        'account' => '6222020202020202',
        'name' => 'Merchant Receiver',
        'money' => '20.00',
        'status' => 0,
        'result' => 'manual_transfer_pending',
        'last_error' => 'awaiting manual transfer',
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $admin = DashboardDataService::adminOverview();
    $merchantOverview = DashboardDataService::merchantOverview($merchantId);

    $today = date('Y-m-d');
    $todayTrend = [];
    foreach (($merchantOverview['trend'] ?? []) as $item) {
        if ((string)($item['date'] ?? '') === $today) {
            $todayTrend = $item;
            break;
        }
    }

    $result = [
        'admin' => [
            'risk_alerts' => (int)($admin['todos']['risk_alerts'] ?? -1),
            'pending_refunds' => (int)($admin['todos']['pending_refunds'] ?? -1),
            'pending_transfers' => (int)($admin['todos']['pending_transfers'] ?? -1),
            'callback_due' => (int)($admin['todos']['callback_due'] ?? -1),
            'callback_exhausted' => (int)($admin['todos']['callback_exhausted'] ?? -1),
            'payout_attention_total' => (int)($admin['payout_summary']['attention_total'] ?? -1),
            'callback_attention_total' => (int)($admin['callback_summary']['attention_total'] ?? -1),
        ],
        'merchant' => [
            'today_amount' => (string)($merchantOverview['cards']['today_amount'] ?? ''),
            'today_orders' => (int)($merchantOverview['cards']['today_orders'] ?? -1),
            'pending_orders' => (int)($merchantOverview['cards']['pending_orders'] ?? -1),
            'manual_refunds' => (int)($merchantOverview['todos']['manual_refunds'] ?? -1),
            'manual_transfers' => (int)($merchantOverview['todos']['manual_transfers'] ?? -1),
            'callback_due' => (int)($merchantOverview['todos']['callback_due'] ?? -1),
            'callback_exhausted' => (int)($merchantOverview['todos']['callback_exhausted'] ?? -1),
            'today_trend_amount' => (string)($todayTrend['amount'] ?? ''),
            'today_trend_orders' => (int)($todayTrend['orders'] ?? -1),
        ],
    ];

    $ok = $result['admin']['pending_refunds'] === 1
        && $result['admin']['pending_transfers'] === 1
        && $result['admin']['callback_due'] === 1
        && $result['admin']['callback_exhausted'] === 1
        && $result['admin']['payout_attention_total'] === 2
        && $result['admin']['callback_attention_total'] === 2
        && $result['admin']['risk_alerts'] === 4
        && $result['merchant']['today_amount'] === '100.00'
        && $result['merchant']['today_orders'] === 1
        && $result['merchant']['pending_orders'] === 1
        && $result['merchant']['manual_refunds'] === 1
        && $result['merchant']['manual_transfers'] === 1
        && $result['merchant']['callback_due'] === 1
        && $result['merchant']['callback_exhausted'] === 1
        && $result['merchant']['today_trend_amount'] === '100.00'
        && $result['merchant']['today_trend_orders'] === 1;

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    restoreJsonStores($backups);
}

exit($ok ? 0 : 1);
