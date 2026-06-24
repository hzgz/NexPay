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

    public function query(array $order): array
    {
        $config = is_array($this->channel['plugin_config'] ?? null) ? $this->channel['plugin_config'] : [];
        $pluginCode = strtolower(trim((string)($this->channel['plugin_code'] ?? $this->channel['plugin'] ?? '')));
        $method = PaymentMetaService::normalizeMethodCode((string)($order['typename'] ?? $order['type'] ?? ''));

        return [
            'status' => 0,
            'trade_no' => trim((string)($order['api_trade_no'] ?? $order['trade_no'] ?? '')),
            'money' => number_format((float)($order['realmoney'] ?? $order['money'] ?? 0), 2, '.', ''),
            'msg' => $this->pendingMessage($pluginCode, $method, $config),
        ];
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
        $pluginCode = strtolower(trim((string)($this->channel['plugin_code'] ?? $config['plugin_code'] ?? '')));
        $mode = strtolower(trim((string)($config['mode'] ?? '')));
        $resolvedSource = strtolower(trim((string)($config['resolved_qrcode_source'] ?? '')));

        if ($resolvedSource === 'image' || ($pluginCode === 'wechat-qrcode' && $mode === 'appreciate')) {
            foreach (['appreciate_image', 'appreciate_qrcode_url', 'qrcode_image'] as $key) {
                $value = trim((string)($config[$key] ?? $this->channel[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach (['qrcode_url', 'payment_address', 'display_value', 'address', 'url', 'link', 'appreciate_qrcode_url', 'qrcode_image', 'appreciate_image'] as $key) {
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

    private function pendingMessage(string $pluginCode, string $method, array $config): string
    {
        $mode = strtolower(trim((string)($config['mode'] ?? '')));

        if ($pluginCode === 'wechat-qrcode' && $mode === 'appreciate') {
            return '等待微信赞赏码回调或人工确认';
        }

        return match ($pluginCode) {
            'alipay-qrcode' => '等待支付宝收款码回调或人工确认',
            'qqpay-qrcode' => '等待 QQ 收款码回调或人工确认',
            'wechat-qrcode' => '等待微信收款码回调或人工确认',
            default => match ($method) {
                'alipay' => '等待支付宝支付结果回调或人工确认',
                'wxpay' => '等待微信支付结果回调或人工确认',
                'qqpay' => '等待 QQ 支付结果回调或人工确认',
                default => '等待支付结果回调或人工确认',
            },
        };
    }
}
