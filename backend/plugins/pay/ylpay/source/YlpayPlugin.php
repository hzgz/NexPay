<?php

declare(strict_types=1);

namespace plugins\payment\ylpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

//https://console-docs.apipost.cn/preview/aa7af53f36da81cb/4ccaebd9eb0a5304
class YlpayPlugin extends BasePayment
{
    private static $API_URL = 'https://papi.020leader.com/unipay/';

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
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
        if ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return $this->wxjspay($ctx);
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
        return md5($signstr);
    }

    private function qrcode(PaymentContext $ctx, string $pay_type): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $requrl = self::$API_URL;
        $params = [
            'service' => 'unipay.acquire.precreate',
            'version' => '3.0',
            'merchantNum' => $this->channel['appid'],
            'cashierNum' => '1001',
            'terminalUniqueNo' => '10001',
            'orderNumExternal' => $tradeNo,
            'inputFee' => $ctx->order['realmoney'],
            'body' => $ctx->ordername,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'clientType' => 'WEB',
            'payClient' => $pay_type,
            'transSubject' => $ctx->ordername,
            'clientIp' => request()->clientip,
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($requrl, $params, $tradeNo) {
            $response = get_curl($requrl, http_build_query($params));
            if (!$response) throw new Exception('接口请求失败');
            $result = json_decode($response, true);
            if (isset($result['sys_code']) && $result['sys_code'] == 'SUCCESS' && $result['result_code'] == 'DOING') {
                $this->updateOrder($tradeNo, $result['orderNum']);
                return $result['qr_code'];
            } else {
                throw new Exception($result['result_msg'] ?? '返回数据解析失败');
            }
        });
    }

    private function jspay(PaymentContext $ctx, string $sub_openid, string $is_mini = '0'): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $requrl = self::$API_URL;
        $params = [
            'service' => 'pay.weixin.jspay',
            'version' => '3.0',
            'merchantNum' => $this->channel['appid'],
            'terminalUniqueNo' => '10001',
            'orderNumExternal' => $tradeNo,
            'inputFee' => $ctx->order['realmoney'],
            'body' => $ctx->ordername,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'isMing' => $is_mini,
            'subOpenid' => $sub_openid,
            'transSubject' => $ctx->ordername,
            'clientIp' => request()->clientip,
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($requrl, $params, $tradeNo) {
            $response = get_curl($requrl, http_build_query($params));
            if (!$response) throw new Exception('接口请求失败');
            $result = json_decode($response, true);
            if (isset($result['sys_code']) && $result['sys_code'] == 'SUCCESS' && $result['result_code'] == 'DOING') {
                $this->updateOrder($tradeNo, $result['orderNum']);
                return $result['pay_info'];
            } else {
                throw new Exception($result['result_msg'] ?? '返回数据解析失败');
            }
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, '4');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
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

        $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';

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
            $pay_info = $this->jspay($ctx, $openid);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
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
        $code = request()->get('code');
        if (empty($code)) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }

        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];
        }

        try {
            $tools = new \WeChatPay\JsApiTool($wxinfo['appid'], $wxinfo['appsecret']);
            $openid = $tools->AppGetOpenid($code);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        try {
            $pay_info = $this->jspay($ctx, $openid, '1');
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($pay_info, true)]];
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
            $code_url = $this->qrcode($ctx, '6');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        if (!request()->post('sign')) return ['type' => 'html', 'data' => 'no data'];

        $sign = $this->makeSign(request()->post(), $this->channel['appkey']);

        if ($sign === request()->post('sign')) {
            if (request()->post('status') == '2') {
                $out_trade_no = request()->post('orderNumExternal');
                $api_trade_no = request()->post('orderNum');
                $buyer = request()->post('transBuyerId');
                $bill_trade_no = request()->post('orderNumOfficial');
                $bill_mch_trade_no = request()->post('orderNumChannel');
                $end_time = request()->post('transTime');

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
        $requrl = self::$API_URL;
        $params = [
            'service' => 'unipay.acquire.query',
            'version' => '3.0',
            'merchantNum' => $this->channel['appid'],
            'orderNumExternal' => $order['trade_no'],
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);
        $response = get_curl($requrl, http_build_query($params));
        if (!$response) throw new \Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['result_code']) && ($result['result_code'] == 'SUCCESS' || $result['result_code'] == 'DOING')) {
            return [
                'api_trade_no' => $result['orderNum'],
                'status' => $result['status'] == 2 ? 1 : 0,
                'money' => $result['totalFee'] / 100,
                'endtime' => $result['transTime'] ?? '',
                'buyer' => $result['transBuyerId'] ?? '',
                'bill_trade_no' => $result['orderNumOfficial'] ?? '',
                'bill_mch_trade_no' => $result['orderNumChannel'] ?? '',
            ];
        } elseif (isset($result['result_msg'])) {
            throw new \Exception($result['result_msg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    //退款
    public function refund(array $order): array
    {
        $requrl = self::$API_URL;
        $params = [
            'service' => 'unipay.acquire.refund',
            'version' => '3.0',
            'merchantNum' => $this->channel['appid'],
            'cashierNum' => '1001',
            'terminalUniqueNo' => '10001',
            'orderNum' => $order['api_trade_no'],
            'refundExternalNum' => $order['refund_no'],
            'refundFee' => $order['refundmoney'],
            'clientIp' => request()->clientip,
            'clientType' => 'WEB',
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        $response = get_curl($requrl, http_build_query($params));
        if (!$response) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($response, true);
        if (isset($result['result_code']) && ($result['result_code'] == 'SUCCESS' || $result['result_code'] == 'DOING')) {
            return ['code' => 0, 'trade_no' => $result['orderNum'], 'refund_fee' => $result['refundFee'] / 100];
        } elseif (isset($result['result_msg'])) {
            return ['code' => -1, 'msg' => $result['result_msg']];
        } else {
            return ['code' => -1, 'msg' => '未知错误'];
        }
    }
}
