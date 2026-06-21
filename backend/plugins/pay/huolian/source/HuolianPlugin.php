<?php

declare(strict_types=1);

namespace plugins\payment\huolian;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class HuolianPlugin extends BasePayment
{
    private function createClient(): HuolianClient
    {
        return new HuolianClient($this->channel['appid'], $this->channel['appkey']);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->isMobile && $ctx->mdevice !== 'wechat' && ($this->channel['appwxa'] > 0 || in_array('2', $this->channel['apptype']))) {
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

        if ($ctx->method == 'wxplugin') {
            return $this->wxplugin($ctx);
        } elseif ($ctx->method == 'app') {
            return $this->wxapppay($ctx);
        } elseif ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->isMobile && $ctx->mdevice !== 'wechat' && ($this->channel['appwxa'] > 0 || in_array('2', $this->channel['apptype']))) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //聚合支付
    private function addOrder(PaymentContext $ctx, string $pay_type): string
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        $params = [
            'businessOrderNo' => $tradeNo,
            'payAmount' => $ctx->order['realmoney'],
            'merchantNo' => $this->channel['appmchid'],
            'operatorAccount' => $this->channel['appurl'],
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'subject' => $ctx->ordername,
            'payWay' => $pay_type,
            'clientIp' => $clientip,
            //'callBackUrl' => $siteurl.'pay/return/'.$tradeNo.'/',
        ];
        if ($ctx->mdevice === 'wechat') $params['callBackUrl'] = $siteurl . 'pay/return/' . $tradeNo . '/';

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('api.hl.order.pay.unified', $params);
            $this->updateOrder($tradeNo, $result['orderNo']);
            return $result['payUrl'];
        });
    }

    //原生支付预下单
    private function nativepay(PaymentContext $ctx, string $pay_type): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        $params = [
            'businessOrderNo' => $tradeNo,
            'payAmount' => $ctx->order['realmoney'],
            'merchantNo' => $this->channel['appmchid'],
            'operatorAccount' => $this->channel['appurl'],
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'subject' => $ctx->ordername,
            'payWay' => $pay_type,
            'clientIp' => $clientip,
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('api.hl.order.pay.native', $params);
            $this->updateOrder($tradeNo, $result['orderNo']);
            return $result['qrCodeUrl'];
        });
    }

    //微信小程序支付
    private function wechat_applet(PaymentContext $ctx, string $appid, ?string $openid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        $params = [
            'businessOrderNo' => $tradeNo,
            'payAmount' => $ctx->order['realmoney'],
            'merchantNo' => $this->channel['appmchid'],
            'operatorAccount' => $this->channel['appurl'],
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'subject' => $ctx->ordername,
            'payWay' => 'wechat',
            'appId' => $appid,
            'openId' => $openid,
            'clientIp' => $clientip,
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('api.hl.order.pay.applet', $params);
            $this->updateOrder($tradeNo, $result['orderNo']);
            return $result;
        });
    }

    //微信小程序托管支付
    private function wechat_applet_host(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        $params = [
            'businessOrderNo' => $tradeNo,
            'payAmount' => $ctx->order['realmoney'],
            'merchantNo' => $this->channel['appmchid'],
            'operatorAccount' => $this->channel['appurl'],
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'subject' => $ctx->ordername,
            'payWay' => 'wechat',
            'clientIp' => $clientip,
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('api.hl.order.pre.pay.applet', $params);
            $this->updateOrder($tradeNo, $result['orderNo']);
            return $result;
        });
    }

    //H5预下单
    private function h5pay(PaymentContext $ctx, string $pay_type): string
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        $params = [
            'businessOrderNo' => $tradeNo,
            'payAmount' => $ctx->order['realmoney'],
            'merchantNo' => $this->channel['appmchid'],
            'operatorAccount' => $this->channel['appurl'],
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'subject' => $ctx->ordername,
            'payWay' => $pay_type,
            'pageNotifyUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'clientIp' => $clientip,
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('api.hl.order.pay.h5', $params);
            $this->updateOrder($tradeNo, $result['orderNo']);
            return $result['payUrl'];
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'alipay');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'wechat');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $code = request()->get('code', '', 'trim');
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
            $result = $this->wechat_applet($ctx, $wxinfo['appid'], $openid);
            $jsApiParameters = $result['jsPayInfo'];
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($jsApiParameters, true)]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        if (in_array('2', $this->channel['apptype'])) {
            try {
                $code_url = $this->h5pay($ctx, 'wechat');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'jump', 'url' => $code_url];
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

    //微信小程序插件支付
    public function wxplugin(PaymentContext $ctx): array
    {
        $appId = 'wxf51d01cf670e28d3';
        try {
            $result = $this->wechat_applet($ctx, $appId);
            $payinfo = ['appId' => $appId, 'merchantNo' => $result['merchantNo'], 'orderNo' => $result['orderNo']];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxplugin', 'data' => $payinfo];
    }

    //微信小程序托管支付
    public function wxapppay(PaymentContext $ctx): array
    {
        try {
            $result = $this->wechat_applet_host($ctx);
            $payinfo = ['appId' => $result['appId'], 'miniProgramId' => $result['miniProgramId'], 'path' => $result['payPath']];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => $payinfo];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'cloud');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'unionpay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!request()->post('respBody')) return ['type' => 'html', 'data' => 'FAIL'];

        $client = $this->createClient();
        $verify_result = $client->verify(request()->post());

        if ($verify_result) {
            $data = json_decode(request()->post('respBody', ''), true);
            if ($data['orderStatus'] == 2) {
                $out_trade_no = $data['businessOrderNo'];
                $api_trade_no = $data['orderNo'];
                $money = $data['payAmount'];
                $buyer = $data['userId'] ?? '';
                $bill_trade_no = $data['topChannelOrderNo'] ?? '';
                $bill_mch_trade_no = $data['channelOrderNo'] ?? '';
                $end_time = $data['payTime'];

                if ($out_trade_no == $tradeNo) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
                return ['type' => 'html', 'data' => 'SUCCESS'];
            } else {
                return ['type' => 'html', 'data' => 'status=' . $data['orderStatus']];
            }
        } else {
            return ['type' => 'html', 'data' => 'FAIL'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $client = $this->createClient();
        $params = [
            'businessOrderNo' => $order['trade_no'],
            'merchantNo' => $this->channel['appmchid'],
        ];
        $result = $client->execute('api.hl.order.pay.details', $params);
        return [
            'api_trade_no' => $result['orderNo'],
            'status' => $result['orderStatus'] == 2 ? 1 : 0,
            'money' => $result['payAmount'],
            'buyer' => $result['userId'] ?? '',
            'bill_trade_no' => $result['topChannelOrderNo'] ?? '',
            'bill_mch_trade_no' => $result['channelOrderNo'] ?? '',
            'endtime' => $result['payTime'] ?? '',
        ];
    }

    //退款
    public function refund($order): array
    {
        $client = $this->createClient();

        $params = [
            'orderNo' => $order['api_trade_no'],
            'businessRefundNo' => $order['refund_no'],
            'refundAmount' => $order['refundmoney'],
            'refundPassword' => $this->channel['appsecret'],
            'merchantNo' => $this->channel['appmchid'],
            'operatorAccount' => $this->channel['appurl'],
        ];

        try {
            $retData = $client->execute('api.hl.order.refund.operation', $params);
            $result = ['code' => 0, 'trade_no' => $retData['refundNo'], 'refund_fee' => $retData['refundAmount']];
        } catch (Exception $e) {
            $result = ['code' => -1, 'msg' => $e->getMessage()];
        }
        return $result;
    }

    //投诉异步回调
    public function complainnotify(PaymentContext $ctx): array
    {
        if (!request()->post('huolianComplaintNo')) return ['type' => 'html', 'data' => 'FAIL'];

        if (substr($this->channel['appmchid'], 0, 1) == '[') {
            $this->channel['appmchid'] = request()->post('merchantNo');
        }

        $model = \app\logic\ComplainLogic::getModel($this->channel);
        $model->refreshNewInfo(request()->post('huolianComplaintNo'), request()->post('operateType'));

        return ['type' => 'html', 'data' => 'SUCCESS'];
    }
}
