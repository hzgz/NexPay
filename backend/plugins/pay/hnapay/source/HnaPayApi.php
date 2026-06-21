<?php

declare(strict_types=1);

namespace plugins\payment\hnapay;

use Exception;

/**
 * 新生支付API
 * @see https://www.yuque.com/chenyanfei-sjuaz/uhng8q
 * @see https://www.yuque.com/chenyanfei-sjuaz/kbvzit
 */
class HnaPayApi
{
    protected string $mer_id;
    protected string $sign_type = '1'; //1：RSA 3：国密交易证书 4：国密密钥
    protected string $charset = '1';
    private $platform_public_key;
    private $merchant_private_key;

    /**
     * @param string $mer_id 商户ID
     * @param string $platform_key_or_path 平台公钥字符串(key_type=0)或平台公钥证书文件路径(key_type=1/2)
     * @param string $merchant_key_or_path 商户私钥字符串(key_type=0)或商户私钥文件路径(key_type=1/2)
     * @param int $key_type 0=新收款密钥(字符串) 1=扫码收款密钥(文件) 2=付款密钥(文件)
     */
    public function __construct(string $mer_id, string $platform_key_or_path, string $merchant_key_or_path, int $key_type = 0)
    {
        $this->mer_id = $mer_id;
        if ($key_type >= 1) {
            if (!file_exists($platform_key_or_path)) {
                throw new Exception('平台公钥证书文件不存在');
            }
            if (!file_exists($merchant_key_or_path)) {
                throw new Exception('商户私钥文件不存在');
            }
            $this->platform_public_key = $this->loadPublicKeyFile($platform_key_or_path);
            $this->merchant_private_key = $this->loadPrivateKeyFile($merchant_key_or_path);
        } else {
            $this->platform_public_key = $this->loadPublicKey($platform_key_or_path);
            $this->merchant_private_key = $this->loadPrivateKey($merchant_key_or_path);
        }
    }

    //扫码支付
    public function scanPay(array $params): array
    {
        $apiurl = 'https://gateway.hnapay.com/website/scanPay.do';
        $publicParams = [
            'tranCode' => 'WS01',
            'version' => '2.1',
            'merId' => $this->mer_id,
            'payType' => 'QRCODE_B2C',
            'charset' => $this->charset,
            'signType' => $this->sign_type
        ];
        $params = array_merge($publicParams, $params);

        $sign_order = ['tranCode', 'version', 'merId', 'submitTime', 'merOrderNum', 'tranAmt', 'payType', 'orgCode', 'notifyUrl', 'charset', 'signType'];
        $params['signMsg'] = $this->generateSignOld($params, $sign_order);

        $response = get_curl($apiurl, http_build_query($params));
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);

        if (isset($arr['resultCode']) && $arr['resultCode'] == '0000') {
            if (!empty($arr['signMsg'])) {
                $sign_order = ['tranCode', 'version', 'merId', 'merOrderNum', 'tranAmt', 'submitTime', 'qrCodeUrl', 'hnapayOrderId', 'resultCode', 'charset', 'signType'];
                if (!$this->verifySignOld($arr, $sign_order, $arr['signMsg'])) {
                    throw new Exception('返回结果验签失败');
                }
            }
            $arr['qrCodeUrl'] = getSubstr($arr['qrCodeUrl'], 'qrContent=', '&sign=');
            return $arr;
        } elseif (isset($arr['resultCode'])) {
            throw new Exception('[' . $arr['resultCode'] . ']' . $arr['msgExt']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //扫码支付查单
    public function scanQuery(string $trade_no): array
    {
        $apiurl = 'https://gateway.hnapay.com/website/queryOrderResult.htm';
        $param = [
            'version' => "2.8",
            'serialID' => date("YmdHis") . rand(11111, 99999),
            'mode' => '1',
            'type' => "1",
            'orderID' => $trade_no,
            'partnerID' => $this->mer_id,
            'signType' => $this->sign_type,
            'charset' => $this->charset,
        ];

        $sign_order = ['version', 'serialID', 'mode', 'type', 'orderID', 'beginTime', 'endTime', 'partnerID', 'remark', 'charset', 'signType'];
        $signStr = '';
        foreach ($sign_order as $key) {
            $signStr .= $key . '=' . ($param[$key] ?? '') . '&';
        }
        $signStr = substr($signStr, 0, -1);
        $param['signMsg'] = $this->rsaPrivateSign($signStr, true);

        $response = get_curl($apiurl, http_build_query($param));
        if (!$response) throw new Exception('接口请求失败');
        $arr = [];
        parse_str($response, $arr);

        if (isset($arr['resultCode']) && $arr['resultCode'] == '0000') {
            return $arr;
        } elseif (isset($arr['resultCode'])) {
            throw new Exception('[' . $arr['resultCode'] . ']' . $arr['ErrorCode']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //扫码支付回调验签
    public function scanVerify(array $param): bool
    {
        if (empty($param['signMsg'])) return false;
        $sign_order = ['tranCode', 'version', 'merId', 'merOrderNum', 'tranAmt', 'submitTime', 'hnapayOrderId', 'tranFinishTime', 'respCode', 'charset', 'signType'];
        return $this->verifySignOld($param, $sign_order, $param['signMsg']);
    }

    //JSAPI支付
    public function jsapiPay(array $params, string $trade_no): array
    {
        $apiurl = 'https://gateway.hnapay.com/ita/inCharge.do';
        $param = [
            'version' => "2.0",
            'tranCode' => "ITA10",
            'merId' => $this->mer_id,
            'merOrderId' => $trade_no,
            'submitTime' => substr($trade_no, 0, 14),
            'signType' => $this->sign_type,
            'charset' => $this->charset,
        ];

        $param['msgCiphertext'] = $this->encryptParams($params);

        $sign_order = ['version', 'tranCode', 'merId', 'merOrderId', 'submitTime', 'msgCiphertext'];
        $param['signValue'] = $this->generateSign($param, $sign_order);

        $response = get_curl($apiurl, http_build_query($param));
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);

        if (isset($arr['resultCode']) && $arr['resultCode'] == '0000') {
            if (!empty($arr['signValue'])) {
                $sign_order = ['version', 'tranCode', 'merOrderId', 'merId', 'charset', 'signType', 'resultCode', 'errorCode', 'hnapayOrderId', 'payInfo'];
                if (!$this->verifySign($arr, $sign_order, $arr['signValue'])) {
                    throw new Exception('返回结果验签失败');
                }
            }
            return $arr;
        } elseif (isset($arr['errorCode'])) {
            throw new Exception('[' . $arr['errorCode'] . ']' . $arr['errorMsg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //JSAPI支付与H5支付查单
    public function jsapiQuery(string $trade_no): array
    {
        $apiurl = 'https://gateway.hnapay.com/exp/query.do';
        $param = [
            'version' => "2.0",
            'tranCode' => "EXP08",
            'merId' => $this->mer_id,
            'merOrderId' => $trade_no,
            'submitTime' => substr($trade_no, 0, 8),
            'signType' => $this->sign_type,
            'charset' => $this->charset,
        ];

        $sign_order = ['version', 'tranCode', 'merId', 'merOrderId', 'submitTime'];
        $param['signValue'] = $this->generateSign($param, $sign_order);

        $response = get_curl($apiurl, http_build_query($param));
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);

        if (isset($arr['resultCode']) && $arr['resultCode'] == '0000') {
            return $arr;
        } elseif (isset($arr['errorCode'])) {
            throw new Exception('[' . $arr['errorCode'] . ']' . $arr['errorMsg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //JSAPI回调验签
    public function jsapiVerify(array $param): bool
    {
        if (empty($param['signValue'])) return false;
        $sign_order = ['version', 'tranCode', 'merOrderId', 'merId', 'merAttach', 'charset', 'signType', 'hnapayOrderId', 'resultCode', 'tranAmt', 'submitTime', 'tranFinishTime'];
        return $this->verifySign($param, $sign_order, $param['signValue']);
    }

    //支付宝H5支付
    public function h5Pay(array $params, string $trade_no): string
    {
        $apiurl = 'https://gateway.hnapay.com/multipay/h5.do';
        $param = [
            'version' => "2.0",
            'tranCode' => "MUP11",
            'merId' => $this->mer_id,
            'merOrderId' => $trade_no,
            'submitTime' => substr($trade_no, 0, 14),
            'signType' => $this->sign_type,
            'charset' => $this->charset,
        ];

        $param['msgCiphertext'] = $this->encryptParams($params);

        $sign_order = ['version', 'tranCode', 'merId', 'merOrderId', 'submitTime', 'signType', 'charset', 'msgCiphertext'];
        $param['signValue'] = $this->generateSign($param, $sign_order);

        $html = "<form id='alipaysubmit' name='alipaysubmit' action='{$apiurl}' method='POST'>";
        foreach ($param as $key => $value) {
            $value = htmlentities($value, ENT_QUOTES | ENT_HTML5);
            $html .= "<input type='hidden' name='{$key}' value='{$value}'/>";
        }
        $html .= "<input type='submit' value='ok' style='display:none;'></form>";
        $html .= "<script>document.forms['alipaysubmit'].submit();</script>";

        return $html;
    }

    //支付宝H5回调验签
    public function alipayh5Verify(array $param): bool
    {
        if (empty($param['signValue'])) return false;
        $sign_order = ['version', 'tranCode', 'merOrderId', 'merId', 'charset', 'signType', 'resultCode', 'hnapayOrderId'];
        return $this->verifySign($param, $sign_order, $param['signValue']);
    }

    //退款
    public function refund(array $params, string $trade_no): array
    {
        $apiurl = 'https://gateway.hnapay.com/exp/refund.do';
        $param = [
            'version' => "2.0",
            'tranCode' => "EXP09",
            'merId' => $this->mer_id,
            'merOrderId' => $trade_no,
            'submitTime' => date('YmdHis'),
            'signType' => $this->sign_type,
            'charset' => $this->charset,
        ];

        $param['msgCiphertext'] = $this->encryptParams($params);

        $sign_order = ['version', 'tranCode', 'merId', 'merOrderId', 'submitTime', 'msgCiphertext'];
        $param['signValue'] = $this->generateSign($param, $sign_order);

        $response = get_curl($apiurl, http_build_query($param));
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);

        if (isset($arr['resultCode']) && $arr['resultCode'] == '0000') {
            return $arr;
        } elseif (isset($arr['errorCode'])) {
            throw new Exception('[' . $arr['errorCode'] . ']' . $arr['errorMsg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //付款到银行
    public function transfer(array $params, string $trade_no): array
    {
        $apiurl = 'https://gateway.hnapay.com/website/singlePay.do';
        $param = [
            'version' => "2.1",
            'tranCode' => "SGP01",
            'merId' => $this->mer_id,
            'merOrderId' => $trade_no,
            'submitTime' => date('YmdHis'),
            'signType' => $this->sign_type,
            'charset' => $this->charset,
        ];

        $param['msgCiphertext'] = $this->encryptParams($params);

        $sign_order = ['version', 'tranCode', 'merId', 'merOrderId', 'submitTime', 'msgCiphertext', 'signType'];
        $param['signValue'] = $this->generateSign($param, $sign_order);

        $response = get_curl($apiurl, http_build_query($param));
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);

        if (isset($arr['resultCode']) && $arr['resultCode'] == '0000') {
            return $arr;
        } elseif (isset($arr['errorCode'])) {
            throw new Exception('[' . $arr['errorCode'] . ']' . $arr['errorMsg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //商户账户余额查询
    public function transferQuery(string $orderid): array
    {
        $apiurl = 'https://gateway.hnapay.com/website/singlePayQuery.do';
        $param = [
            'version' => "2.0",
            'tranCode' => "SGP02",
            'merOrderId' => $orderid,
            'submitTime' => substr($orderid, 0, 14),
            'signType' => $this->sign_type,
            'charset' => $this->charset,
        ];

        $sign_order = ['version', 'tranCode', 'merId', 'merOrderId', 'submitTime'];
        $param['signValue'] = $this->generateSign($param, $sign_order);

        $response = get_curl($apiurl, http_build_query($param));
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);

        if (isset($arr['resultCode']) && $arr['resultCode'] == '0000') {
            return $arr;
        } elseif (isset($arr['errorCode'])) {
            throw new Exception('[' . $arr['errorCode'] . ']' . $arr['errorMsg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //付款凭证下载
    public function transferProof(string $hnapayOrderId): array
    {
        $trade_no = date('YmdHis') . rand(1000, 9999);
        $apiurl = 'https://gateway.hnapay.com/website/payCertificate.do';
        $param = [
            'version' => "2.0",
            'tranCode' => "SGP03",
            'merId' => $this->mer_id,
            'merOrderId' => $trade_no,
            'submitTime' => date('YmdHis'),
            'signType' => $this->sign_type,
            'charset' => $this->charset,
        ];

        $params = [
            'hnapayOrderId' => $hnapayOrderId
        ];

        $param['msgCiphertext'] = $this->encryptParams($params);

        $sign_order = ['version', 'tranCode', 'merId', 'merOrderId', 'submitTime', 'msgCiphertext'];
        $param['signValue'] = $this->generateSign($param, $sign_order);

        $response = get_curl($apiurl, http_build_query($param));
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);

        if (isset($arr['resultCode']) && $arr['resultCode'] == '0000') {
            return $arr;
        } elseif (isset($arr['errorCode'])) {
            throw new Exception('[' . $arr['errorCode'] . ']' . $arr['errorMsg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //商户账户余额查询
    public function queryBalance(): array
    {
        $apiurl = 'https://gateway.hnapay.com/merchant/acct/queryBalance.do';
        $param = [
            'version' => "2.0",
            'tranCode' => "QB01",
            'merId' => $this->mer_id,
            'acctType' => '11',
            'signType' => $this->sign_type,
            'charset' => $this->charset,
        ];

        $sign_order = ['version', 'tranCode', 'merId', 'acctType', 'charset', 'signType'];
        $param['signValue'] = $this->generateSign($param, $sign_order);

        $response = get_curl($apiurl, http_build_query($param));
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);

        if (isset($arr['resultCode']) && $arr['resultCode'] == '0000') {
            return $arr;
        } elseif (isset($arr['errorCode'])) {
            throw new Exception('[' . $arr['errorCode'] . ']' . $arr['errorMsg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //付款回调验签
    public function transferVerify(array $param): bool
    {
        if (empty($param['signValue'])) return false;
        $sign_order = ['version', 'tranCode', 'merOrderId', 'merId', 'charset', 'signType', 'resultCode', 'hnapayOrderId'];
        return $this->verifySign($param, $sign_order, $param['signValue']);
    }

    public function quickPayRequest(array $params, string $trade_no): array
    {
        $apiurl = 'https://gateway.hnapay.com/exp/payRequest2Step.do';
        $param = [
            'version' => "2.0",
            'tranCode' => "EXP12",
            'merId' => $this->mer_id,
            'merOrderId' => $trade_no,
            'submitTime' => date('YmdHis'),
            'signType' => $this->sign_type,
            'charset' => $this->charset,
        ];

        $param['msgCiphertext'] = $this->encryptParams($params);

        $sign_order = ['version', 'tranCode', 'merId', 'merOrderId', 'submitTime', 'msgCiphertext'];
        $param['signValue'] = $this->generateSign($param, $sign_order);

        $response = get_curl($apiurl, http_build_query($param));
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);

        if (isset($arr['resultCode']) && $arr['resultCode'] == '0000') {
            return $arr;
        } elseif (isset($arr['errorCode'])) {
            throw new Exception('[' . $arr['errorCode'] . ']' . $arr['errorMsg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    public function quickPayConfirm(array $params, string $trade_no): array
    {
        $apiurl = 'https://gateway.hnapay.com/exp/payConfirm2Step.do';
        $param = [
            'version' => "2.0",
            'tranCode' => "EXP13",
            'merId' => $this->mer_id,
            'merOrderId' => $trade_no,
            'submitTime' => date('YmdHis'),
            'signType' => $this->sign_type,
            'charset' => $this->charset,
        ];

        $param['msgCiphertext'] = $this->encryptParams($params);

        $sign_order = ['version', 'tranCode', 'merId', 'merOrderId', 'submitTime', 'msgCiphertext'];
        $param['signValue'] = $this->generateSign($param, $sign_order);

        $response = get_curl($apiurl, http_build_query($param));
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);

        if (isset($arr['resultCode']) && ($arr['resultCode'] == '0000' || $arr['resultCode'] == '9999')) {
            return $arr;
        } elseif (isset($arr['errorCode'])) {
            throw new Exception('[' . $arr['errorCode'] . ']' . $arr['errorMsg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //快捷支付回调验签
    public function quickpayVerify(array $param): bool
    {
        if (empty($param['signValue'])) return false;
        $sign_order = ['version', 'tranCode', 'merOrderId', 'merId', 'charset', 'signType', 'resultCode', 'errorCode', 'hnapayOrderId', 'bizProtocolNo', 'payProtocolNo', 'tranAmt', 'checkDate', 'bankCode', 'cardType', 'shortCardNo'];
        return $this->verifySign($param, $sign_order, $param['signValue']);
    }

    //请求参数签名(新收款密钥)
    protected function generateSign(array $param, array $sign_order): string
    {
        $signStr = $this->getSignContent($param, $sign_order);
        return $this->rsaPrivateSign($signStr);
    }

    //请求参数签名(收款密钥)
    protected function generateSignOld(array $param, array $sign_order): string
    {
        $signStr = $this->getSignContent($param, $sign_order);
        return $this->rsaPrivateSign($signStr, true);
    }

    //参数验签(新收款密钥)
    protected function verifySign(array $param, array $sign_order, string $sign): bool
    {
        $signStr = $this->getSignContent($param, $sign_order);
        return $this->rsaPubilcVerify($signStr, $sign);
    }

    //参数验签(收款密钥)
    protected function verifySignOld(array $param, array $sign_order, string $sign): bool
    {
        $signStr = $this->getSignContent($param, $sign_order);
        return $this->rsaPubilcVerify($signStr, $sign, true);
    }

    //生成待签名字符串
    private function getSignContent(array $param, array $sign_order): string
    {
        $signStr = '';
        foreach ($sign_order as $key) {
            if (!isset($param[$key])) {
                throw new Exception('缺少参数' . $key);
            }
            $val = $param[$key];
            if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $signStr .= $key . '=[' . $val . ']';
        }
        return $signStr;
    }

    //请求参数加密
    protected function encryptParams(array $params): string
    {
        $key = openssl_get_publickey($this->platform_public_key);
        if (!$key) {
            throw new Exception('加密失败，平台公钥不正确');
        }
        $data = json_encode($params);
        $dataArray = str_split($data, 117);
        $crypted = '';
        foreach ($dataArray as $subData) {
            $subCrypted = null;
            openssl_public_encrypt($subData, $subCrypted, $key);
            $crypted .= $subCrypted;
        }
        $crypted = base64_encode($crypted);
        return $crypted;
    }

    //商户私钥签名
    protected function rsaPrivateSign(string $data, bool $is_hex = false): string
    {
        openssl_sign($data, $sign, $this->merchant_private_key, OPENSSL_ALGO_SHA1);
        $sign = $is_hex ? bin2hex($sign) : base64_encode($sign);
        return $sign;
    }

    //平台公钥验签
    protected function rsaPubilcVerify(string $data, string $sign, bool $is_hex = false): bool
    {
        $sign = $is_hex ? hex2bin($sign) : base64_decode($sign);
        $result = openssl_verify($data, $sign, $this->platform_public_key);
        return $result === 1;
    }

    //加载平台公钥
    private function loadPublicKey(string $public_key)
    {
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($public_key, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_get_publickey($res);
        if (!$pubkeyid) {
            throw new Exception('平台公钥不正确');
        }
        return $pubkeyid;
    }

    //加载商户私钥
    private function loadPrivateKey(string $private_key)
    {
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($private_key, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $prikeyid = openssl_get_privatekey($res);
        if (!$prikeyid) {
            throw new Exception('商户私钥不正确');
        }
        return $prikeyid;
    }

    //从文件加载平台公钥
    private function loadPublicKeyFile(string $public_key_path)
    {
        $res = file_get_contents($public_key_path);
        $pubkeyid = openssl_get_publickey($res);
        if (!$pubkeyid) {
            throw new Exception('平台公钥不正确');
        }
        return $pubkeyid;
    }

    //从文件加载商户私钥
    private function loadPrivateKeyFile(string $private_key_path)
    {
        $res = file_get_contents($private_key_path);
        $prikeyid = openssl_get_privatekey($res);
        if (!$prikeyid) {
            throw new Exception('商户私钥不正确');
        }
        return $prikeyid;
    }
}
