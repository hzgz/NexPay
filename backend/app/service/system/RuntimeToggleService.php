<?php

namespace app\service\system;

class RuntimeToggleService
{
    public static function pluginRuntimeEnabled(): bool
    {
        $value = strtolower(trim((string) env('ENABLE_PLUGIN_RUNTIME', '0')));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
