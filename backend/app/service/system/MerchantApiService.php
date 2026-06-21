<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\model\Merchant;
use app\service\payment\SignService;
use Throwable;

class MerchantApiService
{
    private const PROFILE_STORE = 'merchant_api_profiles';

    public static function signMode(int $merchantId): string
    {
        if ($merchantId <= 0) {
            return 'md5_rsa';
        }

        $profiles = self::profiles();
        $profile = is_array($profiles[(string)$merchantId] ?? null) ? $profiles[(string)$merchantId] : [];
        $mode = strtolower(trim((string)($profile['sign_mode'] ?? 'md5_rsa')));

        return in_array($mode, ['md5_rsa', 'rsa_only'], true) ? $mode : 'md5_rsa';
    }

    public static function saveSignMode(int $merchantId, int $userId, array $payload): array
    {
        if ($merchantId <= 0) {
            throw new BusinessException('商户身份无效', StatusCode::UNAUTHORIZED);
        }

        $mode = strtolower(trim((string)($payload['sign_mode'] ?? '')));
        if (!in_array($mode, ['md5_rsa', 'rsa_only'], true)) {
            throw new BusinessException('签名方式无效', StatusCode::VALIDATION_ERROR);
        }

        if ($mode === 'rsa_only') {
            $info = ResourceDataService::merchantApiInfo($merchantId, $userId);
            if (trim((string)($info['merchant_public_key'] ?? '')) === '') {
                throw new BusinessException('请先生成 RSA 密钥对后再切换到仅 RSA 签名', StatusCode::VALIDATION_ERROR);
            }
        }

        $profiles = self::profiles();
        $profiles[(string)$merchantId] = [
            'sign_mode' => $mode,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        JsonStoreService::save(self::PROFILE_STORE, $profiles);
        self::syncLocalStores($merchantId, $userId, ['api_sign_mode' => $mode]);

        return ResourceDataService::merchantApiInfo($merchantId, $userId);
    }

    public static function resetMd5Key(int $merchantId, int $userId): array
    {
        if ($merchantId <= 0) {
            throw new BusinessException('商户身份无效', StatusCode::UNAUTHORIZED);
        }

        $key = self::generateMerchantKey();
        self::persistMerchantCredentials($merchantId, $userId, [
            'mch_key' => $key,
        ]);

        return ResourceDataService::merchantApiInfo($merchantId, $userId);
    }

    public static function ensureMd5Key(int $merchantId, int $userId, string $currentKey = ''): string
    {
        $currentKey = trim($currentKey);
        if ($currentKey !== '' || $merchantId <= 0) {
            return $currentKey;
        }

        $key = self::generateMerchantKey();
        self::persistMerchantCredentials($merchantId, $userId, [
            'mch_key' => $key,
        ]);

        return $key;
    }

    public static function generateRsaKeyPair(int $merchantId, int $userId): array
    {
        if ($merchantId <= 0) {
            throw new BusinessException('商户身份无效', StatusCode::UNAUTHORIZED);
        }

        [$publicKey, $privateKey] = self::newRsaKeyPair();
        self::persistMerchantCredentials($merchantId, $userId, [
            'rsa_public_key' => $publicKey,
            'rsa_private_key' => $privateKey,
        ]);

        return [
            'info' => ResourceDataService::merchantApiInfo($merchantId, $userId),
            'generated_public_key' => $publicKey,
            'generated_private_key' => $privateKey,
        ];
    }

    public static function credentialProfile(int $merchantId): array
    {
        if ($merchantId <= 0) {
            return [];
        }

        $profiles = self::profiles();
        $profile = is_array($profiles[(string)$merchantId] ?? null) ? $profiles[(string)$merchantId] : [];
        if ($profile === []) {
            return [];
        }

        return array_intersect_key($profile, [
            'mch_key' => true,
            'rsa_public_key' => true,
            'rsa_private_key' => true,
            'api_sign_mode' => true,
            'sign_mode' => true,
        ]);
    }

    public static function compactPublicKey(string $key): string
    {
        return self::stripPem($key, [
            '-----BEGIN PUBLIC KEY-----',
            '-----END PUBLIC KEY-----',
            '-----BEGIN RSA PUBLIC KEY-----',
            '-----END RSA PUBLIC KEY-----',
        ]);
    }

    public static function compactPrivateKey(string $key): string
    {
        return self::stripPem($key, [
            '-----BEGIN PRIVATE KEY-----',
            '-----END PRIVATE KEY-----',
            '-----BEGIN RSA PRIVATE KEY-----',
            '-----END RSA PRIVATE KEY-----',
        ]);
    }

    public static function derivePublicKey(string $privateKey): string
    {
        $clean = self::compactPrivateKey($privateKey);
        if ($clean === '') {
            return '';
        }

        try {
            $resource = openssl_pkey_get_private(SignService::normalizePrivateKey($clean));
            if (!$resource) {
                return '';
            }

            $details = openssl_pkey_get_details($resource);
            return self::compactPublicKey((string)($details['key'] ?? ''));
        } catch (Throwable) {
            return '';
        }
    }

    private static function profiles(): array
    {
        return JsonStoreService::load(self::PROFILE_STORE, []);
    }

    private static function persistMerchantCredentials(int $merchantId, int $userId, array $changes): void
    {
        if (database_available()) {
            try {
                $merchant = Merchant::find($merchantId);
                if ($merchant) {
                    foreach ($changes as $key => $value) {
                        $merchant->$key = $value;
                    }
                    $merchant->save();
                }
            } catch (Throwable) {
            }
        }

        $profiles = self::profiles();
        $profileKey = (string)$merchantId;
        $currentProfile = is_array($profiles[$profileKey] ?? null) ? $profiles[$profileKey] : [];
        $profiles[$profileKey] = array_replace($currentProfile, $changes, [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        JsonStoreService::save(self::PROFILE_STORE, $profiles);

        self::syncLocalStores($merchantId, $userId, $changes);
    }

    private static function syncLocalStores(int $merchantId, int $userId, array $changes): void
    {
        $accounts = JsonStoreService::load('merchant_accounts', []);
        $accountsChanged = false;

        foreach ($accounts as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowMerchantId = (int)($row['merchant_id'] ?? $row['id'] ?? 0);
            $rowUserId = (int)($row['id'] ?? 0);
            if ($rowMerchantId !== $merchantId && ($userId <= 0 || $rowUserId !== $userId)) {
                continue;
            }

            $accounts[$index] = array_replace($row, $changes);
            $accountsChanged = true;
        }

        if ($accountsChanged) {
            JsonStoreService::save('merchant_accounts', array_values($accounts));
        }

        $authUsers = JsonStoreService::load('merchant_auth_users', []);
        $authChanged = false;

        foreach ($authUsers as $username => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowMerchantId = (int)($row['merchant_id'] ?? $row['id'] ?? 0);
            $rowUserId = (int)($row['id'] ?? 0);
            if ($rowMerchantId !== $merchantId && ($userId <= 0 || $rowUserId !== $userId)) {
                continue;
            }

            $authUsers[$username] = array_replace($row, $changes);
            $authChanged = true;
        }

        if ($authChanged) {
            JsonStoreService::save('merchant_auth_users', $authUsers);
        }
    }

    private static function generateMerchantKey(): string
    {
        do {
            $key = bin2hex(random_bytes(16));
        } while (self::merchantKeyExists($key));

        return $key;
    }

    private static function merchantKeyExists(string $key): bool
    {
        if ($key === '') {
            return true;
        }

        if (database_available()) {
            try {
                if (Merchant::where('mch_key', $key)->find()) {
                    return true;
                }
            } catch (Throwable) {
            }
        }

        foreach (['merchant_accounts', 'merchant_auth_users'] as $storeKey) {
            foreach ((array)JsonStoreService::load($storeKey, []) as $row) {
                if (is_array($row) && (string)($row['mch_key'] ?? '') === $key) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function newRsaKeyPair(): array
    {
        $options = self::opensslKeyOptions();
        $resource = openssl_pkey_new($options);

        if (!$resource) {
            throw new BusinessException('RSA 密钥生成失败', StatusCode::BUSINESS_ERROR);
        }

        $privateKey = '';
        if (!openssl_pkey_export($resource, $privateKey, null, $options)) {
            throw new BusinessException('RSA 私钥导出失败', StatusCode::BUSINESS_ERROR);
        }

        $details = openssl_pkey_get_details($resource);
        $publicKey = (string)($details['key'] ?? '');
        if ($publicKey === '') {
            throw new BusinessException('RSA 公钥导出失败', StatusCode::BUSINESS_ERROR);
        }

        return [
            self::compactPublicKey($publicKey),
            self::compactPrivateKey($privateKey),
        ];
    }

    private static function opensslKeyOptions(): array
    {
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $configPath = self::opensslConfigPath();
        if ($configPath !== '') {
            $options['config'] = $configPath;
        }

        return $options;
    }

    private static function opensslConfigPath(): string
    {
        $candidates = array_values(array_filter([
            getenv('OPENSSL_CONF') ?: '',
            getenv('SSLEAY_CONF') ?: '',
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'openssl.cnf',
        ], static fn(string $path): bool => $path !== ''));

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    private static function stripPem(string $key, array $headers): string
    {
        return trim(str_replace(array_merge($headers, ["\r", "\n"]), '', $key));
    }
}
