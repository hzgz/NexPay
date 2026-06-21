<?php

declare(strict_types=1);

namespace plugins\payment\passpay;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class PasspayPlugin extends BasePayment
{
    private function createClient(): PasspayClient
    {
        return new PasspayClient(
            $this->channel['appurl'],
            $this->channel['appid'],
            $this->channel['appkey'],
            $this->channel['appsecret']
        );
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && (in_array('3', $this->channel['apptype']) || in_array('4', $this->channel['apptype']))) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'qqpay') {
            return ['type' => 'jump', 'url' => '/pay/qqpay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'bank') {
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice == 'wechat' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => request()->siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && (in_array('3', $this->channel['apptype']) || in_array('4', $this->channel['apptype']))) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'qqpay') {
            return $this->qqpay($ctx);
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //统一下单
    private function addOrder(PaymentContext $ctx, string $trade_type, ?string $sub_appid = null, ?string $sub_openid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->createClient();

        if (request()->get('d') == 1) {
            $return_url = request()->siteurl . 'pay/return/' . $tradeNo . '/';
        } else {
            $return_url = request()->siteurl . 'pay/ok/' . $tradeNo . '/';
        }
        $param = [
            'trade_type' => $trade_type,
            'pay_channel_id' => $this->channel['appmchid'],
            'out_trade_no' => $tradeNo,
            'total_amount' => $ctx->order['realmoney'],
            "subject" => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $return_url,
            'client_ip' => request()->clientip,
        ];
        if ($sub_appid && $sub_openid) {
            $param += [
                'sub_appid' => $sub_appid,
                'user_id' => $sub_openid,
                'channe_expend' => json_encode(['is_raw' => 1])
            ];
        }

        return self::lockPayData($tradeNo, function () use ($client, $param, $tradeNo) {
            $result = $client->execute('pay.order/create', $param);
            $this->updateOrder($tradeNo, $result['trade_no']);
            return $result;
        });
    }

    //支付宝支付
    public function alipay(PaymentContext $ctx): array
    {
        if (in_array('3', $this->channel['apptype']) && $ctx->isMobile) {
            $trade_type = 'alipayWap';
        } elseif (in_array('2', $this->channel['apptype']) && !$ctx->isMobile) {
            $trade_type = 'alipayPc';
        } elseif (in_array('4', $this->channel['apptype']) && !in_array('3', $this->channel['apptype'])) {
            $trade_type = 'alipayPub';
        } else {
            $trade_type = 'alipayQr';
        }
        try {
            $result = $this->addOrder($ctx, $trade_type);
            $code_url = $result['payurl'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, 'wechatQr');
            $code_url = $result['payurl'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
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
        $tradeNo = $ctx->order['trade_no'];

        if ($this->channel['appwxmp'] > 0) {
            //①、获取用户openid
            if (!empty($ctx->order['sub_openid'])) {
                if (!empty($ctx->order['sub_appid'])) {
                    $wxinfo['appid'] = $ctx->order['sub_appid'];
                } else {
                    if ($ctx->order['is_applet'] == 1) {
                        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
                        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
                    } else {
                        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
                        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
                    }
                }
                $openid = $ctx->order['sub_openid'];
            } else {
                $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
                if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
                try {
                    $openid = wechat_oauth($wxinfo);
                } catch (Exception $e) {
                    return ['type' => 'error', 'msg' => $e->getMessage()];
                }
            }
            $blocks = checkBlockUser($openid, $tradeNo);
            if ($blocks) return $blocks;

            //②、统一下单
            try {
                $result = $this->addOrder($ctx, $ctx->order['is_applet'] == 1 ? 'wechatLite' : 'wechatPub', $wxinfo['appid'], $openid);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            if ($ctx->method == 'jsapi') {
                return ['type' => 'jsapi', 'data' => $result['payInfo']];
            }

            if (request()->get('d') == 1) {
                $redirect_url = 'data.backurl';
            } else {
                $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
            }
            return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $result['payInfo'], 'redirect_url' => $redirect_url]];
        } else {
            try {
                $result = $this->addOrder($ctx, 'wechatPub');
                $code_url = $result['payurl'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'jump', 'url' => $code_url];
        }
    }

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $code = request()->get('code');
        if (empty($code)) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }

        //①、获取用户openid
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];
        try {
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];

        //②、统一下单
        try {
            $result = $this->addOrder($ctx, 'wechatLite', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($result['payInfo'], true)]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        if (in_array('4', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } else {
            if (in_array('3', $this->channel['apptype'])) {
                $trade_type = 'wechatWap';
            } else {
                $trade_type = 'wechatLiteH5';
            }
            try {
                $result = $this->addOrder($ctx, $trade_type);
                $code_url = $result['payurl'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'qrcode', 'page' => 'wxpay_h5', 'url' => $code_url];
        }
    }

    //QQ扫码支付
    public function qqpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'qqQr');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => 'QQ钱包支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'qq') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile && !request()->get('qrcode')) {
            return ['type' => 'qrcode', 'page' => 'qqpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'qqpay_qrcode', 'url' => $code_url];
        }
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'unionQr');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->createClient();
        $post = request()->post();
        $verify_result = $client->verifySign($post);

        if ($verify_result) {
            if ($post['order_status'] == 'SUCCESS') {
                $out_trade_no = $post['out_trade_no'];
                $trade_no = $post['trade_no'];
                $bill_trade_no = $post['channel_order_sn'] ?? '';
                $buyer = $post['user_id'] ?? '';
                if ($out_trade_no == $tradeNo) {
                    $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no);
                }
                return ['type' => 'html', 'data' => 'success'];
            }
            return ['type' => 'html', 'data' => 'status fail'];
        } else {
            return ['type' => 'html', 'data' => 'sign fail'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $client = $this->createClient();
        $result = $client->execute('pay.order/query', ['out_trade_no' => $order['trade_no']]);
        return [
            'api_trade_no' => $result['trade_no'],
            'status' => $result['order_status'] == 'SUCCESS' ? 1 : 0,
            'money' => $result['total_amount'],
            'buyer' => $result['user_id'] ?? '',
            'bill_trade_no' => $result['channel_order_sn'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->createClient();

        $param = [
            'refund_amount' => $order['refundmoney'],
            'refund_reason' => '订单退款',
            'out_refund_no' => $order['refund_no'],
            'trade_no' => $order['api_trade_no'],
        ];

        try {
            $result = $client->execute('pay.order/refund', $param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        return ['code' => 0, 'trade_no' => $result['trade_no'], 'refund_fee' => $result['refund_amount']];
    }
}
