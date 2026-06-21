<?php

declare(strict_types=1);

namespace plugins\payment\unionpay;

use Exception;

class SwiftpassClient
{
    private string $version = '2.0';
    private string $signType = 'MD5';
    private string $gatewayUrl = 'https://pay.swiftpass.cn/pay/gateway';
    private string $mchId;
    private string $key = '';
    private string $rsaPrivateKey = '';
    private string $rsaPublicKey = '';

    public function __construct(array $config)
    {
        $this->signType = $config['sign_type'];
        if (empty($config['mchid'])) {
            throw new \InvalidArgumentException("商户号不能为空");
        }
        $this->mchId = $config['mchid'];
        if ($this->signType == 'MD5') {
            if (empty($config['key'])) {
                throw new \InvalidArgumentException("商户密钥不能为空");
            }
            $this->key = $config['key'];
        }
        if (substr($this->signType, 0, 3) == 'RSA') {
            if (empty($config['rsa_private_key']) || empty($config['rsa_public_key'])) {
                throw new \InvalidArgumentException("商户私钥/平台公钥不能为空");
            }
            $this->rsaPrivateKey = $config['rsa_private_key'];
            $this->rsaPublicKey = $config['rsa_public_key'];
        }
        if (!empty($config['gateway_url'])) {
            $this->gatewayUrl = $config['gateway_url'];
        }
    }

    public function getMchId(): string
    {
        return $this->mchId;
    }

    /**
     * 发起请求并解析返回结果
     */
    public function requestApi(array $params): array
    {
        if (empty($params['service'])) {
            throw new \InvalidArgumentException("service参数不能为空");
        }
        $publicParams = [
            'mch_id' => $this->mchId,
            'version' => $this->version,
            'sign_type' => $this->signType,
            'nonce_str' => $this->getNonceStr()
        ];
        $params = array_merge($publicParams, $params);
        $params['sign'] = $this->makeSign($params);

        $xml = $this->array2Xml($params);
        $response = $this->curl($this->gatewayUrl, $xml);
        $result = $this->xml2array($response);
        if (isset($result['status']) && $result['status'] == '0') {
            if (!$this->verifySign($result)) {
                throw new Exception('返回数据签名校验失败');
            }
            if (isset($result['result_code']) && $result['result_code'] == '0') {
                return $result;
            } else {
                throw new Exception('[' . $result['err_code'] . ']' . $result['err_msg']);
            }
        } elseif (isset($result['message'])) {
            throw new Exception($result['message']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    /**
     * 支付结果通知
     */
    public function notify(): array
    {
        $xml = file_get_contents("php://input");
        if (empty($xml)) {
            throw new Exception('no_data');
        }
        $result = $this->xml2array($xml);
        if (!$result) {
            throw new Exception('xml_error');
        }
        if (!$this->verifySign($result)) {
            throw new Exception('sign_error');
        }
        return $result;
    }

    private function verifySign(array $params): bool
    {
        if (!isset($params['sign'])) return false;
        $signStr = $this->getSignContent($params);
        if (substr($this->signType, 0, 3) == 'RSA') {
            return $this->rsaPublicVerify($signStr, $params['sign']);
        } else {
            $sign = strtoupper(md5($signStr . '&key=' . $this->key));
            return $params['sign'] === $sign;
        }
    }

    private function makeSign(array $params): string
    {
        $signStr = $this->getSignContent($params);
        if (substr($this->signType, 0, 3) == 'RSA') {
            return $this->rsaPrivateSign($signStr);
        } else {
            return strtoupper(md5($signStr . '&key=' . $this->key));
        }
    }

    private function getSignContent(array $params): string
    {
        ksort($params);
        $signStr = '';
        foreach ($params as $k => $v) {
            if ($k != 'sign' && !$this->isEmpty($v)) {
                $signStr .= $k . '=' . $v . '&';
            }
        }
        return substr($signStr, 0, -1);
    }

    private function rsaPrivateSign(string $data): string
    {
        $key = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($this->rsaPrivateKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $privatekey = openssl_get_privatekey($key);
        if (!$privatekey) {
            throw new Exception("签名失败，商户RSA私钥错误");
        }
        if ($this->signType == 'RSA_1_1') {
            openssl_sign($data, $sign, $privatekey);
        } else {
            openssl_sign($data, $sign, $privatekey, OPENSSL_ALGO_SHA256);
        }
        return base64_encode($sign);
    }

    private function rsaPublicVerify(string $data, string $sign): bool
    {
        $key = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($this->rsaPublicKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $publickey = openssl_get_publickey($key);
        if (!$publickey) {
            throw new Exception("验签失败，平台RSA公钥错误");
        }
        if ($this->signType == 'RSA_1_1') {
            $result = openssl_verify($data, base64_decode($sign), $publickey);
        } else {
            $result = openssl_verify($data, base64_decode($sign), $publickey, OPENSSL_ALGO_SHA256);
        }
        return $result === 1;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private function getNonceStr(int $length = 32): string
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }

    private function array2Xml(array $data): string
    {
        $xml = '<xml>';
        foreach ($data as $key => $val) {
            $xml .= (is_numeric($val) ? "<{$key}>{$val}</{$key}>" : "<{$key}><![CDATA[{$val}]]></{$key}>");
        }
        return $xml . '</xml>';
    }

    private function xml2array(string $xml): array|false
    {
        if (!$xml) {
            return false;
        }
        return json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA), JSON_UNESCAPED_UNICODE), true);
    }

    private function curl(string $url, string $xml, int $second = 10): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $second);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        $data = curl_exec($ch);
        if (curl_errno($ch) > 0) {
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new Exception('call http err :' . $errmsg, 0);
        }
        $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpStatusCode != 200) {
            curl_close($ch);
            throw new Exception('call http err httpcode=' . $httpStatusCode, $httpStatusCode);
        }
        curl_close($ch);
        return $data;
    }
}
