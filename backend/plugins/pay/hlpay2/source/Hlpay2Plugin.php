<?php

declare(strict_types=1);

namespace plugins\payment\hlpay2;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class Hlpay2Plugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'douyinpay') {
            return ['type' => 'jump', 'url' => '/pay/douyinpay/' . $tradeNo . '/'];
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && in_array('2', $this->channel['apptype']) && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && in_array('2', $this->channel['apptype']) && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        } elseif ($ctx->order['typename'] == 'douyinpay') {
            return ['type' => 'jump', 'url' => $siteurl . 'pay/douyinpay/' . $tradeNo . '/'];
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    private function createClient()
    {
        return new HlpayClient(
            $this->channel['appid'],
            $this->channel['appkey'],
            $this->channel['appsecret'],
            $this->channel['appmchid']
        );
    }

    //统一下单
    private function addOrder(PaymentContext $ctx, $pay_type, $pay_sub_type, $sub_appid = null, $sub_openid = null)
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        $client = $this->createClient();

        if (request()->get('r') == 1) {
            $returnUrl = $siteurl . 'pay/ok/' . $tradeNo . '/';
        } else {
            $returnUrl = $siteurl . 'pay/return/' . $tradeNo . '/';
        }
        $param = [
            'mchOrderNo' => $tradeNo,
            'productCode' => $pay_type,
            'paySubType' => $pay_sub_type,
            'orderAmount' => $ctx->order['realmoney'],
            'clientIp' => request()->clientip,
            'subject' => $ctx->ordername,
            'description' => $ctx->order['name'],
            'notifyUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'redirectUrl' => $returnUrl,
            'sceneType' => $this->channel['appswitch'],
        ];
        if (!empty($this->channel['channelcode'])) {
            $param['channelCode'] = $this->channel['channelcode'];
        }
        $extra = [];
        if ($sub_appid && $sub_openid) {
            $extra['subAppid'] = $sub_appid;
            $extra['userId'] = $sub_openid;
        } elseif ($sub_openid) {
            $extra['userId'] = $sub_openid;
        }
        if ($pay_sub_type == 'H5' || $pay_sub_type == 'APP') {
            $extra['originalType'] = 0;
            $extra['appName'] = config_get('sitename');
        }
        if (!empty($extra)) {
            $param['extra'] = $extra;
        }

        $result = self::lockPayData($tradeNo, function () use ($client, $param, $tradeNo) {
            $result = $client->execute('/openapi/order/pay/create', $param);
            $this->updateOrder($tradeNo, $result['payOrderNo']);
            return $result;
        });
        if ($result['state'] == 7 && isset($result['failCode'])) {
            throw new Exception('[' . $result['failCode'] . ']' . $result['failReason']);
        }
        return $result;
    }

    //支付宝支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('4', $this->channel['apptype']) && $ctx->isMobile) {
            $pay_sub_type = 'H5';
        } elseif (in_array('3', $this->channel['apptype']) && !$ctx->isMobile) {
            $pay_sub_type = 'PC';
        } elseif (in_array('1', $this->channel['apptype'])) {
            $pay_sub_type = 'NATIVE';
        } elseif (in_array('2', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        } elseif (in_array('4', $this->channel['apptype'])) {
            $qrcode_url = $siteurl . 'pay/alipay/' . $tradeNo . '/?r=1';
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $qrcode_url];
        } else {
            return ['type' => 'error', 'msg' => '当前支付通道没有开启的支付方式'];
        }
        try {
            $result = $this->addOrder($ctx, 'Ali-PAY', $pay_sub_type);
            $code_url = $result['payInfo'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'alipay' || $pay_sub_type == 'H5' || $pay_sub_type == 'PC') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!empty($ctx->order['sub_openid'])) {
            $user_id = $ctx->order['sub_openid'];
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }

        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;
        if ($user_type == 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $result = $this->addOrder($ctx, 'Ali-PAY', 'JSAPI', null, $user_id);
            $trade_no = $result['payInfo'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $trade_no];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $trade_no, 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            if ($this->channel['appwxmp'] > 0 && $this->channel['appwxa'] == 0) {
                $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            } else {
                $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
            }
        } else {
            try {
                $result = $this->addOrder($ctx, 'WeChat-PAY', 'NATIVE');
                $code_url = $result['payInfo'];
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
            }
        }

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if (!empty($ctx->order['sub_openid'])) {
            if (!empty($ctx->order['sub_appid'])) {
                $wxinfo['appid'] = $ctx->order['sub_appid'];
            } else {
                if ($ctx->order['is_applet'] == 1) {
                    $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
                    if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
                } else {
                    $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
                    if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
                }
            }
            $openid = $ctx->order['sub_openid'];
        } else {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
            try {
                $openid = wechat_oauth($wxinfo);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        try {
            $result = $this->addOrder($ctx, 'WeChat-PAY', $ctx->order['is_applet'] == 1 ? 'MINI_APP' : 'JSAPI', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $result['payInfo']];
        }

        if (request()->get('d') == 1) {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $result['payInfo'], 'redirect_url' => $redirect_url]];
    }

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $code = request()->get('code');
        if (empty($code)) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }
        $code = trim($code);

        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];
        try {
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        try {
            $result = $this->addOrder($ctx, 'WeChat-PAY', 'MINI_APP', $wxinfo['appid'], $openid);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($result['payInfo'], true)]];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
        try {
            $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $result = $this->addOrder($ctx, 'Union-PAY', 'NATIVE');
            $code_url = $result['payInfo'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->mdevice === 'unionpay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
        }
    }

    //抖音支付
    public function douyinpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        try {
            $result = $this->addOrder($ctx, 'DY-PAY', 'H5');
            $code_url = $result['payInfo'];
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '抖音支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->isMobile) {
            $redirect_url = $siteurl . 'pay/return/' . $tradeNo . '/';
            $url = $code_url . '&return_url=' . urlencode($redirect_url);
            return ['type' => 'jump', 'url' => $url];
        } else {
            $code_url = $siteurl . 'pay/douyinpay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'douyinpay_qrcode', 'url' => $code_url];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => 'No data'];

        $client = $this->createClient();
        $verify_result = $client->verifySign($arr);

        if ($verify_result) {
            $data = json_decode($arr['data'], true);
            if ($data['state'] == '3') {
                $out_trade_no = $data['mchOrderNo'];
                $trade_no = $data['payOrderNo'];
                $bill_trade_no = $data['channelOrderNo'];
                $bill_mch_trade_no = $data['instOrderNo'];
                if ($out_trade_no == $tradeNo) {
                    $this->processNotify($ctx->order, $trade_no, null, $bill_trade_no, $bill_mch_trade_no);
                }
                return ['type' => 'html', 'data' => 'success'];
            }
            return ['type' => 'html', 'data' => 'status fail'];
        } else {
            return ['type' => 'html', 'data' => 'sign fail'];
        }
    }

    //支付成功页面
    public function ok(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'ok'];
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $client = $this->createClient();
        $param = [
            'mchOrderNo' => $order['trade_no'],
        ];
        $result = $client->execute('/openapi/order/pay/query', $param);
        return [
            'api_trade_no' => $result['payOrderNo'],
            'status' => $result['state'] == '3' ? 1 : 0,
            'money' => $result['orderAmount'],
            'bill_trade_no' => $result['channelOrderNo'] ?? '',
            'bill_mch_trade_no' => $result['instOrderNo'] ?? '',
            'endtime' => $result['successTime'] ?? '',
        ];
    }

    //退款
    public function refund($order): array
    {
        $client = $this->createClient();

        $param = [
            'payOrderNo' => $order['api_trade_no'],
            'mchRefundOrderNo' => $order['refund_no'],
            'amount' => $order['refundmoney'],
        ];

        try {
            $result = $client->execute('/openapi/order/pay/refund', $param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        return ['code' => 0, 'trade_no' => $result['instOrderNo'], 'refund_fee' => $result['refundAmount']];
    }

    //转账
    public function transfer($bizParam): array
    {
        if ($bizParam['type'] == 'alipay') $entry_type = '1';
        elseif ($bizParam['type'] == 'wxpay') $entry_type = '2';
        elseif ($bizParam['type'] == 'bank') $entry_type = '3';

        $client = $this->createClient();

        $param = [
            'mchChannelCode' => $this->channel['channelcode'],
            'entryType' => $entry_type,
            'mchOrderNo' => $bizParam['out_biz_no'],
            'amount' => $bizParam['money'],
            'clientIp' => request()->clientip,
            'remark' => $bizParam['desc'],
            'name' => $bizParam['payee_real_name'],
            'cardNo' => $bizParam['payee_account'],
        ];
        if ($bizParam['type'] == 'bank') {
            $param['payeeType'] = '1';
        }
        if ($bizParam['type'] == 'alipay') {
            if (is_numeric($bizParam['payee_account']) && substr($bizParam['payee_account'], 0, 4) == '2088') $is_userid = 1;
            elseif (strpos($bizParam['payee_account'], '@') !== false || is_numeric($bizParam['payee_account'])) $is_userid = 2;
            else $is_userid = 3;
            $param['extra'] = ['accountType' => $is_userid];
        }

        try {
            $result = $client->execute('/openapi/order/pay/create', $param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        if ($result['status'] == 3) {
            $status = 1;
        } elseif ($result['status'] == 4 || $result['status'] == 6) {
            $status = 2;
        } else {
            $status = 0;
        }
        return ['code' => 0, 'status' => $status, 'orderid' => $result['payOrderNo'], 'paydate' => date('Y-m-d H:i:s')];
    }

    //转账查询
    public function transfer_query($bizParam): array
    {
        $client = $this->createClient();

        $param = [
            'mchOrderNo' => $bizParam['out_biz_no'],
        ];

        try {
            $result = $client->execute('/openapi/order/pay/query', $param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        $errmsg = null;
        if ($result['status'] == 3) {
            $status = 1;
        } elseif ($result['status'] == 4 || $result['status'] == 6) {
            $status = 2;
            $errmsg = '转账失败';
        } else {
            $status = 0;
        }

        return ['code' => 0, 'status' => $status, 'amount' => $result['amount'], 'paydate' => $result['successTime'], 'errmsg' => $errmsg];
    }

    //余额查询
    public function balance_query($bizParam): array
    {
        $client = $this->createClient();

        $param = [
            'mchChannelCode' => $this->channel['channelcode'],
        ];

        try {
            $result = $client->execute('/openapi/order/pay/account', $param);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        $data = array_filter($result, function ($value) {
            return $value['acctType'] == '3';
        });
        if (empty($data)) return ['code' => -1, 'msg' => '未查询到代付账户'];
        return ['code' => 0, 'amount' => $data[array_key_first($data)]['balance']];
    }
}
