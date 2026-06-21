<?php

declare(strict_types=1);

namespace plugins\payment\kuaiqian;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class KuaiqianPlugin extends BasePayment
{
    private function createClient(): KuaiqianApp
    {
        return new KuaiqianApp(
            $this->channel['appid'],
            $this->channel['appkey'],
            $this->channel['appsecret'],
            getCertFilePath($this->channel['merchant_key_path'] ?? ''),
            getCertFilePath($this->channel['platform_cert_path'] ?? ''),
            getCertFilePath($this->channel['ssl_cert_path'] ?? ''),
            runtime_path('temp')
        );
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay') {
                return ['type' => 'jump', 'url' => '/pay/alipaywap/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('1', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->mdevice === 'wechat' && in_array('1', $this->channel['apptype'])) {
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
        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay') {
                return $this->alipaywap($ctx);
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('1', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return $this->wxjspay($ctx);
            } elseif ($ctx->mdevice === 'wechat' && in_array('1', $this->channel['apptype'])) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //网银支付
    private function bankpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('1', $this->channel['apptype'])) {
            $payType = '10';
        } else {
            $payType = '21';
        }

        $client = $this->createClient();

        $apiurl = 'https://www.99bill.com/gateway/recvMerchantInfoAction.htm';

        $params = [
            'inputCharset' => '1',
            'pageUrl' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'bgUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'version' => 'v2.0',
            'language' => '1',
            'signType' => '4',
            'merchantAcctId' => $this->channel['appid'] . '01',
            'orderId' => $tradeNo,
            'orderAmount' => strval($ctx->order['realmoney'] * 100),
            'orderTime' => date('YmdHis'),
            'productName' => $ctx->ordername,
            'payType' => $payType
        ];
        $params['signMsg'] = $client->generateSign($params);
        $params['terminalIp'] = request()->clientip;
        $params['tdpformName'] = config_get('sitename');

        $html_text = '<form action="' . $apiurl . '" method="post" id="dopay">';
        foreach ($params as $k => $v) {
            $html_text .= "<input type=\"hidden\" name=\"{$k}\" value=\"{$v}\" />\n";
        }
        $html_text .= '<input type="submit" value="正在跳转"></form><script>document.getElementById("dopay").submit();</script>';

        return ['type' => 'html', 'data' => $html_text];
    }

    //H5支付
    private function mobilepay(PaymentContext $ctx, string $payType, ?string $aggregatePay = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $client = $this->createClient();

        $apiurl = 'https://www.99bill.com/mobilegateway/recvMerchantInfoAction.htm';

        $params = [
            'inputCharset' => '1',
            'pageUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'bgUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'version' => 'mobile1.0',
            'language' => '1',
            'signType' => '4',
            'merchantAcctId' => $this->channel['appid'] . '01',
            'orderId' => $tradeNo,
            'orderAmount' => strval($ctx->order['realmoney'] * 100),
            'orderTime' => date('YmdHis'),
            'productName' => $ctx->ordername,
            'payType' => $payType
        ];
        if ($aggregatePay) $params['aggregatePay'] = $aggregatePay;
        if ($this->channel['own_channel'] == 1 || !empty($this->channel['custom_mch_id']) && !empty($this->channel['custom_sub_mch_id']) && !empty($this->channel['custom_channel_id'])) {
            $params['extDataType'] = 'NB2';
            $customAuthNetInfo = [];
            if ($this->channel['own_channel'] == 1) {
                $customAuthNetInfo['own_channel'] = '1';
            }
            if (!empty($this->channel['custom_mch_id']) && !empty($this->channel['custom_sub_mch_id']) && !empty($this->channel['custom_channel_id'])) {
                $customAuthNetInfo['mch_id'] = $this->channel['custom_mch_id'];
                $customAuthNetInfo['sub_mch_id'] = $this->channel['custom_sub_mch_id'];
                $customAuthNetInfo['channel_id'] = $this->channel['custom_channel_id'];
            }
            $params['extDataContent'] = '<NB2>' . json_encode(['customAuthNetInfo' => $customAuthNetInfo]) . '</NB2>';
        }
        $params['signMsg'] = $client->generateSign($params);
        $params['terminalIp'] = request()->clientip;
        $params['tdpformName'] = config_get('sitename');

        $html_text = '<form action="' . $apiurl . '" method="post" id="dopay">';
        foreach ($params as $k => $v) {
            $v = htmlentities($v, ENT_QUOTES | ENT_HTML5);
            $html_text .= "<input type=\"hidden\" name=\"{$k}\" value=\"{$v}\" />\n";
        }
        $html_text .= '<input type="submit" value="正在跳转"></form><script>document.getElementById("dopay").submit();</script>';

        return ['type' => 'html', 'data' => $html_text];
    }

    //获取H5支付链接
    private function mobilepayurl(PaymentContext $ctx, string $payType, ?string $aggregatePay = null): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $client = $this->createClient();

        $apiurl = 'https://www.99bill.com/mobilegateway/recvMerchantInfoAction.htm';

        $params = [
            'inputCharset' => '1',
            'pageUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'bgUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'version' => 'mobile1.0',
            'language' => '1',
            'signType' => '4',
            'merchantAcctId' => $this->channel['appid'] . '01',
            'orderId' => $tradeNo,
            'orderAmount' => strval($ctx->order['realmoney'] * 100),
            'orderTime' => date('YmdHis'),
            'productName' => $ctx->ordername,
            'payType' => $payType
        ];
        if ($aggregatePay) $params['aggregatePay'] = $aggregatePay;
        if ($this->channel['own_channel'] == 1 || !empty($this->channel['custom_mch_id']) && !empty($this->channel['custom_sub_mch_id']) && !empty($this->channel['custom_channel_id'])) {
            $params['extDataType'] = 'NB2';
            $customAuthNetInfo = [];
            if ($this->channel['own_channel'] == 1) {
                $customAuthNetInfo['own_channel'] = '1';
            }
            if (!empty($this->channel['custom_mch_id']) && !empty($this->channel['custom_sub_mch_id']) && !empty($this->channel['custom_channel_id'])) {
                $customAuthNetInfo['mch_id'] = $this->channel['custom_mch_id'];
                $customAuthNetInfo['sub_mch_id'] = $this->channel['custom_sub_mch_id'];
                $customAuthNetInfo['channel_id'] = $this->channel['custom_channel_id'];
            }
            $params['extDataContent'] = '<NB2>' . json_encode(['customAuthNetInfo' => $customAuthNetInfo]) . '</NB2>';
        }
        $params['signMsg'] = $client->generateSign($params);
        $params['terminalIp'] = request()->clientip;
        $params['tdpformName'] = config_get('sitename');

        $res = $client->curl($apiurl, http_build_query($params));
        if (strpos($res[1], '确认支付') !== false) {
            $cookie = '';
            preg_match_all('/Set-Cookie: (.*?);/i', $res[0], $match);
            foreach ($match[1] as $v) {
                $cookie .= $v . '; ';
            }
            if (substr($payType, 0, 2) == '26') {
                $url = 'https://www.99bill.com/mobilegateway/weixinWapPrePay.htm';
                $res = $client->curl($url, '', $cookie);
                $arr = json_decode($res[1], true);
                if (isset($arr['openlink'])) {
                    return $arr['openlink'];
                } else {
                    throw new Exception('微信支付获取链接失败');
                }
            } elseif (substr($payType, 0, 2) == '27') {
                $url = 'https://www.99bill.com/mobilegateway/alicsbPay.htm';
                $res = $client->curl($url, '', $cookie);
                $arr = json_decode($res[1], true);
                if (isset($arr['qrcode'])) {
                    return $arr['qrcode'];
                } elseif ($res[1]) {
                    throw new Exception('支付宝获取链接失败');
                } else {
                    throw new Exception('支付异常');
                }
            }
        } elseif (strpos($res[1], '商家账户不允许收款') !== false) {
            throw new Exception('商家账户不允许收款');
        }
        throw new Exception('支付下单失败');
    }

    //当面付
    private function qrcodePay(PaymentContext $ctx): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $client = $this->createClient();

        $head = [
            'version' => '1.0.0',
            'messageType' => 'A7007',
            'memberCode' => $this->channel['appid'],
            'externalRefNumber' => $tradeNo,
        ];
        if (!empty($this->channel['appmchid'])) {
            $head['memberCode'] = $this->channel['appmchid'];
            $head['vendorMemberCode'] = $this->channel['appid'];
        }
        $body = [
            'merchantId' => $this->channel['merchant_id'],
            'terminalId' => $this->channel['terminal_id'],
            'cur' => 'CNY',
            'amount' => strval($ctx->order['realmoney'] * 100),
            'tr3Url' => config_get('localurl') . 'pay/notifys/' . $tradeNo . '/',
            'qrType' => '00',
            'terminalIp' => request()->clientip,
        ];

        $result = $client->execute($head, $body);
        if ($result['bizResponseCode'] == '0000') {
            $this->updateOrderCombine($tradeNo);
            return $result['qrCode'];
        } else {
            throw new Exception('[' . $result['bizResponseCode'] . ']' . $result['bizResponseMessage']);
        }
    }

    //支付宝支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype']) && $ctx->isMobile) {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'page', 'page' => 'wxopen'];
            }
            return $this->mobilepay($ctx, '27-3');
        } elseif (in_array('2', $this->channel['apptype'])) {
            try {
                $code_url = $this->qrcodePay($ctx);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
        } else {
            $code_url = $siteurl . 'pay/alipaywap/' . $tradeNo . '/';
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //支付宝WAP支付
    public function alipaywap(PaymentContext $ctx): array
    {
        try {
            $jump_url = $this->mobilepayurl($ctx, '27-3');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'jump', 'url' => $jump_url];
    }

    //微信支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype']) && $ctx->isMobile) {
            if ($ctx->mdevice === 'alipay') {
                return ['type' => 'page', 'page' => 'wxopen'];
            }
            return $this->mobilepay($ctx, '26-2');
        } elseif (in_array('2', $this->channel['apptype'])) {
            try {
                $code_url = $this->qrcodePay($ctx);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } elseif ($this->channel['appwxmp'] > 0) {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
        } else {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        }

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //微信WAP支付
    public function wxwappay(PaymentContext $ctx): array
    {
        try {
            $jump_url = $this->mobilepayurl($ctx, '26-2');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $jump_url];
    }

    //微信公众号
    public function wxjspay(PaymentContext $ctx): array
    {
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];

        try {
            $openid = wechat_oauth($wxinfo);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        $blocks = checkBlockUser($openid, $ctx->order['trade_no']);
        if ($blocks) return $blocks;

        $aggregatePay = 'appId=' . $wxinfo['appid'] . ',openId=' . $openid . ',limitPay=0';
        return $this->mobilepay($ctx, '26-1', $aggregatePay);
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
        $client = $this->createClient();

        $apiurl = 'https://www.99bill.com/mobilegateway/miniProgramPay.htm';
        $params = [
            'inputCharset' => '1',
            'bgUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'version' => 'mobile1.0',
            'language' => '1',
            'signType' => '4',
            'merchantAcctId' => $this->channel['appid'] . '01',
            'orderId' => $tradeNo,
            'orderAmount' => strval($ctx->order['realmoney'] * 100),
            'orderTime' => date('YmdHis'),
            'productName' => $ctx->ordername,
            'aggregatePay' => 'appId=' . $wxinfo['appid'] . ',openId=' . $openid . ',limitPay=0',
            'payType' => '26-3'
        ];
        $params['signMsg'] = $client->generateSign($params);
        $params['terminalIp'] = request()->clientip;
        $params['tdpformName'] = config_get('sitename');

        $response = get_curl($apiurl, http_build_query($params));
        $result = json_decode($response, true);
        if (isset($result['responseCode']) && $result['responseCode'] == '00') {
            return ['type' => 'json', 'data' => ['code' => 0, 'data' => $result['payInfo']]];
        } elseif (isset($result['ResponseMsg'])) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $result['ResponseMsg']]];
        } else {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '返回内容解析失败']];
        }
    }

    //云闪付支付
    public function bank(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->isMobile && (in_array('1', $this->channel['apptype']) || in_array('2', $this->channel['apptype']))) {
            if (in_array('1', $this->channel['apptype'])) {
                $payType = '00';
            } else {
                $payType = '21';
            }
            return $this->mobilepay($ctx, $payType);
        } elseif (!$ctx->isMobile && in_array('1', $this->channel['apptype'])) {
            return $this->bankpay($ctx);
        } elseif (in_array('3', $this->channel['apptype'])) {
            try {
                $code_url = $this->qrcodePay($ctx);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
            }
        } else {
            $code_url = $siteurl . 'pay/bank/' . $tradeNo . '/';
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调（H5/网银）
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $client = $this->createClient();
        $params = request()->get();
        $verify_result = $client->verifyNotify($params);

        if ($verify_result) { //验证成功
            if ($params['payResult'] == '10') {
                if ($params['orderId'] == $tradeNo) {
                    $end_time = $params['dealTime'];
                    ($this->markTrustedCallback($ctx, 'notify', 'kuaiqian-verify-notify'))(function () use ($ctx, $params, $end_time) {
                        $this->processNotify($ctx->order, $params['dealId'], null, null, null, $end_time);
                    });
                }
            }
            $redirecturl = $siteurl . 'pay/return/' . $tradeNo . '/';
            return ['type' => 'html', 'data' => '<result>1</result><redirecturl>' . $redirecturl . '</redirecturl>'];
        } else {
            return ['type' => 'html', 'data' => '<result>0</result>'];
        }
    }

    //当面付异步回调
    public function notifys(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $client = $this->createClient();
        $result = null;
        try {
            $response = $client->notifyProcess(request()->getContent(), $result);
        } catch (Exception $ex) {
            return ['type' => 'html', 'data' => $ex->getMessage()];
        }

        if ($result['body']['orderStatus'] == 'S') {
            if ($result['head']['externalRefNumber'] == $tradeNo) {
                $api_trade_no = $result['body']['idOrderCtrl'];
                $end_time = $result['body']['timeEnd'];
                $buyer = $result['body']['thirdPartyBuyerId'] ?? null;
                $bill_trade_no = $result['body']['channelTradeNo'] ?? null;
                $bill_mch_trade_no = $result['body']['idTxnCtrl'] ?? null;
                ($this->markTrustedCallback($ctx, 'notify', 'kuaiqian-facepay-notify'))(function () use ($ctx, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                });
            }
        }

        return ['type' => 'html', 'data' => $response];
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    //查单
    public function query(array $order): array
    {
        $client = $this->createClient();

        if ($order['combine'] == 1) { //当面付
            if (empty($order['api_trade_no'])) throw new \Exception('接口订单号不能为空');
            $head = [
                'version' => '1.0.0',
                'messageType' => 'A7006',
                'memberCode' => $this->channel['appid'],
                'externalRefNumber' => 'QUE' . $order['trade_no'],
            ];
            if (!empty($this->channel['appmchid'])) {
                $head['memberCode'] = $this->channel['appmchid'];
                $head['vendorMemberCode'] = $this->channel['appid'];
            }
            $body = [
                'merchantId' => $this->channel['merchant_id'],
                'terminalId' => $this->channel['terminal_id'],
                'idOrderCtrl' => $order['api_trade_no'],
            ];
            $result = $client->execute($head, $body);
            if ($result['bizResponseCode'] == '0000') {
                return [
                    'api_trade_no' => $result['idOrderCtrl'],
                    'status' => $result['txnStatus'] == 'S' ? 1 : 0,
                    'money' => $result['amount'] / 100,
                    'buyer' => $result['thirdPartyBuyerId'] ?? '',
                    'bill_trade_no' => $result['channelTradeNo'] ?? '',
                    'bill_mch_trade_no' => $result['idTxnCtrl'] ?? '',
                    'endtime' => $result['TimeEnd'] ?? '',
                ];
            } else {
                throw new \Exception($result['bizResponseMessage'] ?? '返回数据解析失败');
            }
        } else {
            $head = [
                'version' => '1.0.0',
                'messageType' => 'F0003',
                'memberCode' => $this->channel['appid'],
                'externalRefNumber' => 'QUE' . $order['trade_no'],
            ];
            $body = [
                'merchantAcctId' => $this->channel['appid'] . '01',
                'queryType' => '0',
                'queryMode' => '1',
                'orderId' => $order['trade_no'],
            ];
            $result = $client->execute($head, $body);
            if ($result['bizResponseCode'] == '0000') {
                if (empty($result['resultList'])) throw new \Exception('返回订单列表为空');
                $data = $result['resultList'][0];
                return [
                    'api_trade_no' => $data['txnNo'],
                    'status' => $data['payResult'] == '10' ? 1 : 0,
                    'money' => $data['amount'] / 100,
                    'endtime' => $data['txnTime'] ?? '',
                ];
            } else {
                throw new \Exception($result['bizResponseMessage'] ?? '返回数据解析失败');
            }
        }
        
    }

    //退款（H5/网银）
    public function refund(array $order): array
    {
        $client = $this->createClient();

        $head = [
            'version' => '1.0.0',
            'messageType' => 'F0001',
            'memberCode' => $this->channel['appid'],
            'externalRefNumber' => $order['refund_no'],
        ];
        $body = [
            'merchantAcctId' => $this->channel['appid'],
            'txnType' => 'bill_drawback_api_1',
            'amount' => strval($order['refundmoney'] * 100),
            'entryTime' => substr($order['trade_no'], 0, 14),
            'orgOrderId' => $order['trade_no'],
        ];

        try {
            $result = $client->execute($head, $body);
            if ($result['bizResponseCode'] == '0000') {
                return ['code' => 0];
            } else {
                return ['code' => -1, 'msg' => '[' . $result['bizResponseCode'] . ']' . $result['bizResponseMessage']];
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //当面付退款
    public function refund_combine(array $order): array
    {
        $client = $this->createClient();

        $head = [
            'version' => '1.0.0',
            'messageType' => 'A7003',
            'memberCode' => $this->channel['appid'],
            'externalRefNumber' => $order['refund_no'],
        ];
        if (!empty($this->channel['appmchid'])) {
            $head['memberCode'] = $this->channel['appmchid'];
            $head['vendorMemberCode'] = $this->channel['appid'];
        }
        $body = [
            'merchantId' => $this->channel['merchant_id'],
            'terminalId' => $this->channel['terminal_id'],
            'amount' => strval($order['refundmoney'] * 100),
            'origOrderCtrl' => $order['api_trade_no'],
            'origRefNumber' => $order['trade_no'],
            'tr3Url' => config_get('localurl') . 'pay/notifys/' . $order['trade_no'] . '/',
        ];

        try {
            $result = $client->execute($head, $body);
            if ($result['bizResponseCode'] == '0000') {
                return ['code' => 0];
            } else {
                return ['code' => -1, 'msg' => '[' . $result['bizResponseCode'] . ']' . $result['bizResponseMessage']];
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账
    public function transfer(array $bizParam): array
    {
        try {
            $bank_info = getBankCardInfo($bizParam['payee_account']);
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }

        $client = $this->createClient();
        $head = [
            'version' => '1.0.0',
            'messageType' => 'C1017',
            'memberCode' => $this->channel['appid'],
            'externalRefNumber' => $bizParam['out_biz_no'],
        ];
        $body = [
            'amount' => strval($bizParam['money'] * 100),
            'cardHolderName' => $bizParam['payee_real_name'],
            'bankName' => $bank_info['bank_name'],
            'pan' => $bizParam['payee_account'],
            'reMark' => $bizParam['transfer_desc'],
            'notifyUrl' => config_get('localurl') . 'pay/transfernotify/' . $this->channel['id'] . '/',
        ];

        try {
            $result = $client->execute($head, $body);
            if ($result['bizResponseCode'] == '0000') {
                return ['code' => 0, 'status' => 0, 'orderid' => $bizParam['out_biz_no'], 'paydate' => date('Y-m-d H:i:s')];
            } else {
                return ['code' => -1, 'errcode' => $result['bizResponseCode'], 'msg' => '[' . $result['bizResponseCode'] . ']' . $result['bizResponseMessage']];
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账查询
    public function transfer_query(array $bizParam): array
    {
        $client = $this->createClient();
        $head = [
            'version' => '1.0.0',
            'messageType' => 'C1018',
            'memberCode' => $this->channel['appid'],
        ];
        $body = [
            'pageNo' => 1,
            'pageSize' => 1,
            'externalRefNumber' => $bizParam['out_biz_no'],
        ];

        try {
            $result = $client->execute($head, $body);
            if (!empty($result['detailedList'])) {
                $detail = $result['detailedList'][0];
                $paydate = $detail['endDate'];
                $errmsg = null;
                if ($detail['txnStatus'] == 'S') {
                    $status = 1;
                } elseif ($detail['txnStatus'] == 'F' || $detail['txnStatus'] == 'R') {
                    $status = 2;
                    $errmsg = $detail['failMessage'] ?? '';
                } else {
                    $status = 0;
                }
                return ['code' => 0, 'status' => $status, 'amount' => $detail['amount'], 'errmsg' => $errmsg, 'paydate' => $paydate];
            } else {
                return ['code' => -1, 'msg' => '[' . $result['bizResponseCode'] . ']' . $result['bizResponseMessage']];
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账异步回调
    public function transfernotify(PaymentContext $ctx): array
    {
        $client = $this->createClient();
        $result = null;
        try {
            $response = $client->notifyProcessTransfer(request()->getContent(), $result);
        } catch (Exception $ex) {
            return ['type' => 'html', 'data' => $ex->getMessage()];
        }

        $out_biz_no = $result['head']['externalRefNumber'];
        $errmsg = null;
        if ($result['body']['txnStatus'] == 'S') {
            $status = 1;
        } elseif ($result['body']['txnStatus'] == 'F' || $result['body']['txnStatus'] == 'R') {
            $status = 2;
            $errmsg = $result['body']['bizResponseMessage'] ?? '';
        } else {
            return ['type' => 'html', 'data' => $response];
        }
        ($this->markTrustedCallback($ctx, 'transfernotify', 'kuaiqian-transfer-notify'))(function () use ($out_biz_no, $status, $errmsg) {
            $this->processTransfer($out_biz_no, $status, $errmsg);
        });

        return ['type' => 'html', 'data' => $response];
    }

    //投诉通知回调
    public function complainnotify(PaymentContext $ctx): array
    {
        $client = $this->createClient();
        $result = null;
        try {
            $response = $client->notifyProcessComplain(request()->getContent(), $result);
        } catch (Exception $ex) {
            return ['type' => 'html', 'data' => $ex->getMessage()];
        }

        if ($result['body']['complaintSource'] == 'ALIPAY_BILL') {
            return ['type' => 'html', 'data' => $response];
        }
        $model = \app\logic\ComplainLogic::getModel($this->channel);
        $model->refreshNewInfo($result['body']['complaintNo'], $result['body']['actionType']);

        return ['type' => 'html', 'data' => $response];
    }
}
