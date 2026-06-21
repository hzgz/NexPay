<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';

use app\model\Order;
use app\service\system\JsonStoreService;
use app\service\system\MerchantAuthService;
use app\service\system\MerchantChannelService;
use app\service\system\PaymentMetaService;
use app\service\system\PluginCodeService;
use app\service\system\PluginSchemaService;
use app\service\system\PluginService;
use app\service\system\SettingsService;
use app\service\system\SystemBusinessPaymentService;

$stores = [
    'settings',
    'merchant_auth_users',
    'merchant_accounts',
    'merchant_channels',
    'merchant_api_profiles',
    'orders_local',
];

$backups = [];
foreach ($stores as $store) {
    $backups[$store] = JsonStoreService::load($store, []);
}

$createdTradeNos = [];
$ok = false;
$result = [];

try {
    foreach ($stores as $store) {
        JsonStoreService::save($store, $store === 'settings' ? $backups[$store] : []);
    }

    $settings = SettingsService::all(false);
    $settings['payment'] = is_array($settings['payment'] ?? null) ? $settings['payment'] : [];
    $settings['payment']['system_checkout'] = is_array($settings['payment']['system_checkout'] ?? null)
        ? $settings['payment']['system_checkout']
        : [];
    $settings['payment']['frontend_test'] = is_array($settings['payment']['frontend_test'] ?? null)
        ? $settings['payment']['frontend_test']
        : [];

    $collector = createMerchant('collector', 1);
    $target = createMerchant('target', 0);
    [$methodCode, $pluginCode, $pluginConfig] = resolveUsableChannelDefinition();

    MerchantChannelService::saveItem($collector['merchant_id'], [
        'channel_name' => 'Collector Fixed Channel',
        'method_code' => $methodCode,
        'plugin_code' => $pluginCode,
        'display_value' => 'collector-fixed-qr',
        'daily_limit' => '999999.00',
        'daily_count_limit' => '999',
        'single_min_amount' => '1.00',
        'single_max_amount' => '100000.00',
        'rate' => '0.80',
        'status_code' => 1,
        'plugin_config' => $pluginConfig,
        'validate_plugin_config' => true,
    ]);

    MerchantChannelService::saveItem($collector['merchant_id'], [
        'channel_name' => 'Collector Latest Channel',
        'method_code' => $methodCode,
        'plugin_code' => $pluginCode,
        'display_value' => 'collector-latest-qr',
        'daily_limit' => '999999.00',
        'daily_count_limit' => '999',
        'single_min_amount' => '1.00',
        'single_max_amount' => '100000.00',
        'rate' => '0.81',
        'status_code' => 1,
        'plugin_config' => $pluginConfig,
        'validate_plugin_config' => true,
    ]);

    $collectorChannels = MerchantChannelService::all($collector['merchant_id'])['items'] ?? [];
    $fixedChannelId = findChannelIdByName($collectorChannels, 'Collector Fixed Channel');
    $latestChannelId = findChannelIdByName($collectorChannels, 'Collector Latest Channel');

    $settings['payment']['system_checkout'] = array_replace($settings['payment']['system_checkout'], [
        'provider' => 'system',
        'mode' => 'v2',
        'payment_url' => SettingsService::all(false)['payment']['system_checkout']['payment_url'] ?? '',
        'merchant_id' => '',
        'merchant_md5' => '',
        'platform_public_key' => '',
        'merchant_private_key' => '',
        'carrier_merchant_id' => (string)$collector['merchant_id'],
        'carrier_channel_id' => (string)$fixedChannelId,
        'carrier_channel_code' => $methodCode,
    ]);
    $settings['payment']['frontend_test'] = array_replace($settings['payment']['frontend_test'], [
        'provider' => 'system',
        'mode' => 'v2',
        'merchant_id' => '',
        'merchant_md5' => '',
        'platform_public_key' => '',
        'merchant_private_key' => '',
        'carrier_merchant_id' => (string)$collector['merchant_id'],
        'carrier_channel_id' => (string)$fixedChannelId,
        'carrier_channel_code' => $methodCode,
    ]);
    JsonStoreService::save('settings', $settings);

    $payload = [
        'merchant_id' => $target['merchant_id'],
        'amount' => '9.90',
        'subject' => 'Collector Routing Verify',
        'channel_code' => $methodCode,
        'request_payload' => [
            '_meta' => [
                'business' => 'merchant_register_fee',
                'merchant_id' => $target['merchant_id'],
            ],
        ],
    ];

    $context = SystemBusinessPaymentService::inspectContext('system_checkout', $payload);
    $order = SystemBusinessPaymentService::create('system_checkout', $payload);
    $orderRow = row($order);
    $createdTradeNos[] = (string)($orderRow['trade_no'] ?? '');

    $requestPayload = is_array($orderRow['request_payload'] ?? null) ? $orderRow['request_payload'] : [];
    $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
    $legacyChannel = is_array($requestPayload['_legacy_channel'] ?? null) ? $requestPayload['_legacy_channel'] : [];
    $legacyConfig = is_array($legacyChannel['config'] ?? null) ? $legacyChannel['config'] : [];

    $checks = [
        'context_uses_collector_channel' => (bool)($context['ok'] ?? false) === true
            && (string)($context['source'] ?? '') === 'collector_channel'
            && (int)($context['carrier_merchant_id'] ?? 0) === $collector['merchant_id']
            && (int)($context['legacy_channel_snapshot']['merchant_channel_id'] ?? 0) === $fixedChannelId,
        'order_routes_to_target_merchant' => (int)($orderRow['merchant_id'] ?? 0) === $target['merchant_id'],
        'order_routes_to_configured_collector_channel' => (int)($orderRow['merchant_channel_id'] ?? 0) === $fixedChannelId
            && (int)($meta['carrier_merchant_id'] ?? 0) === $collector['merchant_id']
            && (string)($meta['gateway_source'] ?? '') === 'collector_channel',
        'configured_channel_beats_latest_channel' => $fixedChannelId > 0
            && $latestChannelId > 0
            && $fixedChannelId !== $latestChannelId
            && (int)($orderRow['merchant_channel_id'] ?? 0) === $fixedChannelId,
        'payment_address_snapshot_preserved' => (string)($orderRow['payment_address'] ?? '') === 'collector-fixed-qr'
            && (string)($legacyConfig['display_value'] ?? '') === 'collector-fixed-qr',
    ];

    $result = [
        'collector' => $collector,
        'target' => $target,
        'method_code' => $methodCode,
        'fixed_channel_id' => $fixedChannelId,
        'latest_channel_id' => $latestChannelId,
        'context' => $context,
        'order' => [
            'trade_no' => (string)($orderRow['trade_no'] ?? ''),
            'merchant_id' => (int)($orderRow['merchant_id'] ?? 0),
            'merchant_channel_id' => (int)($orderRow['merchant_channel_id'] ?? 0),
            'channel_code' => (string)($orderRow['channel_code'] ?? ''),
            'payment_address' => (string)($orderRow['payment_address'] ?? ''),
            'meta' => $meta,
            'legacy_channel' => [
                'merchant_channel_id' => (int)($legacyChannel['merchant_channel_id'] ?? 0),
                'channel_code' => (string)($legacyChannel['channel_code'] ?? ''),
                'display_value' => (string)($legacyConfig['display_value'] ?? ''),
            ],
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
    foreach ($stores as $store) {
        JsonStoreService::save($store, $backups[$store]);
    }

    if (database_available()) {
        try {
            foreach (array_filter($createdTradeNos) as $tradeNo) {
                Order::where('trade_no', $tradeNo)->delete();
            }
        } catch (Throwable) {
        }
    }

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit($ok ? 0 : 1);

function createMerchant(string $prefix, int $statusCode): array
{
    $suffix = $prefix . date('His') . random_int(1000, 9999);
    $payload = MerchantAuthService::registerByAdmin([
        'merchant_name' => 'Verify ' . $prefix,
        'contact_name' => 'Verifier',
        'username' => $suffix,
        'email' => $suffix . '@example.com',
        'phone' => '139' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'password' => 'Passw0rd!123',
        'status_code' => $statusCode,
        'rate' => '0.80',
    ]);

    return [
        'id' => (int)($payload['id'] ?? 0),
        'merchant_id' => (int)($payload['merchant_id'] ?? 0),
        'username' => (string)($payload['username'] ?? ''),
        'status_code' => $statusCode,
    ];
}

function resolveUsableChannelDefinition(): array
{
    $methods = PluginService::methods();
    $plugins = PluginService::plugins();

    foreach ($methods as $method) {
        if ((int)($method['status_code'] ?? 0) !== 1) {
            continue;
        }

        $methodCode = PaymentMetaService::normalizeMethodCode((string)($method['code'] ?? ''));
        foreach ($plugins as $plugin) {
            if ((int)($plugin['status_code'] ?? 0) !== 1) {
                continue;
            }
            if (($plugin['group'] ?? 'pay') !== 'pay') {
                continue;
            }

            $supported = false;
            foreach ((array)($plugin['payment_methods'] ?? []) as $pluginMethod) {
                if (PaymentMetaService::normalizeMethodCode((string)$pluginMethod) === $methodCode) {
                    $supported = true;
                    break;
                }
            }

            if (!$supported) {
                continue;
            }

            $pluginCode = PluginCodeService::normalize((string)($plugin['code'] ?? ''));
            if ($pluginCode === '') {
                continue;
            }

            return [$methodCode, $pluginCode, buildPluginConfig($plugin, $methodCode)];
        }
    }

    throw new RuntimeException('no active method/plugin pair available for collector routing verification');
}

function buildPluginConfig(array $plugin, string $methodCode): array
{
    $config = [];

    foreach ((array)($plugin['settings_schema'] ?? []) as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !PluginSchemaService::isFieldVisible($field, $methodCode, $config)) {
            continue;
        }

        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $default = $plugin['default_settings'][$key] ?? $field['default'] ?? null;
        if ($default !== null && trim((string)$default) !== '') {
            $config[$key] = (string)$default;
            continue;
        }

        $config[$key] = match ($type) {
            'number' => '1',
            'select', 'radio' => firstOptionValue($field['options'] ?? null),
            'checkbox', 'switch' => '1',
            default => 'verify-placeholder',
        };
    }

    return $config;
}

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

function findChannelIdByName(array $items, string $name): int
{
    foreach ($items as $item) {
        if ((string)($item['channel_name'] ?? '') === $name) {
            return (int)($item['id'] ?? 0);
        }
    }

    return 0;
}

function row(mixed $record): array
{
    if (is_array($record)) {
        return $record;
    }

    if (is_object($record) && method_exists($record, 'toArray')) {
        return $record->toArray();
    }

    if (is_object($record)) {
        return json_decode((string)json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) ?: [];
    }

    return [];
}
