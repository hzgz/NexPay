<?php

declare(strict_types=1);

namespace plugins\payment\yinyingtong;

use app\common\PaymentContext;
use app\common\BasePayment;
use think\facade\Db;
use Exception;

class YinyingtongPlugin extends BasePayment
{
    private function createPayClient(PaymentContext $ctx): PayClient
    {
        $client = new PayClient($this->channel['appid'], $this->channel['appkey']);
        $client->setDeviceInfo($ctx->isMobile, $ctx->mdevice);
        return $client;
    }

    private function createM2Client(): M2Client
    {
        $publicCertPath = $this->payRoot . 'cert/M2.cer';
        $privateCertPath = getCertFilePath($this->channel['merchant_private_cert'] ?? '');
        return new M2Client($this->channel['appid'], $this->channel['appkey'], $publicCertPath, $privateCertPath);
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
            if (in_array('3', $this->channel['apptype']) && $ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ((in_array('2', $this->channel['apptype']) || in_array('3', $this->channel['apptype']) && $this->channel['appwxa'] > 0) && $ctx->isMobile) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            if (in_array('1', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/quickpay/' . $tradeNo . '/'];
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
        } elseif ($ctx->method == 'app' || $ctx->method == 'applet') {
            return $this->wxapppay($ctx);
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if (in_array('3', $this->channel['apptype']) && $ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ((in_array('2', $this->channel['apptype']) || in_array('3', $this->channel['apptype']) && $this->channel['appwxa'] > 0) && $ctx->isMobile) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            if (in_array('1', $this->channel['apptype'])) {
                return $this->bank($ctx);
            } else {
                return $this->quickpay($ctx);
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //支付预下单
    private function prepay(PaymentContext $ctx, string $pay_type, ?string $bank_service_type = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->createPayClient($ctx);

        $params = [
            'merchant_number' => $this->channel['appmchid'],
            'order_number' => $tradeNo,
            'amount' => $ctx->order['realmoney'],
            'pay_type' => $pay_type,
            'currency' => 'CNY',
            'order_title' => $ctx->ordername,
            'channel_code' => $pay_type,
            'async_notification_addr' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'notify_key_mode' => '03',
            'ref_no' => $this->channel['trade_platform_no'],
        ];
        if ($bank_service_type) $params['bank_service_type'] = $bank_service_type;
        if ($ctx->order['profits'] > 0) {
            $params['profit_sharing'] = '1';
        }
        if (!empty($this->channel['channel_merch_no'])) {
            $merch_no = $this->channel['channel_merch_no'];
            if (strpos($merch_no, ',')) {
                $merch_nos = explode(',', $merch_no);
                $merch_no = $merch_nos[array_rand($merch_nos)];
            }
            $params['bank_mch_id'] = $merch_no;
        }

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('gepos.pre.pay', $params);
            if ($result['op_ret_code'] == '000') {
                $order_id = $result['order_id'];
                $this->updateOrder($tradeNo, $order_id);
                return $result;
            } else {
                throw new Exception('[' . $result['op_ret_subcode'] . ']' . $result['op_err_msg']);
            }
        });
    }

    //公众号小程序支付
    private function jsapipay(PaymentContext $ctx, string $pay_type, ?string $sub_openid = null, ?string $sub_appid = null, bool $is_mini = false): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->createPayClient($ctx);

        $params = [
            'merchant_number' => $this->channel['appmchid'],
            'order_number' => $tradeNo,
            'amount' => $ctx->order['realmoney'],
            'pay_type' => $pay_type,
            'currency' => 'CNY',
            'order_title' => $ctx->ordername,
            'channel_code' => $pay_type,
            'async_notification_addr' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'notify_key_mode' => '03',
            'ref_no' => $this->channel['trade_platform_no'],
        ];
        if ($sub_openid) $params['open_id'] = $sub_openid;
        if ($sub_appid) $params['sub_appid'] = $sub_appid;
        if ($ctx->order['profits'] > 0) {
            $params['profit_sharing'] = '1';
        }
        if (!empty($this->channel['channel_merch_no'])) {
            $merch_no = $this->channel['channel_merch_no'];
            if (strpos($merch_no, ',')) {
                $merch_nos = explode(',', $merch_no);
                $merch_no = $merch_nos[array_rand($merch_nos)];
            }
            $params['bank_mch_id'] = $merch_no;
        }
        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo, $is_mini) {
            $result = $client->execute($is_mini ? 'gepos.mini.program.pay' : 'gepos.public.number.order', $params);
            if ($result['op_ret_code'] == '000') {
                $this->updateOrder($tradeNo, $result['order_id']);
                return $result['trans_data'];
            } else {
                throw new Exception('[' . $result['op_ret_subcode'] . ']' . $result['op_err_msg']);
            }
        });
    }

    //预下单
    private function precreate(PaymentContext $ctx, string $pay_type, string $user_id): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $client = $this->createPayClient($ctx);

        $params = [
            'merchant_number' => $this->channel['appmchid'],
            'order_number' => $tradeNo,
            'scene' => '14',
            'good_desc' => $ctx->order['name'],
            'total_amount' => $ctx->order['realmoney'],
            'currency' => 'cny',
            'user_id' => $user_id,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('gcash.trade.precreate', $params);
            $this->updateOrder($tradeNo, $result['order_id']);
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
                $result = $this->prepay($ctx, '01');
                $bank_order_id = $result['order_id'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
            $code_url = 'https://h5.gomepay.com/cashier-h5/index.html#/pages/preOrder/orderPay?orderId=' . $bank_order_id . '&showPayButton=0';
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //支付宝生活号支付
    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!empty($ctx->order['sub_openid'])) {
            $user_id = $ctx->order['sub_openid'];
            $user_type = 'uid';
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }

        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;
        if ($user_type == 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $paydata = $this->jsapipay($ctx, '01', $user_id);
            $trade_no = json_decode($paydata, true)['tradeNO'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $trade_no];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $trade_no, 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype'])) {
            try {
                $result = $this->prepay($ctx, '02', '16');
                $bank_order_id = $result['order_id'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            $code_url = 'https://h5.gomepay.com/cashier-h5/index.html#/pages/preOrder/wxPublicOrder?orderId=' . $bank_order_id . '&showPayButton=0';
        } elseif (in_array('2', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } else {
            if ($this->channel['appwxmp'] > 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
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
        if (in_array('2', $this->channel['apptype'])) {
            try {
                $result = $this->prepay($ctx, '02', '22');
                $bank_order_id = $result['order_id'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            $query = 'orderId=' . $bank_order_id . '&showPayButton=0';
            $code_url = 'weixin://dl/business/?appid=' . $result['app_id'] . '&path=pages/wechat/preOrder/orderpay&query=' . urlencode($query) . '&env_version=release';
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } else {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
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
            $paydata = $this->jsapipay($ctx, '02', $openid, $wxinfo['appid'], $ctx->order['is_applet'] == 1);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $paydata];
        }

        if (request()->get('d') == 1) {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $paydata, 'redirect_url' => $redirect_url]];
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
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        //②、统一下单
        try {
            $paydata = $this->jsapipay($ctx, '02', $openid, $wxinfo['appid'], true);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }
        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($paydata, true)]];
    }

    //微信APP支付
    public function wxapppay(PaymentContext $ctx): array
    {
        try {
            $result = $this->prepay($ctx, '02', '22');
            $bank_order_id = $result['order_id'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        $env = $ctx->method == 'applet' ? 'miniprogram' : 'app';
        return ['type' => 'wxapp', 'data' => ['appId' => $result['app_id'], 'miniProgramId' => $result['original_id'], 'path' => 'pages/wechat/preOrder/orderpay?orderId=' . $bank_order_id . '&env=' . $env]];
    }

    //快捷支付(H5)
    public function bank(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $user_id = request()->cookie('yyt_user_id');
        if (empty($user_id)) {
            $user_id = substr(getSid(), 0, 16);
            cookie('yyt_user_id', $user_id, ['expire' => 86400 * 365, 'path' => '/']);
        }
        try {
            $result = $this->precreate($ctx, '10', $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '快捷支付下单失败！' . $ex->getMessage()];
        }
        $params = [
            'merchant_number' => $this->channel['appmchid'],
            'user_id' => $user_id,
            'order_number' => $tradeNo,
            'type' => 'wbsh',
        ];
        $url = 'https://h5.gomepay.com/cashier-h5/index.html#/pages/paymentB/cashRegister?' . http_build_query($params);

        return ['type' => 'jump', 'url' => $url];
    }

    //协议快捷支付
    public function quickpay(PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];

        try {
            $client = $this->createM2Client();
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        $action = request()->post('action');
        if ($action) {
            switch ($action) {
                case 'query_card':
                    $cardno = trim(request()->post('cardno', ''));
                    if (empty($cardno)) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '银行卡号不能为空']];
                    }
                    try {
                        $result = getBankCardInfo($cardno);
                        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $result]];
                    } catch (Exception $ex) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
                    }

                case 'request':
                    $phone = trim(request()->post('phone', ''));
                    $cardno = trim(request()->post('cardno', ''));
                    $cardtype = trim(request()->post('cardtype', ''));
                    $name = trim(request()->post('name', ''));
                    $idcard = trim(request()->post('idcard', ''));
                    if (empty($phone) || empty($cardno) || empty($name) || empty($idcard)) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '参数不能为空']];
                    }
                    if (!is_idcard($idcard)) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '身份证号码不正确']];
                    }

                    Db::name('order')->where('trade_no', $tradeNo)->update(['mobile' => $phone, 'buyer' => $cardno]);
                    $black = Db::name('blacklist')->where(['type' => 0, 'content' => $phone])->find();
                    if ($black) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '系统异常无法完成付款']];
                    }
                    $black = Db::name('blacklist')->where(['type' => 0, 'content' => $cardno])->find();
                    if ($black) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '系统异常无法完成付款']];
                    }

                    $randomKey = random(16);
                    $customer_info = [
                        'customer_name' => $name,
                        'cert_type' => '01',
                        'cert_code' => $idcard,
                        'account_number' => $cardno,
                        'mobile' => $phone,
                    ];
                    $customer_info = $client->sm4Encrypt(json_encode($customer_info), $randomKey);
                    $param = [
                        'login_token' => '',
                        'req_no' => date('YmdHis') . rand(1000, 9999),
                        'plat_form' => $ctx->isMobile ? '02' : '01',
                        'interface_version' => '3.0',
                        'scene' => '0406',
                        'merchant_number' => $this->channel['appmchid'],
                        'terminal_number' => $this->channel['terminal_number'],
                        'order_number' => $tradeNo,
                        'amount' => $ctx->order['realmoney'],
                        'currency' => 'CNY',
                        'business_type' => 'A1',
                        'customer_info' => $customer_info,
                    ];
                    try {
                        $result = $client->execute('epos_api_trans@c_quick_sms', $param, $randomKey, 1);
                        return ['type' => 'json', 'data' => ['code' => 0, 'token' => $result['order_id']]];
                    } catch (Exception $ex) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '快捷短信获取失败！' . $ex->getMessage()]];
                    }

                case 'confirm':
                    $phone = trim(request()->post('phone', ''));
                    $cardno = trim(request()->post('cardno', ''));
                    $cardtype = trim(request()->post('cardtype', ''));
                    $name = trim(request()->post('name', ''));
                    $idcard = trim(request()->post('idcard', ''));
                    $token = trim(request()->post('token', ''));
                    $smscode = trim(request()->post('smscode', ''));
                    if (empty($phone) || empty($cardno) || empty($name) || empty($idcard) || empty($token) || empty($smscode)) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '参数不能为空']];
                    }

                    $randomKey = random(16);
                    $customer_info = [
                        'customer_name' => $name,
                        'cert_type' => '01',
                        'cert_code' => $idcard,
                        'account_number' => $cardno,
                        'mobile' => $phone,
                    ];
                    $customer_info = $client->sm4Encrypt(json_encode($customer_info), $randomKey);
                    $param = [
                        'login_token' => '',
                        'req_no' => date('YmdHis') . rand(1000, 9999),
                        'plat_form' => $ctx->isMobile ? '02' : '01',
                        'interface_version' => '3.0',
                        'merchant_number' => $this->channel['appmchid'],
                        'terminal_number' => $this->channel['terminal_number'],
                        'order_number' => $tradeNo,
                        'amount' => $ctx->order['realmoney'],
                        'currency' => 'CNY',
                        'sms_verification_code' => $smscode,
                        'sms_order_id' => $token,
                        'async_notification_addr' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
                        'business_type' => 'A1',
                        'number_of_types' => '1',
                        'number_of_items' => '1',
                        'goods_list' => json_encode([[
                            'goods_name' => $ctx->order['name'],
                            'amount' => $ctx->order['realmoney'],
                            'quantity' => '1',
                        ]]),
                        'customer_info' => $customer_info,
                    ];
                    if ($ctx->order['profits'] > 0) {
                        $param['profit_sharing'] = '1';
                        $param['ref_no'] = $this->channel['trade_platform_no'];
                    }
                    try {
                        $result = $client->execute('epos_api_trans@c_quick_sign_pay', $param, $randomKey, 1);
                        Db::name('order')->where('trade_no', $tradeNo)->update(['ext' => json_encode(['sign_id' => $result['sign_id'], 'order_id' => $result['order_id']])]);
                        return ['type' => 'json', 'data' => ['code' => 0, 'backurl' => '/pay/return/' . $tradeNo . '/']];
                    } catch (Exception $ex) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '快捷支付下单失败！' . $ex->getMessage()]];
                    }
            }
        }

        return view($this->payRoot . 'view/pay.html', [
            'trade_no' => $tradeNo,
            'order' => $ctx->order,
        ]);
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $dstbdatasign = request()->post('dstbdatasign');
        if (!$dstbdatasign) return ['type' => 'html', 'data' => 'no data'];

        $client = new PayClient($this->channel['appid'], $this->channel['appkey']);
        $data = $client->notify($dstbdatasign, $this->channel['productkey']);

        if ($data) {
            if ($data['orderstatus'] == '00') {
                $out_trade_no = $data['dsorderid'];
                $api_trade_no = $data['orderid'];
                $money = $data['amount'];
                $bill_trade_no = $data['at_order_id'] ?? '';
                $end_time = $data['endtime'];

                $ext = unserialize($ctx->order['ext']);
                $ext['transcode'] = $data['transcode'];

                if ($out_trade_no == $ctx->order['trade_no']) {
                    ($this->markTrustedCallback($ctx, 'notify', 'yinyingtong-signature'))(function () use ($ctx, $api_trade_no, $bill_trade_no, $end_time, $ext) {
                        $this->processNotify($ctx->order, $api_trade_no, null, $bill_trade_no, null, $end_time);
                        $this->updateOrderExt($ctx->order['trade_no'], $ext);
                    });
                }
            }
            return ['type' => 'html', 'data' => '00'];
        } else {
            return ['type' => 'html', 'data' => '01'];
        }
    }

    //旧版异步回调
    public function notify_old(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => 'no data'];

        $client = new PayClient($this->channel['appid'], $this->channel['appkey']);
        $verify_result = $client->verify($arr);

        if ($verify_result) {
            $data = json_decode($arr['data'], true);
            if ($data['status'] == '00') {
                $out_trade_no = $data['order_number'];
                $api_trade_no = $data['order_id'];
                $money = $data['total_amount'];
                $bill_mch_trade_no = $data['biz_content']['data'][0]['bank_order_id'] ?? null;
                $buyer = $data['biz_content']['data'][0]['bank_user_id'] ?? null;
                $end_time = $data['end_time'];

                if ($out_trade_no == $ctx->order['trade_no']) {
                    ($this->markTrustedCallback($ctx, 'notify', 'yinyingtong-old-signature'))(function () use ($ctx, $api_trade_no, $buyer, $bill_mch_trade_no, $end_time) {
                        $this->processNotify($ctx->order, $api_trade_no, $buyer, null, $bill_mch_trade_no, $end_time);
                    });
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
    }

    public function query(array $order): array
    {
        $client = new PayClient($this->channel['appid'], $this->channel['appkey']);
        $ext = unserialize($order['ext']);
        $params = [
            'merchant_number' => $this->channel['appmchid'],
            'order_number' => $order['trade_no'],
            'scene' => ($ext['transcode'] ?? '') == 'T61' ? '0615' : '0606',
        ];
        $result = $client->execute('gepos.order.deal.result', $params);
        if (!isset($result['order_id']) && isset($result['op_err_msg'])) {
            throw new Exception($result['op_err_msg']);
        }
        return [
            'api_trade_no' => $result['order_id'],
            'status' => $result['op_ret_code'] == '00' ? 1 : 0,
            'money' => $result['amount'],
            'bill_trade_no' => $result['at_order_id'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = new PayClient($this->channel['appid'], $this->channel['appkey']);

        $ext = unserialize($order['ext']);

        $params = [
            'scene' => ($ext['transcode'] ?? '') == 'T61' ? '0615' : '0606',
            'merchant_number' => $this->channel['appmchid'],
            'order_number' => $order['refund_no'],
            'old_order_number' => $order['trade_no'],
            'old_order_id' => $order['api_trade_no'],
            'amount' => $order['refundmoney'],
            'currency' => 'CNY',
            'async_notification_addr' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
            'memo' => '订单退款',
        ];

        try {
            $retData = $client->execute('gepos.refund', $params);
            return ['code' => 0, 'trade_no' => $retData['order_id'], 'refund_fee' => $params['amount']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //退款回调
    public function refundnotify(): array
    {
        $dstbdatasign = request()->post('dstbdatasign');
        if (!$dstbdatasign) return ['type' => 'html', 'data' => 'no data'];

        $client = new PayClient($this->channel['appid'], $this->channel['appkey']);
        $data = $client->notify($dstbdatasign, $this->channel['productkey']);

        if ($data) {
            $status = ($data['orderstatus'] ?? '') == '00' ? 1 : 2;
            if ($data['orderstatus'] == '00') {
                $out_trade_no = $data['dsorderid'];
                $api_trade_no = $data['orderid'];
                $money = $data['amount'];
            }
            ($this->markTrustedCallback($ctx, 'refundnotify', 'yinyingtong-signature'))(function () use ($data, $status) {
                $this->processRefund(
                    $data['dsorderid'] ?? '',
                    $status,
                    $status === 2 ? 'yinyingtong refund failed' : '',
                    $data['orderid'] ?? '',
                    $data['amount'] ?? null
                );
            });
            return ['type' => 'html', 'data' => '00'];
        } else {
            return ['type' => 'html', 'data' => '01'];
        }
    }

    //进件通知
    public function applynotify(): array
    {
        $dstbdata = request()->post('dstbdata');
        $dstbdatasign = request()->post('dstbdatasign');

        //计算得出通知验证结果
        $client = $this->createM2Client();
        $verify_result = $client->verify($dstbdata, $dstbdatasign);

        if ($verify_result) { //验证成功
            $data = json_decode($dstbdata, true);

            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->notify($data);

            return ['type' => 'html', 'data' => '00'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => '01'];
        }
    }

    //投诉通知
    public function complainnotify(): array
    {
        $dstbdata = request()->post('dstbdata');
        $dstbdatasign = request()->post('dstbdatasign');

        //计算得出通知验证结果
        $client = $this->createM2Client();
        $verify_result = $client->verify($dstbdata, $dstbdatasign);

        if ($verify_result) { //验证成功
            $data = json_decode($dstbdata, true);

            $model = \app\logic\ComplainLogic::getModel($this->channel);
            $model->refreshNewInfo($data['complaint_id'] . '|' . $data['sub_mchid'], $data['action_type']);

            return ['type' => 'html', 'data' => '00'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => '01'];
        }
    }
}
