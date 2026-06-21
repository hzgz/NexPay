<?php

namespace plugins\payment\sumapay2;

use app\common\PaymentContext;
use Exception;

class Sumapay2Plugin extends \app\common\BasePayment
{
    private function getClient(): PayClient
    {
        return new PayClient($this->channel['appkey'], $this->payRoot);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->isMobile && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        if ($ctx->method == 'applet') {
            return $this->wxapppay($ctx);
        }
        $typename = $ctx->order['typename'];
        return $this->$typename($ctx);
    }

    //聚合码下单
    private function qrcode(PaymentContext $ctx): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $apiurl = 'https://www.sumapay.com/wechatTransitGateway/merchant.do';

        $param = [
            'requestType' => 'IOZ1015',
            'requestId' => $tradeNo,
            'requestStartTime' => date("YmdHis"),
            'merchantCode' => $this->channel['appid'],
            'totalBizType' => $this->channel['biztype'],
            'totalPrice' => $ctx->order['realmoney'],
            'goodsDesc' => $ctx->ordername,
            'rePayTimeOut' => '0',
            'noticeUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'terminalIp' => request()->clientip,
            'userType' => '2',
            'userIdIdentity' => $this->channel['userid'],
        ];
        $signKeys = ['requestType', 'requestId', 'merchantCode', 'totalBizType', 'totalPrice', 'goodsDesc', 'noticeUrl', 'passThrough', 'userType', 'userIdIdentity'];
        $signatrue = $client->getSign($param, $signKeys);

        $param += [
            'signature' => $signatrue,
            'productId' => $ctx->order['uid'] ? $ctx->order['uid'] : '1000',
            'productName' => $ctx->order['name'],
            'fund' => $ctx->order['realmoney'],
            'merAcct' => $this->channel['appid'],
            'bizType' => $this->channel['biztype'],
            'productNumber' => '1',
        ];

        return self::lockPayData($tradeNo, function () use ($client, $apiurl, $param) {
            $result = $client->execute($apiurl, $param);
            return $result['codeUrl'];
        });
    }

    //微信H5APP小程序支付
    private function h5apppay(PaymentContext $ctx, string $envflag = '0'): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $apiurl = 'https://www.sumapay.com/wechatTransitGateway/merchant.do';

        $param = [
            'requestType' => 'IOZ1014',
            'requestId' => $tradeNo,
            'requestStartTime' => date("YmdHis"),
            'merchantCode' => $this->channel['appid'],
            'totalBizType' => $this->channel['biztype'],
            'totalPrice' => $ctx->order['realmoney'],
            'goodsDesc' => $ctx->ordername,
            'envflag' => $envflag,
            'rePayTimeOut' => '0',
            'noticeUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'terminalIp' => request()->clientip,
            'userType' => '2',
            'userIdIdentity' => $this->channel['userid'],
        ];
        if (request()->get('d') == 1) {
            $param['backUrl'] = request()->siteurl . 'pay/return/' . $tradeNo . '/';
        }
        $signKeys = ['requestType', 'requestId', 'merchantCode', 'totalBizType', 'totalPrice', 'goodsDesc', 'envFlag', 'noticeUrl', 'backUrl', 'passThrough', 'userType', 'userIdIdentity'];
        $signatrue = $client->getSign($param, $signKeys);

        $param += [
            'signature' => $signatrue,
            'productId' => $ctx->order['uid'] ? $ctx->order['uid'] : '1000',
            'productName' => $ctx->order['name'],
            'fund' => $ctx->order['realmoney'],
            'merAcct' => $this->channel['appid'],
            'bizType' => $this->channel['biztype'],
            'productNumber' => '1',
        ];

        return self::lockPayData($tradeNo, function () use ($client, $apiurl, $param) {
            $result = $client->execute($apiurl, $param);
            return $result;
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx);
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
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = request()->siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } else {
            try {
                $code_url = $this->qrcode($ctx);
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
        try {
            $result = $this->h5apppay($ctx, '3');
            $code_url = $result['payUrl'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if (strpos($code_url, '&scheme=weixin')) {
            $query = parse_url($code_url, PHP_URL_QUERY);
            parse_str($query, $params);
            $scheme_url = $params['scheme'];
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $scheme_url];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    //微信APP支付
    public function wxapppay(PaymentContext $ctx): array
    {
        try {
            $result = $this->h5apppay($ctx, '0');
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => $result['mpAppId'], 'miniProgramId' => '', 'path' => $result['mpPath']]];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->getClient();

        $signKeys = ['requestId', 'payId', 'fiscalDate', 'description', 'totalPrice', 'tradeAmount', 'tradeFee'];

        if ($client->verify(request()->post(), $signKeys)) {
            if (request()->post('status') == '2') {
                $out_trade_no = request()->post('requestId');
                $trade_no = request()->post('payId');
                $money = request()->post('totalPrice');
                $buyer = request()->post('openId') ?: request()->post('alipayUserId');
                $bill_trade_no = request()->post('channelSn');

                if ($out_trade_no == $tradeNo) {
                    $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no);
                }
                return ['type' => 'html', 'data' => 'success'];
            } else {
                return ['type' => 'html', 'data' => 'status=' . request()->post('status')];
            }
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->getClient();

        $apiurl = 'https://www.sumapay.com/main/Refund_do';

        $param = [
            'requestId' => $order['refund_no'],
            'originalRequestId' => $order['trade_no'],
            'tradeProcess' => $this->channel['appid'],
            'fund' => $order['refundmoney'],
            'noticeUrl' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
            'reason' => '交易退款',
            'refundMothed' => '1',
        ];

        $signKeys = ['requestId', 'originalRequestId', 'tradeProcess', 'fund', 'noticeUrl'];
        $signatrue = $client->getSign($param, $signKeys);
        $param['mersignature'] = $signatrue;

        try {
            $result = $client->execute($apiurl, $param);
            return ['code' => 0, 'trade_no' => $order['trade_no'], 'refund_fee' => $order['refundmoney']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //退款回调
    public function refundnotify(PaymentContext $ctx): array
    {
        $client = $this->getClient();
        $signKeys = ['requestId', 'originalRequestId', 'refundResult', 'refundTime'];

        if ($client->verify(request()->post(), $signKeys)) {
            $status = request()->post('refundResult') == '0' ? 1 : 2;
            ($this->markTrustedCallback($ctx, 'refundnotify', 'sumapay2-signature'))(function () use ($status) {
                $this->processRefund(
                    request()->post('requestId'),
                    $status,
                    $status === 2 ? 'sumapay refund failed' : '',
                    request()->post('originalRequestId'),
                    null,
                    request()->post('refundTime')
                );
            });
            if (request()->post('refundResult') == '0') {
                return ['type' => 'html', 'data' => 'success'];
            } else {
                return ['type' => 'html', 'data' => 'success'];
            }
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //进件回调
    public function applynotify(PaymentContext $ctx): array
    {
        $client = $this->getClient();
        $signKeys = ['requestId', 'result', 'merchantCode', 'userIdentity', 'remark'];

        if ($client->verify2(request()->post(), $signKeys)) {
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->notify(request()->post());
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //审核回调
    public function auditnotify(PaymentContext $ctx): array
    {
        $client = $this->getClient();
        $signKeys = ['requestId', 'result', 'merchantCode', 'userIdentity', 'remark'];

        if ($client->verify2(request()->post(), $signKeys)) {
            //$model = \app\logic\ApplymentLogic::getModel2($this->channel);
            //if($model) $model->notify(request()->post());
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }
}
