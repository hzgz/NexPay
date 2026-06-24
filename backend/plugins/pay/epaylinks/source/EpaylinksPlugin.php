<?php

declare(strict_types=1);

namespace plugins\payment\epaylinks;

use app\common\PaymentContext;
use app\common\BasePayment;
use think\facade\Db;
use Exception;

class EpaylinksPlugin extends BasePayment
{
    public function __construct(array $channel)
    {
        parent::__construct($channel);
    }

    private function createClient(): EfpsService
    {
        $mchId = !empty($this->channel['appmchid']) ? $this->channel['appmchid'] : $this->channel['appid'];
        $mchKeyPath = getCertFilePath($this->channel['mch_key_path'] ?? '');
        $efpsCerPath = $this->payRoot . 'source' . DIRECTORY_SEPARATOR . 'cert' . DIRECTORY_SEPARATOR . 'efps.cer';
        if (!is_file($efpsCerPath)) {
            $legacyPath = $this->payRoot . 'cert' . DIRECTORY_SEPARATOR . 'efps.cer';
            if (is_file($legacyPath)) {
                $efpsCerPath = $legacyPath;
            }
        }
        return new EfpsService($mchId, $this->channel['appkey'], $mchKeyPath, $efpsCerPath);
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
            if (in_array('3', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/quickpay/' . $tradeNo . '/'];
            } elseif (in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
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

        if ($ctx->method === 'jsapi') {
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
            if (in_array('3', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/quickpay/' . $tradeNo . '/'];
            } elseif (in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/bank/' . $tradeNo . '/'];
            } else {
                return $this->unionpay($ctx);
            }
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //收银台支付
    private function cashier(PaymentContext $ctx, string $payMethod): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'version' => '3.0',
            'outTradeNo' => $tradeNo,
            'clientIp' => request()->clientip,
            'orderInfo' => [
                'Id' => $tradeNo,
                'businessType' => '100099',
                'goodsList' => [['name' => $ctx->order['name'], 'number' => '1', 'amount' => intval(round($ctx->order['realmoney'] * 100))]],
            ],
            'payMethod' => $payMethod,
            'payAmount' => intval(round($ctx->order['realmoney'] * 100)),
            'payCurrency' => 'CNY',
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'redirectUrl' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'transactionStartTime' => date('YmdHis'),
            'areaInfo' => '100000'
        ];
        $upmchid = $this->channel['upmchid'];
        if (!empty($upmchid)) {
            if (strpos($upmchid, ',') !== false) {
                $upmchids = explode(',', $upmchid);
                $upmchid = $upmchids[array_rand($upmchids)];
            }
            $params['channelMchtNo'] = $upmchid;
        }

        $client = $this->createClient();
        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->submit('/api/txs/pay/UnifiedPayment', $params);
            return $result['casherUrl'];
        });
    }

    //主扫支付
    private function qrcode(PaymentContext $ctx, string $payMethod): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'version' => '3.0',
            'outTradeNo' => $tradeNo,
            'clientIp' => request()->clientip,
            'orderInfo' => [
                'Id' => $tradeNo,
                'businessType' => '100099',
                'goodsList' => [['name' => $ctx->order['name'], 'number' => '1', 'amount' => intval(round($ctx->order['realmoney'] * 100))]],
            ],
            'payMethod' => $payMethod,
            'payAmount' => intval(round($ctx->order['realmoney'] * 100)),
            'payCurrency' => 'CNY',
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'transactionStartTime' => date('YmdHis'),
            'areaInfo' => '100000'
        ];
        $upmchid = $this->channel['upmchid'];
        if (!empty($upmchid)) {
            if (strpos($upmchid, ',') !== false) {
                $upmchids = explode(',', $upmchid);
                $upmchid = $upmchids[array_rand($upmchids)];
            }
            $params['channelMchtNo'] = $upmchid;
        }

        $client = $this->createClient();
        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->submit('/api/txs/pay/NativePayment', $params);
            return $result['codeUrl'];
        });
    }

    //微信JS支付
    private function wxjsorder(PaymentContext $ctx, string $payMethod, string $appId, string $openId): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'version' => '3.0',
            'outTradeNo' => $tradeNo,
            'appId' => $appId,
            'openId' => $openId,
            'clientIp' => request()->clientip,
            'orderInfo' => [
                'Id' => $tradeNo,
                'businessType' => '100099',
                'goodsList' => [['name' => $ctx->order['name'], 'number' => '1', 'amount' => intval(round($ctx->order['realmoney'] * 100))]],
            ],
            'payMethod' => $payMethod,
            'payAmount' => intval(round($ctx->order['realmoney'] * 100)),
            'payCurrency' => 'CNY',
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'transactionStartTime' => date('YmdHis'),
            'areaInfo' => '100000'
        ];
        $upmchid = $this->channel['upmchid'];
        if (!empty($upmchid)) {
            if (strpos($upmchid, ',') !== false) {
                $upmchids = explode(',', $upmchid);
                $upmchid = $upmchids[array_rand($upmchids)];
            }
            $params['channelMchtNo'] = $upmchid;
        }

        $client = $this->createClient();
        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->submit('/api/txs/pay/WxJSAPIPayment', $params);
            return $result['wxJsapiParam'];
        });
    }

    //支付宝生活号支付
    private function alijsorder(PaymentContext $ctx, string $buyerId): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'version' => '3.0',
            'outTradeNo' => $tradeNo,
            'buyerId' => $buyerId,
            'clientIp' => request()->clientip,
            'orderInfo' => [
                'Id' => $tradeNo,
                'businessType' => '100099',
                'goodsList' => [['name' => $ctx->order['name'], 'number' => '1', 'amount' => intval(round($ctx->order['realmoney'] * 100))]],
            ],
            'payAmount' => intval(round($ctx->order['realmoney'] * 100)),
            'payCurrency' => 'CNY',
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'transactionStartTime' => date('YmdHis'),
            'areaInfo' => '100000'
        ];
        $upmchid = $this->channel['upmchid'];
        if (!empty($upmchid)) {
            if (strpos($upmchid, ',') !== false) {
                $upmchids = explode(',', $upmchid);
                $upmchid = $upmchids[array_rand($upmchids)];
            }
            $params['channelMchtNo'] = $upmchid;
        }

        $client = $this->createClient();
        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->submit('/api/txs/pay/AliJSAPIPayment', $params);
            return substr($result['alipayTradeNo'], 2);
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
                $code_url = $this->qrcode($ctx, '7');
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

    //支付宝生活号支付
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
            $alipay_trade_no = $this->alijsorder($ctx, $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method === 'jsapi') {
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
                $code_url = $this->qrcode($ctx, '6');
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
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
        try {
            $openid = wechat_oauth($wxinfo);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        //②、统一下单
        try {
            $result = $this->wxjsorder($ctx, '1', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        $payinfo = ['appId' => $result['appId'], 'timeStamp' => $result['timeStamp'], 'nonceStr' => $result['nonceStr'], 'package' => $result['wxPackage'], 'signType' => $result['signType'], 'paySign' => $result['paySign']];

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

        $code = request()->get('code', '', 'trim');
        if (empty($code)) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];

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
            $result = $this->wxjsorder($ctx, '35', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        $payinfo = ['appId' => $result['appId'], 'timeStamp' => $result['timeStamp'], 'nonceStr' => $result['nonceStr'], 'package' => $result['wxPackage'], 'signType' => $result['signType'], 'paySign' => $result['paySign']];

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $payinfo]];
    }

    //个人网银支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->cashier($ctx, '3');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '收银台下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    //云闪付扫码支付
    public function unionpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, '24');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //绑卡
    private function bind_card(array $data): string
    {
        $client = $this->createClient();

        $params = [
            'version' => '2.0',
            'mchtOrderNo' => date('YmdHis') . rand(1000, 9999),
            'memberId' => $data['phone'],
            'userName' => $client->rsaPublicEncrypt($data['name']),
            'phoneNum' => $client->rsaPublicEncrypt($data['phone']),
            'bankCardNo' => $client->rsaPublicEncrypt($data['cardno']),
            'bankCardType' => $data['cardtype'],
            'certificatesType' => '01',
            'certificatesNo' => $client->rsaPublicEncrypt($data['idcard']),
            'isSendIssuer' => true,
        ];
        if ($data['cardtype'] == 'credit') {
            $params += [
                'expired' => $data['expiry'],
                'cvn' => $data['cvv'],
            ];
        }

        $result = $client->submit('/api/txs/protocol/bindCard', $params);
        return $result['smsNo'];
    }

    //绑卡确认
    private function bind_card_confirm(array $data): string
    {
        $client = $this->createClient();

        $params = [
            'version' => '2.0',
            'memberId' => $data['phone'],
            'smsNo' => $data['token'],
            'smsCode' => $data['smscode'],
        ];

        $result = $client->submit('/api/txs/protocol/bindCardConfirm', $params);
        return $result['protocol'];
    }

    //绑卡协议查询
    private function query_protocol(string $phone, string $cardno): array
    {
        $client = $this->createClient();

        $params = [
            'version' => '2.0',
            'memberId' => $phone,
            'bankCardNo' => $cardno,
            'state' => 'Valid',
        ];

        $result = $client->submit('/api/txs/protocol/queryProtocol', $params);
        return $result['protocolList'];
    }

    //解绑卡
    private function unbind_card(string $phone, string $protocol): void
    {
        $client = $this->createClient();

        $params = [
            'version' => '2.0',
            'memberId' => $phone,
            'protocol' => $protocol,
        ];

        $client->submit('/api/txs/protocol/unBindCard', $params);
    }

    //交易
    private function prepay(PaymentContext $ctx, array $data): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->createClient();

        $params = [
            'version' => '3.0',
            'outTradeNo' => $tradeNo,
            'transType' => '01',
            'protocol' => $data['protocol'],
            'smsNo' => $data['token'],
            'smsCode' => $data['smscode'],
            'orderInfo' => [
                'Id' => rand(100000, 999999) . '',
                'businessType' => '100001',
                'goodsList' => [[
                    'name' => $ctx->ordername,
                    'number' => '1',
                    'amount' => intval(round($ctx->order['realmoney'] * 100)),
                ]],
            ],
            'payAmount' => intval(round($ctx->order['realmoney'] * 100)),
            'payCurrency' => 'CNY',
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'transactionStartTime' => date('YmdHis'),
        ];

        $result = $client->submit('/api/txs/protocol/protocolPayPre', $params);
        return $result;
    }

    //支付确认
    private function pay_confirm(array $data): array
    {
        $client = $this->createClient();

        $params = [
            'version' => '3.0',
            'token' => $data['token'],
            'protocol' => $data['protocol'],
            'smsCode' => $data['smscode'],
        ];

        $result = $client->submit('/api/txs/protocol/protocolPayConfirm', $params);
        return $result;
    }

    //快捷支付
    public function quickpay(PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];

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
                case 'get_info':
                    $phone = trim(request()->post('phone', ''));
                    $cardno = trim(request()->post('cardno', ''));
                    if (empty($phone) || empty($cardno)) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '参数不能为空']];
                    try {
                        $list = $this->query_protocol($phone, $cardno);
                    } catch (Exception $ex) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '查询绑卡协议失败：' . $ex->getMessage()]];
                    }

                    Db::name('order')->where('trade_no', $tradeNo)->update(['mobile' => $phone, 'buyer' => $cardno]);
                    $black = Db::name('blacklist')->where(['type' => 0, 'content' => $phone])->find();
                    if ($black) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '系统异常无法完成付款']];
                    $black = Db::name('blacklist')->where(['type' => 0, 'content' => $cardno])->find();
                    if ($black) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '系统异常无法完成付款']];

                    if (!empty($list)) {
                        $protocol = $list[0]['protocol'];
                        $data = [
                            'protocol' => $protocol,
                            'token' => '',
                            'smscode' => '',
                        ];
                        try {
                            $result = $this->prepay($ctx, $data);
                        } catch (Exception $ex) {
                            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '交易失败：' . $ex->getMessage()]];
                        }
                        if (!empty($result['token'])) {
                            return ['type' => 'json', 'data' => ['code' => 1, 'token' => $result['token'], 'protocol' => $result['protocol']]];
                        } elseif ($result['payResult'] == '00' || $result['payResult'] == '03') {
                            return ['type' => 'json', 'data' => ['code' => 2, 'backurl' => '/pay/return/' . $tradeNo . '/']];
                        } else {
                            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付失败：' . $result['payError']]];
                        }
                    } else {
                        return ['type' => 'json', 'data' => ['code' => 0]];
                    }
                case 'bind_card':
                    $data = [
                        'name' => trim(request()->post('name', '')),
                        'phone' => trim(request()->post('phone', '')),
                        'cardno' => trim(request()->post('cardno', '')),
                        'cardtype' => trim(request()->post('cardtype', '')),
                        'idcard' => trim(request()->post('idcard', '')),
                        'expiry' => trim(request()->post('expiry', '')),
                        'cvv' => trim(request()->post('cvv', '')),
                    ];
                    if (empty($data['name']) || empty($data['phone']) || empty($data['cardno'] || empty($data['cardtype'])) || empty($data['idcard'])) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '参数不能为空']];
                    if (!is_idcard($data['idcard'])) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '身份证号码不正确']];
                    try {
                        $token = $this->bind_card($data);
                        return ['type' => 'json', 'data' => ['code' => 0, 'token' => $token]];
                    } catch (Exception $ex) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '绑卡失败：' . $ex->getMessage()]];
                    }
                case 'bind_card_confirm':
                    $token = trim(request()->post('token', ''));
                    $smscode = trim(request()->post('smscode', ''));
                    if (empty($token) || empty($smscode)) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '参数不能为空']];
                    $data = [
                        'protocol' => '',
                        'token' => $token,
                        'smscode' => $smscode,
                    ];
                    try {
                        $result = $this->prepay($ctx, $data);
                    } catch (Exception $ex) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '交易失败：' . $ex->getMessage()]];
                    }
                    if (!empty($result['token'])) {
                        return ['type' => 'json', 'data' => ['code' => 0, 'token' => $result['token'], 'protocol' => $result['protocol']]];
                    } elseif ($result['payResult'] == '00' || $result['payResult'] == '03') {
                        return ['type' => 'json', 'data' => ['code' => 1, 'backurl' => '/pay/return/' . $tradeNo . '/']];
                    } else {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付失败：' . $result['payError']]];
                    }
                case 'pay_confirm':
                    $data = [
                        'token' => trim(request()->post('token', '')),
                        'smscode' => trim(request()->post('smscode', '')),
                        'protocol' => trim(request()->post('protocol', '')),
                    ];
                    if (empty($data['token']) || empty($data['smscode']) || empty($data['protocol'])) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '参数不能为空']];
                    try {
                        $result = $this->pay_confirm($data);
                    } catch (Exception $ex) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '交易失败：' . $ex->getMessage()]];
                    }
                    return ['type' => 'json', 'data' => ['code' => 0, 'backurl' => '/pay/return/' . $tradeNo . '/']];
            }
        }

        return view($this->payRoot . 'view/quickpay.html', [
            'trade_no' => $tradeNo,
            'order' => $ctx->order,
        ]);
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $post_data = request()->getContent();
        $arr = json_decode($post_data, true);
        if (!$arr) return ['type' => 'json', 'data' => ['returnCode' => '0001', 'returnMsg' => 'no data']];

        $client = $this->createClient();
        $sign = request()->header('x-efps-sign');
        $verify_result = $client->verifySign($post_data, $sign);

        if ($verify_result) { //验证成功

            if ($arr['payState'] == '00') {
                $out_trade_no = $arr['outTradeNo'];
                $api_trade_no = $arr['transactionNo'];
                $money = $arr['amount'];
                $buyer = $arr['buyerId'] ?? $arr['openId'] ?? '';
                $bill_trade_no = $arr['channelOrder'] ?? '';
                $end_time = $arr['payTime'];
                if ($out_trade_no == $ctx->order['trade_no'] && intval(round($ctx->order['realmoney'] * 100)) == $money) {
                    ($this->markTrustedCallback($ctx, 'notify', 'epaylinks-signature'))(function () use ($ctx, $api_trade_no, $buyer, $bill_trade_no, $end_time) {
                        $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                    });
                }
            }
            return ['type' => 'json', 'data' => ['returnCode' => '0000', 'returnMsg' => 'success']];
        } else {
            return ['type' => 'json', 'data' => ['returnCode' => '0001', 'returnMsg' => 'sign error']];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $params = [
            'outTradeNo' => $order['trade_no'],
        ];
        $client = $this->createClient();
        $result = $client->submit('/api/txs/pay/PaymentQuery', $params);
        return [
            'api_trade_no' => $result['transactionNo'],
            'status' => $result['payState'] == '00' ? 1 : 0,
            'money' => $result['amount'] / 100,
            'buyer' => $result['buyerId'] ?? $result['openId'] ?? '',
            'bill_trade_no' => $result['channelOrder'] ?? '',
            'endtime' => $result['payTime'] ?? '',
        ];
    }

    //退款
    public function refund($order): array
    {
        $params = [
            'outRefundNo' => $order['refund_no'],
            'transactionNo' => $order['api_trade_no'],
            'refundAmount' => intval(round($order['refundmoney'] * 100)),
        ];

        try {
            $client = $this->createClient();
            $result = $client->submit('/api/txs/pay/Refund/V2', $params);

            return ['code' => 0, 'trade_no' => $result['outRefundNo'], 'refund_fee' => $result['refundAmount']];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账
    public function transfer($bizParam): array
    {
        try {
            $bank_info = getBankCardInfo($bizParam['payee_account']);
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }

        $client = $this->createClient();

        $param = [
            'outTradeNo' => $bizParam['out_biz_no'],
            'amount' => intval(round($bizParam['money'] * 100)),
            'bankUserName' => $client->rsaPublicEncrypt($bizParam['payee_real_name']),
            'bankCardNo' => $client->rsaPublicEncrypt($bizParam['payee_account']),
            'bankName' => $bank_info['bank_name'],
            'bankAccountType' => '2',
            'payCurrency' => 'CNY',
            'purpose' => $bizParam['transfer_desc'],
            'notifyUrl' => config_get('localurl') . 'pay/transfernotify/' . $this->channel['id'] . '/',
        ];

        try {
            $result = $client->submit('/api/txs/pay/withdrawalToCard', $param);
            $status = $result['payResult'] == '00' ? 1 : 0;
            return ['code' => 0, 'status' => $status, 'orderid' => $result['transactionNo'], 'paydate' => date('Y-m-d H:i:s')];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账查询
    public function transfer_query($bizParam): array
    {
        $param = [
            'outTradeNo' => $bizParam['out_biz_no'],
        ];

        try {
            $client = $this->createClient();
            $result = $client->submit('/api/txs/pay/withdrawalToCardQuery', $param);
            $errmsg = null;
            if ($result['payState'] == '00') {
                $status = 1;
            } elseif ($result['payState'] == '01') {
                $status = 2;
                $errmsg = $result['channelQueryMsg'];
            } else {
                $status = 0;
            }
            return ['code' => 0, 'status' => $status, 'amount' => $result['amount'] / 100, 'paydate' => date('Y-m-d H:i:s'), 'errmsg' => $errmsg];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //电子回单
    public function transfer_proof($bizParam): array
    {
        $param = [
            'outTradeNo' => $bizParam['out_biz_no'],
        ];

        try {
            $client = $this->createClient();
            $result = $client->submit('/api/txs-query/pay/withdrawalCertification', $param);
            if (empty($result['imageContent'])) {
                throw new Exception('凭证图片不存在');
            }
            file_put_contents(UPLOAD_ROOT . 'bill/' . $bizParam['out_biz_no'] . '.png', base64_decode($result['imageContent']));
            $image = '/upload/bill/' . $bizParam['out_biz_no'] . '.png';
            return ['code' => 0, 'msg' => '电子回单生成成功！', 'download_url' => $image];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //余额查询
    public function balance_query($bizParam): array
    {
        $param = [
            'accountType' => '2',
        ];
        try {
            $client = $this->createClient();
            $result = $client->submit('/api/acc/accountQuery', $param);
            return ['code' => 0, 'amount' => $result['availableBalance'] / 100, 'msg' => '可用金额：' . ($result['availableBalance'] / 100) . '元，在途金额：' . ($result['floatBalance'] / 100) . '元，冻结金额：' . ($result['frozenBalance'] / 100) . '元'];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //付款异步回调
    public function transfernotify(PaymentContext $ctx): array
    {
        $post_data = request()->getContent();
        $arr = json_decode($post_data, true);
        if (!$arr) return ['type' => 'json', 'data' => ['returnCode' => '0001', 'returnMsg' => 'no data']];

        $client = $this->createClient();
        $sign = request()->header('x-efps-sign');
        if ($client->verifySign($post_data, $sign)) {
            $errmsg = null;
            if ($arr['payState'] == '00') {
                $status = 1;
            } elseif ($arr['payState'] == '01') {
                $status = 2;
                $errmsg = $arr['channelQueryMsg'] ?? '';
            } else {
                $status = 0;
            }
            ($this->markTrustedCallback($ctx, 'transfernotify', 'epaylinks-signature'))(function () use ($arr, $status, $errmsg) {
                $this->processTransfer($arr['outTradeNo'], $status, $errmsg);
            });
            return ['type' => 'json', 'data' => ['returnCode' => '0000']];
        } else {
            return ['type' => 'json', 'data' => ['returnCode' => '0001', 'returnMsg' => 'sign error']];
        }
    }

    //进件异步回调
    public function applynotify(PaymentContext $ctx): array
    {
        $post_data = request()->getContent();
        $arr = json_decode($post_data, true);
        if (!$arr) return ['type' => 'json', 'data' => ['returnCode' => '0001', 'returnMsg' => 'no data']];

        $client = $this->createClient();
        $sign = request()->header('x-efps-sign');
        $verify_result = $client->verifySign($post_data, $sign);

        if ($verify_result) { //验证成功
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->notify($arr);
            return ['type' => 'json', 'data' => ['returnCode' => '0000', 'returnMsg' => 'success']];
        } else {
            return ['type' => 'json', 'data' => ['returnCode' => '0001', 'returnMsg' => 'sign error']];
        }
    }
}
