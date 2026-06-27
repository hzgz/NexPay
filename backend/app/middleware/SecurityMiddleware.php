<?php

namespace app\middleware;

use RuntimeException;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class SecurityMiddleware implements MiddlewareInterface
{
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
            'X-XSS-Protection' => '1; mode=block',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
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
            throw new RuntimeException('当前 IP 已被禁止访问');
        }

        $whitelist = $this->parseIpList((string)env('SECURITY_IP_WHITELIST', ''));
        if ($whitelist !== [] && !in_array($ip, $whitelist, true)) {
            throw new RuntimeException('当前 IP 不在允许访问列表中');
        }
    }

    private function assertContentLength(Request $request): void
    {
        $contentLength = (int)$request->header('content-length', 0);
        $maxBytes = max(1024, (int)env('SECURITY_MAX_BODY_BYTES', 2 * 1024 * 1024));
        if ($contentLength > 0 && $contentLength > $maxBytes) {
            throw new RuntimeException('请求体过大，已被拒绝');
        }
    }

    private function assertRateLimit(Request $request): void
    {
        $windowSeconds = max(1, (int)env('SECURITY_RATE_WINDOW', 60));
        $maxRequests = max(1, (int)env('SECURITY_RATE_LIMIT', 240));
        $ip = trim((string)$request->getRealIp());
        $path = trim((string)$request->path());
        $key = ($ip !== '' ? $ip : 'unknown') . '|' . $path;
        $now = time();

        $bucket = self::$memoryRateWindow[$key] ?? ['count' => 0, 'reset' => $now + $windowSeconds];
        if ($bucket['reset'] <= $now) {
            $bucket = ['count' => 0, 'reset' => $now + $windowSeconds];
        }

        $bucket['count']++;
        self::$memoryRateWindow[$key] = $bucket;

        if ($bucket['count'] > $maxRequests) {
            throw new RuntimeException('请求过于频繁，请稍后再试');
        }

        if (count(self::$memoryRateWindow) > 5000) {
            foreach (self::$memoryRateWindow as $windowKey => $window) {
                if (($window['reset'] ?? 0) <= $now) {
                    unset(self::$memoryRateWindow[$windowKey]);
                }
            }
        }
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
