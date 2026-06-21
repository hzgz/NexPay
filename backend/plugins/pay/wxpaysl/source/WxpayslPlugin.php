<?php

declare(strict_types=1);

namespace plugins\payment\wxpaysl;

use app\common\PaymentContext;
use app\common\BasePayment;

class WxpayslPlugin extends BasePayment
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
            //绑定支付的APPID
            'appid'        => $this->channel['appid'],
            //商户号
            'mchid'        => $this->channel['appmchid'],
            //商户APIv2密钥
            'apikey'       => $this->channel['appkey'],
            //公众帐号secert（仅JSAPI支付需要配置）
            'appsecret'    => '',
            //子商户号
            'sub_mchid'    => $this->channel['appurl'],
            //子商户APPID
            'sub_appid'    => $this->channel['sub_appid'] ?? '',
            //商户证书路径（仅退款、撤销订单时需要）
            'sslcert_path' => getCertFilePath($this->channel['sslcert_path'] ?? ''),
            //商户证书私钥路径
            'sslkey_path'  => getCertFilePath($this->channel['sslkey_path'] ?? ''),
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
            if (in_array('3', $this->channel['apptype'])) {
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
        $siteurl = request()->siteurl;
        if (!empty(config_get('localurl_wxpay')) && !strpos(config_get('localurl_wxpay'), request()->host())) {
            $siteurl = config_get('localurl_wxpay');
        }
        $tradeNo = $ctx->order['trade_no'];

        if (in_array('1', $this->channel['apptype'])) {
            $params = [
                'body'             => $ctx->ordername,
                'out_trade_no'     => $tradeNo,
                'total_fee'        => strval($ctx->order['realmoney'] * 100),
                'spbill_create_ip' => request()->clientip,
                'time_expire' => date('YmdHis', strtotime('+2 hours')),
                'notify_url'       => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
                'product_id'       => '01001',
            ];
            if ($ctx->order['profits'] > 0) {
                $params['profit_sharing'] = 'Y';
            }
            $client = new \WeChatPay\PaymentService($this->wechatpayConfig);
            try {
                $result = $client->nativePay($params);
                $code_url = $result['code_url'];
            } catch (\Exception $e) {
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
                $config['sub_appid'] = $ctx->order['sub_appid'];
            } else {
                if (($ctx->order['is_applet'] ?? 0) == 1) {
                    $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
                    if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
                    $config['appid'] = $wxinfo['appid'];
                } else {
                    $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
                    if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
                    $config['appid'] = $wxinfo['appid'];
                }
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
        $params = [
            'body'             => $ctx->ordername,
            'out_trade_no'     => $tradeNo,
            'total_fee'        => strval($ctx->order['realmoney'] * 100),
            'spbill_create_ip' => request()->clientip,
            'time_expire' => date('YmdHis', strtotime('+2 hours')),
            'notify_url'       => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'openid'           => $openid,
        ];
        if ($ctx->order['profits'] > 0) {
            $params['profit_sharing'] = 'Y';
        }
        $client = new \WeChatPay\PaymentService($config);
        try {
            $result = $client->jsapiPay($params);
            $jsApiParameters = json_encode($result);
        } catch (\Exception $e) {
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
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        if ($this->channel['appwxa'] > 0 && request()->get('qrcode', '') === '') {
            try {
                $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
                if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (\Exception $e) {
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
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'page', 'page' => 'wxopen'];
        }

        $scene_info = [
            'h5_info' => [
                'type'     => 'Wap',
                'wap_url'  => $siteurl,
                'wap_name' => config_get('sitename'),
            ],
        ];
        $params = [
            'body'             => $ctx->ordername,
            'out_trade_no'     => $tradeNo,
            'total_fee'        => strval($ctx->order['realmoney'] * 100),
            'spbill_create_ip' => request()->clientip,
            'time_expire' => date('YmdHis', strtotime('+2 hours')),
            'notify_url'       => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'scene_info'       => json_encode($scene_info, JSON_UNESCAPED_UNICODE),
        ];
        if ($ctx->order['profits'] > 0) {
            $params['profit_sharing'] = 'Y';
        }
        $client = new \WeChatPay\PaymentService($this->wechatpayConfig);
        try {
            $result = $client->h5Pay($params);
            $redirect_url = $siteurl . 'pay/return/' . $tradeNo . '/';
            $url = $result['mweb_url'] . '&redirect_url=' . urlencode($redirect_url);
            return ['type' => 'jump', 'url' => $url];
        } catch (\Exception $e) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $e->getMessage()];
        }
    }

    //小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $code = request()->get('code', '', 'trim');
        if (empty($code)) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }

        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];
        }
        $config = $this->wechatpayConfig;
        $config['appid'] = $wxinfo['appid'];

        //①、获取用户openid
        try {
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (\Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        //②、统一下单
        $params = [
            'body'             => $ctx->ordername,
            'out_trade_no'     => $tradeNo,
            'total_fee'        => strval($ctx->order['realmoney'] * 100),
            'spbill_create_ip' => request()->clientip,
            'time_expire' => date('YmdHis', strtotime('+2 hours')),
            'notify_url'       => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'openid'           => $openid,
        ];
        if ($ctx->order['profits'] > 0) {
            $params['profit_sharing'] = 'Y';
        }
        $client = new \WeChatPay\PaymentService($config);
        try {
            $jsApiParameters = $client->jsapiPay($params);
            return ['type' => 'json', 'data' => ['code' => 0, 'data' => $jsApiParameters]];
        } catch (\Exception $e) {
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
        $params = [
            'body'             => $ctx->ordername,
            'out_trade_no'     => $tradeNo,
            'total_fee'        => strval($ctx->order['realmoney'] * 100),
            'spbill_create_ip' => request()->clientip,
            'time_expire' => date('YmdHis', strtotime('+2 hours')),
            'notify_url'       => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($ctx->order['profits'] > 0) {
            $params['profit_sharing'] = 'Y';
        }
        $client = new \WeChatPay\PaymentService($config);
        try {
            $result = $client->appPay($params);
            if ($ctx->method === 'app') {
                return ['type' => 'app', 'data' => json_encode($result)];
            }
            $param = [
                'nonceStr'  => $result['noncestr'],
                'package'   => $result['package'],
                'partnerId' => $result['partnerid'],
                'prepayId'  => $result['prepayid'],
                'timeStamp' => $result['timestamp'],
                'sign'      => $result['sign'],
            ];
            $code_url = 'weixin://app/' . $result['appid'] . '/pay/?' . http_build_query($param);
            return ['type' => 'qrcode', 'page' => 'wxpay_h5', 'url' => $code_url];
        } catch (\Exception $e) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $e->getMessage()];
        }
    }

    //付款码支付
    public function scanpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'body'             => $ctx->ordername,
            'out_trade_no'     => $tradeNo,
            'total_fee'        => strval($ctx->order['realmoney'] * 100),
            'spbill_create_ip' => request()->clientip,
            'auth_code'        => $ctx->order['auth_code'],
        ];
        if ($ctx->order['profits'] > 0) {
            $params['profit_sharing'] = 'Y';
        }
        $client = new \WeChatPay\PaymentService($this->wechatpayConfig);
        try {
            $result = $client->microPay($params);
            ($this->markTrustedQueryResult('notify', 'wechatpay-sdk-micropay-success'))(function () use ($tradeNo, $result) {
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
                    ($this->markTrustedQueryResult('notify', 'wechatpay-sdk-order-query'))(function () use ($tradeNo, $result) {
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
        } catch (\Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => $wxinfo['appid'], 'miniProgramId' => '', 'path' => $path]];
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $client = new \WeChatPay\PaymentService($this->wechatpayConfig);
        $result = $client->orderQuery(null, $order['trade_no']);
        return [
            'api_trade_no' => $result['transaction_id'] ?? '',
            'status' => $result['trade_state'] == 'SUCCESS' ? 1 : 0,
            'money' => ($result['total_fee'] ?? 0) / 100,
            'buyer' => $result['openid'] ?? '',
            'endtime' => $result['time_end'] ?? '',
        ];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $isSuccess = true;
        $errmsg = '';
        try {
            $client = new \WeChatPay\PaymentService($this->wechatpayConfig);
            $data = $client->notify();
            if ($data['out_trade_no'] == $ctx->order['trade_no'] && $data['total_fee'] == strval((float) $ctx->order['realmoney'] * 100)) {
                ($this->markTrustedCallback($ctx, 'notify', 'wechatpay-sdk-notify'))(function () use ($ctx, $data) {
                    $this->processNotify($ctx->order, $data['transaction_id'], $data['openid'], null, null, $data['time_end']);
                });
            }
        } catch (\Exception $e) {
            $isSuccess = false;
            $errmsg = $e->getMessage();
        }

        $xml = $client->replyNotify($isSuccess, $errmsg, true);
        return ['type' => 'html', 'data' => $xml];
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'transaction_id' => $order['api_trade_no'],
            'out_refund_no'  => $order['refund_no'],
            'total_fee'      => strval($order['realmoney'] * 100),
            'refund_fee'     => strval($order['refundmoney'] * 100),
        ];
        $client = new \WeChatPay\PaymentService($this->wechatpayConfig);
        try {
            $result = $client->refund($params);
            return ['code' => 0, 'trade_no' => $result['transaction_id'], 'refund_fee' => $result['refund_fee']];
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //关闭订单
    public function close(array $order): array
    {
        $client = new \WeChatPay\PaymentService($this->wechatpayConfig);
        try {
            $client->closeOrder($order['trade_no']);
            return ['code' => 0];
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }
}
