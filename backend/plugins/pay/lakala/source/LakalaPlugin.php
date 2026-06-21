<?php

declare(strict_types=1);

namespace plugins\payment\lakala;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class LakalaPlugin extends BasePayment
{
    private function createClient(): LakalaClient
    {
        $isTest = $this->channel['appswitch'] == 1;
        $platformCertPath = $this->payRoot . 'cert/' . ($isTest ? 'lkl-apigw-v2.cer' : 'lkl-apigw-v1.cer');
        $merchantCertPath = getCertFilePath($this->channel['mch_cert_path']);
        $merchantKeyPath = getCertFilePath($this->channel['mch_key_path']);
        return new LakalaClient($this->channel['appid'], $platformCertPath, $merchantCertPath, $merchantKeyPath, $isTest);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($this->channel['appselect'] == 1) {
            return ['type' => 'jump', 'url' => '/pay/cashier/' . $tradeNo . '/?type=' . $ctx->order['typename']];
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/'];
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

        if ($ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            } elseif ($ctx->order['typename'] == 'bank') {
                return $this->bankjs($ctx);
            }
        } elseif ($ctx->method == 'scan') {
            return $this->scanpay($ctx);
        } elseif ($this->channel['appselect'] == 1) {
            return ['type' => 'jump', 'url' => '/pay/cashier/' . $tradeNo . '/?type=' . $ctx->order['typename']];
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice == 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => request()->siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice == 'wechat' && $this->channel['appwxmp'] > 0) {
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

    //聚合扫码下单
    private function qrcode(PaymentContext $ctx, string $account_type, string $trans_type, ?array $extend = null): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'merchant_no' => $this->channel['appmchid'],
            'term_no' => $this->channel['appkey'],
            'out_trade_no' => $tradeNo,
            'account_type' => $account_type,
            'trans_type' => $trans_type,
            'total_amount' => strval($ctx->order['realmoney'] * 100),
            'location_info' => [
                'request_ip' => request()->clientip
            ],
            'subject' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($extend) {
            $params['acc_busi_fields'] = $extend;
        }

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params, $tradeNo) {
            $result = $client->execute('/api/v3/labs/trans/preorder', $params);
            $this->updateOrder($tradeNo, $result['trade_no']);
            return $result['acc_resp_fields'];
        });
    }

    //收银台下单
    public function cashier(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $pay_type = request()->get('type');

        if ($pay_type == 'alipay') {
            $pay_mode = 'ALIPAY';
        } elseif ($pay_type == 'wxpay') {
            $pay_mode = 'WECHAT';
        } elseif ($pay_type == 'bank') {
            $pay_mode = 'UNION';
        }

        $params = [
            'out_order_no' => $tradeNo,
            'merchant_no' => $this->channel['appmchid'],
            'total_amount' => strval($ctx->order['realmoney'] * 100),
            'order_efficient_time' => date('YmdHis', time() + 1200),
            'notify_url' => config_get('localurl') . 'pay/cashiernotify/' . $tradeNo . '/',
            'support_refund' => 1,
            'callback_url' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'order_info' => $ctx->ordername,
            'counter_param' => json_encode(['pay_mode' => $pay_mode]),
        ];

        $client = $this->createClient();

        try {
            $result = $client->execute('/api/v3/ccss/counter/order/special_create', $params);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '收银台下单失败！' . $ex->getMessage()];
        }
        $this->updateOrder($tradeNo, $result['pay_order_no']);
        return ['type' => 'jump', 'url' => $result['counter_url']];
    }

    //收银台交易查询
    public function cashierquery(PaymentContext $ctx): array
    {
        $client = $this->createClient();
        $params = [
            'merchant_no' => $this->channel['appmchid'],
            'out_order_no' => $ctx->order['trade_no'],
        ];

        try {
            $result = $client->execute('/api/v3/ccss/counter/order/query', $params);
            return ['type' => 'html', 'data' => '交易查询成功！' . json_encode($result)];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '交易查询失败！' . $ex->getMessage()];
        }
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = request()->siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $result = $this->qrcode($ctx, 'ALIPAY', '41');
                $code_url = $result['code'];
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
            $result = $this->qrcode($ctx, 'ALIPAY', '51', ['user_id' => $user_id]);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['prepay_id']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['prepay_id'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($this->channel['appwxmp']) {
            $code_url = request()->siteurl . 'pay/wxjspay/' . $tradeNo . '/';
        } else {
            $code_url = request()->siteurl . 'pay/wxwappay/' . $tradeNo . '/';
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
            $extend = ['sub_appid' => $wxinfo['appid'], 'user_id' => $openid];
            $result = $this->qrcode($ctx, 'WECHAT', $ctx->order['is_applet'] == 1 ? '71' : '51', $extend);
            $pay_info = [
                'appId' => $result['app_id'],
                'timeStamp' => $result['time_stamp'],
                'nonceStr' => $result['nonce_str'],
                'package' => $result['package'],
                'paySign' => $result['pay_sign'],
                'signType' => $result['sign_type']
            ];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => json_encode($pay_info)];
        }

        if (request()->get('d') == 1) {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => json_encode($pay_info), 'redirect_url' => $redirect_url]];
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
            $extend = ['sub_appid' => $wxinfo['appid'], 'user_id' => $openid];
            $result = $this->qrcode($ctx, 'WECHAT', '71', $extend);
            $pay_info = [
                'appId' => $result['app_id'],
                'timeStamp' => $result['time_stamp'],
                'nonceStr' => $result['nonce_str'],
                'package' => $result['package'],
                'paySign' => $result['pay_sign'],
                'signType' => $result['sign_type']
            ];
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $pay_info]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($this->channel['appwxa'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } elseif ($this->channel['appwxmp'] > 0) {
            $code_url = request()->siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return $this->wxpay($ctx);
        }
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $result = $this->qrcode($ctx, 'UQRCODEPAY', '41');
            $code_url = $result['code'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'unionpay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
        }
    }

    public function bankjs(PaymentContext $ctx): array
    {
        try {
            $result = $this->qrcode($ctx, 'UQRCODEPAY', '51', ['user_id' => $ctx->order['sub_openid']]);
            $code_url = $result['redirect_url'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    public function get_unionpay_userid(string $userAuthCode): array
    {
        $params = [
            'mercId' => $this->channel['appmchid'],
            'termNo' => $this->channel['appkey'],
            'authCode' => $userAuthCode,
            'tradeCode' => '030304',
            'appUpIdentifier' => get_unionpay_ua(),
        ];

        $client = $this->createClient();
        try {
            $result = $client->execute_old('/api/v2/saas/query/wx_openid_query', $params);
            return ['code' => 0, 'data' => $result['userId']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //被扫支付
    public function scanpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->createClient();

        $params = [
            'merchant_no' => $this->channel['appmchid'],
            'term_no' => $this->channel['appkey'],
            'out_trade_no' => $tradeNo,
            'auth_code' => $ctx->order['auth_code'],
            'total_amount' => strval($ctx->order['realmoney'] * 100),
            'location_info' => [
                'request_ip' => request()->clientip
            ],
            'subject' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];

        try {
            $result = $client->execute('/api/v3/labs/trans/micropay', $params);
            if ($client->res_code == 'BBS00000') {
                $buyer = $result['acc_resp_fields']['open_id'] ?? $result['acc_resp_fields']['user_id'] ?? '';
                return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['out_trade_no'], 'api_trade_no' => $result['trade_no'], 'buyer' => $buyer, 'money' => strval(round($result['total_amount'] / 100, 2))]];
            } else {
                $retry = 0;
                $success = false;
                while ($retry < 6) {
                    sleep(3);
                    try {
                        $result = $this->orderQuery($client, $tradeNo);
                    } catch (Exception $e) {
                        return ['type' => 'error', 'msg' => '订单查询失败:' . $e->getMessage()];
                    }
                    if ($result['trade_state'] == 'SUCCESS') {
                        $success = true;
                        break;
                    } elseif ($result['trade_state'] != 'DEAL' && $result['trade_state'] != 'CREATE') {
                        return ['type' => 'error', 'msg' => '订单超时或用户取消支付'];
                    }
                    $retry++;
                }
                if ($success) {
                    return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['out_trade_no'], 'api_trade_no' => $result['trade_no'], 'buyer' => $result['user_id2'], 'money' => strval(round($result['total_amount'] / 100, 2))]];
                } else {
                    try {
                        $this->orderRevoked($client, $tradeNo);
                    } catch (Exception $e) {
                    }
                    return ['type' => 'error', 'msg' => '被扫下单失败！订单已超时'];
                }
            }
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '被扫下单失败！' . $e->getMessage()];
        }
    }

    private function orderQuery(LakalaClient $client, string $out_trade_no): array
    {
        $params = [
            'merchant_no' => $this->channel['appmchid'],
            'term_no' => $this->channel['appkey'],
            'out_trade_no' => $out_trade_no,
        ];
        return $client->execute('/api/v3/labs/query/tradequery', $params);
    }

    private function orderRevoked(LakalaClient $client, string $out_trade_no): array
    {
        $params = [
            'merchant_no' => $this->channel['appmchid'],
            'term_no' => $this->channel['appkey'],
            'out_trade_no' => date('YmdHis') . rand(1000, 9999),
            'origin_out_trade_no' => $out_trade_no,
            'location_info' => [
                'request_ip' => request()->clientip
            ],
        ];
        return $client->execute('/api/v3/labs/relation/revoked', $params);
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $json = request()->getContent();

        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'no data'];

        $authorization = request()->header('authorization');
        if (!$authorization) return ['type' => 'html', 'data' => 'no sign'];

        //计算得出通知验证结果
        $client = $this->createClient();
        $verify_result = $client->verifySign($authorization, $json);

        if ($verify_result) {//验证成功
            $out_trade_no = $data['out_trade_no'];
            $api_trade_no = $data['trade_no'];
            $buyer = $data['user_id2'] ?? '';
            $bill_trade_no = $data['acc_trade_no'] ?? '';
            $end_time = $data['trade_time'];

            if ($data['trade_status'] == 'SUCCESS') {
                if ($out_trade_no == $tradeNo) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //收银台异步回调
    public function cashiernotify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $json = request()->getContent();

        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'no data'];

        $authorization = request()->header('authorization');
        if (!$authorization) return ['type' => 'html', 'data' => 'no sign'];

        //计算得出通知验证结果
        $client = $this->createClient();
        $verify_result = $client->verifySign($authorization, $json);

        if ($verify_result) {//验证成功
            $out_trade_no = $data['out_order_no'];
            $api_trade_no = $data['order_trade_info']['trade_no'];
            $buyer = $data['order_trade_info']['user_id2'] ?? '';
            $bill_trade_no = $data['order_trade_info']['acc_trade_no'] ?? '';
            $end_time = $data['order_trade_info']['trade_time'] ?? '';

            if ($data['order_status'] == '2') {
                if ($out_trade_no == $tradeNo) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'fail'];
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
        if ($this->channel['appselect'] == 1) {
            $params = [
                'merchant_no' => $this->channel['appmchid'],
                'out_trade_no' => $order['trade_no'],
            ];

            $result = $client->execute('/api/v3/ccss/counter/order/query', $params);
            $data = $result['order_trade_info_list'][0];
            return [
                'api_trade_no' => $data['trade_no'],
                'status' => $result['order_status'] == '2' ? 1 : 0,
                'money' => $result['total_amount'] / 100,
                'buyer' => $data['user_id2'] ?? '',
                'bill_trade_no' => $data['acc_trade_no'] ?? '',
                'endtime' => $data['trade_time'] ?? '',
            ];
        } else {
            $params = [
                'merchant_no' => $this->channel['appmchid'],
                'term_no' => $this->channel['appkey'],
                'out_trade_no' => $order['trade_no'],
            ];

            $result = $client->execute('/api/v3/labs/query/tradequery', $params);
            return [
                'api_trade_no' => $result['trade_no'],
                'status' => $result['trade_state'] == 'SUCCESS' ? 1 : 0,
                'money' => $result['total_amount'] / 100,
                'buyer' => $result['user_id2'] ?? '',
                'bill_trade_no' => $result['acc_trade_no'] ?? '',
                'endtime' => $result['trade_time'] ?? '',
            ];
        }
    }

    //退款
    public function refund(array $order): array
    {
        $client = $this->createClient();

        $params = [
            'merchant_no' => $this->channel['appmchid'],
            'term_no' => $this->channel['appkey'],
            'out_trade_no' => $order['refund_no'],
            'refund_amount' => strval($order['refundmoney'] * 100),
            'origin_out_trade_no' => $order['trade_no'],
            'origin_trade_no' => $order['api_trade_no'],
            'location_info' => [
                'request_ip' => request()->clientip
            ],
        ];

        try {
            $result = $client->execute('/api/v3/labs/relation/refund', $params);
            return ['code' => 0, 'trade_no' => $result['trade_no'], 'refund_fee' => $result['refund_amount'] / 100];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //投诉异步回调
    public function complainnotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();

        $data = json_decode($json, true);
        if (!$data) return ['type' => 'json', 'data' => ['code' => 'FAIL', 'message' => 'no data']];

        $authorization = request()->header('authorization');
        if (!$authorization) return ['type' => 'json', 'data' => ['code' => 'FAIL', 'message' => 'no sign']];

        //计算得出通知验证结果
        $client = $this->createClient();
        $verify_result = $client->verifySign($authorization, $json);

        if ($verify_result) {//验证成功
            $model = \app\logic\ComplainLogic::getModel($this->channel);
            if ($model) $model->refreshNewInfo($data['complaint_id'], $data['action_type'] ?? null);
            return ['type' => 'json', 'data' => ['code' => 'SUCCESS', 'message' => '成功']];
        } else {
            //验证失败
            return ['type' => 'json', 'data' => ['code' => 'FAIL', 'message' => 'sign fail']];
        }
    }

    //进件异步回调
    public function applynotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();

        $data = json_decode($json, true);
        if (!$data) return ['type' => 'json', 'data' => ['code' => 'FAIL', 'message' => 'no data']];

        $authorization = request()->header('authorization');
        if (!$authorization) return ['type' => 'json', 'data' => ['code' => 'FAIL', 'message' => 'no sign']];

        //计算得出通知验证结果
        $client = $this->createClient();
        $verify_result = $client->verifySign($authorization, $json);

        if ($verify_result) {//验证成功
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->notify($data);
            return ['type' => 'json', 'data' => ['code' => 'SUCCESS', 'message' => '成功']];
        } else {
            //验证失败
            return ['type' => 'json', 'data' => ['code' => 'FAIL', 'message' => 'sign fail']];
        }
    }
}
