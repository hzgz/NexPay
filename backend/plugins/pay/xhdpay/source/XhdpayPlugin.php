<?php

declare(strict_types=1);

namespace plugins\payment\xhdpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

/**
 * http://wd.xmxhd.net/web/#/662519312/0 密码：123456
 */
class XhdpayPlugin extends BasePayment
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
            } elseif ($ctx->isMobile && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
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
            } elseif ($ctx->isMobile && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    private function makeSign(array $param, string $key): string
    {
        uksort($param, function ($a, $b) {
            return strcasecmp($a, $b);
        });
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != 'sign' && $v !== null && $v !== '') {
                if (is_array($v)) {
                    $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        return md5($signstr . $key);
    }

    private function qrcode(PaymentContext $ctx, string $payType): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $requrl = 'https://gateway.xhdpay.com/hxdpay/make/unifiedUrl';
        $params = [
            'merchantNo' => $this->channel['appid'],
            'merOrderNo' => $tradeNo,
            'orderDesc' => $ctx->ordername,
            'orderAmount' => intval(round($ctx->order['realmoney'] * 100)),
            'terminalNo' => $this->channel['appmchid'],
            'payType' => $payType,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returnUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'terminalIp' => request()->clientip,
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($requrl, $params, $tradeNo) {
            $response = get_curl($requrl, http_build_query($params));
            $result = json_decode($response, true);
            if (isset($result['success']) && $result['success'] == 1) {
                $this->updateOrder($tradeNo, $result['biz']['orderNo']);
                return $result['biz']['payUrl'];
            } else {
                throw new Exception($result['message'] ? $result['message'] : '返回数据解析失败');
            }
        });
    }

    private function jsapipay(PaymentContext $ctx, string $payType, ?string $openid = null, ?string $appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $requrl = 'https://gateway.xhdpay.com/hxdpay/make/wxpay';
        $params = [
            'merchantNo' => $this->channel['appid'],
            'merOrderNo' => $tradeNo,
            'orderDesc' => $ctx->ordername,
            'orderAmount' => intval(round($ctx->order['realmoney'] * 100)),
            'terminalNo' => $this->channel['appmchid'],
            'payType' => $payType,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returnUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'openId' => $openid,
            'appId' => $appid,
            'terminalIp' => request()->clientip,
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($requrl, $params, $tradeNo) {
            $response = get_curl($requrl, http_build_query($params));
            $result = json_decode($response, true);
            if (isset($result['success']) && $result['success'] == 1) {
                $this->updateOrder($tradeNo, $result['biz']['orderNo']);
                return $result['biz'];
            } else {
                throw new Exception($result['message'] ? $result['message'] : '返回数据解析失败');
            }
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
                $code_url = $this->qrcode($ctx, 'ALIPAY');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
            }
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif (str_starts_with($code_url, 'alipays://') && $ctx->isMobile) {
            return ['type' => 'page', 'page' => 'alipay_h5', 'data' => ['code_url' => $code_url, 'redirect_url' => 'data.backurl']];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $user_type = '';

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
            $payinfo = $this->jsapipay($ctx, 'ALIPAY', $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if (isset($payinfo['payData']['payUrl'])) {
            return ['type' => 'jump', 'url' => $payinfo['payData']['payUrl']];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $payinfo['payData']['tradeNO']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $payinfo['payData']['tradeNO'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype'])) {
            try {
                $result = $this->jsapipay($ctx, 'cashierPay');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            if ($ctx->isMobile) {
                $code_url = 'https://gateway.xhdpay.com/h5/hxdCashierPay/shengwangwx.html?hxd=' . $result['orderNo'];
                return ['type' => 'jump', 'url' => $code_url];
            } else {
                $code_url = 'https://gateway.xhdpay.com/wxmini?o=' . $result['orderNo'];
            }
        } else {
            if ($this->channel['appwxmp'] > 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
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

        if (!empty($ctx->order['sub_openid'])) {
            if (!empty($ctx->order['sub_appid'])) {
                $wxinfo['appid'] = $ctx->order['sub_appid'];
            } else {
                $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
                if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
            }
            $openid = $ctx->order['sub_openid'];
        } else {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
            $openid = wechat_oauth($wxinfo);
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        try {
            $payinfo = $this->jsapipay($ctx, 'MP', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
        }
        if (isset($payinfo['payData']['payUrl'])) {
            return ['type' => 'jump', 'url' => $payinfo['payData']['payUrl']];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => json_encode($payinfo)];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => json_encode($payinfo['payData']), 'redirect_url' => $redirect_url]];
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
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        try {
            $payinfo = $this->jsapipay($ctx, 'WXMini', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }
        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $payinfo['payData']]];
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

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, 'UNIONPAY');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => 'No data'];

        $sign = $this->makeSign($arr['biz'], $this->channel['appkey']);

        if ($sign === $arr['sign']) {
            if ($arr['biz']['notifyType'] == 'Paid') {
                $out_trade_no = $arr['biz']['merOrderNo'];
                $api_trade_no = $arr['biz']['orderNo'];
                $buyer = $arr['biz']['paidInfo']['buyerId'] ?? null;
                $bill_trade_no = $arr['biz']['paidInfo']['originalOrder'] ?? null;
                $bill_mch_trade_no = $arr['biz']['channelOrderNo'] ?? null;
                $end_time = $arr['biz']['paidTime'] ?? null;

                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            return ['type' => 'html', 'data' => 'FAIL'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    //退款
    public function refund(array $order): array
    {
        $requrl = 'https://gateway.xhdpay.com/hxdpay/refund';
        $params = [
            'merchantNo' => $this->channel['appid'],
            'merRefundOrderNo' => $order['refund_no'],
            'orderNo' => $order['api_trade_no'],
            'refundAmount' => intval(round($order['refundmoney'] * 100)),
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        $response = get_curl($requrl, http_build_query($params));
        $result = json_decode($response, true);
        if (isset($result['success']) && $result['success'] == 1) {
            return ['code' => 0, 'trade_no' => $result['biz']['refundOrderNo'], 'refund_fee' => $order['refundmoney']];
        } else {
            return ['code' => -1, 'msg' => $result['message'] ? $result['message'] : '返回数据解析失败'];
        }
    }
}
