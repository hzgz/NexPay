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
use app\service\system\ResourceDataService;
use app\service\system\SettingsService;
use app\service\system\TaskService;

$stores = [
    'settings',
    'merchant_channels',
    'orders_local',
    'callback_queue_local',
    'merchant_api_profiles',
    'merchant_accounts',
    'merchant_auth_users',
    'tasks',
    'task_runs',
];

$backups = backupJsonStores($stores);
$server = null;
$result = [];
$ok = false;
$createdTradeNos = [];
$createdOrderIds = [];

try {
    foreach (['merchant_channels', 'orders_local', 'callback_queue_local', 'tasks', 'task_runs'] as $store) {
        JsonStoreService::save($store, []);
    }

    $server = startCallbackServer(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'callback-test-router.php');
    $baseUrl = (string)($server['base_url'] ?? '');

    $settings = SettingsService::all(false);
    $settings['api'] = is_array($settings['api'] ?? null) ? $settings['api'] : [];
    $settings['api']['notify_retry'] = '2';
    JsonStoreService::save('settings', $settings);

    $merchant = bootstrapTestMerchant('cbobserve');
    $merchantId = (int)$merchant['merchant_id'];
    [$methodCode, $pluginCode, $pluginConfig] = resolveFirstUsableChannelDefinition();

    MerchantChannelService::saveItem($merchantId, [
        'channel_name' => 'Callback Observe Verify Channel',
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

    $successOrder = createVerifyOrder($merchantId, $methodCode, $baseUrl . '/success', 'CBOSUCC', 'callback-observe-success');
    $failOrder = createVerifyOrder($merchantId, $methodCode, $baseUrl . '/fail', 'CBOFAIL', 'callback-observe-fail');
    $futureOrder = createVerifyOrder($merchantId, $methodCode, $baseUrl . '/success', 'CBOFUTR', 'callback-observe-future');
    $exceptionOrder = createVerifyOrder($merchantId, $methodCode, $baseUrl . '/success', 'CBOEXCP', 'callback-observe-exception');

    foreach ([$successOrder, $failOrder, $futureOrder, $exceptionOrder] as $order) {
        $createdTradeNos[] = (string)($order->trade_no ?? '');
        $createdOrderIds[] = (int)($order->id ?? 0);
        OrderService::completeByTradeNo((string)$order->trade_no, [
            'source' => 'verify-callback-observability',
            'txid' => 'CBO' . date('His') . random_int(1000, 9999),
            'paid_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $successCallback = mustFindCallbackByOrderId((int)$successOrder->id);
    $failCallback = mustFindCallbackByOrderId((int)$failOrder->id);
    $futureCallback = mustFindCallbackByOrderId((int)$futureOrder->id);
    $exceptionCallback = mustFindCallbackByOrderId((int)$exceptionOrder->id);

    forceCallbackNextTime((int)$futureCallback->id, date('Y-m-d H:i:s', time() + 1800));
    forceCallbackNextTime((int)$exceptionCallback->id, date('Y-m-d H:i:s', time() + 2400));

    $firstRound = CallbackService::dispatchPendingCallbacks();
    $failCallbackAfterFirst = mustFindCallbackByOrderId((int)$failOrder->id);

    forceCallbackNextTime((int)$failCallbackAfterFirst->id, date('Y-m-d H:i:s', time() - 1));
    $secondRound = CallbackService::dispatchPendingCallbacks();

    $exceptionCallbackBefore = mustFindCallbackByOrderId((int)$exceptionOrder->id);
    invokeRuntimeException($exceptionCallbackBefore, 'forced runtime exception for callback observability');
    $exceptionCallbackAfter = mustFindCallbackByOrderId((int)$exceptionOrder->id);

    forceCallbackNextTime((int)$futureCallback->id, date('Y-m-d H:i:s', time() - 1));
    $summaryBeforeTask = CallbackService::summary($merchantId);
    TaskService::run('callback-retry', 'admin:verify');
    $summaryAfterTask = CallbackService::summary($merchantId);

    $adminLogs = ResourceDataService::adminLogs();
    $merchantOrders = ResourceDataService::merchantOrders($merchantId);
    $taskLogs = TaskService::logs('callback-retry');
    $latestTaskRun = $taskLogs['runs'][0] ?? [];

    $exceptionLog = firstCallbackLogByTradeNo((array)($merchantOrders['callback_logs'] ?? []), (string)$exceptionOrder->trade_no);
    $failLog = firstCallbackLogByTradeNo((array)($merchantOrders['callback_logs'] ?? []), (string)$failOrder->trade_no);
    $futureLog = firstCallbackLogByTradeNo((array)($merchantOrders['callback_logs'] ?? []), (string)$futureOrder->trade_no);

    $checks = [
        'batch_states_prepared' => (int)($firstRound['checked'] ?? 0) === 2
            && (int)($firstRound['succeeded'] ?? 0) === 1
            && (int)($firstRound['deferred'] ?? 0) === 1
            && (int)($secondRound['checked'] ?? 0) === 1
            && (int)($secondRound['failed'] ?? 0) === 1,
        'summary_before_task_has_due_and_attention' => (int)($summaryBeforeTask['pending_due'] ?? 0) === 1
            && (int)($summaryBeforeTask['pending_scheduled'] ?? 0) === 1
            && (int)($summaryBeforeTask['retry_exhausted'] ?? 0) === 1
            && (int)($summaryBeforeTask['runtime_exception_total'] ?? 0) === 1
            && (int)($summaryBeforeTask['attention_total'] ?? 0) === 3,
        'summary_after_task_matches_runtime' => (int)($summaryAfterTask['total'] ?? 0) === 4
            && (int)($summaryAfterTask['success_total'] ?? 0) === 2
            && (int)($summaryAfterTask['failed_total'] ?? 0) === 1
            && (int)($summaryAfterTask['pending_due'] ?? 0) === 0
            && (int)($summaryAfterTask['pending_scheduled'] ?? 0) === 1
            && (int)($summaryAfterTask['retry_exhausted'] ?? 0) === 1
            && (int)($summaryAfterTask['runtime_exception_total'] ?? 0) === 1
            && (int)($summaryAfterTask['attention_total'] ?? 0) === 2,
        'admin_and_merchant_payload_include_summary' => is_array($adminLogs['callback_summary'] ?? null)
            && is_array($merchantOrders['callback_summary'] ?? null)
            && (int)(($adminLogs['callback_summary']['retry_exhausted'] ?? -1)) >= 1
            && (int)(($adminLogs['callback_summary']['attention_total'] ?? -1)) >= 2
            && (int)(($merchantOrders['callback_summary']['retry_exhausted'] ?? -1)) === 1,
        'callback_logs_surface_attention_flags' => (bool)($exceptionLog['runtime_exception'] ?? false)
            && (bool)($exceptionLog['needs_attention'] ?? false)
            && (string)($failLog['result'] ?? '') === '失败'
            && (bool)($failLog['retry_exhausted'] ?? false)
            && (string)($futureLog['result'] ?? '') === '成功',
        'task_run_contains_queue_metrics' => (string)($latestTaskRun['status'] ?? '') === 'success'
            && str_contains((string)($latestTaskRun['result'] ?? ''), 'queue_due_before=1')
            && str_contains((string)($latestTaskRun['result'] ?? ''), 'queue_due_after=0')
            && str_contains((string)($latestTaskRun['result'] ?? ''), 'queue_exhausted_after=1')
            && str_contains((string)($latestTaskRun['result'] ?? ''), 'queue_attention_after=2'),
        'runtime_exception_writeback_applied' => (int)($exceptionCallbackAfter->retry_count ?? 0) === 1
            && (int)($exceptionCallbackAfter->status ?? 0) === 0
            && (string)($exceptionLog['result'] ?? '') === '重试中',
    ];

    $result = [
        'merchant_id' => $merchantId,
        'dispatch' => [
            'first' => $firstRound,
            'second' => $secondRound,
        ],
        'summary_before_task' => $summaryBeforeTask,
        'summary_after_task' => $summaryAfterTask,
        'admin_callback_summary' => $adminLogs['callback_summary'] ?? [],
        'merchant_callback_summary' => $merchantOrders['callback_summary'] ?? [],
        'task_run' => $latestTaskRun,
        'logs' => [
            'fail' => $failLog,
            'future' => $futureLog,
            'exception' => $exceptionLog,
        ],
        'callbacks' => [
            'exception_before' => callbackSnapshot($exceptionCallbackBefore),
            'exception_after' => callbackSnapshot($exceptionCallbackAfter),
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
            $orderIds = array_values(array_filter($createdOrderIds));
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

function createVerifyOrder(int $merchantId, string $methodCode, string $notifyUrl, string $prefix, string $param): object
{
    return OrderService::createMerchantBusinessOrder($merchantId, [
        'type' => $methodCode,
        'out_trade_no' => $prefix . date('His') . random_int(1000, 9999),
        'notify_url' => $notifyUrl,
        'return_url' => $notifyUrl,
        'name' => 'Callback Observe Order ' . $prefix,
        'money' => '10.00',
        'clientip' => '127.0.0.1',
        'param' => $param,
        'source_protocol' => 'v2',
        'request_payload' => [
            '_meta' => [
                'business' => 'merchant_payment',
                'source_protocol' => 'v2',
            ],
        ],
    ]);
}

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

function forceCallbackNextTime(int $callbackId, string $nextTime): void
{
    $payload = [
        'next_time' => $nextTime,
    ];

    if (database_available()) {
        CallbackQueue::where('id', $callbackId)->update($payload);
        return;
    }

    LocalOrderStore::updateCallback($callbackId, $payload);
}

function invokeRuntimeException(object $callback, string $message): void
{
    $method = new ReflectionMethod(CallbackService::class, 'handleDispatchException');
    $method->setAccessible(true);
    $method->invoke(null, $callback, new RuntimeException($message), false);
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

function waitForHttpReady(string $url, string $stderrPath, int $timeoutMs = 5000): void
{
    $deadline = microtime(true) + ($timeoutMs / 1000);

    while (microtime(true) < $deadline) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 1,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result !== false) {
            return;
        }

        usleep(100000);
    }

    $stderr = is_file($stderrPath) ? trim((string)file_get_contents($stderrPath)) : '';
    throw new RuntimeException('callback server did not become ready' . ($stderr !== '' ? ': ' . $stderr : ''));
}
