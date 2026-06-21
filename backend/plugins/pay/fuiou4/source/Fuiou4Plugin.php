<?php

declare(strict_types=1);

namespace plugins\payment\fuiou4;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

/**
 * 富友支付(H5支付)
 * http://180.168.100.158:13318/fuiouH5Apipay/
 */
class Fuiou4Plugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $typename = $ctx->order['typename'];

        if ($typename === 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($typename === 'wxpay') {
            if ($ctx->isMobile) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($typename === 'bank') {
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $typename = $ctx->order['typename'];

        if ($typename === 'alipay') {
            return $this->alipay($ctx);
        } elseif ($typename === 'wxpay') {
            if ($ctx->isMobile) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($typename === 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //通用下单
    private function addOrder(PaymentContext $ctx, string $payType): string
    {
        $tradeNo = $ctx->order['trade_no'];

        if($this->channel['appswitch'] == 1){
			$apiurl = 'https://aggpc-test.fuioupay.com/token/order.fuiou';
		}else{
			$apiurl = 'https://aggpcpay.fuioupay.com/token/order.fuiou';
		}
        $param = [
            'order_date' => date('Ymd'),
            'order_id' => $tradeNo,
            'order_amt' => strval((float) $ctx->order['realmoney'] * 100),
            'page_notify_url' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'back_notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'goods_name' => $ctx->ordername,
            'goods_detail' => $ctx->ordername,
            'fee_type' => 'CNY',
            'ver' => '4.0.0',
            'pay_type' => $payType,
        ];

        $client = new PayService($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);

        return self::lockPayData($tradeNo, function () use ($client, $apiurl, $param) {
            $result = $client->submit($apiurl, $param);
            return $result['token_url'];
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'AliPayJL');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $code_url = request()->siteurl . 'pay/wxwappay/' . $tradeNo . '/';

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //微信小程序支付
    public function wxwappay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'WeAppletPay');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'AppQuickPay');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '银联云闪付下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) {
            return ['type' => 'html', 'data' => 'no data'];
        }

        $client = new PayService($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);
        $arr = $client->decryptNotify($data['message']);
        if (!$arr) {
            return ['type' => 'html', 'data' => 'decrypt_fail'];
        }

        if ($arr['order_st'] == 1) {
            $out_trade_no = $arr['order_id'];
            $trade_no = $arr['order_fas_ssn'];
            $money = $arr['order_amt'];
            $buyer = $arr['openid'];
            $bill_trade_no = $arr['pay_ssn'];
            $bill_mch_trade_no = $arr['fy_order_id'];

            if ($out_trade_no == $ctx->order['trade_no']) {
                $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no);
            }
            return ['type' => 'html', 'data' => 'success'];
        }

        return ['type' => 'html', 'data' => 'status_fail'];
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        if ($this->channel['appswitch'] == 1) {
            $apiurl = 'https://aggpc-test.fuioupay.com/token/orderQuery.fuiou';
        } else {
            $apiurl = 'https://aggpcpay.fuioupay.com/token/orderQuery.fuiou';
        }
        $param = [
            'order_date' => date('Ymd', strtotime($order['addtime'])),
            'order_id' => $order['trade_no'],
        ];
        $client = new PayService($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);
        $result = $client->submit($apiurl, $param);
        return [
            'api_trade_no' => $result['order_fas_ssn'],
            'status' => $result['order_st'] == 1 ? 1 : 0,
            'money' => $result['order_amt'] / 100,
            'buyer' => $result['openid'] ?? '',
            'bill_trade_no' => $result['pay_ssn'] ?? '',
            'bill_mch_trade_no' => $result['fy_order_id'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        if ($this->channel['appswitch'] == 1) {
            $apiurl = 'https://refund-transfer-test.fuioupay.com/refund_transfer/aggwapRefund.fuiou';
        } else {
            $apiurl = 'https://refund-transfer.fuioupay.com/refund_transfer/aggwapRefund.fuiou';
        }

        $param = [
            'refund_order_date' => date('Ymd'),
            'refund_order_id' => $order['refund_no'],
            'pay_order_date' => date('Ymd', strtotime($order['addtime'])),
            'pay_order_id' => $order['trade_no'],
            'refund_amt' => strval((float) $order['refundmoney'] * 100),
            'back_notify_url' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
        ];

        $client = new PayService($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);

        try {
            $result = $client->submit($apiurl, $param);
            return [
                'code' => 0,
                'trade_no' => $result['pay_order_id'] ?? null,
                'refund_fee' => $result['refund_amt'] ?? null,
            ];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //退款异步回调
    public function refundnotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) {
            return ['type' => 'html', 'data' => 'no data'];
        }

        $client = new PayService($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);
        $arr = $client->decryptNotify($data['message']);

        if (!$arr) {
            return ['type' => 'html', 'data' => 'decrypt_fail'];
        }

        $status = ($arr['order_st'] ?? null) == 1 ? 1 : 2;
        if ($arr['order_st'] == 1) {
            $this->processRefund(
                $arr['refund_order_id'] ?? $arr['order_id'] ?? '',
                1,
                '',
                $arr['fy_order_id'] ?? $arr['pay_order_id'] ?? '',
                isset($arr['refund_amt']) ? ((float)$arr['refund_amt'] / 100) : null
            );
            return ['type' => 'html', 'data' => 'success'];
        }

        $this->processRefund(
            $arr['refund_order_id'] ?? $arr['order_id'] ?? '',
            $status,
            (string)($arr['reserved_fy'] ?? $arr['resp_desc'] ?? 'fuiou refund failed'),
            $arr['fy_order_id'] ?? $arr['pay_order_id'] ?? '',
            isset($arr['refund_amt']) ? ((float)$arr['refund_amt'] / 100) : null
        );
        return ['type' => 'html', 'data' => 'status_fail'];
    }
}
