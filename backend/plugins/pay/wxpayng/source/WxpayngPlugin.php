<?php

declare(strict_types=1);

namespace plugins\payment\wxpayng;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class WxpayngPlugin extends BasePayment
{
    private array $wechatpayConfig;

    public function __construct(array $channel)
    {
        parent::__construct($channel);
        $this->wechatpayConfig = $this->getConfig();
    }

    private function getConfig(): array
    {
        return [
            //应用ID
            'appid' => $this->channel['appid'],
            //商户号
            'mchid' => $this->channel['appmchid'],
            //APIv3密钥
            'apikey' => $this->channel['appsecret'],
            //「商户API私钥」文件路径
            'merchantPrivateKeyFilePath' => getCertFilePath($this->channel['merchant_key_path'] ?? ''),
            //「商户API证书」的「证书序列号」
            'merchantCertificateSerial' => $this->channel['appkey'],
            //「微信支付公钥」文件路径
            'platformPublicKeyFilePath' => getCertFilePath($this->channel['platform_pubkey_path'] ?? ''),
            //「微信支付平台证书」文件路径
            'platformCertificateFilePath' => getCertFilePath('wxpaycert_'.$this->channel['appmchid'].'.pem', true),
            //微信支付平台公钥ID
            'platformCertificateSerial' => $this->channel['publickeyid'] ?? '',
            //是否国际版商户
            'isGlobal' => true,
        ];
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $urlpre = '/';
        if (!empty(config_get('localurl_wxpay')) && !strpos(config_get('localurl_wxpay'), request()->host())) {
            $urlpre = config_get('localurl_wxpay');
        }

        if ($ctx->mdevice === 'wechat') {
            if (in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $urlpre . 'pay/jspay/' . $tradeNo . '/?d=1'];
            } elseif (in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
                return ['type' => 'jump', 'url' => $urlpre . 'pay/wap/' . $tradeNo . '/'];
            } elseif (in_array('1', $this->channel['apptype']) && config_get('wework_payopen') == 1) {
                return ['type' => 'jump', 'url' => '/pay/qrcode/' . $tradeNo . '/'];
            } else {
                if (defined('SUMBIT2')){
                    return ['type' => 'page', 'page' => 'wxopen'];
                } else {
                    return ['type'=>'jump','url'=>'/pay/submit/'.$tradeNo.'/'];
                }
            }
        } elseif ($ctx->isMobile) {
            if (in_array('3', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $urlpre . 'pay/h5/' . $tradeNo . '/'];
            } elseif (in_array('5', $this->channel['apptype']) && strpos(request()->header('user-agent', ''), 'iPhone OS') !== false) {
                return ['type' => 'jump', 'url' => $urlpre . 'pay/apppay/' . $tradeNo . '/'];
            } elseif (in_array('2', $this->channel['apptype']) && ($this->channel['appwxmp'] > 0 || $this->channel['appwxa'] > 0)) {
                return ['type' => 'jump', 'url' => $urlpre . 'pay/wap/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/qrcode/' . $tradeNo . '/'];
            }
        } else {
            return ['type' => 'jump', 'url' => '/pay/qrcode/' . $tradeNo . '/'];
        }
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $urlpre = request()->siteurl;
        if (!empty(config_get('localurl_wxpay')) && !strpos(config_get('localurl_wxpay'), request()->host())) {
            $urlpre = config_get('localurl_wxpay');
        }

        if ($ctx->method === 'applet' && in_array('2', $this->channel['apptype'])) {
            return $this->applet($ctx);
        } elseif ($ctx->method === 'app') {
            return $this->apppay($ctx);
        } elseif ($ctx->method === 'jsapi') {
            return $this->jspay($ctx);
        } elseif ($ctx->method === 'scan') {
            return $this->scanpay($ctx);
        } elseif ($ctx->mdevice === 'wechat') {
            if (in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $urlpre . 'pay/jspay/' . $tradeNo . '/?d=1'];
            } elseif (in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
                return $this->wap($ctx);
            } else {
                return ['type' => 'jump', 'url' => request()->siteurl . 'pay/submit/' . $tradeNo . '/'];
            }
        } elseif ($ctx->isMobile) {
            if (in_array('5', $this->channel['apptype']) && $ctx->mdevice === 'app') {
                return $this->apppay($ctx);
            } elseif (in_array('3', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $urlpre . 'pay/h5/' . $tradeNo . '/'];
            } elseif (in_array('5', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $urlpre . 'pay/submit/' . $tradeNo . '/'];
            } elseif (in_array('2', $this->channel['apptype']) && ($this->channel['appwxmp'] > 0 || $this->channel['appwxa'] > 0)) {
                return $this->wap($ctx);
            } else {
                return $this->qrcode($ctx);
            }
        } else {
            return $this->qrcode($ctx);
        }
    }

    //扫码支付
    public function qrcode(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        if (!empty(config_get('localurl_wxpay')) && !strpos(config_get('localurl_wxpay'), request()->host())) {
            $siteurl = config_get('localurl_wxpay');
        }

        if (in_array('1', $this->channel['apptype'])) {
            $param = [
                'description' => $ctx->ordername,
                'out_trade_no' => $tradeNo,
                'time_expire' => date('c', strtotime('+2 hours')),
                'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
                'merchant_category_code' => $this->channel['appurl'],
                'amount' => [
                    'total' => intval(round($ctx->order['realmoney'] * 100)),
                    'currency' => 'CNY'
                ],
                'scene_info' => [
                    'payer_client_ip' => request()->clientip
                ]
            ];

            try {
                $client = new \WeChatPay\V3\GlobalPaymentService($this->wechatpayConfig);
                $result = $client->nativePay($param);
                $code_url = $result['code_url'];
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $e->getMessage()];
            }

        } elseif (in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
            $code_url = $siteurl . 'pay/jspay/' . $tradeNo . '/';
        } elseif (in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
            $code_url = $siteurl . 'pay/wap/' . $tradeNo . '/';
        } elseif (in_array('3', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/h5/' . $tradeNo . '/';
        } else {
            return ['type' => 'error', 'msg' => '当前支付通道没有开启的支付方式'];
        }
        return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
    }

    //JS支付
    public function jspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $config = $this->wechatpayConfig;

        if (!empty($ctx->order['sub_openid'])) {
            if (!empty($ctx->order['sub_appid'])) {
                $config['appid'] = $ctx->order['sub_appid'];
            } else {
                $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
                if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
                $config['appid'] = $wxinfo['appid'];
            }
            $openid = $ctx->order['sub_openid'];
        } else {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
            $config['appid'] = $wxinfo['appid'];
            //①、获取用户openid
            $openid = wechat_oauth($wxinfo);
        }

        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        //②、统一下单
        $param = [
            'description' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
            'time_expire' => date('c', strtotime('+2 hours')),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'merchant_category_code' => $this->channel['appurl'],
            'amount' => [
                'total' => intval(round($ctx->order['realmoney'] * 100)),
                'currency' => 'CNY'
            ],
            'payer' => [
                'openid' => $openid
            ],
            'scene_info' => [
                'payer_client_ip' => request()->clientip
            ]
        ];

        try {
            $client = new \WeChatPay\V3\GlobalPaymentService($config);
            $result = $client->jsapiPay($param);
            $jsApiParameters = json_encode($result);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $e->getMessage()];
        }
        if ($ctx->method === 'jsapi') {
            return ['type' => 'jsapi', 'data' => $jsApiParameters];
        }

        if (request()->get('d', '') === '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $jsApiParameters, 'redirect_url' => $redirect_url]];
    }

    //手机支付
    public function wap(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($this->channel['appwxa'] > 0 && !request()->get('qrcode')) {
            try {
                $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
                if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } else {
            $code_url = $siteurl . 'pay/jspay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        }
    }

    //H5支付
    public function h5(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'page', 'page' => 'wxopen'];
        }

        $param = [
            'description' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
            'time_expire' => date('c', strtotime('+2 hours')),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'merchant_category_code' => $this->channel['appurl'],
            'amount' => [
                'total' => intval(round($ctx->order['realmoney'] * 100)),
                'currency' => 'CNY'
            ],
            'scene_info' => [
                'payer_client_ip' => request()->clientip,
                'store_info' => [
                    'name' => config_get('sitename'),
                    'address' => $siteurl,
                ],
            ]
        ];

        try {
            $client = new \WeChatPay\V3\GlobalPaymentService($this->wechatpayConfig);
            $result = $client->h5Pay($param);
            $redirect_url = $siteurl . 'pay/return/' . $tradeNo . '/';
            $url = $result['mweb_url'] . '&redirect_url=' . urlencode($redirect_url);
            return ['type' => 'jump', 'url' => $url];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $e->getMessage()];
        }
    }

    //小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $config = $this->wechatpayConfig;

        $code = request()->get('code', '', 'trim');
        if (empty($code)) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }

        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];
        }
        $config['appid'] = $wxinfo['appid'];

        //①、获取用户openid
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
        $param = [
            'description' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
            'time_expire' => date('c', strtotime('+2 hours')),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'merchant_category_code' => $this->channel['appurl'],
            'amount' => [
                'total' => intval(round($ctx->order['realmoney'] * 100)),
                'currency' => 'CNY'
            ],
            'payer' => [
                'openid' => $openid
            ],
            'scene_info' => [
                'payer_client_ip' => request()->clientip
            ]
        ];

        try {
            $client = new \WeChatPay\V3\GlobalPaymentService($config);
            $jsApiParameters = $client->jsapiPay($param);
            return ['type' => 'json', 'data' => ['code' => 0, 'data' => $jsApiParameters]];
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $e->getMessage()]];
        }
    }

    //APP支付
    public function apppay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $param = [
            'description' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
            'time_expire' => date('c', strtotime('+2 hours')),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'merchant_category_code' => $this->channel['appurl'],
            'amount' => [
                'total' => intval(round($ctx->order['realmoney'] * 100)),
                'currency' => 'CNY'
            ],
            'scene_info' => [
                'payer_client_ip' => request()->clientip
            ]
        ];

        try {
            $client = new \WeChatPay\V3\GlobalPaymentService($this->wechatpayConfig);
            $result = $client->appPay($param);
            if ($ctx->method === 'app') {
                return ['type' => 'app', 'data' => json_encode($result)];
            }
            $params = [
                'nonceStr' => $result['noncestr'],
                'package' => $result['package'],
                'partnerId' => $result['partnerid'],
                'prepayId' => $result['prepayid'],
                'timeStamp' => $result['timestamp'],
                'sign' => $result['sign'],
            ];
            $code_url = 'weixin://app/' . $result['appid'] . '/pay/?' . http_build_query($params);
            return ['type' => 'qrcode', 'page' => 'wxpay_h5', 'url' => $code_url];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $e->getMessage()];
        }
    }

    //付款码支付
    public function scanpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'description' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
            'merchant_category_code' => $this->channel['appurl'],
            'payer' => [
                'auth_code' => $ctx->order['auth_code']
            ],
            'amount' => [
                'total' => intval(round($ctx->order['realmoney'] * 100)),
                'currency' => 'CNY'
            ],
            'scene_info' => [
                'payer_client_ip' => request()->clientip
            ]
        ];
        $client = new \WeChatPay\V3\GlobalPaymentService($this->wechatpayConfig);
        try {
            $result = $client->microPay($params);
            if ($result['trade_state'] == 'SUCCESS') {
                ($this->markTrustedQueryResult('notify', 'wechatpay-global-micropay-success'))(function () use ($tradeNo, $result) {
                    $this->processNotify($this->getOrder($tradeNo), $result['id'], $result['payer']['openid']);
                });
                return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['out_trade_no'], 'api_trade_no' => $result['id'], 'buyer' => $result['payer']['openid'], 'money' => strval(round($result['amount']['total'] / 100, 2))]];
            } elseif ($result['trade_state'] == 'USERPAYING') {
                sleep(2);
                $retry = 0;
                $success = false;
                while ($retry < 6) {
                    sleep(3);
                    try {
                        $result = $client->orderQuery(null, $tradeNo);
                    } catch (Exception $e) {
                        return ['type' => 'error', 'msg' => '微信支付失败！订单查询失败:' . $e->getMessage()];
                    }
                    if ($result['trade_state'] == 'SUCCESS') {
                        $success = true;
                        break;
                    } elseif ($result['trade_state'] != 'USERPAYING') {
                        return ['type' => 'error', 'msg' => '微信支付失败！' . $result['trade_state_desc']];
                    }
                    $retry++;
                }
                if ($success) {
                    ($this->markTrustedQueryResult('notify', 'wechatpay-global-order-query'))(function () use ($tradeNo, $result) {
                        $this->processNotify($this->getOrder($tradeNo), $result['id'], $result['payer']['openid']);
                    });
                    return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['out_trade_no'], 'api_trade_no' => $result['id'], 'buyer' => $result['payer']['openid'], 'money' => strval(round($result['amount']['total'] / 100, 2))]];
                } else {
                    try {
                        $client->reverseOrder($tradeNo);
                    } catch (Exception $e) {
                    }
                    return ['type' => 'error', 'msg' => '微信支付失败！订单已超时'];
                }
            } else {
                return ['type' => 'error', 'msg' => '微信支付失败！' . $result['trade_state_desc']];
            }
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $e->getMessage()];
        }
    }

    //小程序跳转支付
    public function applet(PaymentContext $ctx): array
    {
        try {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            $path = wxminipay_jump_path($ctx->order);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => $wxinfo['appid'], 'miniProgramId' => '', 'path' => $path]];
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    //异步回调
    public function notify(PaymentContext $ctx)
    {
        try {
            $client = new \WeChatPay\V3\GlobalPaymentService($this->wechatpayConfig);
            $data = $client->notify();
        } catch (Exception $e) {
            $json = $client->replyNotify(false, $e->getMessage(), true);
            return ['type' => 'json', 'data' => $json, 'code' => 499];
        }

        if ($data['trade_state'] == 'SUCCESS') {
            if ($data['out_trade_no'] == $ctx->order['trade_no']) {
                ($this->markTrustedCallback($ctx, 'notify', 'wechatpay-v3-global-notify'))(function () use ($ctx, $data) {
                    $this->processNotify($ctx->order, $data['id'], $data['payer']['openid'], null, null, $data['success_time']);
                });
            }
        }
        $json = $client->replyNotify(true, '', true);
        return ['type' => 'json', 'data' => $json];
    }

    public function query(array $order): array
    {
        $client = new \WeChatPay\V3\GlobalPaymentService($this->wechatpayConfig);
        $result = $client->orderQuery(null, $order['trade_no']);
        return [
            'api_trade_no' => $result['transaction_id'] ?? '',
            'status' => $result['trade_state'] == 'SUCCESS' ? 1 : 0,
            'money' => ($result['amount']['total'] ?? 0) / 100,
            'buyer' => $result['payer']['openid'] ?? '',
            'endtime' => $result['success_time'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $param = [
            'transaction_id' => $order['api_trade_no'],
            'out_refund_no' => $order['refund_no'],
            'amount' => [
                'refund' => intval(round($order['refundmoney'] * 100)),
                'total' => intval(round($order['realmoney'] * 100)),
                'currency' => 'CNY'
            ]
        ];

        try {
            $client = new \WeChatPay\V3\GlobalPaymentService($this->wechatpayConfig);
            $result = $client->refund($param);
            return ['code' => 0, 'trade_no' => $result['out_trade_no'], 'refund_fee' => $result['amount']['refund']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //关闭订单
    public function close(array $order): array
    {
        try {
            $client = new \WeChatPay\V3\GlobalPaymentService($this->wechatpayConfig);
            $client->closeOrder($order['trade_no']);
            return ['code' => 0];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }
}
