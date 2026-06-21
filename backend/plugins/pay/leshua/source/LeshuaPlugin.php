<?php

declare(strict_types=1);

namespace plugins\payment\leshua;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

/**
 * https://www.yuque.com/leshua-jhzf/qrcode_pay
 */
class LeshuaPlugin extends BasePayment
{
    const API_URL = 'https://paygate.leshuazf.com/cgi-bin/lepos_pay_gateway.cgi';

    private function makeSign(array $param, string $key): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $k != "error_code") {
                if (is_array($v)) $v = '';
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'key=' . $key;
        return strtoupper(md5($signstr));
    }

    private function xml2array(string $xml): array|false
    {
        if (!$xml) {
            return false;
        }
        LIBXML_VERSION < 20900 && libxml_disable_entity_loader(true);
        return json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA), JSON_UNESCAPED_UNICODE), true);
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

        if ($ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice == 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => request()->siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice == 'wechat' && $this->channel['appwxmp'] > 0) {
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

    private function addOrder(PaymentContext $ctx, string $jspay_flag, ?string $pay_way = null, ?string $openid = null, ?string $appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'service' => 'get_tdcode',
            'jspay_flag' => $jspay_flag,
            'pay_way' => $pay_way,
            'merchant_id' => $this->channel['appid'],
            'third_order_id' => $tradeNo,
            'amount' => strval($ctx->order['realmoney'] * 100),
            'body' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'client_ip' => request()->clientip,
            'nonce_str' => getSid(),
        ];
        if ($openid) $params['sub_openid'] = $openid;
        if ($appid) $params['appid'] = $appid;
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($params, $tradeNo) {
            $response = get_curl(self::API_URL, http_build_query($params));
            if (!$response) throw new Exception('接口请求失败');
            $result = $this->xml2array($response);
            if (isset($result["resp_code"]) && $result["resp_code"] == '0') {
                if (isset($result['result_code']) && $result['result_code'] == '0') {
                    $this->updateOrder($tradeNo, $result['leshua_order_id']);
                    return $result;
                } else {
                    throw new Exception($result["error_msg"] ?? '下单失败');
                }
            } else {
                throw new Exception($result["resp_msg"] ?? '返回数据解析失败');
            }
        });
    }

    //被扫支付
    private function scanpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'service' => 'upload_authcode',
            'auth_code' => $ctx->order['auth_code'],
            'merchant_id' => $this->channel['appid'],
            'third_order_id' => $tradeNo,
            'amount' => strval($ctx->order['realmoney'] * 100),
            'body' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'client_ip' => request()->clientip,
            'nonce_str' => getSid(),
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        $response = get_curl(self::API_URL, http_build_query($params));
        if (!$response) throw new Exception('接口请求失败');
        $result = $this->xml2array($response);
        if (isset($result["resp_code"]) && $result["resp_code"] == '0') {
            if (isset($result['result_code']) && $result['result_code'] == '0') {
                $this->updateOrder($tradeNo, $result['leshua_order_id']);
                return $result;
            } else {
                throw new Exception($result["error_msg"] ?? '下单失败');
            }
        } else {
            throw new Exception($result["resp_msg"] ?? '返回数据解析失败');
        }
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = request()->siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $result = $this->addOrder($ctx, '0', 'ZFBZF');
                $code_url = $result['td_code'];
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
            $result = $this->addOrder($ctx, '1', 'ZFBZF', $user_id);
            $pay_info = json_decode($result['jspay_info'], true);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $pay_info['tradeNO']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $pay_info['tradeNO'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            if ($this->channel['appwxmp'] > 0 && $this->channel['appwxa'] == 0) {
                $code_url = request()->siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = request()->siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
        } else {
            try {
                $result = $this->addOrder($ctx, '2', 'WXZF');
                $code_url = $result['jspay_url'];
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
            $result = $this->addOrder($ctx, $ctx->order['is_applet'] == 1 ? '3' : '1', 'WXZF', $openid, $wxinfo['appid']);
            $pay_info = $result['jspay_info'];
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
        if (!$wxinfo) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];

        try {
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];

        try {
            $result = $this->addOrder($ctx, '3', 'WXZF', $openid, $wxinfo['appid']);
            $pay_info = $result['jspay_info'];
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
            $result = $this->addOrder($ctx, '0', 'UPSMZF');
            $code_url = $result['td_code'];
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
        $arr = $this->xml2array($json);
        if (!$arr) return ['type' => 'html', 'data' => 'No data'];

        if (!empty($this->channel['appsecret'])) {
            $sign = strtolower($this->makeSign($arr, $this->channel['appsecret']));
            if ($sign !== $arr["sign"]) {
                return ['type' => 'html', 'data' => 'fail'];
            }
        } else {
            $arr = $this->queryOrder($arr['leshua_order_id']);
            if (!$arr) {
                return ['type' => 'html', 'data' => 'fail'];
            }
        }
        if ($arr['status'] == '2') {
            $out_trade_no = $arr['third_order_id'];
            $api_trade_no = $arr['leshua_order_id'];
            $buyer = $arr['sub_openid'] ?? '';
            $bill_trade_no = $arr['out_transaction_id'] ?? '';
            $end_time = $arr['pay_time'];
            if ($ctx->order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) {
                $bill_trade_no = substr($bill_trade_no, 2);
            }

            if ($out_trade_no == $tradeNo) {
                $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
            }
        }
        return ['type' => 'html', 'data' => '000000'];
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $params = [
            'service' => 'query_status',
            'merchant_id' => $this->channel['appid'],
            'third_order_id' => $order['trade_no'],
            'nonce_str' => getSid(),
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);
        $response = get_curl(self::API_URL, http_build_query($params));
        if (!$response) throw new \Exception('接口请求失败');
        $result = $this->xml2array($response);
        if (isset($result["resp_code"]) && $result["resp_code"] == '0') {
            if (isset($result['result_code']) && $result['result_code'] == '0') {
                $bill_trade_no = $result['out_transaction_id'] ?? '';
                if ($order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
                return [
                    'api_trade_no' => $result['leshua_order_id'],
                    'status' => $result['status'] == '2' ? 1 : 0,
                    'money' => $result['amount'] / 100,
                    'buyer' => $result['sub_openid'] ?? '',
                    'bill_trade_no' => $bill_trade_no,
                    'endtime' => $result['pay_time'] ?? '',
                ];
            } else {
                throw new \Exception($result["error_msg"] ?? '返回数据解析失败');
            }
        } else {
            throw new \Exception($result["resp_msg"] ?? '返回数据解析失败');
        }
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'service' => 'unified_refund',
            'merchant_id' => $this->channel['appid'],
            'leshua_order_id' => $order['api_trade_no'],
            'merchant_refund_id' => $order['refund_no'],
            'refund_amount' => strval($order['refundmoney'] * 100),
            'nonce_str' => getSid(),
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);

        $response = get_curl(self::API_URL, http_build_query($params));
        $result = $this->xml2array($response);
        if (isset($result["resp_code"]) && $result["resp_code"] == '0') {
            if (isset($result['result_code']) && $result['result_code'] == '0') {
                return ['code' => 0, 'trade_no' => $result['leshua_refund_id'], 'refund_fee' => $result['refund_amount'] / 100];
            } else {
                return ['code' => -1, 'msg' => $result["error_msg"]];
            }
        } else {
            return ['code' => -1, 'msg' => $result["resp_msg"] ?? '返回数据解析失败'];
        }
    }

    private function queryOrder(string $leshua_order_id): array|bool
    {
        $params = [
            'service' => 'query_status',
            'merchant_id' => $this->channel['appid'],
            'leshua_order_id' => $leshua_order_id,
            'nonce_str' => getSid(),
        ];
        $params['sign'] = $this->makeSign($params, $this->channel['appkey']);
        $response = get_curl(self::API_URL, http_build_query($params));
        if (!$response) return false;
        $result = $this->xml2array($response);
        if (isset($result["resp_code"]) && $result["resp_code"] == '0') {
            if (isset($result['result_code']) && $result['result_code'] == '0') {
                return $result;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
}
