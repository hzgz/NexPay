<?php

declare(strict_types=1);

namespace plugins\payment\common;

use app\common\BasePayment;
use app\common\PaymentContext;
use app\service\system\PaymentMetaService;

class StaticPaymentPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        return $this->dispatch($ctx, (string)($ctx->order['typename'] ?? ''));
    }

    public function mapi(PaymentContext $ctx): array
    {
        return $this->dispatch($ctx, (string)($ctx->method ?: ($ctx->order['typename'] ?? '')));
    }

    public function alipay(PaymentContext $ctx): array
    {
        return $this->dispatch($ctx, 'alipay');
    }

    public function wxpay(PaymentContext $ctx): array
    {
        return $this->dispatch($ctx, 'wxpay');
    }

    public function qqpay(PaymentContext $ctx): array
    {
        return $this->dispatch($ctx, 'qqpay');
    }

    public function bank(PaymentContext $ctx): array
    {
        return $this->dispatch($ctx, 'bank');
    }

    public function jdpay(PaymentContext $ctx): array
    {
        return $this->dispatch($ctx, 'jdpay');
    }

    public function douyinpay(PaymentContext $ctx): array
    {
        return $this->dispatch($ctx, 'douyinpay');
    }

    private function dispatch(PaymentContext $ctx, string $method): array
    {
        $kind = strtolower(trim((string)($this->channel['kind'] ?? $this->channel['plugin_kind'] ?? 'qrcode')));
        $method = PaymentMetaService::normalizeMethodCode($method);
        $config = is_array($this->channel['plugin_config'] ?? null) ? $this->channel['plugin_config'] : [];
        $address = $this->resolveAddress($config);
        $page = $this->resolvePage($method, $ctx->isMobile, $kind);

        if ($kind === 'app') {
            return ['type' => 'jump', 'url' => $address !== '' ? $address : request()->siteurl . 'pay/checkout/' . $ctx->order['trade_no']];
        }

        return [
            'type' => 'qrcode',
            'page' => $page,
            'url' => $address !== '' ? $address : (string)($ctx->order['payment_address'] ?? ''),
        ];
    }

    private function resolveAddress(array $config): string
    {
        foreach (['qrcode_url', 'payment_address', 'display_value', 'address', 'url', 'link'] as $key) {
            $value = trim((string)($config[$key] ?? $this->channel[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolvePage(string $method, bool $isMobile, string $kind): string
    {
        if ($kind === 'ck') {
            return $method . '_ck';
        }

        if ($kind === 'app') {
            return $method . '_app';
        }

        if ($isMobile) {
            return match ($method) {
                'wxpay' => 'wxpay_wap',
                'qqpay' => 'qqpay_wap',
                'douyinpay' => 'douyinpay_wap',
                default => $method . '_wap',
            };
        }

        return match ($method) {
            'wxpay' => 'wxpay_qrcode',
            'qqpay' => 'qqpay_qrcode',
            'bank' => 'bank_qrcode',
            'jdpay' => 'jdpay_qrcode',
            'douyinpay' => 'douyinpay_qrcode',
            default => $method . '_qrcode',
        };
    }
}
