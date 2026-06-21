<?php

declare(strict_types=1);

namespace plugins\payment\yseqt;

/**
 * @see https://www.yuque.com/bgu2ty/eqt
 */
class YseqtClient
{
    //发起方商户号
    private string $srcMerchantNo;

    //银盛公钥证书
    private string $businessgateCertPath;

    //私钥证书文件
    private string $privateCertPath;

    //私钥密码
    private string $privateKeyPwd;

    private string $charset = 'UTF-8';
    private string $signType = 'RSA';
    private string $version = 'v2.0.0';

    public string $gateway_url = 'https://eqt.ysepay.com/api/trade';

    public function __construct(string $srcMerchantNo, string $privateKeyPwd, string $certFilePath)
    {
        $this->srcMerchantNo = $srcMerchantNo;
        $this->privateKeyPwd = $privateKeyPwd;
        $this->businessgateCertPath = PLUGIN_ROOT . 'payment/yseqt/cert/businessgate.cer';
        $this->privateCertPath = $certFilePath;
        if (!file_exists($this->businessgateCertPath)) {
            throw new \Exception('银盛公钥证书文件businessgate.cer不存在');
        }
        if (!file_exists($this->privateCertPath)) {
            throw new \Exception('商户私钥证书文件不存在');
        }
    }

    //交易接口
    public function execute(string $serviceNo, array $bizContent): mixed
    {
        $params = [
            'requestId' => getSid(),
            'srcMerchantNo' => $this->srcMerchantNo,
            'version' => $this->version,
            'charset' => $this->charset,
            'serviceNo' => $serviceNo,
            'signType' => $this->signType,
        ];
        $params['bizReqJson'] = json_encode($bizContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $params['sign'] = $this->generateSign($params);
        $response = $this->curl($this->gateway_url, $params);
        $result = json_decode($response, true);
        if (!empty($result['sign'])) {
            $this->verifyResponse($result);
        }
        if (isset($result['code']) && $result['code'] == 'SYS000') {
            return $result['bizResponseJson'];
        } else {
            throw new \Exception($result['msg'] ? $result['msg'] : '返回数据解析失败');
        }
    }

    //文件上传接口
    public function upload(string $serviceNo, array $bizContent): mixed
    {
        $url = 'https://eqt.ysepay.com/api/file';
        $params = [
            'requestId' => getSid(),
            'srcMerchantNo' => $this->srcMerchantNo,
            'version' => $this->version,
            'charset' => $this->charset,
            'serviceNo' => $serviceNo,
            'signType' => $this->signType,
        ];
        $params['sign'] = $this->generateSign($params);
        $params['bizReqJson'] = json_encode($bizContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = $this->curl($url, $params);
        $result = json_decode($response, true);
        if (!empty($result['sign'])) {
            $this->verifyResponse($result);
        }
        if (isset($result['code']) && $result['code'] == 'SYS000') {
            return $result['bizResponseJson'];
        } else {
            throw new \Exception($result['msg'] ? $result['msg'] : '返回数据解析失败');
        }
    }

    //异步通知回调验签
    public function verify(array $params): bool
    {
        if (!$params || !isset($params['sign'])) {
            return false;
        }
        $sign = $params['sign'];
        $data = $this->getSignContent($params);
        try {
            return $this->rsaPubilcVerify($data, $sign);
        } catch (\Exception $ex) {
            return false;
        }
    }

    //验证返回内容签名
    private function verifyResponse(array $params): void
    {
        $sign = $params['sign'];
        if (is_array($params['bizResponseJson'])) {
            $params['bizResponseJson'] = json_encode($params['bizResponseJson'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $data = $this->getSignContent($params);
        $checkResult = $this->rsaPubilcVerify($data, $sign);
        if (!$checkResult) {
            throw new \Exception('对返回数据使用银盛公钥验签失败');
        }
    }

    private function generateSign(array $params): string
    {
        return $this->rsaPrivateSign($this->getSignContent($params));
    }

    private function getSignContent(array $params): string
    {
        ksort($params);
        unset($params['sign']);

        $signStr = "";
        foreach ($params as $key => $val) {
            if (is_string($val) && substr($val, 0, 1) == '@' || $val === null) continue;
            $signStr .= $key . '=' . $val . '&';
        }
        return rtrim($signStr, '&');
    }

    //银盛公钥
    private function getPublicKey(): \OpenSSLAsymmetricKey
    {
        $file = file_get_contents($this->businessgateCertPath);
        $cert = chunk_split(base64_encode($file), 64, "\n");
        $cert = "-----BEGIN CERTIFICATE-----\n" . $cert . "-----END CERTIFICATE-----\n";
        $res = openssl_pkey_get_public($cert);
        if (!$res) {
            throw new \Exception('从银盛公钥证书获取公钥失败');
        }
        return $res;
    }

    //商户私钥
    private function getPrivateKey(): \OpenSSLAsymmetricKey
    {
        $file = file_get_contents($this->privateCertPath);
        if (!openssl_pkcs12_read($file, $cert, $this->privateKeyPwd)) {
            throw new \Exception('商户私钥证书解析失败');
        }
        return openssl_pkey_get_private($cert['pkey']);
    }

    //私钥加签
    private function rsaPrivateSign(string $data): string
    {
        $prikey = $this->getPrivateKey();
        $result = openssl_sign($data, $sign, $prikey);
        if (!$result) throw new \Exception('sign error');
        return base64_encode($sign);
    }

    //公钥验签
    public function rsaPubilcVerify(string $data, string $sign): bool
    {
        $pubkey = $this->getPublicKey();
        $result = openssl_verify($data, base64_decode($sign), $pubkey);
        return $result === 1;
    }

    //DES加密方法
    public function ECBEncrypt(string $data, string $key): string
    {
        $encrypted = openssl_encrypt($data, 'DES-ECB', $key, OPENSSL_RAW_DATA);
        return base64_encode($encrypted);
    }

    //DES解密方法
    public function ECBDecrypt(string $data, string $key): string
    {
        $encrypted = base64_decode($data);
        $decrypted = openssl_decrypt($encrypted, 'DES-ECB', $key, OPENSSL_RAW_DATA);
        return $decrypted;
    }

    private function curl(string $url, ?array $postFields = null): string
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if (is_array($postFields) && 0 < count($postFields)) {
            $postMultipart = false;
            foreach ($postFields as &$value) {
                if (is_string($value) && substr($value, 0, 1) == '@' && class_exists('CURLFile')) {
                    $postMultipart = true;
                    $file = substr($value, 1);
                    if (file_exists($file)) {
                        $value = new \CURLFile($file);
                    }
                }
            }
            curl_setopt($ch, CURLOPT_POST, true);
            if ($postMultipart) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
            }
        }

        $response = curl_exec($ch);

        if (curl_errno($ch) > 0) {
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new \Exception($errmsg, 0);
        }

        $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpStatusCode != 200) {
            curl_close($ch);
            throw new \Exception($response, $httpStatusCode);
        }

        curl_close($ch);

        return $response;
    }
}
