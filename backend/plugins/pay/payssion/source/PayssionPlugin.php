<?php

declare(strict_types=1);

namespace plugins\payment\payssion;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class PayssionPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->order['typename'] == 'alipay') {
            $pm_id = 'alipay_cn';
        } elseif ($ctx->order['typename'] == 'wxpay') {
            $pm_id = 'tenpay_cn';
        } elseif ($ctx->order['typename'] == 'bank') {
            $pm_id = 'unionpay_cn';
        } else {
            return ['type' => 'error', 'msg' => '不支持的支付方式'];
        }

        $apiurl = 'https://www.payssion.com/payment/create.html';
        $data = [
            'api_key' => $this->channel['appid'],
            'pm_id' => $pm_id,
            'amount' => $ctx->order['realmoney'],
            'currency' => 'CNY',
            'description' => $ctx->ordername,
            'order_id' => $tradeNo,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];

        $msg = implode('|', [$data['api_key'], $data['pm_id'], $data['amount'], $data['currency'], $data['order_id'], $this->channel['appkey']]);
        $data['api_sig'] = md5($msg);

        $html_text = '<form action="' . $apiurl . '" method="post" id="dopay">';
        foreach ($data as $k => $v) {
            $html_text .= "<input type=\"hidden\" name=\"{$k}\" value='{$v}' />\n";
        }
        $html_text .= '<input type="submit" value="正在跳转"></form><script>document.getElementById("dopay").submit();</script>';

        return ['type' => 'html', 'data' => $html_text];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $postData = request()->post();

        if (!isset($postData['order_id']) || !isset($postData['notify_sig'])) {
            return ['type' => 'html', 'data' => 'error:data'];
        }

        $msg = implode('|', [$this->channel['appid'], $postData['pm_id'], $postData['amount'], $postData['currency'], $postData['order_id'], $postData['state'], $this->channel['appkey']]);
        $sign = md5($msg);

        if ($sign === $postData['notify_sig']) {
            if ($postData['state'] == 'completed') {
                $out_trade_no = $postData['order_id'];
                $api_trade_no = $postData['transaction_id'];
                if ($out_trade_no == $ctx->order['trade_no'] && round((float) $postData['amount'], 2) == round((float) $ctx->order['realmoney'], 2)) {
                    $this->processNotify($ctx->order, $api_trade_no);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'error:sign'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $apiurl = 'https://www.payssion.com/api/v1/payment/details';
        $data = [
            'api_key' => $this->channel['appid'],
            'order_id' => $order['trade_no'],
        ];
        $msg = implode('|', [$data['api_key'], $data['order_id'], $this->channel['appkey']]);
        $data['api_sig'] = md5($msg);
        $res = get_curl($apiurl, http_build_query($data));
        if (!$res) throw new Exception('接口请求失败');
        $result = json_decode($res, true);
        if (isset($result['result_code']) && $result['result_code'] == 200) {
            $data = $result['transaction'];
            return [
                'api_trade_no' => $data['transaction_id'],
                'status' => $data['state'] == 'completed' ? 1 : 0,
                'money' => $data['amount'],
            ];
        } else {
            throw new \Exception($result['description'] ?? '返回数据解析失败');
        }
    }

    //退款
    public function refund(array $order): array
    {
        $apiurl = 'https://www.payssion.com/api/v1/refunds';
        $data = [
            'api_key' => $this->channel['appid'],
            'transaction_id' => $order['api_trade_no'],
            'amount' => $order['refundmoney'],
            'currency' => 'CNY',
            'track_id' => $order['refund_no'],
        ];
        $msg = implode('|', [$data['api_key'], $data['transaction_id'], $data['amount'], $data['currency'], $this->channel['appkey']]);
        $data['api_sig'] = md5($msg);
        $res = get_curl($apiurl, http_build_query($data));
        if (!$res) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($res, true);

        if (isset($result['result_code']) && $result['result_code'] == 200) {
            return ['code' => 0, 'trade_no' => $result['refund']['transaction_id'], 'refund_fee' => $result['refund']['amount']];
        } elseif (isset($result['description'])) {
            return ['code' => -1, 'msg' => $result['description']];
        } else {
            return ['code' => -1, 'msg' => '未知错误'];
        }
    }
}
