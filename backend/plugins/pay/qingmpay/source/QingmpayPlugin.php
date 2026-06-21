<?php

declare(strict_types=1);

namespace plugins\payment\qingmpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

//http://open.qingmpay.com/index.html
class QingmpayPlugin extends BasePayment
{
    private const API_URL = 'https://pay.qingmpay.com';

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->order['typename'] === 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] === 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0 && in_array('2', $apptype)) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && ($this->channel['appwxa'] > 0 && in_array('2', $apptype) || in_array('3', $apptype))) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] === 'bank') {
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
        }
        return ['type' => 'error', 'msg' => '暂不支持的付款方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->order['typename'] === 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] === 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0 && in_array('2', $apptype)) {
                return $this->wxjspay($ctx);
            } elseif ($ctx->isMobile && ($this->channel['appwxa'] > 0 && in_array('2', $apptype) || in_array('3', $apptype))) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] === 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '暂不支持的付款方式'];
    }

    private function makeSign(array $param, string $key): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != 'sign' && $v !== null && $v !== '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'key=' . $key;
        $sign = md5($signstr);
        return $sign;
    }

    private function addOrder(PaymentContext $ctx, string $service, ?string $openid = null, ?string $appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $requrl = self::API_URL . '/sdkServer/thirdpays/pay/' . $service;
        $params = [
            'appid' => $this->channel['appid'],
            'mchId' => $this->channel['appmchid'],
            'version' => '3.0',
            'charset' => 'UTF-8',
            'signType' => 'MD5',
            'money' => strval(round($ctx->order['realmoney'] * 100)),
            'outTradeNo' => $tradeNo,
            'productName' => $ctx->ordername,
            'productDesc' => $ctx->order['name'],
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'callbackUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'ip' => request()->clientip,
            'nonceStr' => getSid(),
            'api' => '1',
        ];
        if ($openid) $params['openid'] = $openid;
        if ($appid) $params['subAppid'] = $appid;
        $params['sign'] = md5($params['appid'] . $service . $params['money'] . $params['outTradeNo'] . $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($requrl, $params, $tradeNo) {
            $response = get_curl($requrl, http_build_query($params));
            $result = json_decode($response, true);
            if (isset($result['errcode']) && $result['errcode'] == 0) {
                $this->updateOrder($tradeNo, $result['result']['pdorderid']);
                return $result['result'];
            } else {
                throw new Exception($result['err'] ? $result['err'] : '返回数据解析失败');
            }
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->isMobile && in_array('2', $apptype)) {
            try {
                $result = $this->addOrder($ctx, 'ALIPAY_WAP');
                $code_url = $result['pay_info'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'jump', 'url' => $code_url];
        } elseif (in_array('2', $apptype) && !in_array('1', $apptype)) {
            $code_url = $siteurl . 'pay/alipay/' . $tradeNo . '/';
        } else {
            try {
                $result = $this->addOrder($ctx, 'ALIPAY_QRCODE');
                $code_url = $result['code_url'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
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
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if (in_array('1', $apptype)) {
            try {
                $result = $this->addOrder($ctx, 'WECHAT_QRCODE');
                $code_url = $result['code_url'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('3', $apptype)) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } elseif (in_array('2', $apptype)) {
            if ($this->channel['appwxmp'] > 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
        } else {
            return ['type' => 'error', 'msg' => '当前支付通道没有开启的支付方式'];
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

        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];

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
            try {
                $tools = new \WeChatPay\JsApiTool($wxinfo['appid'], $wxinfo['appsecret']);
                $openid = $tools->GetOpenid();
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        try {
            $result = $this->addOrder($ctx, 'WECHAT_SUB', $openid, $wxinfo['appid']);
            $pay_info = $result['pay_info'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
        }
        if (substr($pay_info, 0, 4) === 'http') {
            return ['type' => 'jump', 'url' => $pay_info];
        }
        if ($ctx->method === 'jsapi') {
            return ['type' => 'jsapi', 'data' => $pay_info];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $pay_info, 'redirect_url' => $redirect_url]];
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
            $result = $this->addOrder($ctx, 'WECHAT_MNPROGRAM', $openid, $wxinfo['appid']);
            $pay_info = $result['pay_info'];
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($pay_info, true)]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $apptype = $this->channel['apptype'] ?? [];

        if (in_array('3', $apptype)) {
            try {
                $result = $this->addOrder($ctx, 'WECHAT_WAP');
                $code_url = $result['pay_info'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            if ($ctx->mdevice === 'wechat' && strpos($code_url, '/sdkServer/prepay') !== false) {
                $url = $this->getRedirectUrl($code_url);
                if (strpos($url, '/sdkServer/thirdpay/jump_mini.html?link=') !== false) {
                    $parts = parse_url($url);
                    parse_str($parts['query'], $query);
                    $redirect_uri = urldecode($query['link']);
                    return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $redirect_uri];
                }
            }
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($ctx->order, $wxinfo['id']);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        }
    }

    private function getRedirectUrl(string $url, int $timeout = 10): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $httpheader[] = "Accept: */*";
        $httpheader[] = "Accept-Language: zh-CN,zh;q=0.8";
        $httpheader[] = "Connection: close";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($ch);
        $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return $redirect_url ?: '';
    }

    //QQ扫码支付
    public function qqpay(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, 'TENCENT_QRCODE');
            $code_url = $result['code_url'];
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
            $result = $this->addOrder($ctx, 'UNION_DEBIT');
            $code_url = $result['pay_info'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $postData = request()->post();
        if (empty($postData)) return ['type' => 'html', 'data' => 'No data'];

        $sign = $this->makeSign($postData, $this->channel['appkey']);

        if ($sign === $postData['sign']) {
            if ($postData['status'] === 'success') {
                $out_trade_no = $postData['outTradeNo'];
                $api_trade_no = $postData['pdorderid'];
                $bill_trade_no = $postData['chorderid'] ?? null;
                $bill_mch_trade_no = $postData['bankBillno'] ?? null;
                $money = $postData['money'];
                $buyer = $postData['openid'] ?? null;
                $end_time = $postData['timeEnd'] ?? null;

                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $requrl = self::API_URL . '/sdkServer/thirdpayorder';
        $params = [
            'appid' => $this->channel['appid'],
            'mchId' => $this->channel['appmchid'],
            'version' => '3.0',
            'charset' => 'UTF-8',
            'signType' => 'MD5',
            'outTradeNo' => $order['trade_no'],
        ];
        $params['sign'] = md5($params['appid'] . $params['outTradeNo'] . $this->channel['appkey']);
        $response = get_curl($requrl, http_build_query($params));
        $result = json_decode($response, true);
        if (isset($result['resultCode']) && $result['resultCode'] == 0) {
            return [
                'api_trade_no' => $result['result']['pdorderid'],
                'status' => $result['result']['status'] == 'success' ? 1 : 0,
                'money' => $result['result']['money'] / 100,
                'buyer' => $result['result']['openid'] ?? '',
                'bill_trade_no' => $result['result']['chorderid'] ?? '',
                'bill_mch_trade_no' => $result['result']['bankBillno'] ?? '',
                'endtime' => $result['result']['timeEnd'] ?? '',
            ];
        } else {
            throw new \Exception($result['message'] ?? '返回数据解析失败');
        }
    }

    //退款
    public function refund(array $order): array
    {
        $requrl = self::API_URL . '/sdkServer/thirdpays/refund';
        $params = [
            'appid' => $this->channel['appid'],
            'mchId' => $this->channel['appmchid'],
            'version' => '3.0',
            'charset' => 'UTF-8',
            'signType' => 'MD5',
            'pdorderid' => $order['api_trade_no'],
            'money' => strval(round($order['realmoney'] * 100)),
            'outTradeNo' => $order['trade_no'],
            'outRefundNo' => $order['refund_no'],
            'refundMoney' => strval(round($order['refundmoney'] * 100)),
            'refundReason' => '退款',
            'nonceStr' => getSid(),
        ];
        $params['sign'] = md5($params['mchId'] . $params['appid'] . $params['pdorderid'] . $params['money'] . $params['version'] . $params['outRefundNo'] . $params['refundMoney'] . $this->channel['appkey']);

        $response = get_curl($requrl, http_build_query($params));
        $result = json_decode($response, true);
        if (isset($result['resultCode']) && $result['resultCode'] == 0) {
            return ['code' => 0, 'trade_no' => $result['result']['pdRefundNo'], 'refund_fee' => $result['result']['refundMoney'] / 100];
        } elseif (isset($result['message'])) {
            return ['code' => -1, 'msg' => $result['message']];
        } else {
            return ['code' => -1, 'msg' => '未知错误'];
        }
    }

    //投诉回调
    public function complainnotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data || !isset($data['resource'])) {
            return ['type' => 'html', 'data' => 'no data'];
        }
        $res = $data['resource'];
        $decrypted = \plugins\complain\qingmpay\QingmpayWxpay::decryptResource(
            $res['ciphertext'],
            $res['nonce'],
            $res['associated_data'] ?? '',
            $this->channel['appkey']
        );
        if ($decrypted) {
            $decryptedData = json_decode($decrypted, true);
            $model = \app\logic\ComplainLogic::getModel($this->channel);
            if ($model) {
                $model->refreshNewInfo($decryptedData['complaint_id'], $decryptedData['action_type'] ?? null);
            }
            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            return ['type' => 'html', 'data' => 'DECRYPT FAIL'];
        }
    }

}