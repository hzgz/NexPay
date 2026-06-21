<?php

declare(strict_types=1);

namespace plugins\payment\huifu;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class HuifuPlugin extends BasePayment
{
    private function createClient(): HuifuClient
    {
        return new HuifuClient([
            'sys_id' => $this->channel['appid'],
            'product_id' => $this->channel['appurl'],
            'merchant_private_key' => $this->channel['appsecret'],
            'huifu_public_key' => $this->channel['appkey'],
        ]);
    }

    private function getHuifuId(): string
    {
        return $this->channel['appmchid'] ? $this->channel['appmchid'] : $this->channel['appid'];
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('4', $this->channel['apptype']) && !in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ((in_array('1', $this->channel['apptype']) || in_array('2', $this->channel['apptype'])) && $ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            if (in_array('3', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
            } elseif (in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/quickpay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/unionpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'ecny') {
            return ['type' => 'jump', 'url' => '/pay/ecny/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'douyinpay') {
            return ['type' => 'jump', 'url' => '/pay/douyinpay/' . $tradeNo . '/'];
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
                return $this->unionpayjs($ctx);
            }
        } elseif ($ctx->method == 'scan') {
            return $this->scanpay($ctx);
        } elseif ($ctx->method == 'applet') {
            return $this->wxplugin($ctx);
        } elseif ($ctx->method == 'app') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->aliapppay($ctx);
            } else {
                return $this->wxapppay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('4', $this->channel['apptype']) && !in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ((in_array('1', $this->channel['apptype']) || in_array('2', $this->channel['apptype'])) && $ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            if (in_array('3', $this->channel['apptype'])) {
                return $this->bank($ctx);
            } elseif (in_array('2', $this->channel['apptype'])) {
                return $this->quickpay($ctx);
            } else {
                return $this->unionpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'ecny') {
            return $this->ecny($ctx);
        } elseif ($ctx->order['typename'] == 'douyinpay') {
            return $this->douyinpay($ctx);
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //统一下单
    private function addOrder(PaymentContext $ctx, string $trade_type, ?string $sub_appid = null, ?string $sub_openid = null)
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        $client = $this->createClient();

        $param = [
            'req_date' => substr($tradeNo, 0, 8),
            'req_seq_id' => $tradeNo,
            'huifu_id' => $this->getHuifuId(),
            'trade_type' => $trade_type,
            'trans_amt' => $ctx->order['realmoney'],
            'goods_desc' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'risk_check_data' => json_encode(['ip_addr' => $clientip]),
        ];
        if ($trade_type == 'T_JSAPI' || $trade_type == 'T_MINIAPP') {
            $param['wx_data'] = json_encode(['sub_appid' => $sub_appid, 'sub_openid' => $sub_openid, 'device_info' => '4', 'spbill_create_ip' => $clientip]);
        } elseif ($trade_type == 'A_JSAPI') {
            $param['alipay_data'] = json_encode(['subject' => $ctx->ordername, 'buyer_id' => $sub_openid]);
        } elseif ($trade_type == 'A_NATIVE') {
            $param['alipay_data'] = json_encode(['subject' => $ctx->ordername]);
        } elseif ($trade_type == 'T_NATIVE') {
            $param['wx_data'] = json_encode(['product_id' => '01001', 'spbill_create_ip' => $clientip]);
        } elseif ($trade_type == 'U_JSAPI') {
            $param['unionpay_data'] = json_encode(['qr_code' => $siteurl, 'customer_ip' => $clientip, 'user_id' => $sub_openid]);
        } elseif ($trade_type == 'Y_H5' || $trade_type == 'Y_APP') {
            $param['dy_data'] = json_encode(['order_ip' => $clientip, 'h5_info' => [
                'type' => 'Wap',
                'app_name' => config_get('sitename'),
                'app_url' => $siteurl,
            ]]);
        }
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($param, $ctx);
        }

        return self::lockPayData($tradeNo, function () use ($client, $param) {
            $result = $client->requestApi('/v3/trade/payment/jspay', $param);
            if (isset($result['resp_code']) && $result['resp_code'] == '00000100') {
                return $result['pay_info'] ?? $result['qr_code'] ?? '';
            } elseif (isset($result['resp_desc'])) {
                throw new Exception($result['resp_desc'] . (isset($result['bank_message']) ? ' ' . $result['bank_message'] : ''));
            } else {
                throw new Exception('返回数据解析失败');
            }
        });
    }

    private function handleProfits(array &$param, PaymentContext $ctx): void
    {
        $psreceiver = \app\logic\ProfitSharingLogic::getReceiver($ctx->order['profits']);
        if ($psreceiver) {
            if ($psreceiver['mode'] == 1) {
                $param['delay_acct_flag'] = 'Y';
            } else {
                $acct_infos = [];
                foreach ($psreceiver['info'] as $receiver) {
                    $psmoney = round($ctx->order['realmoney'] * $receiver['rate'] / 100, 2);
                    $acct_infos[] = [
                        'huifu_id' => $receiver['account'],
                        'div_amt' => sprintf('%.2f', $psmoney),
                    ];
                }
                $param['acct_split_bunch'] = json_encode(['acct_infos' => $acct_infos]);
            }
        }
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('1', $this->channel['apptype'])) {
            try {
                $code_url = $this->addOrder($ctx, 'A_NATIVE');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('2', $this->channel['apptype'])) {
            try {
                $code_url = $this->hostingOrder($ctx, 'A_JSAPI', 'M');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('3', $this->channel['apptype'])) {
            try {
                $code_url = $this->aliapphosting($ctx);
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
            }
            if ($ctx->mdevice === 'alipay') {
                return ['type' => 'jump', 'url' => $code_url];
            } elseif ($ctx->isMobile) {
                return ['type' => 'page', 'page' => 'alipay_h5', 'data' => ['code_url' => $code_url, 'redirect_url' => 'data.backurl']];
            }
        } elseif (in_array('4', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            return ['type' => 'error', 'msg' => '当前支付通道没有开启的支付方式'];
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
            $payinfo = $this->addOrder($ctx, 'A_JSAPI', null, $user_id);
            $result = json_decode($payinfo, true);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['tradeNO']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $result['tradeNO'], 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('3', $this->channel['apptype']) && !in_array('2', $this->channel['apptype']) || in_array('1', $this->channel['apptype']) && $this->channel['appwxa'] > 0 && !$this->channel['appwxmp']) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } else {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
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

        if ((in_array('2', $this->channel['apptype']) || !$this->channel['appwxmp']) && $ctx->method != 'jsapi') {
            try {
                $jump_url = $this->hostingOrder($ctx, 'T_JSAPI', 'M');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'jump', 'url' => $jump_url];
        }

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
            $jsApiParameters = $this->addOrder($ctx, $ctx->order['is_applet'] == 1 ? 'T_MINIAPP' : 'T_JSAPI', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $jsApiParameters];
        }

        if (request()->get('d') == 1) {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $jsApiParameters, 'redirect_url' => $redirect_url]];
    }

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $code = request()->get('code', '', 'trim');
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
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        //②、统一下单
        try {
            $jsApiParameters = $this->addOrder($ctx, 'T_MINIAPP', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($jsApiParameters, true)]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('3', $this->channel['apptype'])) {
            try {
                $result = $this->wxapphosting($ctx);
                $code_url = $result['scheme_code'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } elseif (in_array('1', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } else {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        }
    }

    //微信托管小程序下单
    private function wxapphosting(PaymentContext $ctx, string $need_scheme = 'Y', bool $return_type = false)
    {
        $tradeNo = $ctx->order['trade_no'];

        $client = $this->createClient();

        $miniapp_data = ['need_scheme' => $need_scheme];
        if (!empty($this->channel['seq_id'])) {
            $miniapp_data['seq_id'] = $this->channel['seq_id'];
        }
        $param = [
            'pre_order_type' => '3',
            'req_date' => substr($tradeNo, 0, 8),
            'req_seq_id' => $tradeNo,
            'huifu_id' => $this->getHuifuId(),
            'trans_amt' => $ctx->order['realmoney'],
            'goods_desc' => $ctx->ordername,
            'miniapp_data' => json_encode($miniapp_data),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($param, $ctx);
        }

        return self::lockPayData($tradeNo, function () use ($client, $param, $return_type, $tradeNo) {
            $result = $client->requestApi('/v2/trade/hosting/payment/preorder', $param);

            if (isset($result['resp_code']) && $result['resp_code'] == '00000000') {
                $this->updateOrderCombine($tradeNo);
                return $return_type ? $result['pre_order_id'] : json_decode($result['miniapp_data'], true);
            } elseif (isset($result['resp_desc'])) {
                throw new Exception($result['resp_desc'] . (isset($result['bank_message']) ? ' ' . $result['bank_message'] : ''));
            } else {
                throw new Exception('返回数据解析失败');
            }
        });
    }

    //支付宝托管小程序下单
    private function aliapphosting(PaymentContext $ctx): string
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        $client = $this->createClient();

        $param = [
            'pre_order_type' => '2',
            'req_date' => substr($tradeNo, 0, 8),
            'req_seq_id' => $tradeNo,
            'huifu_id' => $this->getHuifuId(),
            'trans_amt' => $ctx->order['realmoney'],
            'goods_desc' => $ctx->ordername,
            'app_data' => json_encode(['app_schema' => $siteurl . 'pay/return/' . $tradeNo . '/']),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($param, $ctx);
        }

        return self::lockPayData($tradeNo, function () use ($client, $param, $tradeNo) {
            $result = $client->requestApi('/v2/trade/hosting/payment/preorder', $param);

            if (isset($result['resp_code']) && $result['resp_code'] == '00000000') {
                $this->updateOrderCombine($tradeNo);
                return $result['jump_url'];
            } elseif (isset($result['resp_desc'])) {
                throw new Exception($result['resp_desc'] . (isset($result['bank_message']) ? ' ' . $result['bank_message'] : ''));
            } else {
                throw new Exception('返回数据解析失败');
            }
        });
    }

    //H5、PC预下单
    private function hostingOrder(PaymentContext $ctx, string $trans_type, string $request_type): string
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        $client = $this->createClient();

        $param = [
            'req_date' => substr($tradeNo, 0, 8),
            'req_seq_id' => $tradeNo,
            'huifu_id' => $this->getHuifuId(),
            'trans_amt' => $ctx->order['realmoney'],
            'goods_desc' => $ctx->ordername,
            'pre_order_type' => '1',
            'hosting_data' => json_encode(['project_title' => config_get('sitename'), 'project_id' => $this->channel['project_id'], 'callback_url' => $siteurl . 'pay/return/' . $tradeNo . '/', 'request_type' => $request_type]),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'trans_type' => $trans_type
        ];
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($param, $ctx);
        }

        return self::lockPayData($tradeNo, function () use ($client, $param, $tradeNo) {
            $result = $client->requestApi('/v2/trade/hosting/payment/preorder', $param);

            if (isset($result['resp_code']) && $result['resp_code'] == '00000000') {
                $this->updateOrderCombine($tradeNo);
                return $result['jump_url'];
            } elseif (isset($result['resp_desc'])) {
                throw new Exception($result['resp_desc'] . (isset($result['bank_message']) ? ' ' . $result['bank_message'] : ''));
            } else {
                throw new Exception('返回数据解析失败');
            }
        });
    }

    //微信小程序插件支付
    public function wxplugin(PaymentContext $ctx): array
    {
        try {
            $pre_order_id = $this->wxapphosting($ctx, 'N', true);
            $payinfo = ['appId' => 'wx11361ccf7f47b948', 'pre_order_id' => $pre_order_id];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxplugin', 'data' => $payinfo];
    }

    //支付宝APP支付
    public function aliapppay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->aliapphosting($ctx);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'scheme', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    //微信APP支付
    public function wxapppay(PaymentContext $ctx): array
    {
        try {
            $result = $this->wxapphosting($ctx, 'N');
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => '', 'miniProgramId' => $result['gh_id'], 'path' => $result['path']]];
    }

    //云闪付扫码支付
    public function unionpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'U_NATIVE');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //云闪付JS支付
    public function unionpayjs(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'U_JSAPI', null, $ctx->order['sub_openid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    //获取云闪付用户标识
    public function get_unionpay_userid(string $userAuthCode): array
    {
        $client = $this->createClient();

        $params = [
            'req_seq_id' => date('YmdHis') . rand(100000, 999999),
            'req_date' => date('Ymd'),
            'huifu_id' => $this->getHuifuId(),
            'auth_code' => $userAuthCode,
            'app_up_identifier' => get_unionpay_ua(),
        ];

        try {
            $result = $client->requestApi('/v2/trade/payment/usermark2/query', $params);
            return ['code' => 0, 'data' => $result['user_id']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //快捷支付
    public function quickpay(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        if ($ctx->isMobile) {
            $request_type = 'M';
            $gw_chnnl_tp = '02';
            $device_type = '1';
        } else {
            $request_type = 'P';
            $gw_chnnl_tp = '01';
            $device_type = '4';
        }

        $client = $this->createClient();

        $param = [
            'req_seq_id' => $tradeNo,
            'req_date' => substr($tradeNo, 0, 8),
            'huifu_id' => $this->getHuifuId(),
            'trans_amt' => $ctx->order['realmoney'],
            'goods_desc' => $ctx->ordername,
            'request_type' => $request_type,
            'extend_pay_data' => json_encode(['goods_short_name' => $ctx->order['name'], 'gw_chnnl_tp' => $gw_chnnl_tp, 'biz_tp' => '100099']),
            'terminal_device_data' => json_encode(['device_type' => $device_type, 'device_ip' => $clientip]),
            'risk_check_data' => json_encode(['ip_addr' => $clientip]),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'front_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        try {
            $jump_url = self::lockPayData($tradeNo, function () use ($client, $param) {
                $result = $client->requestApi('/v2/trade/onlinepayment/quickpay/frontpay', $param);

                if (isset($result['resp_code']) && ($result['resp_code'] == '00000000' || $result['resp_code'] == '00000100')) {
                    return $result['form_url'];
                } elseif (isset($result['resp_desc'])) {
                    throw new Exception($result['resp_desc'] . (isset($result['bank_message']) ? ' ' . $result['bank_message'] : ''));
                } else {
                    throw new Exception('返回数据解析失败');
                }
            });
            return ['type' => 'jump', 'url' => $jump_url];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '快捷支付下单失败！' . $ex->getMessage()];
        }
    }

    //网银支付
    public function bank(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        if ($ctx->isMobile) {
            $gw_chnnl_tp = '02';
            $device_type = '1';
        } else {
            $gw_chnnl_tp = '01';
            $device_type = '4';
        }

        $client = $this->createClient();

        $param = [
            'req_seq_id' => $tradeNo,
            'req_date' => substr($tradeNo, 0, 8),
            'huifu_id' => $this->getHuifuId(),
            'trans_amt' => $ctx->order['realmoney'],
            'goods_desc' => $ctx->ordername,
            'extend_pay_data' => json_encode(['goods_short_name' => $ctx->order['name'], 'gw_chnnl_tp' => $gw_chnnl_tp, 'biz_tp' => '100099']),
            'terminal_device_data' => json_encode(['device_type' => $device_type, 'device_ip' => $clientip]),
            'risk_check_data' => json_encode(['ip_addr' => $clientip]),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'front_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        try {
            $jump_url = self::lockPayData($tradeNo, function () use ($client, $param) {
                $result = $client->requestApi('/v2/trade/onlinepayment/banking/frontpay', $param);

                if (isset($result['resp_code']) && ($result['resp_code'] == '00000000' || $result['resp_code'] == '00000100')) {
                    return $result['form_url'];
                } elseif (isset($result['resp_desc'])) {
                    throw new Exception($result['resp_desc'] . (isset($result['bank_message']) ? ' ' . $result['bank_message'] : ''));
                } else {
                    throw new Exception('返回数据解析失败');
                }
            });
            return ['type' => 'jump', 'url' => $jump_url];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '网银支付下单失败！' . $ex->getMessage()];
        }
    }

    //数字人民币支付
    public function ecny(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'D_NATIVE');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '数字人民币下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //抖音支付
    public function douyinpay(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        try {
            $code_url = $this->addOrder($ctx, 'Y_H5');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '抖音支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->isMobile) {
            $redirect_url = $siteurl . 'pay/return/' . $tradeNo . '/';
            $url = $code_url . '&return_url=' . urlencode($redirect_url);
            return ['type' => 'jump', 'url' => $url];
        } else {
            $code_url = $siteurl . 'pay/douyinpay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'douyinpay_qrcode', 'url' => $code_url];
        }
    }

    //被扫支付
    public function scanpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        $client = $this->createClient();

        $params = [
            'req_seq_id' => $tradeNo,
            'req_date' => substr($tradeNo, 0, 8),
            'huifu_id' => $this->getHuifuId(),
            'trans_amt' => $ctx->order['realmoney'],
            'goods_desc' => $ctx->ordername,
            'auth_code' => $ctx->order['auth_code'],
            'risk_check_data' => json_encode(['ip_addr' => $clientip]),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($ctx->order['typename'] == 'wxpay') {
            $params['wx_data'] = json_encode(['spbill_create_ip' => $clientip]);
        }
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx);
        }

        try {
            $result = $client->requestApi('/v3/trade/payment/micropay', $params);
            if (isset($result['resp_code']) && $result['resp_code'] == '00000000') {
                // 支付成功
            } elseif (isset($result['resp_desc'])) {
                throw new Exception($result['resp_desc'] . (isset($result['bank_message']) ? ' ' . $result['bank_message'] : ''));
            } else {
                throw new Exception('返回数据解析失败');
            }
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '被扫下单失败！' . $e->getMessage()];
        }

        if ($result['trans_stat'] == 'S') {
            $buyer = null;
            if (isset($result['alipay_response'])) {
                $buyer = json_decode($result['alipay_response'], true)['buyer_id'] ?? '';
            } elseif (isset($result['wx_response'])) {
                $buyer = json_decode($result['wx_response'], true)['sub_openid'] ?? '';
            }
            return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['req_seq_id'], 'api_trade_no' => $result['hf_seq_id'], 'buyer' => $buyer, 'money' => $result['trans_amt']]];
        } else {
            $huifu_seq_id = $result['hf_seq_id'];
            $retry = 0;
            $success = false;
            while ($retry < 6) {
                sleep(3);
                try {
                    $result = $this->orderQuery($client, $huifu_seq_id);
                } catch (Exception $e) {
                    return ['type' => 'error', 'msg' => '订单查询失败:' . $e->getMessage()];
                }
                if ($result['trans_stat'] == 'S') {
                    $success = true;
                    break;
                } elseif ($result['tranSts'] != 'P') {
                    return ['type' => 'error', 'msg' => '订单超时或用户取消支付'];
                }
                $retry++;
            }
            if ($success) {
                $buyer = null;
                if (isset($result['alipay_response'])) {
                    $buyer = json_decode($result['alipay_response'], true)['buyer_id'] ?? '';
                } elseif (isset($result['wx_response'])) {
                    $buyer = json_decode($result['wx_response'], true)['sub_openid'] ?? '';
                }
                return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['org_req_seq_id'], 'api_trade_no' => $result['org_hf_seq_id'], 'buyer' => $buyer, 'money' => $result['trans_amt']]];
            } else {
                try {
                    $this->orderClose($client, $tradeNo);
                } catch (Exception $e) {
                }
                return ['type' => 'error', 'msg' => '被扫下单失败！订单已超时'];
            }
        }
    }

    private function orderQuery(HuifuClient $client, string $hf_seq_id): array
    {
        $params = [
            'huifu_id' => $this->getHuifuId(),
            'org_hf_seq_id' => $hf_seq_id
        ];
        return $client->requestApi('/v3/trade/payment/scanpay/query', $params);
    }

    private function orderClose(HuifuClient $client, string $trade_no): array
    {
        $params = [
            'req_date' => date("Ymd"),
            'req_seq_id' => date("YmdHis") . rand(1000, 9999),
            'huifu_id' => $this->getHuifuId(),
            'org_req_date' => substr($trade_no, 0, 8),
            'org_req_seq_id' => $trade_no
        ];
        return $client->requestApi('/v2/trade/payment/scanpay/close', $params);
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $client = $this->createClient();

        $resp_data = request()->post('resp_data', '');
        $data = json_decode($resp_data, true);
        if (!$data) return ['type' => 'html', 'data' => 'no data'];

        if ($client->checkNotifySign($resp_data, request()->post('sign'))) {
            if ($data['trans_stat'] == 'S') {
                if ($data['req_seq_id'] == $tradeNo) {
                    $api_trade_no = $data['hf_seq_id'];
                    $bill_trade_no = $data['out_trans_id'] ?? '';
                    $bill_mch_trade_no = $data['party_order_id'] ?? '';
                    if($ctx->order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
                    $buyer = null;
                    if (isset($data['alipay_response'])) {
                        $buyer = $data['alipay_response']['buyer_id'] ?? '';
                    } elseif (isset($data['wx_response'])) {
                        $buyer = $data['wx_response']['sub_openid'] ?? '';
                    }
                    $end_time = $data['end_time'];
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
                return ['type' => 'html', 'data' => 'RECV_ORD_ID_' . $tradeNo];
            }
            return ['type' => 'html', 'data' => 'resp_code fail'];
        } else {
            return ['type' => 'html', 'data' => 'sign fail'];
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
        $result = $client->requestApi('/v3/trade/payment/scanpay/query', [
            'huifu_id' => $this->getHuifuId(),
            'org_req_date' => substr($order['trade_no'], 0, 8),
            'org_req_seq_id' => $order['trade_no']
        ]);
        $bill_trade_no = $result['out_trans_id'] ?? '';
        if ($order['type'] == 1 && substr($bill_trade_no, 0, 4) != date('Y') && substr($bill_trade_no, 2, 4) == date('Y')) $bill_trade_no = substr($bill_trade_no, 2);
        $buyer = null;
        if (isset($result['alipay_response'])) {
            $buyer = json_decode($result['alipay_response'], true)['buyer_id'] ?? '';
        } elseif (isset($result['wx_response'])) {
            $buyer = json_decode($result['wx_response'], true)['sub_openid'] ?? '';
        }
        return [
            'api_trade_no' => $result['org_hf_seq_id'],
            'status' => $result['trans_stat'] == 'S' ? 1 : 0,
            'money' => $result['trans_amt'],
            'buyer' => $buyer,
            'bill_trade_no' => $bill_trade_no,
            'bill_mch_trade_no' => $result['party_order_id'] ?? '',
            'endtime' => $result['end_time'] ?? '',
        ];
    }

    //退款
    public function refund($order): array
    {
        $client = $this->createClient();

        $param = [
            'req_date' => date("Ymd"),
            'req_seq_id' => $order['refund_no'],
            'huifu_id' => $this->getHuifuId(),
            'ord_amt' => $order['refundmoney'],
            'org_req_date' => substr($order['trade_no'], 0, 8),
            'org_req_seq_id' => $order['trade_no']
        ];
        if ($order['refundmoney'] < $order['realmoney'] && $order['profits'] > 0) {
            $psorder = \app\logic\ProfitSharingLogic::getOrder($order['trade_no']);
            if ($psorder && $psorder['rdata'] && ($psorder['status'] == 1 || $psorder['status'] == 2)) {
                $acct_infos = [];
                $leftmoney = (float)$order['refundmoney'];
                foreach ($psorder['rdata'] as $receiver) {
                    $money = $receiver['money'] > $leftmoney ? $leftmoney : $receiver['money'];
                    $acct_infos[] = [
                        'huifu_id' => $receiver['account'],
                        'div_amt' => sprintf('%.2f', $money),
                    ];
                    $leftmoney = round($leftmoney - $money, 2);
                    if ($leftmoney <= 0) break;
                }
                $param['acct_split_bunch'] = json_encode(['acct_infos' => $acct_infos]);
            }
        }

        try {
            $result = $client->requestApi('/v3/trade/payment/scanpay/refund', $param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        if ($result['resp_code'] == '00000000' || $result['resp_code'] == '00000100') {
            return ['code' => 0, 'trade_no' => $result['hf_seq_id'], 'refund_fee' => $result['ord_amt']];
        } else {
            return ['code' => -1, 'msg' => $result['resp_desc']];
        }
    }

    //托管支付退款
    public function refund_combine($order): array
    {
        $client = $this->createClient();

        $param = [
            'req_date' => date("Ymd"),
            'req_seq_id' => $order['refund_no'],
            'huifu_id' => $this->getHuifuId(),
            'ord_amt' => $order['refundmoney'],
            'org_req_date' => substr($order['trade_no'], 0, 8),
            'org_req_seq_id' => $order['trade_no']
        ];
        try {
            $result = $client->requestApi('/v2/trade/hosting/payment/htRefund', $param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        if ($result['resp_code'] == '00000000' || $result['resp_code'] == '00000100') {
            return ['code' => 0, 'trade_no' => $result['hf_seq_id'], 'refund_fee' => $result['ord_amt']];
        } else {
            return ['code' => -1, 'msg' => $result['resp_desc']];
        }
    }

    //关闭订单
    public function close($order): array
    {
        $client = $this->createClient();

        $param = [
            'req_date' => date("Ymd"),
            'req_seq_id' => date("YmdHis") . rand(1000, 9999),
            'huifu_id' => $this->getHuifuId(),
            'org_req_date' => substr($order['trade_no'], 0, 8),
            'org_req_seq_id' => $order['trade_no']
        ];
        try {
            $result = $client->requestApi('/v2/trade/payment/scanpay/close', $param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        if ($result['resp_code'] == '00000000' || $result['resp_code'] == '00000100') {
            return ['code' => 0];
        } else {
            return ['code' => -1, 'msg' => $result['resp_desc']];
        }
    }

    //托管支付关闭订单
    public function close_combine($order): array
    {
        $client = $this->createClient();

        $param = [
            'req_date' => date("Ymd"),
            'req_seq_id' => $order['refund_no'],
            'huifu_id' => $this->getHuifuId(),
            'org_req_date' => substr($order['trade_no'], 0, 8),
            'org_req_seq_id' => $order['trade_no']
        ];
        try {
            $result = $client->requestApi('/v2/trade/hosting/payment/close', $param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        if ($result['resp_code'] == '00000000' || $result['resp_code'] == '00000100') {
            return ['code' => 0];
        } else {
            return ['code' => -1, 'msg' => $result['resp_desc']];
        }
    }
}
