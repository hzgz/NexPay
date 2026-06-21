<?php

declare(strict_types=1);

namespace plugins\payment\alipayg;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class AlipaygPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => '/pay/pay/' . $ctx->order['trade_no'] . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        return $this->pay($ctx);
    }

    //收银台支付
    public function pay(PaymentContext $ctx): array
    {
        if (!$this->channel['currency_rate']) $this->channel['currency_rate'] = 1;
        if (!$this->channel['currency_code']) $this->channel['currency_code'] = 'CNY';
        $amount = intval(round($ctx->order['realmoney'] * $this->channel['currency_rate'] * 100));

        $osType = '';
        $terminalType = 'WEB';
        if ($ctx->isMobile) {
            $terminalType = 'WAP';
            $ua = request()->header('user-agent', '');
            if (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) {
                $osType = 'IOS';
            } else {
                $osType = 'ANDROID';
            }
        }

        $tradeNo = $ctx->order['trade_no'];
        $client = new AlipayGlobalClient(
            $this->channel['appswitch'],
            $this->channel['appid'],
            $this->channel['appsecret'],
            $this->channel['appkey']
        );
        $params = [
            'env' => [
                'terminalType' => $terminalType,
                'osType' => $osType,
            ],
            'order' => [
                'orderAmount' => [
                    'currency' => $this->channel['currency_code'],
                    'value' => $amount,
                ],
                'referenceOrderId' => $tradeNo,
                'orderDescription' => $ctx->ordername,
            ],
            'paymentRequestId' => $tradeNo,
            'paymentAmount' => [
                'currency' => $this->channel['currency_code'],
                'value' => $amount,
            ],
            'settlementStrategy' => [
                'settlementCurrency' => $this->channel['currency_code'],
            ],
            'paymentMethod' => [
                'paymentMethodType' => 'ALIPAY_CN',
            ],
            'paymentNotifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'paymentRedirectUrl' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'productCode' => 'CASHIER_PAYMENT',
        ];
        try {
            $result = $client->execute('/v1/payments/pay', $params);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
        }

        if (!empty($result['normalUrl'])) {
            return ['type' => 'jump', 'url' => $result['normalUrl']];
        } else {
            return ['type' => 'error', 'msg' => '支付宝下单失败！未获取到支付链接'];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) {
            return ['type' => 'json', 'data' => ['result' => ['resultCode' => 'FAIL', 'resultStatus' => 'F', 'resultMessage' => 'data error']]];
        }

        $client = new AlipayGlobalClient(
            $this->channel['appswitch'],
            $this->channel['appid'],
            $this->channel['appsecret'],
            $this->channel['appkey']
        );

        $signature = request()->header('signature', '');
        $requestTime = request()->header('request-time', '');
        $path = request()->url();
        $verify_result = $client->check($json, $signature, $requestTime, $path);

        if ($verify_result) { //验证成功
            $out_trade_no = $arr['paymentRequestId'];
            $trade_no = $arr['paymentId'];
            $buyer_id = $arr['pspCustomerInfo']['pspCustomerId'] ?? '';
            $total_amount = $arr['paymentAmount']['value'] ?? 0;
            $end_time = $arr['paymentTime'] ?? '';

            if ($arr['result']['resultStatus'] == 'S') {
                if ($out_trade_no == $ctx->order['trade_no']) {
                    ($this->markTrustedCallback($ctx, 'notify', 'alipay-global-signature'))(function () use ($ctx, $trade_no, $buyer_id, $end_time) {
                        $this->processNotify($ctx->order, $trade_no, $buyer_id, null, null, $end_time);
                    });
                }
            }
            return ['type' => 'json', 'data' => ['result' => ['resultCode' => 'SUCCESS', 'resultStatus' => 'S', 'resultMessage' => 'success']]];
        } else {
            //验证失败
            return ['type' => 'json', 'data' => ['result' => ['resultCode' => 'FAIL', 'resultStatus' => 'F', 'resultMessage' => 'sign error']]];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $client = new AlipayGlobalClient(
            $this->channel['appswitch'],
            $this->channel['appid'],
            $this->channel['appsecret'],
            $this->channel['appkey']
        );
        $params = [
            'paymentRequestId' => $order['trade_no'],
        ];
        $result = $client->execute('/v1/payments/inquiryPayment', $params);
        return [
            'api_trade_no' => $result['paymentId'],
            'status' => $result['paymentStatus'] == 'SUCCESS' ? 1 : 0,
            'money' => round($result['paymentAmount']['value'] / 100 * $this->channel['currency_rate'], 2),
            'buyer' => $result['pspCustomerInfo']['pspCustomerId'] ?? '',
            'endtime' => $result['paymentTime'] ?? '',
        ];
    }

    //退款
    public function refund($order): array
    {
        if (!$this->channel['currency_rate']) $this->channel['currency_rate'] = 1;
        if (!$this->channel['currency_code']) $this->channel['currency_code'] = 'CNY';
        $amount = intval(round($order['refundmoney'] * $this->channel['currency_rate'] * 100));

        $client = new AlipayGlobalClient(
            $this->channel['appswitch'],
            $this->channel['appid'],
            $this->channel['appsecret'],
            $this->channel['appkey']
        );
        $params = [
            'refundRequestId' => $order['refund_no'],
            'paymentId' => $order['api_trade_no'],
            'refundAmount' => [
                'currency' => $this->channel['currency_code'],
                'value' => $amount,
            ],
        ];
        try {
            $result = $client->execute('/v1/payments/refund', $params);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
        return ['code' => 0, 'trade_no' => $result['refundId'], 'refund_fee' => $result['refundAmount']['value']];
    }

    //关闭订单
    public function close($order): array
    {
        $client = new AlipayGlobalClient(
            $this->channel['appswitch'],
            $this->channel['appid'],
            $this->channel['appsecret'],
            $this->channel['appkey']
        );
        $params = [
            'paymentRequestId' => $order['trade_no'],
        ];
        try {
            $client->execute('/v1/payments/cancel', $params);
            return ['code' => 0];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }
}
