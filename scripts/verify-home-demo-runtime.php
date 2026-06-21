<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\service\home\DemoCheckoutService;
use app\service\payment\OrderService;
use app\service\system\MerchantChannelService;
use app\service\system\SettingsService;

$stores = [
    'merchant_accounts',
    'merchant_auth_users',
    'merchant_api_profiles',
    'merchant_channels',
    'orders_local',
    'callback_queue_local',
    'settings',
];

$backups = backupJsonStores($stores);
$result = [];
$ok = false;

try {
    bootstrapTestMerchant('guard', ['status_code' => 0]);
    $merchant = bootstrapTestMerchant('hometest');
    $merchantId = (int)($merchant['merchant_id'] ?? 0);
    [$methodCode, $pluginCode, $pluginConfig] = resolveFirstUsableChannelDefinition();

    MerchantChannelService::saveItem($merchantId, [
        'channel_name' => 'Homepage Runtime Test',
        'method_code' => $methodCode,
        'plugin_code' => $pluginCode,
        'plugin_config' => $pluginConfig,
        'display_value' => 'https://example.com/qrcode/homepage-runtime-test.png',
        'daily_limit' => '50000.00',
        'daily_count_limit' => 100,
        'single_min_amount' => '0.01',
        'single_max_amount' => '50000.00',
        'status_code' => 1,
        'validate_plugin_config' => true,
    ]);

    SettingsService::save([
        'payment' => [
            'payment_test_enabled' => true,
            'test_default_amount' => '12.34',
            'test_auto_complete' => true,
        ],
    ]);

    $config = DemoCheckoutService::config();
    $systemOrder = DemoCheckoutService::create([
        'provider' => 'system',
        'method' => $methodCode,
        'amount' => '12.34',
        'subject' => 'Homepage Runtime System Test',
        'trade_no' => 'home-runtime-system-' . date('His'),
    ]);
    $v1Order = DemoCheckoutService::create([
        'provider' => 'epay_v1',
        'method' => $methodCode,
        'amount' => '23.45',
        'subject' => 'Homepage Runtime V1 Test',
        'trade_no' => 'home-runtime-v1-' . date('His'),
    ]);
    $v2Order = DemoCheckoutService::create([
        'provider' => 'epay_v2',
        'method' => $methodCode,
        'amount' => '34.56',
        'subject' => 'Homepage Runtime V2 Test',
        'trade_no' => 'home-runtime-v2-' . date('His'),
    ]);

    $systemStored = OrderService::findByTradeNo((string)($systemOrder['trade_no'] ?? ''));
    $v1Stored = OrderService::findByTradeNo((string)($v1Order['trade_no'] ?? ''));
    $v2Stored = OrderService::findByTradeNo((string)($v2Order['trade_no'] ?? ''));

    $result = [
        'merchant_id' => $merchantId,
        'config_enabled' => (bool)($config['enabled'] ?? false),
        'config_merchant_id' => (string)($config['merchant_id'] ?? ''),
        'config_method_codes' => array_values(array_map(
            static fn(array $item): string => (string)($item['code'] ?? ''),
            is_array($config['methods'] ?? null) ? $config['methods'] : []
        )),
        'selected_method' => $methodCode,
        'system_order' => [
            'trade_no' => (string)($systemStored->trade_no ?? ''),
            'merchant_id' => (int)($systemStored->merchant_id ?? 0),
            'out_trade_no' => (string)($systemStored->out_trade_no ?? ''),
            'pay_url' => (string)($systemOrder['pay_url'] ?? ''),
        ],
        'v1_order' => [
            'trade_no' => (string)($v1Stored->trade_no ?? ''),
            'merchant_id' => (int)($v1Stored->merchant_id ?? 0),
            'out_trade_no' => (string)($v1Stored->out_trade_no ?? ''),
            'pay_url' => (string)($v1Order['pay_url'] ?? ''),
        ],
        'v2_order' => [
            'trade_no' => (string)($v2Stored->trade_no ?? ''),
            'merchant_id' => (int)($v2Stored->merchant_id ?? 0),
            'out_trade_no' => (string)($v2Stored->out_trade_no ?? ''),
            'pay_url' => (string)($v2Order['pay_url'] ?? ''),
        ],
    ];

    $ok = $result['config_enabled']
        && $result['config_merchant_id'] === (string)$merchantId
        && in_array($methodCode, $result['config_method_codes'], true)
        && $result['system_order']['merchant_id'] === $merchantId
        && $result['v1_order']['merchant_id'] === $merchantId
        && $result['v2_order']['merchant_id'] === $merchantId
        && $result['system_order']['pay_url'] !== ''
        && $result['v1_order']['pay_url'] !== ''
        && $result['v2_order']['pay_url'] !== '';

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    restoreJsonStores($backups);
}

exit($ok ? 0 : 1);
