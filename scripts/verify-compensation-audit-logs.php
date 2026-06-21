<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\service\payment\LocalFundStore;
use app\service\payment\LocalTransferStore;
use app\service\payment\OrderService;
use app\service\system\JsonStoreService;
use app\service\system\ManualRefundService;
use app\service\system\ManualTransferService;
use app\service\system\MerchantChannelService;
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
    'merchant_operation_logs',
    'admin_operation_logs',
];

$backups = backupJsonStores($stores);
$result = [];
$ok = false;

try {
    foreach ($stores as $store) {
        if (in_array($store, ['merchant_api_profiles', 'merchant_accounts', 'merchant_auth_users'], true)) {
            continue;
        }

        JsonStoreService::save($store, []);
    }

    $merchant = bootstrapTestMerchant('auditlog');
    $merchantId = (int)$merchant['merchant_id'];
    [$methodCode, $pluginCode, $pluginConfig] = resolveUnsupportedPayoutQueryChannelDefinition();

    MerchantChannelService::saveItem($merchantId, [
        'channel_name' => 'Audit Verify Channel',
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
        'out_trade_no' => 'AUD' . date('His') . random_int(1000, 9999),
        'name' => 'Audit Verify Order',
        'money' => '50.00',
        'clientip' => '127.0.0.1',
        'param' => 'audit-log-verify',
        'source_protocol' => 'v2',
        'request_payload' => [
            '_meta' => [
                'business' => 'merchant_payment',
                'source_protocol' => 'v2',
            ],
        ],
    ]);

    OrderService::completeByTradeNo((string)$order->trade_no, [
        'source' => 'verify-compensation-audit-logs',
        'txid' => 'AUDTX' . date('His') . random_int(1000, 9999),
        'paid_at' => date('Y-m-d H:i:s'),
    ]);

    LocalFundStore::credit(
        $merchantId,
        '100.00',
        'Audit verify seed',
        'audit_seed',
        'AUDIT-SEED',
        date('Y-m-d H:i:s'),
        []
    );

    $singleRefundNo = 'ARF' . date('His') . random_int(1000, 9999);
    $batchRefundNo = 'BRF' . date('His') . random_int(1000, 9999);
    $manualRefundNo = 'MRF' . date('His') . random_int(1000, 9999);
    $singleTransferNo = 'ATR' . date('His') . random_int(1000, 9999);
    $batchTransferNo = 'BTR' . date('His') . random_int(1000, 9999);
    $manualApproveNo = 'MTA' . date('His') . random_int(1000, 9999);
    $manualRejectNo = 'MTR' . date('His') . random_int(1000, 9999);

    foreach ([$singleRefundNo, $batchRefundNo] as $refundNo) {
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

    LocalTransferStore::createRefund([
        'refund_no' => $manualRefundNo,
        'out_refund_no' => 'ORM' . date('His') . random_int(1000, 9999),
        'trade_no' => (string)$order->trade_no,
        'out_trade_no' => (string)$order->out_trade_no,
        'merchant_id' => $merchantId,
        'money' => '2.00',
        'reducemoney' => '2.00',
        'status' => 0,
        'result' => 'manual_refund_pending',
        'last_error' => 'Manual refund review required',
        'channel_id' => (int)($channel['id'] ?? 0),
        'channel_plugin_code' => $pluginCode,
    ]);

    foreach ([$singleTransferNo, $batchTransferNo] as $bizNo) {
        LocalTransferStore::createTransfer([
            'biz_no' => $bizNo,
            'out_biz_no' => 'OTR' . date('His') . random_int(1000, 9999),
            'merchant_id' => $merchantId,
            'type' => $methodCode,
            'account' => 'audit@example.com',
            'name' => 'Audit Verify',
            'money' => '4.00',
            'status' => 0,
            'result' => 'plugin_transfer_pending',
            'last_error' => 'Plugin transfer pending',
            'channel_id' => (int)($channel['id'] ?? 0),
            'channel_plugin_code' => $pluginCode,
        ]);
    }

    LocalTransferStore::createTransfer([
        'biz_no' => $manualApproveNo,
        'out_biz_no' => 'OMA' . date('His') . random_int(1000, 9999),
        'merchant_id' => $merchantId,
        'type' => $methodCode,
        'account' => 'approve@example.com',
        'name' => 'Audit Approve',
        'money' => '5.00',
        'status' => 0,
        'result' => 'manual_transfer_pending',
        'last_error' => 'Manual transfer review required',
        'channel_id' => (int)($channel['id'] ?? 0),
        'channel_plugin_code' => $pluginCode,
    ]);

    LocalTransferStore::createTransfer([
        'biz_no' => $manualRejectNo,
        'out_biz_no' => 'OMR' . date('His') . random_int(1000, 9999),
        'merchant_id' => $merchantId,
        'type' => $methodCode,
        'account' => 'reject@example.com',
        'name' => 'Audit Reject',
        'money' => '6.00',
        'status' => 0,
        'result' => 'manual_transfer_pending',
        'last_error' => 'Manual transfer review required',
        'channel_id' => (int)($channel['id'] ?? 0),
        'channel_plugin_code' => $pluginCode,
    ]);

    $singleRefund = OrderService::syncPendingRefundByNo($singleRefundNo, 'audit-admin');
    $singleTransfer = OrderService::syncPendingTransferByBizNo($singleTransferNo, 'audit-admin');
    $batchRefund = OrderService::syncPendingRefundsBatch([$batchRefundNo], 10, 'audit-admin');
    $batchTransfer = OrderService::syncPendingTransfersBatch([$batchTransferNo], 10, 'audit-admin');
    $manualRefund = ManualRefundService::confirm($manualRefundNo, 'audit-admin', [
        'proof_no' => 'RFPROOF' . random_int(1000, 9999),
        'remark' => 'audit refund confirm',
    ]);
    $manualApprove = ManualTransferService::review($manualApproveNo, 'approve', 'audit-admin', [
        'proof_no' => 'TRPROOF' . random_int(1000, 9999),
        'remark' => 'audit transfer approve',
    ]);
    $manualReject = ManualTransferService::review($manualRejectNo, 'reject', 'audit-admin', [
        'reason' => 'audit transfer reject',
    ]);

    $logs = ResourceDataService::adminLogs();
    $adminLogs = $logs['admin_logs'] ?? [];
    $merchantLogs = $logs['merchant_logs'] ?? [];

    $findAction = static function (array $items, string $actionNeedle): ?array {
        foreach ($items as $item) {
            if (str_contains((string)($item['action'] ?? ''), $actionNeedle)) {
                return $item;
            }
        }

        return null;
    };

    $batchRefundLog = $findAction($adminLogs, '批量同步退款状态');
    $batchTransferLog = $findAction($adminLogs, '批量同步代付状态');
    $singleRefundLog = $findAction($adminLogs, '同步退款状态');
    $singleTransferLog = $findAction($adminLogs, '同步代付状态');
    $manualRefundAdminLog = $findAction($adminLogs, '人工确认退款');
    $manualApproveAdminLog = $findAction($adminLogs, '人工确认代付');
    $manualRejectAdminLog = $findAction($adminLogs, '人工驳回代付');

    $manualRefundMerchantLog = $findAction($merchantLogs, '人工确认退款');
    $manualApproveMerchantLog = $findAction($merchantLogs, '人工确认代付');
    $manualRejectMerchantLog = $findAction($merchantLogs, '人工驳回代付');

    $checks = [
        'single_sync_admin_logs_written' => is_array($singleRefundLog)
            && is_array($singleTransferLog)
            && (string)($singleRefundLog['operator'] ?? '') === 'audit-admin'
            && (string)($singleTransferLog['operator'] ?? '') === 'audit-admin',
        'batch_sync_admin_logs_written' => is_array($batchRefundLog)
            && is_array($batchTransferLog)
            && str_contains((string)($batchRefundLog['summary'] ?? ''), 'counts=')
            && str_contains((string)($batchTransferLog['summary'] ?? ''), 'counts='),
        'manual_compensation_logs_written_to_admin' => is_array($manualRefundAdminLog)
            && is_array($manualApproveAdminLog)
            && is_array($manualRejectAdminLog),
        'manual_compensation_logs_preserved_for_merchant' => is_array($manualRefundMerchantLog)
            && is_array($manualApproveMerchantLog)
            && is_array($manualRejectMerchantLog),
        'resource_data_exposes_structured_detail' => is_array($batchRefundLog['detail'] ?? null)
            && is_array($manualRefundAdminLog['detail'] ?? null)
            && ((string)($manualRefundAdminLog['detail']['refund_no'] ?? '') === $manualRefundNo),
    ];

    $result = [
        'single_refund' => $singleRefund,
        'single_transfer' => $singleTransfer,
        'batch_refund' => $batchRefund,
        'batch_transfer' => $batchTransfer,
        'manual_refund' => $manualRefund,
        'manual_approve' => $manualApprove,
        'manual_reject' => $manualReject,
        'admin_logs' => array_slice($adminLogs, 0, 8),
        'merchant_logs' => array_slice($merchantLogs, 0, 6),
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

            $capability = \app\service\payment\PluginExecutorService::capability($pluginCode);
            if (($capability['refund_query'] ?? false) || ($capability['transfer_query'] ?? false)) {
                continue;
            }

            return [$methodCode, $pluginCode, buildScriptPluginConfig($plugin, $methodCode)];
        }
    }

    throw new RuntimeException('no active method/plugin pair without payout query capability available');
}
