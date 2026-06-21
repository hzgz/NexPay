<?php

declare(strict_types=1);

namespace plugins\payment\adapay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class AdapayPlugin extends BasePayment
{
    private function createClient(): AdapayClient
    {
        if (!empty($this->channel['appmchid'])) $this->channel['appkey'] = $this->channel['appmchid'];
        return new AdapayClient($this->channel['appkey'], $this->channel['appsecret'], $this->channel['appid']);
    }

    //通用创建订单
    private function addOrder(PaymentContext $ctx, string $pay_channel, ?string $openid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->createClient();

        $params = [
            'order_no' => $tradeNo,
            'pay_channel' => $pay_channel,
            'pay_amt' => $ctx->order['realmoney'],
            'goods_title' => $ctx->ordername,
            'goods_desc' => $ctx->ordername,
            'currency' => 'cny',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($pay_channel === 'wx_pub' || $pay_channel === 'wx_lite') {
            $params['expend'] = [
                'openid' => $openid,
            ];
        } elseif ($pay_channel === 'alipay_pub' || $pay_channel === 'alipay_lite') {
            $params['expend'] = [
                'buyer_id' => $openid,
            ];
        }
        if ($ctx->order['profits'] > 0) {
            $psreceiver = \app\logic\ProfitSharingLogic::getReceiver($ctx->order['profits']);
            if ($psreceiver && $psreceiver['mode'] == 0) {
                $psmoney = round(floor($ctx->order['realmoney'] * $psreceiver['rate']) / 100, 2);
                $psmoney2 = round($ctx->order['realmoney'] - $psmoney, 2);
                $div_members = [];
                $div_members[] = ['member_id' => $psreceiver['account'], 'amount' => sprintf('%.2f', $psmoney), 'fee_flag' => 'N'];
                if ($psmoney2 > 0) {
                    $div_members[] = ['member_id' => '0', 'amount' => sprintf('%.2f', $psmoney2), 'fee_flag' => 'Y'];
                } else {
                    $div_members[0]['fee_flag'] = 'Y';
                }
                $params['div_members'] = $div_members;
            } elseif ($psreceiver && $psreceiver['mode'] == 1) {
                $params['pay_mode'] = 'delay';
            }
        }
        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->createPayment($params);
            $this->updateOrder($tradeNo, $result['id']);
            return $result['expend'];
        });
    }

    //跳转支付创建订单
    private function pagepay(PaymentContext $ctx, string $func_code, string $pay_channel): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $client = $this->createClient();

        $params = [
            'adapay_func_code' => $func_code,
            'order_no' => $tradeNo,
            'pay_channel' => $pay_channel,
            'pay_amt' => $ctx->order['realmoney'],
            'goods_title' => $ctx->ordername,
            'goods_desc' => $ctx->ordername,
            'currency' => 'cny',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'callback_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];
        if ($ctx->order['profits'] > 0) {
            $params['pay_mode'] = 'delay';
        }

        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->queryAdapay($params);
            return $result['expend'];
        });
    }

    //收银台创建订单
    private function checkout(PaymentContext $ctx, string $pay_channel, ?string $member_id = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $client = $this->createClient();

        $params = [
            'adapay_func_code' => 'checkout',
            'order_no' => $tradeNo,
            'pay_channel' => $pay_channel,
            'pay_amt' => $ctx->order['realmoney'],
            'goods_title' => $ctx->ordername,
            'goods_desc' => $ctx->ordername,
            'currency' => 'cny',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'callback_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];
        if ($member_id) {
            $params['member_id'] = $member_id;
        }

        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->queryAdapay($params);
            return $result['expend'];
        });
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
            if (in_array('1', $this->channel['apptype']) && $ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            if (in_array('3', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
            } elseif (in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/quickpay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/unionpay/' . $tradeNo . '/'];
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if (in_array('1', $this->channel['apptype']) && $ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            if (in_array('3', $this->channel['apptype'])) {
                return $this->bank($ctx);
            } elseif (in_array('2', $this->channel['apptype'])) {
                return $this->quickpay($ctx);
            } else {
                return $this->unionpay($ctx);
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype']) || empty($this->channel['apptype'][0])) {
            try {
                $result = $this->addOrder($ctx, 'alipay_qr');
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
            }
            $code_url = $result['qrcode_url'];
        } elseif (in_array('2', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } elseif (in_array('3', $this->channel['apptype'])) {
            try {
                $result = $this->pagepay($ctx, 'prePay.preOrder', 'alipay_lite');
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
            }
            $code_url = $result['ali_h5_pay_url'];
            if ($ctx->mdevice === 'alipay') {
                return ['type' => 'jump', 'url' => $code_url];
            } elseif ($ctx->isMobile) {
                return ['type' => 'page', 'page' => 'alipay_h5', 'data' => ['code_url' => $code_url, 'redirect_url' => 'data.backurl']];
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
            $result = $this->addOrder($ctx, 'alipay_pub', $user_id);
            $payinfo = json_decode($result['pay_info'], true);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $payinfo['tradeNO']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $payinfo['tradeNO'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype'])) {
            try {
                $result = $this->pagepay($ctx, 'qrPrePay.qrPreOrder', '');
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $e->getMessage()];
            }
            $code_url = $result['qr_pay_url'];
        } elseif (in_array('3', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } else {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
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
            $result = $this->addOrder($ctx, $ctx->order['is_applet'] == 1 ? 'wx_lite' : 'wx_pub', $openid);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $e->getMessage()];
        }

        $jsApiParameters = $result['pay_info'];
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $jsApiParameters];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $jsApiParameters, 'redirect_url' => $redirect_url]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        if ($this->channel['appwxa'] > 0 && in_array('1', $this->channel['apptype'])) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } elseif (in_array('3', $this->channel['apptype'])) { //托管小程序支付
            try {
                $result = $this->pagepay($ctx, 'wxpay.createOrder', 'wx_lite');
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $e->getMessage()];
            }
            $code_url = $result['scheme_code'];
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } else {
            return $this->wxpay($ctx);
        }
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
            $result = $this->addOrder($ctx, 'wx_lite', $openid);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $e->getMessage()]];
        }

        $jsApiParameters = $result['pay_info'];
        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($jsApiParameters, true)]];
    }

    //云闪付扫码支付
    public function unionpay(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, 'union_qr');
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $e->getMessage()];
        }
        $code_url = $result['qrcode_url'];
        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //快捷支付
    public function quickpay(PaymentContext $ctx): array
    {
        $user_id = cookie('adapay_user_id');
        if (empty($user_id)) {
            $user_id = substr(getSid(), 0, 10);
            cookie('adapay_user_id', $user_id, ['expire' => 3600 * 24 * 365, 'path' => '/']);
        }
        try {
            $result = $this->checkout($ctx, 'fast_pay', $user_id);
            $code_url = $result['pay_url'];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '快捷支付下单失败！' . $e->getMessage()];
        }
        return ['type' => 'jump', 'url' => $code_url];
    }

    //网银支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $result = $this->checkout($ctx, 'online_pay');
            $code_url = $result['pay_url'];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '网银支付下单失败！' . $e->getMessage()];
        }
        return ['type' => 'jump', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $post = request()->post();
        if (!isset($post['sign']) || !isset($post['data'])) {
            return ['type' => 'html', 'data' => 'No'];
        }

        $client = $this->createClient();
        if ($client->verifySign($post['sign'], $post['data'])) {
            $_data = json_decode($post['data'], true);
            if ($_data['status'] == 'succeeded') {
                $api_trade_no = $_data['id'];
                $trade_no = $_data['order_no'];
                $orderAmount = $_data['pay_amt'];
                $buyer = $_data['expend']['sub_open_id'] ?? null;
                $bill_trade_no = $_data['out_trans_id'] ?? null;
                $bill_mch_trade_no = $_data['party_order_id'] ?? null;
                $end_time = $_data['end_time'] ?? null;
                if ($trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
                return ['type' => 'html', 'data' => 'Ok'];
            } else {
                return ['type' => 'html', 'data' => 'No'];
            }
        } else {
            return ['type' => 'html', 'data' => 'No'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        if (empty($order['api_trade_no'])) throw new \Exception('接口订单号不能为空');
        $client = $this->createClient();
        $result = $client->queryPayment($order['api_trade_no']);
        return [
            'api_trade_no' => $result['id'],
            'status' => $result['status'] == 'succeeded' ? 1 : 0,
            'money' => $result['pay_amt'],
            'buyer' => $result['open_id'] ?? '',
            'bill_trade_no' => $result['out_trans_id'] ?? '',
            'bill_mch_trade_no' => $result['party_order_id'] ?? '',
            'endtime' => $result['end_time'] ?? '',
        ];
    }

    //退款
    public function refund($order): array
    {
        $client = $this->createClient();
        $params = [
            'payment_id' => $order['api_trade_no'],
            'refund_order_no' => $order['refund_no'],
            'refund_amt' => $order['refundmoney'],
        ];
        if ($order['profits'] > 0) {
            $psorder = \app\logic\ProfitSharingLogic::getOrder($order['trade_no']);
            if ($psorder && ($psorder['status'] == 1 || $psorder['status'] == 2)) {
                $div_members = [];
                if ($psorder['rdata']) {
                    $allmoney = 0;
                    $leftmoney = (float)$order['refundmoney'];
                    foreach ($psorder['rdata'] as $receiver) {
                        $money = $receiver['money'] > $leftmoney ? $leftmoney : $receiver['money'];
                        $div_members[] = ['member_id' => $receiver['account'], 'amount' => sprintf('%.2f', $money)];
                        $allmoney += $receiver['money'];
                        $leftmoney = round($leftmoney - $money, 2);
                        if ($leftmoney <= 0) break;
                    }
                    if ($order['refundmoney'] > $allmoney && $leftmoney > 0) {
                        $psmoney2 = round($order['refundmoney'] - $allmoney, 2);
                        $psmoney2 = $psmoney2 > $leftmoney ? $leftmoney : $psmoney2;
                        $div_members[] = ['member_id' => '0', 'amount' => sprintf('%.2f', $psmoney2)];
                    }
                } else {
                    $amount = $psorder['money'] > $order['refundmoney'] ? $order['refundmoney'] : $psorder['money'];
                    $div_members[] = [
                        'member_id' => $psorder['account'],
                        'amount' => sprintf('%.2f', $amount),
                    ];
                    if ($order['refundmoney'] > $psorder['money']) {
                        $amount = round($order['refundmoney'] - $psorder['money'], 2);
                        $div_members[] = [
                            'member_id' => '0',
                            'amount' => sprintf('%.2f', $amount),
                        ];
                    }
                }
                $params['payment_id'] = $psorder['settle_no'];
                $params['div_members'] = $div_members;
            } else {
                //未分账时使用撤销
                $reverseParams = [
                    'payment_id' => $order['api_trade_no'],
                    'order_no' => $order['refund_no'],
                    'reverse_amt' => $order['refundmoney'],
                ];
                try {
                    $res = $client->createPaymentReverse($reverseParams);
                } catch (Exception $e) {
                    return ['code' => -1, 'msg' => $e->getMessage()];
                }
                if ($res['status'] == 'succeeded' || $res['status'] == 'pending') {
                    return ['code' => 0, 'trade_no' => $res['id']];
                } else {
                    return ['code' => -1, 'msg' => '[' . $res['error_code'] . ']' . $res['error_msg']];
                }
            }
        }
        try {
            $res = $client->createRefund($params);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        if ($res['status'] == 'succeeded' || $res['status'] == 'pending') {
            return ['code' => 0, 'trade_no' => $res['id'], 'refund_fee' => $res['refund_amt']];
        } else {
            return ['code' => -1, 'msg' => '[' . $res['error_code'] . ']' . $res['error_msg']];
        }
    }
}
