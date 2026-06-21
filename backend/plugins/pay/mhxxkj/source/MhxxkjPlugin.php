<?php

declare(strict_types=1);

namespace plugins\payment\mhxxkj;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class MhxxkjPlugin extends BasePayment
{
    private PayService $client;

    public function __construct(array $channel)
    {
        parent::__construct($channel);
        $this->client = new PayService($channel['appid'], $channel['appkey'], $channel['appsecret']);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->order['typename'] === 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $apptype)) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] === 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $apptype) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && (in_array('2', $apptype) && $this->channel['appwxa'] > 0 || in_array('3', $apptype))) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] === 'qqpay') {
            return ['type' => 'jump', 'url' => '/pay/qqpay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] === 'bank') {
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] === 'douyinpay') {
            return ['type' => 'jump', 'url' => '/pay/douyinpay/' . $tradeNo . '/'];
        }
        return ['type' => 'error', 'msg' => '暂不支持的付款方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->method === 'jsapi') {
            if ($ctx->order['typename'] === 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] === 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->order['typename'] === 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $apptype)) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] === 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $apptype) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && (in_array('2', $apptype) && $this->channel['appwxa'] > 0 || in_array('3', $apptype))) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] === 'qqpay') {
            return $this->qqpay($ctx);
        } elseif ($ctx->order['typename'] === 'bank') {
            return $this->bank($ctx);
        } elseif ($ctx->order['typename'] === 'douyinpay') {
            return $this->douyinpay($ctx);
        }
        return ['type' => 'error', 'msg' => '暂不支持的付款方式'];
    }

    //统一支付接口
    private function addOrder(PaymentContext $ctx, string $payWay, string $payType, ?string $appid = null, ?string $openid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apiurl = 'https://platform.mhxxkj.com/paygateway/mbpay/order/v1';

        if (request()->get('r') == 1) {
            $returnUrl = $siteurl . 'pay/ok/' . $tradeNo . '/';
        } else {
            $returnUrl = $siteurl . 'pay/return/' . $tradeNo . '/';
        }
        $params = [
            'merAccount' => $this->channel['appid'],
            'merNo' => $this->channel['appmchid'],
            'orderId' => $tradeNo,
            'time' => time(),
            'amount' => intval(round($ctx->order['realmoney'] * 100)),
            'product' => $ctx->ordername,
            'payWay' => $payWay,
            'payType' => $payType,
            'userIp' => request()->clientip,
            'returnUrl' => $returnUrl,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'riskItem' => json_encode([
                'appName' => config_get('sitename'),
                'deviceInfo' => 'AND_WAP',
                'applicationId' => request()->siteurl
            ]),
        ];
        if ($appid && $openid) {
            $params['openId'] = $openid;
            $params['appId'] = $appid;
        } elseif ($openid) {
            $params['openId'] = $openid;
        }

        $client = $this->client;
        return self::lockPayData($tradeNo, function () use ($client, $apiurl, $params, $tradeNo) {
            $result = $client->submit($apiurl, $params);
            $this->updateOrder($tradeNo, $result['mbOrderId']);
            return $result;
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if (in_array('3', $apptype) && $ctx->isMobile) {
            try {
                $result = $this->addOrder($ctx, 'ALIPAY', 'ALIPAY_H5');
                $code_url = $result['payUrl'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'jump', 'url' => $code_url];
        } elseif (in_array('1', $apptype)) {
            try {
                $result = $this->addOrder($ctx, 'ALIPAY', 'SCANPAY_ALIPAY');
                $code_url = $result['payUrl'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('3', $apptype) && !$ctx->isMobile) {
            $code_url = $siteurl . 'pay/alipay/' . $tradeNo . '/?r=1';
        } elseif (in_array('2', $apptype)) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
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
        $user_type = 'userid';

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
            $result = $this->addOrder($ctx, 'ALIPAY', 'SHH_ ALIPAY', null, $user_id);
            $trade_no = json_decode($result['payInfo'], true)['tradeNO'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method === 'jsapi') {
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
        $apptype = $this->channel['apptype'] ?? [];

        if (in_array('1', $apptype)) {
            try {
                $result = $this->addOrder($ctx, 'WEIXIN', 'SCANPAY_WEIXIN');
                $code_url = $result['payUrl'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('2', $apptype)) {
            if ($this->channel['appwxmp'] > 0 && $this->channel['appwxa'] == 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
        } else {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/?r=1';
        }

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $apptype = $this->channel['apptype'] ?? [];

        if (in_array('3', $apptype)) {
            try {
                $result = $this->addOrder($ctx, 'WEIXIN', 'H5_WEIXIN');
                $code_url = $result['payUrl'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
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

    //微信公众号支付
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
            $result = $this->addOrder($ctx, 'WEIXIN', 'JSAPI_WEIXIN', $wxinfo['appid'], $openid);
            $payinfo = $result['payInfo'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $payinfo, 'redirect_url' => $redirect_url]];
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
            $tools = new \WeChatPay\JsApiTool($wxinfo['appid'], $wxinfo['appsecret']);
            $openid = $tools->AppGetOpenid($code);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        //②、统一下单
        try {
            $result = $this->addOrder($ctx, 'WEIXIN', 'MINIAPP_WEIXIN', $wxinfo['appid'], $openid);
            $payinfo = $result['payInfo'];
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($payinfo, true)]];
    }

    //QQ扫码支付
    public function qqpay(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, 'QQPAY', 'SCANPAY_QQ');
            $code_url = $result['payUrl'];
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
            $result = $this->addOrder($ctx, 'UNIONPAY', 'SCANPAY_UNIONPAY');
            $code_url = $result['payUrl'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //抖音支付
    public function douyinpay(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, 'DOUYIN', 'SCANPAY_DOUYIN');
            $code_url = $result['payUrl'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '抖音支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'douyinpay_h5', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'douyinpay_qrcode', 'url' => $code_url];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $data = request()->get('data');
        if (empty($data)) return ['type' => 'html', 'data' => 'NO_DATA'];

        $result = $this->client->notify($data);

        if ($result) { //验证成功
            if ($result['orderStatus'] == 'SUCCESS') {
                $out_trade_no = $result['orderId'];
                $api_trade_no = $result['mbOrderId'];
                $money = $result['amount'];
                $bill_trade_no = $result['bankOrderNo'];
                $buyer = $result['openId'] ?? null;
                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no);
                }
            }
            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            return ['type' => 'html', 'data' => 'FAIL'];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $apiurl = 'https://platform.mhxxkj.com/paygateway/mbpay/order/query/v4';
        $params = [
            'merAccount' => $this->channel['appid'],
            'orderId' => $order['trade_no'],
            'orderDate' => date('Ymd', strtotime($order['addtime'])),
            'time' => time(),
        ];
        $result = $this->client->submit($apiurl, $params);
        return [
            'api_trade_no' => $result['mbOrderId'],
            'status' => $result['status'] == 1 ? 1 : 0,
            'money' => $result['amount'],
            'buyer' => $result['openId'] ?? null,
            'bill_trade_no' => $result['bankOrderNo'] ?? null,
            'endtime' => $result['payTime'] ?? null,
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $apiurl = 'https://platform.mhxxkj.com/paygateway/mbrefund/orderRefund/v1';

        $params = [
            'merAccount' => $this->channel['appid'],
            'merchantRefundNo' => $order['refund_no'],
            'mbOrderId' => $order['api_trade_no'],
            'time' => time(),
            'refundAmt' => intval(round($order['refundmoney'] * 100)),
            'refundCause' => '订单退款',
        ];

        try {
            $result = $this->client->submit($apiurl, $params);
            return ['code' => 0, 'trade_no' => $result['refundNo'], 'refund_fee' => $order['refundmoney']];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }
}
