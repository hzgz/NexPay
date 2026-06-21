<?php

declare(strict_types=1);

namespace plugins\payment\payjs;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class PayjsPlugin extends BasePayment
{
    private function getClient(): PayjsClient
    {
        return new PayjsClient($this->channel['appid'], $this->channel['appkey']);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        if ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return $this->wxjspay($ctx);
            } elseif ($ctx->isMobile) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $pay = $this->getClient();

        $arr = [
            'body' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
            'total_fee' => strval($ctx->order['realmoney'] * 100),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'type' => 'alipay',
        ];
        $result = $pay->pay($arr);

        if ($result['return_code'] == 1) {
            $this->updateOrder($tradeNo, $result['payjs_order_id']);
            $code_url = $result['code_url'];
        } else {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败 ' . ($result['return_msg'] ?? '未知错误')];
        }

        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $pay = $this->getClient();

        $arr = [
            'body' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
            'total_fee' => strval($ctx->order['realmoney'] * 100),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        $result = $pay->pay($arr);

        if ($result['return_code'] == 1) {
            $this->updateOrder($tradeNo, $result['payjs_order_id']);
            $code_url = $result['code_url'];
        } else {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . ($result['return_msg'] ?? '未知错误')];
        }

        return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
    }

    //微信公众号支付（收银台）
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $pay = $this->getClient();

        $arr = [
            'body' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
            'total_fee' => strval($ctx->order['realmoney'] * 100),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'auto' => '1',
        ];
        if (request()->get('d') == 1) $arr['callback_url'] = $siteurl . 'pay/return/' . $tradeNo . '/';

        $url = $pay->cashier($arr);
        return ['type' => 'jump', 'url' => $url];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($this->channel['appswitch'] == 1) {
            $pay = $this->getClient();

            $arr = [
                'body' => $ctx->ordername,
                'out_trade_no' => $tradeNo,
                'total_fee' => strval($ctx->order['realmoney'] * 100),
                'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
                'callback_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            ];
            $result = $pay->mwebpay($arr);

            if ($result['return_code'] == 1) {
                $this->updateOrder($tradeNo, $result['payjs_order_id']);
                $h5_url = $result['h5_url'];
                return ['type' => 'jump', 'url' => $h5_url];
            } else {
                return ['type' => 'error', 'msg' => '微信支付下单失败 ' . ($result['return_msg'] ?? '未知错误')];
            }
        } else {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $pay = $this->getClient();

        if ($pay->checkSign(request()->post())) {
            if (request()->post('return_code') == 1) {
                $out_trade_no = request()->post('out_trade_no');
                $payjs_order_id = request()->post('payjs_order_id');
                $openid = request()->post('openid');
                $total_fee = request()->post('total_fee');
                $bill_trade_no = request()->post('transaction_id');
                $end_time = request()->post('time_end');
                if ($out_trade_no == $tradeNo && $total_fee == strval($ctx->order['realmoney'] * 100)) {
                    ($this->markTrustedCallback($ctx, 'notify', 'payjs-signature'))(function () use ($ctx, $payjs_order_id, $openid, $bill_trade_no, $end_time) {
                        $this->processNotify($ctx->order, $payjs_order_id, $openid, $bill_trade_no, null, $end_time);
                    });
                }

                return ['type' => 'html', 'data' => 'success'];
            } else {
                return ['type' => 'html', 'data' => 'fail'];
            }
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $pay = $this->getClient();
        $result = $pay->checkOrder($order['api_trade_no']);
        if ($result['return_code'] == 1) {
            return [
                'api_trade_no' => $result['payjs_order_id'],
                'status' => $result['status'],
                'money' => $result['total_fee'],
                'buyer' => $result['openid'] ?? '',
                'bill_trade_no' => $result['transaction_id'] ?? '',
                'endtime' => $result['paid_time'] ?? '',
            ];
        } else {
            throw new \Exception($result['return_msg'] ?? '返回数据解析失败');
        }
    }

    //退款
    public function refund(array $order): array
    {
        if (round($order['refundmoney'], 2) != round($order['realmoney'], 2)) {
            return ['code' => -1, 'msg' => '该支付插件不支持部分退款'];
        }

        $pay = $this->getClient();

        $result = $pay->refund($order['api_trade_no']);

        if ($result['return_code'] == 1) {
            $result = ['code' => 0, 'trade_no' => $result['payjs_order_id'], 'refund_fee' => $order['realmoney']];
        } else {
            $result = ['code' => -1, 'msg' => $result["return_msg"] ?? '未知错误'];
        }
        return $result;
    }
}
