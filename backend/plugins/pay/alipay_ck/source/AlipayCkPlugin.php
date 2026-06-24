<?php

declare(strict_types=1);

namespace plugins\payment\alipay_ck;

use app\common\BasePayment;
use app\common\PaymentContext;
use app\service\payment\OrderService;
use app\service\system\ConfigService;
use RuntimeException;

class AlipayCkPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        return $this->buildQrResponse($ctx);
    }

    public function mapi(PaymentContext $ctx): array
    {
        return $this->buildQrResponse($ctx);
    }

    public function alipay(PaymentContext $ctx): array
    {
        return $this->buildQrResponse($ctx);
    }

    public function query(array $order): array
    {
        return [
            'status' => 0,
            'trade_no' => trim((string)($order['api_trade_no'] ?? '')),
            'money' => number_format((float)($order['realmoney'] ?? $order['money'] ?? 0), 2, '.', ''),
            'msg' => '等待支付宝收款回调或监控确认',
        ];
    }

    private function buildQrResponse(PaymentContext $ctx): array
    {
        $pluginConfig = is_array($this->channel['plugin_config'] ?? null) ? $this->channel['plugin_config'] : [];
        $loginState = strtolower(trim((string)($pluginConfig['login_state'] ?? '')));
        if ($loginState !== 'authenticated') {
            return ['type' => 'error', 'msg' => '当前通道未完成支付宝 CK 登录，请先扫码登录。'];
        }

        $pid = trim((string)($pluginConfig['account_pid'] ?? ''));
        if ($pid === '') {
            return ['type' => 'error', 'msg' => '当前通道缺少支付宝 PID，请重新登录后再试。'];
        }

        $tradeNo = trim((string)($ctx->order['trade_no'] ?? ''));
        if ($tradeNo === '') {
            throw new RuntimeException('订单号不能为空');
        }

        $payableAmount = number_format((float)($ctx->order['payable_amount'] ?? $ctx->order['realmoney'] ?? $ctx->order['money'] ?? 0), 2, '.', '');
        if ((float)$payableAmount <= 0) {
            $order = OrderService::findByTradeNo($tradeNo);
            $payableAmount = number_format((float)($order->payable_amount ?? $order->amount ?? 0), 2, '.', '');
        }
        $memo = trim((string)($ctx->order['out_trade_no'] ?? $tradeNo));

        $bridgeUrl = rtrim((string)ConfigService::gatewayBaseUrl(), '/')
            . '/alipay/bridge?'
            . http_build_query([
                'user_id' => $pid,
                'price' => $payableAmount,
                'trade_no' => $memo,
            ]);

        return [
            'type' => 'qrcode',
            'page' => 'alipay_qrcode',
            'url' => $bridgeUrl,
            'money' => $payableAmount,
        ];
    }
}
