<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\model\CallbackQueue;
use app\model\Order;
use app\service\payment\CallbackService;
use app\service\payment\LocalOrderStore;
use app\service\payment\OrderService;
use app\service\system\JsonStoreService;
use app\service\system\MerchantChannelService;
use app\service\system\SettingsService;

$stores = [
    'settings',
    'merchant_channels',
    'orders_local',
    'callback_queue_local',
    'merchant_api_profiles',
    'merchant_accounts',
    'merchant_auth_users',
];

$backups = backupJsonStores($stores);
$server = null;
$result = [];
$ok = false;
$createdTradeNos = [];
$failOrder = null;
$successOrder = null;

try {
    foreach (['merchant_channels', 'orders_local', 'callback_queue_local'] as $store) {
        JsonStoreService::save($store, []);
    }

    $server = startCallbackServer(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'callback-test-router.php');
    $baseUrl = (string)($server['base_url'] ?? '');

    $settings = SettingsService::all(false);
    $settings['api'] = is_array($settings['api'] ?? null) ? $settings['api'] : [];
    $settings['api']['notify_retry'] = '2';
    JsonStoreService::save('settings', $settings);

    $merchant = bootstrapTestMerchant('cbretry');
    $merchantId = (int)$merchant['merchant_id'];
    [$methodCode, $pluginCode, $pluginConfig] = resolveFirstUsableChannelDefinition();

    MerchantChannelService::saveItem($merchantId, [
        'channel_name' => 'Callback Retry Verify Channel',
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

    $failOrder = OrderService::createMerchantBusinessOrder($merchantId, [
        'type' => $methodCode,
        'out_trade_no' => 'CBFAIL' . date('His') . random_int(1000, 9999),
        'notify_url' => $baseUrl . '/fail',
        'return_url' => $baseUrl . '/success',
        'name' => 'Callback Retry Fail Order',
        'money' => '10.00',
        'clientip' => '127.0.0.1',
        'param' => 'callback-retry-fail',
        'source_protocol' => 'v2',
        'request_payload' => [
            '_meta' => [
                'business' => 'merchant_payment',
                'source_protocol' => 'v2',
            ],
        ],
    ]);
    $successOrder = OrderService::createMerchantBusinessOrder($merchantId, [
        'type' => $methodCode,
        'out_trade_no' => 'CBSUCC' . date('His') . random_int(1000, 9999),
        'notify_url' => $baseUrl . '/success',
        'return_url' => $baseUrl . '/success',
        'name' => 'Callback Retry Success Order',
        'money' => '11.00',
        'clientip' => '127.0.0.1',
        'param' => 'callback-retry-success',
        'source_protocol' => 'v2',
        'request_payload' => [
            '_meta' => [
                'business' => 'merchant_payment',
                'source_protocol' => 'v2',
            ],
        ],
    ]);

    $createdTradeNos[] = (string)($failOrder->trade_no ?? '');
    $createdTradeNos[] = (string)($successOrder->trade_no ?? '');

    OrderService::completeByTradeNo((string)$failOrder->trade_no, [
        'source' => 'verify-callback-retry-policy',
        'txid' => 'CBF' . date('His') . random_int(1000, 9999),
        'paid_at' => date('Y-m-d H:i:s'),
    ]);
    OrderService::completeByTradeNo((string)$successOrder->trade_no, [
        'source' => 'verify-callback-retry-policy',
        'txid' => 'CBS' . date('His') . random_int(1000, 9999),
        'paid_at' => date('Y-m-d H:i:s'),
    ]);

    $failCallbackBefore = mustFindCallbackByOrderId((int)$failOrder->id);
    $successCallbackBefore = mustFindCallbackByOrderId((int)$successOrder->id);

    $firstRound = CallbackService::dispatchPendingCallbacks();
    $failCallbackAfterFirst = mustFindCallbackByOrderId((int)$failOrder->id);
    $successCallbackAfterFirst = mustFindCallbackByOrderId((int)$successOrder->id);
    $failOrderAfterFirst = OrderService::findByTradeNo((string)$failOrder->trade_no);
    $successOrderAfterFirst = OrderService::findByTradeNo((string)$successOrder->trade_no);

    forceCallbackReady((int)$failCallbackAfterFirst->id);
    $secondRound = CallbackService::dispatchPendingCallbacks();
    $failCallbackAfterSecond = mustFindCallbackByOrderId((int)$failOrder->id);
    $failOrderAfterSecond = OrderService::findByTradeNo((string)$failOrder->trade_no);

    $callbackLogs = CallbackService::logs($merchantId, 10);
    $failLog = firstCallbackLogByTradeNo($callbackLogs, (string)$failOrder->trade_no);
    $successLog = firstCallbackLogByTradeNo($callbackLogs, (string)$successOrder->trade_no);

    $checks = [
        'notify_retry_setting_applied' => (int)($failCallbackBefore->max_retry ?? 0) === 2
            && (int)($successCallbackBefore->max_retry ?? 0) === 2,
        'mixed_batch_continues' => (int)($firstRound['checked'] ?? 0) === 2
            && (int)($firstRound['succeeded'] ?? 0) === 1
            && (int)($firstRound['deferred'] ?? 0) === 1
            && (int)($firstRound['failed'] ?? 0) === 0
            && (int)($failCallbackAfterFirst->status ?? -1) === 0
            && (int)($failCallbackAfterFirst->retry_count ?? 0) === 1
            && (int)($successCallbackAfterFirst->status ?? -1) === 2
            && (int)($successCallbackAfterFirst->retry_count ?? 0) === 1,
        'second_round_honors_retry_limit' => (int)($secondRound['checked'] ?? 0) === 1
            && (int)($secondRound['failed'] ?? 0) === 1
            && (int)($failCallbackAfterSecond->status ?? -1) === 1
            && (int)($failCallbackAfterSecond->retry_count ?? 0) === 2
            && (int)($failCallbackAfterSecond->max_retry ?? 0) === 2
            && (int)($failOrderAfterSecond->callback_status ?? 0) === 3
            && (int)($failOrderAfterSecond->callback_count ?? 0) === 2,
        'success_order_stays_success' => (int)($successOrderAfterFirst->callback_status ?? 0) === 2
            && (int)($successOrderAfterFirst->callback_count ?? 0) === 1
            && (string)($successLog['result'] ?? '') === '成功',
        'logs_reflect_final_states' => (string)($failLog['result'] ?? '') === '失败'
            && (string)($successLog['result'] ?? '') === '成功',
    ];

    $result = [
        'merchant_id' => $merchantId,
        'notify_retry' => $settings['api']['notify_retry'],
        'orders' => [
            'fail_trade_no' => (string)$failOrder->trade_no,
            'success_trade_no' => (string)$successOrder->trade_no,
        ],
        'callbacks' => [
            'fail_before' => callbackSnapshot($failCallbackBefore),
            'success_before' => callbackSnapshot($successCallbackBefore),
            'fail_after_first' => callbackSnapshot($failCallbackAfterFirst),
            'success_after_first' => callbackSnapshot($successCallbackAfterFirst),
            'fail_after_second' => callbackSnapshot($failCallbackAfterSecond),
        ],
        'dispatch' => [
            'first' => $firstRound,
            'second' => $secondRound,
        ],
        'logs' => [
            'fail' => $failLog,
            'success' => $successLog,
        ],
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

    if (database_available()) {
        try {
            foreach (array_filter($createdTradeNos) as $tradeNo) {
                Order::where('trade_no', $tradeNo)->delete();
            }
        } catch (Throwable) {
        }
        try {
            $orderIds = array_values(array_filter([
                (int)($failOrder->id ?? 0),
                (int)($successOrder->id ?? 0),
            ]));
            if ($orderIds !== []) {
                CallbackQueue::whereIn('order_id', $orderIds)->delete();
            }
        } catch (Throwable) {
        }
    }

    stopCallbackServer($server);
    cleanupServerLogs(array_filter([
        (string)($server['stdout'] ?? ''),
        (string)($server['stderr'] ?? ''),
    ]));

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit($ok ? 0 : 1);

function mustFindCallbackByOrderId(int $orderId): object
{
    if ($orderId <= 0) {
        throw new RuntimeException('callback order id missing');
    }

    if (database_available()) {
        $callback = CallbackQueue::where('order_id', $orderId)->find();
        if ($callback) {
            return $callback;
        }
    }

    $callback = LocalOrderStore::findCallbackByOrderId($orderId);
    if ($callback) {
        return $callback;
    }

    throw new RuntimeException('callback not found for order id ' . $orderId);
}

function forceCallbackReady(int $callbackId): void
{
    $payload = [
        'next_time' => date('Y-m-d H:i:s', time() - 1),
    ];

    if (database_available()) {
        CallbackQueue::where('id', $callbackId)->update($payload);
        return;
    }

    LocalOrderStore::updateCallback($callbackId, $payload);
}

function callbackSnapshot(object $callback): array
{
    return [
        'id' => (int)($callback->id ?? 0),
        'status' => (int)($callback->status ?? 0),
        'retry_count' => (int)($callback->retry_count ?? 0),
        'max_retry' => (int)($callback->max_retry ?? 0),
        'last_error' => (string)($callback->last_error ?? ''),
        'next_time' => (string)($callback->next_time ?? ''),
    ];
}

function firstCallbackLogByTradeNo(array $items, string $tradeNo): array
{
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((string)($item['trade_no'] ?? '') === $tradeNo) {
            return $item;
        }
    }

    return [];
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
