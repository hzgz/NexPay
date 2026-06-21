<?php

declare(strict_types=1);

namespace plugins\payment\xingyifu;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class XingyifuPlugin extends BasePayment
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
            if ($ctx->mdevice === 'wechat' && in_array('2', $apptype) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && in_array('2', $apptype) && $this->channel['appwxa'] > 0) {
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
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $apptype)) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $apptype) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && in_array('2', $apptype) && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    private function getClient(): PayClient
    {
        return new PayClient($this->channel['appkey'], $this->channel['appswitch'] == '1');
    }

    //JSAPI支付
    private function jspay(PaymentContext $ctx, string $payWay, string $sub_openid, ?string $sub_appid = null, bool $is_mini = false): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $param = [
            'agetId' => $this->channel['aget_id'],
            'custId' => $this->channel['cust_id'],
            'orderNo' => $tradeNo,
            'txamt' => strval($ctx->order['realmoney'] * 100),
            'payWay' => $payWay,
            'title' => $ctx->ordername,
            'asyncNotify' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'ip' => request()->clientip,
            'openid' => $sub_openid,
        ];
        if ($sub_appid) $param['wxAppid'] = $sub_appid;
        if ($payWay == '1' && $is_mini) $param['traType'] = '8';

        return self::lockPayData($tradeNo, function () use ($client, $param, $tradeNo) {
            $result = $client->execute('/yyfsevr/order/pay', $param);
            $this->updateOrder($tradeNo, $result['orderNo']);
            return $result;
        });
    }

    //付款码支付
    private function scanpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $param = [
            'agetId' => $this->channel['aget_id'],
            'custId' => $this->channel['cust_id'],
            'orderNo' => $tradeNo,
            'txamt' => strval($ctx->order['realmoney'] * 100),
            'code' => $ctx->order['auth_code'],
            'title' => $ctx->ordername,
            'tradingIp' => request()->clientip,
            'asyncNotify' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        $result = $client->execute('/yyfsevr/order/scanByMerchant', $param);
        return $result;
    }

    //扫码支付-官方码
    private function qrcodepay(PaymentContext $ctx, string $payChannel): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $param = [
            'agetId' => $this->channel['aget_id'],
            'custId' => $this->channel['cust_id'],
            'orderNo' => $tradeNo,
            'txamt' => strval($ctx->order['realmoney'] * 100),
            'payChannel' => $payChannel,
            'title' => $ctx->ordername,
            'ip' => request()->clientip,
            'asyncNotify' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        return self::lockPayData($tradeNo, function () use ($client, $param, $tradeNo) {
            $result = $client->execute('/yyfsevr/order/officialPay', $param);
            $this->updateOrder($tradeNo, $result['orderNo']);
            return $result;
        });
    }

    //扫码支付-星驿码
    private function aggregatepay(PaymentContext $ctx): mixed
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $param = [
            'agetId' => $this->channel['aget_id'],
            'custId' => $this->channel['cust_id'],
            'orderNo' => $tradeNo,
            'txamt' => strval($ctx->order['realmoney'] * 100),
            'title' => $ctx->ordername,
            'ip' => request()->clientip,
            'payType' => '01',
            'asyncNotify' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        return self::lockPayData($tradeNo, function () use ($client, $param, $tradeNo) {
            $result = $client->execute('/yyfsevr/order/getCodeUrl', $param);
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
                $result = $this->qrcodepay($ctx, '1');
                $code_url = $result['url'];
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
            $result = $this->jspay($ctx, '2', $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method === 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['prepayid']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['prepayid'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            if ($this->channel['appwxa'] > 0 && $this->channel['appwxmp'] == 0) {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            }
        } else {
            try {
                $code_url = $this->aggregatepay($ctx);
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
            $result = $this->jspay($ctx, '1', $openid, $wxinfo['appid']);
            $payinfo = ['appId' => $result['jsapiAppid'], 'timeStamp' => $result['jsapiTimestamp'], 'nonceStr' => $result['jsapiNoncestr'], 'package' => $result['jsapiPackage'], 'signType' => $result['jsapiSignType'], 'paySign' => $result['jsapiPaySign']];
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
            $result = $this->jspay($ctx, '1', $openid, $wxinfo['appid'], true);
            $payinfo = ['appId' => $result['jsapiAppid'], 'timeStamp' => $result['jsapiTimestamp'], 'nonceStr' => $result['jsapiNoncestr'], 'package' => $result['jsapiPackage'], 'signType' => $result['jsapiSignType'], 'paySign' => $result['jsapiPaySign']];
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

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $result = $this->qrcodepay($ctx, '9');
            $code_url = $result['url'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //获取云闪付用户标识
    public function get_unionpay_userid(string $userAuthCode): array
    {
        $client = $this->getClient();

        $params = [
            'agetId' => $this->channel['aget_id'],
            'custId' => $this->channel['cust_id'],
            'ip' => request()->clientip,
            'authCode' => $userAuthCode,
            'payCode' => get_unionpay_ua(),
        ];

        try {
            $result = $client->execute('/yyfsevr/order/getPaypalTag', $params);
            return ['code' => 0, 'data' => $result['userId']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'json', 'data' => ['rspCod' => '000004', 'rspMsg' => 'No data']];

        $client = $this->getClient();
        $verify_result = $client->verifySign($arr);

        if ($verify_result) {
            if ($arr['ORDER_STATUS'] == '1') {
                $out_trade_no = $arr['THREE_ORDER_NO'];
                $trade_no = $arr['ORDER_NO'];
                $bill_trade_no = $arr['T_PAY_NO'];
                $bill_mch_trade_no = $arr['T_ORDER_NO'] ?? null;
                $buyer = $arr['OPEN_ID'] ?? null;
                $end_time = $arr['ORDER_TIME'];
                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
            }
            return ['type' => 'json', 'data' => ['rspCod' => '000000', 'rspMsg' => 'success']];
        } else {
            return ['type' => 'json', 'data' => ['rspCod' => '000002', 'rspMsg' => 'sign fail']];
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
            'agetId' => $this->channel['aget_id'],
            'custId' => $this->channel['cust_id'],
            'orderNo' => $order['trade_no'],
            'orderTime' => date('Ymd', strtotime($order['addtime'])),
        ];
        $result = $client->execute('/yyfsevr/order/orderQuery', $param);
        return [
            'api_trade_no' => $result['orderNo'] ?? '',
            'status' => $result['orderStatus'] == '1' ? 1 : 0,
            'money' => ($result['txamt'] ?? 0) / 100,
            'buyer' => $result['openId'] ?? null,
            'bill_trade_no' => $result['payNo'] ?? null,
            'bill_mch_trade_no' => $result['torderNo'] ?? null,
            'endtime' => $result['orderTime'] ?? null,
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->getClient();

        $param = [
            'agetId' => $this->channel['aget_id'],
            'custId' => $this->channel['cust_id'],
            'orderNo' => $order['refund_no'],
            'reOrderNo' => $order['api_trade_no'],
            'refundAmount' => strval($order['refundmoney'] * 100),
            'tag' => $order['type'],
        ];

        try {
            $result = $client->execute('/yyfsevr/order/refund', $param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        return ['code' => 0, 'trade_no' => $result['orderFlowNo'], 'refund_fee' => $result['realRefundAmt'] / 100];
    }
}
