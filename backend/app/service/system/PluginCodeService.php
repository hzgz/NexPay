<?php

namespace app\service\system;

class PluginCodeService
{
    public static function normalize(string $code): string
    {
        $normalized = strtolower(trim($code));
        if ($normalized === '') {
            return '';
        }

        return match ($normalized) {
            'wechat_qrcode' => 'wechat-qrcode',
            'wechat_app' => 'wechat-app',
            'alipay_qrcode' => 'alipay-qrcode',
            'alipay_app' => 'alipay-app',
            'alipay_ck' => 'alipay-ck',
            'qq_qrcode' => 'qqpay-qrcode',
            'qq_app' => 'qq-app',
            'usdt_trc20' => 'trc20',
            'payment_test' => 'payment-test',
            default => $normalized,
        };
    }

    public static function normalizeList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $code = self::normalize((string)$item);
            if ($code !== '') {
                $normalized[$code] = $code;
            }
        }

        return array_values($normalized);
    }
}
