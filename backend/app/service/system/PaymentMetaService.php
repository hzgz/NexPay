<?php

namespace app\service\system;

class PaymentMetaService
{
    public static function friendlyMethodName(string $code): string
    {
        return match (self::normalizeMethodCode($code)) {
            'wxpay' => '微信支付',
            'alipay' => '支付宝',
            'qqpay' => 'QQ钱包',
            'balance' => '余额支付',
            'bank' => '银行卡 / 云闪付',
            'jdpay' => '京东支付',
            'paypal' => 'PayPal',
            'douyinpay' => '抖音支付',
            'usdtaptos' => 'USDT-Aptos',
            'usdtpolygon' => 'USDT-Polygon',
            'usdttrc20' => 'USDT-TRC20',
            'trx' => 'TRX',
            'erc20' => 'USDT-ERC20',
            'bsc' => 'USDT-BSC',
            'avaxc' => 'USDT-AVAXC',
            default => strtoupper(self::normalizeMethodCode($code)),
        };
    }

    public static function friendlyMethodIcon(string $code): string
    {
        return match (self::normalizeMethodCode($code)) {
            'wxpay' => 'payment-icons/wechat.png',
            'alipay' => 'payment-icons/alipay.png',
            'qqpay' => 'payment-icons/qqpay.png',
            'bank' => 'payment-icons/unionpay.png',
            'jdpay' => 'payment-icons/jdpay.png',
            'paypal' => 'payment-icons/paypal.png',
            default => 'payment-icons/alipay.png',
        };
    }

    public static function normalizeMethodCode(string $code): string
    {
        $normalized = strtolower(trim($code));
        $map = [
            'wxpay' => 'wxpay',
            'wechatpay' => 'wxpay',
            'wechat' => 'wxpay',
            'weixin' => 'wxpay',
            'alipay' => 'alipay',
            'balance' => 'balance',
            'wallet' => 'balance',
            'qq' => 'qqpay',
            'qqwallet' => 'qqpay',
            'qqpay' => 'qqpay',
            'union' => 'bank',
            'unionpay' => 'bank',
            'bank' => 'bank',
            'yinlian' => 'bank',
            'yunshanfu' => 'bank',
            'cloudquickpass' => 'bank',
            'jdpay' => 'jdpay',
            'paypal' => 'paypal',
            'douyin' => 'douyinpay',
            'douyinpay' => 'douyinpay',
            'ecny' => 'bank',
            'usdt' => 'usdttrc20',
            'usdt-trc20' => 'usdttrc20',
            'usdttrc20' => 'usdttrc20',
            'trc20' => 'usdttrc20',
            'usdtpolygon' => 'usdtpolygon',
            'polygon' => 'usdtpolygon',
            'matic' => 'usdtpolygon',
            'usdtaptos' => 'usdtaptos',
            'aptos' => 'usdtaptos',
            'trx' => 'trx',
            'usdt-erc20' => 'erc20',
            'erc20' => 'erc20',
            'usdt-bsc' => 'bsc',
            'bep20' => 'bsc',
            'bsc' => 'bsc',
            'avaxc' => 'avaxc',
            'avalanche' => 'avaxc',
        ];

        return $map[$normalized] ?? $normalized;
    }

    public static function acceptedMethodCodes(string $code): array
    {
        return match (self::normalizeMethodCode($code)) {
            'wxpay' => ['wxpay', 'wechat', 'wechatpay', 'weixin'],
            'alipay' => ['alipay'],
            'qqpay' => ['qqpay', 'qq', 'qqwallet'],
            'balance' => ['balance', 'wallet'],
            'bank' => ['bank', 'unionpay', 'union', 'yinlian', 'yunshanfu', 'cloudquickpass', 'ecny'],
            'jdpay' => ['jdpay'],
            'paypal' => ['paypal'],
            'douyinpay' => ['douyinpay', 'douyin'],
            'usdtaptos' => ['usdtaptos', 'aptos'],
            'usdtpolygon' => ['usdtpolygon', 'polygon', 'matic'],
            'usdttrc20' => ['usdttrc20', 'usdt-trc20', 'trc20', 'usdt'],
            'trx' => ['trx'],
            'erc20' => ['erc20', 'usdt-erc20'],
            'bsc' => ['bsc', 'bep20', 'usdt-bsc'],
            'avaxc' => ['avaxc', 'avalanche'],
            default => [self::normalizeMethodCode($code)],
        };
    }

    public static function isChainMethodCode(string $code): bool
    {
        return in_array(
            self::normalizeMethodCode($code),
            ['usdttrc20', 'usdtpolygon', 'usdtaptos', 'trx', 'erc20', 'bsc', 'avaxc'],
            true
        );
    }

    public static function isQrcodeMethodCode(string $code): bool
    {
        return in_array(
            self::normalizeMethodCode($code),
            ['wxpay', 'alipay', 'qqpay'],
            true
        );
    }

    public static function normalizeCategory(string $label, string $code): int
    {
        if (self::isChainMethodCode($code)) {
            return 1;
        }

        if (self::normalizeMethodCode($code) === 'paypal') {
            return 3;
        }

        if (self::isQrcodeMethodCode($code)) {
            return 4;
        }

        $trimmed = trim($label);
        if ($trimmed !== '') {
            $value = strtolower($trimmed);

            if (self::containsAny($value, ['usdt', 'chain', 'crypto', 'onchain', 'blockchain'])) {
                return 1;
            }

            if (self::containsAny($value, ['paypal', 'international', 'cross-border'])) {
                return 3;
            }

            if (self::containsAny($value, ['qrcode', 'qr pay', 'scan'])) {
                return 4;
            }

            return 2;
        }

        return 2;
    }

    public static function categoryLabel(int $category): string
    {
        return match ($category) {
            1 => '链上支付',
            3 => '国际支付',
            4 => '码支付',
            default => '聚合支付',
        };
    }

    public static function defaultSettlementByCode(string $code): string
    {
        return self::isChainMethodCode($code) ? '链上实时结算' : 'T+0';
    }

    public static function safeMethodName(string $name, string $code): string
    {
        $trimmed = trim($name);
        $normalizedName = strtolower($trimmed);
        $legacyEnglishNames = [
            'alipay',
            'qq pay',
            'jd pay',
            'wechat pay',
            'douyin pay',
            'bank / unionpay',
            'on-chain realtime',
            'balance',
        ];

        if ($trimmed === '' || self::containsMojibake($trimmed) || in_array($normalizedName, $legacyEnglishNames, true)) {
            return self::friendlyMethodName($code);
        }

        return $trimmed;
    }

    public static function safeCategoryLabel(string $label, string $code): string
    {
        $trimmed = trim($label);
        if ($trimmed === '' || self::containsMojibake($trimmed)) {
            return self::categoryLabel(self::normalizeCategory('', $code));
        }

        return self::categoryLabel(self::normalizeCategory($trimmed, $code));
    }

    public static function safeSettlementLabel(string $label, string $code): string
    {
        $trimmed = trim($label);
        $normalized = strtolower($trimmed);
        $legacyEnglishLabels = [
            'on-chain realtime',
            'on chain realtime',
            'onchain realtime',
        ];

        if (
            $trimmed === ''
            || self::containsMojibake($trimmed)
            || in_array($normalized, $legacyEnglishLabels, true)
        ) {
            return self::defaultSettlementByCode($code);
        }

        return $trimmed;
    }

    public static function safeRemark(string $remark, string $code): string
    {
        $trimmed = trim($remark);
        if ($trimmed === '' || !self::containsMojibake($trimmed)) {
            return $trimmed;
        }

        return match (self::normalizeMethodCode($code)) {
            'wxpay' => '默认微信支付通道',
            'alipay' => '默认支付宝通道',
            'qqpay' => '默认 QQ 钱包通道',
            'balance' => '系统余额支付',
            'bank' => '默认银行卡 / 云闪付通道',
            'paypal' => '默认 PayPal 通道',
            default => self::isChainMethodCode($code) ? '默认链上通道' : '默认收款通道',
        };
    }

    public static function safeDeveloperName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '' || self::containsMojibake($trimmed)) {
            return '官方';
        }

        return $trimmed;
    }

    public static function normalizeMethodList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $code = self::normalizeMethodCode((string)$item);
            if ($code !== '') {
                $normalized[$code] = $code;
            }
        }

        return array_values($normalized);
    }

    public static function containsMojibake(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/\?{2,}/', $trimmed) === 1) {
            return true;
        }

        return preg_match('/[\x{FFFD}\x{95B8}\x{95B9}\x{95BF}\x{95C1}\x{95C2}\x{7035}]/u', $trimmed) === 1;
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
