<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';

use app\service\system\AccountService;
use app\service\system\AdminMerchantService;
use app\service\system\JsonStoreService;
use app\service\system\MerchantAuthService;
use app\service\system\ResourceDataService;

$accountStore = 'merchant_accounts';
$authStore = 'merchant_auth_users';
$profileStore = 'merchant_api_profiles';
$originalAccounts = JsonStoreService::load($accountStore, []);
$originalAuthUsers = JsonStoreService::load($authStore, []);
$originalProfiles = JsonStoreService::load($profileStore, []);

$suffix = (string)random_int(1000, 9999);
$username = 'identity' . $suffix;
$password = 'Identity#' . $suffix . 'A';
$result = [];
$ok = false;

try {
    $created = AdminMerchantService::create([
        'merchant_name' => 'Identity Merchant ' . $suffix,
        'contact_name' => 'Identity Contact',
        'username' => $username,
        'email' => $username . '@example.com',
        'phone' => '1390000' . $suffix,
        'password' => $password,
        'group_name' => 'Default',
        'rate' => '0.80',
        'status_code' => 1,
    ]);

    $merchantId = (int)($created['merchant_id'] ?? 0);
    $userId = (int)($created['user_id'] ?? 0);
    $login = MerchantAuthService::login([
        'username' => $username,
        'password' => $password,
    ], '127.0.0.1');
    $credential = AccountService::merchantCredentialById($merchantId) ?? [];
    $apiInfo = ResourceDataService::merchantApiInfo($merchantId, $userId);
    $adminItems = ResourceDataService::adminMerchants()['items'] ?? [];
    $currentAccounts = JsonStoreService::load($accountStore, []);
    $adminItem = null;

    foreach ($adminItems as $item) {
        if (
            (int)($item['id'] ?? 0) === $merchantId
            || (string)($item['merchant_no'] ?? '') === (string)$merchantId
            || (string)($item['username'] ?? '') === $username
        ) {
            $adminItem = $item;
            break;
        }
    }

    $result = [
        'merchant_id' => $merchantId,
        'user_id' => $userId,
        'login_id' => (int)($login['id'] ?? 0),
        'login_merchant_id' => (int)($login['merchant_id'] ?? 0),
        'credential_appid' => (string)($credential['appid'] ?? ''),
        'api_pid' => (string)($apiInfo['pid'] ?? ''),
        'api_user_id' => (int)($apiInfo['user_id'] ?? 0),
        'api_merchant_uid' => (int)($apiInfo['merchant_uid'] ?? 0),
        'admin_items_total' => count($adminItems),
        'admin_item_ids' => array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $adminItems),
        'account_store_ids' => array_map(
            static fn(array $item): int => (int)($item['merchant_id'] ?? $item['id'] ?? 0),
            array_values(array_filter($currentAccounts, 'is_array'))
        ),
        'admin_item_id' => (int)($adminItem['id'] ?? 0),
        'admin_item_username' => (string)($adminItem['username'] ?? ''),
        'admin_item_appid' => (string)($adminItem['appid'] ?? ''),
        'admin_item_merchant_no' => (string)($adminItem['merchant_no'] ?? ''),
        'api_has_legacy_appid' => array_key_exists('appid', $apiInfo),
    ];

    $expectedId = (string)$merchantId;
    $ok = $merchantId > 0
        && $merchantId === $userId
        && $merchantId === (int)($login['id'] ?? 0)
        && $merchantId === (int)($login['merchant_id'] ?? 0)
        && (string)($credential['appid'] ?? '') === $expectedId
        && (string)($apiInfo['pid'] ?? '') === $expectedId
        && (int)($apiInfo['user_id'] ?? 0) === $merchantId
        && (int)($apiInfo['merchant_uid'] ?? 0) === $merchantId
        && (string)($adminItem['appid'] ?? '') === $expectedId
        && (string)($adminItem['merchant_no'] ?? '') === $expectedId
        && !array_key_exists('appid', $apiInfo);

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    JsonStoreService::save($accountStore, $originalAccounts);
    JsonStoreService::save($authStore, $originalAuthUsers);
    JsonStoreService::save($profileStore, $originalProfiles);
}

exit($ok ? 0 : 1);
