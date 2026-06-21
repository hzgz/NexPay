<?php

declare(strict_types=1);

namespace plugins\payment\fuiou2;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class Fuiou2Plugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] === 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] === 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $ctx->mdevice !== 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] === 'bank') {
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->method === 'jsapi') {
            if ($ctx->order['typename'] === 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] === 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->method === 'scan') {
            return $this->scanpay($ctx);
        } elseif ($ctx->order['typename'] === 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] === 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $ctx->mdevice !== 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] === 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //通用下单
    private function addOrder(PaymentContext $ctx, string $pay_type): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $param = [
            'order_type' => $pay_type,
            'order_amt' => strval($ctx->order['realmoney'] * 100),
            'mchnt_order_no' => $this->channel['appurl'] . $tradeNo,
            'txn_begin_ts' => date('YmdHis'),
            'goods_des' => $ctx->ordername,
            'term_ip' => request()->clientip,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'goods_detail' => '',
            'addn_inf' => '',
            'curr_type' => 'CNY',
            'goods_tag' => '',
        ];
        $client = new PayService($this->channel['appid'], $this->channel['appmchid'], $this->channel['appkey'], $this->channel['appsecret'], $this->channel['appswitch'] == 1);
        return self::lockPayData($tradeNo, function () use ($client, $param) {
            $result = $client->submit('/preCreate', $param);
            return $result['qr_code'];
        });
    }

    //JSAPI支付
    private function jspay(string $trade_type, string $sub_appid, string $sub_openid, PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $param = [
            'trade_type' => $trade_type,
            'order_amt' => strval($ctx->order['realmoney'] * 100),
            'mchnt_order_no' => $this->channel['appurl'] . $tradeNo,
            'txn_begin_ts' => date('YmdHis'),
            'goods_des' => $ctx->ordername,
            'term_ip' => request()->clientip,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'limit_pay' => '',
            'goods_detail' => '',
            'addn_inf' => '',
            'curr_type' => 'CNY',
            'goods_tag' => '',
            'product_id' => '',
            'openid' => '',
            'sub_openid' => $sub_openid,
            'sub_appid' => $sub_appid,
        ];
        $client = new PayService($this->channel['appid'], $this->channel['appmchid'], $this->channel['appkey'], $this->channel['appsecret'], $this->channel['appswitch'] == 1);
        return self::lockPayData($tradeNo, function () use ($client, $param) {
            $result = $client->submit('/wxPreCreate', $param);
            return $result;
        });
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
                $code_url = $this->addOrder($ctx, 'ALIPAY');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
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
        if ($blocks) {
            return $blocks;
        }
        if ($user_type === 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $result = $this->jspay('FWC', '', $user_id, $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }
        if (empty($result['reserved_transaction_id'])) return ['type' => 'error', 'msg' => '支付宝下单失败！支付订单号为空'];

        if ($ctx->method === 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['reserved_transaction_id']];
        }
        
        if (request()->get('d') === '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $ctx->order['trade_no'] . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['reserved_transaction_id'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = $this->channel['appwxmp'] > 0
                ? $siteurl . 'pay/wxjspay/' . $tradeNo . '/'
                : $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } else {
            try {
                $code_url = $this->addOrder($ctx, 'WECHAT');
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

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $code = request()->get('code');
        if (empty($code)) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }

        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];
        try {
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];

        try {
            $result = $this->jspay('LETPAY', $wxinfo['appid'], $openid, $ctx);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }
        $payinfo = [
            'appId' => $result['sdk_appid'],
            'timeStamp' => $result['sdk_timestamp'],
            'nonceStr' => $result['sdk_noncestr'],
            'package' => $result['sdk_package'],
            'signType' => $result['sdk_signtype'],
            'paySign' => $result['sdk_paysign'],
        ];
        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $payinfo]];
    }

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!empty($ctx->order['sub_openid'])) {
            if (!empty($ctx->order['sub_appid'])) {
                $wxinfo = ['appid' => $ctx->order['sub_appid']];
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
            $result = $this->jspay($ctx->order['is_applet'] == 1 ? 'LETPAY' : 'JSAPI', $wxinfo['appid'], $openid, $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        $payinfo = [
            'appId' => $result['sdk_appid'],
            'timeStamp' => $result['sdk_timestamp'],
            'nonceStr' => $result['sdk_noncestr'],
            'package' => $result['sdk_package'],
            'signType' => $result['sdk_signtype'],
            'paySign' => $result['sdk_paysign'],
        ];
        if ($ctx->method === 'jsapi') {
            return ['type' => 'jsapi', 'data' => json_encode($payinfo)];
        }

        if (request()->get('d') === '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $ctx->order['trade_no'] . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => json_encode($payinfo), 'redirect_url' => $redirect_url]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        if ($this->channel['appwxa'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) {
                return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            }
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        }
        return $this->wxpay($ctx);
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'UNIONPAY');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '银联云闪付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->mdevice === 'unionpay') {
            return ['type' => 'jump', 'url' => $code_url];
        }
        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //被扫支付
    public function scanpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $order = $ctx->order;

        if ($order['typename'] === 'alipay') {
            $order_type = 'ALIPAY';
        } elseif ($order['typename'] === 'wxpay') {
            $order_type = 'WECHAT';
        } elseif ($order['typename'] === 'bank') {
            $order_type = 'UNIONPAY';
        } else {
            return ['type' => 'error', 'msg' => '不支持的支付类型'];
        }

        $params = [
            'order_type' => $order_type,
            'order_amt' => strval($order['realmoney'] * 100),
            'mchnt_order_no' => $this->channel['appurl'] . $tradeNo,
            'txn_begin_ts' => date('YmdHis'),
            'goods_des' => $ctx->ordername,
            'goods_detail' => '',
            'term_ip' => request()->clientip,
            'auth_code' => $order['auth_code'],
            'sence' => '1',
            'addn_inf' => '',
            'curr_type' => 'CNY',
            'goods_tag' => '',
        ];
        $client = new PayService($this->channel['appid'], $this->channel['appmchid'], $this->channel['appkey'], $this->channel['appsecret'], $this->channel['appswitch'] == 1);

        try {
            $result = $client->submit('/micropay', $params);
            if ($result['result_code'] === '000000') {
                $this->processNotify($order, $result['reserved_mchnt_order_no'], $result['buyer_id'], $result['transaction_id']);
                return ['type' => 'scan', 'data' => [
                    'type' => $order['typename'],
                    'trade_no' => $tradeNo,
                    'api_trade_no' => $result['reserved_mchnt_order_no'],
                    'buyer' => $result['buyer_id'],
                    'money' => strval(round(($result['total_amount'] ?? $result['order_amt'] ?? 0) / 100, 2)),
                ]];
            }
            $retry = 0;
            $success = false;
            while ($retry < 6) {
                sleep(3);
                try {
                    $result = $this->orderQuery($client, $this->channel['appurl'] . $tradeNo, $order_type);
                } catch (Exception $e) {
                    return ['type' => 'error', 'msg' => '订单查询失败:' . $e->getMessage()];
                }
                if (($result['trans_stat'] ?? '') === 'SUCCESS') {
                    $success = true;
                    break;
                }
                $tranSts = $result['tranSts'] ?? $result['trans_stat'] ?? '';
                if ($tranSts !== 'USERPAYING' && $tranSts !== 'NOTPAY') {
                    return ['type' => 'error', 'msg' => '订单超时或用户取消支付'];
                }
                $retry++;
            }
            if ($success) {
                $this->processNotify($order, $result['mchnt_order_no'], $result['buyer_id'] ?? '', $result['transaction_id'] ?? '');
                return ['type' => 'scan', 'data' => [
                    'type' => $order['typename'],
                    'trade_no' => $tradeNo,
                    'api_trade_no' => $result['mchnt_order_no'],
                    'buyer' => $result['buyer_id'] ?? '',
                    'money' => strval(round(($result['order_amt'] ?? 0) / 100, 2)),
                ]];
            }
            try {
                $this->orderRevoked($client, $this->channel['appurl'] . $tradeNo, $order_type);
            } catch (Exception $e) {
            }
            return ['type' => 'error', 'msg' => '被扫下单失败！订单已超时'];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '被扫下单失败！' . $e->getMessage()];
        }
    }

    //订单查询
    private function orderQuery(PayService $client, string $out_trade_no, string $order_type): array
    {
        $params = ['order_type' => $order_type, 'mchnt_order_no' => $out_trade_no];
        return $client->request('/commonQuery', $params);
    }

    //撤销订单
    private function orderRevoked(PayService $client, string $out_trade_no, string $order_type): void
    {
        $params = [
            'order_type' => $order_type,
            'mchnt_order_no' => $out_trade_no,
            'cancel_order_no' => date('YmdHis') . rand(1000, 9999),
            'operator_id' => '',
        ];
        $client->request('/cancelorder', $params);
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $xml = request()->post('req');
        if (!$xml) {
            return ['type' => 'html', 'data' => 'no data'];
        }
        $xml = urldecode($xml);
        $arr = json_decode(json_encode(simplexml_load_string($xml)), true);
        if (!$arr) {
            return ['type' => 'html', 'data' => 'xml err'];
        }
        $client = new PayService($this->channel['appid'], $this->channel['appmchid'], $this->channel['appkey'], $this->channel['appsecret'], $this->channel['appswitch'] == 1);
        $verify_result = $client->verifySign($arr);
        if ($verify_result) {
            if ($arr['result_code'] === '000000') {
                $out_trade_no = substr($arr['mchnt_order_no'], strlen($this->channel['appurl']));
                $api_trade_no = $arr['mchnt_order_no'];
                $bill_trade_no = $arr['transaction_id'];
                $money = $arr['order_amt'];
                $buyer = $arr['user_id'] ?? '';
                $end_time = $arr['txn_fin_ts'];
                if ($out_trade_no === $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                }
                return ['type' => 'html', 'data' => '1'];
            }
            return ['type' => 'html', 'data' => '0'];
        }
        return ['type' => 'html', 'data' => '0'];
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        if ($order['type'] == 1) {
            $pay_type = 'ALIPAY';
        } elseif ($order['type'] == 2) {
            $pay_type = 'WECHAT';
        } elseif ($order['type'] == 4) {
            $pay_type = 'UNIONPAY';
        } else {
            return ['code' => -1, 'msg' => '不支持的支付类型'];
        }
        $client = new PayService($this->channel['appid'], $this->channel['appmchid'], $this->channel['appkey'], $this->channel['appsecret'], $this->channel['appswitch'] == 1);
        $result = $this->orderQuery($client, $this->channel['appurl'] . $order['trade_no'], $pay_type);
        return [
            'api_trade_no' => $result['mchnt_order_no'],
            'status' => $result['trans_stat'] == 'SUCCESS' ? 1 : 0,
            'money' => $result['order_amt'] / 100,
            'buyer' => $result['buyer_id'] ?? '',
            'bill_trade_no' => $result['transaction_id'] ?? '',
            'endtime' => $result['reserved_txn_fin_ts'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        if ($order['type'] == 1) {
            $pay_type = 'ALIPAY';
        } elseif ($order['type'] == 2) {
            $pay_type = 'WECHAT';
        } elseif ($order['type'] == 4) {
            $pay_type = 'UNIONPAY';
        } else {
            return ['code' => -1, 'msg' => '不支持的支付类型'];
        }
        $param = [
            'mchnt_order_no' => $order['api_trade_no'],
            'refund_order_no' => $order['refund_no'],
            'order_type' => $pay_type,
            'total_amt' => strval($order['realmoney'] * 100),
            'refund_amt' => strval($order['refundmoney'] * 100),
            'operator_id' => '',
        ];
        $client = new PayService($this->channel['appid'], $this->channel['appmchid'], $this->channel['appkey'], $this->channel['appsecret'], $this->channel['appswitch'] == 1);
        try {
            $result = $client->submit('/commonRefund', $param);
            return ['code' => 0, 'trade_no' => $result['mchnt_order_no'], 'refund_fee' => $result['reserved_refund_amt'] ?? 0];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //投诉回调
    public function complainnotify(): array
    {
        $json = request()->post('req');
        if (!$json) {
            return ['type' => 'html', 'data' => 'no req'];
        }
        $data = json_decode($json, true);
        if (!$data) {
            return ['type' => 'html', 'data' => 'no data'];
        }
        $client = new EntryService($this->channel['appid'], $this->channel['entrykey']);
        $verify_result = $client->verifySign($data);
        if ($verify_result) {
            $channelData = $this->channel;
            if (isset($channelData['appmchid']) && substr($channelData['appmchid'], 0, 1) === '[') {
                $channelData['appmchid'] = $data['fy_mchnt_cd'] ?? $channelData['appmchid'];
            }
            $model = \app\logic\ComplainLogic::getModel($channelData);
            $model->refreshNewInfo($data['complaint_id'], $data['action_type']);
            return ['type' => 'html', 'data' => '1'];
        }
        return ['type' => 'html', 'data' => '0'];
    }

    //平台入网审核回调
    public function applynotify(): array
    {
        $json = request()->post('req');
        if (!$json) {
            return ['type' => 'html', 'data' => 'no req'];
        }
        $data = json_decode($json, true);
        if (!$data) {
            return ['type' => 'html', 'data' => 'no data'];
        }
        $client = new EntryService($this->channel['appid'], $this->channel['entrykey']);
        $verify_result = $client->verifySign($data);
        if ($verify_result) {
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) {
                $model->notify($data);
            }
            return ['type' => 'html', 'data' => '1'];
        }
        return ['type' => 'html', 'data' => '0'];
    }
}
