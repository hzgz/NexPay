<?php

declare(strict_types=1);

namespace plugins\payment\youzan;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class YouzanPlugin extends BasePayment
{
    private function getClient(): YouzanClient
    {
        return new YouzanClient(
            $this->channel['appid'],
            $this->channel['appkey'],
            $this->channel['appsecret'],
            $this->channel['publickeyid']
        );
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay') {
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
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($this->channel['appswitch'] == 0 && $ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay') {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //统一下单
    private function addOrder(PaymentContext $ctx, string $pay_tool, ?array $ext = null): mixed
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $params = [
            'outer_trade_no' => $tradeNo,
            'trade_desc' => $ctx->ordername,
            'pay_amount' => intval(round($ctx->order['realmoney'] * 100)),
            'currency' => 'CNY',
            'mch_id' => $this->channel['appid'],
            'pay_tool' => $pay_tool,
            'user_ip' => request()->clientip,
            'pay_notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'expire_time' => date('Y-m-d H:i:s', time() + 1800),
        ];
        if ($ext) $params['ext'] = $ext;

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('youzan.pay.unified.payopen.apply/1.0.0', $params);
            $this->updateOrder($tradeNo, $result['trade_no']);
            return $result['deeplink'];
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';

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

        if (!empty($ctx->order['sub_openid'])) {
            $user_id = $ctx->order['sub_openid'];
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }

        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;

        try {
            $result = $this->addOrder($ctx, 'ALIPAY_JS', ['buyer_id' => $user_id]);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['trade_no']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['trade_no'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($this->channel['appwxa'] == 0 && $this->channel['appwxmp'] > 0) {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
        } else {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
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
        $siteurl = request()->siteurl;

        if (!empty($ctx->order['sub_openid'])) {
            if (!empty($ctx->order['sub_appid'])) {
                $wxinfo['appid'] = $ctx->order['sub_appid'];
            } else {
                $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
                if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
            }
            $openid = $ctx->order['sub_openid'];
        } else {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
            try {
                $openid = wechat_oauth($wxinfo);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        try {
            $pay_info = $this->addOrder($ctx, 'WX_JS', ['open_id' => $openid, 'app_id' => $wxinfo['appid'], 'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => json_encode($pay_info)];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => json_encode($pay_info), 'redirect_url' => $redirect_url]];
    }

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $code = request()->get('code', '', 'trim');
        if ($code === '') {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }

        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];

        try {
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];

        try {
            $pay_info = $this->addOrder($ctx, 'WX_APPLET', ['open_id' => $openid, 'app_id' => $wxinfo['appid']]);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $pay_info]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

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
                $result = $this->addOrder($ctx, 'WX_H5', ['type' => 'Wap', 'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/']);
                $code_url = $result['awake_pay_url'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'jump', 'url' => $code_url];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $data = request()->getContent();
        $arr = json_decode($data, true);
        if (!$arr) return ['type' => 'json', 'data' => ['success' => false, 'message' => 'no data']];

        $client = $this->getClient();
        $verify_result = $client->verifyNotify($data);

        if ($verify_result) {
            if ($arr['status'] == 'SUCCESS') {
                $out_trade_no = $arr['outer_trade_no'];
                $trade_no = $arr['trade_no'];
                $bill_trade_no = $arr['external_payment_no'] ?? '';
                $bill_mch_trade_no = $arr['external_out_payment_no'] ?? '';
                if ($out_trade_no == $tradeNo) {
                    ($this->markTrustedCallback($ctx, 'notify', 'youzan-signature'))(function () use ($ctx, $trade_no, $bill_trade_no, $bill_mch_trade_no) {
                        $this->processNotify($ctx->order, $trade_no, null, $bill_trade_no, $bill_mch_trade_no);
                    });
                }
            }
            return ['type' => 'json', 'data' => ['success' => true]];
        } else {
            return ['type' => 'json', 'data' => ['success' => false, 'message' => 'sign fail']];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->getClient();

        $params = [
            'mch_id' => $this->channel['appid'],
            'out_refund_no' => $order['refund_no'],
            'trade_no' => $order['api_trade_no'],
            'refund_fee' => intval(round($order['refundmoney'] * 100)),
            'notify_url' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
        ];

        try {
            $result = $client->execute('youzan.pay.unified.refundopen.apply/1.0.0', $params);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        return ['code' => 0, 'trade_no' => $result['trade_no'], 'refund_fee' => $result['refund_fee'] / 100];
    }

    //退款异步回调
    public function refundnotify(PaymentContext $ctx): array
    {
        $data = request()->getContent();
        $arr = json_decode($data, true);
        if (!$arr) return ['type' => 'json', 'data' => ['success' => false, 'message' => 'no data']];

        $client = $this->getClient();
        $verify_result = $client->verifyNotify($data);

        if ($verify_result) {
            $refundStatus = strtoupper((string)($arr['refund_status'] ?? $arr['status'] ?? $arr['event_status'] ?? ''));
            $status = in_array($refundStatus, ['SUCCESS', 'SUCCEEDED', 'REFUND_SUCCESS', 'TRADE_SUCCESS'], true) ? 1 : 0;
            if (in_array($refundStatus, ['FAIL', 'FAILED', 'REFUND_FAILED'], true)) {
                $status = 2;
            }
            $refundNo = (string)($arr['out_refund_no'] ?? $arr['refund_no'] ?? $arr['refund_order_no'] ?? '');
            $channelOrderNo = (string)($arr['refund_id'] ?? $arr['trade_no'] ?? '');
            if ($refundNo === '' && $status === 0) {
                return ['type' => 'json', 'data' => ['success' => false, 'message' => 'refund_no missing']];
            }
            ($this->markTrustedCallback($ctx, 'refundnotify', 'youzan-signature'))(function () use ($refundNo, $status, $arr, $channelOrderNo) {
                $this->processRefund(
                    $refundNo,
                    $status,
                    $status === 2 ? (string)($arr['message'] ?? $arr['msg'] ?? 'youzan refund failed') : '',
                    $channelOrderNo,
                    isset($arr['refund_fee']) ? ((float)$arr['refund_fee'] / 100) : ($arr['refund_amount'] ?? null)
                );
            });
            return ['type' => 'json', 'data' => ['success' => true]];
        } else {
            return ['type' => 'json', 'data' => ['success' => false, 'message' => 'sign fail']];
        }
    }
}
