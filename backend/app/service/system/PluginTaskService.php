<?php

namespace app\service\system;

use RuntimeException;

class PluginTaskService
{
    public static function taskDefinitions(): array
    {
        $items = [];
        foreach (PluginService::plugins() as $plugin) {
            $capabilities = array_map('strval', (array)($plugin['capabilities'] ?? []));
            if (!in_array('daemon', $capabilities, true)) {
                continue;
            }

            $code = trim((string)($plugin['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $items[] = [
                'key' => 'plugin-daemon:' . $code,
                'name' => trim((string)($plugin['name'] ?? $code)) . ' 任务轮询',
                'cron' => '*/1 * * * *',
                'status' => (int)($plugin['status_code'] ?? 0) === 1 ? '运行中' : '已停用',
                'last_run' => '',
                'plugin_code' => $code,
                'plugin_name' => trim((string)($plugin['name'] ?? $code)),
                'kind' => trim((string)($plugin['kind'] ?? '')),
            ];
        }

        return $items;
    }

    public static function runAllDaemonPlugins(): array
    {
        $summary = [
            'plugins' => 0,
            'channels' => 0,
            'processed' => 0,
        ];

        foreach (self::taskDefinitions() as $definition) {
            $result = self::runDaemonPlugin((string)$definition['plugin_code']);
            $summary['plugins']++;
            $summary['channels'] += (int)($result['channels'] ?? 0);
            $summary['processed'] += (int)($result['processed'] ?? 0);
        }

        return $summary;
    }

    public static function runTask(string $key): array
    {
        if (!str_starts_with($key, 'plugin-daemon:')) {
            throw new RuntimeException('Unsupported plugin task key');
        }

        $pluginCode = trim(substr($key, strlen('plugin-daemon:')));
        if ($pluginCode === '') {
            throw new RuntimeException('Plugin code is required');
        }

        return self::runDaemonPlugin($pluginCode);
    }

    public static function runDaemonPlugin(string $pluginCode): array
    {
        $pluginCode = PluginCodeService::normalize($pluginCode);
        if ($pluginCode === '') {
            return ['channels' => 0, 'processed' => 0];
        }

        $channels = self::loadDaemonChannels($pluginCode);
        $processed = 0;
        foreach ($channels as $channel) {
            $processed += self::runChannelOnce($pluginCode, $channel);
        }

        return [
            'channels' => count($channels),
            'processed' => $processed,
        ];
    }

    private static function loadDaemonChannels(string $pluginCode): array
    {
        $items = [];

        if (database_available()) {
            try {
                $rows = \app\model\MerchantChannel::select()->toArray();
                foreach ($rows as $row) {
                    $config = is_array($row['config'] ?? null) ? $row['config'] : [];
                    if (PluginCodeService::normalize((string)($config['plugin_code'] ?? '')) !== $pluginCode) {
                        continue;
                    }

                    $pluginConfig = is_array($config['plugin_config'] ?? null) ? $config['plugin_config'] : [];
                    $settings = PluginRuntimeService::settingsFor($pluginCode);
                    $display = trim((string)($config['display_value'] ?? $config['payment_address'] ?? ''));

                    $items[] = array_replace($settings, $pluginConfig, [
                        'id' => (int)($row['id'] ?? 0),
                        'merchant_id' => (int)($row['merchant_id'] ?? 0),
                        'plugin' => legacy_plugin_directory_name($pluginCode),
                        'plugin_code' => $pluginCode,
                        'type' => (string)($config['method_code'] ?? ''),
                        'channel_code' => (string)($config['method_code'] ?? ''),
                        'showname' => (string)($config['method_name'] ?? ''),
                        'name' => (string)($config['plugin_name'] ?? $pluginCode),
                        'appid' => trim((string)($pluginConfig['appid'] ?? $config['address'] ?? $display)),
                        'appkey' => (string)($pluginConfig['appkey'] ?? ''),
                        'appsecret' => (string)($pluginConfig['appsecret'] ?? ''),
                        'appurl' => (string)($pluginConfig['appurl'] ?? ''),
                        'appmchid' => (string)($pluginConfig['appmchid'] ?? ''),
                        'bottoken' => (string)($pluginConfig['bottoken'] ?? ''),
                        'botid' => (string)($pluginConfig['botid'] ?? ''),
                        'xiaoshu' => (string)($pluginConfig['xiaoshu'] ?? ''),
                        'payment_address' => (string)($config['payment_address'] ?? $display),
                        'display_value' => $display,
                        'address' => (string)($pluginConfig['address'] ?? $config['address'] ?? $display),
                        'status' => (int)($row['status'] ?? 0),
                    ]);
                }
            } catch (\Throwable) {
            }
        }

        if ($items !== []) {
            return array_values(array_filter($items, static fn(array $item): bool => (int)($item['status'] ?? 0) === 1));
        }

        $records = JsonStoreService::load('merchant_channels', []);
        $channels = [];
        foreach ($records as $record) {
            foreach ((array)($record['items'] ?? []) as $item) {
                if ((int)($item['status_code'] ?? 0) !== 1) {
                    continue;
                }

                $config = is_array($item['config'] ?? null) ? $item['config'] : [];
                if (PluginCodeService::normalize((string)($config['plugin_code'] ?? $item['plugin_code'] ?? '')) !== $pluginCode) {
                    continue;
                }

                $pluginConfig = is_array($config['plugin_config'] ?? null) ? $config['plugin_config'] : [];
                $settings = PluginRuntimeService::settingsFor($pluginCode);
                $display = trim((string)($config['display_value'] ?? $config['payment_address'] ?? $item['display_value'] ?? ''));

                $channels[] = array_replace($settings, $pluginConfig, [
                    'id' => (int)($item['id'] ?? 0),
                    'merchant_id' => (int)($record['merchant_id'] ?? 0),
                    'plugin' => legacy_plugin_directory_name($pluginCode),
                    'plugin_code' => $pluginCode,
                    'type' => (string)($config['method_code'] ?? $item['method_code'] ?? ''),
                    'channel_code' => (string)($config['method_code'] ?? $item['method_code'] ?? ''),
                    'showname' => (string)($config['method_name'] ?? $item['method_name'] ?? ''),
                    'name' => (string)($config['plugin_name'] ?? $item['plugin_name'] ?? $pluginCode),
                    'appid' => trim((string)($pluginConfig['appid'] ?? $config['address'] ?? $display)),
                    'appkey' => (string)($pluginConfig['appkey'] ?? ''),
                    'appsecret' => (string)($pluginConfig['appsecret'] ?? ''),
                    'appurl' => (string)($pluginConfig['appurl'] ?? ''),
                    'appmchid' => (string)($pluginConfig['appmchid'] ?? ''),
                    'bottoken' => (string)($pluginConfig['bottoken'] ?? ''),
                    'botid' => (string)($pluginConfig['botid'] ?? ''),
                    'xiaoshu' => (string)($pluginConfig['xiaoshu'] ?? ''),
                    'payment_address' => (string)($config['payment_address'] ?? $display),
                    'display_value' => $display,
                    'address' => (string)($pluginConfig['address'] ?? $config['address'] ?? $display),
                    'status' => (int)($item['status_code'] ?? 0),
                ]);
            }
        }

        return $channels;
    }

    private static function runChannelOnce(string $pluginCode, array $channel): int
    {
        $class = 'plugins\\payment\\' . legacy_plugin_directory_name($pluginCode) . '\\' . self::pluginClassName($pluginCode);
        if (!class_exists($class)) {
            return 0;
        }

        $plugin = new $class($channel);
        if (!method_exists($plugin, 'cron')) {
            return 0;
        }

        try {
            return (int)$plugin->cron($channel);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function pluginClassName(string $pluginCode): string
    {
        $base = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', legacy_plugin_directory_name($pluginCode))));
        return $base . 'Plugin';
    }
}
