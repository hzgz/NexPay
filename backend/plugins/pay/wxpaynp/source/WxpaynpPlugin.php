<?php

declare(strict_types=1);

namespace plugins\payment\wxpaynp;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class WxpaynpPlugin extends BasePayment
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
            //子商户应用ID（可留空）
            'sub_appid' => isset($this->channel['sub_appid']) ? $this->channel['sub_appid'] : '',
            //子商户号
            'sub_mchid' => $this->channel['appurl'],
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
            //是否电商收付通
            'ecommerce' => $this->channel['appswitch'] == '1',
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
            if (!empty($this->channel['apiv2key'])) {
                return $this->scanpay($ctx);
            }
            return ['type' => 'error', 'msg' => '当前支付通道不支持付款码支付'];
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
                'amount' => [
                    'total' => intval(round($ctx->order['realmoney'] * 100)),
                    'currency' => 'CNY'
                ],
                'scene_info' => [
                    'payer_client_ip' => request()->clientip
                ]
            ];
            if ($ctx->order['profits'] > 0 || config_get('direct_settle_time') == 1 && $this->channel['appswitch'] == '1') {
                $param['settle_info'] = ['profit_sharing' => true];
            }

            try {
                $client = new \WeChatPay\V3\PartnerPaymentService($this->wechatpayConfig);
                $submoneys = combinepay_submoneys($param['amount']['total']);
                if (!$submoneys) {
                    $result = $client->nativePay($param);
                } else {
                    $param = $this->combineOrderParams($ctx->order, $param, $submoneys, $sub_orders);
                    $result = $client->combineNativePay($param);
                    $this->updateOrderCombine($tradeNo, $sub_orders);
                }
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
        if ($ctx->order['typename'] == 'qqpay') {
            return ['type' => 'qrcode', 'page' => 'qqpay_qrcode', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //JS支付
    public function jspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $config = $this->wechatpayConfig;

        if (!empty($ctx->order['sub_openid'])) {
            if (!empty($ctx->order['sub_appid'])) {
                $config['sub_appid'] = $ctx->order['sub_appid'];
            } else {
                if (($ctx->order['is_applet'] ?? 0) == 1) {
                    $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
                    if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
                    if (!empty($this->channel['subappwxa'])) {
                        $config['sub_appid'] = $wxinfo['appid'];
                    } else {
                        $config['appid'] = $wxinfo['appid'];
                    }
                } else {
                    $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
                    if (!$wxinfo) return ['type' => 'error', 'msg' => $ctx->order['typename'] == 'qqpay' ? '支付通道绑定的QQ互联应用不存在' : '支付通道绑定的微信公众号不存在'];
                    if (!empty($this->channel['subappwxmp'])) {
                        $config['sub_appid'] = $wxinfo['appid'];
                    } else {
                        $config['appid'] = $wxinfo['appid'];
                    }
                }
            }
            $openid = $ctx->order['sub_openid'];
        } else {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => $ctx->order['typename'] == 'qqpay' ? '支付通道绑定的QQ互联应用不存在' : '支付通道绑定的微信公众号不存在'];
            if (!empty($this->channel['subappwxmp'])) {
                $config['sub_appid'] = $wxinfo['appid'];
            } else {
                $config['appid'] = $wxinfo['appid'];
            }
            //①、获取用户openid
            if ($ctx->order['typename'] == 'qqpay') {
                $openid = qqpay_oauth($wxinfo);
            } else {
                $openid = wechat_oauth($wxinfo);
            }
        }

        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        //②、统一下单
        $param = [
            'description' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
            'time_expire' => date('c', strtotime('+2 hours')),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'amount' => [
                'total' => intval(round($ctx->order['realmoney'] * 100)),
                'currency' => 'CNY'
            ],
            'payer' => [
                'sp_openid' => $openid
            ],
            'scene_info' => [
                'payer_client_ip' => request()->clientip
            ]
        ];
        if ($ctx->order['profits'] > 0 || config_get('direct_settle_time') == 1 && $this->channel['appswitch'] == '1') {
            $param['settle_info'] = ['profit_sharing' => true];
        }
        if (!empty($this->channel['sub_appid'])) {
            $param['payer']['sub_openid'] = $openid;
            unset($param['payer']['sp_openid']);
        }

        try {
            $client = new \WeChatPay\V3\PartnerPaymentService($config);
            $submoneys = combinepay_submoneys($param['amount']['total']);
            if (!$submoneys) {
                $result = $client->jsapiPay($param);
            } else {
                $param = $this->combineOrderParams($ctx->order, $param, $submoneys, $sub_orders);
                $result = $client->combineJsapiPay($param);
                $this->updateOrderCombine($tradeNo, $sub_orders);
            }
            if ($ctx->order['typename'] == 'qqpay') {
                $result['payVersion'] = '1';
            }
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
        return ['type' => 'page', 'page' => $ctx->order['typename'] == 'qqpay' ? 'qqpay_jspayn' : 'wxpay_jspay', 'data' => ['jsApiParameters' => $jsApiParameters, 'redirect_url' => $redirect_url]];
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
            'amount' => [
                'total' => intval(round($ctx->order['realmoney'] * 100)),
                'currency' => 'CNY'
            ],
            'scene_info' => [
                'payer_client_ip' => request()->clientip,
                'h5_info' => [
                    'type' => 'Wap',
                    'app_name' => config_get('sitename'),
                    'app_url' => $siteurl,
                ],
            ]
        ];
        if ($ctx->order['profits'] > 0 || config_get('direct_settle_time') == 1 && $this->channel['appswitch'] == '1') {
            $param['settle_info'] = ['profit_sharing' => true];
        }

        try {
            $client = new \WeChatPay\V3\PartnerPaymentService($this->wechatpayConfig);
            $submoneys = combinepay_submoneys($param['amount']['total']);
            if (!$submoneys) {
                $result = $client->h5Pay($param);
            } else {
                $param = $this->combineOrderParams($ctx->order, $param, $submoneys, $sub_orders);
                $result = $client->combineH5Pay($param);
                $this->updateOrderCombine($tradeNo, $sub_orders);
            }
            $redirect_url = $siteurl . 'pay/return/' . $tradeNo . '/';
            $url = $result['h5_url'] . '&redirect_url=' . urlencode($redirect_url);
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
        if ($this->channel['subappwxa']) {
            $config['sub_appid'] = $wxinfo['appid'];
        } else {
            $config['appid'] = $wxinfo['appid'];
        }

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
            'amount' => [
                'total' => intval(round($ctx->order['realmoney'] * 100)),
                'currency' => 'CNY'
            ],
            'payer' => [
                'sp_openid' => $openid
            ],
            'scene_info' => [
                'payer_client_ip' => request()->clientip
            ]
        ];
        if ($ctx->order['profits'] > 0 || config_get('direct_settle_time') == 1 && $this->channel['appswitch'] == '1') {
            $param['settle_info'] = ['profit_sharing' => true];
        }
        if ($this->channel['subappwxa']) {
            $param['payer']['sub_openid'] = $openid;
            unset($param['payer']['sp_openid']);
        }

        try {
            $client = new \WeChatPay\V3\PartnerPaymentService($config);
            $submoneys = combinepay_submoneys($param['amount']['total']);
            if (!$submoneys) {
                $jsApiParameters = $client->jsapiPay($param);
            } else {
                $param = $this->combineOrderParams($ctx->order, $param, $submoneys, $sub_orders);
                $jsApiParameters = $client->combineJsapiPay($param);
                $this->updateOrderCombine($tradeNo, $sub_orders);
            }
            return ['type' => 'json', 'data' => ['code' => 0, 'data' => $jsApiParameters]];
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $e->getMessage()]];
        }
    }

    //APP支付
    public function apppay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $config = $this->wechatpayConfig;
        if (!empty($ctx->order['sub_appid'])) {
            $config['sub_appid'] = $ctx->order['sub_appid'];
        }
        $param = [
            'description' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
            'time_expire' => date('c', strtotime('+2 hours')),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'amount' => [
                'total' => intval(round($ctx->order['realmoney'] * 100)),
                'currency' => 'CNY'
            ],
            'scene_info' => [
                'payer_client_ip' => request()->clientip
            ]
        ];
        if ($ctx->order['profits'] > 0 || config_get('direct_settle_time') == 1 && $this->channel['appswitch'] == '1') {
            $param['settle_info'] = ['profit_sharing' => true];
        }
        try {
            $client = new \WeChatPay\V3\PartnerPaymentService($config);
            $submoneys = combinepay_submoneys($param['amount']['total']);
            if (!$submoneys) {
                $result = $client->appPay($param);
            } else {
                $param = $this->combineOrderParams($ctx->order, $param, $submoneys, $sub_orders);
                $result = $client->combineAppPay($param);
                $this->updateOrderCombine($tradeNo, $sub_orders);
            }
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

        $wechatpayConfig = [
            'appid' => $this->channel['appid'],
            'mchid' => $this->channel['appmchid'],
            'sub_mchid' => $this->channel['appurl'],
            'apikey' => $this->channel['apiv2key'],
            'sslcert_path' => '',
            'sslkey_path' => '',
        ];
        $params = [
            'body'             => $ctx->ordername,
            'out_trade_no'     => $tradeNo,
            'total_fee'        => strval($ctx->order['realmoney'] * 100),
            'spbill_create_ip' => request()->clientip,
            'auth_code'        => $ctx->order['auth_code'],
        ];
        $client = new \WeChatPay\PaymentService($wechatpayConfig);
        try {
            $result = $client->microPay($params);
            ($this->markTrustedQueryResult('notify', 'wechatpay-partner-micropay-success'))(function () use ($tradeNo, $result) {
                $this->processNotify($this->getOrder($tradeNo), $result['transaction_id'], $result['openid']);
            });
            return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['out_trade_no'], 'api_trade_no' => $result['transaction_id'], 'buyer' => $result['openid'], 'money' => strval(round($result['total_fee'] / 100, 2))]];
        } catch (\WeChatPay\WeChatPayException $e) {
            $err_code = $e->getErrCode();
            if ($err_code === 'SYSTEMERROR' || $err_code === 'USERPAYING') {
                if ($err_code === 'USERPAYING') sleep(2);
                $retry = 0;
                $success = false;
                while ($retry < 6) {
                    sleep(3);
                    try {
                        $result = $client->orderQuery(null, $tradeNo);
                    } catch (\Exception $e) {
                        return ['type' => 'error', 'msg' => '微信支付失败！订单查询失败:' . $e->getMessage()];
                    }
                    if ($result['trade_state'] === 'SUCCESS') {
                        $success = true;
                        break;
                    } elseif ($result['trade_state'] !== 'USERPAYING') {
                        return ['type' => 'error', 'msg' => '微信支付失败！订单超时或用户取消支付'];
                    }
                    $retry++;
                }
                if ($success) {
                    ($this->markTrustedQueryResult('notify', 'wechatpay-partner-order-query'))(function () use ($tradeNo, $result) {
                        $this->processNotify($this->getOrder($tradeNo), $result['transaction_id'], $result['openid']);
                    });
                    return ['type' => 'scan', 'data' => ['type' => $ctx->order['typename'], 'trade_no' => $result['out_trade_no'], 'api_trade_no' => $result['transaction_id'], 'buyer' => $result['openid'], 'money' => strval(round($result['total_fee'] / 100, 2))]];
                } else {
                    try {
                        $client->reverse(null, $tradeNo);
                    } catch (\Exception $e) {
                    }
                    return ['type' => 'error', 'msg' => '微信支付失败！订单已超时'];
                }
            }
            return ['type' => 'error', 'msg' => $e->getMessage()];
        } catch (\Exception $e) {
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
            $client = new \WeChatPay\V3\PartnerPaymentService($this->wechatpayConfig);
            $data = $client->notify();
        } catch (Exception $e) {
            $json = $client->replyNotify(false, $e->getMessage(), true);
            return ['type' => 'json', 'data' => $json, 'code' => 499];
        }

        if (isset($data['combine_out_trade_no'])) {
            if ($data['combine_out_trade_no'] == $ctx->order['trade_no']) {
                ($this->markTrustedCallback($ctx, 'notify', 'wechatpay-v3-partner-notify'))(function () use ($ctx, $data) {
                    $sub_orders = [];
                    foreach ($data['sub_orders'] as $detail) {
                        $sub_orders[] = ['sub_trade_no' => $detail['out_trade_no'], 'api_trade_no' => $detail['transaction_id'], 'money' => round($detail['amount']['total_amount'] / 100, 2)];
                    }
                    $this->processSubOrders($ctx->order['trade_no'], $sub_orders);

                    if (config_get('direct_settle_time') > 0 && $this->channel['appswitch'] == '1' && $ctx->order['profits'] == 0) {
                        $ctx->order['settle'] = 1;
                    }
                    $this->processNotify($ctx->order, $data['combine_out_trade_no'], $data['combine_payer_info']['openid']);
                });
            }
        } else {
            if ($data['trade_state'] == 'SUCCESS') {
                if ($data['out_trade_no'] == $ctx->order['trade_no']) {
                    ($this->markTrustedCallback($ctx, 'notify', 'wechatpay-v3-partner-notify'))(function () use ($ctx, $data) {
                        if (config_get('direct_settle_time') > 0 && $this->channel['appswitch'] == '1' && $ctx->order['profits'] == 0) {
                            $ctx->order['settle'] = 1;
                        }
                        $this->processNotify($ctx->order, $data['transaction_id'], $data['payer']['sp_openid'], null, null, $data['success_time']);
                    });
                }
            }
        }
        $json = $client->replyNotify(true, '', true);
        return ['type' => 'json', 'data' => $json];
    }

    public function query(array $order): array
    {
        $client = new \WeChatPay\V3\PartnerPaymentService($this->wechatpayConfig);
        $result = $client->orderQuery(null, $order['trade_no']);
        return [
            'api_trade_no' => $result['transaction_id'] ?? '',
            'status' => $result['trade_state'] == 'SUCCESS' ? 1 : 0,
            'money' => ($result['amount']['total'] ?? 0) / 100,
            'buyer' => $result['payer']['sp_openid'] ?? '',
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
            $client = new \WeChatPay\V3\PartnerPaymentService($this->wechatpayConfig);
            $result = $client->refund($param);
            return ['code' => 0, 'trade_no' => $result['out_trade_no'], 'refund_fee' => $result['amount']['refund']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //合单退款
    public function refund_combine(array $order): array
    {
        $sub_orders = $this->getSubOrders($order['trade_no']);
        if (empty($sub_orders)) return ['code' => -1, 'msg' => '子订单数据不存在'];

        try {
            $client = new \WeChatPay\V3\PartnerPaymentService($this->wechatpayConfig);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        //循环退款
        $refundmoney = $order['refundmoney'];
        foreach ($sub_orders as $sub_order) {
            if ($sub_order['status'] == 2 && (empty($sub_order['refundmoney']) || $sub_order['refundmoney'] >= $sub_order['money'])) continue;
            $money = $sub_order['money'];
            if ($sub_order['status'] == 2) $money = round($sub_order['money'] - $sub_order['refundmoney'], 2);
            if ($money > $refundmoney) {
                $money = $refundmoney;
            }
            $refund_no = date("YmdHis") . rand(11111, 99999);
            $param = [
                'transaction_id' => $sub_order['api_trade_no'],
                'out_refund_no' => $refund_no,
                'amount' => [
                    'refund' => intval(round($money * 100)),
                    'total' => intval(round($sub_order['money'] * 100)),
                    'currency' => 'CNY'
                ]
            ];

            try {
                $client->refund($param);
            } catch (Exception $e) {
                return ['code' => -1, 'msg' => $e->getMessage()];
            }
            $sub_refundmoney = $sub_order['refundmoney'] ? round($sub_order['refundmoney'] + $money, 2) : $money;
            $this->refundSubOrder($sub_order['sub_trade_no'], $sub_refundmoney);
            $refundmoney = round($refundmoney - $money, 2);
            if ($refundmoney <= 0) break;
        }

        return ['code' => 0];
    }

    //关闭订单
    public function close(array $order): array
    {
        try {
            $client = new \WeChatPay\V3\PartnerPaymentService($this->wechatpayConfig);
            $client->closeOrder($order['trade_no']);
            return ['code' => 0];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //合单支付关闭订单
    public function close_combine(array $order): array
    {
        $sub_orders = $this->getSubOrders($order['trade_no']);
        if (empty($sub_orders)) return ['code' => -1, 'msg' => '子订单数据不存在'];

        try {
            $client = new \WeChatPay\V3\PartnerPaymentService($this->wechatpayConfig);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        foreach ($sub_orders as $sub_order) {
            try {
                $client->closeOrder($sub_order['trade_no']);
            } catch (Exception $e) {
                return ['code' => -1, 'msg' => $e->getMessage()];
            }
        }

        return ['code' => 0];
    }

    //处理合单支付参数
    private function combineOrderParams(array $order, array $param, array $submoneys, &$sub_orders_data): array
    {
        if (strpos($this->channel['appurl'], ',')) {
            $sub_mchids = explode(',', $this->channel['appurl']);
            shuffle($sub_mchids);
        }

        $sub_orders = [];
        $i = 1;
        foreach ($submoneys as $money) {
            $sub_order = [
                'attach' => 'combine',
                'amount' => [
                    'total_amount' => $money,
                    'currency' => $param['amount']['currency'],
                ],
                'out_trade_no' => $param['out_trade_no'] . $i,
                'description' => $param['description'],
            ];
            if (isset($sub_mchids)) {
                $sub_order['sub_mchid'] = $sub_mchids[($i - 1) % count($sub_mchids)];
            }
            if ($order['profits'] > 0 || config_get('direct_settle_time') == 1 && $this->channel['appswitch'] == '1') {
                $sub_order['settle_info'] = ['profit_sharing' => true];
            }
            $sub_orders[] = $sub_order;
            $sub_orders_data[] = ['sub_trade_no' => $sub_order['out_trade_no'], 'money' => round($money / 100, 2)];
            $i++;
        }
        if (!empty($param['scene_info']['h5_info'])) $param['scene_info']['device_id'] = '10001';
        $newparam = [
            'combine_out_trade_no' => $param['out_trade_no'],
            'scene_info' => $param['scene_info'],
            'sub_orders' => $sub_orders,
            'time_expire' => date('c', strtotime('+2 hours')),
            'notify_url' => $param['notify_url'],
        ];
        if (isset($param['payer'])) {
            $newparam['combine_payer_info']['openid'] = $param['payer']['sp_openid'];
        }
        return $newparam;
    }

    //投诉通知回调
    public function complainnotify(PaymentContext $ctx)
    {
        try {
            $client = new \WeChatPay\V3\BaseService($this->wechatpayConfig);
            $data = $client->notify();
        } catch (Exception $e) {
            $json = $client->replyNotify(false, $e->getMessage(), true);
            return ['type' => 'json', 'data' => $json, 'code' => 499];
        }

        $model = \app\logic\ComplainLogic::getModel($this->channel);
        $model->refreshNewInfo($data['complaint_id'], $data['action_type']);

        $json = $client->replyNotify(true, '', true);
        return ['type' => 'json', 'data' => $json];
    }

    //商户新增管控流水通知
    public function mchrisknotify(PaymentContext $ctx)
    {
        try {
            $client = new \WeChatPay\V3\BaseService($this->wechatpayConfig);
            $data = $client->notify();
        } catch (Exception $e) {
            $json = $client->replyNotify(false, $e->getMessage(), true);
            return ['type' => 'json', 'data' => $json, 'code' => 499];
        }

        $model = new \app\lib\WxMchRisk($this->channel);
        $model->notify($data);

        $json = $client->replyNotify(true, '', true);
        return ['type' => 'json', 'data' => $json];
    }
}
