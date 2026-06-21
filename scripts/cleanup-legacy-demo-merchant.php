<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';

use app\service\system\JsonStoreService;

function isLegacyDemoMerchantRecord(array $record): bool
{
    return (int)($record['merchant_id'] ?? $record['id'] ?? 0) === 1
        && trim((string)($record['username'] ?? '')) === 'merchant001'
        && trim((string)($record['email'] ?? '')) === 'merchant@example.com'
        && trim((string)($record['phone'] ?? '')) === '13800138000'
        && trim((string)($record['mch_key'] ?? '')) === 'epay_v1_key_123456';
}

function filterMerchantRows(array $rows, int $merchantId): array
{
    $filtered = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if ((int)($row['merchant_id'] ?? 0) === $merchantId) {
            continue;
        }

        $filtered[] = $row;
    }

    return $filtered;
}

function filterMerchantGroups(array $rows, int $merchantId): array
{
    $filtered = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if ((int)($row['merchant_id'] ?? 0) === $merchantId) {
            continue;
        }

        $filtered[] = $row;
    }

    return $filtered;
}

function filterMerchantLogs(array $rows, int $merchantId, string $username): array
{
    $filtered = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rowMerchantId = (int)($row['merchant_id'] ?? $row['id'] ?? 0);
        $operator = trim((string)($row['operator'] ?? $row['username'] ?? ''));
        if ($rowMerchantId === $merchantId || $operator === $username) {
            continue;
        }

        $filtered[] = $row;
    }

    return $filtered;
}

$merchantAccounts = JsonStoreService::load('merchant_accounts', []);
$merchantAuthUsers = JsonStoreService::load('merchant_auth_users', []);
$legacyAuth = is_array($merchantAuthUsers['merchant001'] ?? null) ? $merchantAuthUsers['merchant001'] : null;
$legacyAccount = null;

foreach ($merchantAccounts as $row) {
    if (!is_array($row)) {
        continue;
    }

    if (isLegacyDemoMerchantRecord($row)) {
        $legacyAccount = $row;
        break;
    }
}

$shouldCleanup = ($legacyAuth !== null && isLegacyDemoMerchantRecord($legacyAuth))
    || ($legacyAccount !== null && isLegacyDemoMerchantRecord($legacyAccount));

$result = [
    'should_cleanup' => $shouldCleanup,
    'merchant_id' => 1,
    'username' => 'merchant001',
    'cleaned_stores' => [],
];

if ($shouldCleanup) {
    unset($merchantAuthUsers['merchant001']);
    JsonStoreService::save('merchant_auth_users', $merchantAuthUsers);
    $result['cleaned_stores'][] = 'merchant_auth_users';

    JsonStoreService::save('merchant_accounts', filterMerchantRows($merchantAccounts, 1));
    $result['cleaned_stores'][] = 'merchant_accounts';

    $profiles = JsonStoreService::load('merchant_api_profiles', []);
    if (is_array($profiles) && array_key_exists('1', $profiles)) {
        unset($profiles['1']);
        JsonStoreService::save('merchant_api_profiles', $profiles);
        $result['cleaned_stores'][] = 'merchant_api_profiles';
    }

    foreach ([
        'merchant_channels',
        'orders_local',
        'callback_queue_local',
        'fund_flows_local',
        'refunds_local',
        'transfers_local',
        'settlements_local',
        'merchant_packages',
    ] as $store) {
        $rows = JsonStoreService::load($store, []);
        $filtered = filterMerchantGroups($rows, 1);
        if ($filtered !== $rows) {
            JsonStoreService::save($store, $filtered);
            $result['cleaned_stores'][] = $store;
        }
    }

    $logs = JsonStoreService::load('merchant_operation_logs', []);
    $filteredLogs = filterMerchantLogs($logs, 1, 'merchant001');
    if ($filteredLogs !== $logs) {
        JsonStoreService::save('merchant_operation_logs', $filteredLogs);
        $result['cleaned_stores'][] = 'merchant_operation_logs';
    }
}

$result['remaining_account_ids'] = array_values(array_map(
    static fn(array $row): int => (int)($row['merchant_id'] ?? $row['id'] ?? 0),
    array_filter(JsonStoreService::load('merchant_accounts', []), 'is_array')
));
$result['remaining_auth_usernames'] = array_values(array_filter(array_map(
    static fn($key, $row): string => is_array($row) ? trim((string)($row['username'] ?? $key)) : '',
    array_keys(JsonStoreService::load('merchant_auth_users', [])),
    JsonStoreService::load('merchant_auth_users', [])
)));

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
