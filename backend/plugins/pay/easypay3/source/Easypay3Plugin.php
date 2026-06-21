<?php

declare(strict_types=1);

namespace plugins\payment\easypay3;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class Easypay3Plugin extends BasePayment
{
    private function createClient(): EasypayClient
    {
        return new EasypayClient(
            $this->channel['appid'],
            $this->channel['appmchid'],
            $this->channel['termno'],
            $this->channel['appkey'],
            $this->channel['appsecret']
        );
    }

    private function getOrgTrace(string $tradeNo): string
    {
        return substr($this->channel['appid'], 0, 4) . substr($this->channel['appid'], -4) . $tradeNo;
    }

    //扫码支付接口
    private function qrcode_pay(PaymentContext $ctx, string $tradeCode): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $orgTrace = $this->getOrgTrace($tradeNo);

        $params = [
            'orgBackUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'tradeCode' => $tradeCode,
            'tradeAmt' => intval(round($ctx->order['realmoney'] * 100)),
            'orderInfo' => $ctx->ordername,
            'infoAttach' => $tradeNo,
        ];

        $client = $this->createClient();
        $result = self::lockPayData($tradeNo, function () use ($client, $orgTrace, $params) {
            return $client->paySubmit('/standard/native', $orgTrace, $params);
        });
        if ($result['finRetcode'] == '99') {
            return $result['qrCode'];
        } else {
            throw new Exception('[' . $result['appendRetcode'] . ']' . $result['appendRetmsg']);
        }
    }

    //JSAPI支付接口
    private function jsapi_pay(PaymentContext $ctx, string $tradeCode, string $appid, string $openid): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $orgTrace = $this->getOrgTrace($tradeNo);

        $params = [
            'orgBackUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'orgFrontUrl' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'payerId' => $openid,
            'tradeCode' => $tradeCode,
            'tradeAmt' => intval(round($ctx->order['realmoney'] * 100)),
            'orderInfo' => $ctx->ordername,
            'infoAttach' => $tradeNo,
            'wxSubAppid' => $appid,
        ];

        $client = $this->createClient();
        $result = self::lockPayData($tradeNo, function () use ($client, $orgTrace, $params) {
            return $client->paySubmit('/standard/jsapi', $orgTrace, $params);
        });
        if ($result['finRetcode'] == '99') {
            return $result;
        } else {
            throw new Exception('[' . $result['appendRetcode'] . ']' . $result['appendRetmsg']);
        }
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $this->channel['appwxa'] > 0) {
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
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode_pay($ctx, 'WAC2B');
        } catch (Exception $ex) {
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
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($this->channel['appwxa'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } elseif ($this->channel['appwxmp'] > 0) {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return $this->wxpay($ctx);
        }
    }

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        //①、获取用户openid
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
        try {
            $tools = new \WeChatPay\JsApiTool($wxinfo['appid'], $wxinfo['appsecret']);
            $openid = $tools->GetOpenid();
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        //②、统一下单
        try {
            $result = $this->jsapi_pay($ctx, 'WTJS1', $wxinfo['appid'], $openid);
            $payinfo = $result['wxWcPayData'] ?? '';
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $payinfo, 'redirect_url' => $redirect_url]];
    }

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $code = request()->get('code');
        if (empty($code)) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }

        //①、获取用户openid
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];
        }
        try {
            $tools = new \WeChatPay\JsApiTool($wxinfo['appid'], $wxinfo['appsecret']);
            $openid = $tools->AppGetOpenid($code);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        //②、统一下单
        try {
            $result = $this->jsapi_pay($ctx, 'WTJS2', $wxinfo['appid'], $openid);
            $payinfo = $result['wxWcPayData'] ?? '';
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($payinfo, true)]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode_pay($ctx, 'WUC2B');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'html', 'data' => 'no data'];

        $client = $this->createClient();
        $verify_result = $client->verifySign($arr['data'], $arr['sign']);

        if ($verify_result) {
            if ($arr['data']['finRetcode'] == '00') {
                $out_trade_no = substr($arr['data']['oriOrgTrace'], 8);
                $api_trade_no = $arr['data']['outTrace'];
                $money = (int) $arr['data']['payerAmt'];
                $buyer = $arr['data']['payerId'] ?? null;
                $bill_trade_no = $arr['data']['pcTrace'] ?? null;
                $end_time = $arr['data']['timeEnd'];
                if ($out_trade_no == $ctx->order['trade_no'] && intval(round($ctx->order['realmoney'] * 100)) == $money) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'ok'];
        } else {
            return ['type' => 'html', 'data' => 'error'];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $client = $this->createClient();
        $orgTrace = $this->getOrgTrace(date('YmdHis').rand(100000,999999));
        $params = [
            'oriOrgTrace' => $this->getOrgTrace($order['trade_no']),
        ];
        $result = $client->paySubmit('/standard/tradeQuery', $orgTrace, $params);
        return [
            'api_trade_no' => $result['outTrace'],
            'status' => $result['finRetcode'] == '00' ? 1 : 0,
            'money' => $result['payerAmt'] / 100,
            'buyer' => $result['payerId'] ?? null,
            'bill_trade_no' => $result['pcTrace'] ?? null,
            'endtime' => $result['timeEnd'] ?? null,
        ];
    }

    //退款
    public function refund($order): array
    {
        $orgTrace = $this->getOrgTrace($order['refund_no']);

        $params = [
            'oriOutTrace' => $order['api_trade_no'],
            'oriBizDate' => substr($order['trade_no'], 0, 14),
            'transAmt' => strval($order['refundmoney'] * 100),
        ];

        try {
            $client = $this->createClient();
            $result = $client->refundSubmit('/ledger/mposrefund', $orgTrace, $params);
            return ['code' => 0, 'trade_no' => $result['outTrace'], 'refund_fee' => $result['transAmt'] / 100];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }
}
