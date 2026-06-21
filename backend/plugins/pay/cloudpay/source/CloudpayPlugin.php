<?php

declare(strict_types=1);

namespace plugins\payment\cloudpay;

use app\common\PaymentContext;
use app\common\BasePayment;

/**
 * @see https://opendocs.alipay.com/p/04acox
 */
class CloudpayPlugin extends BasePayment
{
    private function getClient(): AlipayEcoClient
    {
        return new AlipayEcoClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && ($this->channel['appwxa'] > 0 || $this->channel['appwxmp'] > 0)) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        }
        return ['type' => 'jump', 'url' => '/pay/' . $ctx->order['typename'] . '/' . $tradeNo . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && ($this->channel['appwxa'] > 0 || $this->channel['appwxmp'] > 0)) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //扫码支付
    private function qrcode(string $pay_type, PaymentContext $ctx): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $service = 'ant.antfin.eco.cloudpay.trade.precreate';
        $params = [
            'out_order_no' => $tradeNo,
            'total_amount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'cp_mid' => $this->channel['appmchid'],
            'cp_store_id' => $this->channel['appurl'],
            'pay_channel' => $pay_type,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'wechat_pay_request_ext' => [
                'product_id' => '10001'
            ],
        ];

        $client = $this->getClient();
        return self::lockPayData($tradeNo, function () use ($client, $service, $params) {
            $result = $client->execute($service, $params);
            return $result['qr_code'];
        });
    }

    //小程序/JS支付
    private function jspay(string $pay_type, string $buyer_id, PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $service = 'ant.antfin.eco.cloudpay.trade.create';
        $params = [
            'out_order_no' => $tradeNo,
            'total_amount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'buyer_id' => $buyer_id,
            'cp_mid' => $this->channel['appmchid'],
            'cp_store_id' => $this->channel['appurl'],
            'pay_channel' => $pay_type,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        $client = $this->getClient();
        return self::lockPayData($tradeNo, function () use ($client, $service, $params) {
            $result = $client->execute($service, $params);
            return $result;
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode('alipay', $ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode('wechat', $ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
    }

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        //①、获取用户openid
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
        try {
            $openid = wechat_oauth($wxinfo);
        } catch (\Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        //②、统一下单
        try {
            $result = $this->jspay('wechat', $openid, $ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        $pay_info = json_encode(['appId' => $result['wechat_app_id'], 'timeStamp' => $result['time_stamp'], 'nonceStr' => $result['nonce_str'], 'package' => $result['package_info'], 'signType' => $result['sign_type'], 'paySign' => $result['pay_sign']]);

        if ($ctx->method === 'jsapi') {
            return ['type' => 'jsapi', 'data' => $pay_info];
        }
        if (request()->get('d') === '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $pay_info, 'redirect_url' => $redirect_url]];
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
        } catch (\Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        //②、统一下单
        try {
            $result = $this->jspay('wechat', $openid, $ctx);
        } catch (\Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }
        $jsApiParameters = ['appId' => $result['wechat_app_id'], 'timeStamp' => $result['time_stamp'], 'nonceStr' => $result['nonce_str'], 'package' => $result['package_info'], 'signType' => $result['sign_type'], 'paySign' => $result['pay_sign']];

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $jsApiParameters]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($this->channel['appwxa'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (\Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } else {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        if (!request()->post('out_order_no')) return ['type' => 'html', 'data' => 'No data'];

        $sign = request()->header('X-Ca-Signature');

        //计算得出通知验证结果
        $client = $this->getClient();
        $verify_result = $client->verify(request()->post(), $sign);

        if ($verify_result) { //验证成功
            $out_trade_no = request()->post('out_order_no');
            $trade_no = request()->post('trade_no');
            $buyer_id = request()->post('buyer_id');
            $total_amount = request()->post('total_amount');

            if (request()->post('trade_status') == 'ORDER_SUCCESS') {
                if ($out_trade_no == $ctx->order['trade_no']) {
                    ($this->markTrustedCallback($ctx, 'notify', 'cloudpay-signature'))(function () use ($ctx, $trade_no, $buyer_id) {
                        $this->processNotify($ctx->order, $trade_no, $buyer_id);
                    });
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
    }

    //退款
    public function refund(array $order): array
    {
        $service = 'ant.antfin.eco.cloudpay.trade.refund';
        $params = [
            'cp_mid' => $this->channel['appmchid'],
            'cp_store_id' => $this->channel['appurl'],
            'out_order_no' => $order['trade_no'],
            'out_request_no' => $order['refund_no'],
            'refund_amount' => $order['refundmoney'],
        ];

        $client = $this->getClient();
        try {
            $result = $client->execute($service, $params);
            return ['code' => 0, 'trade_no' => $result['out_order_no'], 'refund_fee' => $result['refund_amount']];
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }
}
