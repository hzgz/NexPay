<?php

namespace app\bootstrap;

use app\service\system\PluginRuntimeService;
use app\service\system\RuntimeToggleService;
use Webman\Bootstrap;
use Workerman\Worker;

class PluginBootstrap implements Bootstrap
{
    public static function start(?Worker $worker): void
    {
        if (!RuntimeToggleService::pluginRuntimeEnabled()) {
            return;
        }

        \app\service\system\PluginService::all();
        PluginRuntimeService::ensureSettingsStorage();
        PluginRuntimeService::bootEnabledProviders();
    }
}
