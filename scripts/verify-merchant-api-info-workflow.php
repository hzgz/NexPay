<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'verify-bootstrap.php';

use app\service\system\MerchantApiService;
use app\service\system\ResourceDataService;

$stores = [
    'merchant_accounts',
    'merchant_auth_users',
    'merchant_api_profiles',
];

$backups = backupJsonStores($stores);
$result = [];
$ok = false;

try {
    $merchant = bootstrapTestMerchant('apiinfo');
    $merchantId = (int)$merchant['merchant_id'];
    $userId = (int)$merchant['user_id'];

    $before = ResourceDataService::merchantApiInfo($merchantId, $userId);
    $afterReset = MerchantApiService::resetMd5Key($merchantId, $userId);
    $generated = MerchantApiService::generateRsaKeyPair($merchantId, $userId);
    $afterMode = MerchantApiService::saveSignMode($merchantId, $userId, ['sign_mode' => 'rsa_only']);

    $result = [
        'merchant_id' => $merchantId,
        'before_key' => (string)($before['mch_key'] ?? ''),
        'after_reset_key' => (string)($afterReset['mch_key'] ?? ''),
        'generated_public_key_length' => strlen((string)($generated['generated_public_key'] ?? '')),
        'generated_private_key_length' => strlen((string)($generated['generated_private_key'] ?? '')),
        'sign_mode' => (string)($afterMode['sign_mode'] ?? ''),
        'platform_public_key_length' => strlen((string)($afterMode['platform_public_key'] ?? '')),
    ];

    $ok = $result['before_key'] !== ''
        && $result['after_reset_key'] !== ''
        && $result['before_key'] !== $result['after_reset_key']
        && $result['generated_public_key_length'] > 100
        && $result['generated_private_key_length'] > 100
        && $result['sign_mode'] === 'rsa_only'
        && $result['platform_public_key_length'] > 100;

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    restoreJsonStores($backups);
}

exit($ok ? 0 : 1);
