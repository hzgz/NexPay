<?php

declare(strict_types=1);

namespace plugins\payment\hwkjpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class HwkjpayPlugin extends BasePayment
{
    public function __construct(array $channel)
    {
        parent::__construct($channel);
    }

    private function getClient(): PayClient
    {
        $privateKeyPath = getCertFilePath($this->channel['private_key_path'] ?? '');
        $publicKeyPath = $this->payRoot . 'cert/pubKey.pem';
        return new PayClient($this->channel['appid'], $privateKeyPath, $publicKeyPath);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && in_array('3', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && in_array('3', $this->channel['apptype'])) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //扫码支付下单
    private function addOrder(PaymentContext $ctx, string $method, ?string $appid = null, ?string $openid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $param = [
            'out_trade_no' => $tradeNo,
            'total_amount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'client_ip' => request()->clientip,
        ];
        if ($openid) $param['payer_id'] = $openid;
        if ($appid) $param['app_id'] = $appid;
        $notify_url = config_get('localurl') . 'pay/notify/' . $tradeNo . '/';
        $return_url = request()->siteurl . 'pay/return/' . $tradeNo . '/';

        return self::lockPayData($tradeNo, function () use ($client, $method, $param, $notify_url, $return_url) {
            $result = $client->execute($method, $param, $notify_url, $return_url);
            return $result;
        });
    }

    //支付宝支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, 'trade.alipay.qrcode.pay');
            $code_url = $result['qrcode_url'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0 && !in_array('1', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
        } elseif (in_array('3', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } else {
            try {
                $result = $this->addOrder($ctx, 'trade.wechat.scan.pay');
                $code_url = $result['qrcode_url'];
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
            $result = $this->addOrder($ctx, 'trade.wechat.jsapi.pay', $wxinfo['appid'], $openid);
            $payinfo = $result['js_sdk'];
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
        $code = request()->get('code');
        if (empty($code)) {
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
            $result = $this->addOrder($ctx, 'trade.wechat.jsapi.pay', $wxinfo['appid'], $openid);
            $payinfo = $result['js_sdk'];
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }
        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $payinfo]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        if ($this->channel['appwxa'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } else {
            try {
                $result = $this->addOrder($ctx, 'trade.wechat.mini.h5.pay');
                $code_url = $result['qrcode_url'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        }
    }

    //微信APP支付
    public function wxapppay(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, 'trade.wechat.mini.pay');
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => $result['appId'], 'miniProgramId' => '', 'path' => $result['path']]];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $client = $this->getClient();
        $verify_result = $client->verifySign(request()->post());

        if ($verify_result) {
            if (request()->post('trade_status') == 'TRADE_SUCCESS') {
                $out_trade_no = request()->post('out_trade_no');
                $trade_no = request()->post('trade_no');
                $bill_trade_no = request()->post('channel_order_id', '');
                if ($ctx->order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
                $bill_mch_trade_no = request()->post('bank_order_id');
                $end_time = request()->post('pay_time');
                if ($out_trade_no == $ctx->order['trade_no']) {
                    ($this->markTrustedCallback($ctx, 'notify', 'hwkjpay-signature'))(function () use ($ctx, $trade_no, $bill_trade_no, $bill_mch_trade_no, $end_time) {
                        $this->processNotify($ctx->order, $trade_no, null, $bill_trade_no, $bill_mch_trade_no, $end_time);
                    });
                }
                return ['type' => 'html', 'data' => 'success'];
            }
            return ['type' => 'html', 'data' => 'status fail'];
        } else {
            return ['type' => 'html', 'data' => 'sign fail'];
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
        $param = [
            'out_trade_no' => $order['trade_no'],
        ];
        $result = $client->execute('trade.pay.query', $param);
        $bill_trade_no = $result['channel_order_id'] ?? '';
        if ($order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
        return [
            'api_trade_no' => $result['trade_no'],
            'status' => $result['trade_status'] == 'TRADE_SUCCESS' ? 1 : 0,
            'money' => $result['total_amount'],
            'buyer' => $result['payer_id'] ?? '',
            'bill_trade_no' => $bill_trade_no,
            'bill_mch_trade_no' => $result['bank_order_id'] ?? '',
            'endtime' => $result['pay_time'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->getClient();

        $param = [
            'trade_no' => $order['api_trade_no'],
            'out_trade_refund_no' => $order['refund_no'],
            'refund_amount' => $order['refundmoney'],
        ];
        $notify_url = config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/';

        try {
            $result = $client->execute('trade.pay.refund', $param, $notify_url);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        return ['code' => 0, 'trade_no' => $result['refund_order_id'], 'refund_fee' => $result['refund_amount']];
    }

    //退款异步回调
    public function refundnotify(PaymentContext $ctx): array
    {
        $client = $this->getClient();
        $postData = request()->post();
        $verify_result = $client->verifySign($postData);

        if ($verify_result) {
            if ($postData['refund_status'] == 'TRADE_SUCCESS') {
                $refund_no = $postData['refund_trade_no'];
                ($this->markTrustedCallback($ctx, 'refundnotify', 'hwkjpay-signature'))(function () use ($postData) {
                    $this->processRefund(
                        $postData['out_trade_refund_no'] ?? $postData['refund_trade_no'] ?? '',
                        1,
                        '',
                        $postData['refund_trade_no'] ?? '',
                        $postData['refund_amount'] ?? null
                    );
                });
            } elseif (($postData['refund_status'] ?? '') !== '') {
                ($this->markTrustedCallback($ctx, 'refundnotify', 'hwkjpay-signature'))(function () use ($postData) {
                    $this->processRefund(
                        $postData['out_trade_refund_no'] ?? $postData['refund_trade_no'] ?? '',
                        2,
                        (string)($postData['refund_status'] ?? 'hwkjpay refund failed'),
                        $postData['refund_trade_no'] ?? '',
                        $postData['refund_amount'] ?? null
                    );
                });
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'sign fail'];
        }
    }
}
