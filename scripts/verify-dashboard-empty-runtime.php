<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\service\system\DashboardDataService;
use app\service\system\JsonStoreService;

$stores = [
    'merchant_accounts',
    'merchant_auth_users',
    'merchant_channels',
    'merchant_api_profiles',
    'orders_local',
    'announcements',
];

$backups = backupJsonStores($stores);
$result = [];
$ok = false;

try {
    foreach ($stores as $store) {
        JsonStoreService::save($store, []);
    }

    $emptyAdmin = DashboardDataService::adminOverview();
    $merchant = bootstrapTestMerchant('dashsanity');
    $merchantId = (int)($merchant['merchant_id'] ?? 0);
    $merchantOverview = DashboardDataService::merchantOverview($merchantId);

    $result = [
        'empty_admin' => [
            'merchant_count' => (int)($emptyAdmin['cards']['merchant_count'] ?? -1),
            'order_count' => (int)($emptyAdmin['cards']['order_count'] ?? -1),
            'total_amount' => (string)($emptyAdmin['cards']['total_amount'] ?? ''),
            'latest_orders_count' => count(is_array($emptyAdmin['latest_orders'] ?? null) ? $emptyAdmin['latest_orders'] : []),
            'trend_count' => count(is_array($emptyAdmin['trend'] ?? null) ? $emptyAdmin['trend'] : []),
        ],
        'merchant_overview' => [
            'merchant_id' => $merchantId,
            'balance' => (string)($merchantOverview['cards']['balance'] ?? ''),
            'today_amount' => (string)($merchantOverview['cards']['today_amount'] ?? ''),
            'today_orders' => (int)($merchantOverview['cards']['today_orders'] ?? -1),
            'pending_orders' => (int)($merchantOverview['cards']['pending_orders'] ?? -1),
            'latest_orders_count' => count(is_array($merchantOverview['latest_orders'] ?? null) ? $merchantOverview['latest_orders'] : []),
            'announcements_count' => count(is_array($merchantOverview['announcements'] ?? null) ? $merchantOverview['announcements'] : []),
        ],
    ];

    $ok = $result['empty_admin']['merchant_count'] === 0
        && $result['empty_admin']['order_count'] === 0
        && $result['empty_admin']['total_amount'] === '0.00'
        && $result['empty_admin']['latest_orders_count'] === 0
        && $result['empty_admin']['trend_count'] === 7
        && $result['merchant_overview']['merchant_id'] === $merchantId
        && $result['merchant_overview']['balance'] === '0.00'
        && $result['merchant_overview']['today_amount'] === '0.00'
        && $result['merchant_overview']['today_orders'] === 0
        && $result['merchant_overview']['pending_orders'] === 0
        && $result['merchant_overview']['latest_orders_count'] === 0;

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    restoreJsonStores($backups);
}

exit($ok ? 0 : 1);
