<?php

declare(strict_types=1);

namespace plugins\payment\shouyinbei;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

/**
 * @see https://www.yuque.com/shouyinbei/123
 */
class ShouyinbeiPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
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
        if ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return $this->wxjspay($ctx);
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

    private function getClient(): PayService
    {
        return new PayService($this->channel['appid'], $this->channel['appmchid'], $this->channel['appkey'], $this->channel['appsecret']);
    }

    //统一支付接口
    private function addOrder(PaymentContext $ctx, string $payType, string $payWay, ?string $sub_appid = null, ?string $openid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'businessCode' => '08010001',
            'payType' => $payType,
            'mercOrderNo' => $tradeNo,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'orderAmt' => $ctx->order['realmoney'],
            'payWay' => $payWay,
            'payerIp' => request()->clientip,
            'subject' => $ctx->ordername,
        ];
        if ($sub_appid && $openid) {
            $params['subAppid'] = $sub_appid;
            $params['payUserId'] = $openid;
        }
        if (!empty($this->channel['appurl'])) {
            $divList = [
                ['mercCode' => $this->channel['appurl'], 'divRatio' => '1', 'isChargeFee' => '01']
            ];
            $params += [
                'divMode' => '01',
                'isLedger' => '02',
                'ledgerNotifyUrl' => config_get('localurl') . 'pay/divnotify/' . $tradeNo . '/',
                'divList' => json_encode($divList)
            ];
        }

        $client = $this->getClient();
        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->submit('/pay/order/ployScan', $params);
            if ($result['code'] == '0000') {
                return $result['data'];
            } elseif (isset($result['msg'])) {
                throw new Exception($result['msg']);
            } else {
                throw new Exception('返回数据解密失败');
            }
        });
    }

    //扫码下单接口
    private function qrcode(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'businessCode' => '08010001',
            'mercOrderNo' => $tradeNo,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'orderAmt' => $ctx->order['realmoney'],
            'payerIp' => request()->clientip,
            'subject' => $ctx->ordername,
        ];
        if (!empty($this->channel['appurl'])) {
            $divList = [
                ['mercCode' => $this->channel['appurl'], 'divRatio' => '1', 'isChargeFee' => '01']
            ];
            $params += [
                'divMode' => '01',
                'isLedger' => '02',
                'ledgerNotifyUrl' => config_get('localurl') . 'pay/divnotify/' . $tradeNo . '/',
                'divList' => json_encode($divList)
            ];
        }

        $client = $this->getClient();
        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->submit('/pay/order/ployNotive', $params);
            if ($result['code'] == '0000') {
                return $result['data'];
            } elseif (isset($result['msg'])) {
                throw new Exception($result['msg']);
            } else {
                throw new Exception('返回数据解密失败');
            }
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'page', 'page' => 'wxopen'];
        }
        try {
            $result = $this->qrcode($ctx);
            $code_url = $result['payInfo']['qrCodeUrl'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $result = $this->qrcode($ctx);
            $code_url = $result['payInfo']['qrCodeUrl'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->isMobile) {
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
            $result = $this->addOrder($ctx, 'WECHAT', '03', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        $payinfo = ['appId' => $result['payAppId'], 'timeStamp' => $result['payTimeStamp'], 'nonceStr' => $result['paynonceStr'], 'package' => $result['payPackage'], 'signType' => $result['paySignType'], 'paySign' => $result['paySign']];
        $payinfo = json_encode($payinfo);

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
            $result = $this->addOrder($ctx, 'WECHAT', '02', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }
        $payinfo = ['appId' => $result['payAppId'], 'timeStamp' => $result['payTimeStamp'], 'nonceStr' => $result['paynonceStr'], 'package' => $result['payPackage'], 'signType' => $result['paySignType'], 'paySign' => $result['paySign']];

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $payinfo]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $result = $this->qrcode($ctx);
            $code_url = $result['payInfo']['qrCodeUrl'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->getClient();
        $verify_result = $client->verifySign($data);

        if ($verify_result) {//验证成功

            if ($data['code'] == '0000' && $data['type'] == 'syb.trade.notify') {
                $bizContent = json_decode($data['bizContent'] ?? '', true);
                $out_trade_no = $bizContent['mercOrderNo'];
                $api_trade_no = $bizContent['orderNumber'];
                $money = $bizContent['orderAmt'];
                $buyer = $bizContent['payUserId'];
                $bill_trade_no = $bizContent['thirdTradeNo'];
                $end_time = $bizContent['payTime'];
                if ($out_trade_no == $ctx->order['trade_no'] && round((float)$ctx->order['realmoney'], 2) == round((float)$money, 2)) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //分账异步回调
    public function divnotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->getClient();
        $verify_result = $client->verifySign($data);

        if ($verify_result) {//验证成功

            if ($data['code'] == '0000' && $data['type'] == 'syb.trade.notify') {
                $bizContent = json_decode($data['bizContent'] ?? '', true);
                $out_trade_no = $bizContent['mercOrderNo'];
                $api_trade_no = $bizContent['orderNumber'];
                $money = $bizContent['orderAmt'];
                $divStatus = $bizContent['divStatus'];
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
        $params = [
            'mercOrderNo' => $order['trade_no'],
        ];
        $client = $this->getClient();
        $result = $client->submit('/pay/query/trade', $params);
        if ($result['code'] == '0000') {
            return [
                'api_trade_no' => $result['orderNumber'],
                'status' => $result['status'] == 'TRADE_SUCCESS' ? 1 : 0,
                'money' => $result['orderAmt'],
                'buyer' => $result['payUserId'] ?? '',
                'bill_trade_no' => $result['thirdTradeNo'] ?? '',
                'endtime' => $result['payTime'] ?? '',
            ];
        } else {
            throw new \Exception($result['msg'] ? $result['msg'] : '返回数据解密失败');
        }
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'mercOrderNo' => $order['trade_no'],
            'refundAmt' => $order['refundmoney'],
            'refundReason' => '订单退款',
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $order['trade_no'] . '/',
            'mercRefundNo' => $order['refund_no'],
        ];

        try {
            $client = $this->getClient();
            $result = $client->submit('/pay/order/refund', $params);
            if ($result['code'] == '0000') {
                return ['code' => 0, 'trade_no' => $result['data']['refundNo'], 'refund_fee' => $result['data']['refundAmt']];
            } else {
                return ['code' => -1, 'msg' => $result['msg'] ? $result['msg'] : '返回数据解密失败'];
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }
}
