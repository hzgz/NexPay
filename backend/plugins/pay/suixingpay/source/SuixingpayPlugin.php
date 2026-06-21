<?php

declare(strict_types=1);

namespace plugins\payment\suixingpay;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class SuixingpayPlugin extends BasePayment
{
    private function createClient(): SuixingpayClient
    {
        return new SuixingpayClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);
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
            if ($this->channel['appwxmp'] > 0 && $ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($this->channel['appwxa'] > 0 && $ctx->isMobile) {
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
        } elseif ($ctx->method == 'applet') {
            return $this->wxplugin($ctx);
            //return $this->wxapplet($ctx);
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($this->channel['appwxmp'] > 0 && $ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($this->channel['appwxa'] > 0 && $ctx->isMobile) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //扫码支付
    private function qrcode(PaymentContext $ctx, string $type): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        $params = [
            'mno' => $this->channel['appmchid'],
            'ordNo' => $tradeNo,
            'amt' => $ctx->order['realmoney'],
            'payType' => $type,
            'subject' => $ctx->ordername,
            'trmIp' => $clientip,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->submit('/order/activeScan', $params);

            if ($result['bizCode'] == '0000') {
                return $result['payUrl'];
            } else {
                throw new Exception('[' . $result['bizCode'] . ']' . $result['bizMsg']);
            }
        });
    }

    //JS支付
    private function jsapi(PaymentContext $ctx, string $type, string $subAppid, string $userId, bool $is_mini = false): mixed
    {
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        $payWay = $type == 'WECHAT' && $is_mini ? '03' : '02';

        $params = [
            'mno' => $this->channel['appmchid'],
            'ordNo' => $tradeNo,
            'amt' => $ctx->order['realmoney'],
            'payType' => $type,
            'payWay' => $payWay,
            'subject' => $ctx->ordername,
            'trmIp' => $clientip,
            'subAppid' => $subAppid,
            'userId' => $userId,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $type) {
            $result = $client->submit('/order/jsapiScan', $params);

            if ($result['bizCode'] == '0000') {
                if ($type == 'WECHAT') {
                    return ['appId' => $result['payAppId'], 'timeStamp' => $result['payTimeStamp'], 'nonceStr' => $result['paynonceStr'], 'package' => $result['payPackage'], 'signType' => $result['paySignType'], 'paySign' => $result['paySign']];
                } elseif ($type == 'ALIPAY') {
                    return $result['source'];
                } elseif ($type == 'UNIONPAY') {
                    return $result['redirectUrl'];
                }
            } else {
                throw new Exception('[' . $result['bizCode'] . ']' . $result['bizMsg']);
            }
        });
    }

    //小程序收银台
    private function appletPay(PaymentContext $ctx, string $appletSource): mixed
    {
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        $params = [
            'mno' => $this->channel['appmchid'],
            'ordNo' => $tradeNo,
            'amt' => $ctx->order['realmoney'],
            'appletSource' => $appletSource,
            'subject' => $ctx->ordername,
            'trmIp' => $clientip,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->submit('/order/appletScanPre', $params);

            if ($result['bizCode'] == '0000') {
                return $result;
            } else {
                throw new Exception('[' . $result['bizCode'] . ']' . $result['bizMsg']);
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
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!empty($ctx->order['sub_openid'])) {
            $user_id = $ctx->order['sub_openid'];
            $user_type = null;
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }

        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;
        if ($user_type == 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $alipay_trade_no = $this->jsapi($ctx, 'ALIPAY', '', $user_id);
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
                $code_url = $this->qrcode($ctx, 'WECHAT');
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

        try {
            $pay_info = $this->jsapi($ctx, 'WECHAT', $wxinfo['appid'], $openid, $ctx->order['is_applet'] == 1);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => json_encode($pay_info)];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => json_encode($pay_info), 'redirect_url' => $redirect_url]];
    }

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $code = request()->get('code', '', 'trim');
        if ($code === '') {
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
            $pay_info = $this->jsapi($ctx, 'WECHAT', $wxinfo['appid'], $openid, true);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $pay_info]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
        try {
            $code_url = wxminipay_jump_scheme($ctx->order, $ctx->order['trade_no']);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
    }

    //微信小程序插件支付
    public function wxplugin(PaymentContext $ctx): array
    {
        try {
            $result = $this->appletPay($ctx, '00');
            $payinfo = ['appId' => 'wx78f434e31e956fb8', 'amt' => $result['amt'], 'key' => $result['key']];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxplugin', 'data' => $payinfo];
    }

    //半屏小程序支付
    public function wxapplet(PaymentContext $ctx): array
    {
        try {
            $result = $this->appletPay($ctx, '01');
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => $result['appId'], 'path' => $result['path']]];
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
        $tradeNo = $ctx->order['trade_no'];

        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => '{"code":"fail","msg":"参数错误"}'];

        $client = $this->createClient();
        $verify_result = $client->verifySign($arr);

        if ($verify_result) {//验证成功

            if ($arr['bizCode'] == '0000') {
                if ($arr['ordNo'] == $tradeNo) {
                    $api_trade_no = $arr['sxfUuid'];
                    $buyer = $arr['buyerId'] ?? $arr['openid'] ?? null;
                    $bill_trade_no = $arr['transactionId'] ?? null;
                    $end_time = $arr['finishTime'] ?? null;
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                }
                return ['type' => 'html', 'data' => '{"code":"success","msg":"成功"}'];
            } else {
                return ['type' => 'html', 'data' => '{"code":"fail","msg":"状态错误"}'];
            }
        } else {
            //验证失败
            return ['type' => 'html', 'data' => '{"code":"fail","msg":"签名错误"}'];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
    }

    public function query(array $order): array
    {
        $params = [
            'mno' => $this->channel['appmchid'],
            'ordNo' => $order['trade_no'],
        ];
        $client = $this->createClient();
        $result = $client->submit('/query/tradeQuery', $params);
        if (isset($result['bizCode']) && $result['bizCode'] == '0000') {
            return [
                'api_trade_no' => $result['sxfUuid'] ?? '',
                'status' => $result['tranSts'] == 'SUCCESS' ? 1 : 0,
                'money' => $result['oriTranAmt'],
                'buyer' => $result['buyerId'] ?? null,
                'bill_trade_no' => $result['transactionId'] ?? null,
                'endtime' => $result['finishTime'] ?? null,
            ];
        } elseif (isset($result['bizCode'])) {
            throw new \Exception('[' . $result['bizCode'] . ']' . $result['bizMsg']);
        } else {
            throw new \Exception('未知错误');
        }
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'mno' => $this->channel['appmchid'],
            'ordNo' => $order['refund_no'],
            'origOrderNo' => $order['trade_no'],
            'amt' => $order['refundmoney'],
        ];

        $client = $this->createClient();
        try {
            $result = $client->submit('/order/refund', $params);
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }

        if (isset($result['bizCode']) && $result['bizCode'] == '0000') {
            return ['code' => 0, 'trade_no' => $result['origOrderNo'], 'refund_fee' => $result['amt']];
        } elseif (isset($result['bizCode'])) {
            return ['code' => -1, 'msg' => '[' . $result['bizCode'] . ']' . $result['bizMsg']];
        } else {
            return ['code' => -1, 'msg' => '未知错误'];
        }
    }

    //进件通知
    public function applynotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => '{"code":"fail","msg":"参数错误"}'];

        $client = $this->createClient();
        $verify_result = $client->verifySign($arr);

        if ($verify_result) {//验证成功

            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->notify($arr);

            return ['type' => 'html', 'data' => '{"code":"success","msg":"成功"}'];
        } else {
            return ['type' => 'html', 'data' => '{"code":"fail","msg":"签名错误"}'];
        }
    }
}
