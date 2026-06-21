<?php

declare(strict_types=1);

namespace plugins\payment\jeepay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class JeepayPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('3', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile) {
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

        if ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('3', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    private function makeSign(array $param, string $key): string
    {
        ksort($param);
        $signstr = '';
        foreach ($param as $k => $v) {
            if ($k != "sign" && $v !== null && $v !== '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'key=' . $key;
        return strtoupper(md5($signstr));
    }

    //下单通用
    private function addOrder(PaymentContext $ctx, string $wayCode, ?string $channelExtra = null): array
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        if (request()->get('r') == 1) {
            $returnUrl = $siteurl . 'pay/ok/' . $tradeNo . '/';
        } else {
            $returnUrl = $siteurl . 'pay/return/' . $tradeNo . '/';
        }
        $apiurl = $this->channel['appurl'] . 'api/pay/unifiedOrder';
        $param = [
            'mchNo' => $this->channel['appmchid'],
            'appId' => $this->channel['appid'],
            'mchOrderNo' => $tradeNo,
            'wayCode' => $wayCode,
            'amount' => round($ctx->order['realmoney'] * 100),
            'currency' => 'cny',
            'clientIp' => request()->clientip,
            'subject' => $ctx->ordername,
            'body' => $ctx->ordername,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returnUrl' => $returnUrl,
            'reqTime' => getMillisecond(),
            'version' => '1.0',
            'signType' => 'MD5',
        ];
        if ($channelExtra) $param['channelExtra'] = $channelExtra;

        $param['sign'] = $this->makeSign($param, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($apiurl, $param) {
            $data = get_curl($apiurl, json_encode($param), 0, 0, 0, 0, 0, ['Content-Type: application/json']);
            if (!$data) throw new Exception('接口请求失败');
            $result = json_decode($data, true);

            if (isset($result['code']) && $result['code'] == 0) {
                if (isset($result['data']['errMsg'])) {
                    throw new Exception('[' . $result['data']['errCode'] . ']' . $result['data']['errMsg']);
                } elseif (isset($result['data']['error'])) {
                    throw new Exception($result['data']['error']);
                }
                return [strtolower($result['data']['payDataType']), $result['data']['payData']];
            } else {
                throw new Exception($result['msg'] ?? '返回数据解析失败');
            }
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('3', $this->channel['apptype']) && $ctx->isMobile) {
            $wayCode = 'ALI_WAP';
        } elseif (in_array('2', $this->channel['apptype']) && !$ctx->isMobile) {
            $wayCode = 'ALI_PC';
        } elseif (in_array('1', $this->channel['apptype'])) {
            $wayCode = 'ALI_QR';
        } elseif (in_array('7', $this->channel['apptype'])) {
            $wayCode = 'ALI_APP';
        } elseif (in_array('4', $this->channel['apptype'])) {
            $qrcode_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $qrcode_url];
        } elseif (in_array('5', $this->channel['apptype'])) {
            $wayCode = 'QR_CASHIER';
        } elseif (in_array('6', $this->channel['apptype'])) {
            $wayCode = 'WEB_CASHIER';
        } elseif (in_array('3', $this->channel['apptype'])) {
            $qrcode_url = $siteurl . 'pay/alipay/' . $tradeNo . '/?r=1';
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $qrcode_url];
        } else {
            return ['type' => 'error', 'msg' => '当前支付通道没有开启的支付方式'];
        }

        try {
            list($type, $payData) = $this->addOrder($ctx, $wayCode);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }

        if ($wayCode == 'QR_CASHIER' && $ctx->mdevice !== 'alipay') {
            $type = 'codeurl';
        }

        if ($type == 'payurl') {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'page', 'page' => 'wxopen'];
            }
            return ['type' => 'jump', 'url' => $payData];
        } elseif ($type == 'form') {
            return ['type' => 'html', 'url' => $payData];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $payData];
        }
    }

    //支付宝生活号支付
    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $openId = request()->get('channelUserId');
        if (!$openId) {
            $apiurl = $this->channel['appurl'] . 'api/channelUserId/jump';
            $redirect_url = (is_https() ? 'https://' : 'http://') . request()->host() . request()->url();
            $param = [
                'mchNo' => $this->channel['appmchid'],
                'appId' => $this->channel['appid'],
                'ifCode' => 'AUTO',
                'redirectUrl' => $redirect_url,
                'reqTime' => getMillisecond(),
                'version' => '1.0',
                'signType' => 'MD5',
            ];
            $param['sign'] = $this->makeSign($param, $this->channel['appkey']);
            $jump_url = $apiurl . '?' . http_build_query($param);
            return ['type' => 'jump', 'url' => $jump_url];
        }
        $blocks = checkBlockUser($openId, $tradeNo);
        if ($blocks) return $blocks;

        try {
            $extra = json_encode(['buyerUserId' => $openId]);
            list($type, $payData) = $this->addOrder($ctx, 'ALI_JSAPI', $extra);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }

        if ($type == 'payurl') {
            return ['type' => 'jump', 'url' => $payData];
        } elseif ($type == 'form') {
            return ['type' => 'html', 'url' => $payData];
        } else {
            if (request()->get('d') == '1') {
                $redirect_url = 'data.backurl';
            } else {
                $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
            }
            $arr = json_decode($payData, true);
            return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $arr['alipayTradeNo'], 'redirect_url' => $redirect_url]];
        }
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype'])) {
            $wayCode = 'WX_NATIVE';
        } elseif (in_array('3', $this->channel['apptype'])) {
            $qrcode_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $qrcode_url];
        } elseif (in_array('4', $this->channel['apptype']) || in_array('7', $this->channel['apptype'])) {
            $qrcode_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $qrcode_url];
        } elseif (in_array('5', $this->channel['apptype'])) {
            $wayCode = 'QR_CASHIER';
        } elseif (in_array('6', $this->channel['apptype'])) {
            $wayCode = 'WEB_CASHIER';
        } else {
            return ['type' => 'error', 'msg' => '当前支付通道没有开启的支付方式'];
        }
        try {
            list($type, $payData) = $this->addOrder($ctx, $wayCode);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if ($wayCode == 'QR_CASHIER' && $ctx->mdevice !== 'wechat') {
            $type = 'codeurl';
        }
        if ($type == 'payurl') {
            return ['type' => 'jump', 'url' => $payData];
        } elseif ($type == 'form') {
            return ['type' => 'html', 'url' => $payData];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $payData];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $payData];
        }
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        if (in_array('1', $this->channel['apptype'])) {
            $wayCode = 'YSF_NATIVE';
        } elseif (in_array('5', $this->channel['apptype'])) {
            $wayCode = 'QR_CASHIER';
        } elseif (in_array('6', $this->channel['apptype'])) {
            $wayCode = 'WEB_CASHIER';
        } else {
            return ['type' => 'error', 'msg' => '当前支付通道没有开启的支付方式'];
        }

        try {
            list($type, $payData) = $this->addOrder($ctx, $wayCode);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        if ($type == 'payurl') {
            return ['type' => 'jump', 'url' => $payData];
        } elseif ($type == 'form') {
            return ['type' => 'html', 'url' => $payData];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $payData];
        }
    }

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        //①、获取用户openid
        if ($this->channel['appwxmp'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
            $openid = wechat_oauth($wxinfo);
        } else {
            $openid = request()->get('channelUserId');
            if (!$openid) {
                $apiurl = $this->channel['appurl'] . 'api/channelUserId/jump';
                $redirect_url = (is_https() ? 'https://' : 'http://') . request()->host() . request()->url();
                $param = [
                    'mchNo' => $this->channel['appmchid'],
                    'appId' => $this->channel['appid'],
                    'ifCode' => 'AUTO',
                    'redirectUrl' => $redirect_url,
                    'reqTime' => getMillisecond(),
                    'version' => '1.0',
                    'signType' => 'MD5',
                ];
                $param['sign'] = $this->makeSign($param, $this->channel['appkey']);
                $jump_url = $apiurl . '?' . http_build_query($param);
                return ['type' => 'jump', 'url' => $jump_url];
            }
        }

        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        //②、统一下单
        try {
            $extra = json_encode(['openid' => $openid]);
            list($type, $jsApiParameters) = $this->addOrder($ctx, 'WX_JSAPI', $extra);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if (request()->get('d') == 1) {
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
            $extra = json_encode(['openid' => $openid]);
            list($type, $jsApiParameters) = $this->addOrder($ctx, 'WX_LITE', $extra);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($jsApiParameters, true)]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype'])) { //H5支付
            try {
                list($type, $jump_url) = $this->addOrder($ctx, 'WX_H5');
                return ['type' => 'jump', 'url' => $jump_url];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信H5支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('7', $this->channel['apptype'])) { //APP支付
            try {
                list($type, $code_url) = $this->addOrder($ctx, 'WX_APP');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信H5支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'qrcode', 'page' => 'wxpay_h5', 'url' => $code_url];
        } elseif (in_array('4', $this->channel['apptype']) && $this->channel['appwxa'] > 0) { //小程序支付
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } elseif (in_array('3', $this->channel['apptype'])) { //公众号支付
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return $this->wxpay($ctx);
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (request()->isPost()) {
            $arr = request()->post();
        } else {
            $arr = request()->get();
        }
        if (empty($arr)) return ['type' => 'html', 'data' => 'no data'];

        $sign = $this->makeSign($arr, $this->channel['appkey']);

        if ($sign === $arr["sign"]) {
            if ($arr['state'] == '2') {
                $out_trade_no = $arr['mchOrderNo'];
                $api_trade_no = $arr['payOrderId'];
                $money = $arr['amount'];
                $bill_trade_no = $arr['channelOrderNo'];

                if ($out_trade_no == $tradeNo && $money == strval($ctx->order['realmoney'] * 100)) {
                    $this->processNotify($ctx->order, $api_trade_no, null, $bill_trade_no);
                }
                return ['type' => 'html', 'data' => 'success'];
            } else {
                return ['type' => 'html', 'data' => 'state=' . $arr['state']];
            }
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $params = request()->get();
        $sign = $this->makeSign($params, $this->channel['appkey']);

        if ($sign === $params["sign"]) {
            if ($params['state'] == '2') {
                $out_trade_no = $params['mchOrderNo'];
                $api_trade_no = $params['payOrderId'];
                $money = $params['amount'];

                if ($out_trade_no == $tradeNo && $money == strval($ctx->order['realmoney'] * 100)) {
                    return ($this->markTrustedCallback($ctx, 'return', 'jeepay-signature'))(function () use ($ctx, $api_trade_no) {
                        return $this->processReturn($ctx->order, $api_trade_no);
                    });
                } else {
                    return ['type' => 'error', 'msg' => '订单信息校验失败'];
                }
            } else {
                return ['type' => 'page', 'page' => 'return'];
            }
        } else {
            return ['type' => 'error', 'msg' => '签名验证失败'];
        }
    }

    public function query(array $order): array
    {
        $apiurl = $this->channel['appurl'] . 'api/pay/query';
        $param = [
            'mchNo' => $this->channel['appmchid'],
            'appId' => $this->channel['appid'],
            'mchOrderNo' => $order['trade_no'],
            'reqTime' => getMillisecond(),
            'version' => '1.0',
            'signType' => 'MD5',
        ];
        $param['sign'] = $this->makeSign($param, $this->channel['appkey']);
        $data = get_curl($apiurl, json_encode($param), 0, 0, 0, 0, 0, ['Content-Type: application/json']);
        if (!$data) throw new \Exception('接口请求失败');
        $result = json_decode($data, true);
        if (isset($result['code']) && $result['code'] == 0) {
            return [
                'api_trade_no' => $result['data']['payOrderId'],
                'status' => $result['data']['state'] == 2 ? 1 : 0,
                'money' => $result['data']['amount'] / 100,
                'bill_trade_no' => $result['data']['channelOrderNo'] ?? '',
                'endtime' => $result['data']['successTime'] ?? '',
            ];
        } else {
            throw new \Exception($result['msg'] ?? '返回数据解析失败');
        }
    }

    //退款
    public function refund(array $order): array
    {
        $apiurl = $this->channel['appurl'] . 'api/refund/refundOrder';
        $param = [
            'mchNo' => $this->channel['appmchid'],
            'appId' => $this->channel['appid'],
            'payOrderId' => $order['api_trade_no'],
            'mchRefundNo' => $order['refund_no'],
            'refundAmount' => round($order['refundmoney'] * 100),
            'currency' => 'cny',
            'refundReason' => '申请退款',
            'reqTime' => getMillisecond(),
            'version' => '1.0',
            'signType' => 'MD5',
        ];

        $param['sign'] = $this->makeSign($param, $this->channel['appkey']);

        $data = get_curl($apiurl, json_encode($param), 0, 0, 0, 0, 0, ['Content-Type: application/json']);
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($data, true);

        if (isset($result['code']) && $result['code'] == 0) {
            if (isset($result['data']['errMsg'])) {
                return ['code' => -1, 'msg' => '[' . $result['data']['errCode'] . ']' . $result['data']['errMsg']];
            } elseif (isset($result['data']['error'])) {
                return ['code' => -1, 'msg' => $result['data']['error']];
            }
            return ['code' => 0, 'trade_no' => $result['data']['refundOrderId'], 'refund_fee' => $order['refundmoney']];
        } else {
            return ['code' => -1, 'msg' => $result['msg'] ?? '返回数据解析失败'];
        }
    }

    //转账
    public function transfer(array $bizParam): array
    {
        $type = $bizParam['type'];
        if ($type == 'alipay') {
            $entryType = 'ALIPAY_CASH';
        } elseif ($type == 'wxpay') {
            $entryType = 'WX_CASH';
        } elseif ($type == 'bank') {
            $entryType = 'BANK_CARD';
        }

        $apiurl = $this->channel['appurl'] . 'api/transferOrder';
        $param = [
            'mchNo' => $this->channel['appmchid'],
            'appId' => $this->channel['appid'],
            'mchOrderNo' => $bizParam['out_biz_no'],
            'ifCode' => $type,
            'entryType' => $entryType,
            'amount' => round($bizParam['money'] * 100),
            'currency' => 'cny',
            'accountNo' => $bizParam['payee_account'],
            'accountName' => $bizParam['payee_real_name'],
            'clientIp' => request()->clientip,
            'transferDesc' => $bizParam['transfer_desc'],
            'notifyUrl' => config_get('localurl') . 'pay/transfernotify/' . $this->channel['id'] . '/',
            'reqTime' => getMillisecond(),
            'version' => '1.0',
            'signType' => 'MD5',
        ];

        $param['sign'] = $this->makeSign($param, $this->channel['appkey']);

        $data = get_curl($apiurl, json_encode($param), 0, 0, 0, 0, 0, ['Content-Type: application/json']);
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($data, true);

        if (isset($result['code']) && $result['code'] == 0) {
            if (isset($result['data']['errMsg'])) {
                return ['code' => -1, 'errcode' => $result['data']['errCode'], 'msg' => '[' . $result['data']['errCode'] . ']' . $result['data']['errMsg']];
            } elseif (isset($result['data']['error'])) {
                return ['code' => -1, 'msg' => $result['data']['error']];
            }
            if ($result['data']['state'] == 2) {
                $status = 1;
            } else {
                $status = 0;
            }
            return ['code' => 0, 'status' => $status, 'orderid' => $result['data']['transferId'], 'paydate' => date('Y-m-d H:i:s')];
        } else {
            return ['code' => -1, 'msg' => $result['msg'] ?? '返回数据解析失败'];
        }
    }

    //转账查询
    public function transfer_query(array $bizParam): array
    {
        $apiurl = $this->channel['appurl'] . 'api/transfer/query';
        $param = [
            'mchNo' => $this->channel['appmchid'],
            'appId' => $this->channel['appid'],
            'transferId' => $bizParam['orderid'],
            'reqTime' => getMillisecond(),
            'version' => '1.0',
            'signType' => 'MD5',
        ];

        $param['sign'] = $this->makeSign($param, $this->channel['appkey']);

        $data = get_curl($apiurl, json_encode($param), 0, 0, 0, 0, 0, ['Content-Type: application/json']);
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($data, true);

        if (isset($result['code']) && $result['code'] == 0) {
            if ($result['data']['state'] == 2) {
                $status = 1;
            } elseif ($result['data']['state'] == 1) {
                $status = 0;
            } else {
                $status = 2;
            }
            $paydate = date('Y-m-d H:i:s', intval($result['data']['successTime'] / 1000));
            $errmsg = null;
            if (isset($result['data']['errCode']) && isset($result['data']['errMsg'])) {
                $errmsg = '[' . $result['data']['errCode'] . ']' . $result['data']['errMsg'];
            }
            return ['code' => 0, 'status' => $status, 'amount' => $result['data']['amount'], 'paydate' => $paydate, 'errmsg' => $errmsg];
        } else {
            return ['code' => -1, 'msg' => $result['msg'] ?? '返回数据解析失败'];
        }
    }

    //转账异步回调
    public function transfernotify(PaymentContext $ctx): array
    {
        if (request()->isPost()) {
            $arr = request()->post();
        } else {
            $arr = request()->get();
        }
        if (empty($arr)) return ['type' => 'html', 'data' => 'no data'];

        $sign = $this->makeSign($arr, $this->channel['appkey']);

        if ($sign === $arr["sign"]) {
            if ($arr['state'] == 2) {
                $status = 1;
            } elseif ($arr['state'] == 1) {
                $status = 0;
            } else {
                $status = 2;
            }
            $errmsg = null;
            if (isset($arr['errCode']) && isset($arr['errMsg'])) {
                $errmsg = '[' . $arr['errCode'] . ']' . $arr['errMsg'];
            }
            ($this->markTrustedCallback($ctx, 'transfernotify', 'jeepay-signature'))(function () use ($arr, $status, $errmsg) {
                $this->processTransfer($arr['mchOrderNo'], $status, $errmsg);
            });
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }
}
