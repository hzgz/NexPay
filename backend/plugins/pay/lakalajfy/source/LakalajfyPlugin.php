<?php

declare(strict_types=1);

namespace plugins\payment\lakalajfy;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class LakalajfyPlugin extends BasePayment
{
    public function __construct(array $channel)
    {
        parent::__construct($channel);
    }

    public function submit(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => '/pay/' . $ctx->order['typename'] . '/' . $ctx->order['trade_no'] . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $typename = $ctx->order['typename'];
        return $this->$typename($ctx);
    }

    //支付宝下单
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->createQrcode($ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url, 'expire' => strtotime($ctx->order['addtime']) + 300];
        }
    }

    //微信下单
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->createQrcode($ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url, 'expire' => strtotime($ctx->order['addtime']) + 300];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url, 'expire' => strtotime($ctx->order['addtime']) + 300];
        }
    }

    //云闪付下单
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->createQrcode($ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }
        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url, 'expire' => strtotime($ctx->order['addtime']) + 300];
    }

    private function createQrcode(PaymentContext $ctx): string
    {
        $channel = $this->channel;
        $tradeNo = $ctx->order['trade_no'];
        // 金额
        $amount = $ctx->order['realmoney'];
        $url = 'https://jfyconsole.lakala.com/order/api/cashier/pay';
        $orderData = [
            // 商户ID
            'merchId' => $channel['appurl'],
            // 金额
            'tradeAmount' => $amount,
            // 备注
            'remark' => $channel['appmchid'],
            'orderTemplateData' => [
                [
                    'key' => 1747822239290,
                    'type' => 'number',
                    'index' => 0,
                    'label' => '支付金额',
                    'value' => $amount,
                    'origin' => 'number17478222392900',
                    'options' => [
                        'label' => '支付金额',
                        'content' => $amount,
                        'required' => true,
                        'labelAlign' => '',
                    ],
                    'displayName' => '金额类型',
                    'formItemFlag' => false,
                    'settingsTitle' => '金额类型设置',
                    'marginLeftRight' => 10,
                    'marginTopBottom' => 5,
                    'cashierTemplateName' => $channel['appid'],
                    'state' => true,
                ],
            ],
        ];
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.198 Safari/537.36',
            'Content-Type: application/json;charset=utf-8'
        ];
        $response = get_curl($url, json_encode($orderData), 0, 0, 0, 0, 0, $headers);
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true) ?: [];

        if (isset($result['code']) && $result['code'] == 200) {
            if (empty($result['data']['payUrl'])) {
                throw new \Exception('未返回支付地址');
            }
            $paysurl = $result['data']['payUrl'];
            // 开始截取
            $paysurl2 = parse_url($paysurl, PHP_URL_QUERY);
            $paysurl3 = urldecode($paysurl2);
            parse_str($paysurl3, $paysurl4);
            $paysurl5 = $paysurl4['payOrderNo'];
            // 结束截取
            $this->updateOrder($tradeNo, $paysurl5);
            // 返回支付链接
            return $paysurl;
        } elseif(isset($result['msg'])) {
            throw new \Exception($result['msg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    // 状态监控-查单
    public function queryOrder(string $payOrderNo): array
    {
        $channel = $this->channel;
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.198 Safari/537.36',
            'Content-Type: application/json;charset=utf-8'
        ];
        if (!empty($channel['appsecret'])) {
            $headers[] = 'X-FORWARDED-FOR: ' . $channel['appsecret'];
            $headers[] = 'CLIENT-IP: ' . $channel['appsecret'];
        }
        $url = 'https://payment.lakala.com/m/ccss/counter/order/query';
        $params = [
            'reqTime' => date('YmdHis'),
            'version' => '1.0',
            'reqData' => [
                'channelId' => '95',
                'payOrderNo' => $payOrderNo,
                'merchantNo' => $channel['appkey'],
            ],
        ];
        $response = get_curl($url, json_encode($params), 0, 0, 0, 0, 0, $headers);
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true) ?: [];
        if (isset($result['code']) && $result['code'] == '000000') {
            return $result['respData'];
        } elseif(isset($result['msg'])) {
            throw new \Exception($result['msg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    public function query(array $order): array
    {
        $result = $this->queryOrder($order['api_trade_no']);
        return [
            'api_trade_no' => $result['payOrderNo'],
            'status' => $result['orderStatus'] == 2 ? 1 : 0,
            'money' => $result['totalAmount'] / 100,
            'buyer' => $result['orderTradeInfoList'][0]['userId2'] ?? null,
            'bill_trade_no' => $result['orderTradeInfoList'][0]['tradeNo'] ?? null,
            'bill_mch_trade_no' => $result['orderTradeInfoList'][0]['accTradeNo'] ?? null,
        ];
    }
}
