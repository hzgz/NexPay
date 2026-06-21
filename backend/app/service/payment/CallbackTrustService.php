<?php

declare(strict_types=1);

namespace app\service\payment;

use RuntimeException;

class CallbackTrustService
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private static array $stack = [];

    public static function beginTrusted(array $context, callable $callback): mixed
    {
        self::$stack[] = self::normalizeContext($context);

        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    public static function current(): ?array
    {
        if (self::$stack === []) {
            return null;
        }

        $current = self::$stack[count(self::$stack) - 1] ?? null;
        return is_array($current) ? $current : null;
    }

    public static function isTrusted(array $expected = []): bool
    {
        $current = self::current();
        if ($current === null) {
            return false;
        }

        $expected = self::normalizeContext($expected);
        foreach ($expected as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            if (($current[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    public static function assertTrusted(array $expected = [], string $message = 'Untrusted callback operation blocked'): array
    {
        if (!self::isTrusted($expected)) {
            throw new RuntimeException($message);
        }

        return self::current() ?? [];
    }

    public static function describeCurrent(): array
    {
        return self::current() ?? [];
    }

    private static function normalizeContext(array $context): array
    {
        return [
            'plugin_code' => self::normalizeStringOrNull($context['plugin_code'] ?? null),
            'channel_id' => self::normalizeIntOrNull($context['channel_id'] ?? null),
            'merchant_id' => self::normalizeIntOrNull($context['merchant_id'] ?? null),
            'action' => self::normalizeStringOrNull($context['action'] ?? null),
            'scope' => self::normalizeStringOrNull($context['scope'] ?? null),
            'source' => self::normalizeStringOrNull($context['source'] ?? null),
            'verification' => self::normalizeStringOrNull($context['verification'] ?? null),
        ];
    }

    private static function normalizeStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string)$value);
        return $normalized !== '' ? $normalized : null;
    }

    private static function normalizeIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }
}
