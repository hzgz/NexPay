<?php

declare(strict_types=1);

namespace plugins\payment\yseqt;

use app\common\PaymentContext;
use app\common\BasePayment;
use think\facade\Db;

class YseqtPlugin extends BasePayment
{
    private function getClient(): YseqtClient
    {
        $certFilePath = getCertFilePath($this->channel['cert_pfx'] ?? '');
        return new YseqtClient($this->channel['appid'], $this->channel['appkey'], $certFilePath);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $apptype)) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            } elseif ($ctx->isMobile && (in_array('1', $apptype) || in_array('2', $apptype))) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
        }
        return ['type' => 'jump', 'url' => '/pay/' . $ctx->order['typename'] . '/' . $tradeNo . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            } elseif ($ctx->order['typename'] == 'bank') {
                return $this->bankjs($ctx);
            }
        } elseif ($ctx->method == 'applet') {
            return $this->wxapppay($ctx);
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $apptype)) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return $this->wxpay($ctx);
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

    //正扫支付
    private function scanPay(string $bank_type, PaymentContext $ctx): string
    {
        $params = [
            'requestNo' => $ctx->order['trade_no'],
            'payeeMerchantNo' => $this->channel['appmchid'],
            'orderDesc' => $ctx->ordername,
            'amount' => $ctx->order['realmoney'],
            'bankType' => $bank_type,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/',
        ];
        if ($ctx->order['profits'] > 0) {
            $params['isDivision'] = 'Y';
        }

        $client = $this->getClient();
        return self::lockPayData($ctx->order['trade_no'], function () use ($client, $params) {
            $result = $client->execute('scanPay', $params);
            if ($result['subCode'] == 'COM000') {
                return $result['qrCode'];
            } else {
                throw new \Exception($result['subMsg']);
            }
        });
    }

    //聚合收银台支付
    private function cashierPay(string $pay_mode, PaymentContext $ctx): string
    {
        $params = [
            'requestNo' => $ctx->order['trade_no'],
            'payeeMerchantNo' => $this->channel['appmchid'],
            'orderDesc' => $ctx->ordername,
            'amount' => $ctx->order['realmoney'],
            'payMode' => $pay_mode,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/',
            'isFastPay' => 'Y'
        ];
        if ($ctx->order['profits'] > 0) {
            $params['isDivision'] = 'Y';
        }

        $client = $this->getClient();
        return self::lockPayData($ctx->order['trade_no'], function () use ($client, $params) {
            $result = $client->execute('cashierPay', $params);
            if ($result['subCode'] == 'COM000') {
                return $result['payData'];
            } else {
                throw new \Exception($result['subMsg']);
            }
        });
    }

    //聚合JS支付
    private function jsPay(string $bankType, string $payMode, PaymentContext $ctx, ?string $openid = null, ?string $appid = null): string
    {
        $params = [
            'requestNo' => $ctx->order['trade_no'],
            'payeeMerchantNo' => $this->channel['appmchid'],
            'orderDesc' => $ctx->ordername,
            'amount' => $ctx->order['realmoney'],
            'bankType' => $bankType,
            'payMode' => $payMode,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/',
        ];
        if ($payMode == '28' || $payMode == '29') {
            $params['wxAppId'] = $appid;
            $params['wxOpenId'] = $openid;
        } elseif ($payMode == '26') {
            $params['alipayId'] = $openid;
        } elseif ($payMode == '30') {
            $params['unionUserId'] = $openid;
        }
        if ($ctx->order['profits'] > 0) {
            $params['isDivision'] = 'Y';
        }

        $client = $this->getClient();
        return self::lockPayData($ctx->order['trade_no'], function () use ($client, $params) {
            $result = $client->execute('jsPay', $params);
            if ($result['subCode'] == 'COM000' || $result['subCode'] == 'COM004') {
                return $result['payData'];
            } else {
                throw new \Exception($result['subMsg']);
            }
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $apptype = $this->channel['apptype'] ?? [];
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('1', $apptype)) {
            try {
                $code_url = $this->scanPay('1903000', $ctx);
            } catch (\Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
        } else {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
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
            $paydata = $this->jsPay('1903000', '26', $ctx, $user_id);
            $trade_no = json_decode($paydata, true)['tradeNO'];
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $trade_no];
        }

        if (request()->get('d') === '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $trade_no, 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $apptype = $this->channel['apptype'] ?? [];
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $apptype)) {
            try {
                $code_url = $this->cashierPay('29h5', $ctx);
            } catch (\Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('1', $apptype)) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } else {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
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

        if (in_array('2', $apptype)) {
            try {
                $code_url = $this->cashierPay('29UrlScheme', $ctx);
            } catch (\Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } elseif (in_array('1', $apptype)) {
            try {
                $code_url = $this->cashierPay('28', $ctx);
            } catch (\Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $code_url];
            } else {
                return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
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
            $paydata = $this->jsPay('1902000', $ctx->order['is_applet'] == 1 ? '29' : '28', $ctx, $openid, $wxinfo['appid']);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $paydata];
        }

        if (request()->get('d') === '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $paydata, 'redirect_url' => $redirect_url]];
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
        } catch (\Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        //②、统一下单
        try {
            $paydata = $this->jsPay('1902000', '29', $ctx, $openid, $wxinfo['appid']);
        } catch (\Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }
        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($paydata, true)]];
    }

    //微信APP支付
    public function wxapppay(PaymentContext $ctx): array
    {
        try {
            $paydata = $this->cashierPay('29', $ctx);
        } catch (\Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => 'wx05e71f6f41f6b9b4', 'miniProgramId' => 'gh_d27d42772cd8', 'path' => 'pages/index/index', 'extraData' => json_decode($paydata, true)]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->scanPay('9001002', $ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'unionpay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
        }
    }

    public function bankjs(PaymentContext $ctx): array
    {
        try {
            $paydata = $this->jsPay('9001002', '30', $ctx, $ctx->order['sub_openid']);
            $code_url = json_decode($paydata, true)['redirectUrl'];
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    public function get_unionpay_userid(string $userAuthCode): array
    {
        $client = $this->getClient();

        $params = [
            'userAuthCode' => $userAuthCode,
            'appUpIdentifier' => get_unionpay_ua(),
        ];

        try {
            $result = $client->execute('getUpUserId', $params);
            if ($result['subCode'] == 'COM000') {
                return ['code' => 0, 'data' => $result['unionUserId']];
            } else {
                return ['code' => -1, 'msg' => $result['subMsg']];
            }
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        //计算得出通知验证结果
        $client = $this->getClient();
        $verify_result = $client->verify(request()->post());

        if ($verify_result) { //验证成功
            $arr = json_decode(request()->post('bizResponseJson'), true);
            $out_trade_no = $arr['requestNo'];
            $trade_no = $arr['tradeSn'];
            $buyer_id = $arr['openId'] ?? $arr['userId'] ?? '';
            $total_amount = $arr['amount'];
            $bill_trade_no = $arr['channelRecvSn'] ?? '';
            $bill_mch_trade_no = $arr['channelSendSn'] ?? '';
            if ($ctx->order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) {
                $bill_trade_no = substr($bill_trade_no, 2);
            }
            $end_time = $arr['payTime'];

            if ($arr['state'] == 'SUCCESS') {
                if ($out_trade_no == $ctx->order['trade_no'] && round((float)$total_amount, 2) == round((float)$ctx->order['realmoney'], 2)) {
                    ($this->markTrustedCallback($ctx, 'notify', 'yseqt-signature'))(function () use ($ctx, $trade_no, $buyer_id, $bill_trade_no, $bill_mch_trade_no, $end_time) {
                        $this->processNotify($ctx->order, $trade_no, $buyer_id, $bill_trade_no, $bill_mch_trade_no, $end_time);
                    });
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
    }

    public function query(array $order): array
    {
        $client = $this->getClient();
        $params = [
            'requestNo' => $order['trade_no'],  
        ];
        $result = $client->execute('queryUnifyOrder', $params);
        if ($result['subCode'] == 'COM000' || $result['subCode'] == 'COM004') {
            return [
                'api_trade_no' => $result['tradeSn'],
                'status' => $result['state'] == 'SUCCESS' ? 1 : 0,
                'money' => $result['amount'],
                'buyer' => $result['openId'] ?? $result['userId'] ?? '',
                'bill_trade_no' => $result['channelRecvSn'] ?? '',
                'bill_mch_trade_no' => $result['channelSendSn'] ?? '',
                'endtime' => $result['payTime'] ?? '',
            ];
        } else {
            throw new \Exception($result['subMsg']);
        }
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'requestNo' => $order['refund_no'],
            'origRequestNo' => $order['trade_no'],
            'origTradeSn' => $order['api_trade_no'],
            'amount' => $order['refundmoney'],
            'reason' => '申请退款',
            'isDivision' => 'N'
        ];
        if ($order['profits'] > 0) {
            $psorder = \app\logic\ProfitSharingLogic::getOrder($order['trade_no']);
            if ($psorder && ($psorder['status'] == 1 || $psorder['status'] == 2)) {
                $refundSplitInfo = [];
                if ($psorder['rdata']) {
                    $allmoney = 0;
                    $leftmoney = (float)$order['refundmoney'];
                    foreach ($psorder['rdata'] as $receiver) {
                        $money = $receiver['money'] > $leftmoney ? $leftmoney : $receiver['money'];
                        $refundSplitInfo[] = [
                            'refundMercId' => $receiver['account'],
                            'refundAmount' => $money,
                        ];
                        $allmoney += $receiver['money'];
                        $leftmoney = round($leftmoney - $money, 2);
                        if ($leftmoney <= 0) break;
                    }
                    $order_money = (float)$order['realmoney'];
                    if ($order_money > $allmoney && $leftmoney > 0) {
                        $psmoney2 = round($order_money - $allmoney, 2);
                        $psmoney2 = $psmoney2 > $leftmoney ? $leftmoney : $psmoney2;
                        $refundSplitInfo[] = [
                            'refundMercId' => $this->channel['appmchid'],
                            'refundAmount' => $psmoney2,
                        ];
                    }
                } else {
                    $refundSplitInfo[] = [
                        'refundMercId' => $psorder['account'],
                        'refundAmount' => $psorder['money'] > $order['refundmoney'] ? $order['refundmoney'] : $psorder['money'],
                    ];
                    if ($order['refundmoney'] > $psorder['money']) {
                        $refundSplitInfo[] = [
                            'refundMercId' => $this->channel['appmchid'],
                            'refundAmount' => round((float)$order['refundmoney'] - $psorder['money'], 2),
                        ];
                    }
                }
                $params['isDivision'] = 'Y';
                $params['refundSplitInfo'] = $refundSplitInfo;
            }
        }

        $client = $this->getClient();
        try {
            $result = $client->execute('refund', $params);
            if ($result['subCode'] == 'COM000' || $result['subCode'] == 'COM004') {
                if (isset($psorder) && ($psorder['status'] == 1 || $psorder['status'] == 2)) {
                    Db::name('psorder')->where('id', $psorder['id'])->update(['status' => 4]);
                }
                return ['code' => 0, 'trade_no' => $result['refundSn'], 'refund_fee' => $result['amount']];
            } else {
                $params['requestNo'] = $order['refund_no'] . '1';
                $params['refundSource'] = '01';
                $result = $client->execute('refund', $params);
                if ($result['subCode'] == 'COM000' || $result['subCode'] == 'COM004') {
                    if (isset($psorder) && ($psorder['status'] == 1 || $psorder['status'] == 2)) {
                        Db::name('psorder')->where('id', $psorder['id'])->update(['status' => 4]);
                    }
                    return ['code' => 0, 'trade_no' => $result['refundSn'], 'refund_fee' => $result['amount']];
                } else {
                    return ['code' => -1, 'msg' => $result['subMsg']];
                }
            }
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //进件通知
    public function applynotify(PaymentContext $ctx): array
    {
        //计算得出通知验证结果
        $client = $this->getClient();
        $verify_result = $client->verify(request()->post());

        if ($verify_result) { //验证成功
            $arr = json_decode(request()->post('bizResponseJson'), true);

            if (request()->post('serviceNo') == 'registerNotify') {
                $model = \app\logic\ApplymentLogic::getModel2($this->channel);
                if ($model) $model->notify($arr);
            } elseif (request()->post('serviceNo') == 'contractSignNotify') {
                $model = \app\logic\ApplymentLogic::getModel2($this->channel);
                if ($model) $model->signNotify($arr);
            }

            return ['type' => 'html', 'data' => 'success'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //转账
    public function transfer(array $bizParam): array
    {
        $params = [
            'requestNo' => $bizParam['out_biz_no'],
            'merchantNo' => $this->channel['appmchid'],
            'amount' => $bizParam['money'],
            'orderNote' => $bizParam['transfer_desc'],
            'bankAccountNo' => $bizParam['payee_account'],
            'bankAccountName' => $bizParam['payee_real_name'],
            'notifyUrl' => config_get('localurl') . 'pay/transfernotify/' . $this->channel['id'] . '/',
        ];

        try {
            $client = $this->getClient();
            $result = $client->execute('paymentRequest', $params);
            if ($result['subCode'] == 'COM000') {
                return ['code' => 0, 'status' => 0, 'orderid' => $result['tradeSn'], 'paydate' => date('Y-m-d H:i:s')];
            } else {
                return ['code' => -1, 'msg' => $result['subMsg']];
            }
        } catch (\Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账查询
    public function transfer_query(array $bizParam): array
    {
        $params = [
            'requestNo' => $bizParam['out_biz_no'],
            'tradeDate' => substr($bizParam['paydate'], 0, 8),
        ];

        try {
            $client = $this->getClient();
            $result = $client->execute('paymentQuery', $params);
            if ($result['subCode'] == 'COM000') {
                $errmsg = '';
                if ($result['state'] == 'SUCCESS') {
                    $status = 1;
                } elseif ($result['state'] == 'PROCESSING' || $result['orderStatus'] == 'WAIT_PAY') {
                    $status = 0;
                } else {
                    $status = 2;
                    if ($result['msg']) {
                        $errmsg = $result['msg'];
                    }
                }
                return ['code' => 0, 'status' => $status, 'amount' => $result['amount'], 'paydate' => date('Y-m-d H:i:s', strtotime($result['tradeDate'])), 'errmsg' => $errmsg];
            } else {
                return ['code' => -1, 'msg' => $result['subMsg']];
            }
        } catch (\Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //余额查询
    public function balance_query(array $bizParam): array
    {
        $params = [
            'merchantNo' => $this->channel['appmchid'],
        ];

        try {
            $client = $this->getClient();
            $result = $client->execute('paymentQuery', $params);

            $desc = '账户总金额：' . $result['totalAmount'] . '元';
            $account01 = array_filter($result['accountList'], function ($item) {
                return $item['accountType'] == '01';
            });
            $account01 = $account01[array_key_first($account01)];
            $account02 = array_filter($result['accountList'], function ($item) {
                return $item['accountType'] == '02';
            });
            $account02 = $account02[array_key_first($account02)];
            if (!empty($account01['cashAmount'])) {
                $desc .= '，可提现金额：' . $account01['cashAmount'] . '元';
            }
            if (!empty($account01['uncashAmount']) && $account01['uncashAmount'] > 0) {
                $desc .= '，不可提现金额：' . $account01['uncashAmount'] . '元';
            } elseif (!empty($account02['uncashAmount'])) {
                $desc .= '，不可提现金额：' . $account02['uncashAmount'] . '元';
            }
            if (!empty($result['settledUnpaidAmount'])) {
                $desc .= '，待结算金额：' . $result['settledUnpaidAmount'] . '元';
            }

            return ['code' => 0, 'amount' => $account01['cashAmount'], 'msg' => $desc];
        } catch (\Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //付款异步回调
    public function transfernotify(PaymentContext $ctx): array
    {
        $client = $this->getClient();
        $verify_result = $client->verify(request()->post());

        if ($verify_result) { //验证成功
            $arr = json_decode(request()->post('bizResponseJson'), true);
            $errmsg = null;

            if ($arr['state'] == 'SUCCESS') {
                $status = 1;
            } else {
                $status = 2;
                if ($arr['msg']) {
                    $errmsg = $arr['msg'];
                }
            }
            ($this->markTrustedCallback($ctx, 'transfernotify', 'yseqt-signature'))(function () use ($arr, $status, $errmsg) {
                $this->processTransfer($arr['requestNo'], $status, $errmsg);
            });

            return ['type' => 'html', 'data' => 'success'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'fail'];
        }
    }
}
