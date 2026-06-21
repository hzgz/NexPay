<?php

declare(strict_types=1);

namespace plugins\payment\paypal;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class PaypalPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (!$this->channel['currency_rate']) $this->channel['currency_rate'] = 1;
        $money = round($ctx->order['realmoney'] * $this->channel['currency_rate'], 2);

        $parameter = [
            'intent'            => 'CAPTURE',
            'purchase_units'    => [
                [
                    'amount'        => [
                        'currency_code' => $this->channel['currency_code'],
                        'value'         => $money,
                    ],
                    'description'   => $ctx->order['name'],
                    'custom_id'     => $tradeNo,
                    'invoice_id'    => $tradeNo,
                ],
            ],
            'application_context' => [
                'cancel_url'    => $siteurl . 'pay/cancel/' . $tradeNo . '/',
                'return_url'    => $siteurl . 'pay/return/' . $tradeNo . '/',
            ],
        ];

        try {
            $approvalUrl = self::lockPayData($tradeNo, function () use ($parameter) {
                $client = new PayPalClient($this->channel['appid'], $this->channel['appkey'], (int) $this->channel['appswitch']);
                $result = $client->createOrder($parameter);

                $approvalUrl = null;
                foreach ($result['links'] as $link) {
                    if ($link['rel'] == 'approve') {
                        $approvalUrl = $link['href'];
                    }
                }
                if (empty($approvalUrl)) {
                    throw new Exception('获取支付链接失败');
                }
                return $approvalUrl;
            });

            return ['type' => 'jump', 'url' => $approvalUrl];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => 'PayPal下单失败：' . $ex->getMessage()];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (request()->get('token') && request()->get('PayerID')) {

            $token = request()->get('token');
            try {
                $client = new PayPalClient($this->channel['appid'], $this->channel['appkey'], (int) $this->channel['appswitch']);
                $result = $client->captureOrder($token);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付订单失败 ' . $ex->getMessage()];
            }

            $captures = $result['purchase_units'][0]['payments']['captures'][0];
            $amount = $captures['seller_receivable_breakdown']['gross_amount']['value'];
            $trade_no = $captures['id'];
            $out_trade_no = $captures['invoice_id'];
            $buyer = $result['payer']['email_address'];

            if ($out_trade_no == $tradeNo) {
                return ($this->markTrustedCallback($ctx, 'return', 'paypal-capture-order'))(function () use ($ctx, $trade_no, $buyer) {
                    return $this->processReturn($ctx->order, $trade_no, $buyer);
                });
            } else {
                return ['type' => 'error', 'msg' => '订单信息校验失败'];
            }
        } else {
            return ['type' => 'error', 'msg' => 'PayPal返回参数错误'];
        }
    }

    public function cancel(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'error'];
    }

    public function webhook(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr || empty($arr['event_type'])) {
            return ['type' => 'html', 'data' => '事件类型为空'];
        }
        if (!in_array($arr['event_type'], ['PAYMENT.CAPTURE.COMPLETED'])) {
            return ['type' => 'html', 'data' => '其他事件(' . $arr['event_type'] . ':' . $arr['summary'] . ')'];
        }
        if (empty($this->channel['appsecret'])) {
            return ['type' => 'html', 'data' => '未配置webhookid'];
        }

        $crc32 = crc32($json);
        $transmissionId = request()->header('paypal-transmission-id', '');
        $transmissionTime = request()->header('paypal-transmission-time', '');
        $certUrl = request()->header('paypal-cert-url', '');
        $transmissionSig = request()->header('paypal-transmission-sig', '');

        if (empty($transmissionId) || empty($transmissionTime) || empty($crc32)) {
            return ['type' => 'html', 'data' => '签名数据为空'];
        }
        $sign_string = $transmissionId . '|' . $transmissionTime . '|' . $this->channel['appsecret'] . '|' . $crc32;

        // 通过PAYPAL-CERT-URL头信息去拿公钥
        $public_key = openssl_pkey_get_public(get_curl($certUrl));
        $details = openssl_pkey_get_details($public_key);
        $verify = openssl_verify($sign_string, base64_decode($transmissionSig), $details['key'], 'SHA256');
        if ($verify != 1) {
            return ['type' => 'html', 'data' => '签名验证失败'];
        }

        $resource = $arr['resource'];
        $amount = $resource['amount']['value'];
        $trade_no = $resource['id'];
        $out_trade_no = $resource['invoice_id'];

        if ($out_trade_no == $ctx->order['trade_no']) {
            ($this->markTrustedCallback($ctx, 'notify', 'paypal-webhook-signature'))(function () use ($ctx, $trade_no) {
                $this->processNotify($ctx->order, $trade_no);
            });
        }
        return ['type' => 'html', 'data' => 'success'];
    }

    public function query(array $order): array
    {
        if (empty($order['api_trade_no'])) throw new \Exception('接口订单号不能为空');
        $client = new PayPalClient($this->channel['appid'], $this->channel['appkey'], (int) $this->channel['appswitch']);
        $result = $client->orderDetail($order['api_trade_no']);
        return [
            'api_trade_no' => $result['id'],
            'status' => $result['status'] == 'COMPLETED' ? 1 : 0,
            'buyer' => $result['payer']['email_address'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        if (!$this->channel['currency_rate']) $this->channel['currency_rate'] = 1;
        $money = round($order['refundmoney'] * $this->channel['currency_rate'], 2);

        $parameter = [
            'amount'    => [
                'currency_code'  => $this->channel['currency_code'],
                'value'     => $money,
            ],
        ];

        try {
            $client = new PayPalClient($this->channel['appid'], $this->channel['appkey'], (int) $this->channel['appswitch']);
            $res = $client->refundPayment($order['api_trade_no'], $parameter);
            return ['code' => 0, 'trade_no' => $res['id']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }
}
