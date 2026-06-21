<?php

declare(strict_types=1);

namespace plugins\payment\yizhifubj;

use Exception;

class PayClient
{
    private string $apiurl = 'https://apis.5upay.com';
    private string $merchantId;
    private string $partnerId;
    private string $certpass;
    private string $public_key_path;
    private string $private_key_path;

    public function __construct(string $merchantId, string $partnerId, string $certpass, string $private_key_path, string $public_key_path)
    {
        $this->merchantId = $merchantId;
        $this->partnerId = $partnerId;
        $this->certpass = $certpass;
        $this->private_key_path = $private_key_path;
        $this->public_key_path = $public_key_path;
    }

    // 发起请求
    public function submit(string $path, array $params): array
    {
        //1.Submit parameter is an array.The parameter sequence is spliced from A-Z with the initials of key name, and The corresponding key values are separated by # .(Array to string)
        $hmacSource = $this->buildJson($params);

        //2.This string is encrypted with SHA1
        $sha1mac = sha1($hmacSource, true);

        //3.After SHA1 encryption, sign with private key to calculate HMAC
        $hmac = $this->rsaPrivateSign($sha1mac, $this->private_key_path, $this->certpass);

        //4.HMAC merges with the submitted array, and then converts the JSON string
        $params['hmac'] = $hmac;
        $json_str = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        //5.Encrypt the JSON string with a 16-bit random AES key to get the request body "data"
        $str1 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz1234567890';
        $randStr = str_shuffle($str1);
        $screct_key = substr($randStr, 0, 16);
        $data = $this->aseEncrypt($json_str, $screct_key);

        //6.Encrypt the 16-bit AES key with the certificate public key to get the request header "encryptKey"
        $encryptKey = $this->rsaPublicEncrypt($screct_key, $this->public_key_path);

        $requestId = $params['requestId'];
        $responseText = $this->curl($path, $data, $encryptKey, $requestId);
        $encryptKey = '';
        $merchantId = '';
        $data = '';
        if (preg_match('/encryptkey: (.*?)\r\n/i', $responseText, $match)) {
            $encryptKey = $match[1];
        }
        if (preg_match('/merchantid: (\d+)\r\n/i', $responseText, $match)) {
            $merchantId = $match[1];
        }
        if (preg_match('/\"data\":\"(.*?)\"/', $responseText, $match)) {
            $data = $match[1];
        }
        if (!$encryptKey || !$merchantId || !$data) {
            throw new Exception('返回数据解析失败 ' . $responseText);
        }

        //1.Get the request header "encryptedKey", decrypt it with the certificate private key, and get the AES key()
        $screct_key = $this->rsaPrivateDecrypt($encryptKey, $this->private_key_path, $this->certpass);
        if (!$screct_key) {
            throw new Exception('AES密钥解密失败');
        }

        //2.Get the request body "data", decrypt it with the AES key, and get the array.($encrypt_str)
        $data = $this->aseDecrypt($data, $screct_key);
        if (!$data) {
            throw new Exception('返回数据AES解密失败');
        }
        $array = json_decode($data, true);
        return $array;
    }

    // 获取异步回调数据
    public function getNotify(): array|false
    {
        $raw_post_data = request()->getContent();
        $responsedata = json_decode($raw_post_data, true);
        if (!$responsedata || !isset($responsedata['data'])) return false;
        $encryptKey = request()->header('encryptkey');
        $merchantId = request()->header('merchantid');
        $requestId = request()->header('requestid');
        if (!$encryptKey) return false;

        $screct_key = $this->rsaPrivateDecrypt($encryptKey, $this->private_key_path, $this->certpass);

        $data = $this->aseDecrypt($responsedata['data'], $screct_key);
        if (!$data) {
            throw new Exception('返回数据AES解密失败');
        }

        $array = json_decode($data, true);
        return $array;
    }

    // 异步回调验签
    public function verifyNotify(array $params): bool
    {
        $hmac = $params['hmac'];
        $hmacSource = $this->buildJson($params);
        $sha1mac = sha1($hmacSource, true);
        $verify = $this->rsaPubilcSign($sha1mac, $this->public_key_path, $hmac);
        return $verify === 1;
    }

    // 键名首字母自动排序
    private function buildJson(array $params): string
    {
        unset($params['hmac']);
        $data = array();
        foreach ($params as $k => $var) {
            if (is_scalar($var) && $var !== '' && $var !== null) {
                $data[$k] = $var;
            } else if (is_object($var)) {
                $data[$k] = array_filter((array) $var);
            } else if (is_array($var)) {
                $data[$k] = array_filter($var);
            }
        }
        ksort($data);
        $hmacSource = '';
        foreach ($data as $value) {
            if (is_array($value)) {
                ksort($value);
                foreach ($value as $value2) {
                    if (is_object($value2)) {
                        $value2 = array_filter((array)$value2);
                        ksort($value2);
                        foreach ($value2 as $oValue) {
                            $oValue .= '#';
                            $hmacSource .= trim($oValue);
                        }
                    } else if (is_array($value2)) {
                        ksort($value2);
                        foreach ($value2 as $value3) {
                            if (is_object($value3)) {
                                $value3 = array_filter((array)$value3);
                                ksort($value3);
                                foreach ($value3 as $oValue) {
                                    $oValue .= '#';
                                    $hmacSource .= trim($oValue);
                                }
                            } else {
                                $value3 .= '#';
                                $hmacSource .= trim($value3);
                            }
                        }
                    } else {
                        $value2 .= '#';
                        $hmacSource .= trim($value2);
                    }
                }
            } else {
                $value .= '#';
                $hmacSource .= trim($value);
            }
        }
        return $hmacSource;
    }

    // CFCA公钥加密
    private function rsaPublicEncrypt(string $data, string $public_key_path): string
    {
        $encryptKey = file_get_contents($public_key_path);
        $pem = chunk_split(base64_encode($encryptKey), 64, "\n"); //转换为pem格式的公钥
        $public_key = "-----BEGIN CERTIFICATE-----\n" . $pem . "-----END CERTIFICATE-----\n";
        $pu_key = openssl_pkey_get_public($public_key);
        if (!$pu_key) {
            throw new Exception('公钥加密失败，公钥不正确');
        }
        openssl_public_encrypt($data, $encrypted, $pu_key);
        $encryptKey = base64_encode($encrypted);
        return $encryptKey;
    }

    // CFCA私钥解密
    private function rsaPrivateDecrypt(string $data, string $private_key_path, string $password): string
    {
        $prikey = file_get_contents($private_key_path);
        $results = array();
        openssl_pkcs12_read($prikey, $results, $password);
        $private_key = $results['pkey'];
        $pi_key = openssl_pkey_get_private($private_key);
        if (!$pi_key) {
            throw new Exception('私钥解密失败，私钥不正确');
        }
        openssl_private_decrypt(base64_decode($data), $decrypted, $pi_key);
        return $decrypted;
    }

    // CFCA私钥签名
    private function rsaPrivateSign(string $data, string $private_key_path, string $password): string
    {
        $pubKey = file_get_contents($private_key_path);
        $results = array();
        openssl_pkcs12_read($pubKey, $results, $password);
        $private_key = $results['pkey'];
        $pi_key = openssl_pkey_get_private($private_key); //这个函数可用来判断私钥是否是可用的，可用返回资源id Resource id
        if (!$pi_key) {
            throw new Exception('私钥签名失败，私钥不正确');
        }
        openssl_sign($data, $signature, $private_key, "md5");
        $signature = base64_encode($signature);
        return $signature;
    }

    // CFCA公钥验签
    private function rsaPubilcSign(string $data, string $public_key_path, string $hmac): int
    {
        $public_key = file_get_contents($public_key_path);
        $pem1 = chunk_split(base64_encode($public_key), 64, "\n");
        $pem1 = "-----BEGIN CERTIFICATE-----\n" . $pem1 . "-----END CERTIFICATE-----\n";
        $pi_key = openssl_pkey_get_public($pem1);
        if (!$pi_key) {
            throw new Exception('公钥验签失败，公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($hmac), $pem1, OPENSSL_ALGO_MD5);
        return $result;
    }

    // AES填充方法
    private function addPKCS7Padding(string $string, int $blocksize = 16): string
    {
        $len = strlen($string);
        $pad = $blocksize - ($len % $blocksize);
        $string .= str_repeat(chr($pad), $pad);
        return $string;
    }

    // AES加密
    private function aseEncrypt(string $string, string $screct_key): string
    {
        $string = trim($string);
        $string = $this->addPKCS7Padding($string);
        $encrypt_str = openssl_encrypt($string, 'AES-128-ECB', $screct_key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        return base64_encode($encrypt_str);
    }

    // AES解密
    private function aseDecrypt(string $string, string $screct_key): string
    {
        $string = openssl_decrypt($string, "AES-128-ECB", $screct_key);
        $string = preg_replace('/[\x00-\x1F]/', '', $string);
        return $string;
    }

    private function curl(string $path, string $data, string $encryptKey, string $requestId): string
    {
        $url = $this->apiurl . $path;
        $headers = [
            'Content-Type: application/vnd.5upay-v3.0+json',
            'encryptKey: ' . $encryptKey,
            'merchantId: ' . $this->merchantId,
            'requestId: ' . $requestId
        ];
        if (!empty($this->partnerId)) {
            $headers[] = 'partnerId: ' . $this->partnerId;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($ch);
        if ($errno = curl_errno($ch)) {
            curl_close($ch);
            throw new Exception('curl请求失败errno:' . $errno);
        }
        curl_close($ch);
        return $ret;
    }
}
