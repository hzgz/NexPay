<?php

declare(strict_types=1);

namespace plugins\payment\fubei;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class FubeiPlugin extends BasePayment
{
    public function __construct(array $channel)
    {
        parent::__construct($channel);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->isMobile && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/alipaywap/' . $tradeNo . '/'];
            } elseif ($ctx->mdevice === 'alipay' && in_array('1', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
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

        if ($ctx->method === 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->method === 'applet') {
            return $this->wxplugin($ctx);
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->isMobile && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipaywap/' . $tradeNo . '/'];
            } elseif ($ctx->mdevice === 'alipay' && in_array('1', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } else {
                return $this->wxpay($ctx);
            }
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //下单通用
    private function addOrder(PaymentContext $ctx, string $pay_type, ?string $user_id, ?string $sub_appid = null)
    {
        $tradeNo = $ctx->order['trade_no'];

        $bizContent = [
            'merchant_id' => $this->channel['mchid'],
            'merchant_order_sn' => $tradeNo,
            'pay_type' => $pay_type,
            'total_amount' => $ctx->order['realmoney'],
            'store_id' => $this->channel['appmchid'],
            'user_id' => $user_id,
            'body' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        if ($sub_appid) $bizContent['sub_appid'] = $sub_appid;

        $client = new FubeiClient($this->channel['appid'], $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($client, $bizContent) {
            return $client->execute('fbpay.order.create', $bizContent);
        });
    }

    //支付宝H5下单
    //https://www.yuque.com/51fubei/hq1pfy/zzmg63
    private function alipayH5(PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];

        $bizContent = [
            'merchant_id' => $this->channel['mchid'],
            'merchant_order_sn' => $tradeNo,
            'total_amount' => $ctx->order['realmoney'],
            'store_id' => $this->channel['appmchid'],
            'body' => $ctx->ordername,
            'user_ip' => request()->clientip,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        $client = new FubeiClient($this->channel['appid'], $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($client, $bizContent) {
            return $client->execute('fbpay.order.wap.create', $bizContent);
        });
    }

    //获取微信网页授权url
    private function getWechatAuthUrl(PaymentContext $ctx): string
    {
        $url = request()->siteurl . 'pay/wxjspay/' . $ctx->order['trade_no'] . '/';

        $bizContent = [
            'url' => $url,
            'store_id' => $this->channel['appmchid'],
        ];
        $client = new FubeiClient($this->channel['appid'], $this->channel['appkey']);
        $retData = $client->execute('openapi.agent.merchant.wechat.payment.auth', $bizContent);
        return $retData['authUrl'];
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipaywap/' . $tradeNo . '/';
        } else {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
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
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }

        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;

        try {
            $retData = $this->addOrder($ctx, 'alipay', $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method === 'jsapi') {
            return ['type' => 'jsapi', 'data' => $retData['prepay_id']];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $retData['prepay_id'], 'redirect_url' => $redirect_url]];
    }

    //支付宝H5支付
    public function alipaywap(PaymentContext $ctx): array
    {
        try {
            $retData = $this->alipayH5($ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        $html = $retData['html'];
        if (substr($html, 0, 4) == 'http') {
            return ['type' => 'jump', 'url' => $html];
        }
        return ['type' => 'html', 'data' => $html];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $code_url = request()->siteurl . 'pay/wxjspay/' . $ctx->order['trade_no'] . '/';

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

        if ($this->channel['appwxmp'] > 0) {
            if (!empty($ctx->order['sub_openid'])) {
                if (!empty($ctx->order['sub_appid'])) {
                    $appid = $ctx->order['sub_appid'];
                } else {
                    if ($ctx->order['is_applet'] == 1) {
                        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
                        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
                    } else {
                        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
                        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
                    }
                    $appid = $wxinfo['appid'];
                }
                $openid = $ctx->order['sub_openid'];
            } else {
                $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
                if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
                $appid = $wxinfo['appid'];

                try {
                    $openid = wechat_oauth($wxinfo);
                } catch (Exception $e) {
                    return ['type' => 'error', 'msg' => $e->getMessage()];
                }
            }
        } else {
            if (request()->get('open_id') === null) {
                try {
                    $auth_url = $this->getWechatAuthUrl($ctx);
                    return ['type' => 'jump', 'url' => $auth_url];
                } catch (Exception $e) {
                    return ['type' => 'error', 'msg' => '获取微信网页授权url失败！' . $e->getMessage()];
                }
            }
            $openid = request()->get('open_id');
            $appid = 'wxab36abed3127b34a';
        }

        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        try {
            $retData = $this->addOrder($ctx, 'wxpay', $openid, $appid);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method === 'jsapi') {
            return ['type' => 'jsapi', 'data' => json_encode($retData['sign_package'])];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }

        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => json_encode($retData['sign_package']), 'redirect_url' => $redirect_url]];
    }

    //微信小程序插件支付
    public function wxplugin(PaymentContext $ctx): array
    {
        $appId = 'wx21efb7c54d4729d6';
        try {
            $result = $this->addOrder($ctx, 'wxpay', null, $appId);
            $payinfo = ['appId' => $appId, 'orderSn' => $result['order_sn']];
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'wxplugin', 'data' => $payinfo];
    }

    //微信参数配置
    public function wxconfig()
    {
        $channel = $this->channel;
        $siteurl = request()->siteurl;
        $client = new FubeiClient($channel['appid'], $channel['appkey']);

        if (request()->has('sub_appid', 'post') && request()->has('jsapi_path', 'post')) {
            $bizContent = [
                'store_id' => $channel['appmchid'],
                'sub_appid' => request()->post('sub_appid'),
                'jsapi_path' => request()->post('jsapi_path'),
            ];
            try {
                $retData = $client->execute('fbpay.order.wxconfig', $bizContent);
                $msg = '';
                if (!empty($retData['sub_appid_msg'])) $msg .= $retData['sub_appid_msg'] . '<br/>';
                if (!empty($retData['jsapi_msg'])) $msg .= $retData['jsapi_msg'];
                return $this->showMsg($msg, 1);
            } catch (Exception $e) {
                return $this->showMsg('微信参数配置失败！' . $e->getMessage(), 4);
            }
        }

        $wxinfo = \app\lib\Channel::getWeixin($channel['appwxmp']);

        $bizContent = [
            'store_id' => $channel['appmchid'],
        ];
        try {
            $retData = $client->execute('fbpay.order.wxconfig.query', $bizContent);
            $appid_list = json_decode($retData['appid_list'] ?? '', true);
            $jsapi_path_list = json_decode($retData['jsapi_path_list'] ?? '', true);
            $data = [
                'appid_config_list' => json_decode($appid_list['appid_config_list'] ?? '', true),
                'jsapi_path_list' => json_decode($jsapi_path_list['jsapi_path_list'] ?? '', true),
                'appid' => $wxinfo ? $wxinfo['appid'] : '',
            ];
            foreach (['appid_config_list', 'jsapi_path_list'] as $k) {
                if (!is_array($data[$k])) {
                    $data[$k] = [];
                }
            }
            return view($this->payRoot . 'view/wxconf.html', [
                'channel' => $channel,
                'siteurl' => $siteurl,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return $this->showMsg('微信参数配置查询失败！' . $e->getMessage(), 4);
        }
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, 'unionpay', '');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $client = new FubeiClient($this->channel['appid'], $this->channel['appkey']);
        $verify_result = $client->verify(request()->post());

        if ($verify_result) {
            $data = json_decode(request()->post('data', ''), true);
            if ($data['order_status'] == 'SUCCESS') {
                $out_trade_no = $data['merchant_order_sn'];
                $api_trade_no = $data['order_sn'];
                $money = $data['total_amount'];
                $buyer = $data['user_id'];
                $bill_trade_no = $data['channel_order_sn'];
                $bill_mch_trade_no = $data['ins_order_sn'];
                $end_time = $data['finish_time'];

                if ($out_trade_no == $ctx->order['trade_no'] && round($money, 2) == round($ctx->order['realmoney'], 2)) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                }
                return ['type' => 'html', 'data' => 'success'];
            } else {
                return ['type' => 'html', 'data' => 'status=' . $data['order_status']];
            }
        } else {
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
        $client = new FubeiClient($this->channel['appid'], $this->channel['appkey']);
        $params = [
            'merchant_order_sn' => $order['trade_no'],
        ];
        $result = $client->execute('fbpay.order.query', $params);
        return [
            'api_trade_no' => $result['order_sn'],
            'status' => $result['order_status'] == 'SUCCESS' ? 1 : 0,
            'money' => $result['total_amount'],
            'buyer' => $result['user_id'] ?? '',
            'bill_trade_no' => $result['channel_order_sn'] ?? '',
            'bill_mch_trade_no' => $result['ins_order_sn'] ?? '',
            'endtime' => $result['finish_time'] ?? '',
        ];
    }

    //退款
    public function refund($order): array
    {
        $client = new FubeiClient($this->channel['appid'], $this->channel['appkey']);

        $bizContent = [
            'order_sn' => $order['api_trade_no'],
            'merchant_refund_sn' => $order['refund_no'],
            'refund_amount' => $order['refundmoney'],
        ];
        try {
            $retData = $client->execute('fbpay.order.refund', $bizContent);
            return ['code' => 0, 'trade_no' => $retData['merchant_order_sn'], 'refund_fee' => $retData['refund_amount']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }
}
