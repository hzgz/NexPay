<?php

declare(strict_types=1);

namespace plugins\payment\paypro;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class PayproPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        return ['type' => 'jump', 'url' => '/pay/qrcode/' . $tradeNo . '/?type=' . $ctx->order['typename']];
    }

    public function mapi(PaymentContext $ctx): array
    {
        return $this->qrcode($ctx);
    }

    private function make_sign(array $param, string $key): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $k != "sign_type" && $v !== null && $v !== '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'key=' . $key;
        $sign = strtoupper(md5($signstr));
        return $sign;
    }

    //创建订单
    private function addOrder(PaymentContext $ctx, string $pay_type): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $apiurl = $this->channel['appurl'] . '/openapi/pay/create';
        $param = [
            'pid' => $this->channel['appid'],
            'paytype_code' => $pay_type,
            'out_trade_no' => $tradeNo,
            'total_amount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'client_ip' => request()->clientip,
            'timestamp' => time(),
        ];
        $param['sign'] = $this->make_sign($param, $this->channel['appkey']);
        $param['sign_type'] = 'MD5';

        return self::lockPayData($tradeNo, function () use ($apiurl, $param) {
            $data = get_curl($apiurl, http_build_query($param));
            if (!$data) throw new Exception('接口请求失败');
            $result = json_decode($data, true);
            if (isset($result['code']) && $result['code'] == 1) {
                return $result['data']['pay_url'];
            } else {
                throw new Exception($result['msg'] ?? '返回数据解析失败');
            }
        });
    }

    public function qrcode(PaymentContext $ctx): array
    {
        $pay_type = request()->get('type', '');
        try {
            $url = $this->addOrder($ctx, $pay_type);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $postData = request()->post();

        $sign = $this->make_sign($postData, $this->channel['appkey']);

        if ($sign === $postData["sign"]) {
            if ($postData['trade_status'] == 'TRADE_SUCCESS') {
                $out_trade_no = $postData['out_trade_no'];
                $api_trade_no = $postData['trade_no'];
                $money = $postData['total_amount'];
                $bill_trade_no = $postData['transaction_id'];

                if ($out_trade_no == $ctx->order['trade_no'] && round((float)$money, 2) == round((float)$ctx->order['realmoney'], 2)) {
                    $this->processNotify($ctx->order, $api_trade_no, null, $bill_trade_no);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $apiurl = $this->channel['appurl'] . '/openapi/pay/query';
        $param = [
            'pid' => $this->channel['appid'],
            'out_trade_no' => $order['trade_no'],
            'timestamp' => time(),
        ];
        $param['sign'] = $this->make_sign($param, $this->channel['appkey']);
        $param['sign_type'] = 'MD5';
        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) throw new Exception('接口请求失败');
        $result = json_decode($data, true);
        if (isset($result['code']) && $result['code'] == 1) {
            $data = $result['data'];
            return [
                'api_trade_no' => $data['trade_no'],
                'status' => $data['trade_status'] == 'TRADE_SUCCESS' ? 1 : 0,
                'money' => $data['total_amount'],
            ];
        } else {
            throw new Exception($result['msg'] ?? '返回数据解析失败');
        }
    }
    //退款
    public function refund(array $order): array
    {
        $apiurl = $this->channel['appurl'] . '/openapi/pay/refund';
        $param = [
            'pid' => $this->channel['appid'],
            'trade_no' => $order['api_trade_no'],
            'refund_amount' => $order['refundmoney'],
            'timestamp' => time(),
        ];
        $param['sign'] = $this->make_sign($param, $this->channel['appkey']);
        $param['sign_type'] = 'MD5';
        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($data, true);

        if (isset($result['code']) && $result['code'] == 1) {
            return ['code' => 0];
        } else {
            return ['code' => -1, 'msg' => $result['msg'] ?? '接口返回错误'];
        }
    }
}
