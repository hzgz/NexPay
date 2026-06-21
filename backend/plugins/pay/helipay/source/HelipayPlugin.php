<?php

declare(strict_types=1);

namespace plugins\payment\helipay;

use app\common\PaymentContext;
use app\common\BasePayment;
use think\facade\Db;
use Exception;

/**
 * http://xianshang-doc.helipay.com/server/?s=/api/extLogin/bySecretKey&username=ping_user01&time=2025060415&token=28d95b589fe23e505f406d746549ac56
 */
class HelipayPlugin extends BasePayment
{
    const API_URL = 'http://pay.trx.helipay.com/trx/app/interface.action';

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
            } elseif ($ctx->isMobile && (in_array('3', $this->channel['apptype']) || in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0)) {
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

    private function make_sign($param, $key)
    {
        $signstr = '';
        foreach ($param as $k => $v) {
            if ($k != "sign") {
                $signstr .= '&' . $v;
            }
        }
        $signstr .= '&' . $key;
        return md5($signstr);
    }

    private function getReportId($reportid, PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];
        if (!empty($ctx->order['param']) && is_numeric($ctx->order['param'])) {
            return $ctx->order['param'];
        }
        if (strpos($reportid, ',')) {
            $reportids = explode(',', $reportid);
            $reportid = $reportids[array_rand($reportids)];
        }
        Db::name('order')->where('trade_no', $tradeNo)->update(['param' => $reportid]);
        return $reportid;
    }

    //扫码支付预下单
    private function qrcode($pay_type, PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'P1_bizType' => 'AppPay',
            'P2_orderId' => $tradeNo,
            'P3_customerNumber' => $this->channel['appid'],
            'P4_payType' => 'SCAN',
            'P5_orderAmount' => $ctx->order['realmoney'],
            'P6_currency' => 'CNY',
            'P7_authcode' => '1',
            'P8_appType' => $pay_type,
            'P9_notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'P10_successToUrl' => '',
            'P11_orderIp' => request()->clientip,
            'P12_goodsName' => $ctx->ordername,
            'P13_goodsDetail' => '',
            'P14_desc' => '',
        ];
        if (!empty($this->channel['appmchid'])) $params['P3_customerNumber'] = $this->channel['appmchid'];
        $params['sign'] = $this->make_sign($params, $this->channel['appkey']);
        $params['signatureType'] = 'MD5';
        if (!empty($this->channel['reportid'])) $params['P15_subMerchantId'] = $this->getReportId($this->channel['reportid'], $ctx);
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx);
        }

        return self::lockPayData($tradeNo, function () use ($params, $tradeNo) {
            $response = get_curl(self::API_URL, http_build_query($params));
            if (!$response) throw new Exception('接口请求失败');
            $result = json_decode($response, true);
            if (isset($result["rt2_retCode"]) && ($result["rt2_retCode"] == '0000' || $result["rt2_retCode"] == '0001')) {
                $this->updateOrder($tradeNo, $result['rt6_serialNumber']);
                return $result['rt8_qrcode'];
            } else {
                throw new Exception($result["rt3_retMsg"] ?? '返回数据解析失败');
            }
        });
    }

    //公众号支付预下单
    private function publicpay($pay_type, $appid, $openid, PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'P1_bizType' => 'AppPayPublic',
            'P2_orderId' => $tradeNo,
            'P3_customerNumber' => $this->channel['appid'],
            'P4_payType' => 'PUBLIC',
            'P5_appid' => $appid,
            'P6_deviceInfo' => 'WEB',
            'P7_isRaw' => $openid == '1' ? '0' : '1',
            'P8_openid' => $openid,
            'P9_orderAmount' => $ctx->order['realmoney'],
            'P10_currency' => 'CNY',
            'P11_appType' => $pay_type,
            'P12_notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'P13_successToUrl' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'P14_orderIp' => request()->clientip,
            'P15_goodsName' => $ctx->ordername,
            'P16_goodsDetail' => '',
            'P17_limitCreditPay' => '',
            'P18_desc' => '',
        ];
        if (!empty($this->channel['appmchid'])) $params['P3_customerNumber'] = $this->channel['appmchid'];
        $params['sign'] = $this->make_sign($params, $this->channel['appkey']);
        $params['signatureType'] = 'MD5';
        if (!empty($this->channel['reportid2'])) $params['P20_subMerchantId'] = $this->getReportId($this->channel['reportid2'], $ctx);
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx);
        }

        return self::lockPayData($tradeNo, function () use ($params, $tradeNo) {
            $response = get_curl(self::API_URL, http_build_query($params));
            if (!$response) throw new Exception('接口请求失败');
            $result = json_decode($response, true);
            if (isset($result["rt2_retCode"]) && ($result["rt2_retCode"] == '0000' || $result["rt2_retCode"] == '0001')) {
                $this->updateOrder($tradeNo, $result['rt6_serialNumber']);
                return $result['rt10_payInfo'];
            } else {
                throw new Exception($result["rt3_retMsg"] ?? '返回数据解析失败');
            }
        });
    }

    //小程序支付预下单
    private function appletpay($pay_type, $appid, $openid, PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'P1_bizType' => 'AppPayApplet',
            'P2_orderId' => $tradeNo,
            'P3_customerNumber' => $this->channel['appid'],
            'P4_payType' => 'APPLET',
            'P5_appid' => $appid,
            'P6_deviceInfo' => 'WEB',
            'P7_isRaw' => '1',
            'P8_openid' => $openid,
            'P9_orderAmount' => $ctx->order['realmoney'],
            'P10_currency' => 'CNY',
            'P11_appType' => $pay_type,
            'P12_notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'P13_successToUrl' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'P14_orderIp' => request()->clientip,
            'P15_goodsName' => $ctx->ordername,
            'P16_goodsDetail' => '',
            'P17_limitCreditPay' => '',
            'P18_desc' => '',
        ];
        if (!empty($this->channel['appmchid'])) $params['P3_customerNumber'] = $this->channel['appmchid'];
        $params['sign'] = $this->make_sign($params, $this->channel['appkey']);
        $params['signatureType'] = 'MD5';
        if (!empty($this->channel['reportid2'])) $params['P20_subMerchantId'] = $this->getReportId($this->channel['reportid2'], $ctx);
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx);
        }

        return self::lockPayData($tradeNo, function () use ($params, $tradeNo) {
            $response = get_curl(self::API_URL, http_build_query($params));
            if (!$response) throw new Exception('接口请求失败');
            $result = json_decode($response, true);
            if (isset($result["rt2_retCode"]) && ($result["rt2_retCode"] == '0000' || $result["rt2_retCode"] == '0001')) {
                $this->updateOrder($tradeNo, $result['rt6_serialNumber']);
                return $result['rt10_payInfo'];
            } else {
                throw new Exception($result["rt3_retMsg"] ?? '返回数据解析失败');
            }
        });
    }

    //H5支付预下单
    private function h5pay($pay_type, PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'P1_bizType' => 'AppPayH5WFT',
            'P2_orderId' => $tradeNo,
            'P3_customerNumber' => $this->channel['appid'],
            'P4_orderAmount' => $ctx->order['realmoney'],
            'P5_currency' => 'CNY',
            'P6_orderIp' => request()->clientip,
            'P7_notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'P8_appPayType' => $pay_type,
            'P9_payType' => 'WAP',
            'P10_appName' => config_get('sitename'),
            'P11_deviceInfo' => 'AND_WAP',
            'P12_applicationId' => request()->siteurl,
            'P13_goodsName' => $ctx->ordername,
            'P14_goodsDetail' => '',
            'P15_desc' => '',
        ];
        if (!empty($this->channel['appmchid'])) $params['P3_customerNumber'] = $this->channel['appmchid'];
        $params['sign'] = $this->make_sign($params, $this->channel['appkey']);
        $params['signatureType'] = 'MD5';
        if (!empty($this->channel['reportid3'])) $params['subMerchantId'] = $this->getReportId($this->channel['reportid3'], $ctx);
        $params['isRaw'] = '0';
        $params['nonRawMode'] = '0';
        $params['appId'] = $this->channel['h5appid'];
        $params['successToUrl'] = request()->siteurl . 'pay/return/' . $tradeNo . '/';
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx);
        }

        return self::lockPayData($tradeNo, function () use ($params, $tradeNo) {
            $response = get_curl(self::API_URL, http_build_query($params));
            if (!$response) throw new Exception('接口请求失败');
            $result = json_decode($response, true);
            if (isset($result["rt2_retCode"]) && ($result["rt2_retCode"] == '0000' || $result["rt2_retCode"] == '0001')) {
                $this->updateOrder($tradeNo, $result['rt6_serialNumber']);
                return $result['rt8_payInfo'];
            } else {
                throw new Exception($result["rt3_retMsg"] ?? '返回数据解析失败');
            }
        });
    }

    private function handleProfits(&$param, PaymentContext $ctx)
    {
        $psreceiver = \app\logic\ProfitSharingLogic::getReceiver($ctx->order['profits']);
        if ($psreceiver && $psreceiver['mode'] == 0) {
            $rules = [];
            foreach ($psreceiver['info'] as $receiver) {
                $psmoney = round(floor($ctx->order['realmoney'] * $receiver['rate']) / 100, 2);
                $rules[] = [
                    'splitBillMerchantNo' => $receiver['account'],
                    'splitBillAmount' => $psmoney,
                ];
            }
            $param['splitBillType'] = 'FIXED_AMOUNT';
            $des = new DES3();
            $param['ruleJson'] = $des->encrypt2(json_encode($rules), $this->channel['appsecret']);
        }
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = request()->siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $code_url = $this->qrcode('ALIPAY', $ctx);
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
            $pay_info = $this->publicpay('ALIPAY', '1', $user_id, $ctx);
            $pay_info = json_decode($pay_info, true);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $pay_info['tradeNO']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $pay_info['tradeNO'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype'])) {
            try {
                $code_url = $this->qrcode('WXPAY', $ctx);
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
                    $code_url = $this->publicpay('WXPAY', '1', '1', $ctx);
                } catch (Exception $ex) {
                    return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
                }
            }
        } else {
            try {
                $code_url = $this->h5pay('WXPAY', $ctx);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
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

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

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
            $pay_info = $this->publicpay('WXPAY', $wxinfo['appid'], $openid, $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $pay_info];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $pay_info, 'redirect_url' => $redirect_url]];
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
            $pay_info = $this->appletpay('WXPAY', $wxinfo['appid'], $openid, $ctx);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($pay_info, true)]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        if (in_array('3', $this->channel['apptype'])) {
            try {
                $code_url = $this->h5pay('WXPAY', $ctx);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            if (strpos($code_url, 'jump-mp.html?') !== false) {
                $code_url = $this->getUrlScheme($code_url);
                return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
            }
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

    private function getUrlScheme($code_url)
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1';
        $querystr = parse_url($code_url, PHP_URL_QUERY);
        parse_str($querystr, $data);
        $url = 'https://pay.trx.helipay.com/trx/sd/getResourceCloudEnv';
        $params = [
            'prepayid' => $data['prepayid'],
            'appid' => $data['appid'],
            'appUserOsType' => 'IOS',
            'browser' => $ua,
            'netWorkInfo' => '网络类型-4g,最大带宽-10Mbps,网络往返时间-200ms',
            'occurrenceDate' => date('Y-m-d H:i:s'),
            'clientErrorInfo' => '',
        ];
        $response = get_curl($url, json_encode($params, JSON_UNESCAPED_UNICODE), $code_url, 0, 0, $ua, 0, ['Content-Type: application/json; charset=utf-8', 'Origin: https://h5pay.helipay.com']);
        $result = json_decode($response, true);
        if (isset($result['success']) && $result['success'] == true) {
            $resourceCloudEnvId = $result['resourceCloudEnvId'];
        } else {
            throw new Exception('获取urlScheme失败,getResourceCloudEnv:' . $response);
        }

        $url = 'https://servicewechat.com/wxa-qbase/jsoperatewxdata';
        $call_id = getMillisecond() . '-' . getSid();
        $params = [
            'appid' => $data['appid'],
            'data' => [
                'qbase_api_name' => 'tcbapi_slowcallfunction_v2',
                'qbase_req' => json_encode(['function_name' => 'public', 'data' => json_encode(['action' => 'getUrlScheme', 'query' => 'appid=' . $data['appid'] . '&apptype=TH5&prepayid=' . $data['prepayid'] . '&sign=' . $data['sign'], 'options' => ['envVersion' => 'release']]), 'action' => 1, 'scene' => 1, 'call_id' => $call_id, 'cloudid_list' => []]),
                'qbase_options' => ['appid' => $data['appid'], 'env' => $resourceCloudEnvId],
                'qbase_meta' => ['session_id' => getMillisecond(), 'sdk_version' => 'wx-web-sdk/1.1.0 (1602475903000)', 'filter_user_info' => false],
                'cli_req_id' => $call_id,
            ],
        ];
        $response = get_curl($url, json_encode($params, JSON_UNESCAPED_UNICODE), $code_url, 0, 0, $ua, 0, ['Content-Type: application/json; charset=utf-8', 'Origin: https://h5pay.helipay.com']);
        $result = json_decode($response, true);
        if (isset($result['base_resp']['ret']) && $result['base_resp']['ret'] == 0 && isset($result['data'])) {
            $data = json_decode($result['data'], true);
            if (isset($data['data'])) {
                $res = json_decode($data['data'], true);
                if (isset($res['openlink'])) {
                    return $res['openlink'];
                } else {
                    throw new Exception('获取urlScheme失败,jsoperatewxdata:' . $data['data']);
                }
            } else {
                throw new Exception('获取urlScheme失败,jsoperatewxdata:' . $result['data']);
            }
        } else {
            throw new Exception('获取urlScheme失败,jsoperatewxdata:' . $response);
        }
    }

    //QQ扫码支付
    public function qqpay(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        try {
            $code_url = $this->qrcode('QQPAY', $ctx);
        } catch (Exception $ex) {
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

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode('UNIONPAY', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $post = request()->post();

        $sign_param = [
            'rt1_customerNumber' => $post['rt1_customerNumber'],
            'rt2_orderId' => $post['rt2_orderId'],
            'rt3_systemSerial' => $post['rt3_systemSerial'],
            'rt4_status' => $post['rt4_status'],
            'rt5_orderAmount' => $post['rt5_orderAmount'],
            'rt6_currency' => $post['rt6_currency'],
            'rt7_timestamp' => $post['rt7_timestamp'],
            'rt8_desc' => $post['rt8_desc'],
        ];
        $sign = $this->make_sign($sign_param, $this->channel['appkey']);

        if ($sign === $post["sign"]) {
            if ($post['rt4_status'] == 'SUCCESS') {
                $out_trade_no = $post['rt2_orderId'];
                $api_trade_no = $post['rt3_systemSerial'];
                $money = $post['rt5_orderAmount'];
                $buyer = $post['rt19_subOpenId'] ?? '';
                $bill_trade_no = $post['rt17_outTransactionOrderId'] ?? '';
                $end_time = $post['rt12_orderCompleteDate'];

                if ($out_trade_no == $tradeNo) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
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
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $params = [
            'P1_bizType' => 'AppPayQuery',
            'P2_orderId' => $order['trade_no'],
            'P3_customerNumber' => $this->channel['appid'],
        ];
        $params['sign'] = $this->make_sign($params, $this->channel['appkey']);
        $params['signatureType'] = 'MD5';
        $response = get_curl(self::API_URL, http_build_query($params));
        $result = json_decode($response, true);
        if (isset($result['rt2_retCode']) && $result['rt2_retCode'] == '0000') {
            return [
                'api_trade_no' => $result['rt6_serialNumber'],
                'status' => $result['rt7_orderStatus'] == 'SUCCESS' ? 1 : 0,
                'money' => $result['rt8_orderAmount'],
                'buyer' => $result['rt11_openId'] ?? '',
                'bill_trade_no' => $result['rt18_outTransactionOrderId'] ?? '',
                'endtime' => $result['rt13_orderCompleteDate'] ?? '',
            ];
        } else {
            throw new \Exception($result['rt3_retMsg'] ?? '返回数据解析失败');
        }
    }

    //退款
    public function refund($order): array
    {
        $params = [
            'P1_bizType' => 'AppPayRefund',
            'P2_orderId' => $order['trade_no'],
            'P3_customerNumber' => $this->channel['appid'],
            'P4_refundOrderId' => $order['refund_no'],
            'P5_amount' => $order['refundmoney'],
            'P6_callbackUrl' => '',
        ];
        if (!empty($this->channel['appmchid'])) $params['P3_customerNumber'] = $this->channel['appmchid'];
        $params['sign'] = $this->make_sign($params, $this->channel['appkey']);
        $params['signatureType'] = 'MD5';
        if ($order['profits'] > 0) {
            $psorder = \app\logic\ProfitSharingLogic::getOrder($order['trade_no']);
            if ($psorder && $psorder['rdata']) {
                $leftmoney = (float)$order['refundmoney'];
                $rules = [];
                foreach ($psorder['rdata'] as $receiver) {
                    $money = $receiver['money'] > $leftmoney ? $leftmoney : $receiver['money'];
                    $rules[] = [
                        'merchantNo' => $receiver['account'],
                        'refundAmount' => round($money, 2),
                    ];
                    $leftmoney -= $money;
                    if ($leftmoney <= 0) break;
                }
                if ($leftmoney > 0) {
                    $rules[] = [
                        'merchantNo' => $params['P3_customerNumber'],
                        'refundAmount' => round($leftmoney, 2),
                    ];
                }
                $des = new DES3();
                $params['ruleJson'] = $des->encrypt2(json_encode($rules), $this->channel['appsecret']);
            }
        }

        $response = get_curl(self::API_URL, http_build_query($params));
        $result = json_decode($response, true);
        if (isset($result["rt2_retCode"]) && ($result["rt2_retCode"] == '0000' || $result["rt2_retCode"] == '0001')) {
            return ['code' => 0, 'trade_no' => $result['rt7_serialNumber'], 'refund_fee' => $result['rt8_amount']];
        } elseif (isset($result['rt3_retMsg'])) {
            return ['code' => -1, 'msg' => $result['rt3_retMsg']];
        } else {
            return ['code' => -1, 'msg' => '未知错误'];
        }
    }

    //进件异步回调
    public function applynotify(PaymentContext $ctx): array
    {
        if (!request()->post('data')) return ['type' => 'html', 'data' => 'no data'];

        $sign = md5(request()->post('data') . '&' . $this->channel['public_signkey']);
        if ($sign === request()->post('sign')) {
            $des = new DES3();
            $decrypted = $des->decrypt2(request()->post('data'), $this->channel['public_enckey']);
            if ($decrypted) {
                $data = json_decode($decrypted, true);
                $model = \app\logic\ApplymentLogic::getModel2($this->channel);
                if ($model) $model->notify($data);
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //结算异步回调
    public function settlenotify(PaymentContext $ctx): array
    {
        if (!request()->post('data')) return ['type' => 'html', 'data' => 'no data'];

        unset($arr['rt3_retMsg']);
        $sign = $this->make_sign(request()->post(), $this->channel['settle_signkey']);
        if ($sign === request()->post('sign')) {
            $records = json_decode(request()->post('rt4_settleRecords'), true);
            if (!empty($records)) {
                $srow = $records[0];
                $trade_row = Db::name('applytrade')->where('orderid', $srow['orderId'])->find();
                if ($trade_row && $trade_row['statustext'] != $srow['orderStatus']) {
                    $settlementStatusCode = ['MANUAL' => 0, 'FAILED' => 2, 'DONE' => 1, 'DOING' => 0, 'INIT' => 0];
                    Db::name('applytrade')->where('id', $trade_row['id'])->update([
                        'money' => $srow['settlementAmount'],
                        'fee' => $srow['settleFee'] ?? null,
                        'status' => $settlementStatusCode[$srow['orderStatus']] ?? 0,
                        'statustext' => $srow['orderStatus'],
                        'reason' => $srow['reason'] ?? null,
                        'endtime' => !empty($srow['completeDate']) ? $srow['completeDate'] : null,
                    ]);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //转账异步回调
    public function tradenotify(PaymentContext $ctx): array
    {
        $post = request()->post();
        if (!$post) return ['type' => 'html', 'data' => 'no data'];

        uksort($post, function ($a, $b) {
            if ($a == 'sign') return 1;
            if ($b == 'sign') return -1;
            $na = substr($a, 2, strpos($a, '_') - 2);
            $nb = substr($b, 2, strpos($b, '_') - 2);
            return $na - $nb;
        });
        $sign = $this->make_sign($post, $this->channel['accpay_signkey']);
        if ($sign === $post["sign"]) {
            $trade_row = Db::name('applytrade')->where('orderid', $post['rt7_orderId'])->find();
            if ($trade_row && $trade_row['statustext'] != $post['rt10_orderStatus']) {
                $accountPayStatusCode = ['RECEIVE' => 0, 'IN_ESCROW' => 0, 'ESCROW_CANCELLED' => 2, 'DOING' => 0, 'SUCCESS' => 1, 'FAIL' => 2];
                Db::name('applytrade')->where('id', $trade_row['id'])->update([
                    'status' => $accountPayStatusCode[$post['rt10_orderStatus']] ?? 0,
                    'statustext' => $post['rt10_orderStatus'],
                    'reason' => $post['rt12_reason'] ?? null,
                    'endtime' => date('Y-m-d H:i:s'),
                ]);
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //投诉异步回调
    public function complainnotify(PaymentContext $ctx): array
    {
        if (!request()->post('data')) return ['type' => 'html', 'data' => 'no data'];

        $sign = md5(request()->post('data') . '&' . $this->channel['public_signkey']);
        if ($sign === request()->post('sign')) {
            $des = new DES3();
            $decrypted = $des->decrypt2(request()->post('data'), $this->channel['public_enckey']);
            if ($decrypted) {
                $data = json_decode($decrypted, true);
                $channel = $this->channel;
                if ($data['appPayType'] == 'ALIPAY') $channel['type'] = 1;
                else $channel['type'] = 2;
                $channel['appmchid'] = $data['merchantNo'];
                $model = \app\logic\ComplainLogic::getModel($channel);
                $model->refreshNewInfo($data['complaintId']);
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }
}
