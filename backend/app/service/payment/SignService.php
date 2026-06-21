<?php

namespace app\service\payment;

use app\exception\BusinessException;

class SignService
{
    public static function md5Sign(array $payload, string $key): string
    {
        return strtolower(md5(self::buildSignContent($payload, ['sign', 'sign_type']) . $key));
    }

    public static function verifyMd5(array $payload, string $key, string $signature): bool
    {
        return hash_equals(self::md5Sign($payload, $key), strtolower(trim($signature)));
    }

    public static function rsaSign(array $payload, string $privateKey): string
    {
        $content = self::buildSignContent($payload, ['sign', 'sign_type']);
        $pem = self::normalizePrivateKey($privateKey);
        $resource = openssl_pkey_get_private($pem);
        if (!$resource) {
            throw new BusinessException('平台私钥无效');
        }

        openssl_sign($content, $signature, $resource, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    public static function verifyRsa(array $payload, string $publicKey, string $signature): bool
    {
        $content = self::buildSignContent($payload, ['sign', 'sign_type']);
        $pem = self::normalizePublicKey($publicKey);
        $resource = openssl_pkey_get_public($pem);
        if (!$resource) {
            return false;
        }

        $decoded = base64_decode(trim($signature), true);
        if ($decoded === false) {
            return false;
        }

        return openssl_verify($content, $decoded, $resource, OPENSSL_ALGO_SHA256) === 1;
    }

    public static function buildSignContent(array $payload, array $excludeKeys = []): string
    {
        $content = [];
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (in_array($key, $excludeKeys, true) || is_array($value) || $value === null) {
                continue;
            }

            $stringValue = trim((string)$value);
            if ($stringValue === '') {
                continue;
            }

            $content[] = $key . '=' . $stringValue;
        }

        return implode('&', $content);
    }

    public static function normalizePrivateKey(string $key): string
    {
        $clean = trim(str_replace([
            '-----BEGIN PRIVATE KEY-----',
            '-----END PRIVATE KEY-----',
            '-----BEGIN RSA PRIVATE KEY-----',
            '-----END RSA PRIVATE KEY-----',
            "\r",
            "\n",
        ], '', $key));

        return "-----BEGIN PRIVATE KEY-----\n" . wordwrap($clean, 64, "\n", true) . "\n-----END PRIVATE KEY-----";
    }

    public static function normalizePublicKey(string $key): string
    {
        $clean = trim(str_replace([
            '-----BEGIN PUBLIC KEY-----',
            '-----END PUBLIC KEY-----',
            '-----BEGIN RSA PUBLIC KEY-----',
            '-----END RSA PUBLIC KEY-----',
            "\r",
            "\n",
        ], '', $key));

        return "-----BEGIN PUBLIC KEY-----\n" . wordwrap($clean, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
    }
}
