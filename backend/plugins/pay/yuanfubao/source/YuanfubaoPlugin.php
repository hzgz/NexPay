<?php

declare(strict_types=1);

namespace plugins\payment\yuanfubao;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

/**
 * @see https://apifox.com/apidoc/shared-81264a1b-bd6d-44ed-b456-3222571dffab?pwd=yuanfubao2023  访问密码: yuanfubao2023
 */
class YuanfubaoPlugin extends BasePayment
{
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
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    private function execute(string $method, array $data): array
    {
        $apiurl = 'https://api.yuanfubao.net/open';
        $params = [
            'method' => $method,
            'data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'business_no' => $this->channel['appid'],
            'timestamp' => time(),
        ];
        $params['sign'] = md5($params['timestamp'] . $params['data'] . $this->channel['appkey']);
        $response = get_curl($apiurl, http_build_query($params));
        $result = json_decode($response, true);
        if (isset($result['error']) && $result['error'] == 0) {
            return json_decode($result['data'], true);
        } else {
            throw new Exception($result['msg'] ? $result['msg'] : '返回数据解析失败');
        }
    }

    private function qrcode(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'order_no' => $tradeNo,
            'money' => $ctx->order['realmoney'],
            'remark' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        return self::lockPayData($tradeNo, function () use ($params) {
            return $this->execute('yfb_pay.order.create', $params);
        });
    }

    private function precreate(PaymentContext $ctx, int $trans_type, ?string $openid = null, ?string $appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'order_no' => $tradeNo,
            'money' => $ctx->order['realmoney'],
            'trans_type' => $trans_type,
            'remark' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($trans_type == 0 || $trans_type == 1) {
            $params['openid'] = $openid;
            $params['app_id'] = $appid;
        } elseif ($trans_type == 2) {
            $params['ali_user_id'] = $openid;
        }
        return self::lockPayData($tradeNo, function () use ($params) {
            return $this->execute('yfb_pay.order.pay', $params);
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
                $result = $this->qrcode($ctx);
                $code_url = $result['alipay_url'];
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
            $result = $this->precreate($ctx, 2, $user_id);
            $alipay_trade_no = $result['ali_pay_info']['tradeNO'];
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

        if (in_array('1', $this->channel['apptype'])) {
            try {
                $result = $this->qrcode($ctx);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
            }

            if ($ctx->isMobile) {
                return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $result['openlink']];
            } else {
                return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $result['url_scheme']];
            }
        } else {
            if ($this->channel['appwxmp'] > 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }

            if ($ctx->isMobile) {
                return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
            } else {
                return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
            }
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
            $result = $this->precreate($ctx, $ctx->order['is_applet'] == 1 ? 0 : 1, $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
        }
        $payinfo = $result['wx_pay_info'];
        if ($ctx->method == 'jsapi') {
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
        if (!$wxinfo) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];

        try {
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];

        try {
            $result = $this->precreate($ctx, 0, $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }
        $payinfo = $result['wx_pay_info'];

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

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!request()->post()) return ['type' => 'html', 'data' => 'No data'];

        $sign = md5(request()->post('timestamp') . request()->post('data') . $this->channel['appkey']);

        if ($sign === request()->post("sign")) {
            $data = json_decode(request()->post('data', ''), true);
            $out_trade_no = $data['order_no'];
            $api_trade_no = $data['bill_no'];
            $money = $data['money'];
            $bill_trade_no = $data['third_party_no'];
            $bill_mch_trade_no = $data['trade_no'];

            if ($out_trade_no == $tradeNo) {
                $this->processNotify($ctx->order, $api_trade_no, null, $bill_trade_no, $bill_mch_trade_no);
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
        $params = [
            'order_no' => $order['trade_no'],
        ];
        $result = $this->execute('yfb_pay.order.query_order', $params);
        return [
            'api_trade_no' => $result['bill_no'],
            'status' => $result['trade_state'] == 'SUCCESS' ? 1 : 0,
            'money' => $result['amount'],
            'bill_trade_no' => $result['third_party_no'] ?? null,
            'bill_mch_trade_no' => $result['trade_no'] ?? null,
            'endtime' => $result['pay_time'] ?? null,
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'refund_no' => $order['refund_no'],
            'bill_no' => $order['api_trade_no'],
            'amount' => $order['refundmoney'],
            'reason' => '',
        ];
        try {
            $result = $this->execute('yfb_pay.order.order_refund', $params);
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
        return ['code' => 0, 'trade_no' => $result['refund_no'], 'refund_fee' => $result['amount']];
    }
}
