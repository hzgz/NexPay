<?php

declare(strict_types=1);

namespace plugins\payment\shengpay;

use Exception;

/**
 * https://docs.shengpay.com/
 */
class ShengPayClient
{
    //接口地址
    private string $gatewayUrl = 'https://mchapi.shengpay.com';

    //商户号
    private string $mchId;

    //商户私钥
    private string $mchPrivateKey;

    //盛付通公钥
    private string $sdpPublicKey = 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCmnlzMPhklUfsN8+enQkXk8vA5fGSGOIp6eHzExL7znaUN3bs9GORbvpPDVis8nWdKpgX6TeFP1L2hsVoNXkWZM2MX3P72HJQYdhcVpzhgMj3zlW3KI2/HQyns6Ub6yJNGTajXYEqy+qKY3I7EQETMI3vN128182lMaxwLmVikRQIDAQAB';

    private string $signType = 'RSA';

    public function __construct(string $mchId, string $mchPrivateKey)
    {
        $this->mchId = $mchId;
        $this->mchPrivateKey = $mchPrivateKey;
    }

    //请求API接口并解析返回数据
    public function execute(string $path, array $params): array
    {
        $requrl = $this->gatewayUrl . $path;
        $params['mchId'] = $this->mchId;
        $params['nonceStr'] = md5(uniqid((string)mt_rand(), true) . microtime());
        $params['signType'] = $this->signType;
        $params['sign'] = $this->generateSign($params);
        $response = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['returnCode']) && $result['returnCode'] == 'SUCCESS') {
            if ($result['resultCode'] == 'SUCCESS') {
                if (isset($result['sign'])) {
                    if (!$this->verifySign($result)) {
                        throw new Exception('返回数据验签失败');
                    }
                }
                return $result;
            } else {
                throw new Exception('[' . $result['errorCode'] . ']' . $result['errorCodeDes']);
            }
        } elseif (isset($result['returnMsg'])) {
            throw new Exception($result['returnMsg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //上传文件
    public function upload(string $path, array $params, string $filepath, string $filename): array
    {
        $requrl = $this->gatewayUrl . $path;
        $params['mchId'] = $this->mchId;
        $params['nonceStr'] = md5(uniqid((string)mt_rand(), true) . microtime());
        $params['signType'] = $this->signType;
        $params['sign'] = $this->generateSign($params);
        $body = [
            'file' => new \CURLFile($filepath, null, $filename),
            'metaData' => json_encode($params)
        ];
        $response = get_curl($requrl, $body);
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['returnCode']) && $result['returnCode'] == 'SUCCESS') {
            if ($result['resultCode'] == 'SUCCESS') {
                if (isset($result['sign'])) {
                    if (!$this->verifySign($result)) {
                        throw new Exception('返回数据验签失败');
                    }
                }
                return $result;
            } else {
                throw new Exception('[' . $result['errorCode'] . ']' . $result['errorCodeDes']);
            }
        } elseif (isset($result['returnMsg'])) {
            throw new Exception($result['returnMsg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //获取待签名字符串
    private function getSignContent(array $param): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $v !== '' && $v !== null) {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        return $signstr;
    }

    //请求参数签名
    private function generateSign(array $param): string
    {
        return $this->rsaPrivateSign($this->getSignContent($param));
    }

    //验签方法
    public function verifySign(array $param): bool
    {
        if (empty($param['sign'])) return false;
        return $this->rsaPubilcSign($this->getSignContent($param), $param['sign']);
    }

    //解密事件消息
    public function decrpytEvent(string $ciphertext, string $nonceStr, string $associatedData, string $aeskey): string|false
    {
        $ciphertext = base64_decode($ciphertext);
        if (strlen($ciphertext) <= 16) {
            return false;
        }

        if (function_exists('sodium_crypto_aead_aes256gcm_is_available') && sodium_crypto_aead_aes256gcm_is_available()) {
            return sodium_crypto_aead_aes256gcm_decrypt($ciphertext, $associatedData, $nonceStr, $aeskey);
        }
        if (in_array('aes-256-gcm', openssl_get_cipher_methods())) {
            $ctext = substr($ciphertext, 0, -16);
            $authTag = substr($ciphertext, -16);

            return openssl_decrypt($ctext, 'aes-256-gcm', $aeskey, OPENSSL_RAW_DATA, $nonceStr, $authTag, $associatedData);
        }

        return false;
    }

    //应用私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $priKey = $this->mchPrivateKey;
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $pkeyid = openssl_pkey_get_private($res);
        if (!$pkeyid) {
            throw new Exception('签名失败，应用私钥不正确');
        }
        openssl_sign($data, $signature, $pkeyid);
        $signature = base64_encode($signature);
        return $signature;
    }

    //平台公钥验签
    private function rsaPubilcSign(string $data, string $signature): bool
    {
        $pubKey = $this->sdpPublicKey;
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
}
