<?php

declare(strict_types=1);

namespace plugins\payment\unionpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

/**
 * https://up.95516.com/open/openapi?code=unionpay
 */
class UnionpayPlugin extends BasePayment
{
    private array $payConfig;

    public function __construct(array $channel)
    {
        parent::__construct($channel);
        $this->payConfig = [
            'gateway_url' => $channel['appurl'] ?? '',
            'sign_type' => 'MD5',
            'mchid' => $channel['appid'],
            'key' => $channel['appkey'],
        ];
        if (empty($this->payConfig['gateway_url'])) {
            $this->payConfig['gateway_url'] = 'https://qra.95516.com/pay/gateway';
        }
    }

    private function createClient(): SwiftpassClient
    {
        return new SwiftpassClient($this->payConfig);
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
            } elseif ($ctx->isMobile && ($this->channel['appwxa'] > 0 || in_array('3', $this->channel['apptype']))) {
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
            } elseif ($ctx->order['typename'] == 'bank') {
                return $this->bankjs($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && ($this->channel['appwxa'] > 0 || in_array('3', $this->channel['apptype']))) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'qqpay') {
            return $this->qqpay($ctx);
        } elseif ($ctx->order['typename'] == 'jdpay') {
            return $this->jdpay($ctx);
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //扫码通用
    private function nativepay(PaymentContext $ctx): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'service' => 'unified.trade.native',
            'body' => $ctx->ordername,
            'total_fee' => strval($ctx->order['realmoney'] * 100),
            'mch_create_ip' => request()->clientip,
            'out_trade_no' => $tradeNo,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        $client = $this->createClient();
        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->requestApi($params);
            $code_url = $result['code_url'];
            return $code_url;
        });
    }

    //微信JS支付
    private function weixinjspay(PaymentContext $ctx, string $sub_appid, string $sub_openid, string $is_minipg = '0'): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'service' => 'pay.weixin.jspay',
            'is_raw' => '1',
            'is_minipg' => $is_minipg,
            'body' => $ctx->ordername,
            'sub_appid' => $sub_appid,
            'sub_openid' => $sub_openid,
            'total_fee' => strval($ctx->order['realmoney'] * 100),
            'mch_create_ip' => request()->clientip,
            'out_trade_no' => $tradeNo,
            'device_info' => 'AND_WAP',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        $client = $this->createClient();
        $result = $client->requestApi($params);
        return $result['pay_info'];
    }

    //支付宝服务窗支付
    private function alipayjspay(PaymentContext $ctx, string $buyer_id): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'service' => 'pay.alipay.jspay',
            'body' => $ctx->ordername,
            'total_fee' => strval($ctx->order['realmoney'] * 100),
            'mch_create_ip' => request()->clientip,
            'out_trade_no' => $tradeNo,
            'buyer_id' => $buyer_id,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        $client = $this->createClient();
        $result = $client->requestApi($params);
        return $result['pay_info'];
    }

    //微信H5支付
    private function weixinh5pay(PaymentContext $ctx): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'service' => 'pay.weixin.wappay',
            'body' => $ctx->ordername,
            'total_fee' => strval($ctx->order['realmoney'] * 100),
            'mch_create_ip' => request()->clientip,
            'out_trade_no' => $tradeNo,
            'device_info' => 'AND_WAP',
            'mch_app_name' => config_get('sitename'),
            'mch_app_id' => $siteurl,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'callback_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        $client = $this->createClient();
        $result = $client->requestApi($params);
        return $result['pay_info'];
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
                $code_url = $this->nativepay($ctx);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败 ' . $ex->getMessage()];
            }
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //支付宝服务窗支付
    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!empty($ctx->order['sub_openid'])) {
            $user_id = $ctx->order['sub_openid'];
            $user_type = 'uid';
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }

        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;
        if ($user_type == 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $result = $this->alipayjspay($ctx, $user_id);
            $trade_no = json_decode($result, true)['tradeNO'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $trade_no];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $trade_no, 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->nativepay($ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //QQ扫码支付
    public function qqpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->nativepay($ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => 'QQ钱包支付下单失败 ' . $ex->getMessage()];
        }

        if ($ctx->isMobile && !request()->get('qrcode')) {
            return ['type' => 'qrcode', 'page' => 'qqpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'qqpay_qrcode', 'url' => $code_url];
        }
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->nativepay($ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败 ' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'unionpay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
        }
    }

    //云闪付JS支付
    public function bankjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'service' => 'pay.unionpay.jspay',
            'body' => $ctx->ordername,
            'user_id' => $ctx->order['sub_openid'],
            'total_fee' => strval($ctx->order['realmoney'] * 100),
            'mch_create_ip' => request()->clientip,
            'out_trade_no' => $tradeNo,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        try {
            $client = $this->createClient();
            $result = $client->requestApi($params);
            $code_url = $result['pay_url'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败 ' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    //获取云闪付用户ID
    public function get_unionpay_userid(string $userAuthCode): array
    {
        $params = [
            'service' => 'pay.unionpay.userid',
            'user_auth_code' => $userAuthCode,
            'app_up_identifier' => get_unionpay_ua(),
        ];

        try {
            $client = $this->createClient();
            $result = $client->requestApi($params);
            return ['code' => 0, 'data' => $result['user_id']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //京东扫码支付
    public function jdpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->nativepay($ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '京东支付下单失败 ' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'jdpay_qrcode', 'url' => $code_url];
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
            $pay_info = $this->weixinjspay($ctx, $wxinfo['appid'], $openid, $ctx->order['is_applet'] == 1 ? '1' : '0');
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
            $pay_info = $this->weixinjspay($ctx, $wxinfo['appid'], $openid, '1');
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($pay_info, true)]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('3', $this->channel['apptype'])) {
            try {
                $pay_info = $this->weixinh5pay($ctx);
                return ['type' => 'jump', 'url' => $pay_info];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
            }
        } elseif ($this->channel['appwxa'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } else {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        try {
            $client = $this->createClient();
            $result = $client->notify();
            if ($result['status'] == '0' && $result['result_code'] == '0') {
                if ($result['out_trade_no'] == $ctx->order['trade_no'] && $result['total_fee'] == strval($ctx->order['realmoney'] * 100)) {
                    $buyer = $result['sub_openid'] ?? $result['openid'] ?? null;
                    ($this->markTrustedCallback($ctx, 'notify', 'unionpay-sdk-notify'))(function () use ($ctx, $result, $buyer) {
                        $this->processNotify($ctx->order, $result['transaction_id'], $buyer, $result['out_transaction_id'], null, $result['time_end']);
                    });
                }
                return ['type' => 'html', 'data' => 'success'];
            } else {
                return ['type' => 'html', 'data' => 'failure'];
            }
        } catch (Exception $e) {
            return ['type' => 'html', 'data' => $e->getMessage()];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
    }

    public function query(array $order): array
    {
        $params = [
            'service' => 'unified.trade.query',
            'out_trade_no' => $order['trade_no'],
        ];
        $client = $this->createClient();
        $result = $client->requestApi($params);
        return [
            'api_trade_no' => $result['transaction_id'] ?? '',
            'status' => $result['trade_state'] == 'SUCCESS' ? 1 : 0,
            'money' => ($result['total_fee'] ?? 0) / 100,
            'buyer' => $result['sub_openid'] ?? $result['openid'] ?? null,
            'bill_trade_no' => $result['out_transaction_id'] ?? null,
            'endtime' => $result['time_end'] ?? null,
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'service' => 'unified.trade.refund',
            'transaction_id' => $order['api_trade_no'],
            'out_refund_no' => $order['refund_no'],
            'total_fee' => strval($order['realmoney'] * 100),
            'refund_fee' => strval($order['refundmoney'] * 100),
            'op_user_id' => $this->payConfig['mchid'],
        ];

        try {
            $client = $this->createClient();
            $data = $client->requestApi($params);
            return ['code' => 0, 'trade_no' => $data['refund_id'], 'refund_fee' => $data['refund_fee']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }
}
