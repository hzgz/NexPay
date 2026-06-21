<?php

declare(strict_types=1);

namespace plugins\payment\wxpayn;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class WxpaynPlugin extends BasePayment
{
    private array $wechatpayConfig;

    private array $fail_reason_desc = ['ACCOUNT_FROZEN'=>'该用户账户被冻结', 'REAL_NAME_CHECK_FAIL'=>'收款人未实名认证', 'NAME_NOT_CORRECT'=>'收款人姓名校验不通过', 'OPENID_INVALID'=>'Openid校验失败', 'TRANSFER_QUOTA_EXCEED'=>'超过用户单笔收款额度', 'DAY_RECEIVED_QUOTA_EXCEED'=>'超过用户单日收款额度', 'MONTH_RECEIVED_QUOTA_EXCEED'=>'超过用户单月收款额度', 'DAY_RECEIVED_COUNT_EXCEED'=>'超过用户单日收款次数', 'PRODUCT_AUTH_CHECK_FAIL'=>'未开通该权限或权限被冻结', 'OVERDUE_CLOSE'=>'超过系统重试期，系统自动关闭', 'ID_CARD_NOT_CORRECT'=>'收款人身份证校验不通过', 'ACCOUNT_NOT_EXIST'=>'该用户账户不存在', 'TRANSFER_RISK'=>'该笔转账可能存在风险，已被微信拦截', 'OTHER_FAIL_REASON_TYPE'=>'其它失败原因', 'REALNAME_ACCOUNT_RECEIVED_QUOTA_EXCEED'=>'用户账户收款受限，请引导用户在微信支付查看详情', 'RECEIVE_ACCOUNT_NOT_PERMMIT'=>'未配置该用户为转账收款人', 'PAYER_ACCOUNT_ABNORMAL'=>'商户账户付款受限，可前往商户平台获取解除功能限制指引', 'PAYEE_ACCOUNT_ABNORMAL'=>'用户账户收款异常，请引导用户完善身份信息', 'TRANSFER_REMARK_SET_FAIL'=>'转账备注设置失败，请调整后重新再试','TRANSFER_SCENE_UNAVAILABLE'=>'该转账场景暂不可用，请确认转账场景ID是否正确','TRANSFER_SCENE_INVALID'=>'你尚未获取该转账场景，请确认转账场景ID是否正确','RECEIVE_ACCOUNT_NOT_CONFIGURE'=>'请前往商户平台-商家转账到零钱-前往功能-转账场景中添加','BLOCK_B2C_USERLIMITAMOUNT_MONTH'=>'用户账户存在风险收款受限，本月不支持继续向该用户付款','MERCHANT_REJECT'=>'转账验密人已驳回转账','MERCHANT_NOT_CONFIRM'=>'转账验密人超时未验密','BLOCK_B2C_USERLIMITAMOUNT_BSRULE_MONTH'=>'超出用户月转账收款限额，本月不支持继续向该用户付款','MCH_CANCEL'=>'商户撤销付款'];

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
            if ($ctx->order['profits'] > 0) {
                $param['settle_info'] = ['profit_sharing' => true];
            }

            try {
                $client = new \WeChatPay\V3\PaymentService($this->wechatpayConfig);
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
                $config['appid'] = $ctx->order['sub_appid'];
            } else {
                if (($ctx->order['is_applet'] ?? 0) == 1) {
                    $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
                    if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
                    $config['appid'] = $wxinfo['appid'];
                } else {
                    $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
                    if (!$wxinfo) return ['type' => 'error', 'msg' => $ctx->order['typename'] == 'qqpay' ? '支付通道绑定的QQ互联应用不存在' : '支付通道绑定的微信公众号不存在'];
                    $config['appid'] = $wxinfo['appid'];
                }
            }
            $openid = $ctx->order['sub_openid'];
        } else {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => $ctx->order['typename'] == 'qqpay' ? '支付通道绑定的QQ互联应用不存在' : '支付通道绑定的微信公众号不存在'];
            $config['appid'] = $wxinfo['appid'];
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
                'openid' => $openid
            ],
            'scene_info' => [
                'payer_client_ip' => request()->clientip
            ]
        ];
        if ($ctx->order['profits'] > 0) {
            $param['settle_info'] = ['profit_sharing' => true];
        }

        try {
            $client = new \WeChatPay\V3\PaymentService($config);
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
        if ($ctx->order['profits'] > 0) {
            $param['settle_info'] = ['profit_sharing' => true];
        }

        try {
            $client = new \WeChatPay\V3\PaymentService($this->wechatpayConfig);
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
        $config['appid'] = $wxinfo['appid'];

        try {
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
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
            'payer' => [
                'openid' => $openid
            ],
            'scene_info' => [
                'payer_client_ip' => request()->clientip
            ]
        ];
        if ($ctx->order['profits'] > 0) {
            $param['settle_info'] = ['profit_sharing' => true];
        }

        try {
            $client = new \WeChatPay\V3\PaymentService($config);
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
        if ($ctx->order['profits'] > 0) {
            $param['settle_info'] = ['profit_sharing' => true];
        }
        try {
            $client = new \WeChatPay\V3\PaymentService($this->wechatpayConfig);
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
            ($this->markTrustedQueryResult('notify', 'wechatpay-v3-micropay-success'))(function () use ($tradeNo, $result) {
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
                    ($this->markTrustedQueryResult('notify', 'wechatpay-v3-order-query'))(function () use ($tradeNo, $result) {
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
        $tradeNo = $ctx->order['trade_no'];

        try {
            $client = new \WeChatPay\V3\PaymentService($this->wechatpayConfig);
            $data = $client->notify();
        } catch (Exception $e) {
            $json = $client->replyNotify(false, $e->getMessage(), true);
            return ['type' => 'json', 'data' => $json, 'code' => 499];
        }

        if (isset($data['combine_out_trade_no'])) {
            if ($data['combine_out_trade_no'] == $tradeNo) {
                ($this->markTrustedCallback($ctx, 'notify', 'wechatpay-v3-notify'))(function () use ($ctx, $data, $tradeNo) {
                    $sub_orders = [];
                    foreach ($data['sub_orders'] as $detail) {
                        $sub_orders[] = ['sub_trade_no' => $detail['out_trade_no'], 'api_trade_no' => $detail['transaction_id'], 'money' => round($detail['amount']['total_amount'] / 100, 2)];
                    }
                    $this->processSubOrders($tradeNo, $sub_orders);
                    $this->processNotify($ctx->order, $data['combine_out_trade_no'], $data['combine_payer_info']['openid']);
                });
            }
        } else {
            if ($data['trade_state'] == 'SUCCESS') {
                if ($data['out_trade_no'] == $tradeNo) {
                    ($this->markTrustedCallback($ctx, 'notify', 'wechatpay-v3-notify'))(function () use ($ctx, $data) {
                        $this->processNotify($ctx->order, $data['transaction_id'], $data['payer']['openid'], null, null, $data['success_time']);
                    });
                }
            }
        }
        $json = $client->replyNotify(true, '', true);
        return ['type' => 'json', 'data' => $json];
    }

    public function query(array $order): array
    {
        $client = new \WeChatPay\V3\PaymentService($this->wechatpayConfig);
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
            $client = new \WeChatPay\V3\PaymentService($this->wechatpayConfig);
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
            $client = new \WeChatPay\V3\PaymentService($this->wechatpayConfig);
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
            $client = new \WeChatPay\V3\PaymentService($this->wechatpayConfig);
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
            $client = new \WeChatPay\V3\PaymentService($this->wechatpayConfig);
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
    private function combineOrderParams(array $order, array $param, array $submoneys, &$sub_orders_data)
    {
        $sub_orders = [];
        $sub_orders_data = [];
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
            if ($order['profits'] > 0) {
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
            $newparam['combine_payer_info'] = $param['payer'];
        }
        return $newparam;
    }

    //转账
    public function transfer(array $bizParam): array
    {
        if ($bizParam['type'] == 'qqpay') {
            return $this->transfer_qq($bizParam);
        }
        if (config_get('transfer_wxpay_type') == 1) {
            return $this->transfer_n($bizParam);
        }

        $out_batch_no = $bizParam['out_biz_no'];
        try {
            $client = new \WeChatPay\V3\TransferService($this->wechatpayConfig);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        $transfer_detail = [
            'out_detail_no' => $bizParam['out_biz_no'],
            'transfer_amount' => intval(round($bizParam['money'] * 100)),
            'transfer_remark' => $bizParam['transfer_desc'],
            'openid' => $bizParam['payee_account'],
        ];
        if (!empty($bizParam['payee_real_name'])) {
            $transfer_detail['user_name'] = $client->rsaEncrypt($bizParam['payee_real_name']);
        }
        $param = [
            'out_batch_no' => $out_batch_no,
            'batch_name' => '转账给' . $bizParam['payee_real_name'],
            'batch_remark' => date("YmdHis"),
            'total_amount' => intval(round($bizParam['money'] * 100)),
            'total_num' => 1,
            'transfer_detail_list' => [
                $transfer_detail
            ],
        ];

        try {
            $result = $client->transfer($param);
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            if (!strpos($errorMsg, '对应的订单已经存在')) {
                return ['code' => -1, 'msg' => $errorMsg];
            }
        }
        $batch_id = $result['batch_id'];

        return ['code' => 0, 'status' => 0, 'orderid' => $bizParam['out_biz_no'], 'paydate' => date('Y-m-d H:i:s')];

        usleep(500000);

        try {
            $result = $client->transferoutdetail($out_batch_no, $bizParam['out_biz_no']);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
        if ($result['detail_status'] == 'PROCESSING') {
            return ['code' => 0, 'status' => 0, 'orderid' => $result['detail_id'], 'paydate' => $result['update_time']];
        } elseif ($result['detail_status'] == 'FAIL') {
            return ['code' => -1, 'errcode' => $result['fail_reason'], 'msg' => '[' . $result['fail_reason'] . ']' . ($this->fail_reason_desc[$result['fail_reason']] ?? '')];
        } elseif ($result['detail_status'] == 'SUCCESS') {
            return ['code' => 0, 'status' => 1, 'orderid' => $result['detail_id'], 'paydate' => $result['update_time']];
        } else {
            return ['code' => -1, 'msg' => '转账状态未知'];
        }
    }

    //转账查询
    public function transfer_query(array $bizParam): array
    {
        if ($bizParam['type'] == 'qqpay') {
            return $this->transfer_query_qq($bizParam);
        }
        if (config_get('transfer_wxpay_type') == 1) {
            return $this->transfer_query_n($bizParam);
        }

        try {
            $client = new \WeChatPay\V3\TransferService($this->wechatpayConfig);
            $result = $client->transferoutdetail($bizParam['out_biz_no'], $bizParam['out_biz_no']);
            if ($result['detail_status'] == 'SUCCESS') {
                $status = 1;
            } elseif ($result['detail_status'] == 'FAIL') {
                $status = 2;
            } else {
                $status = 0;
            }
            return ['code' => 0, 'status' => $status, 'amount' => round($result['transfer_amount'] / 100, 2), 'paydate' => $result['update_time'], 'errmsg' => '[' . $result['fail_reason'] . ']' . ($this->fail_reason_desc[$result['fail_reason']] ?? '')];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //电子回单
    public function transfer_proof(array $bizParam): array
    {
        if (config_get('transfer_wxpay_type') == 1) {
            return $this->transfer_proof_n($bizParam);
        }

        try {
            $client = new \WeChatPay\V3\TransferService($this->wechatpayConfig);
            if (!session('?ereceipt_' . $bizParam['out_biz_no'])) {
                $result = $client->transferDetailReceiptApply($bizParam['out_biz_no'], $bizParam['out_biz_no']);
                session('ereceipt_' . $bizParam['out_biz_no'], $result['signature_no']);
            }
            if ($result['signature_status'] == 'FINISHED') {
                return ['code' => 0, 'msg' => '电子回单生成成功！', 'download_url' => $result['download_url']];
            }

            usleep(300000);
            $result = $client->transferDetailReceiptQuery($bizParam['out_biz_no'], $bizParam['out_biz_no']);
            if ($result['signature_status'] == 'FINISHED') {
                $file_content = $client->download($result['download_url']);
                $file_md5 = md5($file_content);
                file_put_contents(UPLOAD_ROOT . 'bill/' . $file_md5 . '.pdf', $file_content);
                $download_url = request()->siteurl . 'upload/bill/' . $file_md5 . '.pdf';
                return ['code' => 0, 'msg' => '电子回单生成成功！', 'download_url' => $download_url];
            } else {
                return ['code' => 0, 'msg' => '电子回单正在生成中，请稍后再试！'];
            }
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //新商家转账
    private function transfer_n(array $bizParam): array
    {
        try {
            $client = new \WeChatPay\V3\TransferService($this->wechatpayConfig);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
        if (empty(config_get('transfer_wxpay_scene_id'))) return ['code' => -1, 'msg' => '未配置转账场景ID'];
        if (empty(config_get('transfer_wxpay_info_type')) || empty(config_get('transfer_wxpay_info_content'))) return ['code' => -1, 'msg' => '未配置转账报备信息'];
        $report_infos = [];
        $info_types = explode('|', config_get('transfer_wxpay_info_type'));
        $info_contents = explode('|', config_get('transfer_wxpay_info_content'));
        foreach ($info_types as $i => $info_type) {
            $report_infos[] = [
                'info_type' => $info_type,
                'info_content' => $info_contents[$i] ?? $info_contents[0] ?? '',
            ];
        }

        $param = [
            'out_bill_no' => $bizParam['out_biz_no'],
            'transfer_scene_id' => config_get('transfer_wxpay_scene_id'),
            'openid' => $bizParam['payee_account'],
            'transfer_amount' => intval(round($bizParam['money'] * 100)),
            'transfer_remark' => $bizParam['transfer_desc'],
            'notify_url' => config_get('localurl') . 'pay/transfernotify/' . $this->channel['id'] . '/',
            'transfer_scene_report_infos' => $report_infos,
        ];
        if ($param['transfer_amount'] >= 30 && !empty($bizParam['payee_real_name'])) {
            $param['user_name'] = $client->rsaEncrypt($bizParam['payee_real_name']);
        }

        try {
            $result = $client->mchTransfer($param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
        if ($result['state'] == 'SUCCESS') {
            return ['code' => 0, 'status' => 1, 'orderid' => $result['transfer_bill_no'], 'paydate' => date('Y-m-d H:i:s'), 'wxpackage' => $result['package_info'] ?? ''];
        } elseif ($result['state'] == 'WAIT_USER_CONFIRM' || $result['state'] == 'TRANSFERING' || $result['state'] == 'ACCEPTED' || $result['state'] == 'PROCESSING') {
            return ['code' => 0, 'status' => 0, 'orderid' => $result['transfer_bill_no'], 'paydate' => date('Y-m-d H:i:s'), 'wxpackage' => $result['package_info'] ?? ''];
        } elseif ($result['state'] == 'FAIL') {
            return ['code' => -1, 'errcode' => $result['fail_reason'], 'msg' => '[' . $result['fail_reason'] . ']' . ($this->fail_reason_desc[$result['fail_reason']] ?? ''), 'wxpackage' => $result['package_info'] ?? ''];
        } else {
            return ['code' => -1, 'msg' => '转账状态未知(' . ($result['state'] ?? '') . ')'];
        }
    }

    //新转账查询
    private function transfer_query_n(array $bizParam): array
    {
        try {
            $client = new \WeChatPay\V3\TransferService($this->wechatpayConfig);
            $result = $client->queryTransferByOutNo($bizParam['out_biz_no']);
            $errmsg = null;
            if ($result['state'] == 'SUCCESS') {
                $status = 1;
            } elseif ($result['state'] == 'FAIL') {
                $status = 2;
                $errmsg = '[' . $result['fail_reason'] . ']' . ($this->fail_reason_desc[$result['fail_reason']] ?? '');
            } elseif ($result['state'] == 'CANCELLED') {
                $status = 2;
                $errmsg = '转账已撤销';
            } else {
                $status = 0;
            }
            return ['code' => 0, 'status' => $status, 'amount' => round($result['transfer_amount'] / 100, 2), 'paydate' => $result['update_time'] ?? '', 'errmsg' => $errmsg];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //转账到QQ钱包
    private function transfer_qq(array $bizParam): array
    {
        try {
            $client = new \WeChatPay\V3\TransferService($this->wechatpayConfig);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
        if (empty(config_get('transfer_wxpay_scene_id'))) return ['code' => -1, 'msg' => '未配置转账场景ID'];
        if (empty(config_get('transfer_wxpay_info_type')) || empty(config_get('transfer_wxpay_info_content'))) return ['code' => -1, 'msg' => '未配置转账报备信息'];
        $report_infos = [];
        $info_types = explode('|', config_get('transfer_wxpay_info_type'));
        $info_contents = explode('|', config_get('transfer_wxpay_info_content'));
        foreach ($info_types as $i => $info_type) {
            $report_infos[] = [
                'info_type' => $info_type,
                'info_content' => $info_contents[$i] ?? $info_contents[0] ?? '',
            ];
        }

        $param = [
            'out_bill_no' => $bizParam['out_biz_no'],
            'transfer_scene_id' => config_get('transfer_wxpay_scene_id'),
            'user_qq' => $bizParam['payee_account'],
            'transfer_amount' => intval(round($bizParam['money'] * 100)),
            'transfer_remark' => $bizParam['transfer_desc'],
            'notify_url' => config_get('localurl') . 'pay/transfernotify/' . $this->channel['id'] . '/',
            'transfer_scene_report_infos' => $report_infos,
        ];
        if ($param['transfer_amount'] >= 30 && !empty($bizParam['payee_real_name'])) {
            $param['user_name'] = $client->rsaEncrypt($bizParam['payee_real_name']);
        }

        try {
            $result = $client->transferToQQ($param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
        if ($result['state'] == 'SUCCESS') {
            return ['code' => 0, 'status' => 1, 'orderid' => $result['transfer_bill_no'], 'paydate' => date('Y-m-d H:i:s'), 'wxpackage' => $result['package_info'] ?? ''];
        } elseif ($result['state'] == 'WAIT_USER_CONFIRM' || $result['state'] == 'TRANSFERING' || $result['state'] == 'ACCEPTED' || $result['state'] == 'PROCESSING') {
            return ['code' => 0, 'status' => 0, 'orderid' => $result['transfer_bill_no'], 'paydate' => date('Y-m-d H:i:s'), 'wxpackage' => $result['package_info'] ?? ''];
        } elseif ($result['state'] == 'FAIL') {
            return ['code' => -1, 'errcode' => $result['fail_reason'], 'msg' => '[' . $result['fail_reason'] . ']' . ($this->fail_reason_desc[$result['fail_reason']] ?? ''), 'wxpackage' => $result['package_info'] ?? ''];
        } else {
            return ['code' => -1, 'msg' => '转账状态未知(' . $result['state'] . ')'];
        }
    }

    //转账到QQ钱包查询
    private function transfer_query_qq(array $bizParam): array
    {
        try {
            $client = new \WeChatPay\V3\TransferService($this->wechatpayConfig);
            $result = $client->queryQQTransfer($bizParam['out_biz_no']);
            $errmsg = null;
            if ($result['state'] == 'SUCCESS') {
                $status = 1;
            } elseif ($result['state'] == 'FAIL') {
                $status = 2;
                $errmsg = '[' . $result['fail_reason'] . ']' . ($this->fail_reason_desc[$result['fail_reason']] ?? '');
            } elseif ($result['state'] == 'CANCELLED') {
                $status = 2;
                $errmsg = '转账已撤销';
            } else {
                $status = 0;
            }
            return ['code' => 0, 'status' => $status, 'amount' => round($result['transfer_amount'] / 100, 2), 'paydate' => $result['update_time'], 'errmsg' => $errmsg];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //撤销转账
    public function transfer_cancel(array $bizParam): array
    {
        try {
            $client = new \WeChatPay\V3\TransferService($this->wechatpayConfig);
            $result = $client->cancelTransfer($bizParam['out_biz_no']);
            return ['code' => 0];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //新电子回单
    private function transfer_proof_n(array $bizParam): array
    {
        try {
            $client = new \WeChatPay\V3\TransferService($this->wechatpayConfig);
            if (!session('?ereceipt_' . $bizParam['out_biz_no'])) {
                $result = $client->transferReceiptApply($bizParam['out_biz_no']);
                session('ereceipt_' . $bizParam['out_biz_no'], '1');
            }
            if ($result['state'] == 'GENERATING') {
                usleep(300000);
            }
            $result = $client->transferReceiptQuery($bizParam['out_biz_no']);
            if ($result['state'] == 'FINISHED') {
                $file_content = $client->download($result['download_url']);
                $file_md5 = md5($file_content);
                file_put_contents(UPLOAD_ROOT . 'bill/' . $file_md5 . '.pdf', $file_content);
                $download_url = request()->siteurl . 'upload/bill/' . $file_md5 . '.pdf';
                return ['code' => 0, 'msg' => '电子回单生成成功！', 'download_url' => $download_url];
            } elseif ($result['state'] == 'FAILED') {
                return ['code' => 0, 'msg' => '电子回单生成失败：' . $result['fail_reason']];
            } else {
                return ['code' => 0, 'msg' => '电子回单正在生成中，请稍后再试！'];
            }
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //新转账回调
    public function transfernotify(PaymentContext $ctx)
    {
        try {
            $client = new \WeChatPay\V3\BaseService($this->wechatpayConfig);
            $data = $client->notify();
        } catch (Exception $e) {
            $json = $client->replyNotify(false, $e->getMessage(), true);
            return ['type' => 'json', 'data' => $json, 'code' => 499];
        }

        $errmsg = null;
        if ($data['state'] == 'SUCCESS') {
            $status = 1;
        } elseif ($data['state'] == 'FAIL') {
            $status = 2;
            $errmsg = '[' . $data['fail_reason'] . ']' . ($this->fail_reason_desc[$data['fail_reason']] ?? '');
        } elseif ($data['state'] == 'CANCELLED') {
            $status = 2;
            $errmsg = '转账已撤销';
        }
        if (isset($status)) {
            ($this->markTrustedCallback($ctx, 'transfernotify', 'wechatpay-v3-transfer-notify'))(function () use ($data, $status, $errmsg) {
                $this->processTransfer($data['out_bill_no'], $status, $errmsg);
            });
        }

        $json = $client->replyNotify(true, '', true);
        return ['type' => 'json', 'data' => $json];
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
}
