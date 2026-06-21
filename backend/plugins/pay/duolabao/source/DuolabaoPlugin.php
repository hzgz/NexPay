<?php

declare(strict_types=1);

namespace plugins\payment\duolabao;

use app\common\PaymentContext;
use app\common\BasePayment;

class DuolabaoPlugin extends BasePayment
{
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
            } elseif ($ctx->isMobile && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } else {
            return ['type' => 'jump', 'url' => '/pay/' . $ctx->order['typename'] . '/' . $tradeNo . '/'];
        }
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
            } elseif ($ctx->isMobile && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } else {
            $typename = $ctx->order['typename'];
            return $this->$typename($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //通用创建订单
    private function qrcode(PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $client = new DuolabaoClient($this->channel['accessKey'], $this->channel['secretKey']);

        $param = [
            'version' => 'V4.0',
            'agentNum' => $this->channel['agentNum'],
            'customerNum' => $this->channel['customerNum'],
            'shopNum' => $this->channel['shopNum'],
            'requestNum' => $tradeNo,
            'orderAmount' => $ctx->order['realmoney'],
            'subOrderType' => 'NORMAL',
            'orderType' => 'SALES',
            'timeExpire' => date('Y-m-d H:i:s', time() + 7200),
            'businessType' => 'QRCODE_TRAD',
            'payModel' => 'ONCE',
            'source' => 'API',
            'callbackUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'completeUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'clientIp' => request()->clientip,
        ];
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($param, $ctx);
        }

        $result = $client->submitNew('/api/generateQRCodeUrl', $param);
        return $result['url'];
    }

    //通用创建订单
    private function jspay(PaymentContext $ctx, $bankType, $authCode, $appId = null)
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = new DuolabaoClient($this->channel['accessKey'], $this->channel['secretKey']);

        $param = [
            'version' => 'V4.0',
            'agentNum' => $this->channel['agentNum'],
            'customerNum' => $this->channel['customerNum'],
            'shopNum' => $this->channel['shopNum'],
            'bankType' => $bankType,
            'paySource' => $bankType,
            'authCode' => $authCode,
            'requestNum' => $tradeNo,
            'orderAmount' => $ctx->order['realmoney'],
            'subOrderType' => 'NORMAL',
            'orderType' => 'SALES',
            'payType' => 'ACTIVE',
            'businessType' => 'QRCODE_TRAD',
            'payModel' => 'ONCE',
            'source' => 'API',
            'timeExpire' => date('Y-m-d H:i:s', time() + 7200),
            'callbackUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'clientIp' => request()->clientip,
        ];
        if ($appId) {
            $param['appId'] = $appId;
            $param['subAppId'] = $appId;
        }
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($param, $ctx);
        }

        $result = $client->submitNew('/api/createPayWithCheck', $param);
        return $result['bankRequest'];
    }

    private function handleProfits(&$params, PaymentContext $ctx)
    {
        $psreceiver = \app\logic\ProfitSharingLogic::getReceiver($ctx->order['profits']);
        if ($psreceiver) {
            $list = [];
            $allmoney = 0;
            foreach ($psreceiver['info'] as $receiver) {
                $psmoney = round(floor($ctx->order['realmoney'] * $receiver['rate']) / 100, 2);
                $list[] = [
                    'customerNum' => $receiver['account'],
                    'amount' => sprintf('%.2f', $psmoney),
                ];
                $allmoney += $psmoney;
            }
            $psmoney2 = round($ctx->order['realmoney'] - $allmoney, 2);
            if ($psmoney2 > 0) {
                $list[] = [
                    'customerNum' => $params['customerNum'],
                    'amount' => sprintf('%.2f', $psmoney2),
                ];
            }
            $params['LedgerRequest'] = [
                'ledgerType' => 'FIXED',
                'ledgerFeeAssume' => 'FIXED',
                'list' => $list,
            ];
        }
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
                $code_url = $this->qrcode($ctx);
            } catch (\Exception $ex) {
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
            $result = $this->jspay($ctx, 'ALIPAY', $user_id);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['TRADENO']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['TRADENO'], 'redirect_url' => $redirect_url]];
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
                $code_url = $this->qrcode($ctx);
            } catch (\Exception $ex) {
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
            $result = $this->jspay($ctx, $ctx->order['is_applet'] == 1 ? 'WX_XCX' : 'WX', $openid, $wxinfo['appid']);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
        }
        $payinfo = ['appId' => $result['APPID'], 'timeStamp' => $result['TIMESTAMP'], 'nonceStr' => $result['NONCESTR'], 'package' => $result['PACKAGE'], 'signType' => $result['SIBGTYPE'], 'paySign' => $result['PAYSIGN']];
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => json_encode($payinfo)];
        }

        if (request()->get('d') == '1') {
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
        } catch (\Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        //②、统一下单
        try {
            $result = $this->jspay($ctx, 'WX_XCX', $openid, $wxinfo['appid']);
        } catch (\Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        $payinfo = ['appId' => $result['APPID'], 'timeStamp' => $result['TIMESTAMP'], 'nonceStr' => $result['NONCESTR'], 'package' => $result['PACKAGE'], 'signType' => $result['SIGNTYPE'], 'paySign' => $result['PAYSIGN']];

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $payinfo]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
        try {
            $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
        } catch (\Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
    }

    //QQ扫码支付
    public function qqpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx);
        } catch (\Exception $ex) {
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
            $code_url = $this->qrcode($ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->isMobile) {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
        }
    }

    //京东支付
    public function jdpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '京东支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->isMobile) {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'jdpay_qrcode', 'url' => $code_url];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => 'no data'];

        $client = new DuolabaoClient($this->channel['accessKey'], $this->channel['secretKey']);

        if ($client->verifyNotify($json)) {
            $trade_no = $arr['requestNum'];
            $api_trade_no = $arr['orderNum'];
            $orderAmount = (float) $arr['orderAmount'];
            $bill_trade_no = $arr['bankOutTradeNum'];
            $bill_mch_trade_no = $arr['bankRequestNum'];
            $buyer = $arr['subOpenId'];
            $end_time = $arr['completeTime'];
            if ($arr['status'] == 'SUCCESS') {
                if ($trade_no == $ctx->order['trade_no'] && round((float) $ctx->order['realmoney'], 2) == round($orderAmount, 2)) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
                return ['type' => 'html', 'data' => 'success'];
            } else {
                return ['type' => 'html', 'data' => 'error'];
            }
        } else {
            return ['type' => 'html', 'data' => 'error'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type'=>'page','page'=>'return'];
    }

    public function query(array $order): array
    {
        $client = new DuolabaoClient($this->channel['accessKey'], $this->channel['secretKey']);
        $result = $client->submitNew('/api/queryOrderPayDetail', [
            'customerNum' => $this->channel['customerNum'],
            'requestNum' => $order['trade_no'],
        ]);
        $data = $result['queryOrderInfoRes']['orderInfoResModel'];
        return [
            'api_trade_no' => $data['num'],
            'status' => $data['status'] == 'SUCCESS' ? 1 : 0,
            'money' => $data['amount'],
            'buyer' => $result['payRecordWrapperList'][0]['payExtend']['subOpenId'] ?? '',
            'bill_trade_no' => $result['payRecordWrapperList'][0]['payExtend']['bankOutTradeNum'] ?? '',
            'endtime' => $data['completeTime'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = new DuolabaoClient($this->channel['accessKey'], $this->channel['secretKey']);

        $param = [
            'requestVersion' => 'V4.0',
            'agentNum' => $this->channel['agentNum'],
            'customerNum' => $this->channel['customerNum'],
            'shopNum' => $this->channel['shopNum'],
            'requestNum' => $order['trade_no'],
            'refundPartAmount' => $order['refundmoney'],
            'refundRequestNum' => $order['refund_no'],
            'extMap' => ['refund_status_type' => '1'],
        ];
        if ($order['profits'] > 0) {
            $psorder = \app\logic\ProfitSharingLogic::getOrder($order['trade_no']);
            if ($psorder && ($psorder['status'] == 1 || $psorder['status'] == 2)) {
                if ($psorder['rdata']) {
                    $leftmoney = (float)$order['refundmoney'];
                    $div_members = [];
                    foreach ($psorder['rdata'] as $receiver) {
                        $money = $receiver['money'] > $leftmoney ? $leftmoney : $receiver['money'];
                        $div_members[] = [
                            'customerNum' => $receiver['account'],
                            'amount' => sprintf('%.2f', $money),
                        ];
                        $leftmoney -= $money;
                        if ($leftmoney <= 0) break;
                    }
                    $param['list'] = $div_members;
                } else {
                    $div_members = [];
                    $amount = $psorder['money'] > $order['refundmoney'] ? $order['refundmoney'] : $psorder['money'];
                    $div_members[] = [
                        'customerNum' => $psorder['account'],
                        'amount' => sprintf('%.2f', $amount),
                    ];
                    if ($order['refundmoney'] > $psorder['money']) {
                        $amount = round($order['refundmoney'] - $psorder['money'], 2);
                        $div_members[] = [
                            'customerNum' => $param['customerNum'],
                            'amount' => sprintf('%.2f', $amount),
                        ];
                    }
                    $param['list'] = $div_members;
                }
            }
        }
        try {
            $result = $client->submitNew('/api/refundByRequestNum', $param);
            return ['code' => 0, 'trade_no' => $result['orderNum'], 'refund_fee' => $result['refundAmount']];
        } catch (\Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //进件回调
    public function applynotify(PaymentContext $ctx): array
    {
        $client = new DuolabaoClient($this->channel['accessKey'], $this->channel['secretKey']);

        if ($client->verifyNotify()) {
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->notify(request()->get());
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'error'];
        }
    }
}
