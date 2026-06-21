<?php

declare(strict_types=1);

namespace plugins\payment\heepaybank;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class HeepaybankPlugin extends BasePayment
{
    private function createClient(): HeepayClient
    {
        return new HeepayClient(
            $this->channel['appid'],
            $this->channel['appkey'],
            $this->channel['appsecret']
        );
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        return $this->bank($ctx);
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        $localurl = config_get('localurl');
        $tradeNo = $ctx->order['trade_no'];

        $user_id = cookie('heepay_user_id');
        if (empty($user_id)) {
            $user_id = substr(getSid(), 0, 10);
            cookie('heepay_user_id', $user_id, ['expire' => 3600 * 24 * 365, 'path' => '/']);
        }

        $params = [
            'out_trade_no' => $tradeNo,
            'out_trade_time' => date('Y-m-d H:i:s'),
            'pay_amt' => $ctx->order['realmoney'],
            'pay_type' => '63',
            'notify_url' => $localurl . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'payer_info' => [
                'merch_user_id' => $user_id,
            ],
            'goods_info' => [
                'goods_name' => $ctx->ordername,
            ],
            'scene_info' => [
                'from_user_ip' => request()->clientip,
            ]
        ];
        if (!empty($this->channel['appmchid'])) $params['sub_merch_id'] = $this->channel['appmchid'];

        $client = $this->createClient();
        try {
            $result = $client->submit('heepay.bank.apply.page.pay', $params);
            return ['type' => 'jump', 'url' => $result['redirect_uri']];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '快捷支付下单失败！' . $ex->getMessage()];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $client = $this->createClient();
        $data = $client->notify(request()->get());

        if ($data) {
            if ($data['status'] == 'SUCCESS') {
                $out_trade_no = $data['agent_bill_id'];
                $api_trade_no = $data['hy_bill_no'];
                $money = $data['pay_amt'];
                $end_time = $data['hy_deal_time'];
                if ($out_trade_no == $tradeNo) {
                    $this->processNotify($ctx->order, $api_trade_no, null, null, null, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'ok'];
        } else {
            return ['type' => 'html', 'data' => 'error'];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $client = $this->createClient();
        $verify_result = $client->verifySign(request()->get());

        if ($verify_result) {
            if (request()->get('status') == 'SUCCESS') {
                $out_trade_no = request()->get('out_trade_no');
                $api_trade_no = request()->get('trade_no');
                $money = request()->get('pay_amt');
                if ($out_trade_no == $tradeNo) {
                    return ($this->markTrustedCallback($ctx, 'return', 'heepaybank-signature'))(function () use ($ctx, $api_trade_no) {
                        return $this->processReturn($ctx->order, $api_trade_no);
                    });
                } else {
                    return ['type' => 'error', 'msg' => '订单信息校验失败'];
                }
            } else {
                return ['type' => 'error', 'msg' => 'status=' . request()->get('status')];
            }
        } else {
            return ['type' => 'error', 'msg' => '验证失败！'];
        }
    }

    //退款
    public function refund(array $order): array
    {
        $apiurl = 'https://pay.heepay.com/API/Payment/PaymentRefund.aspx';
        if (round((float) $order['refundmoney'], 2) == round((float) $order['realmoney'], 2)) {
            $param = [
                'version' => '1',
                'agent_id' => $this->channel['appid'],
                'agent_bill_id' => $order['trade_no'],
                'notify_url' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
                'sign_type' => 'MD5',
            ];
            $signstr = 'agent_bill_id=' . $param['agent_bill_id'] . '&agent_id=' . $param['agent_id'] . '&key=' . $this->channel['appurl'] . '&notify_url=' . $param['notify_url'] . '&version=' . $param['version'];
        } else {
            $refund_details = $order['trade_no'] . ',' . $order['refundmoney'] . ',' . $order['refund_no'];
            $param = [
                'version' => '1',
                'agent_id' => $this->channel['appid'],
                'refund_details' => $refund_details,
                'notify_url' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
                'sign_type' => 'MD5',
            ];
            $signstr = 'agent_id=' . $param['agent_id'] . '&key=' . $this->channel['appurl'] . '&notify_url=' . $param['notify_url'] . '&refund_details=' . $param['refund_details'] . '&version=' . $param['version'];
        }
        $param['sign'] = md5(strtolower($signstr));

        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $data = mb_convert_encoding($data, 'UTF-8', 'GBK');
        $result = json_decode(json_encode(simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA)), true);

        if (isset($result['ret_code']) && $result['ret_code'] == '0000') {
            return ['code' => 0];
        } else {
            return ['code' => -1, 'msg' => $result["ret_msg"] ?? '返回内容解析失败'];
        }
    }

    //退款异步回调
    public function refundnotify(PaymentContext $ctx): array
    {
        $signstr = 'agent_id=' . request()->get('agent_id') . '&hy_bill_no=' . request()->get('hy_bill_no') . '&agent_bill_id=' . request()->get('agent_bill_id') . '&agent_refund_bill_no=' . request()->get('agent_refund_bill_no') . '&refund_amt=' . request()->get('refund_amt') . '&refund_status=' . request()->get('refund_status') . '&hy_deal_time=' . request()->get('hy_deal_time') . '&key=' . $this->channel['appsecret'];
        $sign = md5(strtolower($signstr));

        if ($sign === request()->get('sign')) {
            $status = request()->get('refund_status') === '1' ? 1 : 2;
            ($this->markTrustedCallback($ctx, 'refundnotify', 'heepaybank-signature'))(function () use ($status) {
                $this->processRefund(
                    request()->get('agent_refund_bill_no') ?: request()->get('agent_bill_id'),
                    $status,
                    $status === 2 ? 'heepaybank refund failed' : '',
                    request()->get('hy_bill_no'),
                    ((float)request()->get('refund_amt', 0)) / 100
                );
            });
            return ['type' => 'html', 'data' => 'ok'];
        } else {
            return ['type' => 'html', 'data' => 'error'];
        }
    }
}
