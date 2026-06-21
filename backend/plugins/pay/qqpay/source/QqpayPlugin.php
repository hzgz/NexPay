<?php

declare(strict_types=1);

namespace plugins\payment\qqpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class QqpayPlugin extends BasePayment
{
    private array $qqpayConfig;

    public function __construct(array $channel)
    {
        parent::__construct($channel);
        $this->qqpayConfig = $this->getConfig();
    }

    private function getConfig(): array
    {
        return [
            //商户号
            'mchid' => $this->channel['appid'],

            //商户API密钥
            'apikey' => $this->channel['appkey'],

            //公众号APPID（可空）
            'appid' => '',

            //操作员账号（仅退款、撤销订单、企业付款时需要）
            //创建操作员说明：https://kf.qq.com/faq/170112AZ7Fzm170112VNz6zE.html
            'op_userid' => $this->channel['appurl'],

            //操作员密码
            'op_userpwd' => $this->channel['appmchid'],

            //商户证书路径（仅退款、撤销订单、企业付款时需要）
            'sslcert_path' => getCertFilePath($this->channel['sslcert_path'] ?? ''),

            //商户证书私钥路径
            'sslkey_path' => getCertFilePath($this->channel['sslkey_path'] ?? ''),
        ];
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->mdevice === 'qq' && in_array('2', $this->channel['apptype'])) {
            return ['type' => 'jump', 'url' => '/pay/jspay/' . $tradeNo . '/'];
        } else {
            return ['type' => 'jump', 'url' => '/pay/qrcode/' . $tradeNo . '/'];
        }
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->method == 'app') {
            return $this->apppay($ctx);
        } elseif ($ctx->method == 'jsapi') {
            return $this->jspay($ctx);
        } elseif ($ctx->method == 'scan') {
            return $this->scanpay($ctx);
        } elseif ($ctx->mdevice === 'qq' && in_array('2', $this->channel['apptype'])) {
            return ['type' => 'jump', 'url' => request()->siteurl . 'pay/jspay/' . $tradeNo . '/'];
        } else {
            return $this->qrcode($ctx);
        }
    }

    //扫码支付
    public function qrcode(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'out_trade_no' => $tradeNo,
            'body' => $ctx->ordername,
            'fee_type' => 'CNY',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'spbill_create_ip' => request()->clientip,
            'total_fee' => strval($ctx->order['realmoney'] * 100),
        ];
        try {
            $client = new \QQPay\PaymentService($this->qqpayConfig);
            $result = $client->nativePay($params);
            $code_url = $result['code_url'];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => 'QQ钱包支付下单失败！' . $e->getMessage()];
        }

        if ($ctx->isMobile && !request()->get('qrcode')) {
            if ($ctx->mdevice === 'qq') {
                return ['type' => 'jump', 'url' => $code_url];
            }
            return ['type' => 'qrcode', 'page' => 'qqpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'qqpay_qrcode', 'url' => $code_url];
        }
    }

    //JS支付
    public function jspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'out_trade_no' => $tradeNo,
            'body' => $ctx->ordername,
            'fee_type' => 'CNY',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'spbill_create_ip' => request()->clientip,
            'total_fee' => strval($ctx->order['realmoney'] * 100),
        ];
        try {
            $client = new \QQPay\PaymentService($this->qqpayConfig);
            $result = $client->jsapiPay($params);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => 'QQ钱包支付下单失败！' . $e->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => json_encode($result)];
        }

        return ['type' => 'page', 'page' => 'qqpay_jspay', 'data' => $result];
    }

    //APP支付
    public function apppay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'out_trade_no' => $tradeNo,
            'body' => $ctx->ordername,
            'fee_type' => 'CNY',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'spbill_create_ip' => request()->clientip,
            'total_fee' => strval($ctx->order['realmoney'] * 100),
        ];
        try {
            $client = new \QQPay\PaymentService($this->qqpayConfig);
            $result = $client->appPay($params);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => 'QQ钱包支付下单失败！' . $e->getMessage()];
        }
        return ['type' => 'app', 'data' => $result];
    }

    //付款码支付
    public function scanpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'out_trade_no' => $tradeNo,
            'body' => $ctx->ordername,
            'fee_type' => 'CNY',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'spbill_create_ip' => request()->clientip,
            'total_fee' => strval($ctx->order['realmoney'] * 100),
            'device_info' => '100001',
            'auth_code' => $ctx->order['auth_code'],
        ];
        try {
            $client = new \QQPay\PaymentService($this->qqpayConfig);
            $result = $client->microPay($params);
            if ($result['trade_state'] == 'SUCCESS') {
                ($this->markTrustedQueryResult('notify', 'qqpay-micropay-success'))(function () use ($tradeNo, $result) {
                    $this->processNotify($this->getOrder($tradeNo), $result['transaction_id'], $result['openid']);
                });
                return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['out_trade_no'], 'api_trade_no' => $result['transaction_id'], 'buyer' => $result['openid'], 'money' => strval(round($result['total_fee'] / 100, 2))]];
            } elseif ($result['trade_state'] == 'USERPAYING') {
                sleep(2);
                $retry = 0;
                $success = false;
                while ($retry < 6) {
                    sleep(3);
                    try {
                        $result = $client->orderQuery(null, $tradeNo);
                    } catch (Exception $e) {
                        return ['type' => 'error', 'msg' => 'QQ钱包支付失败！订单查询失败:' . $e->getMessage()];
                    }
                    if ($result['trade_state'] == 'SUCCESS') {
                        $success = true;
                        break;
                    } elseif ($result['trade_state'] != 'USERPAYING') {
                        return ['type' => 'error', 'msg' => 'QQ钱包支付失败！' . $result['trade_state_desc']];
                    }
                    $retry++;
                }
                if ($success) {
                    ($this->markTrustedQueryResult('notify', 'qqpay-order-query'))(function () use ($tradeNo, $result) {
                        $this->processNotify($this->getOrder($tradeNo), $result['transaction_id'], $result['openid']);
                    });
                    return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['out_trade_no'], 'api_trade_no' => $result['transaction_id'], 'buyer' => $result['openid'], 'money' => strval(round($result['total_fee'] / 100, 2))]];
                } else {
                    try {
                        $client->reverse($tradeNo);
                    } catch (Exception $e) {
                    }
                    return ['type' => 'error', 'msg' => 'QQ钱包支付失败！订单已超时'];
                }
            } else {
                return ['type' => 'error', 'msg' => 'QQ钱包支付失败！' . $result['trade_state_desc']];
            }
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => 'QQ钱包支付下单失败！' . $e->getMessage()];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];

        $isSuccess = true;
        $errmsg = '';
        try {
            $client = new \QQPay\PaymentService($this->qqpayConfig);
            $data = $client->notify();
            if ($data['out_trade_no'] == $tradeNo && $data['total_fee'] == strval((float) $ctx->order['realmoney'] * 100)) {
                ($this->markTrustedCallback($ctx, 'notify', 'qqpay-sdk-notify'))(function () use ($ctx, $data) {
                    $this->processNotify($ctx->order, $data['transaction_id'], $data['openid'], null, null, $data['time_end']);
                });
            }
        } catch (Exception $e) {
            $isSuccess = false;
            $errmsg = $e->getMessage();
        }

        $xml = $client->replyNotify($isSuccess, $errmsg, true);
        return ['type' => 'html', 'data' => $xml];
    }

    public function query(array $order): array
    {
        $client = new \QQPay\PaymentService($this->qqpayConfig);
        $result = $client->orderQuery(null, $order['trade_no']);
        return [
            'api_trade_no' => $result['transaction_id'] ?? '',
            'status' => $result['trade_state'] == 'SUCCESS' ? 1 : 0,
            'money' => ($result['total_fee'] ?? 0) / 100,
            'buyer' => $result['openid'] ?? '',
            'endtime' => $result['time_end'] ?? '',
        ];
    }

    //退款
    public function refund($order): array
    {
        $params = [
            'transaction_id' => $order['api_trade_no'],
            'out_refund_no' => $order['refund_no'],
            'refund_fee' => strval($order['refundmoney'] * 100),
        ];
        try {
            $client = new \QQPay\PaymentService($this->qqpayConfig);
            $result = $client->refund($params);
            $result = ['code' => 0, 'trade_no' => $result['transaction_id'], 'refund_fee' => $result['refund_fee']];
        } catch (Exception $e) {
            $result = ['code' => -1, 'msg' => $e->getMessage()];
        }
        return $result;
    }

    //关闭订单
    public function close($order): array
    {
        try {
            $client = new \QQPay\PaymentService($this->qqpayConfig);
            $client->closeOrder($order['trade_no']);
            $result = ['code' => 0];
        } catch (Exception $e) {
            $result = ['code' => -1, 'msg' => $e->getMessage()];
        }
        return $result;
    }

    //转账
    public function transfer($bizParam): array
    {
        $money = strval($bizParam['money'] * 100);
        try {
            $client = new \QQPay\TransferService($this->qqpayConfig);
            $result = $client->transfer($bizParam['out_biz_no'], $bizParam['payee_account'], $bizParam['payee_real_name'], $money, $bizParam['transfer_desc']);
            return ['code' => 0, 'status' => 1, 'orderid' => $result['transaction_id'], 'paydate' => date('Y-m-d H:i:s')];
        } catch (\QQPay\QQPayException $e) {
            $result = $e->getResponse();
            return ['code' => -1, 'errcode' => $result['err_code'], 'msg' => $e->getMessage()];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //转账查询
    public function transfer_query($bizParam): array
    {
        try {
            $client = new \QQPay\TransferService($this->qqpayConfig);
            $result = $client->transferQuery($bizParam['out_biz_no']);
            if ($result['status'] == 'SUCCESS') {
                $status = 1;
            } elseif ($result['status'] == 'REFUND') {
                $status = 2;
            } else {
                $status = 0;
            }
            return ['code' => 0, 'status' => $status, 'amount' => round($result['total_fee'] / 100, 2), 'paydate' => $result['transfer_time'], 'errmsg' => ''];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }
}
