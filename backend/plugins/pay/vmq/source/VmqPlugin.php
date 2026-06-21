<?php

namespace plugins\payment\vmq;

use app\common\PaymentContext;

class VmqPlugin extends \app\common\BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            $paytype = '2';
        } elseif ($ctx->order['typename'] == 'qqpay') {
            $paytype = '4';
        } elseif ($ctx->order['typename'] == 'wxpay') {
            $paytype = '1';
        } elseif ($ctx->order['typename'] == 'bank') {
            $paytype = '3';
        }

        $apiurl = $this->channel['appurl'] . 'createOrder';
        $data = [
            "mchId" => $this->channel['appid'],
            "payId" => $tradeNo,
            "type" => $paytype,
            "price" => $ctx->order['realmoney'],
            "isHtml" => '1',
            "notifyUrl" => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            "returnUrl" => request()->siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        $data["sign"] = md5($data['payId'] . $data['type'] . $data['price'] . $this->channel['appkey']);

        if (is_https() && substr($apiurl, 0, 7) == 'http://') {
            $jump_url = $apiurl . '?' . http_build_query($data);
            return ['type' => 'jump', 'url' => $jump_url];
        } else {
            $html_text = '<form action="' . $apiurl . '" method="post" id="dopay">';
            foreach ($data as $k => $v) {
                $html_text .= "<input type=\"hidden\" name=\"{$k}\" value=\"{$v}\" />\n";
            }
            $html_text .= '<input type="submit" value="正在跳转"></form><script>document.getElementById("dopay").submit();</script>';

            return ['type' => 'html', 'data' => $html_text];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $payId = request()->get('payId'); //商户订单号
        $type = request()->get('type'); //支付方式
        $price = request()->get('price'); //订单金额
        $reallyPrice = request()->get('reallyPrice'); //实际支付金额
        $sign = request()->get('sign'); //校验签名

        if (!$payId || !$sign) return ['type' => 'html', 'data' => 'error_param'];

        $_sign = md5($payId . $type . $price . $reallyPrice . $this->channel['appkey']);
        if ($_sign !== $sign) return ['type' => 'html', 'data' => 'error_sign'];

        $out_trade_no = $payId;
        if ($out_trade_no == $tradeNo && round($price, 2) == round($ctx->order['realmoney'], 2)) {
            $this->processNotify($ctx->order, $out_trade_no);
        }
        return ['type' => 'html', 'data' => 'success'];
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $payId = request()->get('payId'); //商户订单号
        $type = request()->get('type'); //支付方式
        $price = request()->get('price'); //订单金额
        $reallyPrice = request()->get('reallyPrice'); //实际支付金额
        $sign = request()->get('sign'); //校验签名

        if (!$payId || !$sign) return ['type' => 'error', 'data' => '参数不完整'];

        $_sign = md5($payId . $type . $price . $reallyPrice . $this->channel['appkey']);
        if ($_sign !== $sign) return ['type' => 'error', 'data' => '签名校验失败'];

        $out_trade_no = $payId;
        if ($out_trade_no == $tradeNo && round($price, 2) == round($ctx->order['realmoney'], 2)) {
            return ($this->markTrustedCallback($ctx, 'return', 'vmq-signature'))(function () use ($ctx, $out_trade_no) {
                return $this->processReturn($ctx->order, $out_trade_no);
            });
        } else {
            return ['type' => 'error', 'msg' => '订单信息校验失败'];
        }
    }
}
