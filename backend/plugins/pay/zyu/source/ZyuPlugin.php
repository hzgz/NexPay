<?php

declare(strict_types=1);

namespace plugins\payment\zyu;

use app\common\PaymentContext;
use app\common\BasePayment;

class ZyuPlugin extends BasePayment
{
    private function make_sign(array $param, string $key): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $v != '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'key=' . $key;
        $sign = strtoupper(md5($signstr));
        return $sign;
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($this->channel['appswitch'] >= 1) {
            return ['type' => 'jump', 'url' => '/pay/qrcode/' . $tradeNo . '/?type=' . $ctx->order['typename']];
        }

        $apiurl = $this->channel['appurl'];
        $data = [
            "pay_memberid" => $this->channel['appid'],
            "pay_orderid" => $tradeNo,
            "pay_amount" => (float)$ctx->order['realmoney'],
            "pay_applydate" => date("Y-m-d H:i:s"),
            "pay_bankcode" => $this->channel['appmchid'],
            "pay_notifyurl" => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            "pay_callbackurl" => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        $data["pay_md5sign"] = $this->make_sign($data, $this->channel['appkey']);
        $data["pay_productname"] = $ctx->ordername;

        $html_text = '<form action="' . $apiurl . '" method="post" id="dopay">';
        foreach ($data as $k => $v) {
            $html_text .= "<input type=\"hidden\" name=\"{$k}\" value=\"{$v}\" />\n";
        }
        $html_text .= '<input type="submit" value="正在跳转"></form><script>document.getElementById("dopay").submit();</script>';

        return ['type' => 'html', 'data' => $html_text];
    }

    //通用下单
    public function qrcode(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $apiurl = $this->channel['appurl'];
        $data = [
            "pay_memberid" => $this->channel['appid'],
            "pay_orderid" => $tradeNo,
            "pay_amount" => (float)$ctx->order['realmoney'],
            "pay_applydate" => date("Y-m-d H:i:s"),
            "pay_bankcode" => $this->channel['appmchid'],
            "pay_notifyurl" => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            "pay_callbackurl" => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        $data["pay_md5sign"] = $this->make_sign($data, $this->channel['appkey']);
        $data["pay_productname"] = $ctx->ordername;
        $res = get_curl($apiurl, http_build_query($data));
        if (!$res) return ['type' => 'error', 'msg' => '接口请求失败'];
        $result = json_decode($res, true);
        if (isset($result['status']) && ($result['status'] == 200 || $result['status'] == 'success' || $result['status'] == '1') || isset($result['code']) && $result['code'] == 200) {
            if (isset($result['data'])) {
                $code_url = $result['data'];
                if (is_array($code_url)) $code_url = $result['data']['payUrl'];
            } elseif (isset($result['payurl'])) {
                $code_url = $result['payurl'];
            } elseif (isset($result['payUrl'])) {
                $code_url = $result['payUrl'];
            } elseif (isset($result['pay_url'])) {
                $code_url = $result['pay_url'];
            } else {
                return ['type' => 'error', 'msg' => '获取支付链接失败'];
            }
            $type = request()->get('type', '');
            if ($this->channel['appswitch'] == 2) {
                if ($type == 'alipay') {
                    return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
                } elseif ($type == 'wxpay') {
                    if ($ctx->isMobile) {
                        return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
                    } else {
                        return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
                    }
                } elseif ($type == 'qqpay') {
                    if ($ctx->mdevice === 'qq') {
                        return ['type' => 'jump', 'url' => $code_url];
                    } elseif ($ctx->isMobile && !request()->get('qrcode')) {
                        return ['type' => 'qrcode', 'page' => 'qqpay_wap', 'url' => $code_url];
                    } else {
                        return ['type' => 'qrcode', 'page' => 'qqpay_qrcode', 'url' => $code_url];
                    }
                } elseif ($type == 'bank') {
                    return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
                }
            } else {
                return ['type' => 'jump', 'url' => $code_url];
            }
        } else {
            return ['type' => 'error', 'msg' => '创建订单失败！' . ($result['msg'] ?? '返回数据解析失败')];
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $data = [
            "memberid" => request()->post("memberid"), // 商户ID
            "orderid" => request()->post("orderid"), // 订单号
            "amount" => request()->post("amount"), // 交易金额
            "datetime" => request()->post("datetime"), // 交易时间
            "transaction_id" => request()->post("transaction_id"), // 流水号
            "returncode" => request()->post("returncode")
        ];

        $sign = $this->make_sign($data, $this->channel['appkey']);

        if ($sign === request()->post("sign")) {
            if ($data["returncode"] == "00") {
                $out_trade_no = $data['orderid'];
                $trade_no = $data['transaction_id'];
                if ($out_trade_no == $ctx->order['trade_no'] && round((float)$data["amount"], 2) == round((float)$ctx->order['realmoney'], 2)) {
                    $this->processNotify($ctx->order, $trade_no);
                }
            }

            return ['type' => 'html', 'data' => 'OK'];
        } else {
            return ['type' => 'html', 'data' => 'FAIL'];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        if (!request()->get("returncode")) {
            return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
        }

        $data = [
            "memberid" => request()->get("memberid"), // 商户ID
            "orderid" => request()->get("orderid"), // 订单号
            "amount" => request()->get("amount"), // 交易金额
            "datetime" => request()->get("datetime"), // 交易时间
            "transaction_id" => request()->get("transaction_id"), // 流水号
            "returncode" => request()->get("returncode")
        ];

        $sign = $this->make_sign($data, $this->channel['appkey']);

        if ($sign === request()->get("sign")) {
            if ($data["returncode"] == "00") {
                $out_trade_no = $data['orderid'];
                $trade_no = $data['transaction_id'];
                if ($out_trade_no == $ctx->order['trade_no'] && round((float)$data["amount"], 2) == round((float)$ctx->order['realmoney'], 2)) {
                    return ($this->markTrustedCallback($ctx, 'return', 'zyu-signature'))(function () use ($ctx, $trade_no) {
                        return $this->processReturn($ctx->order, $trade_no);
                    });
                } else {
                    return ['type' => 'error', 'msg' => '订单信息校验失败'];
                }
            } else {
                return ['type' => 'error', 'msg' => 'returncode=' . $data["returncode"]];
            }
        } else {
            return ['type' => 'error', 'msg' => '验证失败！'];
        }
    }
}
