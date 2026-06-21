<?php

declare(strict_types=1);

namespace plugins\payment\yeepay;

use Exception;

class YopClient
{
    const VERSION = '3.1.14';

    private static string $serverRoot = "https://openapi.yeepay.com/yop-center";
    private static string $yosServerRoot = "https://yos.yeepay.com/yop-center";
    private static string $yopPublicKey = "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA6p0XWjscY+gsyqKRhw9MeLsEmhFdBRhT2emOck/F1Omw38ZWhJxh9kDfs5HzFJMrVozgU+SJFDONxs8UB0wMILKRmqfLcfClG9MyCNuJkkfm0HFQv1hRGdOvZPXj3Bckuwa7FrEXBRYUhK7vJ40afumspthmse6bs6mZxNn/mALZ2X07uznOrrc2rk41Y2HftduxZw6T4EmtWuN2x4CZ8gwSyPAW5ZzZJLQ6tZDojBK4GZTAGhnn3bg5bBsBlw2+FLkCQBuDsJVsFPiGh/b6K/+zGTvWyUcu+LUj2MejYQELDO3i2vQXVDk7lVi2/TcUYefvIcssnzsfCfjaorxsuwIDAQAB";

    private string $appKey;
    private string $secretKey;
    private bool $downRequest = false;

    public function __construct(string $appKey, string $secretKey)
    {
        $this->appKey = $appKey;
        $this->secretKey = $secretKey;
    }

    //发起GET请求
    public function get(string $path, ?array $params = null): mixed
    {
        return $this->request('GET', $path, $params);
    }

    //发起POST请求
    public function post(string $path, array $params): mixed
    {
        return $this->request('POST', $path, $params);
    }

    //发起上传请求
    public function upload(string $path, array $params): mixed
    {
        return $this->request('POST', $path, $params, true);
    }

    //发起请求并解析返回结果
    public function request(string $httpMethod, string $path, ?array $params = null, bool $file = false): mixed
    {
        $requrl = ($file ? self::$yosServerRoot : self::$serverRoot) . $path;
        if ($httpMethod == 'GET' && $params) {
            $requrl .= '?' . http_build_query($params);
        }

        foreach ($params as &$value) {
            if ($value instanceof \CURLFile || substr((string) $value, 0, 1) == '@') continue;
            $value = rawurlencode((string) $value);
        }

        $headers = $this->getSignedHeaders($httpMethod, $path, $params);

        if ($httpMethod == 'POST') {
            $response = $this->curl($requrl, $params, $headers);
        } else {
            $response = $this->curl($requrl, null, $headers);
        }

        if ($this->downRequest) return $response;

        $result = json_decode($response, true);
        if (isset($result['result'])) {
            return $result['result'];
        } elseif (isset($result['subMessage'])) {
            throw new Exception('[' . $result['subCode'] . ']' . $result['subMessage']);
        } elseif (isset($result['message'])) {
            throw new Exception($result['message']);
        } elseif (isset($result['error'])) {
            throw new Exception($result['error']['message']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //结果通知解密
    public function notifyDecrypt(string $source): ?array
    {
        //分解参数
        $args = explode('$', $source);

        if (count($args) != 4) {
            throw new Exception('invalid response');
        }

        $encryptedRandomKeyToBase64 = $args[0];
        $encryptedDataToBase64 = $args[1];
        $symmetricEncryptAlg = $args[2];
        $digestAlg = $args[3];

        //用私钥对随机密钥进行解密
        $randomKey = $this->rsaPrivateDecrypt($encryptedRandomKeyToBase64);
        if (!$randomKey) {
            throw new Exception('randomKey decrypt fail');
        }

        $encryptedData = openssl_decrypt(self::base64_urldecode($encryptedDataToBase64), "AES-128-ECB", $randomKey, OPENSSL_RAW_DATA);
        if (!$encryptedData) {
            throw new Exception('data decrypt fail');
        }

        //分解参数
        $signToBase64 = substr(strrchr($encryptedData, '$'), 1);
        $sourceData = substr($encryptedData, 0, strlen($encryptedData) - strlen($signToBase64) - 1);

        if ($this->rsaPublicVerify($sourceData, $signToBase64, $digestAlg)) {
            return json_decode($sourceData, true);
        } else {
            throw new Exception('verify sign fail');
        }
    }

    //获取签名头部
    private function getSignedHeaders(string $httpMethod, string $path, ?array $params): array
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z', time());
        $headers = [];

        $headers['x-yop-appkey'] = $this->appKey;
        $headers['x-yop-request-id'] = self::uuid();

        $protocolVersion = "yop-auth-v2";
        $expiredSeconds = "1800";

        $authString = $protocolVersion . "/" . $this->appKey . "/" . $timestamp . "/" . $expiredSeconds;

        $headersToSignSet = ['x-yop-request-id'];

        $canonicalQueryString = $this->getCanonicalQueryString($params);

        $headersToSign = $this->getHeadersToSign($headers, $headersToSignSet);

        $canonicalHeader = self::getCanonicalHeaders($headersToSign);

        $signedHeaders = "";
        foreach ($headersToSign as $key => $value) {
            $signedHeaders .= strlen($signedHeaders) == 0 ? "" : ";";
            $signedHeaders .= $key;
        }
        $signedHeaders = strtolower($signedHeaders);

        $canonicalRequest = $authString . "\n" . $httpMethod . "\n" . $path . "\n" . $canonicalQueryString . "\n" . $canonicalHeader;

        $signToBase64 = $this->rsaPrivateSign($canonicalRequest);

        $headers['Authorization'] = "YOP-RSA2048-SHA256 " . $protocolVersion . "/" . $this->appKey . "/" . $timestamp . "/" . $expiredSeconds . "/" . $signedHeaders . "/" . $signToBase64;
        return $headers;
    }

    //获取规范查询字符串
    private function getCanonicalQueryString(?array $params): string
    {
        if (empty($params)) return '';
        ksort($params);
        $str = '';
        foreach ($params as $k => $v) {
            if ($v instanceof \CURLFile || substr((string) $v, 0, 1) == '@') continue;
            $str .= $k . '=' . $v . '&';
        }
        $str = substr($str, 0, -1);
        return $str;
    }

    //获取待签名标头
    private function getHeadersToSign(array $headers, array $headersToSign): array
    {
        $ret = [];
        foreach ($headersToSign as &$header) {
            $header = strtolower($header);
        }

        foreach ($headers as $key => $value) {
            if (!empty($value)) {
                if (in_array(strtolower($key), $headersToSign) && $key != "Authorization") {
                    $ret[$key] = $value;
                }
            }
        }
        ksort($ret);
        return $ret;
    }

    //获取规范标头
    private static function getCanonicalHeaders(array $headers): string
    {
        if (empty($headers)) return '';
        $str = '';
        foreach ($headers as $key => $value) {
            $key = strtolower($key);
            $value = trim($value);
            $str .= strtolower($key) . ':' . trim($value) . "\n";
        }
        $str = substr($str, 0, -1);
        return $str;
    }

    //商户私钥签名
    private function rsaPrivateSign(string $data, string $digestAlg = 'SHA256'): string
    {
        $key = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($this->secretKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $privatekey = openssl_pkey_get_private($key);
        if (!$privatekey) {
            throw new Exception('签名失败，商户私钥错误');
        }
        openssl_sign($data, $sign, $privatekey, $digestAlg);
        $signToBase64 = self::base64_urlencode($sign);
        $signToBase64 .= '$SHA256';
        return $signToBase64;
    }

    //平台公钥验签
    private function rsaPublicVerify(string $data, string $sign, string $digestAlg = 'SHA256'): bool
    {
        $key = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap(self::$yopPublicKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $publickey = openssl_pkey_get_public($key);
        if (!$publickey) {
            throw new Exception("invalid public key");
        }
        $result = openssl_verify($data, self::base64_urldecode($sign), $publickey, $digestAlg);
        return $result === 1;
    }

    //商户私钥解密
    private function rsaPrivateDecrypt(string $data): string
    {
        $key = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($this->secretKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $privatekey = openssl_pkey_get_private($key);
        if (!$privatekey) {
            throw new Exception('invalid private key');
        }
        openssl_private_decrypt(self::base64_urldecode($data), $decrypted, $privatekey);
        return $decrypted;
    }

    private function curl(string $url, ?array $postFields, array $headers): string
    {
        $uaString = "php/" . self::VERSION . "/" . PHP_OS . "/" . ($_SERVER['SERVER_SOFTWARE'] ?? '') . "/Zend Framework/" . zend_version() . "/" . PHP_VERSION . "/" . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') . "/";

        $headerArray = [];
        foreach ($headers as $key => $value) {
            $headerArray[] = $key . ": " . $value;
        }
        $headerArray[] = 'x-yop-sdk-langs: php';
        $headerArray[] = 'x-yop-sdk-version: ' . self::VERSION;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
        curl_setopt($ch, CURLOPT_USERAGENT, $uaString);

        if (is_array($postFields) && 0 < count($postFields)) {
            $postMultipart = false;
            foreach ($postFields as &$value) {
                if ($value instanceof \CURLFile) {
                    $postMultipart = true;
                } elseif (substr((string) $value, 0, 1) == '@' && class_exists('CURLFile')) {
                    $postMultipart = true;
                    $file = substr((string) $value, 1);
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
            throw new Exception($errmsg, 0);
        }
        $responseHeaders = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if (!empty($responseHeaders) && substr_compare($responseHeaders, "application/octet-stream", 0, 16) == 0) {
            $this->downRequest = true;
        }

        curl_close($ch);

        return $response;
    }

    private static function base64_urlencode(string $data, bool $use_padding = false): string
    {
        $encoded = strtr(base64_encode($data), '+/', '-_');

        return true === $use_padding ? $encoded : rtrim($encoded, '=');
    }

    private static function base64_urldecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    private static function uuid(string $namespace = ''): string
    {
        $uid = uniqid("", true);
        $data = (string) ($_SERVER['REQUEST_TIME'] ?? time());
        $hash = hash('ripemd128', $uid . $data);

        return $namespace .
            substr($uid, 0, 14) .
            substr($uid, 15, 24) .
            substr($hash, 0, 10);
    }
}
