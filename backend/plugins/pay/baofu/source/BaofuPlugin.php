<?php

declare(strict_types=1);

namespace plugins\payment\baofu;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class BaofuPlugin extends BasePayment
{
    private function createClient(): BaofuClient
    {
        return new BaofuClient(
            $this->channel['appid'],
            $this->channel['appurl'],
            $this->channel['appkey'],
            $this->payRoot . 'cert/baofu.cer',
            getCertFilePath($this->channel['pfx_cert_path'] ?? '')
        );
    }

    private function getSubMchId(): string
    {
        $subMchId = $this->channel['appmchid'] ?? '';
        if (strpos($subMchId, '|')) {
            $subMchIds = explode('|', $subMchId);
            $subMchId = $subMchIds[array_rand($subMchIds)];
        }
        return $subMchId;
    }

    //统一下单
    private function addOrder(PaymentContext $ctx, string $pay_code, ?string $sub_openid = null, ?string $sub_appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $subMchId = $this->getSubMchId();

        if ($pay_code == 'WECHAT_JSAPI') {
            $pay_extend = [
                'body' => $ctx->ordername,
                'area_info' => '110101',
                'sub_appid' => $sub_appid,
                'sub_openid' => $sub_openid,
            ];
        } elseif ($pay_code == 'WECHAT_APP') {
            $pay_extend = [
                'body' => $ctx->ordername,
                'area_info' => '110101',
                'sub_appid' => $sub_appid,
            ];
        } elseif ($pay_code == 'WECHAT_MICROPAY') {
            $pay_extend = [
                'body' => $ctx->ordername,
                'area_info' => '110101',
                'auth_code' => $ctx->order['auth_code'],
            ];
        } elseif ($pay_code == 'ALIPAY_NATIVE') {
            $pay_extend = [
                'subject' => $ctx->ordername,
            ];
        } elseif ($pay_code == 'ALIPAY_JSAPI') {
            $pay_extend = [
                'subject' => $ctx->ordername,
                'buyer_id' => $sub_openid,
            ];
        } elseif ($pay_code == 'ALIPAY_MICROPAY') {
            $pay_extend = [
                'subject' => $ctx->ordername,
                'auth_code' => $ctx->order['auth_code'],
            ];
        } elseif ($pay_code == 'QUICK_PASS_NATIVE') {
            $pay_extend = [
                'areaInfo' => '1560001',
            ];
        } elseif ($pay_code == 'QUICK_PASS_NATIVE_JS') {
            $pay_extend = [
                'userId' => $sub_openid,
                'areaInfo' => '1560001',
                'customerIp' => request()->clientip,
                'orderDesc' => $ctx->ordername,
            ];
        }

        $params = [
            'merId' => $this->channel['appid'],
            'terId' => $this->channel['appurl'],
            'outTradeNo' => $tradeNo,
            'txnAmt' => intval(round($ctx->order['realmoney'] * 100)),
            'txnTime' => date('YmdHis'),
            'totalAmt' => intval(round($ctx->order['realmoney'] * 100)),
            'prodType' => 'ORDINARY',
            'payCode' => $pay_code,
            'payExtend' => $pay_extend,
            'subMchId' => $subMchId,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'pageUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'riskInfo' => ['clientIp' => request()->clientip],
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->execute('unified_order', $params);
            if ($result['resultCode'] == 'SUCCESS') {
                return $result['chlRetParam'];
            } elseif(isset($result['errCode'])) {
                throw new Exception('[' . $result['errCode'] . ']' . $result['errMsg']);
            } else {
                throw new Exception('返回数据解析失败');
            }
        });
    }

    //扫码支付
    private function qrcode_order(PaymentContext $ctx, string $pay_code): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $subMchId = $this->getSubMchId();

        $pay_extend = [
            'goods_name' => $ctx->ordername,
            'merchant_name' => '聚合商户',
            'area_info' => '110101',
        ];
        if ($pay_code == 'WECHAT_JSAPI') {
            $pay_extend['wechat_sub_member_id'] = $subMchId;
        } else {
            $pay_extend['alipay_sub_member_id'] = $subMchId;
        }
        $params = [
            'merId' => $this->channel['appid'],
            'terId' => $this->channel['appurl'],
            'outTradeNo' => $tradeNo,
            'txnAmt' => intval(round($ctx->order['realmoney'] * 100)),
            'txnTime' => date('YmdHis'),
            'totalAmt' => intval(round($ctx->order['realmoney'] * 100)),
            'prodType' => 'ORDINARY',
            'cashierFlag' => '0',
            'payCode' => $pay_code,
            'payExtend' => $pay_extend,
            'subMchId' => $subMchId,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'pageUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'riskInfo' => ['clientIp' => request()->clientip],
        ];

        $client = $this->createClient();

        return self::lockPayData($tradeNo, function () use ($client, $params) {
            $result = $client->execute('pre_unified_order', $params);
            if ($result['resultCode'] == 'SUCCESS') {
                return $result['token'];
            } elseif(isset($result['errCode'])) {
                throw new Exception('[' . $result['errCode'] . ']' . $result['errMsg']);
            } else {
                throw new Exception('返回数据解析失败');
            }
        });
    }

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
            } elseif ($ctx->order['typename'] == 'bank') {
                return $this->bankjs($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
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
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = request()->siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $result = $this->addOrder($ctx, 'ALIPAY_NATIVE');
                $code_url = $result['qr_code'];
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

    //支付宝JS支付
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
            $result = $this->addOrder($ctx, 'ALIPAY_JSAPI', $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['trade_no']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['trade_no'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            if ($this->channel['appwxmp'] > 0 && $this->channel['appwxa'] == 0) {
                $code_url = request()->siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = request()->siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
        } else {
            try {
                $code_url = $this->qrcode_order($ctx, 'WECHAT_JSAPI');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
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
            $result = $this->addOrder($ctx, 'WECHAT_JSAPI', $openid, $wxinfo['appid']);
            $pay_info = $result['wc_pay_data'] ?? '';
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
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        try {
            $result = $this->addOrder($ctx, 'WECHAT_JSAPI', $openid, $wxinfo['appid']);
            $pay_info = $result['wc_pay_data'] ?? '';
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
            $code_url = $this->addOrder($ctx, 'QUICK_PASS_NATIVE');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'unionpay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
        }
    }

    //云闪付JS支付
    public function bankjs(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, 'QUICK_PASS_NATIVE_JS', $ctx->order['sub_openid']);
            $code_url = $result['redirectUrl'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    //获取云闪付用户ID
    public function get_unionpay_userid(string $userAuthCode): array
    {
        $param = [
            'merId' => $this->channel['appid'],
            'terId' => $this->channel['appurl'],
            'userAuthCode' => $userAuthCode,
            'appUpIdentifier' => get_unionpay_ua(),
        ];

        $client = $this->createClient();
        try {
            $result = $client->execute('quick_pass_auth', $param);
            if ($result['resultCode'] == 'SUCCESS') {
                return ['code' => 0, 'data' => $result['userId']];
            } elseif(isset($result['errCode'])) {
                return ['code' => -1, 'msg' => '[' . $result['errCode'] . ']' . $result['errMsg']];
            } else {
                return ['code' => -1, 'msg' => '返回数据解析失败'];
            }
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $dataContent = request()->post('dataContent');
        if (!$dataContent) return ['type' => 'html', 'data' => 'NO'];

        $client = $this->createClient();
        $verify_result = $client->verifyNotify($dataContent, request()->post('signStr'));

        if ($verify_result) {
            $params = json_decode($dataContent, true);
            if ($params['txnState'] == 'SUCCESS') {
                if ($params['outTradeNo'] == $ctx->order['trade_no']) {
                    if (strpos($params['payCode'], 'ALIPAY_') !== false) {
                        $buyer = $params['chlRetParam']['buyer_id'] ?? null;
                        $bill_trade_no = $params['chlRetParam']['trade_no'] ?? null;
                    } elseif (strpos($params['payCode'], 'WECHAT_') !== false) {
                        $buyer = null;
                        $bill_trade_no = $params['chlRetParam']['transaction_id'] ?? null;
                    } elseif (strpos($params['payCode'], 'QUICK_PASS_') !== false) {
                        $buyer = null;
                        $bill_trade_no = $params['chlRetParam']['voucherNum'] ?? null;
                    }
                    $bill_mch_trade_no = $params['reqChlNo'] ?? null;
                    $end_time = $params['finishTime'] ?? null;
                    $this->processNotify($ctx->order, $params['tradeNo'], $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'OK'];
        } else {
            return ['type' => 'html', 'data' => 'FAIL'];
        }
    }

    //分账
    private function share(BaofuClient $client, string $originTradeNo, PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $param = [
            'merId' => $this->channel['appid'],
            'terId' => $this->channel['appurl'],
            'originTradeNo' => $originTradeNo,
            'txnTime' => date('YmdHis'),
            'outTradeNo' => $tradeNo,
            'sharingDetails' => [
                [
                    'sharingMerId' => $this->channel['share_merid'],
                    'sharingAmt' => intval(round($ctx->order['realmoney'] * 100)),
                ]
            ],
        ];
        usleep(500000);
        try {
            $result = $client->execute('share_after_pay', $param);
            if ($result['resultCode'] == 'SUCCESS') {
                return ['code' => 0];
            } else {
                return ['code' => -1, 'msg' => '[' . $result['errCode'] . ']' . $result['errMsg']];
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
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
        $param = [
            'merId' => $this->channel['appid'],
            'terId' => $this->channel['appurl'],
            'outTradeNo' => $order['trade_no'],
        ];
        $result = $client->execute('order_query', $param);
        if (strpos($result['payCode'], 'ALIPAY_') !== false) {
            $buyer = $result['chlRetParam']['buyer_id'] ?? null;
            $bill_trade_no = $result['chlRetParam']['trade_no'] ?? null;
        } elseif (strpos($result['payCode'], 'WECHAT_') !== false) {
            $buyer = null;
            $bill_trade_no = $result['chlRetParam']['transaction_id'] ?? null;
        } elseif (strpos($result['payCode'], 'QUICK_PASS_') !== false) {
            $buyer = null;
            $bill_trade_no = $result['chlRetParam']['voucherNum'] ?? null;
        }
        return [
            'api_trade_no' => $result['tradeNo'],
            'status' => $result['txnState'] == 'SUCCESS' ? 1 : 0,
            'money' => $result['succAmt'] ?? null,
            'buyer' => $buyer ?? null,
            'bill_trade_no' => $bill_trade_no ?? null,
            'bill_mch_trade_no' => $result['reqChlNo'] ?? null,
            'endtime' => $result['finishTime'] ?? null,
        ];
    }

    //退款
    public function refund($order): array
    {
        $client = $this->createClient();

        $param = [
            'merId' => $this->channel['appid'],
            'terId' => $this->channel['appurl'],
            'originTradeNo' => $order['api_trade_no'],
            'outTradeNo' => $order['refund_no'],
            'refundAmt' => intval(round($order['refundmoney'] * 100)),
            'totalAmt' => intval(round($order['refundmoney'] * 100)),
            'txnTime' => date('YmdHis'),
            'refundReason' => '申请退款',
        ];

        try {
            $result = $client->execute('order_refund', $param);
            if ($result['resultCode'] == 'SUCCESS') {
                return ['code' => 0, 'trade_no' => $result['tradeNo'], 'refund_fee' => $result['refundAmt'] / 100];
            } else {
                return ['code' => -1, 'msg' => '[' . $result['errCode'] . ']' . $result['errMsg']];
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //进件通知
    public function applynotify(PaymentContext $ctx): array
    {
        $post = request()->post();
        if (!isset($post['data_content'])) {
            return ['type' => 'html', 'data' => 'NO'];
        }

        $model = \app\logic\ApplymentLogic::getModel2($this->channel);
        if ($model) $model->notify($post['data_content']);

        return ['type' => 'html', 'data' => 'OK'];
    }

    //转账
    public function transfer(array $bizParam): array
    {
        try {
            $bank_info = getBankCardInfo($bizParam['payee_account']);
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }

        $client = $this->createClient();

        $params = [
            'trans_content' => ['trans_reqDatas' => [['trans_reqData' => [[
                'trans_no' => $bizParam['out_biz_no'],
                'trans_money' => $bizParam['money'],
                'to_acc_name' => $bizParam['payee_real_name'],
                'to_acc_no' => $bizParam['payee_account'],
                'to_bank_name' => $bank_info['bank_name'],
            ]]]]],
        ];

        try {
            $result = $client->transfer('BF0040001', $params);
            if (empty($result) || empty($result[0]['trans_reqData'])) throw new Exception('转账结果数据不存在');
            $info = $result[0]['trans_reqData'][0];
            return ['code' => 0, 'status' => 0, 'orderid' => $info['trans_batchid'], 'paydate' => date('Y-m-d H:i:s')];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账查询
    public function transfer_query(array $bizParam): array
    {
        $client = $this->createClient();

        $params = [
            'trans_content' => ['trans_reqDatas' => [['trans_reqData' => [[
                'trans_no' => $bizParam['out_biz_no'],
            ]]]]],
        ];
        try {
            $result = $client->transfer('BF0040002', $params);
            if (empty($result) || empty($result[0]['trans_reqData'])) throw new Exception('转账结果数据不存在');
            $info = $result[0]['trans_reqData'][0];
            $errmsg = '';
            if ($info['state'] == '1') {
                $status = 1;
            } elseif ($info['trade_status'] == '-1' || $info['trade_status'] == '2') {
                $status = 2;
                $errmsg = $info['trans_remark'] ?? '';
            } else {
                $status = 0;
            }
            return ['code' => 0, 'status' => $status, 'errmsg' => $errmsg];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //电子回单
    public function transfer_proof(array $bizParam): array
    {
        $client = $this->createClient();

        $params = [
            'memberTransId' => $bizParam['out_biz_no'],
            'fileType' => '2',
            'transferDate' => substr($bizParam['out_biz_no'], 0, 4) . '-' . substr($bizParam['out_biz_no'], 4, 2) . '-' . substr($bizParam['out_biz_no'], 6, 2),
            'orderType' => '0',
            'version' => 'V1.1',
        ];
        try {
            $result = $client->submit('T-1001-003-03', $params);
            if ($result['state'] == '0000') {
                return ['code' => 0, 'msg' => '电子回单生成成功！', 'download_url' => $result['urlDownload']];
            } else {
                return ['code' => -1, 'msg' => '电子回单生成失败！[' . $result['state'] . ']' . $result['message']];
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //余额查询
    public function balance_query(array $bizParam): array
    {
        $client = $this->createClient();

        $params = [
            'version' => '4.0.0',
            'accountType' => 'BASE_ACCOUNT',
        ];
        try {
            $result = $client->submit('T-1001-006-03', $params);
            return ['code' => 0, 'amount' => $result['balance']];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }
}
