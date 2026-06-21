<?php

declare(strict_types=1);

namespace plugins\payment\fuiou3;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class Fuiou3Plugin extends BasePayment
{
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
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && ((in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) || in_array('3', $this->channel['apptype']))) {
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
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && ((in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) || in_array('3', $this->channel['apptype']))) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //通用下单
    private function addOrder(PaymentContext $ctx, string $pay_type, ?string $appid = null, ?string $openid = null): string
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($this->channel['appswitch'] == 1) {
            $apiurl = 'https://hlwnets-test.fuioupay.com/aggpos/order.fuiou';
        } else {
            $apiurl = 'https://hlwnets.fuioupay.com/aggpos/order.fuiou';
        }

        $param = [
            'order_date' => date('Ymd'),
            'order_id' => $tradeNo,
            'order_amt' => strval($ctx->order['realmoney'] * 100),
            'order_pay_type' => $pay_type,
            'back_notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'goods_name' => $ctx->ordername,
            'goods_detail' => $ctx->ordername,
        ];
        if ($appid) $param['appid'] = $appid;
        if ($openid) $param['openid'] = $openid;

        $client = new PayService($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);

        return self::lockPayData($tradeNo, function () use ($client, $apiurl, $param) {
            $result = $client->submit($apiurl, $param);
            return $result['order_info'];
        });
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
                $code_url = $this->addOrder($ctx, 'ALIPAY');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
        }
        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        }
        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    //支付宝JS支付
    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!empty($ctx->order['sub_openid'])) {
            $user_id = $ctx->order['sub_openid'];
            $user_type = 'userid';
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }
        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;
        if ($user_type == 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $payinfo = $this->addOrder($ctx, 'FWC', null, $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }
        $payinfo = json_decode($payinfo, true);
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
                $code_url = $this->addOrder($ctx, 'WECHAT');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('2', $this->channel['apptype'])) {
            if ($this->channel['appwxmp'] > 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
        } elseif (in_array('3', $this->channel['apptype'])) {
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
        $tradeNo = $ctx->order['trade_no'];

        if (!empty($ctx->order['sub_openid'])) {
            if (!empty($ctx->order['sub_appid'])) {
                $wxinfo = ['appid' => $ctx->order['sub_appid']];
            } else {
                if ($ctx->order['is_applet'] == 1) {
                    $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
                    if (!$wxinfo) {
                        return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
                    }
                } else {
                    $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
                    if (!$wxinfo) {
                        return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
                    }
                }
            }
            $openid = $ctx->order['sub_openid'];
        } else {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
            if (!$wxinfo) {
                return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
            }
            $openid = wechat_oauth($wxinfo);
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        try {
            $payinfo = $this->addOrder($ctx, $ctx->order['is_applet'] == 1 ? 'LETPAY' : 'JSAPI', $wxinfo['appid'], $openid);
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

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        if (in_array('3', $this->channel['apptype'])) {
            try {
                $token = $this->addOrder($ctx, 'TAPPLET');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            $code_url = 'weixin://dl/business/?appid=wx2877080d739b734a&path=pages/qrPay/qrPay&query=t%3D' . $token;
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } elseif ($this->channel['appwxa'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) {
                return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            }
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } else {
            return $this->wxpay($ctx);
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
        if ($blocks) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        
        try {
            $jsApiParameters = $this->addOrder($ctx, 'LETPAY', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }
        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($jsApiParameters, true)]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'UNIONPAY');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '银联云闪付下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data || empty($data['message'])) {
            return ['type' => 'html', 'data' => 'no data'];
        }

        $client = new PayService($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);
        $arr = $client->decryptNotify($data['message']);

        if ($arr) {
            if ($arr['order_st'] == 1) {
                $out_trade_no = $arr['order_id'];
                $trade_no = $arr['order_fas_ssn'];
                $money = $arr['order_amt'];
                $buyer = $arr['openid'] ?? '';
                $bill_trade_no = $arr['pay_ssn'] ?? null;
                $bill_mch_trade_no = $arr['fy_order_id'] ?? null;
                $end_time = $arr['pay_time'] ?? null;
                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
                return ['type' => 'html', 'data' => 'success'];
            }
            return ['type' => 'html', 'data' => 'status_fail'];
        }
        return ['type' => 'html', 'data' => 'decrypt_fail'];
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        if ($this->channel['appswitch'] == 1) {
            $apiurl = 'https://aggpc-test.fuioupay.com/aggpos/orderQuery.fuiou';
        } else {
            $apiurl = 'https://aggpcpay.fuioupay.com/aggpos/orderQuery.fuiou';
        }
        $param = [
            'order_date' => date('Ymd', strtotime($order['addtime'])),
            'order_id' => $order['trade_no'],
        ];
        $client = new PayService($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);
        $result = $client->submit($apiurl, $param);
        return [
            'api_trade_no' => $result['order_fas_ssn'],
            'status' => $result['order_st'] == 1 ? 1 : 0,
            'money' => $result['order_amt'] / 100,
            'buyer' => $result['openid'] ?? '',
            'bill_trade_no' => $result['pay_ssn'] ?? '',
            'bill_mch_trade_no' => $result['fy_order_id'] ?? '',
            'endtime' => $result['pay_time'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        if ($this->channel['appswitch'] == 1) {
            $apiurl = 'https://refund-transfer-test.fuioupay.com/refund_transfer/aggposRefund.fuiou';
        } else {
            $apiurl = 'https://refund-transfer.fuioupay.com/refund_transfer/aggposRefund.fuiou';
        }

        $param = [
            'refund_order_date' => date('Ymd'),
            'refund_order_id' => $order['refund_no'],
            'pay_order_date' => date('Ymd', strtotime($order['addtime'])),
            'pay_order_id' => $order['trade_no'],
            'refund_amt' => strval($order['refundmoney'] * 100),
        ];

        $client = new PayService($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);
        try {
            $result = $client->submit($apiurl, $param);
            return ['code' => 0, 'trade_no' => $result['pay_order_id'] ?? '', 'refund_fee' => $result['refund_amt'] ?? 0];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }
}
