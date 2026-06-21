<?php

declare(strict_types=1);

namespace plugins\payment\jindd;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class JinddPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $apptype)) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('1', $apptype) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && in_array('1', $apptype) && $this->channel['appwxa'] > 0) {
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
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->method === 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->method === 'applet') {
            return $this->wxplugin($ctx);
        } elseif ($ctx->method === 'app') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->aliapppay($ctx);
            } else {
                return $this->wxapppay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $apptype)) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('1', $apptype) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && in_array('1', $apptype) && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    private function getClient(): JinddClient
    {
        return new JinddClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);
    }

    //JSAPI支付
    private function jspay(PaymentContext $ctx, string $pay_type, string $js_flag, ?string $sub_openid = null, ?string $sub_appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $param = [
            'pay_type' => $pay_type,
            'mch_id' => $this->channel['mch_id'],
            'sub_mchid' => $this->channel['sub_mchid'],
            'out_trade_no' => $tradeNo,
            'total_amount' => strval($ctx->order['realmoney'] * 100),
            'subject' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'js_flag' => $js_flag,
            'payer_ip' => request()->clientip,
        ];
        if ($sub_openid) $param['open_id'] = $sub_openid;
        if ($sub_appid) $param['sub_appid'] = $sub_appid;

        return self::lockPayData($tradeNo, function () use ($client, $param, $tradeNo) {
            $result = $client->execute('yz.trade.jspay', $param);
            //$this->updateOrder($tradeNo, $result['trade_no']);
            return $result;
        });
    }

    //条码支付
    private function scanpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $param = [
            'mch_id' => $this->channel['mch_id'],
            'sub_mchid' => $this->channel['sub_mchid'],
            'auth_code' => $ctx->order['auth_code'],
            'out_trade_no' => $tradeNo,
            'total_amount' => strval($ctx->order['realmoney'] * 100),
            'subject' => $ctx->ordername,
            'payer_ip' => request()->clientip,
        ];

        return self::lockPayData($tradeNo, function () use ($client, $param, $tradeNo) {
            $result = $client->execute('yz.trade.pay', $param);
            //$this->updateOrder($tradeNo, $result['trade_no']);
            return $result;
        });
    }

    //收银台小程序支付
    private function minipay(PaymentContext $ctx, string $pay_type, bool $isplugin = false): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $param = [
            'pay_type' => $pay_type,
            'mch_id' => $this->channel['mch_id'],
            'sub_mchid' => $this->channel['sub_mchid'],
            'out_trade_no' => $tradeNo,
            'total_amount' => strval($ctx->order['realmoney'] * 100),
            'subject' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        return self::lockPayData($tradeNo, function () use ($client, $param, $isplugin, $tradeNo) {
            $result = $client->execute($isplugin ? 'yz.trade.minipluginpay' : 'yz.trade.miniqrpay', $param);
            //$this->updateOrder($tradeNo, $result['trade_no']);
            return $result;
        });
    }

    //支付宝支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if (in_array('2', $apptype) && !in_array('1', $apptype)) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $result = $this->minipay($ctx, 'ALIPAY');
                $code_url = $result['mini_url'];
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
            $result = $this->jspay($ctx, 'ALIPAY', '0', $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method === 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['channel_trade_no']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['channel_trade_no'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if ($this->channel['appwxa'] > 0 && in_array('1', $apptype)) {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
        } elseif ($this->channel['appwxmp'] > 0 && in_array('1', $apptype)) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } else {
            try {
                $result = $this->minipay($ctx, 'WECHAT');
                $code_url = $result['mini_url'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
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
            $result = $this->jspay($ctx, 'WECHAT', $ctx->order['is_applet'] == 1 ? '1' : '0', $openid, $wxinfo['appid']);
            $payinfo = ['appId' => $result['appid'], 'timeStamp' => $result['time_stamp'], 'nonceStr' => $result['nonce_str'], 'package' => $result['package_str'], 'signType' => $result['pay_sign_type'], 'paySign' => $result['pay_sign']];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method === 'jsapi') {
            return ['type' => 'jsapi', 'data' => json_encode($payinfo)];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => json_encode($payinfo), 'redirect_url' => $redirect_url]];
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
            $result = $this->jspay($ctx, 'WECHAT', '1', $openid, $wxinfo['appid']);
            $payinfo = ['appId' => $result['appid'], 'timeStamp' => $result['time_stamp'], 'nonceStr' => $result['nonce_str'], 'package' => $result['package_str'], 'signType' => $result['pay_sign_type'], 'paySign' => $result['pay_sign']];
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }
        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $payinfo]];
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

    //微信小程序插件支付
    public function wxplugin(PaymentContext $ctx): array
    {
        try {
            $result = $this->minipay($ctx, 'WECHAT', true);
            $payinfo = ['appId' => $result['appid'], 'supplierId' => $result['mch_id'], 'shopId' => $result['sub_mchid'], 'orderId' => $result['order_id']];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxplugin', 'data' => $payinfo];
    }

    //支付宝APP支付
    public function aliapppay(PaymentContext $ctx): array
    {
        try {
            $result = $this->minipay($ctx, 'ALIPAY');
            $qr_code_key = $result['qr_code_key'];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        $code_url = 'alipays://platformapi/startapp?appId=2021003182657355&page=pages/fixedAmountPay/main&query=j%3D' . $qr_code_key;
        return ['type' => 'scheme', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    //微信APP支付
    public function wxapppay(PaymentContext $ctx): array
    {
        try {
            $result = $this->minipay($ctx, 'WECHAT');
            $qr_code_key = $result['qr_code_key'];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => '', 'miniProgramId' => 'gh_4c26f65ff0c2', 'path' => 'pages/fixedAmountPay/main?j=' . $qr_code_key]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->minipay($ctx, 'UNIONPAY');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $client = $this->getClient();
        $verify_result = $client->verifySign(request()->post());

        if ($verify_result) {
            $arr = json_decode(request()->post('biz_content', '{}'), true);
            if ($arr['trade_status'] == 'SUCCESS') {
                $out_trade_no = $arr['out_trade_no'];
                $trade_no = $arr['trade_no'];
                $bill_trade_no = $arr['channel_trade_no'];
                $bill_mch_trade_no = $arr['mch_trade_no'] ?? null;
                $buyer = $arr['open_id'] ?? null;
                $end_time = $arr['pay_time'];
                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
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
        $client = $this->getClient();
        $param = [
            'out_trade_no' => $order['trade_no'],
        ];
        $result = $client->execute('yz.trade.query', $param);
        return [
            'api_trade_no' => $result['trade_no'],
            'status' => $result['trade_status'] == 'SUCCESS' ? 1 : 0,
            'money' => $result['total_amount'] / 100,
            'buyer' => $result['open_id'] ?? '',
            'bill_trade_no' => $result['channel_trade_no'] ?? '',
            'bill_mch_trade_no' => $result['mch_trade_no'] ?? '',
            'endtime' => $result['pay_time'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->getClient();

        $param = [
            'mch_id' => $this->channel['mch_id'],
            'sub_mchid' => $this->channel['sub_mchid'],
            'out_trade_no' => $order['trade_no'],
            'trade_no' => $order['api_trade_no'],
            'out_refund_no' => $order['refund_no'],
            'refund_amount' => strval($order['refundmoney'] * 100),
            'refund_reason' => '订单退款',
        ];

        try {
            $result = $client->execute('yz.trade.refund', $param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        return ['code' => 0, 'trade_no' => $result['refund_no'], 'refund_fee' => $result['refund_amount'] / 100];
    }
}
