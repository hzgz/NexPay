<?php
namespace plugins\payment\hlpay2;

use Exception;

/**
 * @see https://www.yuque.com/hlwl/sbh9lh
 */
class HlpayClient
{
    private $gateway_url = 'https://openapi.3ypay.com';
    private $sub_sn;
    private $app_id;
    private $merchant_private_key;
    private $platform_public_key;
    private $sign_type = 'RSA2';
    private $version = '1.0';

    public function __construct($app_id, $merchant_private_key, $platform_public_key, $sub_sn = null)
    {
        $this->sub_sn = $sub_sn;
        $this->app_id = $app_id;
        $this->merchant_private_key = $merchant_private_key;
        $this->platform_public_key = $platform_public_key;
    }

    public function execute($path, $bizContent)
    {
        $requrl = $this->gateway_url . $path;
        $params = [
            'appId' => $this->app_id,
            'subSn' => $this->sub_sn,
            'timestamp' => intval(getMillisecond()),
            'requestId' => getSid(),
            'version' => $this->version,
            'signType' => $this->sign_type,
            'charset' => 'UTF-8',
            'bizContent' => json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];
        $params['sign'] = $this->generateSign($params);
        $response = get_curl($requrl, json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 0, 0, 0, 0, ['Content-Type: application/json']);
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 200) {
            if (!$this->verifySign($result)) {
                throw new Exception('返回数据验签失败');
            }
            return json_decode($result['data'], true);
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    private function getSignContent($param)
    {
        ksort($param);
        $signstr = '';
        foreach ($param as $k => $v) {
            if ($k != "sign" && !isNullOrEmpty($v)) {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        return $signstr;
    }

    private function generateSign($param)
    {
        return $this->rsaPrivateSign($this->getSignContent($param));
    }

    public function verifySign($param)
    {
        if (empty($param['sign'])) return false;
        return $this->rsaPubilcVerify($this->getSignContent($param), $param['sign']);
    }

    private function rsaPrivateSign($data)
    {
        $priKey = $this->merchant_private_key;
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $pkeyid = openssl_pkey_get_private($res);
        if (!$pkeyid) {
            throw new Exception('签名失败，商户私钥不正确');
        }
        openssl_sign($data, $signature, $pkeyid, OPENSSL_ALGO_SHA256);
        $signature = base64_encode($signature);
        return $signature;
    }

    private function rsaPubilcVerify($data, $signature)
    {
        $pubKey = $this->platform_public_key;
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new Exception('验签失败，平台公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($signature), $pubkeyid, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }
}
