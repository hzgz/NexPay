<?php

declare(strict_types=1);

namespace plugins\payment\yizhifubj;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class YizhifubjPlugin extends BasePayment
{

    private function getClient(): PayClient
    {
        $privateKeyPath = getCertFilePath($this->channel['private_key_path'] ?? '');
        $publicKeyPath = $this->payRoot . 'cert/test.cer';
        return new PayClient($this->channel['appid'], $this->channel['partnerid'] ?? '', $this->channel['appkey'], $privateKeyPath, $publicKeyPath);
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
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
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
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //通用创建订单
    private function addOrder(PaymentContext $ctx, string $paymentModeCode, ?string $openid = null, ?string $appid = null)
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (request()->get('r') == '1') {
            $callbackUrl = $siteurl . 'pay/ok/' . $tradeNo . '/';
        } else {
            $callbackUrl = $siteurl . 'pay/return/' . $tradeNo . '/';
        }

        $param = array(
            'merchantId' => $this->channel['appid'],
            'partnerId' => $this->channel['partnerid'] ?? '',
            'orderAmount' => intval(round($ctx->order['realmoney'] * 100)),
            'orderCurrency' => 'CNY',
            'requestId' => $tradeNo,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'callbackUrl' => $callbackUrl,
            'paymentModeCode' => $paymentModeCode,
            'productDetails' => [[
                'name' => $ctx->ordername,
                'quantity' => 1,
                'amount' => intval(round($ctx->order['realmoney'] * 100))
            ]],
            'payer' => ['idType' => 'IDCARD'],
            'clientIp' => request()->clientip,
            'reportSerialNo' => $this->channel['appmchid'] ?? '',
        );
        if ($openid) $param['openId'] = $openid;
        if ($appid) $param['appId'] = $appid;

        return self::lockPayData($tradeNo, function () use ($param, $openid) {
            $result = $this->getClient()->submit('/onlinePay/order', $param);

            if ($result['status'] == 'REDIRECT') {
                if ($openid && !empty($result['jsString'])) {
                    return ['jsapi', $result['jsString']];
                }
                return ['jump', $result['redirectUrl']];
            } elseif ($result['status'] == 'SUCCESS') {
                if (!empty($result['jsString'])) {
                    return ['jsapi', $result['jsString']];
                } elseif (!empty($result['tradeNo'])) {
                    return ['jsapi', $result['tradeNo']];
                } elseif (!empty($result['scanCodeUrl'])) {
                    $code_url = $result['scanCodeUrl'];
                } else {
                    $code_url = 'data:image/png;base64,' . $result['scanCode'];
                }
                return ['qrcode', $code_url];
            } else {
                if (isset($result['error'])) {
                    throw new Exception($result['error'] . $result['cause']);
                } elseif (isset($result['errorMessage'])) {
                    throw new Exception($result['errorMessage']);
                } else {
                    throw new Exception('返回数据解析失败');
                }
            }
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('4', $this->channel['apptype']) && $ctx->isMobile) {
            $paymentModeCode = 'ALIPAY-WAP-P2P';
        } elseif (in_array('1', $this->channel['apptype'])) {
            $paymentModeCode = 'SCANCODE-EQRCODE_PAY';
        } elseif (in_array('2', $this->channel['apptype'])) {
            $paymentModeCode = 'SCANCODE-ALI_PAY-P2P';
        } elseif (in_array('4', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipay/' . $tradeNo . '/?r=1';
            $type = 'qrcode';
        } elseif (in_array('3', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
            $type = 'qrcode';
        } else {
            return ['type' => 'error', 'msg' => '当前支付通道没有开启的支付方式'];
        }
        if (!empty($paymentModeCode)) {
            try {
                list($type, $code_url) = $this->addOrder($ctx, $paymentModeCode);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
        }

        if ($type == 'jump' || $ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $user_type = '';

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
            list($type, $tradeNoResult) = $this->addOrder($ctx, 'ALIPAY-OFFICIAL-P2P', $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $tradeNoResult];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $tradeNoResult, 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype'])) {
            $paymentModeCode = 'SCANCODE-EQRCODE_PAY';
        } elseif (in_array('2', $this->channel['apptype'])) {
            $paymentModeCode = 'SCANCODE-WEIXIN_PAY-P2P';
        } elseif (in_array('3', $this->channel['apptype'])) {
            if ($this->channel['appwxmp'] > 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
            $type = 'qrcode';
        } else {
            return ['type' => 'error', 'msg' => '当前支付通道没有开启的支付方式'];
        }
        if (!empty($paymentModeCode)) {
            try {
                list($type, $code_url) = $this->addOrder($ctx, $paymentModeCode);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        }

        if ($type == 'jump' || $ctx->mdevice === 'wechat') {
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
            list($type, $payinfo) = $this->addOrder($ctx, $ctx->order['is_applet'] == 1 ? 'MINIAPPS-WEIXIN_PAY-P2P' : 'WECHAT-OFFICIAL_PAY-P2P', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败 ' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $payinfo];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $payinfo, 'redirect_url' => $redirect_url]];
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
            list($type, $payinfo) = $this->addOrder($ctx, 'MINIAPPS-WEIXIN_PAY-P2P', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败 ' . $ex->getMessage()]];
        }
        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($payinfo, true)]];
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
        if (in_array('1', $this->channel['apptype'])) {
            $paymentModeCode = 'SCANCODE-EQRCODE_PAY';
        } elseif (in_array('2', $this->channel['apptype'])) {
            $paymentModeCode = 'SCANCODE-UNION_PAY-P2P';
        } elseif (in_array('3', $this->channel['apptype'])) {
            $paymentModeCode = 'BANK_CARD-EXPRESS_DEBIT';
        } else {
            return ['type' => 'error', 'msg' => '当前支付通道没有开启的支付方式'];
        }
        try {
            list($type, $code_url) = $this->addOrder($ctx, $paymentModeCode);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        if ($type == 'jump') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $client = $this->getClient();
        $data = $client->getNotify();
        if (!$data || !$client->verifyNotify($data)) {
            return ['type' => 'html', 'data' => 'Fail'];
        } else {
            if ($data['status'] == 'SUCCESS') {
                $out_trade_no = $data['requestId'];
                $api_trade_no = $data['serialNumber'];
                $end_time = $data['completeDateTime'];
                if ($out_trade_no == $ctx->order['trade_no'] && strval($data['orderAmount']) == strval($ctx->order['realmoney'] * 100)) {
                    ($this->markTrustedCallback($ctx, 'notify', 'yizhifubj-signature'))(function () use ($ctx, $api_trade_no, $end_time) {
                        $this->processNotify($ctx->order, $api_trade_no, null, null, null, $end_time);
                    });
                }
            }

            return ['type' => 'html', 'data' => 'SUCCESS'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $param = [
            'merchantId' => $this->channel['appid'],
            'partnerId' => $this->channel['partnerid'] ?? '',
            'requestId' => $order['trade_no'],
        ];
        $result = $this->getClient()->submit('/onlinePay/query', $param);
        if (isset($result['status']) && $result['status'] != 'ERROR') {
            return [
                'api_trade_no' => $result['serialNumber'],
                'status' => $result['status'] == 'SUCCESS' ? 1 : 0,
                'money' => $result['orderAmount'] / 100,
                'endtime' => $result['completeDateTime'] ?? '',
            ];
        } else {
            if (isset($result['error'])) {
                throw new Exception($result['error'] . $result['cause']);
            } elseif (isset($result['errorMessage'])) {
                throw new Exception($result['errorMessage']);
            } else {
                throw new Exception('返回数据解析失败');
            }
        }
    }

    //退款
    public function refund(array $order): array
    {
        $param = [
            'merchantId' => $this->channel['appid'],
            'requestId' => $order['refund_no'],
            'orderId' => $order['api_trade_no'],
            'amount' => intval(round($order['refundmoney']) * 100),
            'notifyUrl' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
        ];

        try {
            $result = $this->getClient()->submit('/onlinePay/refund', $param);
            if ($result['status'] == 'SUCCESS') {
                return ['code' => 0, 'trade_no' => $result['serialNumber'], 'refund_fee' => $result['amount'] / 100];
            } else {
                if (isset($result['error'])) {
                    throw new Exception($result['error'] . $result['cause']);
                } elseif (isset($result['errorMessage'])) {
                    throw new Exception($result['errorMessage']);
                } else {
                    throw new Exception('返回数据解析失败');
                }
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    public function refundnotify(PaymentContext $ctx): array
    {
        $client = $this->getClient();
        $data = $client->getNotify();
        if (!$data || !$client->verifyNotify($data)) {
            return ['type' => 'html', 'data' => 'Fail'];
        } else {
            $status = ($data['status'] ?? '') == 'SUCCESS' ? 1 : (($data['status'] ?? '') == 'FAIL' ? 2 : 0);
            if ($data['status'] == 'SUCCESS') {
                $out_trade_no = $data['requestId'];
                $api_trade_no = $data['serialNumber'];
            }
            ($this->markTrustedCallback($ctx, 'refundnotify', 'yizhifubj-signature'))(function () use ($data, $status) {
                $this->processRefund(
                    $data['requestId'] ?? '',
                    $status,
                    $status === 2 ? (string)($data['errorMessage'] ?? 'yizhifubj refund failed') : '',
                    $data['serialNumber'] ?? '',
                    isset($data['amount']) ? $data['amount'] / 100 : null
                );
            });

            return ['type' => 'html', 'data' => 'SUCCESS'];
        }
    }

    //转账
    public function transfer(array $bizParam): array
    {
        $param = [
            'merchantId' => $this->channel['appid'],
            'requestId' => $bizParam['out_biz_no'],
            'amount' => strval($bizParam['money'] * 100),
            'currency' => 'CNY',
            'notifyUrl' => config_get('localurl') . 'pay/transfernotify/' . $this->channel['id'] . '/',
            'payee' => [
                'name' => $bizParam['payee_real_name'],
                'bankCardNum' => $bizParam['payee_account'],
            ],
            'remark' => $bizParam['transfer_desc'],
        ];

        try {
            $result = $this->getClient()->submit('/transferDomestic/single/order', $param);
            if ($result['status'] == 'SUCCESS') {
                return ['code' => 0, 'status' => 0, 'orderid' => $bizParam['out_biz_no'], 'paydate' => date('Y-m-d H:i:s')];
            } else {
                if (isset($result['error'])) {
                    throw new Exception($result['error'] . $result['cause']);
                } elseif (isset($result['errorMessage'])) {
                    throw new Exception($result['errorMessage']);
                } else {
                    throw new Exception('返回数据解析失败');
                }
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账查询
    public function transfer_query(array $bizParam): array
    {
        $param = [
            'merchantId' => $this->channel['appid'],
            'requestId' => $bizParam['out_biz_no'],
        ];

        try {
            $result = $this->getClient()->submit('/transferDomestic/single/query', $param);
            if ($result['status'] == 'SUCCESS') {
                return ['code' => 0, 'status' => 1];
            } elseif ($result['status'] == 'FAILED' || $result['status'] == 'CANCEL') {
                return ['code' => 0, 'status' => 2, 'errmsg' => $result['errorMessage']];
            } elseif ($result['status'] == 'INIT' || $result['status'] == 'REMITING') {
                return ['code' => 0, 'status' => 0];
            } else {
                if (isset($result['error'])) {
                    throw new Exception($result['error'] . $result['cause']);
                } elseif (isset($result['errorMessage'])) {
                    throw new Exception($result['errorMessage']);
                } else {
                    throw new Exception('返回数据解析失败');
                }
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //付款凭证
    /*public function transfer_proof(array $bizParam): array
    {
        $out_biz_no = date("YmdHis") . rand(11111, 99999);
        $param = [
            'merchantId' => $this->channel['appid'],
            'requestId' => $out_biz_no,
            'remitRequestId' => $bizParam['out_biz_no'],
        ];

        try {
            $result = $this->getClient()->submit('/transferDomestic/generateTransferVoucher', $param);
            if ($result['status'] == 'SUCCESS') {
                return ['code' => 0, 'url' => $result['downloadUrl']];
            } else {
                if (isset($result['error'])) {
                    throw new Exception($result['error'] . $result['cause']);
                } elseif (isset($result['errorMessage'])) {
                    throw new Exception($result['errorMessage']);
                } else {
                    throw new Exception('返回数据解析失败');
                }
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }*/

    //余额查询
    public function balance_query(array $bizParam): array
    {
        $out_biz_no = date("YmdHis") . rand(11111, 99999);
        $param = [
            'merchantId' => $this->channel['appid'],
            'requestId' => $out_biz_no,
        ];

        try {
            $result = $this->getClient()->submit('/transferDomestic/account/availableTransferBalance', $param);
            if ($result['status'] == 'SUCCESS') {
                return ['code' => 0, 'amount' => $result['availableTransferBalance'] / 100];
            } else {
                if (isset($result['error'])) {
                    throw new Exception($result['error'] . $result['cause']);
                } elseif (isset($result['errorMessage'])) {
                    throw new Exception($result['errorMessage']);
                } else {
                    throw new Exception('返回数据解析失败');
                }
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账异步回调
    public function transfernotify(PaymentContext $ctx): array
    {
        $client = $this->getClient();
        $data = $client->getNotify();
        if (!$data || !$client->verifyNotify($data)) {
            return ['type' => 'html', 'data' => 'Fail'];
        } else {
            $errmsg = null;
            if ($data['status'] == 'SUCCESS') {
                $status = 1;
            } elseif ($data['status'] == 'FAILED' || $data['status'] == 'CANCEL') {
                $status = 2;
                $errmsg = $data['errorMessage'];
            }
            if (isset($status)) {
                ($this->markTrustedCallback($ctx, 'transfernotify', 'yizhifubj-signature'))(function () use ($data, $status, $errmsg) {
                    $this->processTransfer($data['requestId'], $status, $errmsg);
                });
            }

            return ['type' => 'html', 'data' => 'SUCCESS'];
        }
    }
}
