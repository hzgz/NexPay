<?php

declare(strict_types=1);

//https://yapi.newict.com.cn/
namespace plugins\payment\togoodfin;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class TogoodfinPlugin extends BasePayment
{
    private const API_URL = 'https://tgpay.833006.net';

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
            if ($k != "sign" && $v !== null && $v !== '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'key=' . $key;
        $sign = strtoupper(md5($signstr));
        return $sign;
    }

    private function qrcode(PaymentContext $ctx, string $pay_type): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $requrl = self::API_URL . '/tgPosp/services/payApi/unifiedorder';
        $params = [
            'account' => $this->channel['appid'],
            'payMoney' => $ctx->order['realmoney'],
            'lowOrderId' => $tradeNo,
            'body' => $ctx->ordername,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'payType' => $pay_type,
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($requrl, $params, $tradeNo) {
            $response = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
            if (!$response) throw new Exception('接口请求失败');
            $result = json_decode($response, true);
            if (isset($result["status"]) && $result["status"] == 100) {
                $this->updateOrder($tradeNo, $result['orderId']);
                return $result['codeUrl'];
            } else {
                throw new Exception($result["message"] ?? '返回数据解析失败');
            }
        });
    }

    private function jspay(PaymentContext $ctx, string $appid, string $openid, string $is_mini = '0')
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $requrl = self::API_URL . '/tgPosp/services/payApi/wxJspay';
        $params = [
            'account' => $this->channel['appid'],
            'payMoney' => $ctx->order['realmoney'],
            'lowOrderId' => $tradeNo,
            'body' => $ctx->ordername,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returnUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'appId' => $appid,
            'openId' => $openid,
            'isMinipg' => $is_mini,
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($requrl, $params, $tradeNo) {
            $response = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
            if (!$response) throw new Exception('接口请求失败');
            $result = json_decode($response, true);
            if (isset($result["status"]) && $result["status"] == 100) {
                $this->updateOrder($tradeNo, $result['orderId']);
                return $result['pay_info'];
            } else {
                throw new Exception($result["message"] ? $result["message"] : '返回数据解析失败');
            }
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, '1');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, '0');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
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

        try {
            $openid = wechat_oauth($wxinfo);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        try {
            $pay_info = $this->jspay($ctx, $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
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
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        try {
            $pay_info = $this->jspay($ctx, $wxinfo['appid'], $openid, '1');
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
            $code_url = $this->qrcode($ctx, '4');
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

        $sign = $this->makeSign($arr, $this->channel['appkey']);

        if ($sign === $arr["sign"]) {
            if ($arr['state'] == '0') {
                $out_trade_no = $arr['lowOrderId'];
                $api_trade_no = $arr['upOrderId'];
                $money = $arr['account'];
                $buyer = $arr['openid'];
                $end_time = $arr['payTime'];

                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, null, null, $end_time);
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
        $requrl = self::API_URL . '/tgPosp/services/payApi/reverse/v2';
        $params = [
            'account' => $this->channel['appid'],
            'upOrderId' => $order['api_trade_no'],
            'refundMoney' => $order['refundmoney'],
            'lowRefundNo' => $order['refund_no'],
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        $response = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result["status"]) && $result["status"] == 100) {
            return ['code' => 0];
        } elseif (isset($result['message'])) {
            return ['code' => -1, 'msg' => $result['message']];
        } else {
            return ['code' => -1, 'msg' => '未知错误'];
        }
    }
}
