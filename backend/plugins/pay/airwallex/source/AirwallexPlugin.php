<?php

declare(strict_types=1);

namespace plugins\payment\airwallex;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class AirwallexPlugin extends BasePayment
{
    private function createClient(): AirwallexClient
    {
        $sandbox = ($this->channel['appurl'] ?? '0') === '1';
        return new AirwallexClient(
            $this->channel['appid'],
            $this->channel['appkey'],
            $this->channel['appsecret'],
            $sandbox
        );
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $apptype) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && (in_array('3', $apptype) || in_array('4', $apptype))) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->method === 'jsapi') {
            if ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $apptype) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && (in_array('3', $apptype) || in_array('4', $apptype))) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    private function createAndConfirmIntent(PaymentContext $ctx, array $paymentMethod): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $client = $this->createClient();

        $returnUrl = $siteurl . 'pay/return/' . $tradeNo . '/';

        $params = [
            'amount' => (float) $ctx->order['realmoney'],
            'currency' => 'CNY',
            'merchant_order_id' => $tradeNo,
            'request_id' => $tradeNo,
            'descriptor' => mb_substr($ctx->ordername, 0, 32),
            'return_url' => $returnUrl,
        ];

        return self::lockPayData($tradeNo, function () use ($client, $tradeNo, $params, $paymentMethod) {
            $intent = $client->request('POST', '/pa/payment_intents/create', $params);

            $intentId = $intent['id'];
            $this->updateOrder($tradeNo, $intentId);

            $result = $client->request('POST', '/pa/payment_intents/' . $intentId . '/confirm', [
                'payment_method' => $paymentMethod,
                'request_id' => $intentId . '_confirm_' . time(),
            ]);
            return $result;
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $result = $this->createAndConfirmIntent($ctx, [
                'type' => 'alipaycn',
                'alipaycn' => ['flow' => 'qrcode'],
            ]);
            $code_url = $result['next_action']['url'] ?? '';
            if (empty($code_url)) {
                return ['type' => 'error', 'msg' => '支付宝下单失败，未获取到二维码'];
            }
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'jump', 'url' => $code_url];
    }

    //支付宝手机网站支付
    public function alipaywap(PaymentContext $ctx): array
    {
        $os_type = strpos(request()->header('user-agent', ''), 'iPhone') !== false ? 'ios' : 'android';
        try {
            $result = $this->createAndConfirmIntent($ctx, [
                'type' => 'alipaycn',
                'alipaycn' => ['flow' => 'mobile_web', 'os_type' => $os_type],
            ]);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }

        $code_url = $result['next_action']['url'] ?? '';
        if (empty($code_url)) {
            return ['type' => 'error', 'msg' => '支付宝下单失败，未获取到跳转地址'];
        }
        return ['type' => 'jump', 'url' => $code_url];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if (in_array('1', $apptype)) {
            try {
                $result = $this->createAndConfirmIntent($ctx, [
                    'type' => 'wechatpay',
                    'wechatpay' => ['flow' => 'qrcode'],
                ]);
                $code_url = $result['next_action']['qrcode'] ?? '';
                if (empty($code_url)) {
                    return ['type' => 'error', 'msg' => '微信支付下单失败，未获取到二维码'];
                }
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('2', $apptype)) {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
        } elseif (in_array('3', $apptype) || in_array('4', $apptype)) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } else {
            return ['type' => 'error', 'msg' => '未配置支付方式'];
        }

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        try {
            $result = $this->createAndConfirmIntent($ctx, [
                'type' => 'wechatpay',
                'wechatpay' => ['flow' => 'official_account'],
            ]);
            $code_url = $result['next_action']['url'] ?? '';
            if (empty($code_url)) {
                return ['type' => 'error', 'msg' => '微信支付下单失败，未获取到跳转地址'];
            }
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'jump', 'url' => $code_url];
    }

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $code = request()->get('code', '', 'trim');
        if ($code === '') {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }

        //①、获取用户openid
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];
        }
        try {
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        //②、统一下单
        try {
            $result = $this->createAndConfirmIntent($ctx, [
                'type' => 'wechatpay',
                'wechatpay' => [
                    'flow' => 'mini_program',
                    'open_id' => $openid,
                ],
            ]);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }

        $payinfo = $result['next_action']['data'] ?? [];

        if (isset($payinfo)) {
            return ['type' => 'json', 'data' => ['code' => 0, 'data' => $payinfo]];
        }

        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信小程序下单失败']];
    }

    //微信H5支付
    public function wxwappay(PaymentContext $ctx): array
    {
        if (in_array('4', $this->channel['apptype'])) {
            try {
                $result = $this->createAndConfirmIntent($ctx, [
                    'type' => 'wechatpay',
                    'wechatpay' => ['flow' => 'mobile_web'],
                ]);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信H5支付下单失败！' . $ex->getMessage()];
            }
    
            $code_url = $result['next_action']['url'] ?? '';
    
            if (empty($code_url)) {
                return ['type' => 'error', 'msg' => '微信H5支付下单失败，未获取到跳转地址'];
            }
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        }
    }

    //Webhook异步回调
    public function webhook(PaymentContext $ctx): array
    {
        $payload = request()->getContent();
        $data = json_decode($payload, true);
        if (!$data) {
            http_response_code(400);
            return ['type' => 'html', 'data' => 'no data'];
        }

        $intentData = $data['data']['object'] ?? [];
        $out_trade_no = $intentData['merchant_order_id'] ?? '';
        if (empty($out_trade_no)) {
            http_response_code(400);
            return ['type' => 'html', 'data' => 'no order_id'];
        }

        $order = \think\facade\Db::name('order')->alias('A')->leftJoin('type B', 'A.type=B.id')
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

        $timestamp = request()->header('x-timestamp', '');
        $signature = request()->header('x-signature', '');
        if (empty($signature) || empty($timestamp)) {
            http_response_code(400);
            return ['type' => 'html', 'data' => 'missing signature'];
        }

        $webhookSecret = $channel['appsecret'];
        $expectedSignature = hash_hmac('sha256', $timestamp . $payload, $webhookSecret);
        if (!hash_equals($expectedSignature, $signature)) {
            http_response_code(400);
            return ['type' => 'html', 'data' => 'sign fail'];
        }

        $eventName = $data['name'] ?? '';
        if ($eventName === 'payment_intent.succeeded') {
            $intentId = $intentData['id'] ?? '';
            $attempt = $intentData['latest_payment_attempt'] ?? [];
            $buyer = null;
            $paymentMethod = $attempt['payment_method'] ?? [];
            if (isset($paymentMethod['alipaycn'])) {
                $buyer = $paymentMethod['alipaycn']['user_id'] ?? null;
            } elseif (isset($paymentMethod['wechatpay'])) {
                $buyer = $paymentMethod['wechatpay']['open_id'] ?? null;
            }
            $bill_trade_no = $attempt['payment_method_transaction_id'] ?? null;
            $bill_mch_trade_no = $attempt['provider_transaction_id'] ?? null;
            $endTime = $intentData['updated_at'] ?? null;

            ($this->markTrustedCallback($ctx, 'notify', 'airwallex-webhook-hmac'))(function () use ($order, $intentId, $buyer, $bill_trade_no, $endTime) {
                $this->processNotify($order, $intentId, $buyer, $bill_trade_no, null, $endTime);
            });
        }

        return ['type' => 'html', 'data' => 'success'];
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        if (empty($order['api_trade_no'])) throw new \Exception('api_trade_no is required');
        $client = $this->createClient();
        $result = $client->request('GET', '/pa/payment_intents/' . $order['api_trade_no']);
        $attempt = $result['latest_payment_attempt'] ?? [];
        $paymentMethod = $attempt['payment_method'] ?? [];
        $buyer = null;
        if (isset($paymentMethod['alipaycn'])) {
            $buyer = $paymentMethod['alipaycn']['user_id'] ?? null;
        } elseif (isset($paymentMethod['wechatpay'])) {
            $buyer = $paymentMethod['wechatpay']['open_id'] ?? null;
        }
        return [
            'api_trade_no' => $result['id'],
            'status' => $result['status'] == 'SUCCEEDED' ? 1 : 0,
            'money' => $result['amount'],
            'buyer' => $buyer,
            'bill_trade_no' => $attempt['payment_method_transaction_id'] ?? null,
            'endtime' => $result['updated_at'] ?? null,
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->createClient();

        $params = [
            'payment_intent_id' => $order['api_trade_no'],
            'amount' => (float) $order['refundmoney'],
            'reason' => '订单退款',
            'request_id' => $order['refund_no'],
        ];
        try {
            $result = $client->request('POST', '/pa/refunds/create', $params);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        $status = $result['status'] ?? '';
        if (in_array($status, ['RECEIVED', 'ACCEPTED', 'SETTLED'])) {
            return ['code' => 0, 'trade_no' => $result['id'] ?? '', 'refund_fee' => $result['amount'] ?? $order['refundmoney']];
        } else {
            $msg = $result['failure_details']['message'] ?? '退款失败';
            return ['code' => -1, 'msg' => $msg];
        }
    }
}
