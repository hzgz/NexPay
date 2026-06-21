<?php

declare(strict_types=1);

namespace plugins\payment\ltzf;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class LtzfPlugin extends BasePayment
{
    const API_URL = 'https://api.ltzf.cn';

    private function makeSign(array $param, array $name, string $key): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if (in_array($k, $name) && $v !== null && $v !== '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'key=' . $key;
        return strtoupper(md5($signstr));
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
        if ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            return $this->wxpay($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //通用创建订单
    private function addOrder(PaymentContext $ctx, string $path): mixed
    {
        $tradeNo = $ctx->order['trade_no'];

        $param = [
            'mch_id' => $this->channel['appid'],
            'out_trade_no' => $tradeNo,
            'total_fee' => $ctx->order['realmoney'],
            'body' => $ctx->ordername,
            'timestamp' => time(),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => request()->siteurl . 'pay/ok/' . $tradeNo . '/',
            'quit_url' => request()->siteurl . 'pay/error/' . $tradeNo . '/',
        ];
        $sign_param = ['mch_id', 'out_trade_no', 'total_fee', 'body', 'timestamp', 'notify_url'];
        $param['sign'] = $this->makeSign($param, $sign_param, $this->channel['appkey']);

        $response = get_curl(self::API_URL . $path, http_build_query($param));
        $result = json_decode($response, true);

        if (isset($result["code"]) && $result["code"] == 0) {
            return $result['data'];
        } else {
            throw new Exception($result["msg"] ?? '返回数据解析失败');
        }
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        if (in_array('2', $this->channel['apptype']) && $ctx->isMobile) {
            try {
                $result = $this->addOrder($ctx, '/api/alipay/h5');
                $h5_url = $result['h5_url'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'jump', 'url' => $h5_url];
        } else {
            try {
                $code_img_url = $this->addOrder($ctx, '/api/alipay/native');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
            }
            $code_url = 'data:image/png;base64,' . base64_encode(get_curl($code_img_url));
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        if (in_array('3', $this->channel['apptype']) && $ctx->mdevice === 'wechat') {
            try {
                $result = $this->addOrder($ctx, '/api/wxpay/jsapi_convenient');
                $jump_url = $result['order_url'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'jump', 'url' => $jump_url];
        } elseif (in_array('2', $this->channel['apptype']) && $ctx->isMobile && $ctx->mdevice !== 'wechat') {
            try {
                $jump_url = $this->addOrder($ctx, '/api/wxpay/jump_h5');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
            return ['type' => 'jump', 'url' => $jump_url];
        } elseif (in_array('1', $this->channel['apptype'])) {
            try {
                $result = $this->addOrder($ctx, '/api/wxpay/native');
                $code_url = $result['code_url'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        } elseif (in_array('3', $this->channel['apptype'])) {
            try {
                $result = $this->addOrder($ctx, '/api/wxpay/jsapi_convenient');
                $code_url = $result['order_url'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        }
        if ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $sign_param = ['code', 'timestamp', 'mch_id', 'order_no', 'out_trade_no', 'pay_no', 'total_fee'];
        $sign = $this->makeSign(request()->post(), $sign_param, $this->channel['appkey']);

        if ($sign === request()->post("sign")) {
            if (request()->post("code") == '0') {
                $out_trade_no = request()->post("out_trade_no");
                $trade_no = request()->post("order_no");
                $buyer = request()->post("openid");
                $bill_trade_no = request()->post("pay_no");
                $end_time = request()->post("success_time");

                if ($out_trade_no == $tradeNo) {
                    $this->processNotify($ctx->order, $trade_no, $buyer, $bill_trade_no, null, $end_time);
                }
                return ['type' => 'html', 'data' => 'SUCCESS'];
            } else {
                return ['type' => 'html', 'data' => 'FAIL'];
            }
        } else {
            return ['type' => 'html', 'data' => 'FAIL'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $param = [
            'mch_id' => $this->channel['appid'],
            'out_trade_no' => $order['trade_no'],
            'timestamp' => time(),
        ];
        $sign_param = ['mch_id', 'out_trade_no', 'timestamp'];
        $param['sign'] = $this->makeSign($param, $sign_param, $this->channel['appkey']);

        $response = get_curl(self::API_URL . '/api/wxpay/get_pay_order', http_build_query($param));
        $result = json_decode($response, true);

        if (isset($result["code"]) && $result["code"] == 0) {
            return [
                'api_trade_no' => $result['data']['order_no'],
                'status' => $result['data']['pay_status'],
                'money' => $result['data']['total_fee'],
                'buyer' => $result['data']['openid'],
                'bill_trade_no' => $result['data']['pay_no'],
                'endtime' => $result['data']['success_time'],
            ];
        } else {
            throw new \Exception($result["msg"] ?? '返回数据解析失败');
        }
    }

    //退款
    public function refund(array $order): array
    {
        if ($order['type'] == 2) {
            $path = '/api/wxpay/refund_order';
        } else {
            $path = '/api/alipay/refund_order';
        }

        $param = [
            'mch_id' => $this->channel['appid'],
            'out_trade_no' => $order['trade_no'],
            'out_refund_no' => $order['refund_no'],
            'timestamp' => time(),
            'refund_fee' => $order['refundmoney'],
        ];
        $sign_param = ['mch_id', 'out_trade_no', 'out_refund_no', 'timestamp', 'refund_fee'];
        $param['sign'] = $this->makeSign($param, $sign_param, $this->channel['appkey']);

        $response = get_curl(self::API_URL . $path, http_build_query($param));
        $result = json_decode($response, true);

        if (isset($result["code"]) && $result["code"] == 0) {
            return ['code' => 0, 'trade_no' => $result['data']['out_trade_no'], 'refund_fee' => $order['refundmoney']];
        } else {
            return ['code' => -1, 'msg' => $result["msg"] ?? '返回数据解析失败'];
        }
    }
}
