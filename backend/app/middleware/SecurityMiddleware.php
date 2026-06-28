<?php

namespace app\middleware;

use app\constant\StatusCode;
use app\exception\SecurityException;
use support\Redis;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use Throwable;

class SecurityMiddleware implements MiddlewareInterface
{
    private const DEFAULT_MAX_BODY_BYTES = 2 * 1024 * 1024;

    private const DEFAULT_UPLOAD_MAX_BODY_BYTES = 8 * 1024 * 1024;

    private const DEFAULT_RATE_WINDOW = 60;

    private const DEFAULT_RATE_LIMIT = 240;

    private const DEFAULT_OPEN_API_RATE_LIMIT = 360;

    private const DEFAULT_CALLBACK_RATE_LIMIT = 900;

    private const DEFAULT_AUTH_RATE_LIMIT = 40;

    private const DEFAULT_HEARTBEAT_RATE_LIMIT = 1200;

    /**
     * @var array<string, array{count:int, reset:int}>
     */
    private static array $memoryRateWindow = [];

    public function process(Request $request, callable $handler): Response
    {
        $this->assertIpAllowed($request);
        $this->assertContentLength($request);
        $this->assertRateLimit($request);

        /** @var Response $response */
        $response = $handler($request);

        return $response->withHeaders([
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-XSS-Protection' => '0',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
            'Cross-Origin-Resource-Policy' => 'same-site',
            'Content-Security-Policy' => $this->contentSecurityPolicy($request),
        ]);
    }

    private function assertIpAllowed(Request $request): void
    {
        $ip = trim((string)$request->getRealIp());
        if ($ip === '') {
            return;
        }

        $blacklist = $this->parseIpList((string)env('SECURITY_IP_BLACKLIST', ''));
        if ($blacklist !== [] && in_array($ip, $blacklist, true)) {
            throw new SecurityException('当前 IP 已被禁止访问', 403, StatusCode::FORBIDDEN);
        }

        $whitelist = $this->parseIpList((string)env('SECURITY_IP_WHITELIST', ''));
        if ($whitelist !== [] && !in_array($ip, $whitelist, true)) {
            throw new SecurityException('当前 IP 不在允许访问列表中', 403, StatusCode::FORBIDDEN);
        }
    }

    private function assertContentLength(Request $request): void
    {
        $contentLength = (int)$request->header('content-length', 0);
        $maxBytes = $this->resolveMaxBodyBytes($request);
        if ($contentLength > 0 && $contentLength > $maxBytes) {
            throw new SecurityException('请求体过大，已被拒绝', 413, StatusCode::VALIDATION_ERROR);
        }
    }

    private function assertRateLimit(Request $request): void
    {
        $windowSeconds = max(1, (int)env('SECURITY_RATE_WINDOW', self::DEFAULT_RATE_WINDOW));
        $maxRequests = $this->resolveRateLimit($request);
        $ip = trim((string)$request->getRealIp());
        $pathKey = $this->resolveRateLimitPathKey($request);
        $method = strtoupper((string)$request->method());
        $key = ($ip !== '' ? $ip : 'unknown') . '|' . $method . '|' . $pathKey;
        $now = time();

        $bucket = $this->hitRateLimitBucket($key, $windowSeconds, $now);

        if ($bucket['count'] > $maxRequests) {
            throw new SecurityException('请求过于频繁，请稍后再试', 429, StatusCode::TOO_MANY_REQUESTS);
        }
    }

    private function resolveMaxBodyBytes(Request $request): int
    {
        $path = '/' . ltrim((string)$request->path(), '/');
        $contentType = strtolower((string)$request->header('content-type', ''));
        $isUpload = str_contains($contentType, 'multipart/form-data')
            || str_contains($path, '/upload')
            || str_contains($path, '/avatar/')
            || str_contains($path, '/channels/config/upload');

        if ($isUpload) {
            return max(1024, (int)env('SECURITY_UPLOAD_MAX_BODY_BYTES', self::DEFAULT_UPLOAD_MAX_BODY_BYTES));
        }

        return max(1024, (int)env('SECURITY_MAX_BODY_BYTES', self::DEFAULT_MAX_BODY_BYTES));
    }

    private function resolveRateLimit(Request $request): int
    {
        $path = '/' . ltrim((string)$request->path(), '/');

        if ($this->isHeartbeatPath($path)) {
            return max(1, (int)env('SECURITY_HEARTBEAT_RATE_LIMIT', self::DEFAULT_HEARTBEAT_RATE_LIMIT));
        }

        if ($this->isCallbackPath($path)) {
            return max(1, (int)env('SECURITY_CALLBACK_RATE_LIMIT', self::DEFAULT_CALLBACK_RATE_LIMIT));
        }

        if ($this->isOpenApiPath($path)) {
            return max(1, (int)env('SECURITY_OPEN_API_RATE_LIMIT', self::DEFAULT_OPEN_API_RATE_LIMIT));
        }

        if ($this->isAuthPath($path)) {
            return max(1, (int)env('SECURITY_AUTH_RATE_LIMIT', self::DEFAULT_AUTH_RATE_LIMIT));
        }

        return max(1, (int)env('SECURITY_RATE_LIMIT', self::DEFAULT_RATE_LIMIT));
    }

    /**
     * @return array{count:int, reset:int}
     */
    private function hitRateLimitBucket(string $key, int $windowSeconds, int $now): array
    {
        $redisBucket = $this->hitRedisRateLimitBucket($key, $windowSeconds);
        if ($redisBucket !== null) {
            return $redisBucket;
        }

        $bucket = self::$memoryRateWindow[$key] ?? ['count' => 0, 'reset' => $now + $windowSeconds];
        if ($bucket['reset'] <= $now) {
            $bucket = ['count' => 0, 'reset' => $now + $windowSeconds];
        }

        $bucket['count']++;
        self::$memoryRateWindow[$key] = $bucket;

        if (count(self::$memoryRateWindow) > 5000) {
            foreach (self::$memoryRateWindow as $windowKey => $window) {
                if (($window['reset'] ?? 0) <= $now) {
                    unset(self::$memoryRateWindow[$windowKey]);
                }
            }
        }

        return $bucket;
    }

    /**
     * @return array{count:int, reset:int}|null
     */
    private function hitRedisRateLimitBucket(string $key, int $windowSeconds): ?array
    {
        if (!$this->shouldUseRedisRateLimit()) {
            return null;
        }

        $storageKey = $this->rateLimitStorageKey($key);

        try {
            $count = (int)Redis::incr($storageKey);
            if ($count <= 1) {
                Redis::expire($storageKey, $windowSeconds);
            }

            $ttl = (int)Redis::ttl($storageKey);
            return [
                'count' => $count,
                'reset' => time() + max(1, $ttl > 0 ? $ttl : $windowSeconds),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function shouldUseRedisRateLimit(): bool
    {
        $driver = strtolower(trim((string)env('SESSION_DRIVER', 'redis')));
        return $driver === 'redis' || $driver === 'redis_cluster';
    }

    private function rateLimitStorageKey(string $key): string
    {
        $prefix = trim((string)env('SECURITY_RATE_PREFIX', 'nexpay:security:rate:'));
        if ($prefix === '') {
            $prefix = 'nexpay:security:rate:';
        }

        return $prefix . md5($key);
    }

    private function resolveRateLimitPathKey(Request $request): string
    {
        $route = $request->route;
        if (is_object($route) && method_exists($route, 'getPath')) {
            $path = trim((string)$route->getPath());
            if ($path !== '') {
                return $path;
            }
        }

        $controller = trim((string)$request->controller);
        $action = trim((string)$request->action);
        if ($controller !== '' && $action !== '') {
            return $controller . '@' . $action;
        }

        return '/' . ltrim((string)$request->path(), '/');
    }

    private function isOpenApiPath(string $path): bool
    {
        return $path === '/mapi.php'
            || $path === '/api.php'
            || str_starts_with($path, '/api/pay/')
            || str_starts_with($path, '/api/transfer/');
    }

    private function isCallbackPath(string $path): bool
    {
        return str_starts_with($path, '/pay/notify/')
            || str_starts_with($path, '/pay/refundnotify/')
            || str_starts_with($path, '/pay/transfernotify/')
            || str_starts_with($path, '/pay/preauthnotify/')
            || str_starts_with($path, '/pay/complainnotify/')
            || str_starts_with($path, '/pay/dividenotify/')
            || str_starts_with($path, '/pay/cashiernotify/')
            || $path === '/callback/success';
    }

    private function isHeartbeatPath(string $path): bool
    {
        return $path === '/api/Software/heartbeat'
            || $path === '/api/Software/checkOrder'
            || str_starts_with($path, '/api/report');
    }

    private function isAuthPath(string $path): bool
    {
        return str_starts_with($path, '/api/admin/auth/')
            || str_starts_with($path, '/api/merchant/auth/');
    }

    private function contentSecurityPolicy(Request $request): string
    {
        $base = [
            "default-src 'self'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "object-src 'none'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https:",
            "style-src 'self' 'unsafe-inline' https:",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:",
            "connect-src 'self' ws: wss: http: https:",
            "media-src 'self' data: blob: https:",
        ];

        $path = '/' . ltrim((string)$request->path(), '/');
        if ($this->isOpenApiPath($path) || $this->isCallbackPath($path) || $this->isHeartbeatPath($path)) {
            $base[] = "frame-src 'none'";
        } else {
            $base[] = "frame-src 'self'";
        }

        return implode('; ', $base);
    }

    /**
     * @return array<int, string>
     */
    private function parseIpList(string $raw): array
    {
        $raw = str_replace(["\r", "\n", ';'], ',', $raw);
        $items = array_map(static fn(string $item): string => trim($item), explode(',', $raw));

        return array_values(array_filter($items, static fn(string $item): bool => $item !== ''));
    }
}
