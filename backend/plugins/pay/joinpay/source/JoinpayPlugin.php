<?php

declare(strict_types=1);

namespace plugins\payment\joinpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class JoinpayPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('3', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/alipaywap/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('3', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && (in_array('2', $this->channel['apptype']) || in_array('4', $this->channel['apptype']))) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'qqpay') {
            return ['type' => 'jump', 'url' => '/pay/qqpay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'jdpay') {
            return ['type' => 'jump', 'url' => '/pay/jdpay/' . $tradeNo . '/'];
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
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    private function makeSign(array $params, string $key): string
    {
        unset($params['hmac']);
        return md5(implode("", $params) . $key);
    }

    private function addOrder(PaymentContext $ctx, string $pay_type, ?string $openid = null, ?string $appid = null)
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $requrl = 'https://trade.joinpay.com/tradeRt/uniPay';
        $params = [
            'p0_Version' => '2.5',
            'p1_MerchantNo' => $this->channel['appid'],
            'p2_OrderNo' => $tradeNo,
            'p3_Amount' => $ctx->order['realmoney'],
            'p4_Cur' => '1',
            'p5_ProductName' => $ctx->ordername,
            'p8_ReturnUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'p9_NotifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'q1_FrpCode' => $pay_type,
        ];
        if ($appid && $openid) {
            $params += [
                'q5_OpenId' => $openid,
                'q7_AppId' => $appid,
            ];
        } elseif ($appid) {
            $params['q7_AppId'] = $appid;
        } elseif ($openid) {
            $params['qb_buyerId'] = $openid;
        }
        $params += [
            'qa_TradeMerchantNo' => $this->channel['appmchid'],
            'ql_TerminalIp' => request()->clientip,
        ];
        $params['hmac'] = $this->makeSign($params, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($requrl, $params, $tradeNo) {
            $response = get_curl($requrl, http_build_query($params));
            if (!$response) throw new Exception('接口请求失败');
            $result = json_decode($response, true);
            if (isset($result["ra_Code"]) && $result["ra_Code"] == 100) {
                $this->updateOrder($tradeNo, $result['r7_TrxNo']);
                return $result['rc_Result'];
            } else {
                throw new Exception($result["rb_CodeMsg"] ?? '返回数据解析失败');
            }
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype'])) {
            try {
                $code_url = $this->addOrder($ctx, 'ALIPAY_NATIVE');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('3', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            $code_url = $siteurl . 'pay/alipaywap/' . $tradeNo . '/';
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //支付宝H5
    public function alipaywap(PaymentContext $ctx): array
    {
        try {
            $html = $this->addOrder($ctx, 'ALIPAY_H5');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'html', 'data' => $html];
    }

    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $user_type = '';

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
            $result = $this->addOrder($ctx, 'ALIPAY_FWC', $user_id);
            $payinfo = json_decode($result, true);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $payinfo['tradeNo']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $payinfo['tradeNo'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype'])) {
            try {
                $code_url = $this->addOrder($ctx, 'WEIXIN_NATIVE');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('3', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
        } else {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
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
            $pay_info = $this->addOrder($ctx, $ctx->order['is_applet'] == 1 ? 'WEIXIN_XCX' : 'WEIXIN_GZH', $openid, $wxinfo['appid']);
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
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        try {
            $pay_info = $this->addOrder($ctx, 'WEIXIN_XCX', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($pay_info, true)]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('4', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } elseif (in_array('2', $this->channel['apptype'])) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
            try {
                $code_url = $this->addOrder($ctx, 'WEIXIN_H5_PLUS', null, $wxinfo['appid']);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return $this->wxpay($ctx);
        }
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'UNIONPAY_NATIVE');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        if (!request()->get("hmac")) return ['type' => 'html', 'data' => 'fail'];
        $sign = $this->makeSign(request()->get(), $this->channel['appkey']);

        if ($sign === request()->get("hmac")) {
            if (request()->get('r6_Status') == '100') {
                $out_trade_no = request()->get('r2_OrderNo');
                $api_trade_no = request()->get('r7_TrxNo');
                $money = request()->get('r3_Amount');
                $buyer = request()->get('rd_OpenId');
                $bill_trade_no = request()->get('r9_BankTrxNo', '');
                $bill_mch_trade_no = request()->get('r8_BankOrderNo');
                if ($ctx->order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);

                if ($out_trade_no == $ctx->order['trade_no']) {
                    ($this->markTrustedCallback($ctx, 'notify', 'joinpay-hmac'))(function () use ($ctx, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no) {
                        $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no);
                    });
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
        $requrl = 'https://trade.joinpay.com/tradeRt/queryOrder';
        $params = [
            'p0_Version' => '2.6',
            'p1_MerchantNo' => $this->channel['appid'],
            'p2_OrderNo' => $order['trade_no'],
        ];
        $params['hmac'] = $this->makeSign($params, $this->channel['appkey']);
        $response = get_curl($requrl, http_build_query($params));
        if (!$response) throw new \Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['rb_Code']) && $result['rb_Code'] == 100) {
            $bill_trade_no = $result['r6_BankTrxNo'] ?? '';
            if ($order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
            return [
                'api_trade_no' => $result['r5_TrxNo'],
                'status' => $result['ra_Status'] == '100' ? 1 : 0,
                'money' => $result['r3_Amount'],
                'buyer' => $result['rd_OpenId'] ?? '',
                'bill_trade_no' => $bill_trade_no,
                'endtime' => $result['rf_PayTime'] ?? '',
            ];
        } else {
            throw new \Exception($result['rc_CodeMsg'] ?? '返回数据解析失败');
        }
    }

    //退款
    public function refund(array $order): array
    {
        $requrl = 'https://trade.joinpay.com/tradeRt/refund';
        $params = [
            'p0_Version' => '2.3',
            'p1_MerchantNo' => $this->channel['appid'],
            'p2_OrderNo' => $order['trade_no'],
            'p3_RefundOrderNo' => $order['refund_no'],
            'p4_RefundAmount' => $order['refundmoney'],
            'p6_NotifyUrl' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
        ];
        $params['hmac'] = $this->makeSign($params, $this->channel['appkey']);

        $response = get_curl($requrl, http_build_query($params));
        if (!$response) throw new \Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result["ra_Status"]) && $result["ra_Status"] == 100) {
            return ['code' => 0, 'trade_no' => $result['r3_RefundOrderNo'], 'refund_fee' => $result['r4_RefundAmount']];
        } elseif (isset($result['rc_CodeMsg'])) {
            return ['code' => -1, 'msg' => $result['rc_CodeMsg']];
        } else {
            return ['code' => -1, 'msg' => '未知错误'];
        }
    }

    //退款异步回调
    public function refundnotify(PaymentContext $ctx): array
    {
        if (!request()->get("hmac")) return ['type' => 'html', 'data' => 'fail'];
        $sign = $this->makeSign(request()->get(), $this->channel['appkey']);

        if ($sign === request()->get("hmac")) {
            $status = request()->get('ra_Status') == '100' ? 1 : 2;
            if (request()->get('ra_Status') == '100') {
                $out_trade_no = request()->get('r3_RefundOrderNo');
                $api_trade_no = request()->get('r5_RefundTrxNo');
                $money = request()->get('r4_RefundAmount_str');
            }
            ($this->markTrustedCallback($ctx, 'refundnotify', 'joinpay-hmac'))(function () use ($status) {
                $this->processRefund(
                    request()->get('r3_RefundOrderNo'),
                    $status,
                    $status === 2 ? (string)request()->get('rb_CodeMsg', 'joinpay refund failed') : '',
                    request()->get('r5_RefundTrxNo'),
                    request()->get('r4_RefundAmount_str')
                );
            });
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }
}
