<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\service\payment\OrderService;
use app\service\payment\PluginExecutorService;
use app\service\system\JsonStoreService;
use app\service\system\MerchantChannelService;
use app\service\system\PaymentMetaService;
use app\service\system\PluginCodeService;
use app\service\system\PluginService;

$stores = [
    'merchant_accounts',
    'merchant_auth_users',
    'merchant_channels',
    'plugins',
];

$backups = backupJsonStores($stores);
$ok = false;

try {
    $merchant = bootstrapTestMerchant('xferrouting');
    $merchantId = (int)($merchant['merchant_id'] ?? 0);
    [$strictPlugin, $bridgePlugin, $targetType, $strictMethod, $bridgeMethod] = selectTransferRoutingPlugins();

    $plugins = PluginService::plugins();
    $patchedPlugins = [];
    foreach ($plugins as $plugin) {
        $code = PluginCodeService::normalize((string)($plugin['code'] ?? ''));
        if ($code === PluginCodeService::normalize((string)($strictPlugin['code'] ?? ''))) {
            $plugin['transfer_methods'] = [alternateTransferType($targetType)];
        }
        if ($code === PluginCodeService::normalize((string)($bridgePlugin['code'] ?? ''))) {
            $plugin['transfer_methods'] = [$targetType];
        }

        $patchedPlugins[] = $plugin;
    }
    JsonStoreService::save('plugins', $patchedPlugins);

    MerchantChannelService::saveItem($merchantId, [
        'channel_name' => 'Strict transfer channel',
        'method_code' => $strictMethod,
        'plugin_code' => (string)($strictPlugin['code'] ?? ''),
        'daily_limit' => '100000.00',
        'daily_count_limit' => '100',
        'single_min_amount' => '1.00',
        'single_max_amount' => '100000.00',
        'rate' => '0.88',
        'status_code' => 1,
        'plugin_config' => buildScriptPluginConfig($strictPlugin, $strictMethod),
    ]);

    MerchantChannelService::saveItem($merchantId, [
        'channel_name' => 'Bridge transfer channel',
        'method_code' => $bridgeMethod,
        'plugin_code' => (string)($bridgePlugin['code'] ?? ''),
        'daily_limit' => '100000.00',
        'daily_count_limit' => '100',
        'single_min_amount' => '1.00',
        'single_max_amount' => '100000.00',
        'rate' => '0.89',
        'status_code' => 1,
        'plugin_config' => buildScriptPluginConfig($bridgePlugin, $bridgeMethod),
    ]);

    $selected = OrderService::gatewayTransferChannel($merchantId, $targetType);
    $selectedPluginCode = PluginCodeService::normalize((string)($selected['plugin_code'] ?? ''));
    $selectedMethodCode = PaymentMetaService::normalizeMethodCode((string)($selected['channel_code'] ?? $selected['type'] ?? ''));
    $transferOptions = OrderService::gatewayTransferMethodOptions($merchantId);
    $targetOption = null;
    foreach ($transferOptions as $option) {
        if (PaymentMetaService::normalizeMethodCode((string)($option['value'] ?? $option['code'] ?? '')) === $targetType) {
            $targetOption = $option;
            break;
        }
    }

    $expectedStrictTransferType = alternateTransferType($targetType);
    $ok = $selectedPluginCode === PluginCodeService::normalize((string)($bridgePlugin['code'] ?? ''))
        && $selectedMethodCode === $bridgeMethod
        && is_array($targetOption)
        && in_array(
            PluginCodeService::normalize((string)($bridgePlugin['code'] ?? '')),
            array_map(static fn(mixed $item): string => PluginCodeService::normalize((string)$item), (array)($targetOption['plugin_codes'] ?? [])),
            true
        )
        && !in_array(
            PluginCodeService::normalize((string)($strictPlugin['code'] ?? '')),
            array_map(static fn(mixed $item): string => PluginCodeService::normalize((string)$item), (array)($targetOption['plugin_codes'] ?? [])),
            true
        );

    echo json_encode([
        'merchant_id' => $merchantId,
        'target_transfer_type' => $targetType,
        'strict_channel' => [
            'plugin_code' => (string)($strictPlugin['code'] ?? ''),
            'method_code' => $strictMethod,
            'declared_transfer_methods' => [$expectedStrictTransferType],
        ],
        'bridge_channel' => [
            'plugin_code' => (string)($bridgePlugin['code'] ?? ''),
            'method_code' => $bridgeMethod,
            'declared_transfer_methods' => [$targetType],
        ],
        'selected_channel' => [
            'plugin_code' => $selectedPluginCode,
            'method_code' => $selectedMethodCode,
            'channel_name' => (string)($selected['name'] ?? ''),
        ],
        'target_transfer_option' => $targetOption,
        'ok' => $ok,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    restoreJsonStores($backups);
}

exit($ok ? 0 : 1);

function selectTransferRoutingPlugins(): array
{
    $plugins = PluginService::plugins();

    foreach ($plugins as $strictPlugin) {
        if ((int)($strictPlugin['status_code'] ?? 0) !== 1) {
            continue;
        }
        if (($strictPlugin['group'] ?? 'pay') !== 'pay') {
            continue;
        }

        $strictPluginCode = PluginCodeService::normalize((string)($strictPlugin['code'] ?? ''));
        if ($strictPluginCode === '' || !(PluginExecutorService::capability($strictPluginCode)['transfer'] ?? false)) {
            continue;
        }

        $strictMethods = normalizedPaymentMethods($strictPlugin);
        foreach ($strictMethods as $targetType) {
            foreach ($plugins as $bridgePlugin) {
                if ((int)($bridgePlugin['status_code'] ?? 0) !== 1) {
                    continue;
                }
                if (($bridgePlugin['group'] ?? 'pay') !== 'pay') {
                    continue;
                }

                $bridgePluginCode = PluginCodeService::normalize((string)($bridgePlugin['code'] ?? ''));
                if ($bridgePluginCode === '' || $bridgePluginCode === $strictPluginCode) {
                    continue;
                }
                if (!(PluginExecutorService::capability($bridgePluginCode)['transfer'] ?? false)) {
                    continue;
                }

                $bridgeMethods = normalizedPaymentMethods($bridgePlugin);
                if ($bridgeMethods === []) {
                    continue;
                }

                $strictMethod = $targetType;
                $bridgeMethod = preferredBridgeMethod($bridgeMethods, $targetType);
                if ($bridgeMethod === '') {
                    continue;
                }

                return [$strictPlugin, $bridgePlugin, $targetType, $strictMethod, $bridgeMethod];
            }
        }
    }

    throw new RuntimeException('no two transfer-capable plugins available for routing verification');
}

function normalizedPaymentMethods(array $plugin): array
{
    $methods = [];
    foreach ((array)($plugin['payment_methods'] ?? []) as $method) {
        $methodCode = PaymentMetaService::normalizeMethodCode((string)$method);
        if ($methodCode !== '') {
            $methods[$methodCode] = $methodCode;
        }
    }

    return array_values($methods);
}

function alternateTransferType(string $targetType): string
{
    foreach (['alipay', 'bank', 'wxpay', 'qqpay', 'usdttrc20'] as $candidate) {
        $normalizedCandidate = PaymentMetaService::normalizeMethodCode($candidate);
        if ($normalizedCandidate !== '' && $normalizedCandidate !== $targetType) {
            return $normalizedCandidate;
        }
    }

    throw new RuntimeException('unable to choose alternate transfer type');
}

function preferredBridgeMethod(array $bridgeMethods, string $targetType): string
{
    foreach ($bridgeMethods as $method) {
        if ($method !== $targetType) {
            return $method;
        }
    }

    return $bridgeMethods[0] ?? '';
}
