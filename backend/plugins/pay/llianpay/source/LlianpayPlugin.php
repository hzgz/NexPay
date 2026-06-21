<?php

declare(strict_types=1);

namespace plugins\payment\llianpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use think\facade\Db;
use Exception;

class LlianpayPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $apptype)) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $this->channel['appwxa'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            if (in_array('3', $apptype)) {
                return ['type' => 'jump', 'url' => '/pay/entpay/' . $tradeNo . '/'];
            } elseif (in_array('2', $apptype)) {
                return ['type' => 'jump', 'url' => '/pay/quickpay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if ($ctx->method === 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $apptype)) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            if (in_array('3', $apptype)) {
                return $this->entpay($ctx);
            } elseif (in_array('2', $apptype)) {
                return $this->quickpay($ctx);
            } else {
                return $this->bank($ctx);
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //统一下单接口
    private function addOrder(PaymentContext $ctx, string $payType, ?array $extend_info = null)
    {
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        $user_id = substr(md5($clientip), 0, 10);
        $params = [
            'mch_id' => $this->channel['appid'],
            'user_id' => $user_id,
            'busi_type' => $this->channel['busi_type'],
            'txn_seqno' => $tradeNo,
            'txn_time' => date('YmdHis'),
            'order_amount' => $ctx->order['realmoney'],
            'order_info' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'payee_infos' => [[
                'payee_uid' => $this->channel['appid'],
                'payee_accttype' => 'MCHOWN',
                'payee_type' => 'MCH',
                'payee_amount' => $ctx->order['realmoney'],
            ]],
            'pay_method_infos' => [[
                'pay_type' => $payType,
                'amount' => $ctx->order['realmoney'],
            ]],
            'goods_info' => [[
                'goods_id' => '100001',
                'goods_name' => $ctx->ordername,
            ]],
            'risk_info' => [
                'ip_addr' => $clientip,
            ],
            'risk_item' => json_encode(['frms_ware_category' => '1004', 'user_info_mercht_userno' => $user_id, 'user_info_dt_register' => date('YmdHis', strtotime("-1 year")), 'goods_name' => $ctx->order['name']]),
        ];
        if (!empty($this->channel['appmchid'])) {
            $params['sub_mchid'] = $this->channel['appmchid'];
        }
        if (!empty($this->channel['payee_uid'])) {
            $params['share_flag'] = 'IMMEDIATE';
            $params['payee_infos'] = [[
                'payee_uid' => $this->channel['payee_uid'],
                'payee_accttype' => 'USEROWN',
                'payee_type' => 'USER',
                'payee_amount' => $ctx->order['realmoney'],
            ]];
        }
        if (!empty($this->channel['chnlmchid'])) {
            $chnlmchid = $this->channel['chnlmchid'];
            if (strpos($chnlmchid, ',')) {
                $mchids = explode(',', $chnlmchid);
                $chnlmchid = $mchids[array_rand($mchids)];
            }
            if (strpos($payType, 'ALIPAY') !== false) {
                $extend_info['alipay_data']['ali_sub_mchid'] = $chnlmchid;
            } elseif (strpos($payType, 'WECHAT') !== false) {
                $extend_info['wx_data']['wx_sub_mchid'] = $chnlmchid;
            }
        }
        if ($extend_info) {
            $params['extend_info'] = json_encode($extend_info);
        }

        $client = new LLianPayClient($this->channel['appid'], $this->channel['appkey']);
        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->sendRequest('/mch/v1/ipay/createpay', $params);
            return $result['payload'];
        });
    }

    //收银台接口
    private function cashier(PaymentContext $ctx, ?array $extend_info = null): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $clientip = request()->clientip;

        $params = [
            'mch_id' => $this->channel['appid'],
            'user_id' => str_replace('.', '_', $clientip),
            'busi_type' => $this->channel['busi_type'],
            'txn_seqno' => $tradeNo,
            'txn_time' => date('YmdHis'),
            'order_amount' => $ctx->order['realmoney'],
            'order_info' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'payee_infos' => [[
                'payee_uid' => $this->channel['appid'],
                'payee_accttype' => 'MCHOWN',
                'payee_type' => 'MCH',
                'payee_amount' => $ctx->order['realmoney'],
            ]],
            'goods_info' => [[
                'goods_id' => '100001',
                'goods_name' => $ctx->ordername,
            ]],
            'risk_info' => [
                'ip_addr' => $clientip,
            ]
        ];
        if (!empty($this->channel['appmchid'])) {
            $params['sub_mchid'] = $this->channel['appmchid'];
        }
        if ($extend_info) {
            $params['extend_info'] = json_encode($extend_info);
        }

        $client = new LLianPayClient($this->channel['appid'], $this->channel['appkey']);
        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->sendRequest('/mch/v1/cashier/createpay', $params);
            return $result['gateway_url'];
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if (in_array('2', $apptype) && !in_array('1', $apptype)) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $code_url = $this->addOrder($ctx, 'ALIPAY_NATIVE');
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
            $result = $this->addOrder($ctx, 'ALIPAY_APPLET', ['alipay_data' => ['openid' => $user_id]]);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method === 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result, 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if (in_array('2', $apptype) && !in_array('1', $apptype)) {
            if ($this->channel['appwxmp'] > 0 && $this->channel['appwxa'] == 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
        } else {
            try {
                $code_url = $this->addOrder($ctx, 'WECHAT_NATIVE');
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
            $result = $this->addOrder($ctx, $ctx->order['is_applet'] == 1 ? 'WECHAT_APPLET' : 'WECHAT_JSAPI', ['wx_data' => ['appid' => $wxinfo['appid'], 'openid' => $openid]]);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method === 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $result, 'redirect_url' => $redirect_url]];
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
            $result = $this->addOrder($ctx, 'WECHAT_APPLET', ['wx_data' => ['appid' => $wxinfo['appid'], 'openid' => $openid]]);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($result, true)]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'CLOUDPAY_APP');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //快捷支付
    public function quickpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->cashier($ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '收银台下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    //微企付
    public function entpay(PaymentContext $ctx): array
    {
        if ($ctx->isMobile) {
            $pay_type = 'WECHAT_BUSI_H5';
        } else {
            $pay_type = 'WECHAT_BUSI_PC';
        }
        try {
            $code_url = $this->addOrder($ctx, $pay_type);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微企付下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'jump', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => 'no data'];

        $sign = request()->header('signature-data');
        if (empty($sign)) return ['type' => 'html', 'data' => 'no sign'];

        $client = new LLianPayClient($this->channel['appid'], $this->channel['appkey']);
        $verify_result = $client->verifySign($json, $sign);

        if ($verify_result) {//验证成功

            if ($arr['txn_status'] == 'SUCCESS') {
                $out_trade_no = $arr['txn_seqno'];
                $api_trade_no = $arr['platform_txno'];
                $money = $arr['order_amount'];
                $bill_trade_no = $arr['chnl_txno'] ?? '';
                $bill_mch_trade_no = $arr['chnl_req_serialId'] ?? '';
                $buyer = $arr['chnl_user_id'] ?? '';
                $pay_type = $arr['pay_method_infos'][0]['pay_type'] ?? '';
                $end_time = $arr['success_time'] ?? '';
                if ($out_trade_no == $ctx->order['trade_no']) {
                    Db::name('order')->where('trade_no', $ctx->order['trade_no'])->update(['ext' => $pay_type]);
                    ($this->markTrustedCallback($ctx, 'notify', 'llianpay-signature'))(function () use ($ctx, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time) {
                        $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                    });
                }
            }
            return ['type' => 'html', 'data' => 'Success'];
        } else {
            return ['type' => 'html', 'data' => 'Fail'];
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
            'mch_id' => $this->channel['appid'],
            'txn_seqno' => $order['trade_no'],
        ];
        $client = new LLianPayClient($this->channel['appid'], $this->channel['appkey']);
        $result = $client->sendRequest('/query/v1/ipay/orderquery', $params);
        $pay_type = $result['pay_method_infos'][0]['pay_type'] ?? '';
        Db::name('order')->where('trade_no', $order['trade_no'])->update(['ext' => $pay_type]);
        return [
            'api_trade_no' => $result['platform_txno'],
            'status' => $result['txn_status'] == 'SUCCESS' ? 1 : 0,
            'money' => $result['order_amount'],
            'buyer' => $result['chnl_user_id'] ?? '',
            'bill_trade_no' => $result['chnl_txno'] ?? '',
            'bill_mch_trade_no' => $result['chnl_req_serialId'] ?? '',
            'endtime' => $result['success_time'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $pay_type = $order['ext'];

        $params = [
            'mch_id' => $this->channel['appid'],
            'refund_seqno' => $order['refund_no'],
            'refund_time' => date('YmdHis'),
            'txn_seqno' => $order['trade_no'],
            'refund_amount' => $order['refundmoney'],
            'notify_url' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
            'refund_method_infos' => [[
                'pay_type' => $pay_type,
                'amount' => $order['realmoney'],
            ]],
            'payee_refund_infos' => [[
                'payee_uid' => $this->channel['appid'],
                'payee_accttype' => 'MCHOWN',
                'payee_type' => 'MCH',
                'payee_amount' => $order['realmoney'],
            ]]
        ];
        if (!empty($this->channel['appmchid'])) {
            $params['sub_mchid'] = $this->channel['appmchid'];
        }
        if (!empty($this->channel['payee_uid'])) {
            $params['payee_refund_infos'] = [[
                'payee_uid' => $this->channel['payee_uid'],
                'payee_accttype' => 'USEROWN',
                'payee_type' => 'USER',
                'payee_amount' => $order['realmoney'],
            ]];
        }

        try {
            $client = new LLianPayClient($this->channel['appid'], $this->channel['appkey']);
            $result = $client->sendRequest('/mch/v1/ipay/refund', $params);

            return ['code' => 0, 'trade_no' => $result['platform_refundno'], 'refund_fee' => $result['refund_amount']];

        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //退款异步回调
    public function refundnotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => 'no data'];

        $sign = request()->header('signature-data');
        if (empty($sign)) return ['type' => 'html', 'data' => 'no sign'];

        $client = new LLianPayClient($this->channel['appid'], $this->channel['appkey']);
        $verify_result = $client->verifySign($json, $sign);

        if ($verify_result) {//验证成功

            if ($arr['refund_status'] == 'SUCCESS') {
                $out_trade_no = $arr['refund_seqno'];
                $api_trade_no = $arr['platform_refundno'];
                $money = $arr['refund_amount'];
            }
            $status = ($arr['refund_status'] ?? '') === 'SUCCESS' ? 1 : (($arr['refund_status'] ?? '') === '' ? 0 : 2);
            ($this->markTrustedCallback($ctx, 'refundnotify', 'llianpay-signature'))(function () use ($arr, $status) {
                $this->processRefund(
                    $arr['refund_seqno'] ?? '',
                    $status,
                    $status === 2 ? (string)($arr['ret_msg'] ?? $arr['refund_status'] ?? 'llianpay refund failed') : '',
                    $arr['platform_refundno'] ?? '',
                    $arr['refund_amount'] ?? null
                );
            });
            return ['type' => 'html', 'data' => 'Success'];
        } else {
            return ['type' => 'html', 'data' => 'Fail'];
        }
    }

    //提现回调
    public function drawnotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => 'no data'];

        $sign = request()->header('signature-data');
        if (empty($sign)) return ['type' => 'html', 'data' => 'no sign'];

        $client = new LLianPayClient($this->channel['appid'], $this->channel['appkey']);
        $verify_result = $client->verifySign($json, $sign);

        if ($verify_result) {//验证成功

            $service = new LLianPayWithDraw($this->channel);
            $service->notify($arr);
            return ['type' => 'html', 'data' => 'Success'];
        } else {
            return ['type' => 'html', 'data' => 'Fail'];
        }
    }

    //进件回调
    public function applynotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => 'no data'];

        $sign = request()->header('signature-data');
        if (empty($sign)) return ['type' => 'html', 'data' => 'no sign'];

        $client = new LLianPayClient($this->channel['appid'], $this->channel['appkey']);
        $verify_result = $client->verifySign($json, $sign);

        if ($verify_result) {//验证成功

            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->notify($arr);
            return ['type' => 'html', 'data' => 'Success'];
        } else {
            return ['type' => 'html', 'data' => 'Fail'];
        }
    }
}
