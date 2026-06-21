<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\service\payment\LocalTransferStore;
use app\service\payment\OrderService;
use app\service\payment\PluginExecutorService;
use app\service\system\JsonStoreService;
use app\service\system\MerchantChannelService;
use app\service\system\PaymentMetaService;
use app\service\system\PluginCodeService;
use app\service\system\PluginService;
use app\service\system\ResourceDataService;

$stores = [
    'merchant_channels',
    'orders_local',
    'refunds_local',
    'transfers_local',
    'fund_flows_local',
    'merchant_api_profiles',
    'merchant_accounts',
    'merchant_auth_users',
    'admin_operation_logs',
];

$backups = backupJsonStores($stores);
$result = [];
$ok = false;

try {
    foreach (['merchant_channels', 'orders_local', 'refunds_local', 'transfers_local', 'fund_flows_local'] as $store) {
        JsonStoreService::save($store, []);
    }

    $merchant = bootstrapTestMerchant('batchsync');
    $merchantId = (int)$merchant['merchant_id'];
    [$methodCode, $pluginCode, $pluginConfig] = resolveUnsupportedPayoutQueryChannelDefinition();

    MerchantChannelService::saveItem($merchantId, [
        'channel_name' => 'Batch Sync Verify Channel',
        'method_code' => $methodCode,
        'plugin_code' => $pluginCode,
        'daily_limit' => '100000.00',
        'daily_count_limit' => '1000',
        'single_min_amount' => '1.00',
        'single_max_amount' => '100000.00',
        'rate' => '0.88',
        'status_code' => 1,
        'plugin_config' => $pluginConfig,
        'validate_plugin_config' => true,
    ]);

    $channelList = MerchantChannelService::all($merchantId);
    $channel = $channelList['items'][0] ?? null;
    if (!is_array($channel)) {
        throw new RuntimeException('failed to create verify channel');
    }

    $order = OrderService::createMerchantBusinessOrder($merchantId, [
        'type' => $methodCode,
        'out_trade_no' => 'BATCH' . date('His') . random_int(1000, 9999),
        'name' => 'Batch Sync Verify Order',
        'money' => '36.00',
        'clientip' => '127.0.0.1',
        'param' => 'batch-sync-verify',
        'source_protocol' => 'v2',
        'request_payload' => [
            '_meta' => [
                'business' => 'merchant_payment',
                'source_protocol' => 'v2',
            ],
        ],
    ]);

    OrderService::completeByTradeNo((string)$order->trade_no, [
        'source' => 'verify-admin-payout-batch-sync',
        'txid' => 'BATCHTX' . date('His') . random_int(1000, 9999),
        'paid_at' => date('Y-m-d H:i:s'),
    ]);

    $refundOne = 'BRF' . date('His') . random_int(1000, 9999);
    $refundTwo = 'BRF' . date('His') . random_int(1000, 9999);
    $transferOne = 'BTR' . date('His') . random_int(1000, 9999);
    $transferTwo = 'BTR' . date('His') . random_int(1000, 9999);

    foreach ([$refundOne, $refundTwo] as $refundNo) {
        LocalTransferStore::createRefund([
            'refund_no' => $refundNo,
            'out_refund_no' => 'ORF' . date('His') . random_int(1000, 9999),
            'trade_no' => (string)$order->trade_no,
            'out_trade_no' => (string)$order->out_trade_no,
            'merchant_id' => $merchantId,
            'money' => '3.00',
            'reducemoney' => '3.00',
            'status' => 0,
            'result' => 'plugin_refund_pending',
            'last_error' => 'Plugin refund pending',
            'channel_id' => (int)($channel['id'] ?? 0),
            'channel_plugin_code' => $pluginCode,
        ]);
    }

    foreach ([$transferOne, $transferTwo] as $bizNo) {
        LocalTransferStore::createTransfer([
            'biz_no' => $bizNo,
            'out_biz_no' => 'OTR' . date('His') . random_int(1000, 9999),
            'merchant_id' => $merchantId,
            'type' => $methodCode,
            'account' => 'batch@example.com',
            'name' => 'Batch Sync',
            'money' => '4.00',
            'status' => 0,
            'result' => 'plugin_transfer_pending',
            'last_error' => 'Plugin transfer pending',
            'channel_id' => (int)($channel['id'] ?? 0),
            'channel_plugin_code' => $pluginCode,
        ]);
    }

    $refundBatch = OrderService::syncPendingRefundsBatch([$refundOne, $refundTwo], 10);
    $transferBatch = OrderService::syncPendingTransfersBatch([$transferOne, $transferTwo], 10);
    $adminOrders = ResourceDataService::adminOrders();

    $refundRows = array_values(array_filter((array)($adminOrders['refunds'] ?? []), static fn(array $row): bool => in_array((string)($row['refund_no'] ?? ''), [$refundOne, $refundTwo], true)));
    $transferRows = array_values(array_filter((array)($adminOrders['transfers'] ?? []), static fn(array $row): bool => in_array((string)($row['biz_no'] ?? ''), [$transferOne, $transferTwo], true)));

    $checks = [
        'refund_batch_manualized_two' => (int)($refundBatch['manualized'] ?? 0) === 2 && (int)($refundBatch['plugin_pending_after'] ?? -1) === 0,
        'transfer_batch_manualized_two' => (int)($transferBatch['manualized'] ?? 0) === 2 && (int)($transferBatch['plugin_pending_after'] ?? -1) === 0,
        'admin_refund_rows_expose_manual_mode' => count($refundRows) === 2 && count(array_filter($refundRows, static fn(array $row): bool => ($row['mode'] ?? '') === 'manual' && ($row['result'] ?? '') === 'manual_refund_pending')) === 2,
        'admin_transfer_rows_expose_manual_mode' => count($transferRows) === 2 && count(array_filter($transferRows, static fn(array $row): bool => ($row['mode'] ?? '') === 'manual' && ($row['result'] ?? '') === 'manual_transfer_pending')) === 2,
    ];

    $result = [
        'refund_batch' => $refundBatch,
        'transfer_batch' => $transferBatch,
        'refund_rows' => $refundRows,
        'transfer_rows' => $transferRows,
        'checks' => $checks,
    ];

    $ok = !in_array(false, $checks, true);
} finally {
    restoreJsonStores($backups);
    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit($ok ? 0 : 1);

function resolveUnsupportedPayoutQueryChannelDefinition(): array
{
    $methods = PluginService::methods();
    $plugins = PluginService::plugins();

    foreach ($methods as $method) {
        if ((int)($method['status_code'] ?? 0) !== 1) {
            continue;
        }

        $methodCode = PaymentMetaService::normalizeMethodCode((string)($method['code'] ?? ''));
        if ($methodCode === '') {
            continue;
        }

        foreach ($plugins as $plugin) {
            if ((int)($plugin['status_code'] ?? 0) !== 1) {
                continue;
            }
            if (($plugin['group'] ?? 'pay') !== 'pay') {
                continue;
            }

            $pluginCode = PluginCodeService::normalize((string)($plugin['code'] ?? ''));
            if ($pluginCode === '' || !scriptPluginSupportsMethod($plugin, $methodCode)) {
                continue;
            }

            $capability = PluginExecutorService::capability($pluginCode);
            if (($capability['refund_query'] ?? false) || ($capability['transfer_query'] ?? false)) {
                continue;
            }

            return [$methodCode, $pluginCode, buildScriptPluginConfig($plugin, $methodCode)];
        }
    }

    throw new RuntimeException('no active method/plugin pair without payout query capability available');
}
