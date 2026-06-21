<?php

declare(strict_types=1);

use app\service\payment\PluginExecutorService;
use app\service\payment\SignService;
use app\service\system\JsonStoreService;
use app\service\system\MerchantAuthService;
use app\service\system\MerchantApiService;
use app\service\system\PaymentMetaService;
use app\service\system\PluginCodeService;
use app\service\system\PluginSchemaService;
use app\service\system\PluginService;
use app\service\system\ResourceDataService;

function backupJsonStores(array $stores): array
{
    $backups = [];

    foreach ($stores as $store) {
        $backups[$store] = JsonStoreService::load($store, []);
    }

    return $backups;
}

function restoreJsonStores(array $backups): void
{
    foreach ($backups as $store => $payload) {
        JsonStoreService::save((string)$store, is_array($payload) ? $payload : []);
    }
}

function bootstrapTestMerchant(string $prefix = 'verify', array $overrides = []): array
{
    $prefix = strtolower(trim($prefix));
    $prefix = preg_replace('/[^a-z0-9]+/', '', $prefix) ?: 'verify';
    $prefix = substr($prefix, 0, 12);
    $suffix = date('His') . random_int(1000, 9999);
    $username = substr($prefix . $suffix, 0, 24);
    $password = (string)($overrides['password'] ?? 'Passw0rd!123');
    $merchantName = (string)($overrides['merchant_name'] ?? ('Verify Merchant ' . $suffix));
    $contactName = (string)($overrides['contact_name'] ?? 'Verify User');
    $email = (string)($overrides['email'] ?? ($username . '@example.com'));
    $phone = (string)($overrides['phone'] ?? ('139' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT)));

    $record = MerchantAuthService::registerByAdmin(array_replace([
        'merchant_name' => $merchantName,
        'contact_name' => $contactName,
        'username' => $username,
        'email' => $email,
        'phone' => $phone,
        'password' => $password,
        'group_name' => (string)($overrides['group_name'] ?? ''),
        'status_code' => (int)($overrides['status_code'] ?? 1),
        'rate' => (string)($overrides['rate'] ?? '0.80'),
    ], $overrides));

    return [
        'merchant_id' => (int)($record['merchant_id'] ?? 0),
        'user_id' => (int)($record['id'] ?? ($record['merchant_id'] ?? 0)),
        'username' => $username,
        'password' => $password,
        'merchant_name' => $merchantName,
        'contact_name' => $contactName,
        'email' => $email,
        'phone' => $phone,
    ];
}

function ensureScriptMerchantApiCredentials(int $merchantId, int $userId): array
{
    $merchantInfo = ResourceDataService::merchantApiInfo($merchantId, $userId);
    $privateKey = trim((string)($merchantInfo['merchant_private_key'] ?? $merchantInfo['rsa_private_key'] ?? ''));

    if ($privateKey === '') {
        $generated = MerchantApiService::generateRsaKeyPair($merchantId, $userId);
        $merchantInfo = is_array($generated['info'] ?? null)
            ? $generated['info']
            : ResourceDataService::merchantApiInfo($merchantId, $userId);
        $privateKey = trim((string)($generated['generated_private_key'] ?? $merchantInfo['merchant_private_key'] ?? $merchantInfo['rsa_private_key'] ?? ''));
    }

    if ($privateKey === '') {
        throw new RuntimeException('merchant RSA private key missing after generation');
    }

    return [$merchantInfo, (string)($merchantInfo['merchant_id'] ?? $merchantId), $privateKey];
}

function signedScriptV2Payload(array $payload, string $pid, string $privateKey): array
{
    $payload['pid'] = $pid;
    $payload['timestamp'] = (string)time();
    $payload['sign_type'] = 'RSA';
    $payload['sign'] = SignService::rsaSign($payload, $privateKey);

    return $payload;
}

function resolveFirstUsableChannelDefinition(): array
{
    $methods = PluginService::methods();
    $plugins = PluginService::plugins();

    foreach ($methods as $method) {
        if ((int)($method['status_code'] ?? 0) !== 1) {
            continue;
        }

        $methodCode = PaymentMetaService::normalizeMethodCode((string)($method['code'] ?? ''));
        if ($methodCode === '') {
            continue;
        }

        foreach ($plugins as $plugin) {
            if ((int)($plugin['status_code'] ?? 0) !== 1) {
                continue;
            }
            if (($plugin['group'] ?? 'pay') !== 'pay') {
                continue;
            }

            $pluginCode = PluginCodeService::normalize((string)($plugin['code'] ?? ''));
            if ($pluginCode === '' || !scriptPluginSupportsMethod($plugin, $methodCode)) {
                continue;
            }

            return [$methodCode, $pluginCode, buildScriptPluginConfig($plugin, $methodCode)];
        }
    }

    throw new RuntimeException('no active method/plugin pair available');
}

function scriptPluginSupportsMethod(array $plugin, string $methodCode): bool
{
    foreach ((array)($plugin['payment_methods'] ?? []) as $pluginMethod) {
        if (PaymentMetaService::normalizeMethodCode((string)$pluginMethod) === $methodCode) {
            return true;
        }
    }

    return false;
}

function buildScriptPluginConfig(array $plugin, string $methodCode): array
{
    $config = [];

    foreach ((array)($plugin['settings_schema'] ?? []) as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !PluginSchemaService::isFieldVisible($field, $methodCode, $config)) {
            continue;
        }

        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $default = $plugin['default_settings'][$key] ?? $field['default'] ?? null;
        if ($default !== null && trim((string)$default) !== '') {
            $config[$key] = is_array($default) ? $default : (string)$default;
            continue;
        }

        $config[$key] = match ($type) {
            'number' => '1',
            'select', 'radio' => firstScriptOptionValue($field['options'] ?? null),
            'checkbox', 'switch' => '1',
            default => 'verify-placeholder',
        };
    }

    return $config;
}

function firstScriptOptionValue(mixed $options): string
{
    if (is_array($options)) {
        $first = reset($options);
        if (is_array($first)) {
            return (string)($first['value'] ?? $first['key'] ?? $first['id'] ?? '1');
        }

        if ($first !== false) {
            return (string)$first;
        }
    }

    return '1';
}
