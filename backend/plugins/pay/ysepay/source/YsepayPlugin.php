<?php

declare(strict_types=1);

namespace plugins\payment\ysepay;

use app\common\PaymentContext;
use app\common\BasePayment;

class YsepayPlugin extends BasePayment
{
    private function getClient(): YsepayClient
    {
        $certFilePath = getCertFilePath($this->channel['cert_pfx'] ?? '');
        return new YsepayClient($this->channel['appid'], $this->channel['appkey'], $certFilePath);
    }

    private function getSellerId(): string
    {
        return $this->channel['appmchid'] ? $this->channel['appmchid'] : $this->channel['appid'];
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && ($this->channel['appwxa'] > 0)) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'qqpay') {
            return ['type' => 'jump', 'url' => '/pay/qqpay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'bank') {
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
        }
        return ['type' => 'jump', 'url' => '/pay/' . $ctx->order['typename'] . '/' . $tradeNo . '/'];
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
        } elseif ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && ($this->channel['appwxa'] > 0)) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'qqpay') {
            return $this->qqpay($ctx);
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //扫码支付
    private function qrcode(string $bank_type, PaymentContext $ctx): string
    {
        $seller_id = $this->getSellerId();
        $method = 'ysepay.online.qrcodepay';
        $params = [
            'out_trade_no' => $ctx->order['trade_no'],
            'shopdate' => date("Ymd"),
            'subject' => $ctx->ordername,
            'total_amount' => $ctx->order['realmoney'],
            'currency' => 'CNY',
            'seller_id' => $seller_id,
            'timeout_express' => '2h',
            'business_code' => $this->channel['appurl'],
            'bank_type' => $bank_type,
            'submer_ip' => request()->clientip,
        ];

        $client = $this->getClient();
        $client->notifyUrl = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';
        $result = $client->execute($method, $params);
        return $result['source_qr_code_url'];
    }

    //微信公众号小程序支付
    private function weixinpay(string $appid, string $openid, PaymentContext $ctx, string $isminipg = '2'): string
    {
        $seller_id = $this->getSellerId();
        $method = 'ysepay.online.weixin.pay';
        $params = [
            'out_trade_no' => $ctx->order['trade_no'],
            'shopdate' => date("Ymd"),
            'subject' => $ctx->ordername,
            'total_amount' => $ctx->order['realmoney'],
            'currency' => 'CNY',
            'seller_id' => $seller_id,
            'timeout_express' => '2h',
            'business_code' => $this->channel['appurl'],
            'appid' => $appid,
            'sub_openid' => $openid,
            'is_minipg' => $isminipg,
            'payer_ip' => request()->clientip,
        ];

        $client = $this->getClient();
        $client->notifyUrl = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';

        $result = $client->execute($method, $params);
        return $result['jsapi_pay_info'];
    }

    //支付宝生活号支付
    private function alijsapipay(string $buyer_id, PaymentContext $ctx): string
    {
        $seller_id = $this->getSellerId();
        $method = 'ysepay.online.alijsapi.pay';
        $params = [
            'out_trade_no' => $ctx->order['trade_no'],
            'shopdate' => date("Ymd"),
            'subject' => $ctx->ordername,
            'total_amount' => $ctx->order['realmoney'],
            'currency' => 'CNY',
            'seller_id' => $seller_id,
            'timeout_express' => '2h',
            'business_code' => $this->channel['appurl'],
            'buyer_id' => $buyer_id,
            'payer_ip' => request()->clientip,
        ];

        $client = $this->getClient();
        $client->notifyUrl = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';

        $result = $client->execute($method, $params);
        return $result['jsapi_pay_info'];
    }

    //银联行业码支付
    private function cupmulapppay(string $buyer_id, PaymentContext $ctx): string
    {
        $seller_id = $this->getSellerId();
        $method = 'ysepay.online.cupmulapp.qrcodepay';
        $params = [
            'out_trade_no' => $ctx->order['trade_no'],
            'shopdate' => date("Ymd"),
            'subject' => $ctx->ordername,
            'total_amount' => $ctx->order['realmoney'],
            'currency' => 'CNY',
            'seller_id' => $seller_id,
            'timeout_express' => '2h',
            'business_code' => $this->channel['appurl'],
            'spbill_create_ip' => request()->clientip,
            'bank_type' => '9001002',
            'userId' => $buyer_id,
        ];

        $client = $this->getClient();
        $client->notifyUrl = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';

        $result = $client->execute($method, $params);
        return $result['web_url'];
    }

    //WAP支付
    private function wappay(string $bank_type, PaymentContext $ctx): string
    {
        $seller_id = $this->getSellerId();
        $method = 'ysepay.online.wap.directpay.createbyuser';
        $params = [
            'out_trade_no' => $ctx->order['trade_no'],
            'shopdate' => date("Ymd"),
            'subject' => $ctx->ordername,
            'total_amount' => $ctx->order['realmoney'],
            'seller_id' => $seller_id,
            'timeout_express' => '7d',
            'business_code' => $this->channel['appurl'],
            'pay_mode' => 'native',
            'bank_type' => $bank_type,
        ];

        $client = $this->getClient();
        $client->notifyUrl = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';
        $client->returnUrl = request()->siteurl . 'pay/return/' . $ctx->order['trade_no'] . '/';

        $html = $client->pageExecute($method, $params);
        return $html;
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $apptype = $this->channel['apptype'] ?? [];
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $apptype) && $ctx->isMobile) {
            try {
                $html = $this->wappay('1903000', $ctx);
            } catch (\Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'html', 'data' => $html];
        } elseif (in_array('1', $apptype)) {
            try {
                $code_url = $this->qrcode('1903000', $ctx);
            } catch (\Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('3', $apptype)) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            $code_url = $siteurl . 'pay/alipay/' . $tradeNo . '/';
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

        if (!empty($ctx->order['sub_openid'])) {
            $user_id = $ctx->order['sub_openid'];
            $user_type = 'userid';
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }

        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;
        if ($user_type == 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $result = $this->alijsapipay($user_id, $ctx);
            $trade_no = json_decode($result, true)['tradeNO'];
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $trade_no];
        }

        if (request()->get('d') === '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $trade_no, 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $apptype = $this->channel['apptype'] ?? [];
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $apptype) && !in_array('1', $apptype)) {
            if ($this->channel['appwxmp'] > 0 && $this->channel['appwxa'] == 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
        } else {
            try {
                $code_url = $this->qrcode('1902000', $ctx);
            } catch (\Exception $ex) {
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

    //QQ扫码支付
    public function qqpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode('1904000', $ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => 'QQ钱包支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'qq') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile && !request()->get('qrcode')) {
            return ['type' => 'qrcode', 'page' => 'qqpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'qqpay_qrcode', 'url' => $code_url];
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
            $jsApiParameters = $this->weixinpay($wxinfo['appid'], $openid, $ctx, $ctx->order['is_applet'] == 1 ? '1' : '2');
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $jsApiParameters];
        }

        if (request()->get('d') === '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $jsApiParameters, 'redirect_url' => $redirect_url]];
    }

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $code = request()->get('code', '', 'trim');
        if ($code === '') {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }

        //①、获取用户openid
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];
        }
        try {
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (\Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        //②、统一下单
        try {
            $jsApiParameters = $this->weixinpay($wxinfo['appid'], $openid, $ctx, '1');
        } catch (\Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($jsApiParameters, true)]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
        try {
            $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
        } catch (\Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode('9001002', $ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'unionpay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
        }
    }

    public function bankjs(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->cupmulapppay($ctx->order['sub_openid'], $ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    public function get_unionpay_userid(string $userAuthCode): array
    {
        $client = $this->getClient();

        $params = [
            'authCode' => $userAuthCode,
            'appUpIdentifier' => get_unionpay_ua(),
        ];

        try {
            $result = $client->execute('ysepay.online.cupgetmulapp.userid', $params);
            return ['code' => 0, 'data' => $result['userId']];
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        //计算得出通知验证结果
        $client = $this->getClient();
        $verify_result = $client->verify(request()->post());

        if ($verify_result) { //验证成功
            $out_trade_no = request()->post('out_trade_no');
            $trade_no = request()->post('trade_no');
            $buyer_id = request()->post('buyer_user_id');
            $total_amount = request()->post('total_amount');
            $bill_trade_no = request()->post('channel_recv_sn');
            $bill_mch_trade_no = request()->post('channel_send_sn');
            if ($ctx->order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) {
                $bill_trade_no = substr($bill_trade_no, 2);
            }

            if (request()->post('trade_status') == 'TRADE_SUCCESS') {
                if ($out_trade_no == $ctx->order['trade_no'] && round((float)$total_amount, 2) == round((float)$ctx->order['realmoney'], 2)) {
                    ($this->markTrustedCallback($ctx, 'notify', 'ysepay-signature'))(function () use ($ctx, $trade_no, $buyer_id, $bill_trade_no, $bill_mch_trade_no) {
                        $this->processNotify($ctx->order, $trade_no, $buyer_id, $bill_trade_no, $bill_mch_trade_no);
                    });
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        //计算得出通知验证结果
        $client = $this->getClient();
        $verify_result = $client->verify(request()->get());

        if ($verify_result) { //验证成功
            $out_trade_no = request()->get('out_trade_no');
            $trade_no = request()->get('trade_no');
            $total_amount = request()->get('total_amount');

            if (request()->get('trade_status') == 'TRADE_SUCCESS') {
                if ($out_trade_no == $ctx->order['trade_no'] && round((float)$total_amount, 2) == round((float)$ctx->order['realmoney'], 2)) {
                    return ($this->markTrustedCallback($ctx, 'return', 'ysepay-signature'))(function () use ($ctx, $trade_no) {
                        return $this->processReturn($ctx->order, $trade_no);
                    });
                } else {
                    return ['type' => 'error', 'msg' => '订单信息校验失败'];
                }
            } else {
                return ['type' => 'error', 'msg' => 'trade_status=' . request()->get('trade_status')];
            }
        } else {
            //验证失败
            return ['type' => 'error', 'msg' => '返回验证失败'];
        }
    }

    public function query(array $order): array
    {
        $client = $this->getClient();
        $seller_id = $this->getSellerId();
        $params = [
            'out_trade_no' => $order['trade_no'],
            'shopdate' => date("Ymd", strtotime($order['addtime'])),
            'seller_id' => $seller_id,
        ];
        $result = $client->execute('ysepay.online.trade.order.query', $params);
        $detail = $result['pay_detail_list'][0] ?? [];
        $bill_trade_no = $detail['channel_recv_sn'] ?? '';
        if ($order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) {
            $bill_trade_no = substr($bill_trade_no, 2);
        }
        return [
            'api_trade_no' => $result['trade_no'],
            'status' => $result['trade_status'] == 'TRADE_SUCCESS' ? 1 : 0,
            'money' => $result['total_amount'],
            'buyer' => $detail['buyer_user_id'] ?? '',
            'bill_trade_no' => $bill_trade_no,
            'bill_mch_trade_no' => $detail['channel_send_sn'] ?? '',
            'endtime' => $detail['pay_success_time'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $method = 'ysepay.online.trade.refund';
        $params = [
            'out_trade_no' => $order['trade_no'],
            'shopdate' => date("Ymd"),
            'trade_no' => $order['api_trade_no'],
            'refund_amount' => $order['refundmoney'],
            'refund_reason' => '申请退款',
            'out_request_no' => $order['refund_no'],
        ];

        try {
            $client = $this->getClient();
            $result = $client->execute($method, $params);
            return ['code' => 0, 'trade_no' => $result['trade_no'], 'refund_fee' => $result['refund_amount']];
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //进件通知
    public function applynotify(PaymentContext $ctx): array
    {
        $json = file_get_contents("php://input");
        $data = json_decode($json, true);
        if (!$data) {
            return ['type' => 'html', 'data' => 'fail'];
        }

        //计算得出通知验证结果
        $client = $this->getClient();
        $verify_result = $client->verify2($data);

        if ($verify_result) { //验证成功
            $arr = json_decode($data['bizContent'], true);

            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->notify($arr);

            return ['type' => 'html', 'data' => 'success'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'fail'];
        }
    }
}
