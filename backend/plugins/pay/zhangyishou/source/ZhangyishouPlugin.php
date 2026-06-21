<?php

declare(strict_types=1);

namespace plugins\payment\zhangyishou;

use app\common\PaymentContext;
use app\common\BasePayment;

class ZhangyishouPlugin extends BasePayment
{
    private function getPayConfig(): array
    {
        return [
            //登录账号
            'MerchantId' => $this->channel['appid'],
            //商户编号
            'MerchantNo' => $this->channel['appurl'],
            //商户密钥
            'key' => $this->channel['appkey'],
            //通道ID
            'PayChannelId' => $this->channel['appmchid'],
        ];
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        /*if($ctx->mdevice === 'wechat'){
            return ['type'=>'jump','url'=>'/pay/wxjspay/'.$tradeNo.'/'];
        }*/

        return ['type' => 'jump', 'url' => '/pay/' . $ctx->order['typename'] . '/' . $tradeNo . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $typename = $ctx->order['typename'];
        return $this->$typename($ctx);
    }

    //通用扫码
    private function qrcode(string $type, PaymentContext $ctx): string
    {
        $pay_config = $this->getPayConfig();
        $getwayurl = 'https://apipay.zhangyishou.com/api/Order/AddOrder';
        $params = [
            'MerchantId' => $pay_config['MerchantId'],
            'DownstreamOrderNo' => $ctx->order['trade_no'],
            'OrderTime' => date('Y-m-d H:i:s'),
            'PayChannelId' => $pay_config['PayChannelId'],
            'AsynPath' => config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/',
            'OrderMoney' => $ctx->order['realmoney'],
            'IPPath' => request()->clientip,
        ];

        $signStr = "";
        foreach ($params as $row) {
            $signStr .= $row;
        }
        $signStr .= $pay_config['key'];
        $params['MD5Sign'] = md5($signStr);
        $params['MerchantNo'] = $pay_config['MerchantNo'];
        $params['Mproductdesc'] = $ctx->ordername;
        if ($type == 'qqpay' && $ctx->mdevice === 'qq' || $type == 'wxpay' && $ctx->mdevice === 'wechat') {
            $params['ReturnUrl'] = request()->siteurl . 'pay/return/' . $ctx->order['trade_no'] . '/';
        }

        return self::lockPayData($ctx->order['trade_no'], function () use ($getwayurl, $params) {
            $data = get_curl($getwayurl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
            if (!$data) throw new \Exception('接口请求失败');
            $result = json_decode($data, true);

            if (isset($result['Code']) && $result['Code'] == '1009') {
                $code_url = $result['Info'];
            } elseif(isset($result['Message'])) {
                throw new \Exception('[' . $result['Code'] . ']' . $result['Message'] . ':' . $result['Info']);
            } else {
                throw new \Exception('返回数据解析失败');
            }

            return $code_url;
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode('alipay', $ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        if (strpos($this->channel['appmchid'], '|')) {
            $appmchid = explode('|', $this->channel['appmchid']);
            $this->channel['appmchid'] = $appmchid[0];
            $isscheme = false;
            if ($ctx->isMobile && $ctx->mdevice !== 'wechat') {
                $this->channel['appmchid'] = $appmchid[1];
                $isscheme = true;
            }
        } else {
            $isscheme = false;
        }

        try {
            $code_url = $this->qrcode('wxpay', $ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if ($isscheme) {
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } elseif ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    private function wx_get_code(string $orderno, string $redirect_uri): string
    {
        $url = 'https://apipay.zhangyishou.com/api/get/code';
        $params = [
            'Apptype' => '0',
            'Code' => '',
            'MD5Sign' => '',
            'MerchantId' => '',
            'OrderNo' => $orderno,
            'RedirectUri' => $redirect_uri,
            'WayId' => $this->channel['appmchid'],
        ];
        $data = get_curl($url, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        $result = json_decode($data, true);
        if ($result['Code'] == '1009') {
            return $result['Info'];
        } else {
            throw new \Exception('获取登录地址失败[' . $result['Code'] . ']' . $result['Message'] . ':' . $result['Info']);
        }
    }

    private function wx_get_openid(string $orderno, string $code): string
    {
        $url = 'https://apipay.zhangyishou.com/api/get/userId';
        $params = [
            'Apptype' => '0',
            'Code' => $code,
            'MD5Sign' => '',
            'MerchantId' => '',
            'OrderNo' => $orderno,
            'RedirectUri' => '',
            'WayId' => $this->channel['appmchid'],
        ];
        $data = get_curl($url, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        $result = json_decode($data, true);
        if ($result['Code'] == '1009') {
            return $result['Info'];
        } else {
            throw new \Exception('获取OpenId失败[' . $result['Code'] . ']' . $result['Message'] . ':' . $result['Info']);
        }
    }

    private function wx_get_paydata(string $orderno, string $openid): string
    {
        $url = 'https://apipay.zhangyishou.com/api/Order/byOrderNoPay';
        $params = [
            'openId' => $openid,
            'orderNo' => $orderno,
        ];
        $data = get_curl($url, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        $result = json_decode($data, true);
        if ($result['Code'] == '1009') {
            return $result['Info'];
        } else {
            throw new \Exception('获取公众号支付参数失败[' . $result['Code'] . ']' . $result['Message'] . ':' . $result['Info']);
        }
    }

    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        try {
            $code_url = $this->qrcode('wxpay', $ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        $orderno = substr($code_url, strpos($code_url, 'OrderNo=') + 8);

        if (!request()->get('code')) {
            $redirect_uri = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            $jump_url = $this->wx_get_code($orderno, $redirect_uri);
            $jump_url = str_replace('pay.html', 'skip.html', $jump_url);
            return ['type' => 'jump', 'url' => $jump_url];
        }
        $code = trim(request()->get('code'));
        $openid = $this->wx_get_openid($orderno, $code);
        $paydata = $this->wx_get_paydata($orderno, $openid);

        if (request()->get('d') === '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $paydata, 'redirect_url' => $redirect_url]];
    }

    //QQ扫码支付
    public function qqpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode('qqpay', $ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => 'QQ钱包支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'qq') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile && !request()->get('qrcode')) {
            return ['type' => 'qrcode', 'page' => 'qqpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'qqpay_qrcode', 'url' => $code_url];
        }
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode('bank', $ctx);
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $pay_config = $this->getPayConfig();
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'data error'];

        $signStr = $data['MerchantId'] . $data['DownstreamOrderNo'] . $pay_config['key'];
        $sign = md5($signStr);

        if ($sign === $data['Signature']) {
            if ($data['OrderState'] == 1) {
                $trade_no = $data['OrderNo'];
                if ($data['DownstreamOrderNo'] == $ctx->order['trade_no'] && round((float)$data['OrderMoney'], 2) == round((float)$ctx->order['realmoney'], 2)) {
                    $this->processNotify($ctx->order, $trade_no);
                }
                return ['type' => 'html', 'data' => 'OK'];
            }
        }
        return ['type' => 'html', 'data' => 'ERROR'];
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
    }

    public function query(array $order): array
    {
        $pay_config = $this->getPayConfig();
        $getwayurl = 'https://apipay.zhangyishou.com/query';
        $params = [
            'MerchantId' => $pay_config['MerchantId'],
            'MerchantOrderNo' => $order['trade_no'],
        ];
        
        $signStr = "";
        foreach ($params as $row) {
            $signStr .= $row;
        }
        $signStr .= $pay_config['key'];
        $params['MD5Sign'] = md5($signStr);

        $data = get_curl($getwayurl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$data) throw new \Exception('接口请求失败');
        $result = json_decode($data, true);
        if ($result['Code'] == '1009') {
            return [
                'api_trade_no' => $result['OrderNo'],
                'status' => $result['OrderState'] == 1 ? 1 : 0,
                'money' => $result['OrderMoney'],
            ];
        } else {
            throw new \Exception($result["Message"]);
        }
    }

    //退款
    public function refund(array $order): array
    {
        $pay_config = $this->getPayConfig();
        $getwayurl = 'https://apipay.zhangyishou.com/api/OrderRefund/Refund';
        $params = [
            'MerchantId' => $pay_config['MerchantId'],
            'MerchantOrder' => $order['trade_no'],
            'RefundAmount' => $order['refundmoney'],
        ];

        $signStr = "";
        foreach ($params as $row) {
            $signStr .= $row;
        }
        $signStr .= $pay_config['key'];
        $params['MD5Sign'] = md5($signStr);

        $data = get_curl($getwayurl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);

        $result = json_decode($data, true);

        if ($result['Code'] == '1009') {
            return ['code' => 0, 'trade_no' => $order['trade_no'], 'refund_fee' => $order['refundmoney']];
        } else {
            return ['code' => -1, 'msg' => $result["Message"]];
        }
    }

    //转账
    public function transfer(array $bizParam): array
    {
        if ($bizParam['type'] == 'alipay') {
            $PayChannelId = '12002';
            $PaymentType = '3';
            if (is_numeric($bizParam['payee_account']) && substr($bizParam['payee_account'], 0, 4) == '2088') {
                $AccountNumberType = '2';
            } elseif (strpos($bizParam['payee_account'], '@') !== false || is_numeric($bizParam['payee_account'])) {
                $AccountNumberType = '1';
            } else {
                $AccountNumberType = '3';
            }
        } else {
            $PayChannelId = '12001';
            $PaymentType = '2';
            $AccountNumberType = '1';
        }

        $pay_config = $this->getPayConfig();
        $getwayurl = 'https://apipay.zhangyishou.com/api/Order/AddOrder';
        $params = [
            'MerchantId' => $pay_config['MerchantId'],
            'DownstreamOrderNo' => $bizParam['out_biz_no'],
            'OrderTime' => date('Y-m-d H:i:s'),
            'PayChannelId' => $PayChannelId,
            'AsynPath' => config_get('localurl') . 'pay/transfernotify/' . $this->channel['id'] . '/',
            'OrderMoney' => sprintf('%.2f', $bizParam['money']),
            'IPPath' => request()->clientip,
        ];

        $signStr = "";
        foreach ($params as $row) {
            $signStr .= $row;
        }
        $signStr .= $pay_config['key'];
        $params += [
            'MD5Sign' => md5($signStr),
            'MerchantNo' => $pay_config['MerchantNo'],
            'PaymentType' => $PaymentType,
            'AccountNumber' => $bizParam['payee_account'],
            'AccountNumberType' => $AccountNumberType,
            'AccountName' => $bizParam['payee_real_name'],
            'PaymentRemark' => $bizParam['transfer_desc'],
            'ReasonPayment' => $bizParam['transfer_desc'],
            'Mproductdesc' => $bizParam['transfer_desc'],
        ];

        $data = get_curl($getwayurl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);

        $result = json_decode($data, true);
        if ($result['Code'] == '1009') {
            $info = json_decode($result['Info'], true);
            $order_id = $info['alipay_fund_trans_uni_transfer_response']['out_biz_no'];
            return ['code' => 0, 'status' => 0, 'orderid' => $order_id, 'paydate' => date('Y-m-d H:i:s')];
        } else {
            return ['code' => -1, 'msg' => $result["Message"] ? $result["Message"] : '返回数据解析失败'];
        }
    }

    //付款异步回调
    public function transfernotify(PaymentContext $ctx): array
    {
        $pay_config = $this->getPayConfig();
        $json = file_get_contents("php://input");
        $data = json_decode($json, true);
        if (!$data) return ['type' => 'html', 'data' => 'data error'];

        $signStr = $data['MerchantId'] . $data['DownstreamOrderNo'] . $pay_config['key'];
        $sign = md5($signStr);

        if ($sign === $data['Signature']) {
            $errmsg = null;
            if ($data['OrderState'] == '1') {
                $status = 1;
            } else {
                $status = 2;
                $errmsg = $data['Remark'];
            }
            ($this->markTrustedCallback($ctx, 'transfernotify', 'zhangyishou-signature'))(function () use ($data, $status, $errmsg) {
                $this->processTransfer($data['DownstreamOrderNo'], $status, $errmsg);
            });
            return ['type' => 'html', 'data' => 'OK'];
        } else {
            return ['type' => 'html', 'data' => 'ERROR'];
        }
    }

    //余额查询
    public function balance_query(array $bizParam): array
    {
        $pay_config = $this->getPayConfig();
        $getwayurl = 'https://apipay.zhangyishou.com/query/bookQuery';
        $params = [
            'userName' => $pay_config['MerchantId'],
            'merchantNo' => $pay_config['MerchantNo'],
        ];
        $signStr = "";
        foreach ($params as $row) {
            $signStr .= $row;
        }
        $signStr .= $pay_config['key'];
        $params['MD5Sign'] = md5($signStr);

        $data = get_curl($getwayurl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);

        $result = json_decode($data, true);
        if ($result['Code'] == '1009') {
            return ['code' => 0, 'amount' => $result['Info']];
        } else {
            return ['code' => -1, 'msg' => $result["Message"] ? $result["Message"] : '返回数据解析失败'];
        }
    }
}
