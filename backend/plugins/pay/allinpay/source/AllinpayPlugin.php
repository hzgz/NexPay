<?php

declare(strict_types=1);

namespace plugins\payment\allinpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class AllinpayPlugin extends BasePayment
{
    private function createClient(): AllinpayClient
    {
        return new AllinpayClient(
            $this->channel['orgid'] ?? '',
            $this->channel['appmchid'],
            $this->channel['appid'],
            $this->channel['appkey'],
            $this->channel['appsecret']
        );
    }

    //统一支付接口
    private function addOrder(PaymentContext $ctx, string $paytype, ?string $sub_appid = null, ?string $openid = null): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $apiurl = 'https://vsp.allinpay.com/apiweb/unitorder/pay';

        $params = [
            'trxamt' => strval($ctx->order['realmoney'] * 100),
            'reqsn' => $tradeNo,
            'paytype' => $paytype,
            'body' => $ctx->ordername,
            'validtime' => '30',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'cusip' => request()->clientip,
        ];
        if ($sub_appid) $params['sub_appid'] = $sub_appid;
        if ($openid) {
            $params['acct'] = $openid;
            $params['front_url'] = request()->siteurl . 'pay/return/' . $tradeNo . '/';
        }
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx->order);
        }

        $client = $this->createClient();
        $result = $client->submit($apiurl, $params);
        if ($result['trxstatus'] == '0000') {
            return $result['payinfo'];
        } else {
            throw new Exception($result['errmsg']);
        }
    }

    //H5收银台
    private function cashier(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $apiurl = 'https://syb.allinpay.com/apiweb/h5unionpay/unionorder';

        $params = [
            'trxamt' => strval($ctx->order['realmoney'] * 100),
            'reqsn' => $tradeNo,
            'body' => $ctx->ordername,
            'validtime' => '30',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returl' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'charset' => 'UTF-8',
        ];
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx->order);
        }

        $client = $this->createClient();
        $data = $client->cashier($params);

        $html_text = '<form action="' . $apiurl . '" method="post" id="dopay">';
        foreach ($data as $k => $v) {
            $html_text .= "<input type=\"hidden\" name=\"{$k}\" value=\"{$v}\" />\n";
        }
        $html_text .= '<input type="submit" value="正在跳转"></form><script>document.getElementById("dopay").submit();</script>';

        return ['type' => 'html', 'data' => $html_text];
    }

    //小程序收银台
    private function applet(PaymentContext $ctx, string $paytype): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'trxamt' => strval($ctx->order['realmoney'] * 100),
            'reqsn' => $tradeNo,
            'paytype' => $paytype,
            'body' => $ctx->ordername,
            'validtime' => '30',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx->order);
        }

        $client = $this->createClient();
        return $client->cashier($params);
    }

    private function handleProfits(array &$params, array $order): void
    {
        $psreceiver = \app\logic\ProfitSharingLogic::getReceiver($order['profits']);
        if ($psreceiver) {
            $asinfo = '';
            foreach ($psreceiver['info'] as $receiver) {
                $psmoney = round(floor($order['realmoney'] * $receiver['rate']));
                $asinfo .= $receiver['account'] . ':01:' . $psmoney . ';';
            }
            $asinfo = rtrim($asinfo, ';');
            $params['asinfo'] = $asinfo;
        }
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
        } elseif ($ctx->order['typename'] == 'qqpay') {
            return ['type' => 'jump', 'url' => '/pay/qqpay/' . $tradeNo . '/'];
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
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
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

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = request()->siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $code_url = $this->addOrder($ctx, 'A01');
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
            $alipay_trade_no = $this->addOrder($ctx, 'A02', null, $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $alipay_trade_no];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $alipay_trade_no, 'redirect_url' => $redirect_url]];
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
                $code_url = $this->addOrder($ctx, 'W01');
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
        $tradeNo = $ctx->order['trade_no'];

        if ($this->channel['appwxa'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } elseif ($this->channel['appwxmp'] > 0) {
            $code_url = request()->siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return $this->wxpay($ctx);
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
            $payinfo = $this->addOrder($ctx, $ctx->order['is_applet'] == 1 ? 'W06' : 'W02', $wxinfo['appid'], $openid);
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
            $payinfo = $this->addOrder($ctx, 'W06', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($payinfo, true)]];
    }

    //QQ扫码支付
    public function qqpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'Q01');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => 'QQ钱包支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'qq') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile && request()->get('qrcode') === null) {
            return ['type' => 'qrcode', 'page' => 'qqpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'qqpay_qrcode', 'url' => $code_url];
        }
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'U01');
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
            $code_url = $this->addOrder($ctx, 'U02', null, $ctx->order['sub_openid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    //获取云闪付用户ID
    public function get_unionpay_userid(string $userAuthCode): array
    {
        $apiurl = 'https://vsp.allinpay.com/apiweb/unitorder/authcodetouserid';
        $params = [
            'authcode' => $userAuthCode,
            'authtype' => '02',
            'identify' => get_unionpay_ua(),
        ];

        $client = $this->createClient();
        try {
            $result = $client->submit($apiurl, $params);
            return ['code' => 0, 'data' => $result['acct']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $client = $this->createClient();
        $verify_result = $client->verifySign(request()->post());

        if ($verify_result) {
            $trxstatus = request()->post('trxstatus');
            if ($trxstatus == '0000') {
                $out_trade_no = request()->post('cusorderid');
                $api_trade_no = request()->post('trxid');
                $money = request()->post('initamt');
                $buyer = request()->post('acct');
                $bill_trade_no = request()->post('chnltrxid');
                $end_time = request()->post('paytime');
                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                } else {
                    return ['type' => 'html', 'data' => 'fail'];
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $apiurl = 'https://vsp.allinpay.com/apiweb/tranx/query';
        $params = [
            'reqsn' => $order['trade_no'],
        ];
        $client = $this->createClient();
        $result = $client->submit($apiurl, $params);
        return [
            'api_trade_no' => $result['trxid'],
            'status' => $result['trxstatus'] == '0000' ? 1 : 0,
            'money' => $result['trxamt'],
            'buyer' => $result['acct'] ?? '',
            'bill_trade_no' => $result['chnltrxid'] ?? '',
            'bill_mch_trade_no' => $result['partyorderid'] ?? '',
            'endtime' => $result['fintime'] ?? '',
        ];
    }

    //退款
    public function refund($order): array
    {
        $apiurl = 'https://vsp.allinpay.com/apiweb/tranx/refund';

        $params = [
            'trxamt' => strval($order['refundmoney'] * 100),
            'reqsn' => $order['refund_no'],
            'oldtrxid' => $order['api_trade_no'],
        ];

        try {
            $client = $this->createClient();
            $result = $client->submit($apiurl, $params);
            if ($result['trxstatus'] != '0000' && $result['trxstatus'] != '2008' && $result['trxstatus'] != '2000') {
                return ['code' => -1, 'msg' => $result['errmsg']];
            }
            return ['code' => 0, 'trade_no' => $result['trxid'], 'refund_fee' => $result['fee']];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //投诉通知
    public function complainnotify(PaymentContext $ctx): array
    {
        $client = $this->createClient();
        $verify_result = $client->verifySign(request()->post());

        if ($verify_result) {
            $data = json_decode(request()->post('resource', ''), true);
            if (request()->post('risktype') == 'VIOLATION') {
                if (class_exists('\\app\\lib\\WxMchRisk')) {
                    $model = new \app\lib\WxMchRisk($this->channel);
                    $model->notify($data);
                }
            } elseif (request()->post('risktype') == 'COMPLAINT') {
                $model = \app\logic\ComplainLogic::getModel($this->channel);
                $model->refreshNewInfo($data['complaint_id'], $data['action_type']);
            } elseif (request()->post('risktype') == 'COMPLAINTV2') {
                $model = \app\logic\ComplainLogic::getModel($this->channel);
                $model->refreshNewInfo($data['complaint_id']);
            }

            return ['type' => 'html', 'data' => 'sccuess'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //进件通知
    public function applynotify(PaymentContext $ctx): array
    {
        $post = request()->post();

        $client = $this->createClient();
        $verify_result = $client->verifySign($post);

        if ($verify_result) {
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->notify($post);

            return ['type' => 'html', 'data' => 'sccuess'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }
}
