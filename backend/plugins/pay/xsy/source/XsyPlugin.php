<?php

declare(strict_types=1);

namespace plugins\payment\xsy;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class XsyPlugin extends BasePayment
{
    public function __construct(array $channel)
    {
        parent::__construct($channel);
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
            } elseif ($ctx->order['typename'] == 'bank') {
                return $this->bankjs($ctx);
            }
        } elseif ($ctx->method == 'scan') {
            return $this->scanpay($ctx);
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

    //扫码支付
    private function qrcodePay(PaymentContext $ctx, string $pay_type): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $param = [
            'merchantNo' => $this->channel['appmchid'],
            'orderNo' => $tradeNo,
            'amt' => intval(round($ctx->order['realmoney'] * 100)),
            'payType' => $pay_type,
            'subject' => $ctx->order['name'],
            'trmIp' => request()->clientip,
            'customerIp' => request()->clientip,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/'
        ];

        $client = new PayClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret'], $this->channel['appswitch'] == 1);
        return self::lockPayData($tradeNo, function () use ($client, $param) {
            $result = $client->request('/trade/activeScan', $param);
            if (strpos($result['payUrl'], 'qrContent=')) {
                $result['payUrl'] = getSubstr($result['payUrl'], 'qrContent=', '&sign=');
            }
            return $result['payUrl'];
        });
    }

    //公众号小程序支付
    private function jsapiPay(PaymentContext $ctx, string $pay_type, string $pay_way, string $userid, ?string $appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $param = [
            'merchantNo' => $this->channel['appmchid'],
            'orderNo' => $tradeNo,
            'amt' => intval(round($ctx->order['realmoney'] * 100)),
            'payType' => $pay_type,
            'payWay' => $pay_way,
            'subAppId' => $appid,
            'userId' => $userid,
            'subject' => $ctx->order['name'],
            'trmIp' => request()->clientip,
            'customerIp' => request()->clientip,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/'
        ];

        $client = new PayClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret'], $this->channel['appswitch'] == 1);
        return self::lockPayData($tradeNo, function () use ($client, $param) {
            return $client->request('/trade/jsapiScan', $param);
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
                $code_url = $this->qrcodePay($ctx, 'ALIPAY');
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
            }
        }
        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //支付宝生活号支付
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
            $retData = $this->jsapiPay($ctx, 'ALIPAY', '02', $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $retData['source']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $retData['source'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($this->channel['appwxa'] > 0 && $this->channel['appwxmp'] == 0) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } else {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
        }
        if ($ctx->isMobile) {
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

        //②、统一下单
        try {
            $result = $this->jsapiPay($ctx, 'WECHAT', '02', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        $payinfo = ['appId' => $result['payAppId'], 'timeStamp' => $result['payTimeStamp'], 'nonceStr' => $result['paynonceStr'], 'package' => $result['payPackage'], 'signType' => $result['paySignType'], 'paySign' => $result['paySign']];

        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => json_encode($payinfo)];
        }

        if (request()->get('d') == 1) {
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
            $result = $this->jsapiPay($ctx, 'WECHAT', '03', $openid, $wxinfo['appid']);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }

        $payinfo = ['appId' => $result['payAppId'], 'timeStamp' => $result['payTimeStamp'], 'nonceStr' => $result['paynonceStr'], 'package' => $result['payPackage'], 'signType' => $result['paySignType'], 'paySign' => $result['paySign']];

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

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $url = $this->qrcodePay($ctx, 'UNIONPAY');
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $e->getMessage()];
        }
        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $url];
    }

    //云闪付JS支付
    public function bankjs(PaymentContext $ctx): array
    {
        try {
            $result = $this->jsapiPay($ctx, 'UNIONPAY', '02', $ctx->order['sub_openid']);
            $url = $result['redirectUrl'];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $e->getMessage()];
        }
        return ['type' => 'jump', 'url' => $url];
    }

    //获取云闪付用户标识
    public function get_unionpay_userid(string $userAuthCode): array
    {
        $params = [
            'merchantNo' => $this->channel['appmchid'],
            'userAuthCode' => $userAuthCode,
            'appIdentifier' => get_unionpay_ua(),
        ];

        $client = new PayClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret'], $this->channel['appswitch'] == 1);
        try {
            $result = $client->request('/trade/getUnionInfo', $params);
            return ['code' => 0, 'data' => $result['userId']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //被扫支付
    public function scanpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            $pay_type = 'ALIPAY';
        } elseif ($ctx->order['typename'] == 'wxpay') {
            $pay_type = 'WECHAT';
        } elseif ($ctx->order['typename'] == 'bank') {
            $pay_type = 'UNIONPAY';
        } else {
            return ['type' => 'error', 'msg' => '不支持的支付方式'];
        }

        $params = [
            'merchantNo' => $this->channel['appmchid'],
            'orderNo' => $tradeNo,
            'authCode' => $ctx->order['auth_code'],
            'amt' => intval(round($ctx->order['realmoney'] * 100)),
            'payType' => $pay_type,
            'subject' => $ctx->order['name'],
            'trmIp' => request()->clientip,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        $client = new PayClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret'], $this->channel['appswitch'] == 1);

        try {
            $result = $client->request('/trade/reverseScan', $params);
            if ($client->res_code == '0000') {
                return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['orderNo'], 'api_trade_no' => $result['outOrderNo'], 'buyer' => $result['buyerId'], 'money' => $ctx->order['realmoney']]];
            } else {
                $retry = 0;
                $success = false;
                while ($retry < 6) {
                    sleep(3);
                    try {
                        $result = $this->orderQuery($client, $tradeNo);
                    } catch (Exception $e) {
                        return ['type' => 'error', 'msg' => '订单查询失败:' . $e->getMessage()];
                    }
                    if ($result['tranSts'] == 'SUCCESS') {
                        $success = true;
                        break;
                    } elseif ($result['tranSts'] != 'NEEDPAY' && $result['tranSts'] != 'PAYING') {
                        return ['type' => 'error', 'msg' => '订单超时或用户取消支付'];
                    }
                    $retry++;
                }
                if ($success) {
                    return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['orderNo'], 'api_trade_no' => $result['outOrderNo'], 'buyer' => $result['buyerId'], 'money' => strval(round($result['amt'] / 100, 2))]];
                } else {
                    try {
                        $this->orderRevoked($client, $tradeNo, $pay_type);
                    } catch (Exception $e) {
                    }
                    return ['type' => 'error', 'msg' => '被扫下单失败！订单已超时'];
                }
            }
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '被扫下单失败！' . $e->getMessage()];
        }
    }

    private function orderQuery(PayClient $client, string $out_trade_no): array
    {
        $params = [
            'merchantNo' => $this->channel['appmchid'],
            'orderNo' => $out_trade_no,
        ];
        return $client->request('/trade/tradeQuery', $params);
    }

    private function orderRevoked(PayClient $client, string $out_trade_no, string $pay_type): array
    {
        $params = [
            'merchantNo' => $this->channel['appmchid'],
            'orderNo' => $out_trade_no,
            'payType' => $pay_type,
        ];
        return $client->request('/trade/cancel', $params);
    }

    //回调
    public function notify(PaymentContext $ctx): array
    {
        $data = request()->getContent();
        $arr = json_decode($data, true);
        if (!$arr) return ['type' => 'html', 'data' => '{"code":"nodata"}'];

        $client = new PayClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret'], $this->channel['appswitch'] == 1);

        try {
            if (!$client->verifySign($arr, $data)) {
                return ['type' => 'html', 'data' => '{"code":"fail"}'];
            }
            $out_trade_no = $arr['respData']['orderNo'];
            $api_trade_no = $arr['respData']['outOrderNo'];
            $buyer = $arr['respData']['buyerId'] ?? '';
            $bill_trade_no = $arr['respData']['transactionId'] ?? '';
            $bill_mch_trade_no = $arr['respData']['thirdPartyUuid'] ?? '';
            $end_time = $arr['respData']['payTime'];
            if ($out_trade_no == $ctx->order['trade_no']) {
                $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
            }
            return ['type' => 'html', 'data' => '{"code":"success"}'];
        } catch (Exception $e) {
            return ['type' => 'html', 'data' => '{"code":"' . $e->getMessage() . '"}'];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
    }

    public function query(array $order): array
    {
        $client = new PayClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret'], $this->channel['appswitch'] == 1);
        $result = $this->orderQuery($client, $order['trade_no']);
        return [
            'api_trade_no' => $result['outOrderNo'],
            'status' => $result['tranSts'] == 'SUCCESS' ? 1 : 0,
            'money' => $result['amt'] / 100,
            'buyer' => $result['buyerId'] ?? '',
            'bill_trade_no' => $result['transactionId'] ?? '',
            'bill_mch_trade_no' => $result['thirdPartyUuid'] ?? '',
            'endtime' => $result['payTime'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $param = [
            'merchantNo' => $this->channel['appmchid'],
            'orderNo' => $order['refund_no'],
            'origOrderNo' => $order['trade_no'],
            'amt' => intval(round($order['refundmoney'] * 100))
        ];

        try {
            $client = new PayClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret'], $this->channel['appswitch'] == 1);
            $result = $client->request('/trade/refund', $param);
            return ['code' => 0, 'trade_no' => $result['orderNo'], 'refund_fee' => $result['amt'] / 100];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }
}
