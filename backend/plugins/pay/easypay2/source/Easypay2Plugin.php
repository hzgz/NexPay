<?php

declare(strict_types=1);

namespace plugins\payment\easypay2;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class Easypay2Plugin extends BasePayment
{
    private function createClient(): EasypayClient
    {
        return new EasypayClient(
            $this->channel['appid'],
            $this->channel['appkey'],
            $this->channel['appsecret']
        );
    }

    //扫码支付接口
    private function qrcode_pay(PaymentContext $ctx, string $pay_type): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'merchant_id' => $this->channel['appmchid'],
            'out_trade_no' => $tradeNo,
            'amount' => intval(round($ctx->order['realmoney'] * 100)),
            'subject' => $ctx->ordername,
            'pay_type' => $pay_type,
            'business_time' => date('Y-m-d H:i:s'),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        $client = $this->createClient();
        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('easypay.qrcode.pay.push', $params);
            $this->updateOrder($tradeNo, $result['order_no']);
            return $result['code_url'];
        });
    }

    //JSAPI支付接口
    private function jsapi_pay(PaymentContext $ctx, string $pay_type, string $openid, ?string $appid = null): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'merchant_id' => $this->channel['appmchid'],
            'out_trade_no' => $tradeNo,
            'amount' => intval(round($ctx->order['realmoney'] * 100)),
            'subject' => $ctx->ordername,
            'pay_type' => $pay_type,
            'open_id' => $openid,
            'business_time' => date('Y-m-d H:i:s'),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        $client = $this->createClient();
        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('easypay.js.pay.push', $params);
            $this->updateOrder($tradeNo, $result['order_no']);
            return $result['pay_info'];
        });
    }

    //收银台支付
    private function cashier(PaymentContext $ctx, bool $is_h5 = false): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'merchant_id' => $this->channel['appmchid'],
            'out_trade_no' => $tradeNo,
            'bank_code' => 'EASYPAY',
            'account_type' => '1',
            'amount' => intval(round($ctx->order['realmoney'] * 100)),
            'subject' => $ctx->ordername,
            'body' => $ctx->order['name'],
            'front_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        $client = $this->createClient();
        $html_text = $client->submit($is_h5 ? 'easypay.merchant.easyPayh5' : 'easypay.merchant.easyPay', $params);
        return ['type' => 'html', 'data' => $html_text];
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
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
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

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $code_url = $this->qrcode_pay($ctx, 'aliPay');
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
            $payinfo = $this->jsapi_pay($ctx, 'aliJsPay', $user_id);
            $payinfo = json_decode($payinfo, true);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $payinfo['alipayTradeNo']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $payinfo['alipayTradeNo'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            if ($this->channel['appwxa'] > 0 && $this->channel['appwxmp'] == 0) {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            }
        } else {
            try {
                $code_url = $this->qrcode_pay($ctx, 'wxNative');
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

        //②、统一下单
        try {
            $payinfo = $this->jsapi_pay($ctx, 'wxJsPay', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $payinfo];
        }

        if (request()->get('d') == '1') {
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
            $payinfo = $this->jsapi_pay($ctx, 'wxJsPay', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($payinfo, true)]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode_pay($ctx, 'unionNative');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $biz_content = request()->post('biz_content');
        if (!$biz_content) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        $verify_result = $client->verifySign($biz_content, request()->post('sign'));

        if ($verify_result) {
            $data = json_decode($biz_content, true);
            if ($data['trade_status'] == 'TRADE_FINISHED') {
                $out_trade_no = $data['out_trade_no'];
                $api_trade_no = $data['trade_no'];
                $buyer = $data['buyer_id'] ?? null;
                $bill_trade_no = $data['ref_no'] ?? null;
                $end_time = $data['gmt_payment'] ?? null;
                if ($out_trade_no == $ctx->order['trade_no']) {
                    ($this->markTrustedCallback($ctx, 'notify', 'easypay2-signature'))(function () use ($ctx, $api_trade_no, $buyer, $bill_trade_no, $end_time) {
                        $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                    });
                }
            }
            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            return ['type' => 'html', 'data' => 'FAIL'];
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
            'merchant_id' => $this->channel['appmchid'],
            'out_trade_no' => $order['trade_no'],
        ];
        $result = $client->execute('easypay.merchant.query', $params);
        return [
            'api_trade_no' => $result['trade_no'],
            'status' => $result['trade_status'] == 'SUCCESS' ? 1 : 0,
            'money' => $result['amount'] / 100,
            'bill_trade_no' => $result['ref_no'] ?? null,
            'endtime' => $result['success_time'] ?? null,
        ];
    }

    //退款
    public function refund($order): array
    {
        $client = $this->createClient();

        $params = [
            'merchant_id' => $this->channel['appmchid'],
            'out_trade_no' => $order['refund_no'],
            'origin_trade_no' => $order['trade_no'],
            'refund_amount' => intval(round($order['refundmoney'] * 100)),
        ];

        try {
            $client->execute('easypay.merchant.refund', $params);
            return ['code' => 0];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账
    public function transfer(array $bizParam): array
    {
        $client = $this->createClient();

        $params = [
            'merchant_id' => $this->channel['appmchid'],
            'out_trade_no' => $bizParam['out_biz_no'],
            'acc' => $bizParam['payee_account'],
            'name' => $bizParam['payee_real_name'],
            'bis_type' => '1',
            'amount' => intval(round($bizParam['money'] * 100)),
            'acc_type' => '2',
            'remark' => $bizParam['transfer_desc'],
            'notify_url' => config_get('localurl') . 'pay/transfernotify/' . $this->channel['id'] . '/',
        ];

        try {
            $result = $client->execute('trade.acc.dsfpay.newPay', $params);
            return ['code' => 0, 'status' => 0, 'orderid' => $result['out_trade_no'], 'paydate' => date('Y-m-d H:i:s')];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账查询
    public function transfer_query(array $bizParam): array
    {
        $client = $this->createClient();

        $params = [
            'merchant_id' => $this->channel['appmchid'],
            'out_trade_no' => $bizParam['out_biz_no'],
        ];
        try {
            $result = $client->execute('trade.acc.dsfpay.query', $params);
            $errmsg = '';
            if ($result['trade_status'] == 'SUCCESS') {
                $status = 1;
            } elseif ($result['trade_status'] == 'FAIL') {
                $status = 2;
                $errmsg = $result['msg'] ?? '';
            } else {
                $status = 0;
            }
            return ['code' => 0, 'status' => $status, 'errmsg' => $errmsg];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //电子回单
    public function transfer_proof(array $bizParam): array
    {
        $client = $this->createClient();

        $params = [
            'merchant_id' => $this->channel['appmchid'],
            'out_trade_no' => $bizParam['out_biz_no'],
        ];
        try {
            $result = $client->execute('trade.acc.dsfpay.getReceipt', $params);
            file_put_contents(UPLOAD_ROOT . 'bill/' . $bizParam['orderid'] . '.pdf', base64_decode($result['receipt_string']));
            $image = '/upload/bill/' . $bizParam['orderid'] . '.pdf';
            return ['code' => 0, 'msg' => '电子回单生成成功！', 'download_url' => $image];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //余额查询
    public function balance_query(array $bizParam): array
    {
        $client = $this->createClient();

        $params = [
            'merchant_id' => $this->channel['appmchid'],
        ];
        try {
            $result = $client->execute('trade.acc.balance', $params);
            return ['code' => 0, 'amount' => $result['total_cash_acc_balance'] / 100, 'msg' => '结算账户余额：' . ($result['total_settle_acc_balance'] / 100) . ' 元，现金账户余额：' . ($result['total_cash_acc_balance'] / 100) . ' 元'];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //付款异步回调
    public function transfernotify(PaymentContext $ctx): array
    {
        $post = request()->post();
        if (!isset($post['biz_content'])) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        $verify_result = $client->verifySign($post['biz_content'], $post['sign'] ?? '');

        if ($verify_result) {
            $data = json_decode($post['biz_content'], true);
            if ($data['trade_status'] == 'SUCCESS') {
                $status = 1;
            } elseif ($data['trade_status'] == 'FAIL') {
                $status = 2;
            } else {
                $status = 0;
            }
            ($this->markTrustedCallback($ctx, 'transfernotify', 'easypay2-signature'))(function () use ($data, $status) {
                $this->processTransfer($data['out_trade_no'], $status);
            });
            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            return ['type' => 'html', 'data' => 'FAIL'];
        }
    }
}
