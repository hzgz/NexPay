<?php

declare(strict_types=1);

namespace plugins\payment\omipay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class OmipayPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->isMobile) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
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
        if ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->isMobile) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    private function getSign(array $param, string $secret_key): string
    {
        $signstr = $param['m_number'] . '&' . $param['timestamp'] . '&' . $param['nonce_str'] . '&' . $secret_key;
        return strtoupper(md5($signstr));
    }

    //二维码通用下单
    private function qrcode(PaymentContext $ctx, string $pay_type): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $apiurl = 'https://www.omipay.com.cn/omipay/api/v2/MakeQROrder';
        $param = [
            'm_number' => $this->channel['appid'],
            'order_name' => $ctx->ordername,
            'currency' => 'CNY',
            'amount' => intval(round($ctx->order['realmoney'] * 100)),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'out_order_no' => $tradeNo,
            'platform' => $pay_type,
            'timestamp' => getMillisecond(),
            'nonce_str' => getSid()
        ];
        $param['sign'] = $this->getSign($param, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($apiurl, $param) {
            $data = get_curl($apiurl . '?' . http_build_query($param));
            if (!$data) throw new Exception('接口请求失败');
            $result = json_decode($data, true);

            if (isset($result['return_code']) && $result['return_code'] == 'SUCCESS') {
                $code_url = $result['qrcode'];
            } elseif (isset($result['error_code'])) {
                throw new Exception('[' . $result['error_code'] . ']' . $result['error_msg']);
            } else {
                throw new Exception('返回数据解析失败');
            }
            return $code_url;
        });
    }

    //JSAPI通用下单
    private function jspay(PaymentContext $ctx): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $apiurl = 'https://www.omipay.com.cn/omipay/api/v2/MakeJSAPIOrder';
        $param = [
            'm_number' => $this->channel['appid'],
            'order_name' => $ctx->ordername,
            'currency' => 'CNY',
            'amount' => intval(round($ctx->order['realmoney'] * 100)),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'redirect_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'out_order_no' => $tradeNo,
            'direct_pay' => 1,
            'show_pc_pay_url' => 0,
            'timestamp' => getMillisecond(),
            'nonce_str' => getSid()
        ];
        $param['sign'] = $this->getSign($param, $this->channel['appkey']);

        return self::lockPayData($tradeNo, function () use ($apiurl, $param) {
            $data = get_curl($apiurl . '?' . http_build_query($param));
            if (!$data) throw new Exception('接口请求失败');
            $result = json_decode($data, true);

            if (isset($result['return_code']) && $result['return_code'] == 'SUCCESS') {
                $pay_url = $result['pay_url'];
            } elseif (isset($result['error_code'])) {
                throw new Exception('[' . $result['error_code'] . ']' . $result['error_msg']);
            } else {
                throw new Exception('返回数据解析失败');
            }
            return $pay_url;
        });
    }

    //支付宝线上支付
    public function alipayol(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->isMobile) {
            $type = 'wap';
        } else {
            $type = 'pc';
        }

        $apiurl = 'https://www.omipay.com.cn/omipay/api/v2/MakeOnlineOrder';
        $param = [
            'm_number' => $this->channel['appid'],
            'order_name' => $ctx->ordername,
            'currency' => 'CNY',
            'amount' => intval(round($ctx->order['realmoney'] * 100)),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'redirect_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'out_order_no' => $tradeNo,
            'type' => $type,
            'timestamp' => getMillisecond(),
            'nonce_str' => getSid()
        ];
        $param['sign'] = $this->getSign($param, $this->channel['appkey']);

        $data = get_curl($apiurl . '?' . http_build_query($param));
        if (!$data) return ['type' => 'error', 'msg' => '接口请求失败'];
        $result = json_decode($data, true);

        if (isset($result['return_code']) && $result['return_code'] == 'SUCCESS') {
            return ['type' => 'jump', 'url' => $result['pay_url']];
        } elseif (isset($result['error_code'])) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！[' . $result['error_code'] . ']' . $result['error_msg']];
        } else {
            return ['type' => 'error', 'msg' => '支付宝下单失败！返回数据解析失败'];
        }
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, 'ALIPAY');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, 'WECHATPAY');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
    }

    //微信公众号支付
    public function wxwappay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->jspay($ctx);
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

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, 'UPI');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '银联云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'json', 'data' => ['return_code' => 'FAIL']];

        $arr['m_number'] = $this->channel['appid'];
        $sign = $this->getSign($arr, $this->channel['appkey']);

        if ($sign === $arr['sign']) {
            if ($arr['return_code'] == 'SUCCESS') {
                $out_trade_no = $arr['out_order_no'];
                $trade_no = $arr['order_no'];
                $total_amount = $arr['total_amount'];
                $end_time = $arr['pay_time'];

                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $trade_no, null, null, null, $end_time);
                }
                return ['type' => 'json', 'data' => ['return_code' => 'SUCCESS']];
            } else {
                return ['type' => 'json', 'data' => ['return_code' => 'FAIL']];
            }
        } else {
            return ['type' => 'json', 'data' => ['return_code' => 'FAIL']];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    //退款
    public function refund(array $order): array
    {
        $apiurl = 'https://www.omipay.com.cn/omipay/api/v2/Refund';
        $param = [
            'm_number' => $this->channel['appid'],
            'order_no' => $order['api_trade_no'],
            'out_refund_no' => $order['refund_no'],
            'amount' => intval(round($order['refundmoney'] * 100)),
            'timestamp' => getMillisecond(),
            'nonce_str' => getSid()
        ];
        $param['sign'] = $this->getSign($param, $this->channel['appkey']);

        $data = get_curl($apiurl . '?' . http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($data, true);

        if (isset($result['return_code']) && $result['return_code'] == 'SUCCESS') {
            $result = ['code' => 0, 'trade_no' => $result['refund_no'], 'refund_fee' => $result['amount']];
        } elseif (isset($result['error_code'])) {
            $result = ['code' => -1, 'msg' => '[' . $result['error_code'] . ']' . $result['error_msg']];
        } else {
            $result = ['code' => -1, 'msg' => '返回数据解析失败'];
        }
        return $result;
    }
}
