<?php
namespace plugins\payment\heepaybank;

use Exception;

class HeepayClient
{
    private $sign_type = 'RSA1';
    private $version = '1.0';
    private $mchid;
    private $platform_public_key;
    private $merchant_private_key;

    public function __construct($mchid, $platform_public_key, $merchant_private_key)
    {
        $this->mchid = $mchid;
        $this->platform_public_key = $platform_public_key;
        $this->merchant_private_key = $merchant_private_key;
    }

    //发起API请求
    public function submit($method, $biz_content)
    {
        $requrl = 'https://pay.heepay.com/API/Cashier/ApplyPay.aspx';
        $data = json_encode($biz_content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $params = [
            'method' => $method,
            'version' => $this->version,
            'merch_id' => $this->mchid,
            'biz_content' => $data,
            'timestamp' => date('Y-m-d H:i:s'),
            'sign_type' => $this->sign_type,
        ];

        $params['sign'] = $this->generateSign($params);

        $params['biz_content'] = $this->rsaPublicEncrypt(str_replace('\/', '/', $params['biz_content']));

        $response = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 10000) {
            $result['data'] = $this->rsaPrivateDecrypt($result['data']);
            if (!$result['data']) {
                throw new Exception('返回数据解密失败');
            }
            if (!$this->verifySign($result)) {
                throw new Exception('返回数据验签失败');
            }
            return json_decode($result['data'], true) ?? [];
        } elseif (isset($result['sub_msg'])) {
            throw new Exception('[' . $result['sub_code'] . ']' . $result['sub_msg']);
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    public function notify($param)
    {
        if (!isset($param['sign']) || !isset($param['encrypt_data'])) return false;
        $data = $this->rsaPrivateDecrypt($param['encrypt_data']);
        parse_str($data, $arr);
        if (!$arr) return false;

        if (!$this->rsaPubilcVerify($this->getSignContent($arr), $param['sign'])) {
            return false;
        }

        return $arr;
    }

    //获取待签名字符串
    private function getSignContent($param)
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign") {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        return $signstr;
    }

    //请求参数签名
    private function generateSign($param)
    {
        return $this->rsaPrivateSign($this->getSignContent($param));
    }

    //验签方法
    public function verifySign($param)
    {
        if (empty($param['sign'])) return false;
        return $this->rsaPubilcVerify($this->getSignContent($param), $param['sign']);
    }

    //商户私钥签名
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
        openssl_sign($data, $signature, $pkeyid);
        $signature = base64_encode($signature);
        return $signature;
    }

    //平台公钥验签
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
        $result = openssl_verify($data, base64_decode($signature), $pubkeyid);
        return $result === 1;
    }

    //平台公钥加密
    private function rsaPublicEncrypt($data)
    {
        $pubKey = $this->platform_public_key;
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new Exception('加密失败，平台公钥不正确');
        }

        $encrypted = '';
        $partLen = openssl_pkey_get_details($pubkeyid)['bits'] / 8 - 11;
        $plainData = str_split($data, $partLen);
        foreach ($plainData as $chunk) {
            $partialEncrypted = '';
            $encryptionOk = openssl_public_encrypt($chunk, $partialEncrypted, $pubkeyid);
            if ($encryptionOk === false) {
                return false;
            }
            $encrypted .= $partialEncrypted;
        }
        return base64_encode($encrypted);
    }

    //商户私钥解密
    private function rsaPrivateDecrypt($data)
    {
        $priKey = $this->merchant_private_key;
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $pkeyid = openssl_pkey_get_private($res);
        if (!$pkeyid) {
            throw new Exception('解密失败，商户私钥不正确');
        }

        $decrypted = '';
        $partLen = openssl_pkey_get_details($pkeyid)['bits'] / 8;
        $data = str_split(base64_decode($data), $partLen);
        foreach ($data as $chunk) {
            $partial = '';
            $decryptionOK = openssl_private_decrypt($chunk, $partial, $pkeyid);
            if ($decryptionOK === false) {
                return false;
            }
            $decrypted .= $partial;
        }
        return $decrypted;
    }
}
