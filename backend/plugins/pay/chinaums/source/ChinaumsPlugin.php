<?php

declare(strict_types=1);

namespace plugins\payment\chinaums;

use app\common\PaymentContext;
use app\common\BasePayment;

class ChinaumsPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            if (in_array('2', $this->channel['apptype']) && $ctx->isMobile) {
                $code_url = $this->h5pay($ctx, 'alipay');
                return ['type' => 'jump', 'url' => $code_url];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if (in_array('2', $this->channel['apptype']) && $ctx->isMobile) {
                $code_url = $this->h5pay($ctx, 'wxpay');
                return ['type' => 'jump', 'url' => $code_url];
            } elseif (in_array('3', $this->channel['apptype']) && $ctx->isMobile) {
                $code_url = $this->h5pay($ctx, 'wxminipay');
                return ['type' => 'jump', 'url' => $code_url];
            } elseif (in_array('4', $this->channel['apptype']) && $ctx->isMobile && strpos(request()->header('user-agent'), 'iPhone OS') !== false) {
                return ['type' => 'jump', 'url' => '/pay/wxapppay/' . $tradeNo . '/'];
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
            if (in_array('2', $this->channel['apptype']) && $ctx->isMobile) {
                $code_url = $this->h5pay($ctx, 'alipay');
                return ['type' => 'jump', 'url' => $code_url];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if (in_array('2', $this->channel['apptype']) && $ctx->isMobile) {
                $code_url = $this->h5pay($ctx, 'wxpay');
                return ['type' => 'jump', 'url' => $code_url];
            } elseif (in_array('3', $this->channel['apptype']) && $ctx->isMobile) {
                $code_url = $this->h5pay($ctx, 'wxminipay');
                return ['type' => 'jump', 'url' => $code_url];
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //扫码下单
    private function qrcode(PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = new ChinaumsBuild($this->channel['appid'], $this->channel['appkey'], $this->channel['appswitch'] == 1);

        $path = '/v1/netpay/bills/get-qrcode';
        $time = time();
        $param = [
            'msgId' => getSid(),
            'requestTimestamp' => date('Y-m-d H:i:s', $time),
            'mid' => $this->channel['appmchid'],
            'tid' => $this->channel['appurl'],
            'instMid' => 'QRPAYDEFAULT',
            'billNo' => $this->channel['msgsrcid'] . $tradeNo,
            'billDate' => date('Y-m-d', $time),
            'billDesc' => $ctx->ordername,
            'totalAmount' => intval(round($ctx->order['realmoney'] * 100)),
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returnUrl' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'clientIp' => request()->clientip,
        ];
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($param, $ctx);
        }

        return self::lockPayData($tradeNo, function () use ($client, $path, $param, $time) {
            $result = $client->request($path, $param, $time);
            if (isset($result['errCode']) && $result['errCode'] == 'SUCCESS') {
                return $result['billQRCode'];
            } elseif (isset($result['errMsg'])) {
                throw new \Exception($result['errMsg']);
            } elseif (isset($result['errInfo'])) {
                throw new \Exception($result['errInfo']);
            } else {
                throw new \Exception('返回数据解析失败');
            }
        });
    }

    private function h5pay(PaymentContext $ctx, $pay_type)
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = new ChinaumsBuild($this->channel['appid'], $this->channel['appkey'], $this->channel['appswitch'] == 1);

        if ($pay_type == 'alipay') {
            $path = '/v1/netpay/trade/h5-pay';
        } elseif ($pay_type == 'wxpay') {
            $path = '/v1/netpay/wxpay/h5-pay';
        } elseif ($pay_type == 'wxminipay') {
            $path = '/v1/netpay/wxpay/h5-to-minipay';
        }

        $time = time();
        $param = [
            'msgId' => getSid(),
            'requestTimestamp' => date('Y-m-d H:i:s', $time),
            'mid' => $this->channel['appmchid'],
            'tid' => $this->channel['appurl'],
            'instMid' => 'H5DEFAULT',
            'merOrderId' => $this->channel['msgsrcid'] . $tradeNo,
            'orderDesc' => $ctx->ordername,
            'totalAmount' => intval(round($ctx->order['realmoney'] * 100)),
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returnUrl' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'clientIp' => request()->clientip,
        ];
        if ($pay_type == 'wxpay') {
            $param['sceneType'] = 'AND_WAP';
            $param['merAppName'] = config_get('sitename');
            $param['merAppId'] = request()->siteurl;
        }
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($param, $ctx);
        }

        $url = $client->requestGet($path, $param, $time);

        $this->updateOrderCombine($tradeNo);

        return $url;
    }

    //APP支付(跳转小程序)
    private function apppay(PaymentContext $ctx, $pay_type, $sub_app_id = null)
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = new ChinaumsBuild($this->channel['appid'], $this->channel['appkey'], $this->channel['appswitch'] == 1);

        if ($pay_type == 'alipay') {
            $path = '/v1/netpay/trade/app-pre-order';
        } elseif ($pay_type == 'wxpay') {
            $path = '/v1/netpay/wx/app-pre-order';
        } elseif ($pay_type == 'bank') {
            $path = '/v1/netpay/uac/app-order';
        }
        $time = time();
        $param = [
            'msgId' => getSid(),
            'requestTimestamp' => date('Y-m-d H:i:s', $time),
            'mid' => $this->channel['appmchid'],
            'tid' => $this->channel['appurl'],
            'instMid' => 'APPDEFAULT',
            'merOrderId' => $this->channel['msgsrcid'] . $tradeNo,
            'orderDesc' => $ctx->ordername,
            'totalAmount' => intval(round($ctx->order['realmoney'] * 100)),
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'clientIp' => request()->clientip,
        ];
        if ($sub_app_id) {
            $param['subAppId'] = $sub_app_id;
        }
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($param, $ctx);
        }

        return self::lockPayData($tradeNo, function () use ($client, $path, $param, $time) {
            $result = $client->request($path, $param, $time);
            if (isset($result['errCode']) && $result['errCode'] == 'SUCCESS') {
                return $result['appPayRequest'];
            } elseif (isset($result['errMsg'])) {
                throw new \Exception($result['errMsg']);
            } elseif (isset($result['errInfo'])) {
                throw new \Exception($result['errInfo']);
            } else {
                throw new \Exception('返回数据解析失败');
            }
        });
    }

    private function handleProfits(&$param, PaymentContext $ctx)
    {
        $psreceiver = \app\logic\ProfitSharingLogic::getReceiver($ctx->order['profits']);
        if ($psreceiver) {
            $param['divisionFlag'] = true;
            $suborders = [];
            $i = 1;
            $allmoney = 0;
            foreach ($psreceiver['info'] as $receiver) {
                $psmoney = intval(round(floor($ctx->order['realmoney'] * $receiver['rate'])));
                $suborders[] = [
                    'mid' => $receiver['account'],
                    'merOrderId' => $this->channel['msgsrcid'] . $ctx->order['trade_no'] . $i++,
                    'totalAmount' => $psmoney
                ];
                $allmoney += $psmoney;
            }
            $param['platformAmount'] = $param['totalAmount'] - $allmoney;
            $param['subOrders'] = $suborders;
        }
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //支付宝APP支付
    public function alipayapp(PaymentContext $ctx): array
    {
        try {
            $result = $this->apppay($ctx, 'alipay');
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'app', 'data' => $result];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx);
        } catch (\Exception $ex) {
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

    //微信APP支付
    public function wxapppay(PaymentContext $ctx): array
    {
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
        try {
            $result = $this->apppay($ctx, 'wxpay', $wxinfo['appid']);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'app') {
            return ['type' => 'app', 'data' => $result];
        }

        $param = [
            'nonceStr' => $result['noncestr'],
            'package' => $result['package'],
            'partnerId' => $result['partnerid'],
            'prepayId' => $result['prepayid'],
            'timeStamp' => $result['timestamp'],
            'sign' => $result['sign'],
        ];
        $code_url = 'weixin://app/' . $result['appid'] . '/pay/?' . http_build_query($param);
        return ['type' => 'qrcode', 'page' => 'wxpay_h5', 'url' => $code_url];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $client = new ChinaumsBuild($this->channel['appid'], $this->channel['appkey'], $this->channel['appswitch'] == 1);

        $verifyResult = $client->verify(request()->post(), $this->channel['appsecret']);

        if ($verifyResult) {
            if (request()->post('instMid') == 'H5DEFAULT') {
                if (request()->post('status') == 'TRADE_SUCCESS') {
                    $out_trade_no = substr(request()->post('merOrderId'), 4);
                    $trade_no = request()->post('merOrderId');
                    $money = request()->post('totalAmount');
                    $buyer = request()->post('buyerId');
                    $bill_trade_no = request()->post('targetOrderId');
                    $end_time = request()->post('payTime');
                    if ($out_trade_no == $ctx->order['trade_no'] && $money == strval($ctx->order['realmoney'] * 100)) {
                        ($this->markTrustedCallback($ctx, 'notify', 'chinaums-signature'))(function () use ($ctx, $trade_no, $buyer, $bill_trade_no, $end_time) {
                            $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no, null, $end_time);
                        });
                    }
                    return ['type' => 'html', 'data' => 'SUCCESS'];
                } else {
                    return ['type' => 'html', 'data' => 'FAILED'];
                }
            } else {
                if (request()->post('billStatus') == 'PAID') {
                    $out_trade_no = substr(request()->post('billNo'), 4);
                    $billPayment = json_decode(request()->post('billPayment', ''), true);
                    $trade_no = request()->post('billNo');
                    $money = request()->post('totalAmount');
                    $buyer = $billPayment['buyerId'] ?? '';
                    $bill_trade_no = $billPayment['targetOrderId'] ?? '';
                    $end_time = $billPayment['payTime'] ?? '';
                    if ($out_trade_no == $ctx->order['trade_no'] && $money == strval($ctx->order['realmoney'] * 100)) {
                        ($this->markTrustedCallback($ctx, 'notify', 'chinaums-signature'))(function () use ($ctx, $trade_no, $buyer, $bill_trade_no, $end_time) {
                            $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no, null, $end_time);
                        });
                    }
                    return ['type' => 'html', 'data' => 'SUCCESS'];
                } else {
                    return ['type' => 'html', 'data' => 'FAILED'];
                }
            }
        }
        return ['type' => 'html', 'data' => 'FAILED'];
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
    }

    public function query(array $order): array
    {
        $client = new ChinaumsBuild($this->channel['appid'], $this->channel['appkey'], $this->channel['appswitch'] == 1);
        $param = [
            'mid' => $this->channel['appmchid'],
            'tid' => $this->channel['appurl'],
            'billNo' => $order['api_trade_no'],
        ];
        $result = $client->request('/v1/netpay/bills/query', $param, time());
        if (isset($result['errCode']) && $result['errCode'] == 'SUCCESS') {
            $billPayment = json_decode($result['billPayment'] ?? '', true);
            return [
                'api_trade_no' => $result['billNo'],
                'status' => $result['billStatus'] == 'PAID' ? 1 : 0,
                'money' => $result['totalAmount'],
                'buyer' => $billPayment['buyerId'] ?? '',
                'bill_trade_no' => $billPayment['targetOrderId'] ?? '',
                'endtime' => $billPayment['payTime'] ?? '',
            ];
        } else {
            return ['code' => -1, 'msg' => $result['errMsg'] ?? '返回数据解析失败'];
        }
    }

    //退款
    public function refund(array $order): array
    {
        $client = new ChinaumsBuild($this->channel['appid'], $this->channel['appkey'], $this->channel['appswitch'] == 1);

        if ($order['combine'] == 1) { //H5支付退款
            $path = '/v1/netpay/refund';
            $time = time();
            $param = [
                'msgId' => getSid(),
                'requestTimestamp' => date('Y-m-d H:i:s', $time),
                'mid' => $this->channel['appmchid'],
                'tid' => $this->channel['appurl'],
                'instMid' => 'H5DEFAULT',
                'merOrderId' => $order['api_trade_no'],
                'billDate' => date('Y-m-d', strtotime($order['addtime'])),
                'refundOrderId' => $this->channel['msgsrcid'] . $order['refund_no'],
                'refundAmount' => $order['refundmoney'] * 100,
            ];
        } else {
            $path = '/v1/netpay/bills/refund';
            $time = time();
            $param = [
                'msgId' => getSid(),
                'requestTimestamp' => date('Y-m-d H:i:s', $time),
                'mid' => $this->channel['appmchid'],
                'tid' => $this->channel['appurl'],
                'instMid' => 'QRPAYDEFAULT',
                'billNo' => $order['api_trade_no'],
                'billDate' => date('Y-m-d', strtotime($order['addtime'])),
                'refundOrderId' => $this->channel['msgsrcid'] . $order['refund_no'],
                'refundAmount' => $order['refundmoney'] * 100,
            ];
        }

        $result = $client->request($path, $param, $time);
        if (isset($result['errCode']) && $result['errCode'] == 'SUCCESS') {
            return ['code' => 0, 'trade_no' => $result['billNo'], 'refund_fee' => round($result['refundAmount'] / 100, 2)];
        } else {
            return ['code' => -1, 'msg' => $result['errMsg'] ?? '返回数据解析失败'];
        }
    }
}
