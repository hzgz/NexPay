<?php

declare(strict_types=1);

namespace plugins\payment\shengpay;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class ShengpayPlugin extends BasePayment
{
    private function createClient(): ShengPayClient
    {
        return new ShengPayClient($this->channel['appid'], $this->channel['appkey']);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('4', $this->channel['apptype']) && !in_array('3', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('1', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && (in_array('3', $this->channel['apptype']) || in_array('4', $this->channel['apptype']))) {
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

        if ($ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('4', $this->channel['apptype']) && !in_array('3', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('1', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && (in_array('3', $this->channel['apptype']) || in_array('4', $this->channel['apptype']))) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //统一下单
    private function addOrder(PaymentContext $ctx, string $tradeType, ?string $extra = null): mixed
    {
        $tradeNo = $ctx->order['trade_no'];

        $client = $this->createClient();

        $path = $this->channel['appswitch'] == 1 ? '/pay/unifiedorderOffline' : '/pay/unifiedorder';

        $param = [
            'outTradeNo' => $tradeNo,
            'totalFee' => intval(round($ctx->order['realmoney'] * 100)),
            'currency' => 'CNY',
            'tradeType' => $tradeType,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'pageUrl' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'extra' => $extra,
            'body' => $ctx->ordername,
            'clientIp' => request()->clientip,
        ];
        if (!empty($this->channel['appmchid'])) $param['subMchId'] = $this->channel['appmchid'];
        if ($ctx->order['profits'] > 0) $param['isNeedShare'] = 'TRUE';

        return self::lockPayData($tradeNo, function () use ($client, $path, $param, $tradeNo) {
            $result = $client->execute($path, $param);
            $this->updateOrder($tradeNo, $result['transactionId']);
            return $result['payInfo'];
        });
    }

    //微信小程序收银台
    private function wxlite(PaymentContext $ctx): mixed
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $clientip = request()->clientip;

        $client = $this->createClient();

        $param = [
            'outTradeNo' => $tradeNo,
            'totalFee' => intval(round($ctx->order['realmoney'] * 100)),
            'currency' => 'CNY',
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'pageUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'nonceStr' => random(32),
            'body' => $ctx->ordername,
            'clientIp' => $clientip,
        ];
        if (!empty($this->channel['appmchid'])) $param['subMchId'] = $this->channel['appmchid'];
        if ($ctx->order['profits'] > 0) $param['isNeedShare'] = 'TRUE';

        return self::lockPayData($tradeNo, function () use ($client, $param, $tradeNo) {
            $result = $client->execute('/pay/preUnifieAppletdorder', $param);
            $this->updateOrder($tradeNo, $result['transactionId']);
            return $result['payInfo'];
        });
    }

    //支付宝支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('3', $this->channel['apptype']) && $ctx->isMobile) {
            $tradeType = 'alipay_wap';
        } elseif (in_array('2', $this->channel['apptype']) && !$ctx->isMobile) {
            $tradeType = 'alipay_pc';
        } elseif (in_array('4', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
            $tradeType = 'alipay_jsapi';
        } else {
            $tradeType = 'alipay_qr';
        }
        if (!isset($code_url)) {
            try {
                $code_url = $this->addOrder($ctx, $tradeType);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
            }
        }

        if ($tradeType == 'alipay_qr' || $tradeType == 'alipay_jsapi') {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        } else {
            return ['type' => 'jump', 'url' => $code_url];
        }
    }

    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!empty($ctx->order['sub_openid'])) {
            $user_id = $ctx->order['sub_openid'];
            $user_type = null;
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }

        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;

        if ($user_type == 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $pay_info = $this->addOrder($ctx, 'alipay_jsapi', json_encode(['openId' => $user_id]));
            $result = json_decode($pay_info, true);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['tradeNo']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['tradeNo'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype'])) {
            try {
                $code_url = $this->addOrder($ctx, 'wx_native');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('1', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
        } elseif (in_array('4', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } elseif (in_array('5', $this->channel['apptype'])) {
            try {
                $code_url = $this->addOrder($ctx, 'shengpay_aggre');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } else {
            return ['type' => 'error', 'msg' => '当前支付通道没有开启的支付方式'];
        }

        if ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
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
            $pay_info = $this->addOrder($ctx, $ctx->order['is_applet'] == 1 ? 'wx_lite' : 'wx_jsapi', json_encode(['openId' => $openid, 'appId' => $wxinfo['appid']]));
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
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
            $pay_info = $this->addOrder($ctx, 'wx_lite', json_encode(['openId' => $openid, 'appId' => $wxinfo['appid']]));
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($pay_info, true)]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        if (in_array('3', $this->channel['apptype'])) {
            try {
                $code_url = $this->addOrder($ctx, 'wx_wap');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'jump', 'url' => $code_url];
        } elseif (in_array('4', $this->channel['apptype'])) {
            try {
                $code_url = $this->wxlite($ctx);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } else {
            return $this->wxpay($ctx);
        }
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'upacp_qr');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        $verify_result = $client->verifySign($data);

        if ($verify_result) {
            if ($data['status'] == 'PAY_SUCCESS') {
                $out_trade_no = $data['outTradeNo'];
                $trade_no = $data['transactionId'];
                $payerInfo = json_decode($data['payerInfo'], true);
                $buyer = $payerInfo['openid'] ?? null;
                $bill_trade_no = $payerInfo['officOrderNum'] ?? null;
                $attach = json_decode($data['attach'], true);
                $bill_mch_trade_no = $attach['sftInstOrderNo'] ?? null;
                $end_time = $data['timeEnd'];
                if ($out_trade_no == $tradeNo) {
                    ($this->markTrustedCallback($ctx, 'notify', 'shengpay-signature'))(function () use ($ctx, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time) {
                        $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                    });
                }
                return ['type' => 'html', 'data' => 'SUCCESS'];
            }
            return ['type' => 'html', 'data' => 'FAIL'];
        } else {
            return ['type' => 'html', 'data' => 'SIGN FAIL'];
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
            'outTradeNo' => $order['trade_no'],
        ];
        $result = $client->execute('/pay/queryOrder', $params);
        $buyer = null;
        $bill_trade_no = null;
        $bill_mch_trade_no = null;
        if (isset($result['payerInfo'])) {
            $payerInfo = json_decode($result['payerInfo'], true);
            $buyer = $payerInfo['openid'] ?? null;
            $bill_trade_no = $payerInfo['officOrderNum'] ?? null;
        }
        if (isset($result['attach'])) {
            $attach = json_decode($result['attach'], true);
            $bill_mch_trade_no = $attach['sftInstOrderNo'] ?? null;
        }
        return [
            'api_trade_no' => $result['transactionId'],
            'status' => $result['status'] == 'PAY_SUCCESS' ? 1 : 0,
            'money' => $result['totalFee'] / 100,
            'buyer' => $buyer,
            'bill_trade_no' => $bill_trade_no,
            'bill_mch_trade_no' => $bill_mch_trade_no,
            'endtime' => $result['timeEnd'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->createClient();

        $param = [
            'outTradeNo' => $order['trade_no'],
            'outRefundNo' => $order['refund_no'] ? $order['refund_no'] : 'R' . $order['trade_no'],
            'refundFee' => intval(round($order['refundmoney'] * 100)),
            'notifyUrl' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
        ];

        try {
            $result = $client->execute('/refund/orderRefund', $param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        return ['code' => 0, 'trade_no' => $result['refundId'], 'refund_fee' => $result['refundFee'] / 100];
    }

    //退款异步回调
    public function refundnotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        $verify_result = $client->verifySign($data);

        if ($verify_result) {
            $status = ($data['refundStatus'] ?? '') == 'REFUND_SUCCESS' ? 1 : 2;
            if ($data['refundStatus'] == 'REFUND_SUCCESS') {
                $out_trade_no = $data['refundOrderNo'];
                $trade_no = $data['refundId'];

            }
            ($this->markTrustedCallback($ctx, 'refundnotify', 'shengpay-signature'))(function () use ($data, $status) {
                $this->processRefund(
                    $data['refundOrderNo'] ?? '',
                    $status,
                    $status === 2 ? 'shengpay refund failed' : '',
                    $data['refundId'] ?? '',
                    isset($data['refundFee']) ? $data['refundFee'] / 100 : null
                );
            });
            return ['type' => 'html', 'data' => $status === 1 ? 'SUCCESS' : 'FAIL'];
        } else {
            return ['type' => 'html', 'data' => 'SIGN FAIL'];
        }
    }

    //转账
    public function transfer(array $bizParam): array
    {
        $client = $this->createClient();

        $params = [
            'mchOrderNo' => $bizParam['out_biz_no'],
            'transAmount' => intval(round($bizParam['money'] * 100)),
            'payeeAccount' => $bizParam['payee_account'],
            'accountType' => 'C',
            'payeeName' => $bizParam['payee_real_name'],
            'remark' => $bizParam['transfer_desc'],
            'notifyUrl' => config_get('localurl') . 'pay/transfernotify/' . $this->channel['id'] . '/',
        ];

        try {
            $result = $client->execute('/merchant/fund/transfer', $params);
            return ['code' => 0, 'status' => 0, 'orderid' => $result['transNo'], 'paydate' => date('Y-m-d H:i:s')];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账查询
    public function transfer_query(array $bizParam): array
    {
        $client = $this->createClient();

        $params = [
            'transNo' => $bizParam['orderid'],
        ];
        try {
            $result = $client->execute('/merchant/fund/query', $params);
            $errmsg = '';
            if ($result['transStatus'] == 'SUCCESS') {
                $status = 1;
            } elseif ($result['transStatus'] == 'FAIL') {
                $status = 2;
                $errmsg = $result['transStatusDes'];
            } else {
                $status = 0;
            }
            return ['code' => 0, 'status' => $status, 'errmsg' => $errmsg];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //付款异步回调
    public function transfernotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        $verify_result = $client->verifySign($data);

        if ($verify_result) {
            $errmsg = null;
            if ($data['transStatus'] == 'SUCCESS') {
                $status = 1;
            } elseif ($data['transStatus'] == 'FAIL') {
                $status = 2;
                $errmsg = $data['transStatusDes'];
            } else {
                $status = 0;
            }
            ($this->markTrustedCallback($ctx, 'transfernotify', 'shengpay-signature'))(function () use ($data, $status, $errmsg) {
                $this->processTransfer($data['mchOrderNo'], $status, $errmsg);
            });
            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            return ['type' => 'html', 'data' => 'SIGN FAIL'];
        }
    }

    //微信参数配置
    public function wxconfig()
    {
        $channel = $this->channel;
        $siteurl = request()->siteurl;
        $client = $this->createClient();

        if (request()->has('conf_type', 'post') && request()->has('conf_value', 'post')) {
            $conf_value = trim(request()->post('conf_value'));
            $params = [
                'subMchId' => !empty($channel['appmchid']) ? $channel['appmchid'] : $channel['appid'],
                'configType' => request()->post('conf_type'),
                'appId' => trim(request()->post('appid', '')),
            ];
            if (request()->post('conf_type') == '1') $params['payUrl'] = $conf_value;
            elseif (request()->post('conf_type') == '2') $params['appId'] = $conf_value;
            try {
                $client->execute('/report/appidBind', $params);
                return $this->showMsg('微信参数配置成功！', 1);
            } catch (Exception $e) {
                return $this->showMsg('微信参数配置失败！' . $e->getMessage(), 4);
            }
        }

        $wxinfo = \app\lib\Channel::getWeixin($channel['appwxmp']);

        $param = [
            'subMchId' => !empty($channel['appmchid']) ? $channel['appmchid'] : $channel['appid'],
            'configType' => '5',
        ];
        try {
            $result = $client->execute('/report/appidBind', $param);
            $appid_list = json_decode($result['appidConfigArray'] ?? '[]', true);
            $payurl_list = json_decode($result['payUrlArray'] ?? '[]', true);
            if (!is_array($appid_list)) {
                $appid_list = [];
            }
            if (!is_array($payurl_list)) {
                $payurl_list = [];
            }
            $data = ['appid_list' => $appid_list, 'payurl_list' => $payurl_list, 'appid' => $wxinfo ? $wxinfo['appid'] : ''];
            return view($this->payRoot . 'view/wxconf.html', [
                'channel' => $channel,
                'siteurl' => $siteurl,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return $this->showMsg('微信参数配置查询失败！' . $e->getMessage(), 4);
        }
    }

    //进件异步回调
    public function applynotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        $verify_result = $client->verifySign($data);

        if ($verify_result) {
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->notify($data);
            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            return ['type' => 'html', 'data' => 'SIGN FAIL'];
        }
    }

    //提现异步回调
    public function settlenotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        $verify_result = $client->verifySign($data);

        if ($verify_result) {
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->settlenotify($data);
            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            return ['type' => 'html', 'data' => 'SIGN FAIL'];
        }
    }

    //分账异步回调
    public function sharingnotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        $verify_result = $client->verifySign($data);

        if ($verify_result) {
            if ($data['status'] == 'FINISHED') {
                $receivers = json_decode($data['receivers'], true);
                $info = $receivers[0];
                if ($info['sharingStatus'] == 'SUCCESS') {
                    $this->processProfitSharing($data['mchSharingNo'], 2);
                } elseif ($info['sharingStatus'] == 'FAIL') {
                    $this->processProfitSharing($data['mchSharingNo'], 3, $info['failReason']);
                }
            }
            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            return ['type' => 'html', 'data' => 'SIGN FAIL'];
        }
    }

    //投诉回调
    public function complainnotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data || !isset($data['resource'])) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        $res = $data['resource'];
        $decrypted = $client->decrpytEvent($res['ciphertext'], $res['nonce'], '', $this->channel['aeskey']);

        if ($decrypted) {
            $decryptedData = json_decode($decrypted, true);
            $channel = $this->channel;
            $channel['type'] = $decryptedData['tradeType'] == '支付宝' ? 1 : 2;
            $model = \app\logic\ComplainLogic::getModel($channel);
            $model->refreshNewInfo($decryptedData['complaintId'], $decryptedData);
            return ['type' => 'html', 'data' => 'SUCCESS'];
        } else {
            return ['type' => 'html', 'data' => 'SIGN FAIL'];
        }
    }
}
