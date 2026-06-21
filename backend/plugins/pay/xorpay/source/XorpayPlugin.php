<?php

declare(strict_types=1);

namespace plugins\payment\xorpay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class XorpayPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //扫码支付
    private function qrcode(PaymentContext $ctx, string $pay_type): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $apiurl = 'https://xorpay.com/api/pay/' . $this->channel['appid'];
        $param = [
            'name' => $ctx->ordername,
            'pay_type' => $pay_type,
            'price' => $ctx->order['realmoney'],
            'order_id' => $tradeNo,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        $param['sign'] = md5($param['name'] . $param['pay_type'] . $param['price'] . $param['order_id'] . $param['notify_url'] . $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($apiurl, $param, $tradeNo) {
            $data = get_curl($apiurl, http_build_query($param));
            if (!$data) throw new Exception('接口请求失败');
            $result = json_decode($data, true);

            if (isset($result['status']) && $result['status'] == 'ok') {
                $this->updateOrder($tradeNo, $result['aoid']);
                return $result['info']['qr'] ?? '';
            } else {
                throw new Exception($result['status'] ?? '返回数据解析失败');
            }
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, 'alipay');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, 'native');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //微信收银台支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (request()->get('d') == '1') {
            $redirect_url = $siteurl . 'pay/return/' . $tradeNo . '/';
        } else {
            $redirect_url = $siteurl . 'pay/ok/' . $tradeNo . '/';
        }

        $apiurl = 'https://xorpay.com/api/cashier/' . $this->channel['appid'];
        $param = [
            'name' => $ctx->ordername,
            'pay_type' => 'jsapi',
            'price' => $ctx->order['realmoney'],
            'order_id' => $tradeNo,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $redirect_url,
        ];
        $param['sign'] = md5($param['name'] . $param['pay_type'] . $param['price'] . $param['order_id'] . $param['notify_url'] . $this->channel['appkey']);

        $html_text = '<form action="' . $apiurl . '" method="post" id="dopay">';
        foreach ($param as $k => $v) {
            $html_text .= "<input type=\"hidden\" name=\"{$k}\" value=\"{$v}\" />\n";
        }
        $html_text .= '<input type="submit" value="正在跳转"></form><script>document.getElementById("dopay").submit();</script>';

        return ['type' => 'html', 'data' => $html_text];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
        return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $postData = request()->post();

        $sign = md5($postData['aoid'] . $postData['order_id'] . $postData['pay_price'] . $postData['pay_time'] . $this->channel['appkey']);

        if (isset($postData['aoid']) && $sign === $postData['sign']) {
            $out_trade_no = $postData['order_id'];
            $api_trade_no = $postData['aoid'];
            $money = (float) $postData['pay_price'];
            $data = json_decode($postData['detail'] ?? '', true);
            $buyer = $data['buyer'] ?? '';

            if ($out_trade_no == $ctx->order['trade_no'] && round($money, 2) == round((float) $ctx->order['realmoney'], 2)) {
                $this->processNotify($ctx->order, $api_trade_no, $buyer);
            }
            return ['type' => 'html', 'data' => 'success'];
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
        if (empty($order['api_trade_no'])) throw new Exception('接口订单号不能为空');
        $apiurl = 'https://xorpay.com/api/query/' . $order['api_trade_no'];
        $data = get_curl($apiurl);
        if (!$data) throw new Exception('接口请求失败');
        $result = json_decode($data, true);
        return [
            'api_trade_no' => $order['api_trade_no'],
            'status' => $result['status'] == 'payed' || $result['status'] == 'fee_error' || $result['status'] == 'success' ? 1 : 0,
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $apiurl = 'https://xorpay.com/api/refund/' . $order['api_trade_no'];
        $param = [
            'price' => $order['refundmoney'],
        ];
        $param['sign'] = md5($param['price'] . $this->channel['appkey']);

        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($data, true);

        if (isset($result['status']) && $result['status'] == 'ok') {
            return ['code' => 0];
        } else {
            return ['code' => -1, 'msg' => $result['info'] ?? '接口返回错误'];
        }
    }
}
