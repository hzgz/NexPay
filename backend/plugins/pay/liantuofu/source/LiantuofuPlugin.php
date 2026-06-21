<?php

declare(strict_types=1);

namespace plugins\payment\liantuofu;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

/**
 * @see https://www.showdoc.com.cn/liantuofu
 * @see https://liantuo.apifox.cn/
 */
class LiantuofuPlugin extends BasePayment
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
            } elseif ($ctx->isMobile && (in_array('2', $apptype) && $this->channel['appwxa'] > 0 || in_array('3', $apptype))) {
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
            } elseif ($ctx->isMobile && (in_array('2', $apptype) && $this->channel['appwxa'] > 0 || in_array('3', $apptype))) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    private function make_sign(array $param, string $key): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $v !== null && $v !== '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'key=' . $key;
        $sign = md5($signstr);
        return $sign;
    }

    private function qrcode(PaymentContext $ctx): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $requrl = 'https://api.liantuofu.com/open/jspay';
        $params = [
            'appId' => $this->channel['appid'],
            'random' => (string) rand(100000, 999999),
            'merchantCode' => $this->channel['appmchid'],
            'outTradeNo' => $tradeNo,
            'totalAmount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'orderSource' => '16',
            'clientIp' => request()->clientip,
        ];
        $params['sign'] = $this->make_sign($params, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($requrl, $params) {
            $response = get_curl($requrl, http_build_query($params));
            if (!$response) throw new Exception('接口请求失败');
            $result = json_decode($response, true);
            if (isset($result["code"]) && $result["code"] == 'SUCCESS') {
                return $result['url'];
            } elseif (isset($result["subMsg"])) {
                throw new Exception('[' . $result['subCode'] . ']' . $result['subMsg']);
            } else {
                throw new Exception($result["msg"] ?? '返回数据解析失败');
            }
        });
    }

    private function precreate(PaymentContext $ctx, string $pay_type, string $trade_type, ?string $openid = null, ?string $appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $requrl = 'https://api.liantuofu.com/open/precreate';
        $params = [
            'appId' => $this->channel['appid'],
            'random' => (string) rand(100000, 999999),
            'merchantCode' => $this->channel['appmchid'],
            'outTradeNo' => $tradeNo,
            'totalAmount' => $ctx->order['realmoney'],
            'channel' => $pay_type,
            'tradeType' => $trade_type,
            'openId' => $openid,
            'subAppId' => $appid,
            //'goodsDetail' => json_encode([['goodsId'=>'1','goodsName'=>$ctx->ordername,'quantity'=>'1','price'=>$ctx->order['realmoney']]]),
            'subject' => $ctx->ordername,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'orderSource' => '4',
            'clientIp' => request()->clientip,
        ];
        $params['sign'] = $this->make_sign($params, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($requrl, $params) {
            $response = get_curl($requrl, http_build_query($params));
            if (!$response) throw new Exception('接口请求失败');
            $result = json_decode($response, true);
            if (isset($result["code"]) && $result["code"] == 'SUCCESS') {
                return $result;
            } elseif (isset($result["subMsg"])) {
                throw new Exception('[' . $result['subCode'] . ']' . $result['subMsg']);
            } else {
                throw new Exception($result["msg"] ?? '返回数据解析失败');
            }
        });
    }

    private function execute(string $path, array $params): array
    {
        $body = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $time = time();
        $sign = strtoupper(md5($this->channel['appid'] . $time . $this->channel['appkey'] . $body));
        $headers = [
            'client-id: ' . $this->channel['appid'],
            'client-time: ' . $time,
            'client-sign: ' . $sign,
            'Content-Type: application/json; charset=utf-8',
        ];
        $requrl = 'https://dev.liantuofu.com' . $path;
        $response = get_curl($requrl, $body, 0, 0, 0, 0, 0, $headers);
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 200) {
            return $result['data'];
        } else {
            throw new Exception($result['msg'] ?? '返回数据解析失败');
        }
    }

    private function newpayapi(PaymentContext $ctx, string $pay_model, string $pay_type): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'merchant_code' => $this->channel['appmchid'],
            'pay_model' => $pay_model,
            'order_no' => $tradeNo,
            'order_amt' => $ctx->order['realmoney'],
            'pay_type' => $pay_type,
            'goods' => $ctx->ordername,
        ];

        return self::lockPayData($tradeNo, function () use ($params) {
            $result = $this->execute('/open/pay', $params);
            return $result;
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if (in_array('2', $apptype) && !in_array('1', $apptype)) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $result = $this->precreate($ctx, 'ALIPAY', 'NATIVE');
                $code_url = $result['qrCode'];
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
            $result = $this->precreate($ctx, 'ALIPAY', 'JSAPI', $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method === 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['transactionId']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['transactionId'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if (in_array('1', $apptype)) {
            try {
                $result = $this->precreate($ctx, 'WXPAY', 'NATIVE');
                $code_url = $result['qrCode'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('3', $apptype)) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
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
            $result = $this->precreate($ctx, 'WXPAY', $ctx->order['is_applet'] == 1 ? 'MINIAPP' : 'JSAPI', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
        }
        $payinfo = ['appId' => $result['appId'], 'timeStamp' => $result['timeStamp'], 'nonceStr' => $result['nonceStr'], 'package' => $result['payPackage'], 'signType' => $result['signType'], 'paySign' => $result['paySign']];
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
            $result = $this->precreate($ctx, 'WXPAY', 'MINIAPP', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }
        $payinfo = ['appId' => $result['appId'], 'timeStamp' => $result['timeStamp'], 'nonceStr' => $result['nonceStr'], 'package' => $result['payPackage'], 'signType' => $result['signType'], 'paySign' => $result['paySign']];

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $payinfo]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $apptype = $this->channel['apptype'] ?? [];

        if (in_array('3', $apptype)) {
            try {
                $result = $this->newpayapi($ctx, '0', '0');
                $order_no = $result['order_no'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            $query = 'no=' . $order_no . '&sm=' . $this->channel['appid'] . '&m=' . $this->channel['appmchid'];
            $code_url = 'weixin://dl/business/?appid=wx87168b525bf5fc8b&path=other/halfPay/index&query=' . urlencode($query);
            $this->updateOrderCombine($tradeNo);
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } else {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        }
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $result = $this->precreate($ctx, 'UNIONPAY', 'NATIVE');
            $code_url = $result['qrCode'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $postData = request()->post();
        if (empty($postData)) return ['type' => 'html', 'data' => 'No data'];

        $sign = $this->make_sign($postData, $this->channel['appkey']);

        if ($sign === $postData["sign"]) {
            if ($postData['orderStatus'] == 'SUCCESS') {
                $out_trade_no = $postData['outTradeNo'];
                $api_trade_no = $postData['outTransactionId'];
                $money = $postData['totalAmount'];
                $buyer = $postData['buyerId'] ?? '';
                $bill_trade_no = $postData['transactionId'] ?? '';
                $bill_mch_trade_no = $postData['outTransactionId'] ?? '';
                $end_time = $postData['payTime'];

                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //异步回调（新接口）
    public function notifyn(PaymentContext $ctx): array
    {
        $sign = request()->header('client-sign');
        $time = request()->header('client-time');
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data || !$sign || !$time) return ['type' => 'html', 'data' => 'no data'];

        $sign_n = strtoupper(md5($this->channel['appid'] . $time . $this->channel['appkey'] . $json));

        if (strtoupper($sign) === $sign_n) {
            if ($data['type'] == 'pay') {
                if ($data['data']['pay_status'] == 1) {
                    $out_trade_no = $data['data']['order_no'];
                    $money = $data['data']['order_amt'];
                    $end_time = $data['data']['pay_time'];
                    $bill_trade_no = $data['data']['third_trade_no'] ?? null;
                    $bill_mch_trade_no = $data['data']['shop_trade_no'] ?? null;

                    $order = $this->getOrder($out_trade_no);
                    if (!$order) return ['type' => 'html', 'data' => 'no order'];
                    $this->processNotify($order, $out_trade_no, null, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'ok'];
        } else {
            return ['type' => 'html', 'data' => 'sign error'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $params = [
            'merchant_code' => $this->channel['appmchid'],
            'order_no' => $order['trade_no'],
        ];
        $result = $this->execute('/open/pay/query', $params);
        return [
            'api_trade_no' => $result['order_no'],
            'status' => $result['pay_status'] == 1 ? 1 : 0,
            'money' => $result['order_amt'],
            'bill_trade_no' => $result['third_trade_no'] ?? '',
            'bill_mch_trade_no' => $result['shop_trade_no'] ?? '',
            'endtime' => $result['pay_time'] ?? '',
        ];
    }
    //退款
    public function refund(array $order): array
    {
        $requrl = 'https://api.liantuofu.com/open/refundV2';
        $params = [
            'appId' => $this->channel['appid'],
            'random' => rand(100000, 999999),
            'merchantCode' => $this->channel['appmchid'],
            'refundNo' => $order['refund_no'],
            'outTradeNo' => $order['trade_no'],
            'refundAmount' => $order['refundmoney'],
        ];
        $params['sign'] = $this->make_sign($params, $this->channel['appkey']);

        $response = get_curl($requrl, http_build_query($params));
        $result = json_decode($response, true);
        if (isset($result["code"]) && $result["code"] == 'SUCCESS') {
            return ['code' => 0];
        } elseif (isset($result['subMsg'])) {
            return ['code' => -1, 'msg' => '[' . $result['subCode'] . ']' . $result['subMsg']];
        } elseif (isset($result['msg'])) {
            return ['code' => -1, 'msg' => $result['msg']];
        } else {
            return ['code' => -1, 'msg' => '未知错误'];
        }
    }

    //退款（合单）
    public function refund_combine(array $order): array
    {
        $params = [
            'merchant_code' => $this->channel['appmchid'],
            'refund_no' => $order['refund_no'],
            'order_no' => $order['trade_no'],
            'refund_amt' => $order['refundmoney'],
        ];

        try {
            $result = $this->execute('/open/refund', $params);
            return ['code' => 0];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }
}
