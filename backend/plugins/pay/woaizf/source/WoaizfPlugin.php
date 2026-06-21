<?php

namespace plugins\payment\woaizf;

use app\common\PaymentContext;
use Exception;

class WoaizfPlugin extends \app\common\BasePayment
{
    private function makeSign(array $param, string $key): string
    {
        ksort($param);
        $signstr = '';
        foreach ($param as $k => $v) {
            if ($k != "sign" && !isNullOrEmpty($v)) {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        return md5($signstr . $key);
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('6', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'qqpay') {
            return ['type' => 'jump', 'url' => '/pay/qqpay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'bank') {
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('6', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => request()->siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'qqpay') {
            return $this->qqpay($ctx);
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //下单
    private function addOrder(PaymentContext $ctx, string $pay_type, ?string $openid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!empty($this->channel['appurl'])) {
            $apiurl = $this->channel['appurl'] . 'api/payment/create';
        } else {
            $apiurl = 'https://payapi.52zhifu.com/api/payment/create';
        }
        $param = [
            'appid' => $this->channel['appid'],
            'channel' => $pay_type,
            'money' => $ctx->order['realmoney'],
            'client_ip' => request()->clientip,
            'name' => $ctx->ordername,
            'out_trade_no' => $tradeNo,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'timestamp' => '' . time(),
        ];
        if ($openid) {
            $param['openid'] = $openid;
        }
        $param['sign'] = $this->makeSign($param, $this->channel['appkey']);

        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) throw new Exception('接口请求失败');
        $result = json_decode($data, true);

        if (isset($result['code']) && $result['code'] == 200) {
            return [$result['data']['type'], $result['data']['param']];
        } else {
            throw new Exception($result['message'] ?? '返回数据解析失败');
        }
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        if (in_array('3', $this->channel['apptype']) && $ctx->isMobile) {
            $pay_type = 'ALIPAY_WAP';
        } elseif (in_array('2', $this->channel['apptype']) && !$ctx->isMobile) {
            $pay_type = 'ALIPAY_PC';
        } else {
            $pay_type = 'ALIPAY_QR';
        }
        try {
            list($type, $payData) = $this->addOrder($ctx, $pay_type);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }

        if ($type == 'redirect_url') {
            return ['type' => 'jump', 'url' => $payData];
        } elseif ($type == 'form') {
            return ['type' => 'form', 'url' => $payData];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $payData];
        }
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        if (in_array('5', $this->channel['apptype']) && $ctx->isMobile) {
            $pay_type = 'WECHAT_H5';
        } else {
            $pay_type = 'WECHAT_QR';
        }
        try {
            list($type, $payData) = $this->addOrder($ctx, $pay_type);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if ($type == 'redirect_url') {
            return ['type' => 'jump', 'url' => $payData];
        } elseif ($type == 'form') {
            return ['type' => 'form', 'url' => $payData];
        } else {
            if ($ctx->isMobile) {
                return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $payData];
            } else {
                return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $payData];
            }
        }
    }

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        //①、获取用户openid
        if (!request()->get('openid')) {
            $redirect_url = request()->siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            if (!empty($this->channel['appurl'])) {
                $apiurl = $this->channel['appurl'] . 'api/openid';
            } else {
                $apiurl = 'https://payapi.52zhifu.com/api/openid';
            }
            $url = $apiurl . '?appid=' . $this->channel['appid'] . '&redirect_uri=' . urlencode($redirect_url);
            return ['type' => 'jump', 'url' => $url];
        }
        $openId = request()->get('openid');
        $blocks = checkBlockUser($openId, $tradeNo);
        if ($blocks) return $blocks;

        //②、统一下单
        try {
            list($type, $payData) = $this->addOrder($ctx, 'WECHAT_JSAPI', $openId);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        $redirect_url = 'data.backurl';
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $payData, 'redirect_url' => $redirect_url]];
    }

    //QQ扫码支付
    public function qqpay(PaymentContext $ctx): array
    {
        try {
            list($type, $payData) = $this->addOrder($ctx, 'QQPAY_QR');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => 'QQ钱包支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'qq') {
            return ['type' => 'jump', 'url' => $payData];
        } elseif ($ctx->isMobile && !request()->get('qrcode')) {
            return ['type' => 'qrcode', 'page' => 'qqpay_wap', 'url' => $payData];
        } else {
            return ['type' => 'qrcode', 'page' => 'qqpay_qrcode', 'url' => $payData];
        }
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            list($type, $payData) = $this->addOrder($ctx, 'BANK_QR');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $payData];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!request()->post('sign')) return ['type' => 'html', 'data' => 'no data'];

        $sign = $this->makeSign(request()->post(), $this->channel['appkey']);

        if ($sign === request()->post('sign')) {
            $out_trade_no = request()->post('out_trade_no');
            $api_trade_no = request()->post('trade_no');
            $money = (float) request()->post('money');

            if ($out_trade_no == $tradeNo && round($money, 2) == round((float) $ctx->order['realmoney'], 2)) {
                $this->processNotify($ctx->order, $api_trade_no);
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

    //退款
    public function refund(array $order): array
    {
        if (!empty($this->channel['appurl'])) {
            $apiurl = $this->channel['appurl'] . 'api/refund/confrim';
        } else {
            $apiurl = 'https://payapi.52zhifu.com/api/refund/confrim';
        }
        $param = [
            'appid' => $this->channel['appid'],
            'trade_no' => $order['api_trade_no'],
            'money' => $order['refundmoney'],
            'timestamp' => '' . time(),
        ];
        $param['sign'] = $this->makeSign($param, $this->channel['appkey']);
        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($data, true);

        if (isset($result['code']) && $result['code'] == 200) {
            return ['code' => 0];
        } else {
            return ['code' => -1, 'msg' => $result['message'] ?? '接口返回错误'];
        }
    }

    //转账
    public function transfer(array $bizParam): array
    {
        if ($bizParam['type'] == 'wxpay') $bizParam['type'] = 'wechat';

        if (!empty($this->channel['appurl'])) {
            $apiurl = $this->channel['appurl'] . 'api/trans/confrim';
        } else {
            $apiurl = 'https://payapi.52zhifu.com/api/trans/confrim';
        }
        $param = [
            'appid' => $this->channel['appid'],
            'type' => $bizParam['type'],
            'account' => $bizParam['payee_account'],
            'name' => $bizParam['payee_real_name'],
            'memo' => $bizParam['transfer_desc'],
            'money' => $bizParam['money'],
            'timestamp' => '' . time(),
        ];
        $param['sign'] = $this->makeSign($param, $this->channel['appkey']);
        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($data, true);

        if (isset($result['code']) && $result['code'] == 200) {
            return ['code' => 0, 'status' => $result['data']['status'], 'orderid' => $result['data']['trade_no'], 'paydate' => date('Y-m-d H:i:s')];
        } else {
            return ['code' => -1, 'msg' => $result['message'] ?? '接口返回错误'];
        }
    }

    //转账查询
    public function transfer_query(array $bizParam): array
    {
        if (!empty($this->channel['appurl'])) {
            $apiurl = $this->channel['appurl'] . 'api/trans/query';
        } else {
            $apiurl = 'https://payapi.52zhifu.com/api/trans/query';
        }
        $param = [
            'appid' => $this->channel['appid'],
            'trade_no' => $bizParam['orderid'],
            'timestamp' => '' . time(),
        ];
        $param['sign'] = $this->makeSign($param, $this->channel['appkey']);
        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $result = json_decode($data, true);

        if (isset($result['code']) && $result['code'] == 200) {
            return ['code' => 0, 'status' => $result['data']['status'], 'amount' => $result['data']['money'], 'paydate' => $result['data']['trade_time'], 'errmsg' => $result['data']['status_text']];
        } else {
            return ['code' => -1, 'msg' => $result['message'] ?? '接口返回错误'];
        }
    }
}
