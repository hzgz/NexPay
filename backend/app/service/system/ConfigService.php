<?php

namespace app\service\system;

use app\model\SystemConfig;
use app\service\payment\SignService;
use Throwable;

class ConfigService
{
    private const STORE_KEY = 'system_configs_local';

    public static function get(string $key, mixed $default = null): mixed
    {
        $envMap = [
            'app_name' => env('APP_NAME', 'NexPay 聚合支付系统'),
            'app_url' => env('APP_URL', 'http://127.0.0.1:5174'),
            'platform_public_key' => env('PLATFORM_PUBLIC_KEY', ''),
            'platform_private_key' => env('PLATFORM_PRIVATE_KEY', ''),
            'internal_refund_secret' => env('INTERNAL_REFUND_SECRET', env('TOKEN_SECRET', 'change-this-token-secret')),
        ];

        if (database_available()) {
            try {
                $record = SystemConfig::where('key', $key)->find();
                if ($record && trim((string)$record->value) !== '') {
                    return self::normalizeValue($record->value);
                }
            } catch (Throwable) {
            }
        }

        $local = self::localStore();
        if (array_key_exists($key, $local)) {
            return self::normalizeValue($local[$key]);
        }

        $settingsValue = self::settingsFallbackValue($key);
        if ($settingsValue !== null && $settingsValue !== '') {
            return self::normalizeValue($settingsValue);
        }

        if (array_key_exists($key, $envMap) && $envMap[$key] !== '') {
            return self::normalizeValue($envMap[$key]);
        }

        return $default;
    }

    public static function all(array $defaults = []): array
    {
        $keys = array_unique(array_merge(array_keys($defaults), [
            'app_name',
            'app_url',
            'platform_public_key',
            'platform_private_key',
            'internal_refund_secret',
        ]));

        $items = [];
        foreach ($keys as $key) {
            $items[$key] = self::get($key, $defaults[$key] ?? null);
        }

        return $items;
    }

    public static function save(array $payload): array
    {
        if (database_available()) {
            try {
                foreach ($payload as $key => $value) {
                    if (!is_string($key) || $key === '') {
                        continue;
                    }

                    $record = SystemConfig::where('key', $key)->find();
                    if ($record) {
                        $record->value = self::stringifyValue($value);
                        $record->save();
                        continue;
                    }

                    $record = new SystemConfig();
                    $record->key = $key;
                    $record->value = self::stringifyValue($value);
                    $record->description = '';
                    $record->save();
                }
            } catch (Throwable) {
                JsonStoreService::save(self::STORE_KEY, array_replace(self::localStore(), $payload));
            }
        } else {
            JsonStoreService::save(self::STORE_KEY, array_replace(self::localStore(), $payload));
        }

        return self::all($payload);
    }

    public static function gatewayBaseUrl(): string
    {
        return rtrim((string)self::get('app_url', 'http://127.0.0.1:5174'), '/');
    }

    public static function platformPublicKey(): string
    {
        $publicKey = (string)self::get('platform_public_key', '');
        $privateKey = (string)self::get('platform_private_key', '');

        if ($publicKey !== '' && $privateKey !== '' && self::keysMatch($publicKey, $privateKey)) {
            return $publicKey;
        }

        $derived = self::derivePublicKey($privateKey);
        return $derived !== '' ? $derived : $publicKey;
    }

    public static function platformPrivateKey(): string
    {
        return (string)self::get('platform_private_key', '');
    }

    public static function internalRefundSecret(): string
    {
        return (string)self::get(
            'internal_refund_secret',
            env('INTERNAL_REFUND_SECRET', env('TOKEN_SECRET', 'change-this-token-secret'))
        );
    }

    protected static function normalizeValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === 'true') {
            return true;
        }
        if ($trimmed === 'false') {
            return false;
        }
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        if ($trimmed !== '' && is_numeric($trimmed)) {
            return str_contains($trimmed, '.') ? (float)$trimmed : (int)$trimmed;
        }

        return $trimmed;
    }

    protected static function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return trim((string)$value);
    }

    protected static function localStore(): array
    {
        return JsonStoreService::load(self::STORE_KEY, []);
    }

    private static function settingsFallbackValue(string $key): mixed
    {
        $settings = JsonStoreService::load('settings', []);

        return match ($key) {
            'app_name' => $settings['basic']['site_name'] ?? null,
            'app_url' => $settings['basic']['site_url'] ?? ($settings['basic']['gateway_base_url'] ?? null),
            'platform_public_key' => $settings['payment']['platform_public_key'] ?? null,
            'platform_private_key' => $settings['payment']['platform_private_key'] ?? null,
            'internal_refund_secret' => $settings['payment']['internal_refund_secret'] ?? null,
            default => null,
        };
    }

    private static function keysMatch(string $publicKey, string $privateKey): bool
    {
        try {
            $publicResource = openssl_pkey_get_public(SignService::normalizePublicKey($publicKey));
            $privateResource = openssl_pkey_get_private(SignService::normalizePrivateKey($privateKey));
            if (!$publicResource || !$privateResource) {
                return false;
            }

            $publicDetails = openssl_pkey_get_details($publicResource);
            $privateDetails = openssl_pkey_get_details($privateResource);
            return isset($publicDetails['rsa']['n'], $privateDetails['rsa']['n'])
                && $publicDetails['rsa']['n'] === $privateDetails['rsa']['n'];
        } catch (Throwable) {
            return false;
        }
    }

    private static function derivePublicKey(string $privateKey): string
    {
        if ($privateKey === '') {
            return '';
        }

        try {
            $privateResource = openssl_pkey_get_private(SignService::normalizePrivateKey($privateKey));
            if (!$privateResource) {
                return '';
            }

            $details = openssl_pkey_get_details($privateResource);
            return (string)($details['key'] ?? '');
        } catch (Throwable) {
            return '';
        }
    }
}
