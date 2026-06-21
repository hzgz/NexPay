<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\service\payment\CallbackService;
use app\service\payment\GatewayCompatService;
use app\service\payment\LocalFundStore;
use app\service\payment\LocalOrderStore;
use app\service\payment\LocalTransferStore;
use app\service\payment\OrderService;
use app\service\payment\PluginExecutorService;
use app\service\payment\SignService;
use app\service\system\JsonStoreService;
use app\service\system\ManualRefundService;
use app\service\system\ManualTransferService;
use app\service\system\MerchantApiService;
use app\service\system\MerchantChannelService;
use app\service\system\MerchantFundService;
use app\service\system\PaymentMetaService;
use app\service\system\PluginCodeService;
use app\service\system\PluginSchemaService;
use app\service\system\PluginService;
use app\service\system\ResourceDataService;

$stores = [
    'merchant_channels',
    'orders_local',
    'callback_queue_local',
    'fund_flows_local',
    'refunds_local',
    'transfers_local',
    'merchant_operation_logs',
    'admin_operation_logs',
    'merchant_api_profiles',
    'merchant_accounts',
    'merchant_auth_users',
];

$backups = [];
foreach ($stores as $store) {
    $backups[$store] = JsonStoreService::load($store, []);
}

$server = null;
$serverLogs = ['stdout' => '', 'stderr' => ''];
$result = [];
$ok = false;
$merchantId = 0;
$userId = 0;

try {
    foreach ([
        'merchant_channels',
        'orders_local',
        'callback_queue_local',
        'fund_flows_local',
        'refunds_local',
        'transfers_local',
        'merchant_operation_logs',
        'admin_operation_logs',
    ] as $store) {
        JsonStoreService::save($store, []);
    }

    $server = startCallbackServer(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'callback-test-router.php');
    $serverLogs = [
        'stdout' => (string)($server['stdout'] ?? ''),
        'stderr' => (string)($server['stderr'] ?? ''),
    ];
    $callbackBaseUrl = (string)($server['base_url'] ?? '');

    $merchant = bootstrapTestMerchant('closure');
    $merchantId = (int)$merchant['merchant_id'];
    $userId = (int)$merchant['user_id'];

    [$merchantInfo, $pid, $privateKey] = ensureScriptMerchantApiCredentials($merchantId, $userId);
    [$methodCode, $pluginCode, $pluginConfig, $capability] = resolveUsableChannelDefinition();

    $channelState = MerchantChannelService::saveItem($merchantId, [
        'channel_name' => 'Closure Runtime Channel',
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
    $channel = newestChannelItem($channelState);

    $successOrder = OrderService::createMerchantBusinessOrder($merchantId, [
        'type' => $methodCode,
        'out_trade_no' => businessNo('BIZS'),
        'notify_url' => $callbackBaseUrl . '/success',
        'return_url' => $callbackBaseUrl . '/success',
        'name' => 'Business Callback Success Order',
        'money' => '18.00',
        'clientip' => '127.0.0.1',
        'param' => 'merchant-callback-success',
        'source_protocol' => 'v2',
        'request_payload' => [
            '_meta' => [
                'business' => 'merchant_payment',
                'source_protocol' => 'v2',
            ],
        ],
    ]);
    OrderService::completeByTradeNo((string)$successOrder->trade_no, [
        'source' => 'refund-transfer-callback-closure',
        'txid' => businessNo('REALS'),
        'paid_at' => date('Y-m-d H:i:s'),
    ]);
    $successDispatch = CallbackService::dispatchPendingCallbacks();
    $successCallback = callbackLogByTradeNo($merchantId, (string)$successOrder->trade_no);
    $successOrderAfter = OrderService::findByTradeNo((string)$successOrder->trade_no);

    $retryOrder = OrderService::createMerchantBusinessOrder($merchantId, [
        'type' => $methodCode,
        'out_trade_no' => businessNo('BIZR'),
        'notify_url' => $callbackBaseUrl . '/fail',
        'return_url' => $callbackBaseUrl . '/success',
        'name' => 'Business Callback Retry Order',
        'money' => '12.00',
        'clientip' => '127.0.0.1',
        'param' => 'merchant-callback-retry',
        'source_protocol' => 'v2',
        'request_payload' => [
            '_meta' => [
                'business' => 'merchant_payment',
                'source_protocol' => 'v2',
            ],
        ],
    ]);
    OrderService::completeByTradeNo((string)$retryOrder->trade_no, [
        'source' => 'refund-transfer-callback-closure',
        'txid' => businessNo('REALR'),
        'paid_at' => date('Y-m-d H:i:s'),
    ]);
    $retryDispatchFirst = CallbackService::dispatchPendingCallbacks();
    $retryCallbackBefore = callbackLogByTradeNo($merchantId, (string)$retryOrder->trade_no);
    if ($retryCallbackBefore === []) {
        throw new RuntimeException('retry callback record missing after first dispatch');
    }

    LocalOrderStore::updateCallback((int)($retryCallbackBefore['id'] ?? 0), [
        'notify_url' => $callbackBaseUrl . '/success',
    ]);
    $retryDispatchManual = CallbackService::retryNow((int)($retryCallbackBefore['id'] ?? 0));
    $retryCallbackAfter = callbackLogByTradeNo($merchantId, (string)$retryOrder->trade_no);
    $retryOrderAfter = OrderService::findByTradeNo((string)$retryOrder->trade_no);

    $balanceAfterOrders = LocalFundStore::balanceForMerchant($merchantId);

    $recharge = MerchantFundService::createRechargeOrder($merchantId, [
        'amount' => '50.00',
        'client_ip' => '127.0.0.1',
        'type' => $methodCode,
    ]);
    OrderService::completeByTradeNo((string)($recharge['trade_no'] ?? ''), [
        'source' => 'refund-transfer-callback-closure',
        'txid' => businessNo('RECH'),
        'paid_at' => date('Y-m-d H:i:s'),
    ]);
    $balanceAfterRecharge = LocalFundStore::balanceForMerchant($merchantId);

    $refundAmount = '8.00';
    $refundOutNo = businessNo('RFD');
    $refundSubmit = null;
    $refundSubmitError = '';
    try {
        $refundSubmit = GatewayCompatService::refundForV2(signedV2Payload([
            'trade_no' => (string)$successOrder->trade_no,
            'money' => $refundAmount,
            'out_refund_no' => $refundOutNo,
        ], $pid, $privateKey));
    } catch (Throwable $exception) {
        $refundSubmitError = $exception->getMessage();
    }

    $refund = LocalTransferStore::findRefund($merchantId, null, $refundOutNo, (string)$successOrder->trade_no);
    $refundPath = 'gateway';
    if (!$refund) {
        $refundPath = 'fallback_seeded';
        $refund = LocalTransferStore::createRefund([
            'merchant_id' => $merchantId,
            'trade_no' => (string)$successOrder->trade_no,
            'out_trade_no' => (string)$successOrder->out_trade_no,
            'refund_no' => businessNo('RFM'),
            'out_refund_no' => $refundOutNo,
            'money' => $refundAmount,
            'reducemoney' => $refundAmount,
            'status' => 0,
            'result' => 'manual_refund_pending',
            'last_error' => 'Manual refund review required',
            'channel_id' => (int)($channel['id'] ?? 0),
            'channel_plugin_code' => $pluginCode,
        ]);
    }

    $manualRefund = null;
    if ((int)($refund->status ?? 0) === 0) {
        $manualRefund = ManualRefundService::confirm((string)$refund->refund_no, 'closure-script', [
            'proof_no' => businessNo('RFPROOF'),
            'remark' => 'manual refund closure',
        ]);
        $refund = LocalTransferStore::findRefundByNo((string)$refund->refund_no) ?? $refund;
    } else {
        $refundPath = 'gateway_auto_success';
    }
    $refundQuery = GatewayCompatService::refundQueryForV2(signedV2Payload([
        'out_refund_no' => $refundOutNo,
    ], $pid, $privateKey));
    $balanceAfterRefund = LocalFundStore::balanceForMerchant($merchantId);

    $manualTransferType = resolveManualTransferType($merchantId);

    $approveOutBizNo = businessNo('TRA');
    $approveSubmit = null;
    $approveSubmitError = '';
    try {
        $approveSubmit = GatewayCompatService::transferSubmitForV2(signedV2Payload([
            'type' => $manualTransferType,
            'account' => 'payee.approve@example.com',
            'name' => 'Closure Approver',
            'money' => '12.00',
            'out_biz_no' => $approveOutBizNo,
        ], $pid, $privateKey));
    } catch (Throwable $exception) {
        $approveSubmitError = $exception->getMessage();
    }

    $approveTransfer = LocalTransferStore::findTransferByBizNo(null, $approveOutBizNo);
    $approvePath = 'gateway';
    if (!$approveTransfer) {
        $approvePath = 'fallback_seeded';
        $approveTransfer = LocalTransferStore::createTransfer([
            'merchant_id' => $merchantId,
            'biz_no' => businessNo('TRM'),
            'out_biz_no' => $approveOutBizNo,
            'type' => $manualTransferType,
            'account' => 'payee.approve@example.com',
            'name' => 'Closure Approver',
            'money' => '12.00',
            'status' => 0,
            'available_money' => (string)($balanceAfterRefund['available'] ?? '0.00'),
            'transfer_rate' => '0.00',
            'result' => 'manual_transfer_pending',
            'last_error' => 'Manual transfer review required',
            'channel_plugin_code' => $pluginCode,
            'channel_id' => (int)($channel['id'] ?? 0),
        ]);
    }

    $manualApprove = null;
    if ((int)($approveTransfer->status ?? 0) === 0) {
        $manualApprove = ManualTransferService::review((string)$approveTransfer->biz_no, 'approve', 'closure-script', [
            'proof_no' => businessNo('TRPROOF'),
            'remark' => 'manual transfer approve',
        ]);
        $approveTransfer = LocalTransferStore::findTransferByBizNo((string)$approveTransfer->biz_no) ?? $approveTransfer;
    } else {
        $approvePath = 'gateway_auto_success';
    }
    $approveQuery = GatewayCompatService::transferQueryForV2(signedV2Payload([
        'out_biz_no' => $approveOutBizNo,
    ], $pid, $privateKey));
    $balanceAfterApprove = LocalFundStore::balanceForMerchant($merchantId);

    $rejectOutBizNo = businessNo('TRR');
    $rejectSubmit = null;
    $rejectSubmitError = '';
    try {
        $rejectSubmit = GatewayCompatService::transferSubmitForV2(signedV2Payload([
            'type' => $manualTransferType,
            'account' => 'payee.reject@example.com',
            'name' => 'Closure Rejector',
            'money' => '7.00',
            'out_biz_no' => $rejectOutBizNo,
        ], $pid, $privateKey));
    } catch (Throwable $exception) {
        $rejectSubmitError = $exception->getMessage();
    }

    $rejectTransfer = LocalTransferStore::findTransferByBizNo(null, $rejectOutBizNo);
    $rejectPath = 'gateway';
    if (!$rejectTransfer) {
        $rejectPath = 'fallback_seeded';
        $rejectTransfer = LocalTransferStore::createTransfer([
            'merchant_id' => $merchantId,
            'biz_no' => businessNo('TRN'),
            'out_biz_no' => $rejectOutBizNo,
            'type' => $manualTransferType,
            'account' => 'payee.reject@example.com',
            'name' => 'Closure Rejector',
            'money' => '7.00',
            'status' => 0,
            'available_money' => (string)($balanceAfterApprove['available'] ?? '0.00'),
            'transfer_rate' => '0.00',
            'result' => 'manual_transfer_pending',
            'last_error' => 'Manual transfer review required',
            'channel_plugin_code' => $pluginCode,
            'channel_id' => (int)($channel['id'] ?? 0),
        ]);
    }

    $manualReject = null;
    if ((int)($rejectTransfer->status ?? 0) === 0) {
        $manualReject = ManualTransferService::review((string)$rejectTransfer->biz_no, 'reject', 'closure-script', [
            'reason' => 'manual transfer reject',
        ]);
        $rejectTransfer = LocalTransferStore::findTransferByBizNo((string)$rejectTransfer->biz_no) ?? $rejectTransfer;
    } else {
        $rejectPath = 'gateway_auto_success';
    }
    $rejectQuery = GatewayCompatService::transferQueryForV2(signedV2Payload([
        'out_biz_no' => $rejectOutBizNo,
    ], $pid, $privateKey));
    $balanceAfterReject = LocalFundStore::balanceForMerchant($merchantId);

    $checks = [
        'success_callback_succeeded' => (int)($successDispatch['succeeded'] ?? 0) === 1
            && (int)($successCallback['status_code'] ?? 0) === 2
            && (int)($successOrderAfter->callback_status ?? 0) === 2,
        'retry_callback_deferred_then_succeeded' => (int)($retryDispatchFirst['deferred'] ?? 0) === 1
            && (int)($retryCallbackBefore['status_code'] ?? -1) === 0
            && (int)($retryDispatchManual['succeeded'] ?? 0) === 1
            && (int)($retryCallbackAfter['status_code'] ?? 0) === 2
            && (int)($retryOrderAfter->callback_status ?? 0) === 2,
        'recharge_balance_delta' => moneyEquals(
            (string)($balanceAfterRecharge['available'] ?? '0.00'),
            moneyAdd((string)($balanceAfterOrders['available'] ?? '0.00'), '50.00')
        ) && (string)($balanceAfterRecharge['total_recharge'] ?? '0.00') === '50.00',
        'refund_balance_delta' => moneyEquals(
            (string)($balanceAfterRefund['available'] ?? '0.00'),
            moneySub((string)($balanceAfterRecharge['available'] ?? '0.00'), $refundAmount)
        ) && (int)($refundQuery['status'] ?? 0) === 1,
        'transfer_approve_balance_delta' => moneyEquals(
            (string)($balanceAfterApprove['available'] ?? '0.00'),
            moneySub((string)($balanceAfterRefund['available'] ?? '0.00'), '12.00')
        ) && (int)($approveQuery['status'] ?? 0) === 1,
        'transfer_reject_keeps_balance' => moneyEquals(
            (string)($balanceAfterReject['available'] ?? '0.00'),
            (string)($balanceAfterApprove['available'] ?? '0.00')
        ) && (int)($rejectQuery['status'] ?? 0) === 2,
    ];

    $result = [
        'callback_server' => $callbackBaseUrl,
        'merchant' => [
            'merchant_id' => $pid,
            'merchant_key' => (string)($merchantInfo['mch_key'] ?? ''),
            'has_private_key' => $privateKey !== '',
        ],
        'selected_channel' => [
            'id' => (int)($channel['id'] ?? 0),
            'method_code' => $methodCode,
            'plugin_code' => $pluginCode,
            'capability' => $capability,
            'manual_transfer_type' => $manualTransferType,
        ],
        'callback_success' => [
            'trade_no' => (string)$successOrder->trade_no,
            'dispatch' => $successDispatch,
            'log' => $successCallback,
            'order_callback_status' => (int)($successOrderAfter->callback_status ?? 0),
        ],
        'callback_retry' => [
            'trade_no' => (string)$retryOrder->trade_no,
            'first_dispatch' => $retryDispatchFirst,
            'first_log' => $retryCallbackBefore,
            'manual_retry' => $retryDispatchManual,
            'final_log' => $retryCallbackAfter,
            'order_callback_status' => (int)($retryOrderAfter->callback_status ?? 0),
        ],
        'funds' => [
            'after_orders' => $balanceAfterOrders,
            'recharge' => $recharge,
            'after_recharge' => $balanceAfterRecharge,
            'after_refund' => $balanceAfterRefund,
            'after_transfer_approve' => $balanceAfterApprove,
            'after_transfer_reject' => $balanceAfterReject,
        ],
        'refund' => [
            'path' => $refundPath,
            'submit' => $refundSubmit,
            'submit_error' => $refundSubmitError,
            'record' => refundShape($refund),
            'manual' => $manualRefund,
            'query' => $refundQuery,
        ],
        'transfer_approve' => [
            'path' => $approvePath,
            'submit' => $approveSubmit,
            'submit_error' => $approveSubmitError,
            'record' => transferShape($approveTransfer),
            'manual' => $manualApprove,
            'query' => $approveQuery,
        ],
        'transfer_reject' => [
            'path' => $rejectPath,
            'submit' => $rejectSubmit,
            'submit_error' => $rejectSubmitError,
            'record' => transferShape($rejectTransfer),
            'manual' => $manualReject,
            'query' => $rejectQuery,
        ],
        'checks' => $checks,
    ];

    $ok = !in_array(false, $checks, true);
} catch (Throwable $exception) {
    $result = array_merge($result, [
        'error' => $exception->getMessage(),
        'exception' => get_class($exception),
    ]);
} finally {
    stopCallbackServer($server);

    foreach ($stores as $store) {
        JsonStoreService::save($store, $backups[$store]);
    }

    cleanupServerLogs($serverLogs);

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit($ok ? 0 : 1);

function ensureMerchantApiCredentials(int $merchantId, int $userId): array
{
    $merchantInfo = ResourceDataService::merchantApiInfo($merchantId, $userId);
    $privateKey = trim((string)($merchantInfo['merchant_private_key'] ?? $merchantInfo['rsa_private_key'] ?? ''));

    if ($privateKey === '') {
        $generated = MerchantApiService::generateRsaKeyPair($merchantId, $userId);
        $merchantInfo = is_array($generated['info'] ?? null)
            ? $generated['info']
            : ResourceDataService::merchantApiInfo($merchantId, $userId);
        $privateKey = trim((string)($generated['generated_private_key'] ?? $merchantInfo['merchant_private_key'] ?? $merchantInfo['rsa_private_key'] ?? ''));
    }

    if ($privateKey === '') {
        throw new RuntimeException('merchant RSA private key missing after generation');
    }

    return [$merchantInfo, (string)($merchantInfo['merchant_id'] ?? $merchantId), $privateKey];
}

function signedV2Payload(array $payload, string $pid, string $privateKey): array
{
    $payload['pid'] = $pid;
    $payload['timestamp'] = (string)time();
    $payload['sign_type'] = 'RSA';
    $payload['sign'] = SignService::rsaSign($payload, $privateKey);
    return $payload;
}

function resolveUsableChannelDefinition(): array
{
    $methods = PluginService::methods();
    $plugins = PluginService::plugins();
    $best = null;
    $bestScore = -1;

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
            if ($pluginCode === '' || !pluginSupportsMethod($plugin, $methodCode)) {
                continue;
            }

            $capability = PluginExecutorService::capability($pluginCode);
            $score = ($capability['refund'] ? 0 : 2) + ($capability['transfer'] ? 0 : 1);
            $candidate = [
                $methodCode,
                $pluginCode,
                buildPluginConfig($plugin, $methodCode),
                $capability,
            ];

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }

            if ($score === 3) {
                return $candidate;
            }
        }
    }

    if ($best !== null) {
        return $best;
    }

    throw new RuntimeException('no active method/plugin pair available for refund-transfer callback closure verification');
}

function pluginSupportsMethod(array $plugin, string $methodCode): bool
{
    foreach ((array)($plugin['payment_methods'] ?? []) as $pluginMethod) {
        if (PaymentMetaService::normalizeMethodCode((string)$pluginMethod) === $methodCode) {
            return true;
        }
    }

    return false;
}

function buildPluginConfig(array $plugin, string $methodCode): array
{
    $config = [];

    foreach ((array)($plugin['settings_schema'] ?? []) as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !PluginSchemaService::isFieldVisible($field, $methodCode, $config)) {
            continue;
        }

        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $default = $plugin['default_settings'][$key] ?? $field['default'] ?? null;
        if ($default !== null && trim((string)$default) !== '') {
            $config[$key] = (string)$default;
            continue;
        }

        $config[$key] = match ($type) {
            'number' => '1',
            'select', 'radio' => firstOptionValue($field['options'] ?? null),
            'checkbox', 'switch' => '1',
            default => 'closure-placeholder',
        };
    }

    return $config;
}

function firstOptionValue(mixed $options): string
{
    if (is_array($options)) {
        $first = reset($options);
        if (is_array($first)) {
            return (string)($first['value'] ?? $first['key'] ?? $first['id'] ?? '1');
        }

        if ($first !== false) {
            return (string)$first;
        }
    }

    return '1';
}

function resolveManualTransferType(int $merchantId): string
{
    foreach (['alipay', 'wxpay', 'qqpay', 'bank'] as $type) {
        if (OrderService::gatewayTransferChannel($merchantId, $type) === null) {
            return $type;
        }
    }

    return 'alipay';
}

function newestChannelItem(array $state): array
{
    $items = is_array($state['items'] ?? null) ? $state['items'] : [];
    if ($items === []) {
        return [];
    }

    usort($items, static fn(array $left, array $right): int => (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0));
    return $items[0];
}

function callbackLogByTradeNo(int $merchantId, string $tradeNo): array
{
    foreach (CallbackService::logs($merchantId, 100) as $row) {
        if ((string)($row['trade_no'] ?? '') === $tradeNo) {
            return $row;
        }
    }

    return [];
}

function refundShape(?object $refund): array
{
    if (!$refund) {
        return [];
    }

    return ManualRefundService::shapeRefund($refund);
}

function transferShape(?object $transfer): array
{
    if (!$transfer) {
        return [];
    }

    return ManualTransferService::shapeTransfer($transfer);
}

function businessNo(string $prefix): string
{
    return $prefix . date('YmdHis') . random_int(100000, 999999);
}

function moneyAdd(string $left, string $right): string
{
    return number_format((float)$left + (float)$right, 2, '.', '');
}

function moneySub(string $left, string $right): string
{
    return number_format((float)$left - (float)$right, 2, '.', '');
}

function moneyEquals(string $left, string $right): bool
{
    return number_format((float)$left, 2, '.', '') === number_format((float)$right, 2, '.', '');
}

function startCallbackServer(string $routerPath): array
{
    if (!is_file($routerPath)) {
        throw new RuntimeException('callback router missing: ' . $routerPath);
    }

    $port = reserveFreePort();
    $stdout = tempnam(sys_get_temp_dir(), 'nexpay-callback-out-');
    $stderr = tempnam(sys_get_temp_dir(), 'nexpay-callback-err-');
    if ($stdout === false || $stderr === false) {
        throw new RuntimeException('failed to create callback server log files');
    }

    $command = escapeshellarg(PHP_BINARY)
        . ' -S 127.0.0.1:' . $port
        . ' ' . escapeshellarg(basename($routerPath));

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $stdout, 'a'],
        2 => ['file', $stderr, 'a'],
    ];

    $pipes = [];
    $process = proc_open($command, $descriptors, $pipes, dirname($routerPath));
    if (!is_resource($process)) {
        throw new RuntimeException('failed to start callback server');
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $baseUrl = 'http://127.0.0.1:' . $port;
    waitForHttpReady($baseUrl . '/success', $stderr);

    return [
        'process' => $process,
        'base_url' => $baseUrl,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function stopCallbackServer(?array $server): void
{
    if (!$server || !is_resource($server['process'] ?? null)) {
        return;
    }

    @proc_terminate($server['process']);
    @proc_close($server['process']);
}

function cleanupServerLogs(array $paths): void
{
    foreach ($paths as $path) {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}

function reserveFreePort(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('failed to reserve callback port: ' . $error);
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($name) || !str_contains($name, ':')) {
        throw new RuntimeException('failed to resolve callback port');
    }

    return (int)substr(strrchr($name, ':'), 1);
}

function waitForHttpReady(string $url, string $stderrPath): void
{
    for ($i = 0; $i < 40; $i++) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 1,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if (trim((string)$response) === 'success') {
            return;
        }

        usleep(150000);
    }

    $stderr = is_file($stderrPath) ? trim((string)file_get_contents($stderrPath)) : '';
    throw new RuntimeException('callback server did not become ready' . ($stderr !== '' ? ': ' . $stderr : ''));
}
