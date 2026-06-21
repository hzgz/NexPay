<?php

//http://39.106.84.215:8181/docs/saas/saas-1fdsisa23tp3f

declare(strict_types=1);

namespace plugins\payment\haipay;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class HaipayPlugin extends BasePayment
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
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
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

    //预下单
    private function prepay(PaymentContext $ctx, string $pay_type, string $pay_mode, ?string $sub_openid = null, ?string $sub_appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'agent_no' => $this->channel['agent_no'],
            'merch_no' => $this->channel['merch_no'],
            'pay_type' => $pay_type,
            'pay_mode' => $pay_mode,
            'out_trade_no' => $tradeNo,
            'total_amount' => $ctx->order['realmoney'],
            'pn' => $this->channel['pn'],
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($sub_openid) {
            $params['openid'] = $sub_openid;
        }
        if ($sub_appid) {
            $params['appid'] = $sub_appid;
        }
        if ($pay_type == 'WX') {
            $params['extend_params'] = ['body' => $ctx->ordername];
        } elseif ($pay_type == 'ALI') {
            $params['extend_params'] = ['subject' => $ctx->ordername];
        }
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx->order);
        }
        $client = new HaiPayClient($this->channel['accessid'], $this->channel['accesskey']);
        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->payRequest('/api/v2/pay/pre-pay', $params);
            $this->updateOrder($tradeNo, $result['trade_no']);
            return $result;
        });
    }

    private function handleProfits(array &$params, array $order): void
    {
        $psreceiver = \app\logic\ProfitSharingLogic::getReceiver($order['profits']);
        if ($psreceiver) {
            $relation = [];
            foreach ($psreceiver['info'] as $receiver) {
                $psmoney = round(floor($order['realmoney'] * $receiver['rate']) / 100, 2);
                $relation[] = [
                    'receive_no' => $receiver['account'],
                    'amt' => sprintf('%.2f', $psmoney),
                ];
            }
            $params['ledger_biz'] = [
                'ledger_type' => $psreceiver['mode'] == 1 ? 'DELAY_SETTLE' : 'REALTIME_SETTLE',
                'ledger_relation' => $relation,
            ];
        }
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype']) || empty($this->channel['apptype'][0])) {
            try {
                $result = $this->prepay($ctx, 'ALI', 'NATIVE');
                $code_url = $result['ali_qr_code'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('2', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        }
        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //支付宝JS支付
    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!empty($ctx->order['sub_openid'])) {
            $user_id = $ctx->order['sub_openid'];
            $user_type = 'userid';
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }
        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;
        if ($user_type == 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $result = $this->prepay($ctx, 'ALI', 'JSAPI', $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['ali_trade_no']];
        }
        
        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['ali_trade_no'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        /*try{
			$result = $this->prepay('WX', 'NATIVE');
			$code_url = $result['code_url'];
		}catch(Exception $ex){
			return ['type'=>'error','msg'=>'微信支付下单失败！'.$ex->getMessage()];
		}*/
        if ($this->channel['appwxa'] > 0 && $this->channel['appwxmp'] == 0) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } else {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
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
            $result = $this->prepay($ctx, 'WX', 'JSAPI', $openid, $wxinfo['appid']);
            $pay_info = $result['wc_pay_data'];
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
        if ($blocks) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];

        try {
            $result = $this->prepay($ctx, 'WX', 'JSAPI', $openid, $wxinfo['appid']);
            $pay_info = $result['wc_pay_data'];
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }
        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($pay_info, true)]];
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

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $result = $this->prepay($ctx, 'UNIONQR', 'NATIVE');
            $code_url = $result['uniqr_qr_code'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //付款码支付
    public function scanpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'accessid' => $this->channel['accessid'],
            'merch_no' => $this->channel['merch_no'],
            'auth_code' => $ctx->order['auth_code'],
            'out_trade_no' => $tradeNo,
            'total_amount' => $ctx->order['realmoney'],
            'pn' => $this->channel['pn'],
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($ctx->order['typename'] == 'wxpay') {
            $params['extend_params'] = ['body' => $ctx->ordername];
        } elseif ($ctx->order['typename'] == 'alipay') {
            $params['extend_params'] = ['subject' => $ctx->ordername];
        }
        $params['terminal_info'] = ['device_ip' => request()->clientip];
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx->order);
        }
        
        try {
            $client = new HaiPayClient($this->channel['accessid'], $this->channel['accesskey']);
            $result = $client->payRequest('/api/v2/pay/passive-pay', $params);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '被扫下单失败！' . $e->getMessage()];
        }

        $api_trade_no = $result['trade_no'];
        if ($result['trade_status'] == '1') {
            $result = $this->orderQuery($client, $api_trade_no);
            return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['out_trade_no'], 'api_trade_no' => $api_trade_no, 'buyer' => $result['openid'] ?? '', 'money' => $result['order_amount']]];
        } else {
            $retry = 0;
            $success = false;
            while ($retry < 6) {
                sleep(3);
                try {
                    $result = $this->orderQuery($client, $api_trade_no);
                } catch (Exception $e) {
                    return ['type' => 'error', 'msg' => '订单查询失败:' . $e->getMessage()];
                }
                if ($result['trade_status'] == '1') {
                    $success = true;
                    break;
                } elseif ($result['tranSts'] != '3') {
                    return ['type' => 'error', 'msg' => '订单超时或用户取消支付'];
                }
                $retry++;
            }
            if ($success) {
                return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['out_trade_no'], 'api_trade_no' => $api_trade_no, 'buyer' => $result['openid'] ?? '', 'money' => $result['order_amount']]];
            } else {
                try {
                    $this->orderClose($client, $api_trade_no);
                } catch (Exception $e) {
                }
                return ['type' => 'error', 'msg' => '被扫下单失败！订单已超时'];
            }
        }
    }

    //查询订单
    private function orderQuery(HaiPayClient $client, string $api_trade_no): array
    {
        $params = [
            'merch_no' => $this->channel['merch_no'],
            'trade_no' => $api_trade_no,
        ];
        return $client->payRequest('/api/v2/pay/order-query', $params);
    }

    //关闭订单
    private function orderClose(HaiPayClient $client, string $api_trade_no): array
    {
        $params = [
            'merch_no' => $this->channel['merch_no'],
            'trade_no' => $api_trade_no,
        ];
        return $client->payRequest('/api/v2/pay/close-order', $params);
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) {
            return ['type' => 'html', 'data' => 'No data'];
        }
        $client = new HaiPayClient($this->channel['accessid'], $this->channel['accesskey']);
        if ($client->verify($arr)) {
            if ($arr['trade_status'] == '1') {
                $out_trade_no = $arr['out_trade_no'];
                $api_trade_no = $arr['trade_no'];
                $bill_trade_no = $arr['bank_trade_no'];
                $money = $arr['order_amount'];
                $buyer = $arr['openid'] ?? '';
                $end_time = $arr['end_time'];
                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                }
            }
            return ['type' => 'json', 'data' => ['return_code' => 'SUCCESS']];
        } else {
            return ['type' => 'json', 'data' => ['return_code' => 'FAIL', 'return_msg' => 'SIGN ERROR']];
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
            'merch_no' => $this->channel['merch_no'],
            'out_trade_no' => $order['trade_no'],
        ];
        $client = new HaiPayClient($this->channel['accessid'], $this->channel['accesskey']);
        $result = $client->payRequest('/api/v2/pay/order-query', $params);
        return [
            'api_trade_no' => $result['trade_no'],
            'status' => $result['trade_status'] == '1' ? 1 : 0,
            'money' => $result['order_amount'],
            'buyer' => $result['openid'] ?? '',
            'bill_trade_no' => $result['bank_trade_no'] ?? '',
            'endtime' => $result['end_time'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'agent_no' => $this->channel['agent_no'],
            'merch_no' => $this->channel['merch_no'],
            'trade_no' => $order['api_trade_no'],
            'out_refund_no' => $order['refund_no'],
            'refund_amount' => $order['refundmoney'],
            'pn' => $this->channel['pn'],
        ];
        try {
            $client = new HaiPayClient($this->channel['accessid'], $this->channel['accesskey']);
            $result = $client->payRequest('/api/v2/pay/refund', $params);
            return ['code' => 0, 'trade_no' => $result['refund_no'], 'refund_fee' => $result['refund_amount']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //进件回调
    public function applynotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) {
            return ['type' => 'html', 'data' => 'No data'];
        }
        $client = new HaiPayClient($this->channel['accessid'], $this->channel['accesskey']);
        if ($client->verify($arr)) {
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) {
                $model->notify($arr);
            }
            return ['type' => 'json', 'data' => ['return_code' => 'SUCCESS']];
        } else {
            return ['type' => 'json', 'data' => ['return_code' => 'FAIL', 'return_msg' => 'SIGN ERROR']];
        }
    }

    //投诉回调
    public function complainnotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) {
            return ['type' => 'html', 'data' => 'No data'];
        }
        $client = new HaiPayClient($this->channel['accessid'], $this->channel['accesskey']);
        if ($client->verify($arr)) {
            $thirdid = $arr['complaintId'];
            if (isset($arr['gmtComplain'])) {
                $this->channel['type'] = 1;
            } else {
                $this->channel['type'] = 2;
            }
            $model = \app\logic\ComplainLogic::getModel($this->channel);
            $model->refreshNewInfo($thirdid);
            return ['type' => 'json', 'data' => ['return_code' => 'SUCCESS']];
        } else {
            return ['type' => 'json', 'data' => ['return_code' => 'FAIL', 'return_msg' => 'SIGN ERROR']];
        }
    }
}
