<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\CallbackService;
use app\service\payment\OrderService;
use Throwable;

class TaskService
{
    private const STORE_KEY = 'tasks';
    private const RUN_LOG_KEY = 'task_runs';

    public static function all(): array
    {
        $seed = array_merge(self::baseTasks(), self::sanitizePluginTasks(PluginTaskService::taskDefinitions()));
        $stored = self::sanitizeTasks(JsonStoreService::load(self::STORE_KEY, $seed));
        $items = self::mergeTasks($stored, $seed);
        JsonStoreService::save(self::STORE_KEY, $items);

        return [
            'items' => $items,
            'runs' => self::runs(),
        ];
    }

    public static function runs(string $key = ''): array
    {
        $items = self::loadRuns();
        $key = trim($key);
        if ($key === '') {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn(array $run): bool => ($run['task_key'] ?? '') === $key,
        ));
    }

    public static function logs(string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            throw new BusinessException('任务标识不能为空', StatusCode::VALIDATION_ERROR);
        }

        $task = null;
        foreach (self::all()['items'] as $item) {
            if (($item['key'] ?? '') === $key) {
                $task = $item;
                break;
            }
        }

        if ($task === null) {
            throw new BusinessException('任务不存在', StatusCode::NOT_FOUND);
        }

        return [
            'task' => $task,
            'runs' => array_slice(self::runs($key), 0, 30),
        ];
    }

    public static function saveCron(string $key, string $cron): array
    {
        $key = trim($key);
        $cron = trim($cron);

        if ($key === '') {
            throw new BusinessException('任务标识不能为空', StatusCode::VALIDATION_ERROR);
        }

        if ($cron === '') {
            throw new BusinessException('Cron 表达式不能为空', StatusCode::VALIDATION_ERROR);
        }

        if (!self::isValidCron($cron)) {
            throw new BusinessException('Cron 表达式格式不正确', StatusCode::VALIDATION_ERROR);
        }

        $items = self::all()['items'];
        $found = false;

        foreach ($items as &$item) {
            if (($item['key'] ?? '') !== $key) {
                continue;
            }

            $item['cron'] = $cron;
            $found = true;
            break;
        }
        unset($item);

        if (!$found) {
            throw new BusinessException('任务不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::STORE_KEY, $items);

        return self::all();
    }

    public static function run(string $key, string $operator = 'admin'): array
    {
        $key = trim($key);
        if ($key === '') {
            throw new BusinessException('任务标识不能为空', StatusCode::VALIDATION_ERROR);
        }

        $items = self::all()['items'];
        $taskIndex = null;
        $taskName = $key;
        $executedAt = date('Y-m-d H:i:s');

        foreach ($items as $index => $item) {
            if (($item['key'] ?? '') !== $key) {
                continue;
            }

            $taskIndex = $index;
            $taskName = (string)($item['name'] ?? $key);
            break;
        }

        if ($taskIndex === null) {
            throw new BusinessException('任务不存在', StatusCode::NOT_FOUND);
        }

        $items[$taskIndex]['status'] = '执行中';
        $items[$taskIndex]['last_run'] = $executedAt;
        JsonStoreService::save(self::STORE_KEY, $items);

        try {
            $metrics = self::execute($key);
            $items[$taskIndex]['status'] = '最近成功';
            self::appendRun([
                'task_key' => $key,
                'task_name' => $taskName,
                'operator' => trim($operator) !== '' ? $operator : 'admin',
                'executed_at' => $executedAt,
                'status' => 'success',
                'result' => self::formatResult($metrics),
            ]);
        } catch (Throwable $exception) {
            $items[$taskIndex]['status'] = '最近失败';
            self::appendRun([
                'task_key' => $key,
                'task_name' => $taskName,
                'operator' => trim($operator) !== '' ? $operator : 'admin',
                'executed_at' => $executedAt,
                'status' => 'failed',
                'result' => trim($exception->getMessage()) !== '' ? $exception->getMessage() : '执行失败',
            ]);
            JsonStoreService::save(self::STORE_KEY, $items);
            throw $exception;
        }

        JsonStoreService::save(self::STORE_KEY, $items);
        return self::all();
    }

    private static function execute(string $key): array
    {
        if (str_starts_with($key, 'plugin-daemon:')) {
            return PluginTaskService::runTask($key);
        }

        return match ($key) {
            'order-expire' => ['expired' => OrderService::expirePendingOrders()],
            'chain-listen' => OrderService::syncPendingOrders(),
            'payout-sync' => OrderService::syncPendingPayoutsWithSummary(),
            'callback-retry' => CallbackService::dispatchPendingCallbacksWithSummary(),
            default => throw new BusinessException('任务不存在', StatusCode::NOT_FOUND),
        };
    }

    private static function baseTasks(): array
    {
        return [
            [
                'key' => 'order-expire',
                'name' => '订单过期处理',
                'cron' => '*/1 * * * *',
                'status' => '待命',
                'last_run' => '',
            ],
            [
                'key' => 'chain-listen',
                'name' => '支付状态同步',
                'cron' => '*/10 * * * * *',
                'status' => '待命',
                'last_run' => '',
            ],
            [
                'key' => 'payout-sync',
                'name' => '退款/代付状态同步',
                'cron' => '*/30 * * * * *',
                'status' => '待命',
                'last_run' => '',
            ],
            [
                'key' => 'callback-retry',
                'name' => '回调重试',
                'cron' => '*/1 * * * *',
                'status' => '待命',
                'last_run' => '',
            ],
        ];
    }

    private static function mergeTasks(array $stored, array $definitions): array
    {
        $map = [];

        foreach ($definitions as $index => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $key = trim((string)($definition['key'] ?? ('task-' . $index)));
            if ($key === '') {
                continue;
            }

            $map[$key] = array_replace([
                'key' => $key,
                'name' => $key,
                'cron' => '* * * * *',
                'status' => '待命',
                'last_run' => '',
            ], $definition);
        }

        foreach ($stored as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = trim((string)($item['key'] ?? ''));
            if ($key === '' || !isset($map[$key])) {
                continue;
            }

            $map[$key]['cron'] = trim((string)($item['cron'] ?? $map[$key]['cron']));
            $map[$key]['status'] = self::sanitizeStatus((string)($item['status'] ?? $map[$key]['status']));
            $map[$key]['last_run'] = trim((string)($item['last_run'] ?? $map[$key]['last_run']));
        }

        return array_values($map);
    }

    private static function sanitizeTasks(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = trim((string)($item['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $normalized[] = [
                ...$item,
                'key' => $key,
                'name' => self::displayNameFor($key, (string)($item['name'] ?? $key)),
                'cron' => trim((string)($item['cron'] ?? '* * * * *')),
                'status' => self::sanitizeStatus((string)($item['status'] ?? '待命')),
                'last_run' => trim((string)($item['last_run'] ?? '')),
            ];
        }

        return $normalized;
    }

    private static function sanitizePluginTasks(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = trim((string)($item['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $pluginName = trim((string)($item['plugin_name'] ?? $item['name'] ?? $key));

            $normalized[] = [
                ...$item,
                'key' => $key,
                'name' => self::displayNameFor($key, $pluginName),
                'plugin_name' => $pluginName,
                'cron' => trim((string)($item['cron'] ?? '*/1 * * * *')),
                'status' => self::sanitizeStatus((string)($item['status'] ?? '待命')),
                'last_run' => trim((string)($item['last_run'] ?? '')),
            ];
        }

        return $normalized;
    }

    private static function loadRuns(): array
    {
        $items = JsonStoreService::load(self::RUN_LOG_KEY, []);
        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = trim((string)($item['task_key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $normalized[] = [
                'task_key' => $key,
                'task_name' => self::displayNameFor($key, (string)($item['task_name'] ?? $key)),
                'operator' => trim((string)($item['operator'] ?? 'system')) ?: 'system',
                'executed_at' => trim((string)($item['executed_at'] ?? '')),
                'status' => self::sanitizeRunStatus((string)($item['status'] ?? 'success')),
                'result' => self::sanitizeResult((string)($item['result'] ?? '执行成功')),
            ];
        }

        JsonStoreService::save(self::RUN_LOG_KEY, array_slice($normalized, 0, 80));
        return $normalized;
    }

    private static function appendRun(array $run): void
    {
        $runs = self::loadRuns();
        array_unshift($runs, [
            'task_key' => trim((string)($run['task_key'] ?? '')),
            'task_name' => self::displayNameFor((string)($run['task_key'] ?? ''), (string)($run['task_name'] ?? '')),
            'operator' => trim((string)($run['operator'] ?? 'system')) ?: 'system',
            'executed_at' => trim((string)($run['executed_at'] ?? date('Y-m-d H:i:s'))),
            'status' => self::sanitizeRunStatus((string)($run['status'] ?? 'success')),
            'result' => self::sanitizeResult((string)($run['result'] ?? '执行成功')),
        ]);

        JsonStoreService::save(self::RUN_LOG_KEY, array_slice($runs, 0, 80));
    }

    private static function displayNameFor(string $key, string $fallback = ''): string
    {
        return match ($key) {
            'order-expire' => '订单过期处理',
            'chain-listen' => '支付状态同步',
            'payout-sync' => '退款/代付状态同步',
            'callback-retry' => '回调重试',
            default => self::pluginTaskName($key, $fallback),
        };
    }

    private static function pluginTaskName(string $key, string $fallback): string
    {
        if (!str_starts_with($key, 'plugin-daemon:')) {
            return trim($fallback) !== '' ? trim($fallback) : $key;
        }

        $pluginCode = trim(substr($key, strlen('plugin-daemon:')));
        $pluginName = trim($fallback);

        if ($pluginName === '' || str_contains($pluginName, '任务轮询') || str_contains($pluginName, '守护任务')) {
            foreach (PluginService::plugins() as $plugin) {
                if (trim((string)($plugin['code'] ?? '')) !== $pluginCode) {
                    continue;
                }

                $pluginName = trim((string)($plugin['name'] ?? $pluginCode));
                break;
            }
        }

        if ($pluginName === '') {
            $pluginName = $pluginCode;
        }

        return $pluginName . ' 守护任务';
    }

    private static function sanitizeStatus(string $status): string
    {
        $value = trim($status);
        $lower = strtolower($value);

        return match (true) {
            $value === '',
            $lower === 'idle',
            $lower === 'ready',
            $lower === 'waiting',
            $lower === 'pending',
            $lower === 'enabled',
            $lower === 'enable' => '待命',
            $lower === 'running',
            $lower === 'processing',
            str_contains($value, '执行中'),
            str_contains($value, '运行中'),
            str_contains($value, '杩愯') => '执行中',
            str_contains($value, '待命'),
            str_contains($value, '寰呭懡'),
            str_contains($value, '启用') => '待命',
            str_contains($value, '鍚敤') => '待命',
            $lower === 'disabled',
            $lower === 'disable',
            $lower === 'stopped',
            $lower === 'stop',
            str_contains($value, '已停用'),
            str_contains($value, '宸插仠鐢'),
            str_contains($value, '停用'),
            str_contains($value, '鍋滅敤') => '已停用',
            $lower === 'success',
            $lower === 'succeeded',
            str_contains($value, '最近成功'),
            str_contains($value, '鎴愬姛') => '最近成功',
            $lower === 'failed',
            $lower === 'error',
            str_contains($value, '最近失败'),
            str_contains($value, '澶辫触') => '最近失败',
            default => $value,
        };
    }

    private static function sanitizeRunStatus(string $status): string
    {
        $raw = trim($status);
        $value = strtolower($raw);

        return match (true) {
            $value === '',
            $value === 'success',
            $value === 'ok',
            $value === 'succeeded',
            str_contains($value, 'success'),
            str_contains($raw, '成功'),
            str_contains($value, '鎴愬姛') => 'success',
            $value === 'failed',
            $value === 'error',
            $value === 'errored',
            str_contains($value, 'fail'),
            str_contains($value, 'error'),
            str_contains($raw, '失败'),
            str_contains($value, '澶辫触') => 'failed',
            default => $value,
        };
    }

    private static function sanitizeResult(string $result): string
    {
        $value = trim($result);
        $lower = strtolower($value);

        return match (true) {
            $value === '',
            $value === 'success',
            $value === 'ok',
            $value === '成功',
            $value === '执行成功',
            $lower === 'succeeded',
            $value === '鎴愬姛' => '执行成功',
            $value === 'failed',
            $value === 'error',
            $value === '失败',
            $value === '执行失败',
            $lower === 'errored',
            $value === '澶辫触' => '执行失败',
            default => $value,
        };
    }

    private static function formatResult(array $metrics): string
    {
        $pairs = [];
        foreach ($metrics as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = 'null';
            }

            $pairs[] = $key . '=' . $value;
        }

        return $pairs === [] ? '执行成功' : implode(', ', $pairs);
    }

    private static function isValidCron(string $cron): bool
    {
        $parts = preg_split('/\s+/', trim($cron));
        if (!is_array($parts)) {
            return false;
        }

        $count = count($parts);
        return $count === 5 || $count === 6;
    }
}
