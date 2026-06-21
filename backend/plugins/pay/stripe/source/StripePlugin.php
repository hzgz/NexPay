<?php

declare(strict_types=1);

namespace plugins\payment\stripe;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;
use think\facade\Db;

class StripePlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($this->channel['appswitch'] == 1 && $ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($this->channel['appswitch'] == 1 && $ctx->order['typename'] == 'wxpay') {
            return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
        }

        if ($ctx->order['typename'] == 'alipay') {
            $payment_method = 'alipay';
        } elseif ($ctx->order['typename'] == 'wxpay') {
            $payment_method = 'wechat_pay';
        } elseif ($ctx->order['typename'] == 'paypal') {
            $payment_method = 'paypal';
        } else {
            $payment_method = '';
        }

        if (!$this->channel['currency_rate']) $this->channel['currency_rate'] = 1;
        if (!$this->channel['currency_code']) $this->channel['currency_code'] = 'cny';
        $amount = intval(round($ctx->order['realmoney'] * $this->channel['currency_rate'] * 100));

        try {
            $stripe = new StripeClient($this->channel['appid']);
            $data = [
                'success_url'         => $siteurl . 'pay/return/' . $tradeNo . '/',
                'cancel_url'          => $siteurl . 'pay/error/' . $tradeNo . '/',
                'client_reference_id' => $tradeNo,
                'line_items' => [[
                    'price_data' => [
                        'currency'     => strtolower($this->channel['currency_code']),
                        'product_data' => [
                            'name' => $ctx->ordername
                        ],
                        'unit_amount'  => $amount
                    ],
                    'quantity'   => 1
                ]],
                'mode'                => 'payment'
            ];
            if ($payment_method) $data['payment_method_types'] = [$payment_method];
            if ($payment_method == 'wechat_pay') {
                $data['payment_method_options']['wechat_pay']['client'] = 'web';
            }
            $result = $stripe->request('post', '/v1/checkout/sessions', $data);
            $jump_url = $result['url'];
            return ['type' => 'jump', 'url' => $jump_url];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
    }

    private function paymentintent(PaymentContext $ctx, string $payment_method): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $stripe = new StripeClient($this->channel['appid']);

        $data = ['type' => $payment_method];
        try {
            $result = $stripe->request('post', '/v1/payment_methods', $data);
            $payment_method_id = $result['id'];
        } catch (Exception $e) {
            throw new Exception('创建支付方式失败:' . $e->getMessage());
        }

        if (!$this->channel['currency_rate']) $this->channel['currency_rate'] = 1;
        if (!$this->channel['currency_code']) $this->channel['currency_code'] = 'cny';
        $amount = intval(round($ctx->order['realmoney'] * $this->channel['currency_rate'] * 100));

        $data = [
            'amount' => $amount,
            'currency' => strtolower($this->channel['currency_code']),
            'confirm' => 'true',
            'payment_method' => $payment_method_id,
            'payment_method_types' => [$payment_method],
            'description' => $ctx->ordername,
            'metadata' => [
                'order_id' => $tradeNo,
            ],
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];
        if ($payment_method == 'wechat_pay') {
            $data['payment_method_options']['wechat_pay']['client'] = 'web';
        }
        $result = $stripe->request('post', '/v1/payment_intents', $data);
        $this->updateOrder($tradeNo, $result['id']);
        if ($payment_method == 'alipay') {
            $url = $result['next_action']['alipay_handle_redirect']['url'];
        } elseif ($payment_method == 'wechat_pay') {
            $url = $result['next_action']['wechat_pay_display_qr_code']['data'];
        } else {
            $url = $result['next_action']['redirect_to_url']['url'];
        }
        return $url;
    }

    public function alipay(PaymentContext $ctx): array
    {
        try {
            $url = $this->paymentintent($ctx, 'alipay');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $url];
    }

    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $url = $this->paymentintent($ctx, 'wechat_pay');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $url];
        }
    }

    //异步回调
    public function webhook(PaymentContext $ctx): array
    {
        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);
        if (!$data) {
            http_response_code(400);
            return ['type' => 'html', 'data' => 'no data'];
        }

        if (isset($data['data']['object']['client_reference_id'])) {
            $out_trade_no = $data['data']['object']['client_reference_id'];
        } elseif (isset($data['data']['object']['metadata']['order_id'])) {
            $out_trade_no = $data['data']['object']['metadata']['order_id'];
        } else {
            http_response_code(400);
            return ['type' => 'html', 'data' => 'no order_id'];
        }
        $order = Db::name('order')->alias('A')->leftJoin('type B', 'A.type=B.id')
            ->where('A.trade_no', $out_trade_no)
            ->field('A.*,B.name typename,B.showname typeshowname')
            ->find();
        if (!$order) {
            http_response_code(400);
            return ['type' => 'html', 'data' => 'no order'];
        }

        $channel = \app\lib\Channel::get($order['channel']);
        if (!$channel) {
            http_response_code(400);
            return ['type' => 'html', 'data' => 'no channel'];
        }

        $endpoint_secret = $channel['appkey'];
        $sig_header = request()->header('stripe-signature', '');
        $event = null;

        try {
            $event = Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
        } catch (Exception $e) {
            http_response_code(400);
            return ['type' => 'html', 'data' => $e->getMessage()];
        }

        switch ($event['type']) {
            case 'checkout.session.completed':
                $session = $event['data']['object'];
                if ($session['payment_status'] == 'paid') {
                    ($this->markTrustedCallback($ctx, 'notify', 'stripe-webhook-signature'))(function () use ($order, $session) {
                        $this->processNotify($order, $session['payment_intent']);
                    });
                }
                break;
            case 'checkout.session.async_payment_succeeded':
                $session = $event['data']['object'];
                ($this->markTrustedCallback($ctx, 'notify', 'stripe-webhook-signature'))(function () use ($order, $session) {
                    $this->processNotify($order, $session['payment_intent']);
                });
                break;
            case 'payment_intent.succeeded':
                $session = $event['data']['object'];
                ($this->markTrustedCallback($ctx, 'notify', 'stripe-webhook-signature'))(function () use ($order, $session) {
                    $this->processNotify($order, $session['id']);
                });
                break;
        }
        return ['type' => 'html', 'data' => 'success'];
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
    }

    //同步回调
    public function error(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'error'];
    }

    public function query(array $order): array
    {
        if (empty($order['api_trade_no'])) throw new \Exception('api_trade_no is required');
        $stripe = new StripeClient($this->channel['appid']);
        $result = $stripe->request('get', '/v1/payment_intents/' . $order['api_trade_no']);
        return [
            'api_trade_no' => $result['id'],
            'status' => $result['status'] == 'succeeded' ? 1 : 0,
            'money' => $result['amount'] * $this->channel['currency_rate'] / 100,
        ];
    }

    //退款
    public function refund(array $order): array
    {
        if (!$this->channel['currency_rate']) $this->channel['currency_rate'] = 1;
        $amount = intval(round($order['refundmoney'] * $this->channel['currency_rate'] * 100));

        try {
            $stripe = new StripeClient($this->channel['appid']);
            $data = [
                'payment_intent' => $order['api_trade_no'],
                'amount' => $amount,
            ];
            $result = $stripe->request('post', '/v1/refunds', $data);
            return ['code' => 0, 'trade_no' => $result['payment_intent'], 'refund_fee' => $result['amount'] / 100];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }
}
