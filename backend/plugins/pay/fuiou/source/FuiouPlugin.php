<?php

declare(strict_types=1);

namespace plugins\payment\fuiou;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

/**
 * https://fundwx.fuiou.com/doc/#/aggregatePay/
 */
class FuiouPlugin extends BasePayment
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
            } elseif ($ctx->isMobile && $ctx->mdevice !== 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
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
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $ctx->mdevice !== 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //通用下单
    private function addOrder(string $pay_type, PaymentContext $ctx): string
    {
        $tradeNo = $ctx->order['trade_no'];
        if (($this->channel['appswitch'] ?? 0) == 1) {
            $apiurl = 'https://aipaytest.fuioupay.com/aggregatePay/preCreate';
        } else {
            $apiurl = 'https://aipay-cloud.fuioupay.com/aggregatePay/preCreate';
        }
        $param = [
            'version' => '1.0',
            'mchnt_cd' => $this->channel['appid'],
            'random_str' => random(32),
            'order_type' => $pay_type,
            'order_amt' => strval($ctx->order['realmoney'] * 100),
            'mchnt_order_no' => $this->channel['appurl'] . $tradeNo,
            'txn_begin_ts' => date('YmdHis'),
            'goods_des' => $ctx->ordername,
            'term_id' => (string) rand(10000000, 99999999),
            'term_ip' => request()->clientip,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        $param_ord = ['mchnt_cd', 'order_type', 'order_amt', 'mchnt_order_no', 'txn_begin_ts', 'goods_des', 'term_id', 'term_ip', 'notify_url', 'random_str', 'version'];
        $signStr = '';
        foreach ($param_ord as $key) {
            $signStr .= $param[$key] . '|';
        }
        $signStr .= $this->channel['appkey'];
        $param['sign'] = md5($signStr);

        return self::lockPayData($tradeNo, function () use ($apiurl, $param) {
            $data = get_curl($apiurl, json_encode($param), 0, 0, 0, 0, 0, ['Content-Type: application/json']);
            if (!$data) throw new Exception('接口请求失败');
            $result = json_decode($data, true);
            if (isset($result['result_code']) && $result['result_code'] == '000000') {
                return $result['qr_code'];
            } else {
                throw new Exception($result['result_msg'] ?? '返回数据解析失败');
            }
        });
    }

    //公众号小程序下单
    private function jspay(string $trade_type, string $sub_openid, PaymentContext $ctx, ?string $sub_appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        if (($this->channel['appswitch'] ?? 0) == 1) {
            $apiurl = 'https://aipaytest.fuioupay.com/aggregatePay/wxPreCreate';
        } else {
            $apiurl = 'https://aipay-cloud.fuioupay.com/aggregatePay/wxPreCreate';
        }
        $param = [
            'version' => '1.0',
            'mchnt_cd' => $this->channel['appid'],
            'random_str' => random(32),
            'order_amt' => strval($ctx->order['realmoney'] * 100),
            'mchnt_order_no' => $this->channel['appurl'] . $tradeNo,
            'txn_begin_ts' => date('YmdHis'),
            'goods_des' => $ctx->ordername,
            'term_id' => (string) rand(10000000, 99999999),
            'term_ip' => request()->clientip,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'trade_type' => $trade_type,
            'sub_openid' => $sub_openid,
        ];
        if ($sub_appid) {
            $param['sub_appid'] = $sub_appid;
        }
        $param_ord = ['mchnt_cd', 'trade_type', 'order_amt', 'mchnt_order_no', 'txn_begin_ts', 'goods_des', 'term_id', 'term_ip', 'notify_url', 'random_str', 'version'];
        $signStr = '';
        foreach ($param_ord as $key) {
            $signStr .= $param[$key] . '|';
        }
        $signStr .= $this->channel['appkey'];
        $param['sign'] = md5($signStr);

        return self::lockPayData($tradeNo, function () use ($apiurl, $param) {
            $data = get_curl($apiurl, json_encode($param), 0, 0, 0, 0, 0, ['Content-Type: application/json']);
            if (!$data) throw new Exception('接口请求失败');
            $result = json_decode($data, true);
            if (isset($result['result_code']) && $result['result_code'] == '000000') {
                return $result;
            } else {
                throw new Exception($result['result_msg'] ?? '返回数据解析失败');
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
                $code_url = $this->addOrder('ALIPAY', $ctx);
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

    //支付宝JS支付
    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!empty($ctx->order['sub_openid'])) {
            $user_id = $ctx->order['sub_openid'];
            $user_type = 'userid';
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }
        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;
        if ($user_type == 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $result = $this->jspay('FWC', $user_id, $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }
        if (empty($result['reserved_transaction_id'])) return ['type' => 'error', 'msg' => '支付宝下单失败！支付订单号为空'];

        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['reserved_transaction_id']];
        }
        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['reserved_transaction_id'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            if ($this->channel['appwxmp'] > 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
        } else {
            try {
                $code_url = $this->addOrder('WECHAT', $ctx);
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

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $code = request()->get('code', '', 'trim');
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
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        try {
            $result = $this->jspay('LETPAY', $openid, $ctx, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }
        $payinfo = ['appId' => $result['sdk_appid'], 'timeStamp' => $result['sdk_timestamp'], 'nonceStr' => $result['sdk_noncestr'], 'package' => $result['sdk_package'], 'signType' => $result['sdk_signtype'], 'paySign' => $result['sdk_paysign']];
        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $payinfo]];
    }

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!empty($ctx->order['sub_openid'])) {
            if (!empty($ctx->order['sub_appid'])) {
                $wxinfo = ['appid' => $ctx->order['sub_appid']];
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
            $result = $this->jspay($ctx->order['is_applet'] == 1 ? 'LETPAY' : 'JSAPI', $openid, $ctx, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        $payinfo = ['appId' => $result['sdk_appid'], 'timeStamp' => $result['sdk_timestamp'], 'nonceStr' => $result['sdk_noncestr'], 'package' => $result['sdk_package'], 'signType' => $result['sdk_signtype'], 'paySign' => $result['sdk_paysign']];
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
            $code_url = $this->addOrder('UNIONPAY', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '银联云闪付下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) {
            return ['type' => 'html', 'data' => '0'];
        }
        $param_ord = ['mchnt_cd', 'mchnt_order_no', 'settle_order_amt', 'order_amt', 'txn_fin_ts', 'reserved_fy_settle_dt', 'random_str'];
        $signStr = '';
        foreach ($param_ord as $key) {
            $signStr .= ($arr[$key] ?? '') . '|';
        }
        $signStr .= $this->channel['appkey'];
        $sign = md5($signStr);
        if ($sign === ($arr['sign'] ?? '')) {
            $out_trade_no = substr($arr['mchnt_order_no'], strlen($this->channel['appurl']));
            $trade_no = $arr['transaction_id'];
            $money = $arr['order_amt'];
            $buyer = $arr['reserved_buyer_logon_id'] ?? '';
            $bill_mch_trade_no = $arr['reserved_channel_order_id'] ?? null;
            $end_time = $arr['txn_fin_ts'];
            if ($out_trade_no == $ctx->order['trade_no']) {
                $this->processNotify($ctx->order, $trade_no, $buyer, null, $bill_mch_trade_no, $end_time);
            }
            return ['type' => 'html', 'data' => '1'];
        }
        return ['type' => 'html', 'data' => '0'];
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        if (($this->channel['appswitch'] ?? 0) == 1) {
            $apiurl = 'https://aipaytest.fuioupay.com/aggregatePay/commonQuery';
        } else {
            $apiurl = 'https://aipay-cloud.fuioupay.com/aggregatePay/commonQuery';
        }
        if ($order['type'] == 1) {
            $pay_type = 'ALIPAY';
        } elseif ($order['type'] == 2) {
            $pay_type = 'WECHAT';
        } elseif ($order['type'] == 4) {
            $pay_type = 'UNIONPAY';
        } else {
            return ['code' => -1, 'msg' => '不支持的支付类型'];
        }
        $param = [
            'version' => '1.0',
            'mchnt_cd' => $this->channel['appid'],
            'term_id' => (string) rand(10000000, 99999999),
            'mchnt_order_no' => $this->channel['appurl'] . $order['trade_no'],
            'random_str' => random(32),
            'order_type' => $pay_type,
        ];
        $param_ord = ['mchnt_cd', 'order_type', 'mchnt_order_no', 'term_id', 'random_str', 'version'];
        $signStr = '';
        foreach ($param_ord as $key) {
            $signStr .= $param[$key] . '|';
        }
        $signStr .= $this->channel['appkey'];
        $param['sign'] = md5($signStr);
        $data = get_curl($apiurl, json_encode($param), 0, 0, 0, 0, 0, ['Content-Type: application/json']);
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($data, true);
        if (isset($result['result_code']) && $result['result_code'] == '000000') {
            return [
                'api_trade_no' => $result['transaction_id'],
                'status' => $result['trans_stat'] == 'SUCCESS' ? 1 : 0,
                'money' => $result['order_amt'] / 100,
                'buyer' => $result['reserved_buyer_logon_id'] ?? '',
                'bill_mch_trade_no' => $result['reserved_channel_order_id'] ?? '',
                'endtime' => $result['reserved_txn_fin_ts'] ?? '',
            ];
        } else {
            throw new \Exception($result['result_msg'] ?? '查询失败');
        }
    }

    //退款
    public function refund(array $order): array
    {
        if (($this->channel['appswitch'] ?? 0) == 1) {
            $apiurl = 'https://aipaytest.fuioupay.com/aggregatePay/commonRefund';
        } else {
            $apiurl = 'https://aipay-cloud.fuioupay.com/aggregatePay/commonRefund';
        }
        if ($order['type'] == 1) {
            $pay_type = 'ALIPAY';
        } elseif ($order['type'] == 2) {
            $pay_type = 'WECHAT';
        } elseif ($order['type'] == 4) {
            $pay_type = 'UNIONPAY';
        } else {
            return ['code' => -1, 'msg' => '不支持的支付类型'];
        }
        
        $param = [
            'version' => '1.0',
            'mchnt_cd' => $this->channel['appid'],
            'term_id' => (string) rand(10000000, 99999999),
            'random_str' => random(32),
            'mchnt_order_no' => $this->channel['appurl'] . $order['trade_no'],
            'refund_order_no' => $this->channel['appurl'] . $order['refund_no'],
            'order_type' => $pay_type,
            'total_amt' => strval($order['realmoney'] * 100),
            'refund_amt' => strval($order['refundmoney'] * 100),
        ];
        $param_ord = ['mchnt_cd', 'order_type', 'mchnt_order_no', 'refund_order_no', 'total_amt', 'refund_amt', 'term_id', 'random_str', 'version'];
        $signStr = '';
        foreach ($param_ord as $key) {
            $signStr .= $param[$key] . '|';
        }
        $signStr .= $this->channel['appkey'];
        $param['sign'] = md5($signStr);
        $data = get_curl($apiurl, json_encode($param), 0, 0, 0, 0, 0, ['Content-Type: application/json']);
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($data, true);

        if (isset($result['result_code']) && $result['result_code'] == '000000') {
            return ['code' => 0, 'trade_no' => $result['mchnt_order_no'] ?? '', 'refund_fee' => $result['reserved_refund_amt'] ?? 0];
        } else {
            return ['code' => -1, 'msg' => $result['result_msg'] ?? '退款失败'];
        }
    }
}
