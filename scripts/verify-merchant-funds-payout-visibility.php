<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\service\payment\LocalOrderStore;
use app\service\payment\LocalTransferStore;
use app\service\system\JsonStoreService;
use app\service\system\ResourceDataService;

$stores = [
    'merchant_accounts',
    'merchant_auth_users',
    'merchant_api_profiles',
    'orders_local',
    'refunds_local',
    'transfers_local',
    'fund_flows_local',
];

$backups = backupJsonStores($stores);
$result = [];
$ok = false;

try {
    foreach ($stores as $store) {
        JsonStoreService::save($store, []);
    }

    $merchant = bootstrapTestMerchant('fundspayout');
    $merchantId = (int)($merchant['merchant_id'] ?? 0);

    $order = LocalOrderStore::createOrder([
        'trade_no' => 'BIZ' . date('His') . '101',
        'out_trade_no' => 'OUT' . date('His') . '101',
        'merchant_id' => $merchantId,
        'merchant_channel_id' => 21,
        'channel_code' => 'alipay',
        'subject' => 'Payout visibility order',
        'amount' => '88.00',
        'status' => 1,
        'request_payload' => [
            '_meta' => [
                'business' => 'merchant_order',
            ],
        ],
        'created_at' => date('Y-m-d H:i:s'),
        'pay_time' => date('Y-m-d H:i:s'),
    ]);

    LocalTransferStore::createRefund([
        'merchant_id' => $merchantId,
        'trade_no' => (string)$order->trade_no,
        'out_trade_no' => (string)$order->out_trade_no,
        'refund_no' => 'RF' . date('His') . '101',
        'out_refund_no' => 'ORF' . date('His') . '101',
        'money' => '8.00',
        'reducemoney' => '8.00',
        'status' => 0,
        'result' => 'manual_refund_pending',
        'last_error' => 'manual refund required',
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    LocalTransferStore::createTransfer([
        'merchant_id' => $merchantId,
        'biz_no' => 'TR' . date('His') . '101',
        'out_biz_no' => 'OTR' . date('His') . '101',
        'type' => 'bank',
        'account' => '6222020202020202',
        'name' => 'Receiver One',
        'money' => '16.00',
        'status' => 0,
        'result' => 'plugin_transfer_pending',
        'last_error' => 'plugin syncing',
        'channel_plugin_code' => 'mockpay',
        'created_at' => date('Y-m-d H:i:s', strtotime('-1 minute')),
    ]);

    $funds = ResourceDataService::merchantFunds($merchantId);
    $pending = is_array($funds['pending_payouts'] ?? null) ? $funds['pending_payouts'] : [];
    $summary = is_array($funds['payout_summary'] ?? null) ? $funds['payout_summary'] : [];

    $refundRow = null;
    $transferRow = null;
    foreach ($pending as $item) {
        if (($item['category'] ?? '') === 'refund') {
            $refundRow = $item;
        }
        if (($item['category'] ?? '') === 'transfer') {
            $transferRow = $item;
        }
    }

    $result = [
        'summary' => [
            'refund_manual_pending' => (int)($summary['refunds']['manual_pending'] ?? -1),
            'transfer_plugin_pending' => (int)($summary['transfers']['plugin_pending'] ?? -1),
        ],
        'pending_count' => count($pending),
        'refund_row' => [
            'mode' => (string)($refundRow['mode'] ?? ''),
            'mode_label' => (string)($refundRow['mode_label'] ?? ''),
            'status' => (string)($refundRow['status'] ?? ''),
            'errmsg' => (string)($refundRow['errmsg'] ?? ''),
        ],
        'transfer_row' => [
            'mode' => (string)($transferRow['mode'] ?? ''),
            'mode_label' => (string)($transferRow['mode_label'] ?? ''),
            'status' => (string)($transferRow['status'] ?? ''),
            'plugin' => (string)($transferRow['channel_plugin_code'] ?? ''),
        ],
    ];

    $ok = $result['summary']['refund_manual_pending'] === 1
        && $result['summary']['transfer_plugin_pending'] === 1
        && $result['pending_count'] === 2
        && $result['refund_row']['mode'] === 'manual'
        && $result['refund_row']['mode_label'] === '人工处理'
        && $result['transfer_row']['mode'] === 'auto'
        && $result['transfer_row']['mode_label'] === '自动同步'
        && $result['transfer_row']['plugin'] === 'mockpay';

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    restoreJsonStores($backups);
}

exit($ok ? 0 : 1);
