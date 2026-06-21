<?php

declare(strict_types=1);

namespace plugins\payment\xxpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class XxpayPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        return ['type' => 'jump', 'url' => '/pay/' . $ctx->order['typename'] . '/' . $tradeNo . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $typename = $ctx->order['typename'];
        return $this->$typename($ctx);
    }

    private function makeSign(array $param, string $key): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $v !== '' && $v !== null) {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'key=' . $key;
        $sign = strtoupper(md5($signstr));
        return $sign;
    }

    //下单通用
    private function addOrder(PaymentContext $ctx, ?string $extra = null): mixed
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $apiurl = $this->channel['appurl'] . 'api/pay/create_order';
        $param = [
            'mchId' => intval($this->channel['appmchid']),
            'appId' => $this->channel['appid'],
            'productId' => intval($this->channel['product_id']),
            'mchOrderNo' => $tradeNo,
            'amount' => intval(round($ctx->order['realmoney'] * 100)),
            'currency' => 'cny',
            'clientIp' => request()->clientip,
            'subject' => $ctx->ordername,
            'body' => $ctx->ordername,
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'returnUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'reqTime' => date('YmdHis'),
            'version' => '1.0',
        ];
        if ($extra) $param['extra'] = $extra;

        $param['sign'] = $this->makeSign($param, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($apiurl, $param) {
            $data = get_curl($apiurl, http_build_query($param));
            if (!$data) throw new Exception('接口请求失败');
            $result = json_decode($data, true);

            if (isset($result['retCode']) && ($result['retCode'] == '0' || $result['retCode'] == 'SUCCESS')) {
                if (isset($result['payMethod'])) {
                    return $result;
                } elseif (isset($result['payParams'])) {
                    return $result['payParams'];
                } else {
                    throw new Exception('返回数据解析失败');
                }
            } else {
                throw new Exception($result['retMsg'] ?? '返回数据解析失败');
            }
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }

        if ($result['payMethod'] == 'formJump' && !empty($result['payUrl']) && substr($result['payUrl'], 0, 4) != 'http') {
            return ['type' => 'html', 'data' => $result['payUrl']];
        } elseif (isset($result['payUrl'])) {
            $code_url = $result['payUrl'];
        } elseif (isset($result['payJumpUrl'])) {
            $code_url = $result['payJumpUrl'];
        } elseif (isset($result['data']['payUrl'])) {
            $code_url = $result['data']['payUrl'];
        } elseif (isset($result['data']['codeUrl'])) {
            $code_url = $result['data']['codeUrl'];
        } else {
            $code_url = $result['codeUrl'];
        }
        return ['type' => 'jump', 'url' => $code_url];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if ($result['payMethod'] == 'formJump' && !empty($result['payUrl']) && substr($result['payUrl'], 0, 4) != 'http') {
            return ['type' => 'html', 'data' => $result['payUrl']];
        } elseif (isset($result['payUrl'])) {
            $code_url = $result['payUrl'];
        } elseif (isset($result['payJumpUrl'])) {
            $code_url = $result['payJumpUrl'];
        } elseif (isset($result['data']['payUrl'])) {
            $code_url = $result['data']['payUrl'];
        } elseif (isset($result['data']['codeUrl'])) {
            $code_url = $result['data']['codeUrl'];
        } else {
            $code_url = $result['codeUrl'];
        }
        return ['type' => 'jump', 'url' => $code_url];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        if ($result['payMethod'] == 'formJump') {
            return ['type' => 'html', 'url' => $result['payUrl']];
        } elseif (isset($result['payUrl'])) {
            $code_url = $result['payUrl'];
        } elseif (isset($result['payJumpUrl'])) {
            $code_url = $result['payJumpUrl'];
        } elseif (isset($result['data']['payUrl'])) {
            $code_url = $result['data']['payUrl'];
        } elseif (isset($result['data']['codeUrl'])) {
            $code_url = $result['data']['codeUrl'];
        } else {
            $code_url = $result['codeUrl'];
        }
        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (request()->post('sign')) {
            $arr = request()->post();
        } elseif (request()->get('sign')) {
            $arr = request()->get();
        } else {
            return ['type' => 'html', 'data' => 'no data'];
        }

        $sign = $this->makeSign($arr, $this->channel['appkey']);

        if ($sign === $arr["sign"]) {
            if ($arr['status'] == '2' || $arr['status'] == '3') {
                $out_trade_no = $arr['mchOrderNo'];
                $api_trade_no = $arr['payOrderId'];
                $money = $arr['amount'];
                $bill_trade_no = $arr['channelOrderNo'] ?? '';

                if ($out_trade_no == $tradeNo && $money == strval($ctx->order['realmoney'] * 100)) {
                    $this->processNotify($ctx->order, $api_trade_no, null, $bill_trade_no);
                }
                return ['type' => 'html', 'data' => 'success'];
            } else {
                return ['type' => 'html', 'data' => 'status=' . $arr['status']];
            }
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $sign = $this->makeSign(request()->get(), $this->channel['appkey']);

        if ($sign === request()->get("sign")) {
            if (request()->get('status') == '2') {
                $out_trade_no = request()->get('mchOrderNo');
                $api_trade_no = request()->get('payOrderId');
                $money = request()->get('amount');
                $bill_trade_no = request()->get('channelOrderNo');

                if ($out_trade_no == $tradeNo && $money == strval($ctx->order['realmoney'] * 100)) {
                    return ($this->markTrustedCallback($ctx, 'return', 'xxpay-signature'))(function () use ($ctx, $api_trade_no, $bill_trade_no) {
                        return $this->processReturn($ctx->order, $api_trade_no, null, $bill_trade_no);
                    });
                } else {
                    return ['type' => 'error', 'msg' => '订单信息校验失败'];
                }
            } else {
                return ['type' => 'page', 'page' => 'return'];
            }
        } else {
            return ['type' => 'error', 'msg' => '签名验证失败'];
        }
    }

    public function query(array $order): array
    {
        $apiurl = $this->channel['appurl'] . 'api/pay/query_order';
        $param = [
            'mchId' => intval($this->channel['appmchid']),
            'mchOrderNo' => $order['trade_no'],
        ];
        $param['sign'] = $this->makeSign($param, $this->channel['appkey']);
        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) throw new Exception('接口请求失败');
        $result = json_decode($data, true);
        if (isset($result['retCode']) && ($result['retCode'] == '0' || $result['retCode'] == 'SUCCESS')) {
            return [
                'api_trade_no' => $result['payOrderId'],
                'status' => $result['status'] == '2' ? 1 : 0,
                'money' => $result['amount'] / 100,
                'bill_trade_no' => $result['channelOrderNo'] ?? '',
                'endtime' => $result['paySuccTime'] ?? '',
            ];
        } else {
            throw new Exception($result['retMsg'] ?? '返回数据解析失败');
        }
    }
}
