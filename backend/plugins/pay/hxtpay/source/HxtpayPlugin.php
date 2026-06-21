<?php

declare(strict_types=1);

namespace plugins\payment\hxtpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class HxtpayPlugin extends BasePayment
{
    private function getClient(): HXTClient
    {
        return new HXTClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $this->channel['appwxa'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //主扫支付
    private function qrcode(PaymentContext $ctx, string $pay_type): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $params = [
            'mch_id' => $this->channel['appmchid'],
            'out_trade_no' => $tradeNo,
            'body' => $ctx->ordername,
            'total_fee' => strval($ctx->order['realmoney'] * 100),
            'mch_create_ip' => request()->clientip,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'pay_type' => $pay_type,
            'trade_type' => '1',
        ];

        $result = self::lockPayData($tradeNo, function () use ($client, $params) {
            return $client->execute('/api/pay/unifiedPay', $params);
        });
        return $result['code_url'] ?? $result['qr_code'];
    }

    //JS支付
    private function jsapi(PaymentContext $ctx, string $pay_type, string $openid, ?string $appid = null, ?string $is_minipg = null): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $params = [
            'mch_id' => $this->channel['appmchid'],
            'out_trade_no' => $tradeNo,
            'body' => $ctx->ordername,
            'total_fee' => strval($ctx->order['realmoney'] * 100),
            'mch_create_ip' => request()->clientip,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'pay_type' => $pay_type,
            'trade_type' => '3',
        ];
        if ($pay_type == '3') {
            $params['buyer_id'] = $openid;
        } elseif ($pay_type == '2') {
            $params['is_minipg'] = $is_minipg;
            $params['sub_openid'] = $openid;
            $params['sub_appid'] = $appid;
        } elseif ($pay_type == '1') {
            $params['auth_id'] = $openid;
        }

        $result = self::lockPayData($tradeNo, function () use ($client, $params) {
            return $client->execute('/api/pay/unifiedPay', $params);
        });
        return $result['pay_info'];
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $code_url = $this->qrcode($ctx, '3');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
            }
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $user_type = null;

        if (!empty($ctx->order['sub_openid'])) {
            $user_id = $ctx->order['sub_openid'];
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }

        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;

        if ($user_type == 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $result = $this->jsapi($ctx, 'AliPayJsapi', $user_id);
            $payinfo = json_decode($result, true);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $payinfo['tradeNO']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $payinfo['tradeNO'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype'])) {
            try {
                $code_url = $this->qrcode($ctx, '2');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } else {
            if ($this->channel['appwxa'] > 0 && $this->channel['appwxmp'] == 0) {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            }
        }

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
        try {
            $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
    }

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

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
            $openid = wechat_oauth($wxinfo);
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        //②、统一下单
        try {
            $payinfo = $this->jsapi($ctx, '2', $openid, $wxinfo['appid'], $ctx->order['is_applet'] == 1 ? '1' : '0');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $payinfo];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $payinfo, 'redirect_url' => $redirect_url]];
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
            $payinfo = $this->jsapi($ctx, '2', $openid, $wxinfo['appid'], '1');
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($payinfo, true)]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, '1');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->getClient();
        $verify_result = $client->verify($data);

        if ($verify_result) {//验证成功
            if ($data['pay_result'] == '0') {
                $out_trade_no = $data['out_trade_no'];
                $api_trade_no = $data['transaction_id'];
                $money = $data['total_fee'] / 100;
                $buyer = $data['buyer_user_id'] ?? $data['sub_openid'] ?? null;
                $bill_trade_no = $data['out_transaction_id'] ?? null;
                $end_time = $data['time_end'];
                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'sign error'];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $client = $this->getClient();
        $result = $client->execute('/api/order/orderQuery', [
            'out_trade_no' => $order['trade_no'],
            'mch_id' => $this->channel['appmchid'],
        ]);
        return [
            'api_trade_no' => $result['transaction_id'],
            'status' => $result['trade_state'] == 'SUCCESS' ? 1 : 0,
            'money' => $result['total_fee'] / 100,
            'buyer' => $result['buyer_user_id'] ?? $result['sub_openid'] ?? null,
            'bill_trade_no' => $result['out_transaction_id'] ?? null,
            'endtime' => $result['time_end'],
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->getClient();

        $params = [
            'mch_id' => $this->channel['appmchid'],
            'order_no' => $order['api_trade_no'],
            'out_refund_no' => $order['refund_no'],
            'refund_fee' => strval($order['refundmoney'] * 100),
            'remark' => '订单退款',
            'mch_create_ip' => request()->clientip,
        ];

        try {
            $result = $client->execute('/api/order/unifiedOrderRefund', $params);
            return ['code' => 0, 'trade_no' => $result['refund_no'], 'refund_fee' => $result['refund_fee'] / 100];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //结算异步回调
    public function settlenotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->getClient();
        $verify_result = $client->verify($data);

        if ($verify_result) {//验证成功
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->settlenotify($data);
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'sign error'];
        }
    }
}
