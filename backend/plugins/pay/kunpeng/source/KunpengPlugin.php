<?php

declare(strict_types=1);

namespace plugins\payment\kunpeng;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class KunpengPlugin extends BasePayment
{
    private function createClient(): KunpengClient
    {
        $privateKeyPath = getCertFilePath($this->channel['private_key_path'] ?? '');
        $publicKeyPath = $this->payRoot . 'cert/sv_public_key.pem';
        return new KunpengClient($this->channel['appid'], $privateKeyPath, $publicKeyPath);
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
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice == 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => request()->siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice == 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => request()->siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //扫码支付下单
    private function addOrder(PaymentContext $ctx, string $productId, ?string $appid = null, ?string $openid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->createClient();

        $appmchid = $this->channel['appmchid'];

        $param = [
            'productId' => $productId,
            'orderDate' => date('Ymd'),
            'orderNo' => $tradeNo,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returnUrl' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'orderAmount' => strval($ctx->order['realmoney'] * 100),
            'goodsName' => $ctx->ordername,
            'clientIp' => request()->clientip,
            'validTime' => 600,
        ];
        if (!empty($appmchid)) {
            if (strpos($appmchid, ',') !== false) {
                $upmchids = explode(',', $appmchid);
                $appmchid = $upmchids[array_rand($upmchids)];
            }
            $param['transMerId'] = $appmchid;
        }
        if ($openid) $param['userOpenid'] = $openid;
        if ($appid) $param['appId'] = $appid;

        return self::lockPayData($tradeNo, function () use ($client, $param) {
            $result = $client->execute('/mas/unitorder/pay.do', $param);
            return $result;
        });
    }

    //支付宝支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = request()->siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $result = $this->addOrder($ctx, 'A01');
                $code_url = $result['codeUrl'];
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

    //支付宝JS支付
    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $user_type = null;

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
            $result = $this->addOrder($ctx, 'A03', null, $user_id);
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

        if ($this->channel['appwxmp'] > 0) {
            $code_url = request()->siteurl . 'pay/wxjspay/' . $tradeNo . '/';
        } else {
            $code_url = request()->siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        }

        if ($ctx->mdevice == 'wechat') {
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
            $result = $this->addOrder($ctx, $ctx->order['is_applet'] == 1 ? 'W06' : 'W02', $wxinfo['appid'], $openid);
            $payinfo = $result['payInfo'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $payinfo];
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
        $code = request()->get('code');
        if (empty($code)) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }

        //①、获取用户openid
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];
        try {
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];

        //②、统一下单
        try {
            $result = $this->addOrder($ctx, 'W06', $wxinfo['appid'], $openid);
            $payinfo = json_decode($result['payInfo'], true);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }
        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $payinfo]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
        try {
            $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, 'U01');
            $code_url = $result['codeUrl'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->createClient();
        $verify_result = $client->verifySign(request()->post());

        if ($verify_result) {
            if (request()->post('resultCode') == '0000') {
                $out_trade_no = request()->post('orderNo');
                $trade_no = request()->post('instOrderNo');
                $bill_trade_no = request()->post('payNo', '');
                if ($ctx->order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) {
                    $bill_trade_no = substr($bill_trade_no, 2);
                }
                $bill_mch_trade_no = request()->post('channelNo');
                $channelResponse = json_decode(request()->post('channelResponse', ''), true);
                $buyer = $channelResponse['buyer_id'] ?? $channelResponse['openid'] ?? '';
                if ($out_trade_no == $tradeNo) {
                    ($this->markTrustedCallback($ctx, 'notify', 'kunpeng-signature'))(function () use ($ctx, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no) {
                        $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no);
                    });
                }
                return ['type' => 'html', 'data' => 'OK'];
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
        $client = $this->createClient();
        $result = $client->execute('/mas/order/query.do', ['orderNo' => $order['trade_no']]);
        $channelResponse = json_decode($result['channelResponse'], true);
        $bill_trade_no = $result['payNo'] ?? '';
        if ($order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
        return [
            'api_trade_no' => $result['instOrderNo'],
            'status' => 1,
            'money' => $result['orderAmount'] / 100,
            'buyer' => $channelResponse['buyer_id'] ?? $channelResponse['openid'] ?? '',
            'bill_trade_no' => $bill_trade_no,
            'bill_mch_trade_no' => $result['channelNo'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->createClient();

        $param = [
            'paymentOrderNo' => $order['trade_no'],
            'paymentInstOrderNo' => $order['api_trade_no'],
            'refundOrderNo' => $order['refund_no'],
            'refundAmount' => strval($order['refundmoney'] * 100),
            'currency' => 'CNY',
            'returnUrl' => request()->siteurl . 'pay/return/' . $order['trade_no'] . '/',
            'notifyUrl' => config_get('localurl') . 'pay/refundnotify/' . $this->channel['id'] . '/',
        ];

        try {
            $result = $client->execute('/mas/order/refund.do', $param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        return ['code' => 0, 'trade_no' => $result['instRefundOrderNo'], 'refund_fee' => $order['refundmoney']];
    }

    //退款回调
    public function refundnotify(PaymentContext $ctx): array
    {
        $client = $this->createClient();
        $post = request()->post();
        $verify_result = $client->verifySign($post);

        if ($verify_result) {
            if ($post['resultCode'] == '0000') {
                ($this->markTrustedCallback($ctx, 'refundnotify', 'kunpeng-signature'))(function () use ($post) {
                    $this->processRefund(
                        $post['refundOrderNo'] ?? $post['orderNo'] ?? $post['paymentOrderNo'] ?? '',
                        1,
                        '',
                        $post['instRefundOrderNo'] ?? $post['instOrderNo'] ?? '',
                        isset($post['refundAmount']) ? ((float)$post['refundAmount'] / 100) : null
                    );
                });
                return ['type' => 'html', 'data' => 'OK'];
            }
            ($this->markTrustedCallback($ctx, 'refundnotify', 'kunpeng-signature'))(function () use ($post) {
                $this->processRefund(
                    $post['refundOrderNo'] ?? $post['orderNo'] ?? $post['paymentOrderNo'] ?? '',
                    2,
                    (string)($post['resultMsg'] ?? 'kunpeng refund failed'),
                    $post['instRefundOrderNo'] ?? $post['instOrderNo'] ?? '',
                    isset($post['refundAmount']) ? ((float)$post['refundAmount'] / 100) : null
                );
            });
            return ['type' => 'html', 'data' => 'status fail'];
        } else {
            return ['type' => 'html', 'data' => 'sign fail'];
        }
    }

    //进件回调
    public function applynotify(PaymentContext $ctx): array
    {
        $client = $this->createClient();
        $post = request()->post();
        $verify_result = $client->verifySign($post);

        if ($verify_result) {
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->notify($post);
            return ['type' => 'html', 'data' => 'OK'];
        } else {
            return ['type' => 'html', 'data' => 'sign fail'];
        }
    }

    //提现回调
    public function cashnotify(PaymentContext $ctx): array
    {
        $client = $this->createClient();
        $post = request()->post();
        $verify_result = $client->verifySign($post);

        if ($verify_result) {
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->cashnotify($post);
            return ['type' => 'html', 'data' => 'OK'];
        } else {
            return ['type' => 'html', 'data' => 'sign fail'];
        }
    }

    //分账回调
    public function sharingnotify(PaymentContext $ctx): array
    {
        $client = $this->createClient();
        $post = request()->post();
        $verify_result = $client->verifySign($post);

        if ($verify_result) {
            $sharingInfos = json_decode($post['sharingInfos'], true);
            if (!empty($sharingInfos)) {
                $info = $sharingInfos[0];
                if ($info['resultcode'] == '0000') {
                    $this->processProfitSharing($post['sharingCustOrderNo'], 2);
                } elseif ($info['resultcode'] == '9997') {
                    // 处理中，不做操作
                } else {
                    $this->processProfitSharing($post['sharingCustOrderNo'], 3, $info['resultmsg']);
                }
            }
            return ['type' => 'html', 'data' => 'OK'];
        } else {
            return ['type' => 'html', 'data' => 'sign fail'];
        }
    }

    //投诉回调
    public function complainnotify(PaymentContext $ctx): array
    {
        $post = request()->post();

        if (isset($post['complaintChannel'])) {
            $channel = $this->channel;
            if ($post['complaintChannel'] == 'A') $channel['type'] = 1;
            else $channel['type'] = 2;
            $model = \app\logic\ComplainLogic::getModel($channel);
            $model->refreshNewInfo($post['complaintId'], $post);
            return ['type' => 'html', 'data' => 'OK'];
        } else {
            return ['type' => 'html', 'data' => 'sign fail'];
        }
    }
}
