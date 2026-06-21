<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';

use app\service\system\AccountService;
use app\service\system\JsonStoreService;
use app\service\system\MerchantAuthService;

$accountStore = 'merchant_accounts';
$authStore = 'merchant_auth_users';
$originalAccounts = JsonStoreService::load($accountStore, []);
$originalAuthUsers = JsonStoreService::load($authStore, []);

$suffix = (string)random_int(1000, 9999);
$username = 'normalize' . $suffix;
$password = 'Normalize#' . $suffix . 'A';
$staleAppId = 'legacy-' . $suffix;
$result = [];
$ok = false;

try {
    $created = MerchantAuthService::registerByAdmin([
        'merchant_name' => 'Normalize Merchant ' . $suffix,
        'contact_name' => 'Normalize Contact',
        'username' => $username,
        'email' => $username . '@example.com',
        'phone' => '1390000' . $suffix,
        'password' => $password,
        'group_name' => 'Default',
        'rate' => '0.80',
        'status_code' => 1,
    ]);
    $merchantId = (int)($created['merchant_id'] ?? $created['id'] ?? 0);

    $authUsers = JsonStoreService::load($authStore, []);
    if (is_array($authUsers[$username] ?? null)) {
        $authUsers[$username]['appid'] = $staleAppId;
        JsonStoreService::save($authStore, $authUsers);
    }

    $accounts = JsonStoreService::load($accountStore, []);
    foreach ($accounts as $index => $account) {
        if (!is_array($account)) {
            continue;
        }

        if ((int)($account['merchant_id'] ?? $account['id'] ?? 0) === $merchantId) {
            $accounts[$index]['appid'] = $staleAppId;
            break;
        }
    }
    JsonStoreService::save($accountStore, $accounts);

    $login = MerchantAuthService::login([
        'username' => $username,
        'password' => $password,
    ], '127.0.0.1');
    $credential = AccountService::merchantCredentialById($merchantId) ?? [];

    $authUsers = JsonStoreService::load($authStore, []);
    $accounts = JsonStoreService::load($accountStore, []);
    $savedAuth = is_array($authUsers[$username] ?? null) ? $authUsers[$username] : [];
    $savedAccount = [];

    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }

        if ((int)($account['merchant_id'] ?? $account['id'] ?? 0) === $merchantId) {
            $savedAccount = $account;
            break;
        }
    }

    $expectedId = (string)$merchantId;
    $result = [
        'merchant_id' => $merchantId,
        'login_id' => (int)($login['id'] ?? 0),
        'login_merchant_id' => (int)($login['merchant_id'] ?? 0),
        'credential_appid' => (string)($credential['appid'] ?? ''),
        'auth_store_appid' => (string)($savedAuth['appid'] ?? ''),
        'account_store_appid' => (string)($savedAccount['appid'] ?? ''),
    ];

    $ok = (int)($login['id'] ?? 0) === $merchantId
        && (int)($login['merchant_id'] ?? 0) === $merchantId
        && (string)($credential['appid'] ?? '') === $expectedId
        && (string)($savedAuth['appid'] ?? '') === $expectedId
        && (string)($savedAccount['appid'] ?? '') === $expectedId;

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    JsonStoreService::save($accountStore, $originalAccounts);
    JsonStoreService::save($authStore, $originalAuthUsers);
}

exit($ok ? 0 : 1);
