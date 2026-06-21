<?php

declare(strict_types=1);

namespace plugins\payment\jlpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class JlpayPlugin extends BasePayment
{
    private function createClient(): JlpayClient
    {
        return new JlpayClient(
            $this->channel['appid'],
            $this->channel['appkey'],
            $this->channel['appsecret'],
            $this->channel['appswitch']
        );
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
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
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
        $siteurl = request()->siteurl;

        if ($ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            } elseif ($ctx->order['typename'] == 'bank') {
                return $this->bankjs($ctx);
            }
        } elseif ($ctx->method == 'app' || $ctx->method == 'applet') {
            return $this->wxapppay($ctx);
        } elseif ($ctx->method == 'scan') {
            return $this->scanpay($ctx);
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return $this->wxjspay($ctx);
            } elseif ($ctx->isMobile && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //扫码支付
    private function qrcode(PaymentContext $ctx, string $pay_type): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'mch_id' => $this->channel['mch_id'],
            'term_no' => $this->channel['term_no'],
            'pay_type' => $pay_type,
            'out_trade_no' => $tradeNo,
            'body' => $ctx->ordername,
            'attach' => $ctx->order['name'],
            'total_fee' => strval(round($ctx->order['realmoney'] * 100)),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'mch_create_ip' => request()->clientip,
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('/open/trans/qrcodepay', $params);
            $this->updateOrder($tradeNo, $result['transaction_id']);
            return $result['code_url'];
        });
    }

    //微信公众号/小程序支付
    private function officialpay(PaymentContext $ctx, string $openid, string $appid): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'mch_id' => $this->channel['mch_id'],
            'term_no' => $this->channel['term_no'],
            'pay_type' => 'wxpay',
            'open_id' => $openid,
            'sub_appid' => $appid,
            'out_trade_no' => $tradeNo,
            'body' => $ctx->ordername,
            'attach' => $ctx->order['name'],
            'total_fee' => strval(round($ctx->order['realmoney'] * 100)),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'mch_create_ip' => request()->clientip,
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('/open/trans/officialpay', $params);
            $this->updateOrder($tradeNo, $result['transaction_id']);
            return $result['pay_info'];
        });
    }

    //支付宝服务窗/小程序支付
    private function waph5pay(PaymentContext $ctx, string $buyer_id): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'mch_id' => $this->channel['mch_id'],
            'term_no' => $this->channel['term_no'],
            'pay_type' => 'alipay',
            'buyer_id' => $buyer_id,
            'out_trade_no' => $tradeNo,
            'body' => $ctx->ordername,
            'attach' => $ctx->order['name'],
            'total_fee' => strval(round($ctx->order['realmoney'] * 100)),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'mch_create_ip' => request()->clientip,
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('/open/trans/waph5pay', $params);
            $this->updateOrder($tradeNo, $result['transaction_id']);
            return $result['pay_info'];
        });
    }

    //银联行业码支付
    private function unionjspay(PaymentContext $ctx, string $user_id): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'mch_id' => $this->channel['mch_id'],
            'term_no' => $this->channel['term_no'],
            'pay_type' => 'unionpay',
            'app_up_identifier' => get_unionpay_ua(),
            'user_auth_code' => session('unionpay_auth_code'),
            'user_id' => $user_id,
            'out_trade_no' => $tradeNo,
            'body' => $ctx->ordername,
            'attach' => $ctx->order['name'],
            'total_fee' => strval(round($ctx->order['realmoney'] * 100)),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'mch_create_ip' => request()->clientip,
            'qr_code' => $siteurl,
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('/open/trans/unionjspay', $params);
            $this->updateOrder($tradeNo, $result['transaction_id']);
            return $result['pay_info'];
        });
    }

    //收银托管
    private function cashierpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'merch_no' => $this->channel['mch_id'],
            'term_no' => $this->channel['term_no'],
            'out_trade_no' => $tradeNo,
            'description' => $ctx->ordername,
            'attach' => $ctx->order['name'],
            'product_name' => $ctx->order['name'],
            'total_amount' => strval(round($ctx->order['realmoney'] * 100)),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->execute('/open/cashier/trans/trade/pre-order', $params);
            return $result;
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $code_url = $this->qrcode($ctx, 'alipay');
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

        if (!empty($ctx->order['sub_openid'])) {
            $user_id = $ctx->order['sub_openid'];
            $user_type = null;
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }

        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;

        if ($user_type == 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $result = $this->waph5pay($ctx, $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        $payinfo = json_decode($result, true);
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $payinfo['tradoNo']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $payinfo['tradoNo'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            if ($this->channel['appwxmp'] > 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
        } else {
            try {
                $code_url = $this->qrcode($ctx, 'wxpay');
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
            $payinfo = $this->officialpay($ctx, $openid, $wxinfo['appid']);
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
        $code = trim($code);

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
            $payinfo = $this->officialpay($ctx, $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($payinfo, true)]];
    }

    //微信APP支付
    public function wxapppay(PaymentContext $ctx): array
    {
        try {
            $result = $this->cashierpay($ctx);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => $result['jl_pay_appid'], 'miniProgramId' => $result['gh_id'], 'path' => $result['cashier_url']]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, 'unionpay');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'unionpay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
        }
    }

    //云闪付JS支付
    public function bankjs(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->unionjspay($ctx, $ctx->order['sub_openid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    //获取银联用户ID
    public function get_unionpay_userid(string $userAuthCode): array
    {
        $params = [
            'mch_id' => $this->channel['mch_id'],
            'pay_type' => 'unionpay',
            'auth_code' => $userAuthCode,
            'app_up_identifier' => get_unionpay_ua(),
        ];

        $client = $this->createClient();
        try {
            $result = $client->execute('/open/trans/getopenid', $params);
            session('unionpay_auth_code', $userAuthCode);
            return ['code' => 0, 'data' => $result['user_id']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //付款码支付
    public function scanpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'mch_id' => $this->channel['mch_id'],
            'term_no' => $this->channel['term_no'],
            'out_trade_no' => $tradeNo,
            'body' => $ctx->ordername,
            'attach' => $ctx->order['name'],
            'total_fee' => strval(round($ctx->order['realmoney'] * 100)),
            'auth_code' => $ctx->order['auth_code'],
            'mch_create_ip' => request()->clientip,
        ];

        try {
            $client = $this->createClient();
            $result = $client->execute('/open/trans/micropay', $params);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '被扫下单失败！' . $e->getMessage()];
        }

        if ($result['status'] == '2') {
            $bill_trade_no = $result['chn_transaction_id'] ?? '';
            if ($ctx->order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
            $this->processNotify($this->getOrder($tradeNo), $result['transaction_id'], $result['sub_openid'], $bill_trade_no);
            return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['out_trade_no'], 'api_trade_no' => $result['transaction_id'], 'buyer' => $result['sub_openid'], 'money' => strval(round($result['total_fee'] / 100, 2))]];
        } else {
            $transaction_id = $result['transaction_id'];
            $retry = 0;
            $success = false;
            while ($retry < 6) {
                sleep(3);
                try {
                    $result = $this->orderQuery($transaction_id);
                } catch (Exception $e) {
                    return ['type' => 'error', 'msg' => '订单查询失败:' . $e->getMessage()];
                }
                if ($result['status'] == '2') {
                    $success = true;
                    break;
                } elseif ($result['tranSts'] != '1') {
                    return ['type' => 'error', 'msg' => '订单超时或用户取消支付'];
                }
                $retry++;
            }
            if ($success) {
                $bill_trade_no = $result['chn_transaction_id'] ?? '';
                if ($ctx->order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
                $this->processNotify($this->getOrder($tradeNo), $result['transaction_id'], $result['sub_openid'], $bill_trade_no);
                return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['out_trade_no'], 'api_trade_no' => $result['transaction_id'], 'buyer' => $result['sub_openid'], 'money' => strval(round($result['total_fee'] / 100, 2))]];
            } else {
                try {
                    $this->orderClose($transaction_id);
                } catch (Exception $e) {
                }
                return ['type' => 'error', 'msg' => '被扫下单失败！订单已超时'];
            }
        }
    }

    private function orderQuery(string $transaction_id): array
    {
        $client = $this->createClient();
        $params = [
            'mch_id' => $this->channel['mch_id'],
            'transaction_id' => $transaction_id,
        ];
        return $client->execute('/open/trans/chnquery', $params);
    }

    private function orderClose(string $transaction_id): array
    {
        $client = $this->createClient();
        $params = [
            'mch_id' => $this->channel['mch_id'],
            'out_trade_no' => date('YmdHis') . rand(1000, 9999),
            'ori_transaction_id' => $transaction_id,
            'mch_create_ip' => request()->clientip,
        ];
        return $client->execute('/open/trans/cancel', $params);
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'json', 'data' => ['ret_code' => '00002', 'ret_msg' => 'no data']];

        $client = $this->createClient();
        $verify_result = $client->verifyNotify($json);

        if ($verify_result) { //验证成功
            if ($arr['status'] == '2') {
                $out_trade_no = $arr['out_trade_no'];
                $api_trade_no = $arr['transaction_id'];
                $buyer = $arr['sub_openid'] ?? '';
                $bill_trade_no = $arr['chn_transaction_id'] ?? '';
                if ($ctx->order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
                $end_time = $arr['pay_time'] ?? '';
                if ($out_trade_no == $tradeNo) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                }
            }
            return ['type' => 'json', 'data' => ['ret_code' => '00000']];
        } else {
            return ['type' => 'json', 'data' => ['ret_code' => '00001', 'ret_msg' => 'sign fail']];
        }
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
            'mch_id' => $this->channel['mch_id'],
            'out_trade_no' => $order['trade_no'],
        ];
        $result = $client->execute('/open/trans/chnquery', $params);
        $bill_trade_no = $result['chn_transaction_id'] ?? '';
        if ($order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
        return [
            'api_trade_no' => $result['transaction_id'],
            'status' => $result['status'] == '2' ? 1 : 0,
            'money' => $result['total_fee'] / 100,
            'buyer' => $result['sub_openid'] ?? '',
            'bill_trade_no' => $bill_trade_no,
            'endtime' => $result['pay_time'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'mch_id' => $this->channel['mch_id'],
            'out_trade_no' => $order['refund_no'],
            'ori_transaction_id' => $order['api_trade_no'],
            'total_fee' => strval(round($order['refundmoney'] * 100)),
            'mch_create_ip' => request()->clientip,
        ];

        try {
            $client = $this->createClient();
            $result = $client->execute('/open/trans/refund', $params);
            return ['code' => 0, 'trade_no' => $result['transaction_id'], 'refund_fee' => $result['total_fee'] / 100];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }
}
