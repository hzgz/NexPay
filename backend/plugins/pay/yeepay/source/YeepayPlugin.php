<?php

declare(strict_types=1);

namespace plugins\payment\yeepay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class YeepayPlugin extends BasePayment
{
    public function __construct(array $channel)
    {
        parent::__construct($channel);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/'];
            } elseif ($ctx->isMobile && (in_array('3', $this->channel['apptype']) || $this->channel['appwxa'] > 0)) {
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
        } elseif ($ctx->method == 'applet') {
            return $this->wxapppay($ctx);
        } elseif ($ctx->method == 'app') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->aliapppay($ctx);
            } else {
                return $this->wxapppay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/'];
            } elseif ($ctx->isMobile && (in_array('3', $this->channel['apptype']) || $this->channel['appwxa'] > 0)) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //聚合支付托管下单
    private function tutelage_pay(PaymentContext $ctx, string $payWay, string $payType, bool $return_type = false): mixed
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'parentMerchantNo' => $this->channel['appid'],
            'merchantNo' => empty($this->channel['appmchid']) ? $this->channel['appid'] : $this->channel['appmchid'],
            'orderId' => $tradeNo,
            'orderAmount' => $ctx->order['realmoney'],
            'goodsName' => $ctx->ordername,
            'expiredTime' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'payWay' => $payWay,
            'channel' => $payType,
            'scene' => $this->channel['appswitch'] == 1 ? 'OFFLINE' : 'ONLINE',
            'userIp' => request()->clientip,
            'redirectUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];
        if ($ctx->order['profits']) {
            $this->handleProfits($params, $ctx);
        }

        $client = new YopClient($this->channel['appkey'], $this->channel['appsecret']);

        return self::lockPayData($tradeNo, function () use ($client, $params, $return_type) {
            $result = $client->post('/rest/v1.0/aggpay/tutelage/pre-pay', $params);
            if ($result['code'] == '00000') {
                return $return_type ? ['appId' => $result['appId'], 'miniProgramPath' => $result['miniProgramPath'], 'miniProgramOrgId' => $result['miniProgramOrgId']] : $result['prePayTn'];
            } else {
                throw new Exception('[' . $result['code'] . ']' . $result['message']);
            }
        });
    }

    //聚合支付统一下单
    private function pre_pay(PaymentContext $ctx, string $payWay, string $payType, ?string $appId = null, ?string $userId = null): mixed
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'parentMerchantNo' => $this->channel['appid'],
            'merchantNo' => empty($this->channel['appmchid']) ? $this->channel['appid'] : $this->channel['appmchid'],
            'orderId' => $tradeNo,
            'orderAmount' => $ctx->order['realmoney'],
            'goodsName' => $ctx->ordername,
            'expiredTime' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'redirectUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'payWay' => $payWay,
            'channel' => $payType,
            'scene' => $this->channel['appswitch'] == 1 ? 'OFFLINE' : 'ONLINE',
            'userIp' => request()->clientip,
        ];
        if ($appId) $params['appId'] = $appId;
        if ($userId) $params['userId'] = $userId;
        if ($ctx->order['profits']) {
            $this->handleProfits($params, $ctx);
        }

        $client = new YopClient($this->channel['appkey'], $this->channel['appsecret']);

        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->post('/rest/v1.0/aggpay/pre-pay', $params);
            if ($result['code'] == '00000') {
                return $result['prePayTn'];
            } else {
                throw new Exception('[' . $result['code'] . ']' . $result['message']);
            }
        });
    }

    private function handleProfits(array &$params, PaymentContext $ctx): void
    {
        $psreceiver = \app\logic\ProfitSharingLogic::getReceiver($ctx->order['profits']);
        if ($psreceiver) {
            $divideDetail = [];
            foreach ($psreceiver['info'] as $receiver) {
                $psmoney = round(floor($ctx->order['realmoney'] * $receiver['rate']) / 100, 2);
                $divideDetail[] = [
                    'ledgerNo' => $receiver['account'],
                    'amount' => $psmoney,
                    'ledgerType' => 'MERCHANT2MERCHANT',
                ];
            }
            $params['fundProcessType'] = 'REAL_TIME_DIVIDE';
            $params['divideDetail'] = json_encode($divideDetail);
            $params['divideNotifyUrl'] = config_get('localurl') . 'pay/dividenotify/' . $ctx->order['trade_no'] . '/';
        }
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
                $code_url = $this->pre_pay($ctx, 'USER_SCAN', 'ALIPAY');
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

    //支付宝生活号支付
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
            $alipay_trade_no = $this->pre_pay($ctx, 'ALIPAY_LIFE', 'ALIPAY', null, $user_id);
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
                $code_url = $this->pre_pay($ctx, 'USER_SCAN', 'WECHAT');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('3', $this->channel['apptype']) || !in_array('2', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } else {
            if ($this->channel['appwxmp'] > 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
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
            $openid = wechat_oauth($wxinfo);
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        //②、统一下单
        try {
            $payinfo = $this->pre_pay($ctx, $ctx->order['is_applet'] == 1 ? 'MINI_PROGRAM' : 'WECHAT_OFFIACCOUNT', 'WECHAT', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $payinfo];
        }

        if (request()->get('d') == 1) {
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
            $payinfo = $this->pre_pay($ctx, 'MINI_PROGRAM', 'WECHAT', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($payinfo, true)]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        if (in_array('3', $this->channel['apptype'])) {
            try {
                $jump_url = $this->tutelage_pay($ctx, 'H5_PAY', 'WECHAT');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }

            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $jump_url];
            } else {
                return ['type' => 'qrcode', 'page' => 'wxpay_h5', 'url' => $jump_url];
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
            return $this->wxpay($ctx);
        }
    }

    //支付宝APP支付
    public function aliapppay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->tutelage_pay($ctx, 'SDK_PAY', 'ALIPAY');
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'scheme', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    //微信APP支付
    public function wxapppay(PaymentContext $ctx): array
    {
        try {
            $result = $this->tutelage_pay($ctx, 'SDK_PAY', 'WECHAT', true);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => $result['appId'], 'miniProgramId' => $result['miniProgramOrgId'], 'path' => $result['miniProgramPath']]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->pre_pay($ctx, 'USER_SCAN', 'UNIONPAY');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $response = request()->post('response');
        if (!$response) return ['type' => 'html', 'data' => 'no data'];

        $client = new YopClient($this->channel['appkey'], $this->channel['appsecret']);
        try {
            $data = $client->notifyDecrypt($response);
        } catch (Exception $e) {
            return ['type' => 'html', 'data' => $e->getMessage()];
        }

        if ($data) {
            $out_trade_no = $data['orderId'];
            $api_trade_no = $data['uniqueOrderNo'];
            $total_amount = $data['orderAmount'];
            $payerInfo = json_decode($data['payerInfo'], true);
            $buyer = $payerInfo['userID'] ?? '';
            $bill_trade_no = $data['channelTrxId'] ?? '';
            $bill_mch_trade_no = $data['bankOrderId'] ?? '';
            $end_time = $data['paySuccessDate'];

            if ($data['status'] == 'SUCCESS') {
                if ($out_trade_no == $ctx->order['trade_no'] && round((float) $total_amount, 2) == round((float) $ctx->order['realmoney'], 2)) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'FAIL'];
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
            'parentMerchantNo' => $this->channel['appid'],
            'merchantNo' => empty($this->channel['appmchid']) ? $this->channel['appid'] : $this->channel['appmchid'],
            'orderId' => $order['trade_no'],
        ];
        $client = new YopClient($this->channel['appkey'], $this->channel['appsecret']);
        $result = $client->get('/rest/v1.0/trade/order/query', $params);
        if ($result['code'] == 'OPR00000') {
            return [
                'api_trade_no' => $result['uniqueOrderNo'],
                'status' => $result['status'] == 'SUCCESS' ? 1 : 0,
                'money' => $result['orderAmount'] / 100,
                'buyer' => $result['payerInfo']['userID'] ?? '',
                'bill_trade_no' => $result['channelTrxId'] ?? '',
                'bill_mch_trade_no' => $result['bankOrderId'] ?? '',
                'endtime' => $result['paySuccessDate'] ?? '',
            ];
        } else {
            throw new Exception('[' . $result['code'] . ']' . $result['message']);
        }
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'parentMerchantNo' => $this->channel['appid'],
            'merchantNo' => empty($this->channel['appmchid']) ? $this->channel['appid'] : $this->channel['appmchid'],
            'orderId' => $order['trade_no'],
            'refundRequestId' => $order['refund_no'] ?? $order['trade_no'],
            'refundAmount' => $order['refundmoney']
        ];

        $client = new YopClient($this->channel['appkey'], $this->channel['appsecret']);

        $result = $client->post('/rest/v1.0/trade/refund', $params);

        if ($result['code'] == 'OPR00000') {
            return ['code' => 0, 'trade_no' => $result['uniqueRefundNo'], 'refund_fee' => $result['refundAmount']];
        } else {
            return ['code' => -1, 'msg' => '[' . $result['code'] . ']' . $result['message']];
        }
    }

    //进件异步回调
    public function applynotify(PaymentContext $ctx): array
    {
        $response = request()->post('response');
        if (!$response) return ['type' => 'html', 'data' => 'no data'];

        $client = new YopClient($this->channel['appkey'], $this->channel['appsecret']);
        try {
            $data = $client->notifyDecrypt($response);
        } catch (Exception $e) {
            return ['type' => 'html', 'data' => $e->getMessage()];
        }

        if ($data) {
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->notify($data);

            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'FAIL'];
        }
    }

    //投诉通知
    public function complainnotify(PaymentContext $ctx): array
    {
        $response = request()->post('response');
        if (!$response) return ['type' => 'html', 'data' => 'no data'];

        $client = new YopClient($this->channel['appkey'], $this->channel['appsecret']);
        try {
            $data = $client->notifyDecrypt($response);
        } catch (Exception $e) {
            return ['type' => 'html', 'data' => $e->getMessage()];
        }

        if ($data) {
            $model = \app\logic\ComplainLogic::getModel($this->channel);
            if ($model) $model->refreshNewInfo($data['complaintNo'], $data['actionType']);

            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'FAIL'];
        }
    }

    //分账回调
    public function dividenotify(PaymentContext $ctx): array
    {
        $response = request()->post('response');
        if (!$response) return ['type' => 'html', 'data' => 'no data'];

        $client = new YopClient($this->channel['appkey'], $this->channel['appsecret']);
        try {
            $data = $client->notifyDecrypt($response);
        } catch (Exception $e) {
            return ['type' => 'html', 'data' => $e->getMessage()];
        }

        if ($data) {
            $divide_trade_no = $data['divideRequestId'];
            $out_trade_no = $data['orderId'];
            $status = $data['divideStatus'];
            if ($status == 'SUCCESS') {
                $this->processProfitSharing($out_trade_no, 2, null, $divide_trade_no);
            } elseif ($status == 'FAIL') {
                $this->processProfitSharing($out_trade_no, 3, $data['failReason']);
            }

            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'FAIL'];
        }
    }
}
