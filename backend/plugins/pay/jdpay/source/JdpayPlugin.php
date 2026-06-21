<?php

declare(strict_types=1);

namespace plugins\payment\jdpay;

use app\common\BasePayment;
use app\common\PaymentContext;

class JdpayPlugin extends BasePayment
{
    private function createService(): JdpayService
    {
        return new JdpayService(
            $this->channel['appkey'],
            getCertFilePath($this->channel['private_key_path'] ?? ''),
            $this->payRoot . 'cert/wy_rsa_public_key.pem'
        );
    }

    public function submit(PaymentContext $ctx): array
    {
        $service = $this->createService();
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->isMobile) {
            $oriUrl = 'https://h5pay.jd.com/jdpay/saveOrder';
        } else {
            $oriUrl = 'https://wepay.jd.com/jdpay/saveOrder';
        }

        $param = [];
        $param["version"] = 'V2.0';
        $param["merchant"] = $this->channel['appid'];
        $param["tradeNum"] = $tradeNo;
        $param["tradeName"] = $ctx->ordername;
        $param["tradeTime"] = date('YmdHis');
        $param["amount"] = strval($ctx->order['realmoney'] * 100);
        $param["currency"] = 'CNY';
        $param["callbackUrl"] = $siteurl . 'pay/return/' . $tradeNo . '/';
        $param["notifyUrl"] = config_get('localurl') . 'pay/notify/' . $tradeNo . '/';
        $param["ip"] = request()->clientip;
        $param["userId"] = '';
        $param["orderType"] = '1';

        $unSignKeyList = ["sign"];
        $sign = $service->signWithoutToHex($param, $unSignKeyList);
        $param["sign"] = $sign;
        $keys = base64_decode($this->channel['appkey']);

        $param["tradeNum"] = $service->encrypt2HexStr($keys, $param["tradeNum"]);
        if ($param["tradeName"] != null && $param["tradeName"] != "") {
            $param["tradeName"] = $service->encrypt2HexStr($keys, $param["tradeName"]);
        }
        $param["tradeTime"] = $service->encrypt2HexStr($keys, $param["tradeTime"]);
        $param["amount"] = $service->encrypt2HexStr($keys, $param["amount"]);
        $param["currency"] = $service->encrypt2HexStr($keys, $param["currency"]);
        $param["callbackUrl"] = $service->encrypt2HexStr($keys, $param["callbackUrl"]);
        $param["notifyUrl"] = $service->encrypt2HexStr($keys, $param["notifyUrl"]);
        $param["ip"] = $service->encrypt2HexStr($keys, $param["ip"]);
        if ($param["userId"] != null && $param["userId"] != "") {
            $param["userId"] = $service->encrypt2HexStr($keys, $param["userId"]);
        }
        if ($param["orderType"] != null && $param["orderType"] != "") {
            $param["orderType"] = $service->encrypt2HexStr($keys, $param["orderType"]);
        }

        $html_text = '<form action="' . $oriUrl . '" method="post" id="dopay">';
        foreach ($param as $k => $v) {
            $html_text .= "<input type=\"hidden\" name=\"{$k}\" value=\"{$v}\" />\n";
        }
        $html_text .= '<input type="submit" value="正在跳转"></form><script>document.getElementById("dopay").submit();</script>';

        return ['type' => 'html', 'data' => $html_text];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $service = $this->createService();
        $xml = request()->getContent();
        $resData = [];
        $flag = $service->decryptResXml($xml, $resData);

        if ($flag) {
            $trade_no = $resData["tradeNum"];
            $out_trade_no = $resData["tradeNum"];
            if ($resData["status"] == 2) {
                if ($out_trade_no == $ctx->order['trade_no'] && $resData["amount"] == strval($ctx->order['realmoney'] * 100)) {
                    $this->processNotify($ctx->order, $trade_no);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        }

        return ['type' => 'html', 'data' => 'error'];
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        $service = $this->createService();
        $keys = base64_decode($this->channel['appkey']);
        $post = request()->post();

        $param = [];
        if (!empty($post["tradeNum"])) {
            $param["tradeNum"] = $service->decrypt4HexStr($keys, $post["tradeNum"]);
        }
        if (!empty($post["amount"])) {
            $param["amount"] = $service->decrypt4HexStr($keys, $post["amount"]);
        }
        if (!empty($post["currency"])) {
            $param["currency"] = $service->decrypt4HexStr($keys, $post["currency"]);
        }
        if (!empty($post["tradeTime"])) {
            $param["tradeTime"] = $service->decrypt4HexStr($keys, $post["tradeTime"]);
        }
        if (!empty($post["status"])) {
            $param["status"] = $service->decrypt4HexStr($keys, $post["status"]);
        }

        $sign = $post["sign"];
        $strSourceData = $service->signString($param, []);
        $decryptStr = $service->decryptByPublicKey($sign);
        $sha256SourceSignString = hash("sha256", $strSourceData);

        if ($decryptStr == $sha256SourceSignString) {
            $trade_no = $param["tradeNum"];
            if ($trade_no == $ctx->order['trade_no'] && $param["amount"] == strval($ctx->order['realmoney'] * 100)) {
                return ($this->markTrustedCallback($ctx, 'return', 'jdpay-signature'))(function () use ($ctx, $trade_no) {
                    return $this->processReturn($ctx->order, $trade_no);
                });
            }
            return ['type' => 'error', 'msg' => '订单信息校验失败'];
        }

        return ['type' => 'error', 'msg' => '验证签名失败！'];
    }

    //退款
    public function refund(array $order): array
    {
        $service = $this->createService();

        $param = [];
        $param["version"] = "V2.0";
        $param["merchant"] = $this->channel['appid'];
        $param["tradeNum"] = $order['refund_no'];
        $param["oTradeNum"] = $order['api_trade_no'];
        $param["amount"] = $order['refundmoney'] * 100;
        $param["currency"] = "CNY";

        $reqXmlStr = $service->encryptReqXml($param);
        $url = 'https://paygate.jd.com/service/refund';
        [$return_code, $return_content] = $service->httpPost($url, $reqXmlStr);

        $resData = [];
        $flag = $service->decryptResXml($return_content, $resData);
        if ($flag) {
            if ($resData['status'] == "1") {
                return ['code' => 0, 'trade_no' => $resData['oTradeNum'], 'refund_fee' => $resData['amount']/100];
            }
            return ['code' => -1, 'msg' => '[' . $resData['result']['code'] . ']' . $resData['result']['desc']];
        }

        return ['code' => -1, 'msg' => '验签失败'];
    }
}
