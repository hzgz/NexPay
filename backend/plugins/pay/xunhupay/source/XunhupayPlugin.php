<?php

declare(strict_types=1);

namespace plugins\payment\xunhupay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class XunhupayPlugin extends BasePayment
{
    public function __construct(array $channel)
    {
        parent::__construct($channel);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $typename = $ctx->order['typename'];
        if ($typename == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($typename == 'wxpay') {
            return $this->wxpay($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //通用下单
    private function addOrder(PaymentContext $ctx, string $type): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $params = [
            'version'        => '1.1',
            'trade_order_id' => $tradeNo,
            'payment'        => $type,
            'total_fee'      => $ctx->order['realmoney'],
            'title'          => $ctx->ordername,
            'notify_url'     => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url'     => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        if ($type == 'wechat' && $ctx->isMobile) {
            $params['type'] = 'WAP';
            $params['wap_url'] = request()->host();
            $params['wap_name'] = config_get('sitename');
        }

        $client = new XunhupayClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appurl'] ?? '');
        $result = $client->do_payment($params);
        if ($ctx->isMobile) {
            return ['jump', $result['url']];
        } else {
            $code_url = $client->parseQrcode($result['url_qrcode']);
            return ['qrcode', $code_url];
        }
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            [$type, $code_url] = $this->addOrder($ctx, 'alipay');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }

        if ($type == 'jump') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            [$type, $code_url] = $this->addOrder($ctx, 'wechat');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if ($type == 'jump') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $postData = request()->post();
        if (empty($postData) || !isset($postData['hash']) || !isset($postData['trade_order_id'])) {
            return ['type' => 'html', 'data' => 'data_fail'];
        }

        $client = new XunhupayClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appurl'] ?? '');
        $verify_result = $client->verify($postData);
        if (!$verify_result) {
            return ['type' => 'html', 'data' => 'sign_fail'];
        }

        if ($postData['status'] == 'OD') {
            $out_trade_no = $postData['trade_order_id'];
            $order_id = $postData['open_order_id'];
            $total_fee = $postData['total_fee'];
            $bill_trade_no = $postData['transaction_id'] ?? '';
            if ($out_trade_no == $ctx->order['trade_no'] && round((float) $total_fee, 2) == round((float) $ctx->order['realmoney'], 2)) {
                $this->processNotify($ctx->order, $order_id, null, $bill_trade_no, null, $postData['time']);
            }
            return ['type' => 'html', 'data' => 'success'];
        }
        return ['type' => 'html', 'data' => 'fail'];
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
    }

    public function query(array $order): array
    {
        $client = new XunhupayClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appurl'] ?? '');
        $params = [
            'out_trade_order' => $order['trade_no']
        ];
        $result = $client->query_payment($params);
        $data = $result['data'];
        return [
            'api_trade_no' => $data['open_order_id'],
            'status' => $data['status'] == 'OD' ? 1 : 0,
            'money' => $data['total_amount'],
            'bill_trade_no' => $data['transaction_id'] ?? '',
            'endtime' => $data['paid_date'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $client = new XunhupayClient($this->channel['appid'], $this->channel['appkey'], $this->channel['appurl'] ?? '');
        $params = [
            'open_order_id' => $order['api_trade_no']
        ];
        try {
            $result = $client->do_refund($params);
            return ['code' => 0, 'trade_no' => $result['transaction_id'], 'refund_fee' => $result['refund_fee']];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }
}
