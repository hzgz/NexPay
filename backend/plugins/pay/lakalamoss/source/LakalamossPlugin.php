<?php

declare(strict_types=1);

namespace plugins\payment\lakalamoss;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class LakalamossPlugin extends BasePayment
{
    private function createClient(): MossClient
    {
        return new MossClient($this->channel['appid'], $this->channel['appsecret']);
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

    //订单支付
    private function addOrder(PaymentContext $ctx, string $pay_scene, string $account_type, ?string $trans_type = null, ?string $openid = null, ?string $appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->createClient();

        $params = [
            'mer_no' => $this->channel['appmchid'],
            'order_no' => $tradeNo,
            'total_amount' => strval(round($ctx->order['realmoney'] * 100)),
            'pay_scene' => $pay_scene,
            'account_type' => $account_type,
            'subject' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($pay_scene == '0') {
            $params['callback_url'] = request()->siteurl . 'pay/return/' . $tradeNo . '/';
        } else {
            $params['trans_type'] = $trans_type;
            $params['location_info'] = [
                'request_ip' => request()->clientip
            ];
            if ($openid) $params['user_id'] = $openid;
            if ($appid) $params['acc_busi_fields']['sub_appid'] = $appid;
        }
        if (!empty($this->channel['splitmchid'])) {
            $params['split_info'] = [[
                'mer_no' => $this->channel['splitmchid'],
                'amount' => strval(round(($ctx->order['realmoney'] - 0.01) * 100)),
            ], [
                'mer_no' => $this->channel['appmchid'],
                'amount' => strval(round(0.01 * 100)),
            ]];
        }

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('lfops.moss.order.pay', $params);
            return $result;
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = request()->siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $result = $this->addOrder($ctx, '1', 'ALIPAY', '41');
                $code_url = $result['acc_resp_fields']['code'];
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
            $result = $this->addOrder($ctx, '1', 'ALIPAY', '51', $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['prepay_id']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['prepay_id'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            if ($this->channel['appwxa'] > 0 && $this->channel['appwxmp'] == 0) {
                $code_url = request()->siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            } else {
                $code_url = request()->siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            }
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $code_url];
            } elseif ($ctx->isMobile) {
                return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
            } else {
                return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
            }
        } else {
            try {
                $result = $this->addOrder($ctx, '0', 'WECHAT');
                $code_url = $result['counter_url'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            if ($ctx->isMobile) {
                return ['type' => 'jump', 'url' => $code_url];
            } else {
                return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
            }
        }
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

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        //①、获取用户openid
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
                $tools = new \WeChatPay\JsApiTool($wxinfo['appid'], $wxinfo['appsecret']);
                $openid = $tools->GetOpenid();
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        //②、统一下单
        try {
            $result = $this->addOrder($ctx, '1', 'WECHAT', '51', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        $payinfo = [
            'appId' => $result['acc_resp_fields']['app_id'],
            'timeStamp' => $result['acc_resp_fields']['time_stamp'],
            'nonceStr' => $result['acc_resp_fields']['nonce_str'],
            'package' => $result['acc_resp_fields']['package'],
            'signType' => $result['acc_resp_fields']['sign_type'],
            'paySign' => $result['acc_resp_fields']['pay_sign']
        ];
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => json_encode($payinfo)];
        }

        if (request()->get('d') == 1) {
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
        if (!$wxinfo) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];
        try {
            $tools = new \WeChatPay\JsApiTool($wxinfo['appid'], $wxinfo['appsecret']);
            $openid = $tools->AppGetOpenid($code);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];

        //②、统一下单
        try {
            $result = $this->addOrder($ctx, '1', 'WECHAT', '71', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }
        $payinfo = [
            'appId' => $result['acc_resp_fields']['app_id'],
            'timeStamp' => $result['acc_resp_fields']['time_stamp'],
            'nonceStr' => $result['acc_resp_fields']['nonce_str'],
            'package' => $result['acc_resp_fields']['package'],
            'signType' => $result['acc_resp_fields']['sign_type'],
            'paySign' => $result['acc_resp_fields']['pay_sign']
        ];

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $payinfo]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, '1', 'UQRCODEPAY', '41');
            $code_url = $result['acc_resp_fields']['code'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        try {
            $data = $client->notify($arr);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => $client->buildNotifyResponse($arr['head'], false, $ex->getMessage())];
        }

        if ($data['trade_state'] == 'SUCCESS') {
            $out_trade_no = $data['order_no'];
            $api_trade_no = $data['pay_serial'];
            $buyer = $data['user_id2'];
            $bill_trade_no = $data['acc_trade_no'];
            $bill_mch_trade_no = $data['trade_no'];
            $end_time = $data['trade_time'];
            if ($out_trade_no == $tradeNo) {
                $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
            }
        }
        return ['type' => 'json', 'data' => $client->buildNotifyResponse($arr['head'], true)];
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $client = $this->createClient();
        $params = [
            'order_no' => $order['trade_no'],
        ];
        $result = $client->execute('lfops.moss.order.qry', $params);
        $data = $result['pay_info_list'][0];
        return [
            'api_trade_no' => $data['pay_serial'],
            'status' => $data['trade_state'] == 'SUCCESS' ? 1 : 0,
            'money' => $result['total_amount'] / 100,
            'buyer' => $data['user_id2'] ?? '',
            'bill_trade_no' => $data['acc_trade_no'] ?? '',
            'endtime' => $data['trade_time'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->createClient();

        $params = [
            'order_no' => $order['refund_no'],
            'origin_order_no' => $order['trade_no'],
            'refund_amount' => strval(round($order['refundmoney'] * 100)),
            'refund_reason' => '订单退款',
            'location_info' => [
                'request_ip' => request()->clientip
            ]
        ];

        try {
            $result = $client->execute('lfops.moss.order.ref', $params);
            return ['code' => 0];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //投诉回调
    public function complainnotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        try {
            $data = $client->notify($arr);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => $client->buildNotifyResponse($arr['head'], false, $ex->getMessage())];
        }

        $channel = $this->channel;
        if ($arr['account_type'] == 'ALIPAY') $channel['type'] = 1;
        else $channel['type'] = 2;
        $model = \app\logic\ComplainLogic::getModel($channel);
        if ($arr['account_type'] == 'ALIPAY') $type = $arr['wx_data']['action_type'];
        else $type = $arr['zfb_data']['message_type'];
        $model->refreshNewInfo($arr['complaint_id'], $type);

        return ['type' => 'json', 'data' => $client->buildNotifyResponse($arr['head'], true)];
    }
}
