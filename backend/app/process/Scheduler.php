<?php

namespace app\process;

use app\service\payment\CallbackService;
use app\service\payment\OrderService;
use app\service\system\PluginTaskService;
use support\Log;
use Workerman\Crontab\Crontab;

class Scheduler
{
    public function onWorkerStart(): void
    {
        new Crontab('*/1 * * * *', static function (): void {
            OrderService::expirePendingOrders();
        });

        new Crontab('*/10 * * * * *', static function (): void {
            OrderService::syncPendingOrders();
        });

        new Crontab('*/1 * * * *', static function (): void {
            CallbackService::dispatchPendingCallbacks();
        });

        new Crontab('*/1 * * * *', static function (): void {
            PluginTaskService::runAllDaemonPlugins();
        });

        Log::info('NexPay scheduler booted.');
    }
}
