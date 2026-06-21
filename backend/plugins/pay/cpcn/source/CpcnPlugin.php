<?php

declare(strict_types=1);

namespace plugins\payment\cpcn;

use app\common\PaymentContext;
use app\common\BasePayment;
use think\facade\Db;
use Exception;

class CpcnPlugin extends BasePayment
{
    private function getClient(): PayClient
    {
        return new PayClient($this->channel['appid'], $this->channel['appurl'], $this->channel['appswitch'] == 1);
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
            } elseif ($ctx->isMobile && (in_array('3', $this->channel['apptype']) || in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0)) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
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

        if ($ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->method == 'applet') {
            return $this->wxapppay($ctx);
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return $this->wxjspay($ctx);
            } elseif ($ctx->isMobile && (in_array('3', $this->channel['apptype']) || in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0)) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/quickpay/' . $tradeNo . '/'];
            }
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //统一下单接口
    private function addOrder(PaymentContext $ctx, string $payment_way, string $payment_type, string $pay_way, ?string $openid = null, ?string $appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'InstitutionID' => $this->channel['appid'],
            'TxSN' => $tradeNo,
            'PayerUserID' => $ctx->order['uid'],
            'PayeeUserID' => $this->channel['appid'],
            'PayeeAccountNumber' => '0001',
            'PaymentWay' => $payment_way,
            'Amount' => strval($ctx->order['realmoney'] * 100),
            'ExpirePeriod' => '10m',
            'PageURL' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'GoodsName' => $ctx->ordername,
            'PlatformName' => config_get('sitename'),
            'ClientIP' => request()->clientip,
            'HasSubsequentSplit' => '1',
            'DeductionSettlementFlag' => '0001',
            'NoticeURL' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if (!empty($this->channel['appmchid'])) {
            $mchids = explode(',', $this->channel['appmchid']);
            $params['Extension'] = ['AppointMerchantInfos' => []];
            foreach ($mchids as $mchid) {
                $mchid = trim($mchid);
                if ($mchid) {
                    $params['Extension']['AppointMerchantInfos'][] = ['MerchantID' => $mchid];
                }
            }
            $params['Extension'] = json_encode($params['Extension']);
        }
        if ($payment_way == '40') {
            $params['QRPay'] = [
                'QRPaymentType' => $payment_type,
                'QRPaymentWay' => $pay_way,
                'RedirectPageURL' => $siteurl . 'pay/return/' . $tradeNo . '/',
            ];
            if ($payment_type == '10') {
                $params['QRPay']['QRPageUrlType'] = '10';
            }
            if ($openid) $params['QRPay']['OpenID'] = $openid;
            if ($appid) $params['QRPay']['MerchantAppID'] = $appid;
        } elseif ($payment_way == '42') {
            $params['ScanPay'] = [
                'ScanPaymentType' => $payment_type,
                'ScanPaymentWay' => $pay_way,
                'RedirectPageURL' => $siteurl . 'pay/return/' . $tradeNo . '/',
            ];
            if ($payment_type == '10') {
                $params['ScanPay']['ScanPageUrlType'] = '10';
                if ($ctx->mdevice !== 'wechat') {
                    $params['PageURL'] = $siteurl . 'pay/ok/' . $tradeNo . '/';
                }
            }
        } elseif ($payment_way == '80') {
            $params['RedirectPay'] = [
                'PayWay' => $pay_way,
                'PayType' => $payment_type,
                'RedirectSource' => '20',
            ];
            if ($payment_type == '30') {
                $params['RedirectPay']['LimitPay'] = '10';
            } elseif ($payment_type == '31' && ($pay_way == '45' || $pay_way == '46')) {
                $params['RedirectPay']['ProductMode'] = '01';
            }
            if ($payment_type == '31' && $pay_way == '50' && !$openid) {
                $params['RedirectPay']['CashierFlag'] = '20';
            } elseif ($payment_type == '31' && $pay_way == '51' && !$openid) {
                $params['RedirectPay']['PluginFlag'] = '30';
            }
            if ($openid) $params['RedirectPay']['SubOpenID'] = $openid;
            if ($appid) $params['RedirectPay']['SubAppID'] = $appid;
        }

        $client = $this->getClient();
        $result = self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->payRequest('5011', $params);
            return $result;
        });
        if ($result['Status'] == '40') {
            throw new Exception('[' . $result['ResponseCode'] . ']' . $result['ResponseMessage']);
        }
        return $result;
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (($ctx->isMobile) && in_array('3', $this->channel['apptype'])) {
            try {
                $result = $this->addOrder($ctx, '80', '32', '45');
                $code_url = $result['QRAuthCode'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
            }
            if (strpos($code_url, '{"bizContent"') === 0) {
                $arr = json_decode($code_url, true);
                $code_url = $arr['action'];
            }
            return ['type' => 'jump', 'url' => $code_url];
        } elseif (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $result = $this->addOrder($ctx, '42', '20', '41');
                $code_url = $result['QRCodeURL'];
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
            $result = $this->addOrder($ctx, '80', '32', '50', $user_id);
            $payinfo = json_decode($result['QRAuthCode'], true);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $payinfo['trade_no']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $payinfo['trade_no'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype'])) {
            try {
                $result = $this->addOrder($ctx, '42', '10', '41');
                $code_url = $result['QRCodeURL'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('2', $this->channel['apptype'])) {
            if ($this->channel['appwxmp'] > 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } elseif ($this->channel['appwxa'] > 0) {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            } else {
                try {
                    $result = $this->addOrder($ctx, '80', '31', '50');
                    $code_url = $result['QRAuthCode'];
                } catch (Exception $ex) {
                    return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
                }
            }
        } elseif (in_array('3', $this->channel['apptype'])) {
            try {
                $result = $this->addOrder($ctx, '80', '31', '45');
                $code_url = $result['QRAuthCode'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } else {
            return ['type' => 'error', 'msg' => '当前支付通道没有开启的支付方式'];
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
        $siteurl = request()->siteurl;

        if (in_array('3', $this->channel['apptype'])) {
            try {
                $result = $this->addOrder($ctx, '80', '31', '45');
                $code_url = $result['QRAuthCode'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            $code_url .= '&redirect_url=' . urlencode($siteurl . 'pay/return/' . $tradeNo . '/');
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($this->channel['appwxa'] > 0) {
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
            $result = $this->addOrder($ctx, '80', '31', $ctx->order['is_applet'] == 1 ? '51' : '50', $openid, $wxinfo['appid']);
            $payinfo = $result['QRAuthCode'];
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
            $result = $this->addOrder($ctx, '80', '31', '51', $openid, $wxinfo['appid']);
            $payinfo = $result['QRAuthCode'];
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($payinfo, true)]];
    }

    //微信APP支付
    public function wxapppay(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, '80', '31', '51');
            $payinfo = json_decode($result['QRAuthCode'], true);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => $payinfo['appid'], 'miniProgramId' => '', 'path' => $payinfo['path']]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, '42', '30', '41');
            $code_url = $result['QRCodeURL'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //查询银行卡信息
    private function query_card_info(string $cardno): array
    {
        $params = [
            'InstitutionID' => $this->channel['appid'],
            'TxSN' => date('YmdHis') . rand(100000, 999999),
            'AccountNumber' => $cardno,
        ];
        $client = $this->getClient();
        $result = $client->payRequest('2751', $params);
        if ($result['Status'] == '10') {
            throw new Exception('[' . $result['ResponseCode'] . ']' . $result['ResponseMessage']);
        }
        return $result;
    }

    //查询绑卡状态
    private function query_bind_card(string $cardno, string $phone): bool
    {
        $txsn = $cardno . $phone;
        $params = [
            'InstitutionID' => $this->channel['appid'],
            'SourceTxSN' => $txsn,
            'SourceTxCode' => '4611',
        ];
        $client = $this->getClient();
        $result = $client->payRequest('4616', $params);
        if ($result['Status'] == '30') {
            return true;
        }
        return false;
    }

    //快捷支付下单
    private function prepay(PaymentContext $ctx, array $data): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $txsn = $data['cardno'] . $data['phone'];

        $params = [
            'InstitutionID' => $this->channel['appid'],
            'TxSN' => $tradeNo,
            'PayerUserID' => $data['phone'],
            'PayeeUserID' => $this->channel['appid'],
            'PayeeAccountNumber' => '0001',
            'PaymentWay' => '10',
            'Amount' => strval($ctx->order['realmoney'] * 100),
            'ExpirePeriod' => '30m',
            'PageURL' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'GoodsName' => $ctx->ordername,
            'ClientIP' => request()->clientip,
            'HasSubsequentSplit' => '1',
            'DeductionSettlementFlag' => '0001',
            'NoticeURL' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($data['first']) {
            $params['QuickPay'] = [
                'BindingPaymentType' => '20',
                'SMSVerification' => '1',
                'BindingTxSN' => $txsn,
                'BankID' => $data['bankid'],
                'AccountName' => $data['name'],
                'AccountNumber' => $data['cardno'],
                'IdentificationType' => '0',
                'IdentificationNumber' => $data['idcard'],
                'PhoneNumber' => $data['phone'],
                'CardType' => $data['cardtype'],
            ];
        } else {
            $params['QuickPay'] = [
                'BindingPaymentType' => '10',
                'SMSVerification' => '1',
                'BindingTxSN' => $txsn,
                'AccountNumber' => $data['cardno'],
            ];
        }
        if ($data['cardtype'] == '20') {
            $params['QuickPay']['CVN2'] = $data['cvv'];
            $params['QuickPay']['ValidateDate'] = substr($data['expiry'], 2, 2) . substr($data['expiry'], 5, 2);
        }
        $client = $this->getClient();
        $result = $client->payRequest('5011', $params);
        if ($result['Status'] == '40') {
            throw new Exception('[' . $result['ResponseCode'] . ']' . $result['ResponseMessage']);
        }
        return $result;
    }

    //快捷支付确认
    private function pay_confirm(array $data): array
    {
        $tradeNo = $data['trade_no'];
        $params = [
            'InstitutionID' => $this->channel['appid'],
            'TxSN' => $tradeNo,
            'SmsValidationCode' => $data['smscode'],
        ];
        if ($data['cardtype'] == '20') {
            $params['CVN2'] = $data['cvv'];
            $params['ValidateDate'] = substr($data['expiry'], 2, 2) . substr($data['expiry'], 5, 2);
        }
        $client = $this->getClient();
        $result = $client->payRequest('5015', $params);
        if ($result['Status'] == '40') {
            throw new Exception('[' . $result['ResponseCode'] . ']' . $result['ResponseMessage']);
        }
        return $result;
    }

    //快捷支付
    public function quickpay(PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];
        $action = request()->post('action');

        if ($action) {
            switch ($action) {
                case 'query_card':
                    $cardno = trim(request()->post('cardno', ''));
                    if (empty($cardno)) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '银行卡号不能为空']];
                    try {
                        $result = $this->query_card_info($cardno);
                        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $result]];
                    } catch (Exception $ex) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
                    }

                case 'get_info':
                    $phone = trim(request()->post('phone', ''));
                    $cardno = trim(request()->post('cardno', ''));
                    if (empty($phone) || empty($cardno)) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '参数不能为空']];
                    try {
                        $status = $this->query_bind_card($cardno, $phone);
                    } catch (Exception $ex) {
                        if (strpos($ex->getMessage(), '交易流水号不存在') !== false) {
                            $status = false;
                        } else {
                            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '查询绑卡状态失败：' . $ex->getMessage()]];
                        }
                    }

                    Db::name('order')->where('trade_no', $tradeNo)->update(['mobile' => $phone, 'buyer' => $cardno]);
                    $black = Db::name('blacklist')->where(['type' => 0, 'content' => $phone])->find();
                    if ($black) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '系统异常无法完成付款']];
                    $black = Db::name('blacklist')->where(['type' => 0, 'content' => $cardno])->find();
                    if ($black) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '系统异常无法完成付款']];

                    if ($status == true) {
                        $data = [
                            'first' => false,
                            'cardno' => $cardno,
                            'phone' => $phone,
                        ];
                        try {
                            $result = $this->prepay($ctx, $data);
                        } catch (Exception $ex) {
                            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '交易失败：' . $ex->getMessage()]];
                        }
                        if ($result['Status'] == '30') {
                            return ['type' => 'json', 'data' => ['code' => 2, 'backurl' => '/pay/return/' . $tradeNo . '/']];
                        } else {
                            return ['type' => 'json', 'data' => ['code' => 1, 'result' => $result]];
                        }
                    } else {
                        return ['type' => 'json', 'data' => ['code' => 0]];
                    }

                case 'bind_card':
                    $data = [
                        'first' => true,
                        'name' => trim(request()->post('name', '')),
                        'phone' => trim(request()->post('phone', '')),
                        'cardno' => trim(request()->post('cardno', '')),
                        'cardtype' => trim(request()->post('cardtype', '')),
                        'bankid' => trim(request()->post('bankid', '')),
                        'idcard' => trim(request()->post('idcard', '')),
                        'expiry' => trim(request()->post('expiry', '')),
                        'cvv' => trim(request()->post('cvv', '')),
                    ];
                    if (empty($data['name']) || empty($data['phone']) || empty($data['cardno']) || empty($data['cardtype']) || empty($data['idcard'])) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '参数不能为空']];
                    }
                    if (!is_idcard($data['idcard'])) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '身份证号码不正确']];
                    try {
                        $result = $this->prepay($ctx, $data);
                        return ['type' => 'json', 'data' => ['code' => 0, 'result' => $result]];
                    } catch (Exception $ex) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '绑卡失败：' . $ex->getMessage()]];
                    }

                case 'pay_confirm':
                    $data = [
                        'trade_no' => $tradeNo,
                        'smscode' => trim(request()->post('smscode', '')),
                        'cardtype' => trim(request()->post('cardtype', '')),
                        'expiry' => trim(request()->post('expiry', '')),
                        'cvv' => trim(request()->post('cvv', '')),
                    ];
                    if (empty($data['smscode'])) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '参数不能为空']];
                    try {
                        $result = $this->pay_confirm($data);
                    } catch (Exception $ex) {
                        return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '交易失败：' . $ex->getMessage()]];
                    }
                    return ['type' => 'json', 'data' => ['code' => 0, 'backurl' => '/pay/return/' . $tradeNo . '/']];
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
        $message = request()->post('message');
        $signature = request()->post('signature');
        if (!$message || !$signature) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->getClient();
        try {
            $data = $client->parseNotify($message, $signature);

            if ($data['Status'] == '30') {
                $out_trade_no = $data['TxSN'];
                $api_trade_no = $data['OrderNo'];
                $money = $data['Amount'];
                $buyer = isset($data['PayerID']) && is_string($data['PayerID']) ? $data['PayerID'] : null;
                $bill_trade_no = isset($data['TraceNo']) && is_string($data['TraceNo']) ? $data['TraceNo'] : null;
                $end_time = $data['ResponseTime'];
                if ($out_trade_no == $ctx->order['trade_no']) {
                    ($this->markTrustedCallback($ctx, 'notify', 'cpcn-signature'))(function () use ($ctx, $api_trade_no, $buyer, $bill_trade_no, $end_time) {
                        $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                    });
                }
            }
            return ['type' => 'html', 'data' => $client->echoResult()];
        } catch (Exception $e) {
            return ['type' => 'html', 'data' => $client->echoResult(false, $e->getMessage())];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $client = $this->getClient();
        $result = $client->payRequest('5016', [
            'InstitutionID' => $this->channel['appid'],
            'TxSN' => $order['trade_no'],
        ]);
        return [
            'api_trade_no' => $result['OrderNo'],
            'status' => $result['Status'] == '30' ? 1 : 0,
            'money' => $result['Amount'],
            'buyer' => isset($result['PayerID']) && is_string($result['PayerID']) ? $result['PayerID'] : null,
            'bill_trade_no' => isset($data['TraceNo']) && is_string($data['TraceNo']) ? $data['TraceNo'] : null,
            'endtime' => $result['ResponseTime'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'InstitutionID' => $this->channel['appid'],
            'TxSN' => $order['refund_no'],
            'PaymentTxSN' => $order['trade_no'],
            'RefundWay' => '20',
            'Amount' => strval($order['refundmoney'] * 100),
            'NoticeURL' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
        ];

        try {
            $client = $this->getClient();
            $result = $client->payRequest('5021', $params);

            return ['code' => 0, 'trade_no' => $result['TxSN'], 'refund_fee' => $result['Amount'] / 100];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //退款异步回调
    public function refundnotify(PaymentContext $ctx): array
    {
        $message = request()->post('message');
        $signature = request()->post('signature');
        if (!$message || !$signature) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->getClient();
        try {
            $data = $client->parseNotify($message, $signature);

            if ($data['Status'] == '20') {
                $out_trade_no = $data['TxSN'];
                $api_trade_no = $data['OrderNo'];
                $money = $data['Amount'];
            }
            $status = ($data['Status'] ?? '') == '20' ? 1 : (($data['Status'] ?? '') === '' ? 0 : 2);
            ($this->markTrustedCallback($ctx, 'refundnotify', 'cpcn-signature'))(function () use ($data, $status) {
                $this->processRefund(
                    $data['TxSN'] ?? '',
                    $status,
                    $status === 2 ? (string)($data['Status'] ?? 'cpcn refund failed') : '',
                    $data['OrderNo'] ?? '',
                    isset($data['Amount']) ? ((float)$data['Amount'] / 100) : null
                );
            });
            return ['type' => 'html', 'data' => $client->echoResult()];
        } catch (Exception $e) {
            return ['type' => 'html', 'data' => $client->echoResult(false, $e->getMessage())];
        }
    }
}
