<?php

declare(strict_types=1);

namespace app\service\payment;

use RuntimeException;

final class LocalQrEncoder
{
    private static bool $booted = false;

    public static function encodePng(string $content, int $size = 320): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        self::boot();

        $size = max(80, min(640, $size));
        $tmpFile = tempnam(sys_get_temp_dir(), 'nexpay_qr_');
        if ($tmpFile === false) {
            throw new RuntimeException('无法创建二维码临时文件');
        }

        try {
            \QRcode::png($content, $tmpFile, \QR_ECLEVEL_M, $size, 1);
            $body = (string) @file_get_contents($tmpFile);
            if ($body === '') {
                throw new RuntimeException('二维码生成失败');
            }

            return $body;
        } finally {
            @unlink($tmpFile);
        }
    }

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        if (!extension_loaded('gd')) {
            throw new RuntimeException('当前环境未安装 GD 扩展');
        }

        if (!defined('NEXPAY_QRCODE_RUNTIME_ONLY')) {
            define('NEXPAY_QRCODE_RUNTIME_ONLY', true);
        }

        require_once __DIR__ . DIRECTORY_SEPARATOR . 'PhpQrCodeRuntime.php';
        self::$booted = true;
    }
}
