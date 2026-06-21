<?php

declare(strict_types=1);

namespace plugins\payment\sandpay;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class SandpayPlugin extends BasePayment
{
    private function createClient(): SandpayClient
    {
        return new SandpayClient(
            $this->channel['appid'],
            $this->channel['appkey'],
            (int) $this->channel['appswitch'],
            $this->channel['appswitch'] == 1 ? $this->payRoot . 'cert/sand_uat.cer' : $this->payRoot . 'cert/sand.cer',
            getCertFilePath($this->channel['merchant_private_cert'])
        );
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
            if (in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/fastpay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
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
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $this->channel['appwxa'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            if (in_array('2', $this->channel['apptype'])) {
                return $this->fastpay($ctx);
            } else {
                return $this->bank($ctx);
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //统一下单
    private function addOrder(PaymentContext $ctx, string $pay_type, string $pay_mode, ?string $sub_openid = null, ?string $sub_appid = null): mixed
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $clientip = request()->clientip;

        $client = $this->createClient();
        $params = [
            'marketProduct' => $this->channel['product'],
            'outReqTime' => date('YmdHis'),
            'mid' => $this->channel['appid'],
            'outOrderNo' => $tradeNo,
            'description' => $ctx->ordername,
            'goodsClass' => '01',
            'amount' => $ctx->order['realmoney'],
            'payType' => $pay_type,
            'payMode' => $pay_mode,
            'payerInfo' => [
                'payAccLimit' => '',
            ],
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'riskmgtInfo' => [
                'sourceIp' => $clientip,
            ],
        ];
        if ($sub_openid && $sub_appid) {
            $params['payerInfo'] = [
                'subAppId' => $sub_appid,
                'subUserId' => $sub_openid,
                'frontUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            ];
        } elseif ($sub_openid) {
            $params['payerInfo'] = [
                'userId' => $sub_openid,
                'frontUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            ];
        }

        $channel = $this->channel;
        return self::lockPayData($tradeNo, function () use ($client, $params, $channel, $tradeNo) {
            $result = $client->execute('/v4/sd-receipts/api/trans/trans.order.create', $params);
            $this->updateOrder($tradeNo, $result['sandSerialNo']);
            if ($channel['appswitch'] == 1) {
                $log = "商户订单号：" . $tradeNo . "\r\n【统一下单接口】请求报文：\r\n" . $client->request_body . "\r\n【统一下单接口】响应报文：\r\n" . $client->response_body . "\r\n\r\n";
                file_put_contents($this->payRoot . 'logs/' . date('Ymd') . '.log', $log, FILE_APPEND);
            }
            return $result['credential'];
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
                $result = $this->addOrder($ctx, 'ALIPAY', 'QR');
                $code_url = $result['qrCode'];
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
            $user_type = null;
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }

        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;
        if ($user_type == 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $result = $this->addOrder($ctx, 'ALIPAY', 'JSAPI', $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['tradeNo']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['tradeNo'], 'redirect_url' => $redirect_url]];
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
                $result = $this->addOrder($ctx, 'WXPAY', 'QR');
                $code_url = $result['qrCode'];
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

    //微信公众号
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
            $payinfo = $this->addOrder($ctx, 'WXPAY', $ctx->order['is_applet'] == 1 ? 'MINI' : 'JSAPI', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
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
            $payinfo = $this->addOrder($ctx, 'WXPAY', 'MINI', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($payinfo, true)]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
        try {
            $code_url = wxminipay_jump_scheme($ctx->order, $tradeNo);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, 'CUPPAY', 'QR');
            $code_url = $result['qrCode'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //快捷支付
    public function fastpay(PaymentContext $ctx): array
    {
        $user_id = cookie('sandpay_user_id');
        if (empty($user_id)) {
            $user_id = substr(getSid(), 0, 10);
            cookie('sandpay_user_id', $user_id, ['expire' => 3600 * 24 * 365, 'path' => '/']);
        }
        try {
            $result = $this->addOrder($ctx, 'FASTPAY', 'SANDH5', $user_id);
            $jump_url = $result['cashierUrl'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'jump', 'url' => $jump_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $sign   = request()->post('sign');
        $data   = request()->post('bizData');

        $client = $this->createClient();
        $verifyFlag = $client->verify($data, $sign);

        if ($verifyFlag) {
            $array = json_decode($data, true);

            if ($this->channel['appswitch'] == 1) {
                $params = [
                    'outReqTime' => date('YmdHis'),
                    'mid' => $this->channel['appid'],
                    'outOrderNo' => $array['outOrderNo'],
                ];
                try {
                    $client->execute('/v4/sd-receipts/api/trans/trans.order.query', $params);
                } catch (Exception $e) {
                    //return ['type'=>'error','msg'=>'订单查询失败 '.$ex->getMessage()];
                }
                $log = "商户订单号：" . $tradeNo . "\r\n异步通知：\r\n" . $data . "\r\n【订单查询接口】请求报文：\r\n" . $client->request_body . "\r\n【订单查询接口】响应报文：\r\n" . $client->response_body . "\r\n\r\n";
                file_put_contents($this->payRoot . 'logs/' . date('Ymd') . '.log', $log, FILE_APPEND);
            }

            if ($array['orderStatus'] == 'success') {
                $out_trade_no = $array['outOrderNo'];
                $trade_no = $array['sandSerialNo'];
                $money = $array['amount'];
                $buyer = $array['payer']['payerAccNo'] ?? '';
                $bill_trade_no = $array['channelOrderNo'] ?? '';
                $bill_mch_trade_no = $array['channelSerialNo'] ?? '';
                $end_time = $array['finishedTime'];
                if ($out_trade_no == $tradeNo) {
                    ($this->markTrustedCallback($ctx, 'notify', 'sandpay-signature'))(function () use ($ctx, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time) {
                        $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                    });
                }
                return ['type' => 'html', 'data' => 'respCode=000000'];
            }
        }
        return ['type' => 'html', 'data' => 'respCode=020002'];
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
    }

    public function query(array $order): array
    {
        $params = [
            'marketProduct' => $this->channel['product'],
            'outReqTime' => date('YmdHis'),
            'mid' => $this->channel['appid'],
            'outOrderNo' => $order['trade_no'],
        ];
        $client = $this->createClient();
        $result = $client->execute('/v4/sd-receipts/api/trans/trans.order.query', $params);
        if ($this->channel['appswitch'] == 1) {
            $log = "商户订单号：" . $order['trade_no'] . "\r\n【订单查询接口】请求报文：\r\n" . $client->request_body . "\r\n【订单查询接口】响应报文：\r\n" . $client->response_body . "\r\n\r\n";
            file_put_contents($this->payRoot . 'logs/' . date('Ymd') . '.log', $log, FILE_APPEND);
        }
        return [
            'api_trade_no' => $result['sandSerialNo'],
            'status' => $result['orderStatus'] == 'success' ? 1 : 0,
            'money' => $result['amount'],
            'buyer' => $result['payer']['payerAccNo'] ?? '',
            'bill_trade_no' => $result['channelOrderNo'] ?? '',
            'bill_mch_trade_no' => $result['channelSerialNo'] ?? '',
            'endtime' => $result['finishedTime'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'marketProduct' => $this->channel['product'],
            'outReqTime' => date('YmdHis'),
            'mid' => $this->channel['appid'],
            'outOrderNo' => $order['refund_no'],
            'oriOutOrderNo' => $order['trade_no'],
            'amount' => $order['refundmoney'],
            'notifyUrl' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
        ];
        try {
            $client = $this->createClient();
            $result = $client->execute('/v4/sd-receipts/api/trans/trans.order.refund', $params);
            if ($this->channel['appswitch'] == 1) {
                $log = "商户订单号：" . $order['trade_no'] . "\r\n【退货接口】请求报文：\r\n" . $client->request_body . "\r\n【退货接口】响应报文：\r\n" . $client->response_body . "\r\n\r\n";
                file_put_contents($this->payRoot . 'logs/' . date('Ymd') . '.log', $log, FILE_APPEND);
            }
            return ['code' => 0, 'trade_no' => $result['sandSerialNo'], 'refund_fee' => $result['amount']];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //退款回调
    public function refundnotify(PaymentContext $ctx): array
    {
        $sign   = request()->post('sign');
        $data   = request()->post('bizData');

        $client = $this->createClient();
        $verifyFlag = $client->verify($data, $sign);

        if ($verifyFlag) {
            $array = json_decode($data, true);
            if ($array['orderStatus'] == 'success') {
                $out_trade_no = $array['outOrderNo'];
                $trade_no = $array['sandSerialNo'];
                $money = $array['amount'];
                ($this->markTrustedCallback($ctx, 'refundnotify', 'sandpay-signature'))(function () use ($out_trade_no, $trade_no, $money) {
                    $this->processRefund($out_trade_no, 1, '', $trade_no, $money);
                });
                return ['type' => 'html', 'data' => 'respCode=000000'];
            }
        }
        return ['type' => 'html', 'data' => 'respCode=020002'];
    }

    //转账
    public function transfer(array $bizParam): array
    {
        $params = [
            'mid' => $this->channel['appid'],
            'outOrderNo' => $bizParam['out_biz_no'],
            'amount' => $bizParam['money'],
            'payeeInfo' => [
                'accType' => 'cup',
                'accNo' => $bizParam['payee_account'],
                'accName' => $bizParam['payee_real_name'],
            ],
            'payerInfo' => [
                'sdaccSubId' => 'payment',
                'remark' => $bizParam['transfer_desc'],
            ],
        ];

        try {
            $client = $this->createClient();
            $result = $client->execute('/v4/sd-payment/api/trans/trans.payment.order.create', $params);
            $status = $result['paymentStatus'] == 'success' ? 1 : 0;
            if ($this->channel['appswitch'] == 1) {
                $log = "商户订单号：" . $bizParam['out_biz_no'] . "\r\n【付款接口】请求报文：\r\n" . $client->request_body . "\r\n【付款接口】响应报文：\r\n" . $client->response_body . "\r\n\r\n";
                file_put_contents($this->payRoot . 'logs/' . date('Ymd') . '.log', $log, FILE_APPEND);
            }
            return ['code' => 0, 'status' => $status, 'orderid' => $result['sandSerialNo'], 'paydate' => $result['finishedTime']];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账查询
    public function transfer_query(array $bizParam): array
    {
        $params = [
            'mid' => $this->channel['appid'],
            'outReqDate' => substr($bizParam['out_biz_no'], 0, 8),
            'outOrderNo' => $bizParam['out_biz_no'],
        ];
        try {
            $client = $this->createClient();
            $result = $client->execute('/v4/sd-payment/api/trans/trans.payment.order.query', $params);
            $status = $result['orderStatus'] == 'success' ? 1 : 0;
            if ($this->channel['appswitch'] == 1) {
                $log = "商户订单号：" . $bizParam['out_biz_no'] . "\r\n【付款订单查询接口】请求报文：\r\n" . $client->request_body . "\r\n【付款订单查询接口】响应报文：\r\n" . $client->response_body . "\r\n\r\n";
                file_put_contents($this->payRoot . 'logs/' . date('Ymd') . '.log', $log, FILE_APPEND);
            }
            return ['code' => 0, 'status' => $status];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //余额查询
    public function balance_query(array $bizParam): array
    {
        $params = [
            'mid' => $this->channel['appid'],
            'sdaccSubId' => 'payment',
        ];
        try {
            $client = $this->createClient();
            $result = $client->execute('/v4/sd-payment/api/trans/trans.payment.balance.query', $params);
            $account = $result['accountList'][0];
            if ($this->channel['appswitch'] == 1) {
                $log = "商户订单号：" . ($bizParam['out_biz_no'] ?? '') . "\r\n【余额查询接口】请求报文：\r\n" . $client->request_body . "\r\n【余额查询接口】响应报文：\r\n" . $client->response_body . "\r\n\r\n";
                file_put_contents($this->payRoot . 'logs/' . date('Ymd') . '.log', $log, FILE_APPEND);
            }
            if (empty($account)) return ['code' => -1, 'msg' => '未查询到账户信息'];
            return ['code' => 0, 'amount' => $account['availableBal'], 'msg' => '当前账户可用余额：' . $account['availableBal'] . ' 元，冻结金额：' . $account['frozenBal'] . '，在途余额：' . $account['transitBal']];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }
}
