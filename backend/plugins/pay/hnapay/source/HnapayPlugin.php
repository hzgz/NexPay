<?php
/***
 * https://www.yuque.com/chenyanfei-sjuaz/uhng8q
 */

declare(strict_types=1);

namespace plugins\payment\hnapay;

use app\common\PaymentContext;
use app\common\BasePayment;
use think\facade\Db;
use Exception;

class HnapayPlugin extends BasePayment
{
    //创建JSAPI/H5支付客户端(新收款密钥)
    private function createClient(): HnaPayApi
    {
        return new HnaPayApi($this->channel['appid'], $this->channel['appkey'], $this->channel['appsecret']);
    }

    //创建扫码支付客户端(收款密钥-文件)
    private function createScanClient(): HnaPayApi
    {
        $platformCertPath = $this->payRoot . 'cert/hnapay.pem';
        $merchantKeyPath = getCertFilePath($this->channel['mch_key_path'] ?? '');
        return new HnaPayApi($this->channel['appid'], $platformCertPath, $merchantKeyPath, 1);
    }

    //创建付款客户端(付款密钥-文件)
    private function createTransferClient(): HnaPayApi
    {
        $platformCertPath = $this->payRoot . 'cert/hnapaypay.pem';
        $merchantKeyPath = getCertFilePath($this->channel['pay_key_path'] ?? '');
        return new HnaPayApi($this->channel['appid'], $platformCertPath, $merchantKeyPath, 2);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            if ($this->channel['appswitch'] == 0 && $ctx->mdevice === 'alipay') {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } elseif ($this->channel['appswitch'] == 1 && $ctx->mdevice === 'alipay') {
                return ['type' => 'jump', 'url' => '/pay/alipayh5/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($this->channel['appswitch'] == 0 && $ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($this->channel['appswitch'] == 0 && $ctx->isMobile) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            if ($this->channel['appswitch'] == 1) {
                return ['type' => 'jump', 'url' => '/pay/quickpay/' . $tradeNo . '/'];
            }
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
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
            if ($this->channel['appswitch'] == 0 && $ctx->mdevice === 'alipay') {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } elseif ($this->channel['appswitch'] == 1 && $ctx->mdevice === 'alipay') {
                return ['type' => 'jump', 'url' => '/pay/alipayh5/' . $tradeNo . '/'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($this->channel['appswitch'] == 0 && $ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($this->channel['appswitch'] == 0 && $ctx->isMobile) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            if ($this->channel['appswitch'] == 1) {
                return $this->quickpay($ctx);
            }
            return $this->bank($ctx);
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //二维码下单通用
    private function qrcode(PaymentContext $ctx, string $orgCode): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $param = [
            'merOrderNum' => $tradeNo,
            'tranAmt' => strval($ctx->order['realmoney'] * 100),
            'submitTime' => substr($tradeNo, 0, 14),
            'orgCode' => $orgCode,
            'goodsName' => $ctx->ordername,
            'tranIP' => request()->clientip,
            'notifyUrl' => config_get('localurl') . 'pay/notifys/' . $tradeNo . '/',
            'weChatMchId' => $this->channel['appmchid'],
        ];

        $pay = $this->createScanClient();

        return self::lockPayData($tradeNo, function () use ($pay, $param) {
            $result = $pay->scanPay($param);
            return $result['qrCodeUrl'];
        });
    }

    //JSAPI下单通用
    private function jsapi(PaymentContext $ctx, string $orgCode, string $appId, string $openId): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $param = [
            'tranAmt' => $ctx->order['realmoney'],
            'orgCode' => $orgCode,
            'notifyServerUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'merUserIp' => request()->clientip,
            'goodsInfo' => $ctx->ordername,
            'orderSubject' => $ctx->ordername,
            'merchantId' => $this->channel['appmchid'],
        ];
        if ($orgCode == 'WECHATPAY') {
            $param += [
                'appId' => $appId,
                'openId' => $openId,
            ];
        } elseif ($orgCode == 'ALIPAY') {
            $param += [
                'aliAppId' => $appId,
                'buyerId' => $openId,
            ];
        }

        $pay = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($pay, $param, $tradeNo) {
            $result = $pay->jsapiPay($param, $tradeNo);
            return $result['payInfo'];
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        if ($this->channel['appswitch'] == 2) {
            try {
                $code_url = $this->qrcode($ctx, 'ALIPAY');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
        } elseif ($this->channel['appswitch'] == 1) {
            if ($ctx->isMobile) {
                return $this->alipayh5($ctx);
            } else {
                $code_url = $siteurl . 'pay/alipayh5/' . $tradeNo . '/?d=1';
            }
        } else {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        }
        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
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

        $achannel = \app\lib\Channel::get(config_get('alipay_web_login'));

        try {
            $retData = $this->jsapi($ctx, 'ALIPAY', $achannel['appid'], $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $retData['tradeNO']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $retData['tradeNO'], 'redirect_url' => $redirect_url]];
    }

    //支付宝H5支付
    public function alipayh5(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        if (request()->get('d') == '1') {
            $front_url = $siteurl . 'pay/ok/' . $tradeNo . '/';
        } else {
            $front_url = $siteurl . 'pay/return/' . $tradeNo . '/';
        }

        $param = [
            'tranAmt' => $ctx->order['realmoney'],
            'payType' => 'HnaZFB',
            'exPayMode' => '',
            'cardNo' => '',
            'holderName' => '',
            'identityCode' => '',
            'merUserId' => '',
            'orderExpireTime' => '10',
            'frontUrl' => $front_url,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'riskExpand' => '',
            'goodsInfo' => '',
            'orderSubject' => $ctx->ordername,
            'orderDesc' => '',
            'merchantId' => json_encode(['02' => $this->channel['appmchid']]),
            'bizProtocolNo' => '',
            'payProtocolNo' => '',
            'merUserIp' => $clientip,
            'payLimit' => '',
        ];
        $pay = $this->createClient();
        $html = $pay->h5Pay($param, $tradeNo);
        return ['type' => 'html', 'data' => $html];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        if ($this->channel['appswitch'] == 2) {
            try {
                $code_url = $this->qrcode($ctx, 'WECHATPAY');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
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

        try {
            $pay_info = $this->jsapi($ctx, 'WECHATPAY', $wxinfo['appid'], $openid);
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

        $code = request()->get('code');
        if (empty($code)) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }
        $code = trim($code);

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

        try {
            $pay_info = $this->jsapi($ctx, 'WECHATPAY', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $pay_info]];
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
            return $this->wxpay($ctx);
        }
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, 'UNIONPAY');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '银联云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //快捷支付
    public function quickpay(PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        $pay = $this->createClient();

        if (request()->post('action')) {
            switch (request()->post('action')) {
                case 'query_card':
                    $cardno = trim(request()->post('cardno', ''));
                    if (empty($cardno)) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '银行卡号不能为空']];
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
                    $expiry = str_replace('/', '', trim(request()->post('expiry', '')));
                    $cvv = trim(request()->post('cvv', ''));
                    if (empty($phone) || empty($cardno) || empty($name) || empty($idcard)) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '参数不能为空']];
                    }
                    if (!is_idcard($idcard)) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '身份证号码不正确']];

                    Db::name('order')->where('trade_no', $tradeNo)->update(['mobile' => $phone, 'buyer' => $cardno]);
                    $black = Db::name('blacklist')->where(['type' => 0, 'content' => $phone])->find();
                    if ($black) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '系统异常无法完成付款']];
                    $black = Db::name('blacklist')->where(['type' => 0, 'content' => $cardno])->find();
                    if ($black) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '系统异常无法完成付款']];

                    $param = [
                        'tranAmt' => $ctx->order['realmoney'],
                        'payType' => '2',
                        'cardNo' => $cardno,
                        'holderName' => $name,
                        'cardAvailableDate' => $expiry,
                        'cvv2' => $cvv,
                        'mobileNo' => $phone,
                        'identityType' => '01',
                        'identityCode' => $idcard,
                        'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
                        'merUserId' => $phone,
                        'merUserIp' => $clientip,
                        'goodsInfo' => $ctx->ordername,
                    ];
                    try {
                        $result = $pay->quickPayRequest($param, $tradeNo);
                        return ['type' => 'json', 'data' => ['code' => 0, 'token' => $result['hnapayOrderId']]];
                    } catch (Exception $ex) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '快捷支付下单失败！' . $ex->getMessage()]];
                    }
                case 'confirm':
                    $token = trim(request()->post('token', ''));
                    $smscode = trim(request()->post('smscode', ''));
                    if (empty($token) || empty($smscode)) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '参数不能为空']];
                    $paymentTerminalInfo = ($ctx->isMobile ? '02|' : '01|') . substr(md5(request()->post('phone', '')), 0, 10);
                    $param = [
                        'hnapayOrderId' => $token,
                        'smsCode' => $smscode,
                        'merUserIp' => $clientip,
                        'paymentTerminalInfo' => $paymentTerminalInfo,
                        'receiverTerminalInfo' => '01|00001|CN|110000',
                        'deviceInfo' => $clientip . '||||||',
                    ];
                    try {
                        $result = $pay->quickPayConfirm($param, $tradeNo);
                        Db::name('order')->where('trade_no', $tradeNo)->update(['ext' => json_encode(['bizProtocolNo' => $result['bizProtocolNo'], 'payProtocolNo' => $result['payProtocolNo']])]);
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

    //协议页面
    public function agreement(PaymentContext $ctx)
    {
        return view($this->payRoot . 'view/agreement.html');
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $postData = request()->post();
        if (empty($postData)) return ['type' => 'html', 'data' => 'no_data'];
        //file_put_contents('logs.txt' , http_build_query($postData));

        $pay = $this->createClient();

        if ($postData['tranCode'] == 'MUP11') {
            $verify_result = $pay->alipayh5Verify($postData);
        } elseif ($postData['tranCode'] == 'EXP13') {
            $verify_result = $pay->quickpayVerify($postData);
        } else {
            $verify_result = $pay->jsapiVerify($postData);
        }
        if ($verify_result) {
            if ($postData['resultCode'] == '0000') {
                $out_trade_no = $postData['merOrderId'];
                $trade_no = $postData['hnapayOrderId'];
                $bill_mch_trade_no = $postData['bankOrderId'] ?? '';
                $bill_trade_no = $postData['realBankOrderId'] ?? '';
                if ($ctx->order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
                $money = $postData['tranAmt'];
                $buyer = $postData['userId'] ?? '';
                $end_time = $postData['tranFinishTime'];

                if ($out_trade_no == $tradeNo) {
                    ($this->markTrustedCallback($ctx, 'notify', 'hnapay-jsapi-signature'))(function () use ($ctx, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time) {
                        $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                    });
                }
                return ['type' => 'html', 'data' => '200'];
            } else {
                return ['type' => 'html', 'data' => '200'];
            }
        } else {
            return ['type' => 'html', 'data' => 'sign_error'];
        }
    }

    //异步回调(扫码支付)
    public function notifys(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        //file_put_contents('logs.txt' , http_build_query(request()->post()));

        $pay = $this->createScanClient();

        $postData = request()->post();
        if ($pay->scanVerify($postData)) {
            if ($postData['respCode'] == '0000') {
                $out_trade_no = $postData['merOrderNum'];
                $trade_no = $postData['hnapayOrderId'];
                $bill_mch_trade_no = $postData['bankOrderId'] ?? '';
                $bill_trade_no = $postData['realBankOrderId'] ?? '';
                if ($ctx->order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
                $money = $postData['tranAmt'];
                $buyer = $postData['userId'] ?? '';
                $end_time = $postData['tranFinishTime'];

                if ($out_trade_no == $tradeNo) {
                    ($this->markTrustedCallback($ctx, 'notify', 'hnapay-scan-signature'))(function () use ($ctx, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time) {
                        $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                    });
                }
                return ['type' => 'html', 'data' => '200'];
            } else {
                return ['type' => 'html', 'data' => '200'];
            }
        } else {
            return ['type' => 'html', 'data' => 'sign_error'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $pay = $this->createClient();
        if ($this->channel['appswitch'] == 2) {
            $result = $pay->scanQuery($order['trade_no']);
            $data = explode(',', $result['queryDetails']);
            $bill_trade_no = $data[13] ?? '';
            if ($order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
            return [
                'api_trade_no' => $data[5],
                'status' => $data[6] == '2' ? 1 : 0,
                'money' => $data[1],
                'buyer' => $data[14] ?? '',
                'bill_trade_no' => $bill_trade_no,
                'bill_mch_trade_no' => $data[9] ?? '',
                'endtime' => $data[4] ?? '',
            ];
        } else {
            $result = $pay->jsapiQuery($order['trade_no']);
            $bill_trade_no = $result['realBankOrderId'] ?? '';
            if ($order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
            return [
                'api_trade_no' => $result['hnapayOrderId'],
                'status' => $result['orderStatus'] == '1' ? 1 : 0,
                'money' => $result['tranAmt'],
                'buyer' => $result['userId'] ?? '',
                'bill_trade_no' => $bill_trade_no,
                'bill_mch_trade_no' => $result['bankOrderId'] ?? '',
                'endtime' => $result['tranFinishTime'] ?? '',
            ];
        }
        
    }

    //退款
    public function refund($order): array
    {
        $param = [
            'orgMerOrderId' => $order['trade_no'],
            'orgSubmitTime' => substr($order['trade_no'], 0, 14),
            'orderAmt' => $order['realmoney'],
            'refundOrderAmt' => $order['refundmoney'],
            'notifyUrl' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
        ];

        try {
            $pay = $this->createClient();
            $result = $pay->refund($param, $order['refund_no']);
            return ['code' => 0, 'trade_no' => $result['orgMerOrderId'], 'refund_fee' => $result['refundAmt']];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //退款异步回调
    public function refundnotify(PaymentContext $ctx): array
    {
        $postData = request()->post();

        $pay = $this->createClient();
        if ($pay->jsapiVerify($postData)) {
            if ($postData['resultCode'] == '0000') {
                ($this->markTrustedCallback($ctx, 'refundnotify', 'hnapay-refund-signature'))(function () use ($ctx, $postData) {
                    $this->processRefund(
                        $postData['refundOrderId'] ?? $postData['refundOrderNo'] ?? $postData['merOrderId'] ?? $ctx->order['trade_no'] ?? '',
                        1,
                        '',
                        $postData['hnapayOrderId'] ?? $postData['bankOrderId'] ?? '',
                        $postData['refundOrderAmt'] ?? $postData['tranAmt'] ?? null
                    );
                });
                return ['type' => 'html', 'data' => '200'];
            } else {
                ($this->markTrustedCallback($ctx, 'refundnotify', 'hnapay-refund-signature'))(function () use ($ctx, $postData) {
                    $this->processRefund(
                        $postData['refundOrderId'] ?? $postData['refundOrderNo'] ?? $postData['merOrderId'] ?? $ctx->order['trade_no'] ?? '',
                        2,
                        (string)($postData['resultMsg'] ?? $postData['errorMsg'] ?? 'hnapay refund failed'),
                        $postData['hnapayOrderId'] ?? $postData['bankOrderId'] ?? '',
                        $postData['refundOrderAmt'] ?? $postData['tranAmt'] ?? null
                    );
                });
                return ['type' => 'html', 'data' => 'status_error'];
            }
        } else {
            return ['type' => 'html', 'data' => 'sign_error'];
        }
    }

    //转账
    public function transfer($bizParam): array
    {
        $param = [
            'tranAmt' => $bizParam['money'],
            'payType' => '1',
            'auditFlag' => '0',
            'payeeName' => $bizParam['payee_real_name'],
            'payeeAccount' => $bizParam['payee_account'],
            'note' => '',
            'remark' => $bizParam['transfer_desc'],
            'bankCode' => '',
            'payeeType' => '1',
            'notifyUrl' => config_get('localurl') . 'pay/transfernotify/' . $this->channel['id'] . '/',
            'paymentTerminalInfo' => '01|A10001',
            'deviceInfo' => request()->clientip,
        ];

        try {
            $client = $this->createTransferClient();
            $result = $client->transfer($param, $bizParam['out_biz_no']);
            return ['code' => 0, 'status' => 0, 'orderid' => $result['hnapayOrderId'], 'paydate' => date('Y-m-d H:i:s')];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账查询
    public function transfer_query($bizParam): array
    {
        try {
            $client = $this->createTransferClient();
            $result = $client->transferQuery($bizParam['out_biz_no']);
            if ($result['orderStatus'] == '1') {
                $status = 1;
            } elseif ($result['orderStatus'] == '0' || $result['orderStatus'] == '3') {
                $status = 0;
            } else {
                $status = 2;
            }
            $errmsg = null;
            if ($result['orderFailedCode']) {
                $errmsg = '[' . $result['orderFailedCode'] . ']' . $result['orderFailedMsg'];
            }
            return ['code' => 0, 'status' => $status, 'amount' => $result['tranAmt'], 'paydate' => date('Y-m-d H:i:s', strtotime($result['successTime'])), 'errmsg' => $errmsg];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //电子回单
    public function transfer_proof($bizParam): array
    {
        try {
            $client = $this->createTransferClient();
            $result = $client->transferProof($bizParam['orderid']);
            file_put_contents(UPLOAD_ROOT . 'bill/' . $bizParam['orderid'] . '.png', base64_decode($result['payCertificate']));
            $image = '/upload/bill/' . $bizParam['orderid'] . '.png';
            return ['code' => 0, 'msg' => '电子回单生成成功！', 'download_url' => $image];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //余额查询
    public function balance_query($bizParam): array
    {
        try {
            $client = $this->createClient();
            $result = $client->queryBalance();
            return ['code' => 0, 'amount' => $result['avaBalance'], 'msg' => '当前账户可用余额：' . $result['avaBalance'] . ' 元，待结转金额：' . $result['pendAmt']];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //付款异步回调
    public function transfernotify(PaymentContext $ctx): array
    {
        $postData = request()->post();

        $pay = $this->createTransferClient();
        if ($pay->transferVerify($postData)) {
            if ($postData['resultCode'] == '0000') {
                $status = 1;
            } else {
                $status = 2;
            }
            $errmsg = null;
            if ($postData['errorMsg']) {
                $errmsg = '[' . $postData['errorCode'] . ']' . $postData['errorMsg'];
            }
            ($this->markTrustedCallback($ctx, 'transfernotify', 'hnapay-transfer-signature'))(function () use ($postData, $status, $errmsg) {
                $this->processTransfer($postData['merOrderId'], $status, $errmsg);
            });
            return ['type' => 'html', 'data' => '200'];
        } else {
            return ['type' => 'html', 'data' => 'sign_error'];
        }
    }
}
