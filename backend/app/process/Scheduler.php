<?php

namespace app\process;

use app\service\system\TaskService;
use support\Log;
use Workerman\Crontab\Crontab;
use Workerman\Timer;
use Throwable;

class Scheduler
{
    /**
     * @var array<string, Crontab>
     */
    private array $crontabs = [];

    private string $scheduleHash = '';

    public function onWorkerStart(): void
    {
        $this->reloadSchedule();
        Timer::add(60, function (): void {
            $this->reloadSchedule();
        });
        Log::info('NexPay scheduler booted.');
    }

    private function reloadSchedule(): void
    {
        $definitions = TaskService::scheduledTasks();
        $hash = md5(json_encode($definitions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        if ($hash === $this->scheduleHash) {
            return;
        }

        foreach ($this->crontabs as $crontab) {
            $crontab->destroy();
        }
        $this->crontabs = [];

        foreach ($definitions as $definition) {
            $key = trim((string)($definition['key'] ?? ''));
            $cron = trim((string)($definition['cron'] ?? ''));
            $name = trim((string)($definition['name'] ?? $key));
            if ($key === '' || $cron === '') {
                continue;
            }

            $this->crontabs[$key] = new Crontab($cron, function () use ($key): void {
                $this->runTask($key);
            }, $name);
        }

        $this->scheduleHash = $hash;
        Log::info('NexPay scheduler reloaded: ' . json_encode([
            'tasks' => array_map(static function (array $item): array {
                return [
                    'key' => (string)($item['key'] ?? ''),
                    'cron' => (string)($item['cron'] ?? ''),
                ];
            }, $definitions),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function runTask(string $key): void
    {
        try {
            TaskService::run($key, 'scheduler');
        } catch (Throwable $exception) {
            Log::error('NexPay scheduler task failed [' . $key . ']: ' . $exception->getMessage());
        }
    }
}
