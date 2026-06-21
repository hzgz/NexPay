<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\service\payment\OrderService;
use app\service\system\MerchantChannelService;
use app\service\system\ResourceDataService;

$stores = [
    'merchant_accounts',
    'merchant_auth_users',
    'merchant_channels',
];

$backups = backupJsonStores($stores);
$merchantId = 0;
$ok = false;

try {
    $merchant = bootstrapTestMerchant('withdraw');
    $merchantId = (int)$merchant['merchant_id'];
    [$methodCode, $pluginCode, $pluginConfig] = resolveFirstUsableChannelDefinition();

    MerchantChannelService::saveItem($merchantId, [
        'channel_name' => 'Withdraw Verify Channel',
        'method_code' => $methodCode,
        'plugin_code' => $pluginCode,
        'daily_limit' => '100000.00',
        'daily_count_limit' => '100',
        'single_min_amount' => '1.00',
        'single_max_amount' => '100000.00',
        'rate' => '0.88',
        'status_code' => 1,
        'plugin_config' => $pluginConfig,
        'validate_plugin_config' => true,
    ]);

    $fundData = ResourceDataService::merchantFunds($merchantId);
    $withdrawOptions = is_array($fundData['withdraw_options'] ?? null) ? $fundData['withdraw_options'] : [];
    $accountTypes = is_array($withdrawOptions['account_types'] ?? null) ? $withdrawOptions['account_types'] : [];
    $actualTransferTypes = OrderService::gatewayTransferMethodOptions($merchantId);

    $accountTypeValues = array_values(array_filter(array_map(static function ($item): string {
        if (is_string($item)) {
            return trim($item);
        }

        if (is_array($item)) {
            return trim((string)($item['value'] ?? $item['code'] ?? ''));
        }

        return '';
    }, $accountTypes)));

    $actualTransferValues = array_values(array_filter(array_map(
        static fn(array $item): string => trim((string)($item['value'] ?? $item['code'] ?? '')),
        array_filter($actualTransferTypes, 'is_array')
    )));

    $manualFallback = ['alipay', 'bank', 'usdt'];

    if ($actualTransferValues !== []) {
        $ok = ($withdrawOptions['transfer_enabled'] ?? false) === true
            && ($withdrawOptions['review_mode'] ?? '') === 'manual'
            && $accountTypeValues === $actualTransferValues;
    } else {
        $ok = ($withdrawOptions['transfer_enabled'] ?? false) === false
            && ($withdrawOptions['review_mode'] ?? '') === 'manual'
            && $accountTypeValues === $manualFallback;
    }

    echo json_encode([
        'merchant_id' => $merchantId,
        'withdraw_option_values' => $accountTypeValues,
        'actual_transfer_values' => $actualTransferValues,
        'transfer_enabled' => (bool)($withdrawOptions['transfer_enabled'] ?? false),
        'review_mode' => (string)($withdrawOptions['review_mode'] ?? ''),
        'ok' => $ok,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    restoreJsonStores($backups);
}

exit($ok ? 0 : 1);
