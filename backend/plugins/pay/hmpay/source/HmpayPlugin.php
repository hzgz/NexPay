<?php

declare(strict_types=1);

namespace plugins\payment\hmpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class HmpayPlugin extends BasePayment
{
    private array $hmpayConfig;

    public function __construct(array $channel)
    {
        parent::__construct($channel);
        $this->hmpayConfig = [
            'signType' => "RSA",
            'platRsaPublicKey' => $this->channel['appkey'],
            'rsaPrivateKey' => $this->channel['appsecret'],
            'charset' => "UTF-8",
            'gatewayUrl' => "https://hmpay.sandpay.com.cn/gateway/api",
            'appId' => $this->channel['appid'],
            'subAppId' => $this->channel['appmchid'] ?? '',
        ];
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                if ($this->channel['appwxmp'] > 0) {
                    return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
                } else {
                    return ['type' => 'jump', 'url' => '/pay/wxmppay/' . $tradeNo . '/?d=1'];
                }
            } elseif ($ctx->isMobile) {
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
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                if ($this->channel['appwxmp'] > 0) {
                    return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
                } else {
                    return $this->wxmppay($ctx);
                }
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

    //扫码支付下单
    private function qrcodepay(PaymentContext $ctx, string $pay_way): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $bizContent = [
            'pay_way' => $pay_way,
            'create_ip' => request()->clientip,
            'total_amount' => $ctx->order['realmoney'],
            'out_order_no' => $tradeNo,
            'body' => $ctx->ordername,
            'store_id' => $this->channel['appurl'],
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        return self::lockPayData($tradeNo, function () use ($bizContent) {
            $client = new HmPayClient($this->hmpayConfig);
            $request = $client->request('trade.percreate', $bizContent);
            $result = $client->execute($request);
            if (isset($result['sub_code']) && $result['sub_code'] == 'SUCCESS') {
                return $result['qr_code'];
            } else {
                throw new Exception('[' . $result['sub_code'] . ']' . $result['sub_msg']);
            }
        });
    }

    //JSAPI支付下单
    private function jsapipay(PaymentContext $ctx, string $pay_way, string $openid, ?string $appid = null): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($appid) {
            $bizContent = [
                'pay_way' => $pay_way,
                'pay_type' => 'JSAPI',
                'mer_app_id' => $appid,
                'mer_buyer_id' => $openid,
                'create_ip' => request()->clientip,
                'total_amount' => $ctx->order['realmoney'],
                'out_order_no' => $tradeNo,
                'body' => $ctx->ordername,
                'store_id' => $this->channel['appurl'],
                'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
                'redirect_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            ];
        } else {
            $bizContent = [
                'pay_way' => $pay_way,
                'pay_type' => 'JSAPI',
                'buyer_id' => $openid,
                'create_ip' => request()->clientip,
                'total_amount' => $ctx->order['realmoney'],
                'out_order_no' => $tradeNo,
                'body' => $ctx->ordername,
                'store_id' => $this->channel['appurl'],
                'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
                'redirect_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            ];
        }

        return self::lockPayData($tradeNo, function () use ($bizContent) {
            $client = new HmPayClient($this->hmpayConfig);
            $request = $client->request('trade.create', $bizContent);
            $result = $client->execute($request);
            if (isset($result['sub_code']) && $result['sub_code'] == 'SUCCESS') {
                return $result['pay_data'];
            } else {
                throw new Exception('[' . $result['sub_code'] . ']' . $result['sub_msg']);
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
                $code_url = $this->qrcodepay($ctx, 'ALIPAY');
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
            $result = $this->jsapipay($ctx, 'ALIPAY', $user_id);
            $trade_no = json_decode($result, true)['tradeNo'];
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
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            if ($this->channel['appwxmp'] > 0 && $this->channel['appwxa'] == 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
        } else {
            try {
                $code_url = $this->qrcodepay($ctx, 'WECHAT');
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

    //微信公众号支付（自有公众号）
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        //①、获取用户openid
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
            try {
                $openid = wechat_oauth($wxinfo);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        //②、统一下单
        try {
            $jsApiParameters = $this->jsapipay($ctx, 'WECHAT', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $jsApiParameters];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $jsApiParameters, 'redirect_url' => $redirect_url]];
    }

    //微信公众号支付（平台公众号）
    public function wxmppay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (!request()->get('buyer_id')) {
            $client = new HmPayClient($this->hmpayConfig);
            $bizContent = [
                'pay_way' => 'WECHAT',
                'store_id' => $this->channel['appurl'],
                'url' => $siteurl . 'pay/wxmppay/' . $tradeNo . '/',
            ];
            try {
                $request = $client->request('trade.create.auth', $bizContent);
                $result = $client->execute($request);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            if ($result['sub_code'] == 'SUCCESS') {
                $auth_url = $result['auth_url'];
                return ['type' => 'jump', 'url' => $auth_url];
            } else {
                return ['type' => 'error', 'msg' => '获取授权地址失败！[' . $result['sub_code'] . ']' . $result['sub_msg']];
            }
        }

        $buyer_id = trim(request()->get('buyer_id'));
        $blocks = checkBlockUser($buyer_id, $tradeNo);
        if ($blocks) return $blocks;

        try {
            $jsApiParameters = $this->jsapipay($ctx, 'WECHAT', $buyer_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        $param = [
            'pay_way' => 'WECHAT',
            'callback_url' => '/pay/return/' . $tradeNo . '/',
            'cancel_url' => '/pay/error/' . $tradeNo . '/',
            'pay_data' => $jsApiParameters
        ];
        $url = 'https://hmpay.sandpay.com.cn/gateway/trade/jsApiPay?' . http_build_query($param);

        return ['type' => 'jump', 'url' => $url];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($this->channel['appwxa'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } elseif ($this->channel['appwxmp'] > 0) {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            $code_url = $siteurl . 'pay/wxmppay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        }
    }

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $code = request()->get('code', '', 'trim');
        if ($code === '') {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }

        //①、获取用户openid
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

        //②、统一下单
        try {
            $pay_data = $this->jsapipay($ctx, 'WECHAT', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($pay_data, true)]];
    }

    //云闪付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcodepay($ctx, 'UNIONPAY');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $client = new HmPayClient($this->hmpayConfig);
        $verify_result = $client->rsaCheckV2(request()->get());

        if ($verify_result) {//验证成功
            //商户订单号
            $out_trade_no = request()->get('out_order_no');

            //支付宝交易号
            $trade_no = request()->get('plat_trx_no');

            //买家支付宝
            $buyer_id = request()->get('buyer_id');

            //交易金额
            $total_amount = request()->get('pay_amount');

            if (request()->get('trade_status') == 'SUCCESS') {
                if ($out_trade_no == $ctx->order['trade_no'] && round((float)$total_amount, 2) == round((float)$ctx->order['realmoney'], 2)) {
                    $bill_trade_no = request()->get('bank_trx_no', '');
                    if ($ctx->order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
                    $bill_mch_trade_no = request()->get('bank_order_no');
                    $end_time = request()->get('pay_success_time');
                    $this->processNotify($ctx->order, $trade_no, $buyer_id, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'FAIL'];
        }
    }

    //支付失败页面
    public function error(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'error'];
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $client = new HmPayClient($this->hmpayConfig);
        $request = $client->request('trade.query', [
            'order_create_time' => date('YmdHis', strtotime($order['addtime'])),
            'out_order_no' => $order['trade_no'],
        ]);
        $result = $client->execute($request);
        $bill_trade_no = $result['bank_trx_no'] ?? '';
        if ($order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
        return [
            'api_trade_no' => $result['plat_trx_no'],
            'status' => $result['sub_code'] == 'SUCCESS' ? 1 : 0,
            'money' => $result['total_amount'],
            'buyer' => $result['buyer_id'] ?? '',
            'bill_trade_no' => $bill_trade_no,
            'bill_mch_trade_no' => $result['bank_order_no'] ?? '',
            'endtime' => $result['success_time'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $bizContent = [
            'plat_trx_no' => $order['api_trade_no'],
            'refund_amount' => $order['refundmoney'],
            'refund_request_no' => $order['refund_no'],
            'store_id' => '100001',
        ];

        try {
            $client = new HmPayClient($this->hmpayConfig);
            $request = $client->request('trade.refund', $bizContent);
            $result = $client->execute($request);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
        if ($result['sub_code'] == 'REFUND_SUCCESS') {
            $result = ['code' => 0, 'trade_no' => $result['out_order_no'], 'refund_fee' => $result['refund_amount']];
        } else {
            $result = ['code' => -1, 'msg' => '[' . $result['sub_code'] . ']' . $result['sub_msg']];
        }
        return $result;
    }
}
