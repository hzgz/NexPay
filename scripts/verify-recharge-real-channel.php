<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\exception\BusinessException;
use app\service\payment\LocalOrderStore;
use app\service\system\MerchantChannelService;
use app\service\system\MerchantFundService;
use app\service\system\ResourceDataService;

$stores = [
    'merchant_accounts',
    'merchant_auth_users',
    'merchant_channels',
    'orders_local',
];

$backups = backupJsonStores($stores);
$message = '';
$code = null;
$created = null;
$originalOrderCount = count(array_filter($backups['orders_local'] ?? [], 'is_array'));
$before = 0;
$afterProbe = 0;
$restored = false;
$optionCodes = [];
$merchantId = 0;
$ok = false;

try {
    $merchant = bootstrapTestMerchant('recharge');
    $merchantId = (int)$merchant['merchant_id'];
    [$methodCode, $pluginCode, $pluginConfig] = resolveFirstUsableChannelDefinition();

    MerchantChannelService::saveItem($merchantId, [
        'channel_name' => 'Recharge Verify Channel',
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

    $before = count(LocalOrderStore::allOrders());
    $fundData = ResourceDataService::merchantFunds($merchantId);
    $rechargeOptions = is_array($fundData['recharge_options'] ?? null) ? $fundData['recharge_options'] : [];
    $optionCodes = array_values(array_filter(array_map(
        static fn(array $item): string => trim((string)($item['method_code'] ?? '')),
        array_filter($rechargeOptions, 'is_array')
    )));

    try {
        $created = MerchantFundService::createRechargeOrder($merchantId, [
            'amount' => '1.00',
            'client_ip' => '127.0.0.1',
        ]);
    } catch (BusinessException $exception) {
        $message = $exception->getMessage();
        $code = $exception->errorCode();
    }

    $afterProbe = count(LocalOrderStore::allOrders());
} finally {
    restoreJsonStores($backups);
    $restored = count(LocalOrderStore::allOrders()) === $originalOrderCount;
}

$hasUsableChannel = is_array($created);
$ok = $restored && (
    (
        $hasUsableChannel
        && $afterProbe === $before + 1
        && in_array((string)($created['channel_code'] ?? ''), $optionCodes, true)
        && ($created['trade_no'] ?? '') !== ''
        && ($created['out_trade_no'] ?? '') !== ''
        && ($created['pay_url'] ?? '') !== ''
    )
    || (
        !$hasUsableChannel
        && $afterProbe === $before
        && $optionCodes === []
        && $message === '当前商户暂无可用支付通道'
    )
);

echo json_encode([
    'merchant_id' => $merchantId,
    'created' => $created,
    'recharge_option_codes' => $optionCodes,
    'before_orders' => $before,
    'after_probe_orders' => $afterProbe,
    'after_restore_orders' => count(LocalOrderStore::allOrders()),
    'scenario' => $hasUsableChannel ? 'usable_channel_created_checkout_order' : 'no_usable_channel_rejected',
    'message' => $message,
    'code' => $code,
    'restored' => $restored,
    'ok' => $ok,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
