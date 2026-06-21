<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';

use app\exception\BusinessException;
use app\service\payment\LocalOrderStore;
use app\service\system\JsonStoreService;
use app\service\system\MerchantChannelService;
use app\service\system\PluginSchemaService;
use app\service\system\PluginService;

$channelStore = 'merchant_channels';
$orderStore = 'orders_local';
$originalChannels = JsonStoreService::load($channelStore, []);
$originalOrders = JsonStoreService::load($orderStore, []);
$merchantId = 88000000 + random_int(100000, 899999);
$ok = false;
$result = [];

try {
    $methods = PluginService::methods();
    $plugins = PluginService::plugins();
    $methodCode = '';
    $plugin = null;

    foreach ($methods as $method) {
        if ((int)($method['status_code'] ?? 0) !== 1) {
            continue;
        }

        $candidateMethod = (string)($method['code'] ?? '');
        foreach ($plugins as $pluginRow) {
            if ((int)($pluginRow['status_code'] ?? 0) !== 1) {
                continue;
            }
            if (($pluginRow['group'] ?? 'pay') !== 'pay') {
                continue;
            }
            foreach ((array)($pluginRow['payment_methods'] ?? []) as $pluginMethod) {
                if (PluginService::normalizeMethodCode((string)$pluginMethod) === PluginService::normalizeMethodCode($candidateMethod)) {
                    $methodCode = PluginService::normalizeMethodCode($candidateMethod);
                    $plugin = $pluginRow;
                    break 3;
                }
            }
        }
    }

    if ($methodCode === '' || !is_array($plugin)) {
        throw new RuntimeException('未找到可用于验证的启用支付方式和支付插件');
    }

    $created = MerchantChannelService::saveItem($merchantId, [
        'channel_name' => '验证通道 A',
        'method_code' => $methodCode,
        'plugin_code' => (string)$plugin['code'],
        'daily_limit' => '9.99',
        'daily_count_limit' => '2',
        'single_min_amount' => '1.00',
        'single_max_amount' => '5.00',
        'rate' => '0.88',
        'status_code' => 1,
    ]);

    $items = $created['items'] ?? [];
    $first = $items[0] ?? [];
    $channelId = (int)($first['id'] ?? 0);

    $updated = MerchantChannelService::saveItem($merchantId, [
        'id' => $channelId,
        'channel_name' => '验证通道 B',
        'method_code' => $methodCode,
        'plugin_code' => (string)$plugin['code'],
        'daily_limit' => '19.99',
        'daily_count_limit' => '3',
        'single_min_amount' => '2.00',
        'single_max_amount' => '6.00',
        'rate' => '0.89',
        'status_code' => 1,
        'plugin_config' => [],
    ]);

    $configError = '';
    try {
        MerchantChannelService::saveItem($merchantId, [
            'id' => $channelId,
            'channel_name' => '验证通道 B',
            'method_code' => $methodCode,
            'plugin_code' => (string)$plugin['code'],
            'daily_limit' => '19.99',
            'daily_count_limit' => '3',
            'single_min_amount' => '2.00',
            'single_max_amount' => '6.00',
            'rate' => '0.89',
            'status_code' => 1,
            'plugin_config' => [],
            'validate_plugin_config' => true,
        ]);
    } catch (BusinessException $exception) {
        $configError = $exception->getMessage();
    }

    $pluginConfig = [];
    foreach ((array)($plugin['settings_schema'] ?? []) as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !PluginSchemaService::isFieldVisible($field, $methodCode, $pluginConfig)) {
            continue;
        }

        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $default = $plugin['default_settings'][$key] ?? $field['default'] ?? null;
        if ($default !== null && trim((string)$default) !== '') {
            $pluginConfig[$key] = (string)$default;
            continue;
        }

        $pluginConfig[$key] = match ($type) {
            'number' => '1',
            'select', 'radio' => firstOptionValue($field['options'] ?? null),
            'checkbox', 'switch' => '1',
            default => 'verify-placeholder',
        };
    }

    MerchantChannelService::saveItem($merchantId, [
        'id' => $channelId,
        'channel_name' => '验证通道 B',
        'method_code' => $methodCode,
        'plugin_code' => (string)$plugin['code'],
        'daily_limit' => '19.99',
        'daily_count_limit' => '3',
        'single_min_amount' => '2.00',
        'single_max_amount' => '6.00',
        'rate' => '0.89',
        'status_code' => 1,
        'plugin_config' => $pluginConfig,
        'validate_plugin_config' => true,
    ]);

    $limitError = '';
    try {
        MerchantChannelService::testItem($merchantId, $channelId, [
            'amount' => '1.00',
            'subject' => '限额验证订单',
        ]);
    } catch (BusinessException $exception) {
        $limitError = $exception->getMessage();
    }

    $afterOrders = count(LocalOrderStore::allOrders());
    $updatedFirst = $updated['items'][0] ?? [];
    $result = [
        'method_code' => $methodCode,
        'plugin_code' => (string)$plugin['code'],
        'created_count' => count($items),
        'channel_id' => $channelId,
        'channel_name' => (string)($updatedFirst['channel_name'] ?? ''),
        'daily_limit' => (string)($updatedFirst['daily_limit'] ?? ''),
        'daily_count_limit' => (int)($updatedFirst['daily_count_limit'] ?? -1),
        'single_min_amount' => (string)($updatedFirst['single_min_amount'] ?? ''),
        'single_max_amount' => (string)($updatedFirst['single_max_amount'] ?? ''),
        'config_error' => $configError,
        'limit_error' => $limitError,
        'orders_after_probe' => $afterOrders,
    ];

    $ok = $result['created_count'] === 1
        && $result['channel_id'] > 0
        && $result['channel_name'] === '验证通道 B'
        && $result['daily_limit'] === '19.99'
        && $result['daily_count_limit'] === 3
        && $result['single_min_amount'] === '2.00'
        && $result['single_max_amount'] === '6.00'
        && $result['limit_error'] === '测试金额低于通道单笔最小限额';

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    JsonStoreService::save($channelStore, $originalChannels);
    JsonStoreService::save($orderStore, $originalOrders);
}

exit($ok ? 0 : 1);

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
