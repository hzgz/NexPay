<?php

declare(strict_types=1);

namespace plugins\payment\douyinpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use app\lib\douyinpay\PaymentService;
use app\lib\douyinpay\DouyinOauth;
use Exception;

class DouyinpayPlugin extends BasePayment
{
    private array $douyinpayConfig;

    public function __construct(array $channel)
    {
        parent::__construct($channel);
        $this->douyinpayConfig = $this->getConfig();
    }

    //抖音支付配置文件
    private function getConfig(): array
    {
        return [
            //应用ID
            'appid' => $this->channel['appid'],

            //商户号
            'mchid' => $this->channel['appmchid'],

            //接口加密密钥
            'apikey' => $this->channel['apikey'],

            //「商户API私钥」文件路径
            'merchantPrivateKeyFilePath' => getCertFilePath($this->channel['merchant_key_path'] ?? ''),

            //「商户API证书」的「证书序列号」
            'merchantCertificateSerial' => $this->channel['certserial'],

            //「抖音支付平台证书」文件路径
            'platformCertificateFilePath' => getCertFilePath('douyinpaycert_' . $this->channel['appmchid'] . '.pem', true),
        ];
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->mdevice === 'douyin' && in_array('2', $this->channel['apptype'])) {
            return ['type' => 'jump', 'url' => '/pay/jspay/' . $tradeNo . '/?d=1'];
        } elseif ($ctx->isMobile && in_array('3', $this->channel['apptype'])) {
            return ['type' => 'jump', 'url' => '/pay/h5/' . $tradeNo . '/'];
        } else {
            return ['type' => 'jump', 'url' => '/pay/qrcode/' . $tradeNo . '/'];
        }
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->method == 'app') {
            return $this->apppay($ctx);
        } elseif ($ctx->method == 'jsapi') {
            return $this->jspay($ctx);
        } elseif ($ctx->mdevice === 'douyin' && in_array('2', $this->channel['apptype'])) {
            return ['type' => 'jump', 'url' => $siteurl . 'pay/jspay/' . $tradeNo . '/?d=1'];
        } elseif ($ctx->isMobile && in_array('3', $this->channel['apptype'])) {
            return ['type' => 'jump', 'url' => $siteurl . 'pay/h5/' . $tradeNo . '/'];
        } else {
            return $this->qrcode($ctx);
        }
    }

    //扫码支付
    public function qrcode(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('1', $this->channel['apptype'])) {

            $param = [
                'description' => $ctx->ordername,
                'out_trade_no' => $tradeNo,
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
                $client = new PaymentService($this->douyinpayConfig);
                $result = $client->nativePay($param);
                $code_url = $result['code_url'];
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => '抖音支付下单失败！' . $e->getMessage()];
            }

        } elseif (in_array('2', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/jspay/' . $tradeNo . '/';
        } elseif (in_array('3', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/h5/' . $tradeNo . '/';
        } else {
            return ['type' => 'error', 'msg' => '当前支付通道没有开启的支付方式'];
        }

        if ($ctx->isMobile) {
            if (in_array('1', $this->channel['apptype'])) {
                return ['type' => 'qrcode', 'page' => 'douyinpay_h5', 'url' => $code_url];
            } else {
                return ['type' => 'qrcode', 'page' => 'douyinpay_wap', 'url' => $code_url];
            }
        } else {
            return ['type' => 'qrcode', 'page' => 'douyinpay_qrcode', 'url' => $code_url];
        }
    }

    //JS支付
    public function jspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $config = $this->douyinpayConfig;

        $oauth = new DouyinOauth($this->channel['appid'], $this->channel['appsecret']);
        if (!empty($ctx->order['sub_openid'])) {
            if (!empty($ctx->order['sub_appid'])) {
                $config['appid'] = $ctx->order['sub_appid'];
            }
            $openid = $ctx->order['sub_openid'];
        } else {
            $redirect_uri = $siteurl . 'user/oauth/douyin';
            $state = base64_encode(request()->url());
            $openid = $oauth->get_openid($redirect_uri, $state);
        }

        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        //②、统一下单
        $param = [
            'description' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
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
            $client = new PaymentService($config);
            $result = $client->jsapiPay($param);
            $jsApiParameters = json_encode($result);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '抖音支付下单失败！' . $e->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $jsApiParameters];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }

        $url = rtrim($siteurl, '/').$_SERVER['REQUEST_URI'];
		$sdkConfig = json_encode($oauth->getSdkConfig($url), JSON_UNESCAPED_SLASHES);

        return ['type' => 'page', 'page' => 'douyinpay_jspay', 'data' => ['sdkConfig'=>$sdkConfig, 'jsApiParameters' => $jsApiParameters, 'redirect_url' => $redirect_url]];
    }

    //H5支付
    public function h5(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $param = [
            'description' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
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
            $client = new PaymentService($this->douyinpayConfig);
            $result = $client->h5Pay($param);
            $redirect_url = $siteurl . 'pay/return/' . $tradeNo . '/';
            $url = $result['h5_url'] . '&return_url=' . urlencode($redirect_url);
            return ['type' => 'jump', 'url' => $url];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '抖音支付下单失败！' . $e->getMessage()];
        }
    }

    //APP支付
    public function apppay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $param = [
            'description' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
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
            $client = new PaymentService($this->douyinpayConfig);
            $result = $client->appPay($param);
            return ['type' => 'app', 'data' => json_encode($result)];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '抖音支付下单失败！' . $e->getMessage()];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type'=>'page','page'=>'return'];
    }

    //异步回调
    public function notify(PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];

        try {
            $client = new PaymentService($this->douyinpayConfig);
            $data = $client->notify();
        } catch (Exception $e) {
            $json = $client->replyNotify(false, $e->getMessage(), true);
            return ['type' => 'json', 'data' => $json, 'code' => 499];
        }

        if ($data['trade_state'] == 'SUCCESS') {
            if ($data['out_trade_no'] == $tradeNo) {
                ($this->markTrustedCallback($ctx, 'notify', 'douyinpay-sdk-notify'))(function () use ($ctx, $data) {
                    $this->processNotify($ctx->order, $data['transaction_id'], $data['payer']['openid'], null, null, $data['success_time']);
                });
            }
        }
        $json = $client->replyNotify(true, '', true);
        return ['type' => 'json', 'data' => $json];
    }

    public function query(array $order): array
    {
        $client = new PaymentService($this->douyinpayConfig);
        $data = $client->orderQuery(null, $order['trade_no']);
        return [
            'api_trade_no' => $data['transaction_id'],
            'status' => $data['trade_state'] == 'SUCCESS' ? 1 : 0,
            'money' => $data['amount']['total'] / 100,
            'buyer' => $data['payer']['openid'],
            'endtime' => $data['success_time'],
        ];
    }

    //退款
    public function refund($order): array
    {
        $param = [
            'transaction_id' => $order['api_trade_no'],
            'out_refund_no' => $order['refund_no'],
            'notify_url' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
            'amount' => [
                'refund' => intval(round($order['refundmoney'] * 100)),
                'total' => intval(round($order['realmoney'] * 100)),
                'currency' => 'CNY'
            ]
        ];

        try {
            $client = new PaymentService($this->douyinpayConfig);
            $result = $client->refund($param);
            $result = ['code' => 0, 'trade_no' => $result['refund_id'], 'refund_fee' => $result['amount']['refund'] / 100];
        } catch (Exception $e) {
            $result = ['code' => -1, 'msg' => $e->getMessage()];
        }
        return $result;
    }

    //退款回调
    public function refundnotify(PaymentContext $ctx): array
    {
        try {
            $client = new PaymentService($this->douyinpayConfig);
            $data = $client->notify();
        } catch (Exception $e) {
            $json = $client->replyNotify(false, $e->getMessage(), true);
            return ['type' => 'json', 'data' => $json, 'code' => 499];
        }

        $status = $data['refund_status'] == 'SUCCESS' ? 1 : (($data['refund_status'] ?? '') === 'ABNORMAL' ? 2 : 0);
        ($this->markTrustedCallback($ctx, 'refundnotify', 'douyinpay-sdk-notify'))(function () use ($data, $status) {
            $this->processRefund(
                $data['out_refund_no'] ?? '',
                $status,
                $status === 2 ? (string)($data['message'] ?? 'douyin refund failed') : '',
                $data['refund_id'] ?? '',
                isset($data['amount']['refund']) ? $data['amount']['refund'] / 100 : null,
                $data['success_time'] ?? ''
            );
        });
        $json = $client->replyNotify(true, '', true);
        return ['type' => 'json', 'data' => $json];
    }

    //关闭订单
    public function close($order): array
    {
        try {
            $client = new PaymentService($this->douyinpayConfig);
            $client->closeOrder($order['trade_no']);
            $result = ['code' => 0];
        } catch (Exception $e) {
            $result = ['code' => -1, 'msg' => $e->getMessage()];
        }
        return $result;
    }
}
