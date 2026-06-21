<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';

use app\service\system\JsonStoreService;
use app\service\system\PluginTaskService;
use app\service\system\PluginService;
use app\service\system\ResourceDataService;
use app\service\system\TaskService;

$taskStore = 'tasks';
$runStore = 'task_runs';
$adminLogStore = 'admin_operation_logs';

$originalTasks = JsonStoreService::load($taskStore, []);
$originalRuns = JsonStoreService::load($runStore, []);
$originalAdminLogs = JsonStoreService::load($adminLogStore, []);

try {
    JsonStoreService::save($adminLogStore, []);

    $pluginCode = '';
    foreach (PluginTaskService::taskDefinitions() as $definition) {
        $key = trim((string)($definition['key'] ?? ''));
        if (!str_starts_with($key, 'plugin-daemon:')) {
            continue;
        }

        $candidate = trim(substr($key, strlen('plugin-daemon:')));
        if ($candidate !== '') {
            $pluginCode = $candidate;
            break;
        }
    }

    if ($pluginCode === '') {
        foreach (PluginService::plugins() as $plugin) {
            $candidate = trim((string)($plugin['code'] ?? ''));
            if ($candidate !== '') {
                $pluginCode = $candidate;
                break;
            }
        }
    }

    $pluginTaskKey = $pluginCode !== '' ? 'plugin-daemon:' . $pluginCode : '';

    JsonStoreService::save($taskStore, array_values(array_filter([
        [
            'key' => 'order-expire',
            'name' => '订单过期处理',
            'cron' => '*/1 * * * *',
            'status' => '鍚敤',
            'last_run' => '2026-06-20 10:10:00',
        ],
        [
            'key' => 'chain-listen',
            'name' => '支付状态同步',
            'cron' => '*/10 * * * * *',
            'status' => 'running',
            'last_run' => '2026-06-20 10:11:00',
        ],
        [
            'key' => 'payout-sync',
            'name' => 'Payout status sync',
            'cron' => '*/30 * * * * *',
            'status' => 'idle',
            'last_run' => '2026-06-20 10:12:00',
        ],
        [
            'key' => 'callback-retry',
            'name' => '回调重试',
            'cron' => '*/1 * * * *',
            'status' => 'disabled',
            'last_run' => '2026-06-20 10:13:00',
        ],
        $pluginTaskKey !== '' ? [
            'key' => $pluginTaskKey,
            'name' => $pluginCode . '任务轮询',
            'cron' => '*/1 * * * *',
            'status' => '鎴愬姛',
            'last_run' => '2026-06-20 10:14:00',
        ] : null,
    ], static fn($item): bool => is_array($item))));

    JsonStoreService::save($runStore, array_values(array_filter([
        [
            'task_key' => 'callback-retry',
            'task_name' => '鍥炶皟閲嶈瘯',
            'operator' => 'admin',
            'executed_at' => '2026-06-20 10:15:00',
            'status' => '澶辫触',
            'result' => 'failed',
        ],
        $pluginTaskKey !== '' ? [
            'task_key' => $pluginTaskKey,
            'task_name' => $pluginCode . '任务轮询',
            'operator' => 'system',
            'executed_at' => '2026-06-20 10:16:00',
            'status' => '鎴愬姛',
            'result' => 'success',
        ] : null,
    ], static fn($item): bool => is_array($item))));

    $taskData = TaskService::all();
    $taskMap = [];
    foreach ($taskData['items'] ?? [] as $item) {
        if (is_array($item) && trim((string)($item['key'] ?? '')) !== '') {
            $taskMap[(string)$item['key']] = $item;
        }
    }

    $callbackLogs = TaskService::logs('callback-retry');
    $adminLogs = ResourceDataService::adminLogs()['admin_logs'] ?? [];
    $taskAdminLog = null;
    foreach ($adminLogs as $item) {
        if (($item['action'] ?? '') === '执行任务：回调重试') {
            $taskAdminLog = $item;
            break;
        }
    }
    $sanitizedTasks = JsonStoreService::load($taskStore, []);
    $sanitizedRuns = JsonStoreService::load($runStore, []);

    $pluginSummary = null;
    if ($pluginCode !== '') {
        $pluginSummary = PluginService::diagnosePlugin($pluginCode)['tasks'] ?? null;
    }
    $pluginTaskDefined = is_array($pluginSummary) && (bool)($pluginSummary['defined'] ?? false);

    $checks = [
        (($taskMap['order-expire']['status'] ?? '') === '待命'),
        (($taskMap['chain-listen']['status'] ?? '') === '执行中'),
        (($taskMap['payout-sync']['status'] ?? '') === '待命'),
        (($taskMap['payout-sync']['name'] ?? '') === '退款/代付状态同步'),
        (($taskMap['callback-retry']['status'] ?? '') === '已停用'),
        (($callbackLogs['task']['name'] ?? '') === '回调重试'),
        (($callbackLogs['runs'][0]['status'] ?? '') === 'failed'),
        (($callbackLogs['runs'][0]['result'] ?? '') === '执行失败'),
        is_array($taskAdminLog),
        (($sanitizedTasks[2]['status'] ?? '') === '待命'),
        (($sanitizedRuns[0]['status'] ?? '') === 'failed'),
        (($sanitizedRuns[0]['result'] ?? '') === '执行失败'),
    ];

    if ($pluginTaskDefined) {
        $checks[] = (($taskMap[$pluginTaskKey]['status'] ?? '') === '最近成功');
        $checks[] = str_ends_with((string)($taskMap[$pluginTaskKey]['name'] ?? ''), '守护任务');
    }

    if (is_array($pluginSummary)) {
        $checks[] = (($pluginSummary['recent_runs'][0]['status'] ?? '') === 'success');
    }

    $ok = !in_array(false, $checks, true);

    echo json_encode([
        'plugin_code' => $pluginCode,
        'order_expire_status' => $taskMap['order-expire']['status'] ?? null,
        'chain_listen_status' => $taskMap['chain-listen']['status'] ?? null,
        'payout_sync_name' => $taskMap['payout-sync']['name'] ?? null,
        'payout_sync_status' => $taskMap['payout-sync']['status'] ?? null,
        'callback_retry_status' => $taskMap['callback-retry']['status'] ?? null,
        'callback_log_status' => $callbackLogs['runs'][0]['status'] ?? null,
        'callback_log_result' => $callbackLogs['runs'][0]['result'] ?? null,
        'admin_log_action' => $taskAdminLog['action'] ?? null,
        'plugin_task_status' => $pluginTaskKey !== '' ? ($taskMap[$pluginTaskKey]['status'] ?? null) : null,
        'plugin_run_status' => is_array($pluginSummary) ? ($pluginSummary['recent_runs'][0]['status'] ?? null) : null,
        'ok' => $ok,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo PHP_EOL;
} finally {
    JsonStoreService::save($taskStore, $originalTasks);
    JsonStoreService::save($runStore, $originalRuns);
    JsonStoreService::save($adminLogStore, $originalAdminLogs);
}
