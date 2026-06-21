<?php

declare(strict_types=1);

namespace plugins\payment\shangfutong;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class ShangfutongPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'bank') {
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        if ($ctx->method === 'applet') {
            return $this->wxappletpay($ctx);
        } elseif ($ctx->method === 'app') {
            return $this->wxapppay($ctx);
        } elseif ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            return $this->wxpay($ctx);
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    private function getClient(): PayClient
    {
        return new PayClient($this->channel['appid'], $this->channel['appkey']);
    }

    //PC扫码支付
    private function scanpay(PaymentContext $ctx): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'subject' => $ctx->ordername,
            'body' => $ctx->order['name'],
            'amount' => intval(round($ctx->order['realmoney'] * 100)),
            'currency' => 'cny',
            'mchOrderNo' => $tradeNo,
            'storeId' => $this->channel['appmchid'],
            'routeNo' => $this->channel['route_no'],
            'clientIp' => request()->clientip,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returnUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        $client = $this->getClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('/api/open/payment/scanpay', $params);
            $this->updateOrder($tradeNo, $result['payOrderId']);
            return $result['payInfo']['sourceQrCodeUrl'];
        });
    }

    //APP支付
    private function apppay(PaymentContext $ctx, string $pay_type): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'subject' => $ctx->ordername,
            'body' => $ctx->order['name'],
            'payType' => $pay_type,
            'amount' => intval(round($ctx->order['realmoney'] * 100)),
            'currency' => 'cny',
            'mchOrderNo' => $tradeNo,
            'storeId' => $this->channel['appmchid'],
            'routeNo' => $this->channel['route_no'],
            'clientIp' => request()->clientip,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returnUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        $client = $this->getClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('/api/open/payment/apppay', $params);
            $this->updateOrder($tradeNo, $result['payOrderId']);
            return $result['liteInfo'];
        });
    }

    //小程序支付
    private function applet(PaymentContext $ctx, string $pay_type): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'subject' => $ctx->ordername,
            'body' => $ctx->order['name'],
            'payType' => $pay_type,
            'isScreen' => true,
            'amount' => intval(round($ctx->order['realmoney'] * 100)),
            'currency' => 'cny',
            'mchOrderNo' => $tradeNo,
            'storeId' => $this->channel['appmchid'],
            'routeNo' => $this->channel['route_no'],
            'clientIp' => request()->clientip,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returnUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        $client = $this->getClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('/api/open/payment/ltpay', $params);
            $this->updateOrder($tradeNo, $result['payOrderId']);
            return $result['liteInfo'];
        });
    }

    //手机网页支付
    private function h5pay(PaymentContext $ctx, string $pay_type): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'subject' => $ctx->ordername,
            'body' => $ctx->order['name'],
            'payType' => $pay_type,
            'amount' => intval(round($ctx->order['realmoney'] * 100)),
            'currency' => 'cny',
            'mchOrderNo' => $tradeNo,
            'storeId' => $this->channel['appmchid'],
            'routeNo' => $this->channel['route_no'],
            'clientIp' => request()->clientip,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returnUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        $client = $this->getClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('/api/open/payment/h5pay', $params);
            if (!isset($result['payInfo'])) {
                throw new Exception('支付参数信息未返回');
            }
            $this->updateOrder($tradeNo, $result['payOrderId']);
            return $result['payInfo']['payUrl'];
        });
    }

    //JS支付
    private function jspay(PaymentContext $ctx, string $pay_type, ?string $sub_openid = null, ?string $sub_appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'subject' => $ctx->ordername,
            'body' => $ctx->order['name'],
            'payType' => $pay_type,
            'amount' => intval(round($ctx->order['realmoney'] * 100)),
            'currency' => 'cny',
            'mchOrderNo' => $tradeNo,
            'storeId' => $this->channel['appmchid'],
            'routeNo' => $this->channel['route_no'],
            'clientIp' => request()->clientip,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returnUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];
        if ($sub_openid) $params['userId'] = $sub_openid;
        if ($sub_appid) $params['subAppid'] = $sub_appid;

        $client = $this->getClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('/api/open/payment/jspay', $params);
            if (!isset($result['payInfo'])) {
                throw new Exception('支付参数信息未返回');
            }
            $this->updateOrder($tradeNo, $result['payOrderId']);
            return $result['payInfo'];
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->scanpay($ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        //$code_url = request()->siteurl.'pay/alipaywap/'.$ctx->order['trade_no'].'/';
        if (strpos($code_url, 'qrcode=') !== false) {
            $code_url = explode('qrcode=', $code_url)[1];
        }
        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    public function alipaywap(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->h5pay($ctx, 'ALIPAY');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->scanpay($ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        $scheme_url = 'weixin://dl/business/?t=' . (explode('//wxaurl.cn/', $code_url)[1]);

        if ($ctx->isMobile) {
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $scheme_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //微信APP支付
    public function wxapppay(PaymentContext $ctx): array
    {
        try {
            $result = $this->apppay($ctx, 'WECHAT');
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => $result['appId'], 'miniProgramId' => $result['ghId'], 'path' => $result['path']]];
    }

    //微信半屏小程序支付
    public function wxappletpay(PaymentContext $ctx): array
    {
        try {
            $result = $this->applet($ctx, 'WECHAT');
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => $result['appId'], 'miniProgramId' => $result['ghId'], 'path' => $result['path']]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->scanpay($ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->getClient();
        $verify_result = $client->verify($arr);

        if ($verify_result) {
            $data = json_decode($arr['bizData'] ?? '', true);
            if ($data['state'] == 'TRADE_SUCCESS') {
                $out_trade_no = $data['mchOrderNo'];
                $api_trade_no = $data['payOrderId'];
                $money = $data['amount'];
                $bill_trade_no = $data['channelTradeNo'] ?? '';
                $bill_mch_trade_no = $data['channelSendNo'] ?? '';
                $buyer = $data['userId'] ?? '';
                $end_time = $data['payTime'] ?? '';

                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            return ['type' => 'html', 'data' => 'FAILED'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $client = $this->getClient();
        $params = [
            'mchOrderNo' => $order['trade_no'],
        ];
        $result = $client->execute('/api/open/query/trade', $params);
        return [
            'api_trade_no' => $result['payOrderId'],
            'status' => $result['state'] == 'TRADE_SUCCESS' ? 1 : 0,
            'money' => $result['amount'] / 100,
            'buyer' => $result['userId'] ?? '',
            'bill_trade_no' => $result['channelTradeNo'] ?? '',
            'bill_mch_trade_no' => $result['channelSendNo'] ?? '',
            'endtime' => $result['payTime'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->getClient();

        $params = [
            'mchRefundNo' => $order['refund_no'],
            'payOrderId' => $order['api_trade_no'],
            'refundAmount' => intval(round($order['refundmoney'] * 100)),
            'refundReason' => '订单退款',
        ];

        try {
            $retData = $client->execute('/api/open/order/refund', $params);
            $result = ['code' => 0, 'trade_no' => $retData['refundOrderId'], 'refund_fee' => $retData['refundAmt'] / 100];
        } catch (Exception $e) {
            $result = ['code' => -1, 'msg' => $e->getMessage()];
        }
        return $result;
    }
}
