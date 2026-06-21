<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\service\payment\OrderService;
use app\service\payment\LocalTransferStore;
use app\service\payment\PluginExecutorService;
use app\service\system\JsonStoreService;
use app\service\system\MerchantChannelService;
use app\service\system\ResourceDataService;
use app\service\system\TaskService;

$stores = [
    'merchant_channels',
    'orders_local',
    'refunds_local',
    'transfers_local',
    'fund_flows_local',
    'merchant_api_profiles',
    'merchant_accounts',
    'merchant_auth_users',
    'tasks',
    'task_runs',
    'admin_operation_logs',
];

$backups = backupJsonStores($stores);
$result = [];
$ok = false;

try {
    foreach (['merchant_channels', 'orders_local', 'refunds_local', 'transfers_local', 'fund_flows_local', 'tasks', 'task_runs', 'admin_operation_logs'] as $store) {
        JsonStoreService::save($store, []);
    }

    $merchant = bootstrapTestMerchant('payoutsync');
    $merchantId = (int)$merchant['merchant_id'];
    [$methodCode, $pluginCode, $pluginConfig] = resolveUnsupportedPayoutQueryChannelDefinition();

    MerchantChannelService::saveItem($merchantId, [
        'channel_name' => 'Payout Sync Manualize Verify Channel',
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
        throw new RuntimeException('failed to create merchant channel for payout sync verification');
    }

    $order = OrderService::createMerchantBusinessOrder($merchantId, [
        'type' => $methodCode,
        'out_trade_no' => 'PSM' . date('His') . random_int(1000, 9999),
        'name' => 'Payout Sync Manualize Verify Order',
        'money' => '18.00',
        'clientip' => '127.0.0.1',
        'param' => 'payout-sync-manualize',
        'source_protocol' => 'v2',
        'request_payload' => [
            '_meta' => [
                'business' => 'merchant_payment',
                'source_protocol' => 'v2',
            ],
        ],
    ]);

    OrderService::completeByTradeNo((string)$order->trade_no, [
        'source' => 'verify-payout-sync-manualization',
        'txid' => 'PSM' . date('His') . random_int(1000, 9999),
        'paid_at' => date('Y-m-d H:i:s'),
    ]);

    $refundNoOne = 'RFM' . date('His') . random_int(1000, 9999);
    $refundNoTwo = 'RFM' . date('His') . random_int(1000, 9999);
    $transferNoOne = 'TRM' . date('His') . random_int(1000, 9999);
    $transferNoTwo = 'TRM' . date('His') . random_int(1000, 9999);

    LocalTransferStore::createRefund([
        'refund_no' => $refundNoOne,
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
    LocalTransferStore::createRefund([
        'refund_no' => $refundNoTwo,
        'out_refund_no' => 'ORF' . date('His') . random_int(1000, 9999),
        'trade_no' => (string)$order->trade_no,
        'out_trade_no' => (string)$order->out_trade_no,
        'merchant_id' => $merchantId,
        'money' => '2.00',
        'reducemoney' => '2.00',
        'status' => 0,
        'result' => 'plugin_refund_pending',
        'last_error' => 'Plugin refund pending',
        'channel_id' => (int)($channel['id'] ?? 0),
        'channel_plugin_code' => $pluginCode,
    ]);
    LocalTransferStore::createTransfer([
        'biz_no' => $transferNoOne,
        'out_biz_no' => 'OTR' . date('His') . random_int(1000, 9999),
        'merchant_id' => $merchantId,
        'type' => $methodCode,
        'account' => 'payee.one@example.com',
        'name' => 'Payout Sync One',
        'money' => '4.00',
        'status' => 0,
        'result' => 'plugin_transfer_pending',
        'last_error' => 'Plugin transfer pending',
        'channel_id' => (int)($channel['id'] ?? 0),
        'channel_plugin_code' => $pluginCode,
    ]);
    LocalTransferStore::createTransfer([
        'biz_no' => $transferNoTwo,
        'out_biz_no' => 'OTR' . date('His') . random_int(1000, 9999),
        'merchant_id' => $merchantId,
        'type' => $methodCode,
        'account' => 'payee.two@example.com',
        'name' => 'Payout Sync Two',
        'money' => '5.00',
        'status' => 0,
        'result' => 'plugin_transfer_pending',
        'last_error' => 'Plugin transfer pending',
        'channel_id' => (int)($channel['id'] ?? 0),
        'channel_plugin_code' => $pluginCode,
    ]);

    $summaryBefore = OrderService::payoutSummary();
    $refundSync = OrderService::syncPendingRefundByNo($refundNoOne);
    $transferSync = OrderService::syncPendingTransferByBizNo($transferNoOne);
    $summaryAfterSingle = OrderService::payoutSummary();

    TaskService::run('payout-sync', 'admin:verify');
    $taskLogs = TaskService::logs('payout-sync');
    $taskRun = $taskLogs['runs'][0] ?? [];
    $summaryAfterTask = OrderService::payoutSummary();
    $adminOrders = ResourceDataService::adminOrders();

    $refundRows = array_values(array_filter((array)($adminOrders['refunds'] ?? []), static fn(array $row): bool => str_starts_with((string)($row['refund_no'] ?? ''), 'RFM')));
    $transferRows = array_values(array_filter((array)($adminOrders['transfers'] ?? []), static fn(array $row): bool => str_starts_with((string)($row['biz_no'] ?? ''), 'TRM')));

    $checks = [
        'summary_before_tracks_plugin_pending' => (int)($summaryBefore['refunds']['plugin_pending'] ?? 0) === 2
            && (int)($summaryBefore['refunds']['manual_pending'] ?? 0) === 0
            && (int)($summaryBefore['transfers']['plugin_pending'] ?? 0) === 2
            && (int)($summaryBefore['transfers']['manual_pending'] ?? 0) === 0,
        'single_sync_manualizes_records' => (string)($refundSync['bucket'] ?? '') === 'manualized'
            && (string)($refundSync['result'] ?? '') === 'manual_refund_pending'
            && (string)($transferSync['bucket'] ?? '') === 'manualized'
            && (string)($transferSync['result'] ?? '') === 'manual_transfer_pending',
        'summary_after_single_sync_reflects_manual_pending' => (int)($summaryAfterSingle['refunds']['plugin_pending'] ?? 0) === 1
            && (int)($summaryAfterSingle['refunds']['manual_pending'] ?? 0) === 1
            && (int)($summaryAfterSingle['transfers']['plugin_pending'] ?? 0) === 1
            && (int)($summaryAfterSingle['transfers']['manual_pending'] ?? 0) === 1,
        'task_run_manualizes_remaining_plugin_pending' => (string)($taskRun['status'] ?? '') === 'success'
            && str_contains((string)($taskRun['result'] ?? ''), 'manualized=2')
            && str_contains((string)($taskRun['result'] ?? ''), 'refund_pending_before=1')
            && str_contains((string)($taskRun['result'] ?? ''), 'transfer_pending_before=1')
            && str_contains((string)($taskRun['result'] ?? ''), 'refund_pending_after=0')
            && str_contains((string)($taskRun['result'] ?? ''), 'transfer_pending_after=0'),
        'summary_after_task_clears_plugin_pending' => (int)($summaryAfterTask['refunds']['plugin_pending'] ?? 0) === 0
            && (int)($summaryAfterTask['refunds']['manual_pending'] ?? 0) === 2
            && (int)($summaryAfterTask['transfers']['plugin_pending'] ?? 0) === 0
            && (int)($summaryAfterTask['transfers']['manual_pending'] ?? 0) === 2,
        'admin_payload_exposes_summary_and_manual_labels' => (int)(($adminOrders['payout_summary']['refunds']['manual_pending'] ?? -1)) === 2
            && (int)(($adminOrders['payout_summary']['transfers']['manual_pending'] ?? -1)) === 2
            && in_array('人工待退款', array_values(array_filter(array_map(static fn(array $row): string => (string)($row['status'] ?? ''), $refundRows))), true)
            && in_array('人工待代付', array_values(array_filter(array_map(static fn(array $row): string => (string)($row['status'] ?? ''), $transferRows))), true),
    ];

    $result = [
        'merchant_id' => $merchantId,
        'plugin_code' => $pluginCode,
        'method_code' => $methodCode,
        'summary_before' => $summaryBefore,
        'refund_sync' => $refundSync,
        'transfer_sync' => $transferSync,
        'summary_after_single_sync' => $summaryAfterSingle,
        'task_run' => $taskRun,
        'summary_after_task' => $summaryAfterTask,
        'admin_payout_summary' => $adminOrders['payout_summary'] ?? [],
        'refund_rows' => $refundRows,
        'transfer_rows' => $transferRows,
        'checks' => $checks,
    ];

    $ok = !in_array(false, $checks, true);
} catch (Throwable $exception) {
    $result = [
        'error' => $exception->getMessage(),
        'exception' => get_class($exception),
    ];
} finally {
    restoreJsonStores($backups);
    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit($ok ? 0 : 1);

function resolveUnsupportedPayoutQueryChannelDefinition(): array
{
    $methods = \app\service\system\PluginService::methods();
    $plugins = \app\service\system\PluginService::plugins();

    foreach ($methods as $method) {
        if ((int)($method['status_code'] ?? 0) !== 1) {
            continue;
        }

        $methodCode = \app\service\system\PaymentMetaService::normalizeMethodCode((string)($method['code'] ?? ''));
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

            $pluginCode = \app\service\system\PluginCodeService::normalize((string)($plugin['code'] ?? ''));
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
