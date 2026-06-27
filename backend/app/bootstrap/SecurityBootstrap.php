<?php

namespace app\bootstrap;

use RuntimeException;
use Webman\Bootstrap;
use Workerman\Worker;

class SecurityBootstrap implements Bootstrap
{
    private const FORBIDDEN_DEFAULTS = [
        'epay-plus-secret',
        'epay-plus-demo-secret',
        'changeme',
        '123456',
    ];

    public static function start(?Worker $worker): void
    {
        self::assertSecret('TOKEN_SECRET');
        self::assertSecret('INTERNAL_REFUND_SECRET', true);
    }

    private static function assertSecret(string $key, bool $allowFallbackToTokenSecret = false): void
    {
        $value = trim((string)env($key, ''));
        if ($value === '' && $allowFallbackToTokenSecret) {
            $value = trim((string)env('TOKEN_SECRET', ''));
        }

        if ($value === '') {
            throw new RuntimeException($key . ' 未配置，系统已拒绝启动');
        }

        if (in_array(strtolower($value), self::FORBIDDEN_DEFAULTS, true)) {
            throw new RuntimeException($key . ' 使用了默认弱密钥，系统已拒绝启动');
        }

        if (strlen($value) < 16) {
            throw new RuntimeException($key . ' 长度过短，至少需要 16 位');
        }
    }
}
