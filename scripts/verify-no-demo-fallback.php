<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\OrderService;
use app\service\system\JsonStoreService;

function nextUnusedMerchantId(): int
{
    $maxId = 0;

    foreach (JsonStoreService::load('merchant_accounts', []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $maxId = max($maxId, (int)($row['merchant_id'] ?? $row['id'] ?? 0));
    }

    foreach (JsonStoreService::load('merchant_auth_users', []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $maxId = max($maxId, (int)($row['merchant_id'] ?? $row['id'] ?? 0));
    }

    return max(1000000, $maxId + 1000);
}

function captureBusinessException(callable $callback): array
{
    try {
        $callback();

        return [
            'thrown' => false,
            'message' => '',
            'code' => null,
        ];
    } catch (BusinessException $exception) {
        return [
            'thrown' => true,
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode(),
        ];
    }
}

$unusedMerchantId = nextUnusedMerchantId();

$invalidPid = captureBusinessException(static function () use ($unusedMerchantId): void {
    OrderService::gatewayMerchantByPid((string)$unusedMerchantId);
});

$submit2WithoutOrder = captureBusinessException(static function (): void {
    OrderService::createFromV1Fallback([
        'type' => 'alipay',
    ]);
});

$submit2WithoutMerchant = captureBusinessException(static function () use ($unusedMerchantId): void {
    OrderService::createFromV1Fallback([
        'pid' => (string)$unusedMerchantId,
        'out_trade_no' => 'NO-FALLBACK-' . date('YmdHis'),
    ]);
});

$ok = $invalidPid['thrown']
    && (int)$invalidPid['code'] === StatusCode::NOT_FOUND
    && $submit2WithoutOrder['thrown']
    && $submit2WithoutMerchant['thrown']
    && (int)$submit2WithoutMerchant['code'] === StatusCode::NOT_FOUND;

echo json_encode([
    'unused_merchant_id' => $unusedMerchantId,
    'invalid_pid' => $invalidPid,
    'submit2_without_order' => $submit2WithoutOrder,
    'submit2_without_merchant' => $submit2WithoutMerchant,
    'ok' => $ok,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
