<?php

declare(strict_types=1);

namespace plugins\payment\huishouqian;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class HuishouqianPlugin extends BasePayment
{
    private function getClient(): PayApp
    {
        $privateKeyPath = getCertFilePath($this->channel['private_key_path'] ?? '');
        $publicKeyPath = getCertFilePath($this->channel['cert_path'] ?? '');
        return new PayApp($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret'], $privateKeyPath, $publicKeyPath, $this->channel['appswitch'] == 1);
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

    //统一下单
    private function addOrder(PaymentContext $ctx, string $payType, ?string $appid = null, ?string $openid = null): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->mdevice === 'alipay' || $ctx->mdevice === 'wechat') {
            $pageUrl = $siteurl . 'pay/return/' . $tradeNo . '/';
        } else {
            $pageUrl = $siteurl . 'pay/ok/' . $tradeNo . '/';
        }

        $memo = ['spbillCreateIp' => request()->clientip, 'longitude' => '116.397128', 'latitude' => '39.916527'];
        if ($appid) $memo['appid'] = $appid;
        if ($openid) $memo['openid'] = $openid;
        $params = [
            'transNo' => $tradeNo,
            'payType' => $payType,
            'returnUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'pageUrl' => $pageUrl,
            'orderAmt' => strval($ctx->order['realmoney'] * 100),
            'goodsInfo' => $ctx->ordername,
            'requestDate' => date('YmdHis'),
            'memo' => json_encode($memo)
        ];

        $client = $this->getClient();

        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->submit('POLYMERIZE_MAIN_SWEPTN', $params);

            if ($result['respCode'] == 100213 || $result['respCode'] == 100201) {
                return $result['qrCode'];
            } else {
                throw new Exception('[' . $result['respCode'] . ']' . $result['respMsg']);
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
                $code_url = $this->addOrder($ctx, 'DYNAMIC_ALPAY');
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
            $alipay_trade_no = $this->addOrder($ctx, 'ALI_JSAPI', null, $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $alipay_trade_no];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $alipay_trade_no, 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            if ($this->channel['appwxmp'] > 0 && $this->channel['appwxa'] == 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
        } else {
            try {
                $code_url = $this->addOrder($ctx, 'DYNAMIC_WECHAT');
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
            $payinfo = $this->addOrder($ctx, $ctx->order['is_applet'] == 1 ? 'WECHAT_APPLET' : 'WECHAT_JSAPI', $wxinfo['appid'], $openid);
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
            $payinfo = $this->addOrder($ctx, 'WECHAT_APPLET', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($payinfo, true)]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'DYNAMIC_UNION');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'unionpay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $client = $this->getClient();
        $verify_result = $client->verifyNotify(request()->post());

        if ($verify_result) {//验证成功
            $params = json_decode(request()->post('signContent', ''), true);
            if (isset($params['orderStatus']) && $params['orderStatus'] == 'SUCCESS') {
                $out_trade_no = $params['transNo'];
                $api_trade_no = $params['tradeNo'];
                $buyer = $params['buyerName'] ?? null;
                $bill_trade_no = $params['payOrderNo'] ?? null;
                $bill_mch_trade_no = $params['channelOrderNo'] ?? null;
                $end_time = $params['finishedDate'];
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

    public function query(array $order): array
    {
        $client = $this->getClient();
        $param = [
            'transNo' => $order['trade_no'],
            'queryType' => '2',
        ];
        $result = $client->submit('POLYMERIZE_QUERY', $param);
        return [
            'api_trade_no' => $result['tradeNo'],
            'status' => $result['orderStatus'] == 'SUCCESS' ? 1 : 0,
            'money' => $result['orderAmt'],
            'buyer' => $result['buyerName'] ?? '',
            'bill_trade_no' => $result['payOrderNo'] ?? '',
            'bill_mch_trade_no' => $result['channelOrderNo'] ?? '',
            'endtime' => $result['finishedDate'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->getClient();

        $param = [
            'transNo' => $order['refund_no'],
            'refundType' => '2',
            'origTransNo' => $order['trade_no'],
            'origOrderAmt' => strval($order['realmoney'] * 100),
            'orderAmt' => strval($order['refundmoney'] * 100),
            'requestDate' => date('YmdHis'),
            'refundReason' => '申请退款',
        ];

        try {
            $result = $client->submit('POLYMERIZE_REFUND', $param);
            if ($result['orderStatus'] == 'PROCESSING' || $result['orderStatus'] == 'SUCCESS') {
                return ['code' => 0, 'trade_no' => $result['transNo'], 'refund_fee' => $result['orderAmt']];
            } else {
                return ['code' => -1, 'msg' => '[' . $result['respCode'] . ']' . $result['respMsg']];
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }
}
