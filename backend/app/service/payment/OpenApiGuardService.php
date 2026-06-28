<?php

declare(strict_types=1);

namespace app\service\payment;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\system\ConfigService;
use support\Redis;
use Throwable;

class OpenApiGuardService
{
    /**
     * @var array<string, int>
     */
    private static array $memoryReplayWindow = [];

    public static function assertV2Timestamp(array $payload): int
    {
        $timestamp = self::extractUnixTimestamp($payload['timestamp'] ?? null);
        if ($timestamp === null) {
            throw new BusinessException('V2 请求缺少有效 timestamp', StatusCode::VALIDATION_ERROR);
        }

        $maxSkew = max(1, (int)env('OPENAPI_V2_MAX_SKEW_SECONDS', 300));
        if (abs(time() - $timestamp) > $maxSkew) {
            throw new BusinessException('V2 请求 timestamp 已过期', StatusCode::UNAUTHORIZED);
        }

        return $timestamp;
    }

    public static function assertV2ReplayProtection(string|int $merchantId, array $payload, string $scope): void
    {
        $ttl = max(5, (int)env('OPENAPI_V2_REPLAY_TTL_SECONDS', 600));
        $fingerprint = md5(SignService::buildSignContent($payload, ['sign', 'sign_type']));
        $key = 'v2|' . (string)$merchantId . '|' . trim($scope) . '|' . $fingerprint;
        self::assertReplayKeyFresh($key, $ttl, 'V2 请求重复提交，已被拦截');
    }

    public static function assertSoftwareCompatFreshness(array $payload, string $scope): void
    {
        $strict = self::compatStrictModeEnabled();
        $timestamp = self::extractUnixTimestamp($payload['timestamp'] ?? null);
        if ($timestamp === null) {
            if ($strict) {
                throw new BusinessException('监控请求缺少有效 timestamp', StatusCode::VALIDATION_ERROR);
            }

            return;
        }

        $maxSkew = max(1, (int)env('SOFTWARE_COMPAT_MAX_SKEW_SECONDS', 300));
        if (abs(time() - $timestamp) > $maxSkew) {
            throw new BusinessException('监控请求 timestamp 已过期', StatusCode::UNAUTHORIZED);
        }

        $requireSign = self::compatRequireSignEnabled();
        $signature = trim((string)($payload['sign'] ?? ''));
        if ($signature === '') {
            if ($requireSign) {
                throw new BusinessException('监控请求缺少 sign', StatusCode::VALIDATION_ERROR);
            }

            return;
        }

        $key = trim((string)($payload['token'] ?? $payload['key'] ?? ''));
        if ($key === '') {
            throw new BusinessException('监控请求缺少密钥参数', StatusCode::VALIDATION_ERROR);
        }

        $signSource = self::softwareCompatSignSource($payload);
        if (!hash_equals(strtolower(md5($signSource . $timestamp . $key)), strtolower($signature))) {
            throw new BusinessException('监控请求签名校验失败', StatusCode::UNAUTHORIZED);
        }

        if (self::compatReplayProtectionEnabled()) {
            $merchantId = trim((string)($payload['id'] ?? $payload['merchant_id'] ?? $payload['pid'] ?? $payload['uid'] ?? '0'));
            $ttl = max(5, (int)env('SOFTWARE_COMPAT_REPLAY_TTL_SECONDS', 600));
            $fingerprint = md5($scope . '|' . $signSource . '|' . $signature . '|' . $timestamp);
            self::assertReplayKeyFresh(
                'software|' . $merchantId . '|' . trim($scope) . '|' . $fingerprint,
                $ttl,
                '监控请求重复提交，已被拦截'
            );
        }
    }

    public static function compatSignExample(string $path): array
    {
        $timestamp = time();
        $baseUrl = rtrim((string)ConfigService::gatewayBaseUrl(), '/');
        $url = $baseUrl . '/' . ltrim($path, '/');

        return [
            'timestamp' => (string)$timestamp,
            'sign_source' => base64_encode($url),
            'sign_rule' => 'md5(base64(url) + timestamp + 商户密钥)',
        ];
    }

    private static function assertReplayKeyFresh(string $key, int $ttl, string $message): void
    {
        if (self::shouldUseRedis()) {
            try {
                $storageKey = self::replayStorageKey($key);
                $count = (int)Redis::incr($storageKey);
                if ($count <= 1) {
                    Redis::expire($storageKey, $ttl);
                    return;
                }

                throw new BusinessException($message, StatusCode::TOO_MANY_REQUESTS);
            } catch (BusinessException $exception) {
                throw $exception;
            } catch (Throwable) {
            }
        }

        $now = time();
        self::purgeExpiredMemoryReplayKeys($now);
        $expiresAt = self::$memoryReplayWindow[$key] ?? 0;
        if ($expiresAt > $now) {
            throw new BusinessException($message, StatusCode::TOO_MANY_REQUESTS);
        }

        self::$memoryReplayWindow[$key] = $now + $ttl;
    }

    private static function softwareCompatSignSource(array $payload): string
    {
        $source = trim((string)($payload['url'] ?? ''));
        if ($source !== '') {
            return $source;
        }

        $reportUrl = trim((string)($payload['report_url'] ?? ''));
        if ($reportUrl !== '') {
            return base64_encode($reportUrl);
        }

        unset($payload['sign']);
        ksort($payload);

        return base64_encode((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function extractUnixTimestamp(mixed $value): ?int
    {
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }

        if (ctype_digit($text)) {
            return (int)$text;
        }

        if (is_numeric($text)) {
            return (int)floor((float)$text);
        }

        return null;
    }

    private static function compatStrictModeEnabled(): bool
    {
        return self::toBool(env('SOFTWARE_COMPAT_REQUIRE_TIMESTAMP', '0'));
    }

    private static function compatRequireSignEnabled(): bool
    {
        return self::toBool(env('SOFTWARE_COMPAT_REQUIRE_SIGN', '0'));
    }

    private static function compatReplayProtectionEnabled(): bool
    {
        return self::toBool(env('SOFTWARE_COMPAT_REPLAY_PROTECTION', '0'));
    }

    private static function replayStorageKey(string $key): string
    {
        $prefix = trim((string)env('OPENAPI_REPLAY_PREFIX', 'nexpay:openapi:replay:'));
        if ($prefix === '') {
            $prefix = 'nexpay:openapi:replay:';
        }

        return $prefix . md5($key);
    }

    private static function shouldUseRedis(): bool
    {
        $driver = strtolower(trim((string)env('SESSION_DRIVER', 'redis')));
        return $driver === 'redis' || $driver === 'redis_cluster';
    }

    private static function purgeExpiredMemoryReplayKeys(int $now): void
    {
        if (count(self::$memoryReplayWindow) <= 5000) {
            return;
        }

        foreach (self::$memoryReplayWindow as $key => $expiresAt) {
            if ($expiresAt <= $now) {
                unset(self::$memoryReplayWindow[$key]);
            }
        }
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
