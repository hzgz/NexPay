<?php

declare(strict_types=1);

namespace plugins\payment\soezpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

//http://www.soezpay.com/#/docsApi
class SoezpayPlugin extends BasePayment
{
    private const API_URL = 'https://api.soezpay.com';

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->order['typename'] === 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] === 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0 && in_array('2', $apptype)) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $this->channel['appwxa'] > 0 && in_array('2', $apptype)) {
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
            } elseif ($ctx->isMobile && $this->channel['appwxa'] > 0 && in_array('2', $apptype)) {
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
        $sign = strtoupper(md5($signstr));
        return $sign;
    }

    private function addOrder(PaymentContext $ctx, string $account_type, string $trans_type, ?string $openid = null, ?string $appid = null): mixed
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $requrl = self::API_URL . '/api/pay/pay';
        $params = [
            'merch_appid' => $this->channel['appid'],
            'account_type' => $account_type,
            'trans_type' => $trans_type,
            'out_trade_no' => $tradeNo,
            'order_title' => $ctx->ordername,
            'total_fee' => strval(round($ctx->order['realmoney'] * 100)),
            'currency_type' => 'CNY',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'callback_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'request_ip' => request()->clientip,
            'nonce_str' => getSid(),
            'cashier' => '3',
        ];
        if (isset($ctx->order['auth_code'])) $params['auth_code'] = $ctx->order['auth_code'];
        if ($appid) $params['appid'] = $appid;
        if ($openid) $params['openid'] = $openid;
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($requrl, $params, $tradeNo) {
            $response = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
            if (!$response) throw new Exception('接口请求失败');
            $result = json_decode($response, true);
            if (isset($result['code']) && $result['code'] == 200) {
                $this->updateOrder($tradeNo, $result['data']['trade_no']);
                return $result['data']['pay_info'];
            } else {
                throw new Exception($result['msg'] ?? '返回数据解析失败');
            }
        });
    }

    private function cashier(PaymentContext $ctx, string $account_type): mixed
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $requrl = self::API_URL . '/api/pay/payCashierDesk';
        $params = [
            'merch_appid' => $this->channel['appid'],
            'account_type' => $account_type,
            'out_trade_no' => $tradeNo,
            'order_title' => $ctx->ordername,
            'total_fee' => strval(round($ctx->order['realmoney'] * 100)),
            'currency_type' => 'CNY',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'callback_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'request_ip' => request()->clientip,
            'nonce_str' => getSid(),
            'cashier' => '3',
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($requrl, $params, $tradeNo) {
            $response = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
            if (!$response) throw new Exception('接口请求失败');
            $result = json_decode($response, true);
            if (isset($result['code']) && $result['code'] == 200) {
                return $result['data']['cashier_url'];
            } else {
                throw new Exception($result['msg'] ?? '返回数据解析失败');
            }
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, 'ALIPAY', 'alipay_qr');
            $code_url = $result['code'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
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

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            if ($this->channel['appwxmp'] > 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } elseif ($this->channel['appwxa'] > 0) {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
        } else {
            try {
                $code_url = $this->cashier($ctx, 'WECHAT');
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
            $result = $this->addOrder($ctx, 'WECHAT', $ctx->order['is_applet'] == 1 ? 'wx_lite' : 'wx_jsapi', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
        }
        $pay_info = json_encode(['appId' => $result['app_id'], 'timeStamp' => $result['time_stamp'], 'nonceStr' => $result['nonce_str'], 'package' => $result['package'], 'signType' => $result['sign_type'], 'paySign' => $result['pay_sign']]);
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
            $result = $this->addOrder($ctx, 'WECHAT', 'wx_lite', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }
        $pay_info = ['appId' => $result['app_id'], 'timeStamp' => $result['time_stamp'], 'nonceStr' => $result['nonce_str'], 'package' => $result['package'], 'signType' => $result['sign_type'], 'paySign' => $result['pay_sign']];

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $pay_info]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
        try {
            $code_url = wxminipay_jump_scheme($ctx->order, $wxinfo['id']);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, 'unionPay', 'union_pay_native');
            $code_url = $result['code'];
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

        $sign = $this->makeSign($postData, $this->channel['appkey']);

        if ($sign === $postData['sign']) {
            if ($postData['order_status'] === 'PAY_SUCCESS') {
                $out_trade_no = $postData['out_trade_no'];
                $api_trade_no = $postData['trade_no'];
                $money = $postData['total_fee'];
                $bill_trade_no = $postData['office_order_num'] ?? null;
                $end_time = $postData['pay_time'] ?? null;

                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $api_trade_no, null, $bill_trade_no, null, $end_time);
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
        $requrl = self::API_URL . '/api/pay/orderQuery';
        $params = [
            'merch_appid' => $this->channel['appid'],
            'out_trade_no' => $order['trade_no'],
            'nonce_str' => getSid(),
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);
        $response = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 200) {
            return [
                'api_trade_no' => $result['data']['trade_no'],
                'status' => $result['data']['order_status'] == 'PAY_SUCCESS' ? 1 : 0,
                'money' => $result['data']['total_fee'] / 100,
                'endtime' => $result['data']['pay_time'] ?? '',
            ];
        } else {
            throw new \Exception($result['msg'] ?? '返回数据解析失败');
        }
    }

    //退款
    public function refund(array $order): array
    {
        $requrl = self::API_URL . '/api/pay/orderRefund';
        $params = [
            'merch_appid' => $this->channel['appid'],
            'out_trade_no' => $order['trade_no'],
            'out_refund_no' => $order['refund_no'],
            'refund_fee' => strval(round($order['refundmoney'] * 100)),
            'request_ip' => request()->clientip,
            'nonce_str' => getSid(),
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        $response = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$response) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 200) {
            return ['code' => 0, 'trade_no' => $result['data']['refund_no'], 'refund_fee' => $result['data']['refund_fee'] / 100];
        } else {
            return ['code' => -1, 'msg' => $result['msg'] ?? '返回数据解析失败'];
        }
    }

}