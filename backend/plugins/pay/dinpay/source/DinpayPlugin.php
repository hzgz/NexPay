<?php

declare(strict_types=1);

namespace plugins\payment\dinpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use think\facade\Db;
use Exception;

class DinpayPlugin extends BasePayment
{
    private function createClient(): DinpayClient
    {
        if (!empty($this->channel['appmchid']) && substr($this->channel['appmchid'], 0, 1) != '[') $this->channel['appid'] = $this->channel['appmchid'];
        return new DinpayClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('3', $this->channel['apptype']) && !in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && ($this->channel['appwxa'] > 0 || in_array('2', $this->channel['apptype']))) {
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
            if ($ctx->mdevice == 'alipay' && in_array('3', $this->channel['apptype']) && !in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice == 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && ($this->channel['appwxa'] > 0 || in_array('2', $this->channel['apptype']))) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    private function getReportId($reportid, $order)
    {
        $tradeNo = $order['trade_no'];
        if (!empty($order['param']) && is_numeric($order['param'])) {
            return $order['param'];
        }
        if (strpos($reportid, ',')) {
            $reportids = explode(',', $reportid);
            $reportid = $reportids[array_rand($reportids)];
        }
        Db::name('order')->where('trade_no', $tradeNo)->update(['param' => $reportid]);
        return $reportid;
    }

    //扫码支付
    private function qrcode(PaymentContext $ctx, $paytype)
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'interfaceName' => 'AppPay',
            'paymentType' => $paytype,
            'paymentMethods' => 'SCAN',
            'paymentCode' => '1',
            'payAmount' => $ctx->order['realmoney'],
            'currency' => 'CNY',
            'orderNo' => $tradeNo,
            'orderIp' => request()->clientip,
            'goodsName' => $ctx->ordername,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx);
        }
        if (!empty($this->channel['reportid'])) $params['reportId'] = $this->getReportId($this->channel['reportid'], $ctx->order);

        $client = $this->createClient();
        $result = $client->execute('/api/appPay/pay', $params);
        return $result['qrcode'];
    }

    //公众号支付
    private function publicpay(PaymentContext $ctx, $paytype, $openid, $appid)
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'interfaceName' => 'AppPayPublic',
            'paymentType' => $paytype,
            'paymentMethods' => 'PUBLIC',
            'appid' => $appid,
            'openid' => $openid,
            'payAmount' => $ctx->order['realmoney'],
            'currency' => 'CNY',
            'orderNo' => $tradeNo,
            'orderIp' => request()->clientip,
            'goodsName' => $ctx->ordername,
            'isNative' => '1',
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'successToUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx);
        }
        if (!empty($this->channel['reportid'])) $params['reportId'] = $this->getReportId($this->channel['reportid'], $ctx->order);

        $client = $this->createClient();
        $result = $client->execute('/api/appPay/payPublic', $params);
        return $result['payInfo'];
    }

    //小程序支付
    private function appletpay(PaymentContext $ctx, $paytype, $openid, $appid)
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'interfaceName' => 'AppPayApplet',
            'paymentType' => $paytype,
            'paymentMethods' => 'APPLET',
            'appid' => $appid,
            'openid' => $openid,
            'payAmount' => $ctx->order['realmoney'],
            'currency' => 'CNY',
            'orderNo' => $tradeNo,
            'orderIp' => request()->clientip,
            'goodsName' => $ctx->ordername,
            'isNative' => '1',
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            //'successToUrl' => $siteurl.'pay/return/'.$tradeNo.'/',
        ];
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx);
        }
        if (!empty($this->channel['reportid'])) $params['reportId'] = $this->getReportId($this->channel['reportid'], $ctx->order);

        $client = $this->createClient();
        $result = $client->execute('/api/appPay/payApplet', $params);
        return $result['payInfo'];
    }

    //H5支付
    private function h5pay(PaymentContext $ctx, $paytype)
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'interfaceName' => 'AppPayH5WFT',
            'paymentType' => $paytype,
            'paymentMethods' => 'WAP',
            'payAmount' => $ctx->order['realmoney'],
            'currency' => 'CNY',
            'orderNo' => $tradeNo,
            'orderIp' => request()->clientip,
            'applyName' => config_get('sitename'),
            'applyType' => 'AND_WAP',
            'applyId' => $siteurl,
            'isNative' => '0',
            'goodsName' => $ctx->ordername,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'successToUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx);
        }
        if (!empty($this->channel['reportid'])) $params['reportId'] = $this->getReportId($this->channel['reportid'], $ctx->order);

        $client = $this->createClient();
        $result = $client->execute('/api/appPay/payH5', $params);
        return $result['payInfo'];
    }

    private function handleProfits(&$params, PaymentContext $ctx)
    {
        $order = $ctx->order;
        $tradeNo = $order['trade_no'];
        $psreceiver = \app\logic\ProfitSharingLogic::getReceiver($order['profits']);
        if ($psreceiver) {
            $splitRules = [];
            $i = 1;
            foreach ($psreceiver['info'] as $receiver) {
                $psmoney = round(floor($order['realmoney'] * $receiver['rate']) / 100, 2);
                $splitRules[] = [
                    'splitBillMerchantNo' => $receiver['account'],
                    'splitBillAmount' => $psmoney,
                    'splitBillRequestNo' => $tradeNo . $i++,
                ];
            }
            $params['splitType'] = 'FIXED_AMOUNT';
            $params['splitRules'] = json_encode($splitRules);
        }
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype']) || empty($this->channel['apptype'][0])) {
            try {
                $code_url = $this->qrcode($ctx, 'ALIPAY');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('2', $this->channel['apptype'])) {
            if ($ctx->mdevice === 'alipay') {
                try {
                    $code_url = $this->h5pay($ctx, 'ALIPAY');
                } catch (Exception $ex) {
                    return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
                }
            } else {
                $code_url = $siteurl . 'pay/alipay/' . $tradeNo . '/';
            }
        } elseif (in_array('3', $this->channel['apptype'])) {
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
            $payinfo = $this->publicpay($ctx, 'ALIPAY', $user_id, '1');
            $result = json_decode($payinfo, true);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['tradeNO']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['tradeNO'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype'])) {
            try {
                $code_url = $this->qrcode($ctx, 'WXPAY');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('2', $this->channel['apptype'])) {
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
        if (in_array('2', $this->channel['apptype'])) {
            try {
                $code_url = $this->h5pay($ctx, 'WXPAY');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'jump', 'url' => $code_url];
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
            $payinfo = $this->publicpay($ctx, 'WXPAY', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
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
            $payinfo = $this->appletpay($ctx, 'WXPAY', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($payinfo, true)]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, 'UNIONPAY');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'unionpay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        if (!request()->post('data')) return ['type' => 'html', 'data' => 'no data'];
        $order = $ctx->order;

        $client = $this->createClient();
        $verify_result = $client->sm2DoVerifySign(request()->post('data'), request()->post('sign'));

        if ($verify_result) {//验证成功

            $data = json_decode(request()->post('data'), true);
            if ($data['orderStatus'] == 'SUCCESS') {
                $out_trade_no = $data['orderNo'];
                $api_trade_no = $data['channelNumber'];
                $money = (float) $data['payAmount'];
                $buyer = $data['subOpenId'] ?? '';
                $bill_trade_no = $data['outTransactionOrderId'] ?? '';
                $end_time = $data['orderPayDate'];
                if ($order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
                if ($out_trade_no == $order['trade_no'] && round($money, 2) == round((float) $order['realmoney'], 2)) {
                    $this->processNotify($order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
    }

    public function query(array $order): array
    {
        $client = $this->createClient();
        $param = [
            'interfaceName' => 'AppPayQuery',
            'orderNo' => $order['trade_no'],
        ];
        $data = $client->execute('/api/appPay/payQuery', $param);
        $bill_trade_no = $data['outTransactionOrderId'] ?? '';
        if ($order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
        return [
            'api_trade_no' => $data['channelNumber'],
            'status' => $data['orderStatus'] == 'SUCCESS' ? 1 : 0,
            'money' => $data['payAmount'],
            'buyer' => $data['subOpenId'] ?? '',
            'bill_trade_no' => $bill_trade_no,
            'endtime' => $data['orderPayDate'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'interfaceName' => 'AppPayRefund',
            'payOrderNo' => $order['trade_no'],
            'refundOrderNo' => $order['refund_no'],
            'refundAmount' => $order['refundmoney'],
            'notifyUrl' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
        ];
        if ($order['profits'] > 0) {
            $psorder = \app\logic\ProfitSharingLogic::getOrder($order['trade_no']);
            if ($psorder && $psorder['rdata']) {
                $leftmoney = (float)$order['refundmoney'];
                $rules = [];
                $i = 1;
                foreach ($psorder['rdata'] as $receiver) {
                    $money = $receiver['money'] > $leftmoney ? $leftmoney : $receiver['money'];
                    $rules[] = [
                        'merchantNo' => $receiver['account'],
                        'refundAmount' => $money,
                        'splitBillRequestNo' => $order['trade_no'] . $i++,
                    ];
                    $leftmoney -= $money;
                    if ($leftmoney <= 0) break;
                }
                $params['refundSplitRules'] = json_encode($rules);
            }
        }

        try {
            $client = $this->createClient();
            $result = $client->execute('/api/appPay/payRefund', $params);

            return ['code' => 0, 'trade_no' => $result['refundChannelNumber'], 'refund_fee' => $result['refundAmount']];

        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //退款异步回调
    public function refundnotify(PaymentContext $ctx): array
    {
        if (!request()->post('data')) return ['type' => 'html', 'data' => 'no data'];
        $client = $this->createClient();
        $verify_result = $client->sm2DoVerifySign(request()->post('data'), request()->post('sign'));

        if ($verify_result) {//验证成功

            $data = json_decode(request()->post('data'), true);
            $status = ($data['refundStatus'] ?? '') == 'ALL_REFUND' ? 1 : (($data['refundStatus'] ?? '') == 'REFUND_FAIL' ? 2 : 0);
            if ($data['refundStatus'] == 'ALL_REFUND') {
                $out_trade_no = $data['orderNo'];
                $api_trade_no = $data['channelNumber'];
            }
            ($this->markTrustedCallback($ctx, 'refundnotify', 'dinpay-signature'))(function () use ($data, $status) {
                $this->processRefund(
                    $data['orderNo'] ?? '',
                    $status,
                    $status === 2 ? (string)($data['statusIllustrate'] ?? 'dinpay refund failed') : '',
                    $data['channelNumber'] ?? '',
                    $data['refundAmount'] ?? null
                );
            });
            return ['type' => 'html', 'data' => 'sccuess'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //转账异步回调
    public function transfernotify(PaymentContext $ctx): array
    {
        if (!request()->post('data')) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        $verify_result = $client->sm2DoVerifySign(request()->post('data'), request()->post('sign'));

        if ($verify_result) {//验证成功

            $data = json_decode(request()->post('data'), true);
            $orderid = $data['orderNo'];
            $trade_row = Db::name('applytrade')->where('orderid', $orderid)->find();
            $settlementStatusCode = ['MANUAL'=>0,'FAILED'=>2,'DONE'=>1,'DOING'=>0,'INIT'=>0];
            $status = $settlementStatusCode[$data['orderStatus']] ?? 0;
            $errmsg = $status === 2 ? (string)($data['statusIllustrate'] ?? 'dinpay transfer failed') : '';
            if ($trade_row) {
                if ($trade_row['statustext'] != $data['orderStatus']) {
                    Db::name('applytrade')->where('id', $trade_row['id'])->update(['status' => $settlementStatusCode[$data['orderStatus']] ?? 0, 'statustext' => $data['orderStatus'], 'reason' => $data['statusIllustrate'] ?? null, 'endtime' => date('Y-m-d H:i:s')]);
                }
            }
            ($this->markTrustedCallback($ctx, 'transfernotify', 'dinpay-signature'))(function () use ($orderid, $status, $errmsg, $data) {
                $this->processTransfer($orderid, $status, $errmsg, $data['transferVoucher'] ?? $data['channelNumber'] ?? null);
            });
            return ['type' => 'html', 'data' => 'sccuess'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //投诉通知
    public function complainnotify(PaymentContext $ctx): array
    {
        if (!request()->post('data')) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        $verify_result = $client->sm2DoVerifySign(request()->post('data'), request()->post('sign'));

        if ($verify_result) {//验证成功

            $data = json_decode(request()->post('data'), true);
            $thirdid = $data['complaintId'];
            if (substr($this->channel['appmchid'], 0, 1) == '[') {
                $this->channel['appmchid'] = $data['merchantId'];
            }
            if ($data['appPayType'] == 'ALIPAY') {
                $this->channel['type'] = 1;
            } else {
                $this->channel['type'] = 2;
            }
            $model = \app\logic\ComplainLogic::getModel($this->channel);
            $model->refreshNewInfo($thirdid);
            return ['type' => 'html', 'data' => 'sccuess'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }
}
