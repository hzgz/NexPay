<?php

declare(strict_types=1);

namespace plugins\payment\fuiou4;

/**
 * http://180.168.100.158:13318/fuiouH5Apipay/
 */
class PayService
{
    private string $version = '1.0.0';
    private string $mchnt_cd;
    private string $platform_public_key;
    private string $merchant_private_key;

    public function __construct(string $mchnt_cd, string $platform_public_key, string $merchant_private_key)
    {
        $this->mchnt_cd = $mchnt_cd;
        $this->platform_public_key = $platform_public_key;
        $this->merchant_private_key = $merchant_private_key;
    }

    //发起API请求
    public function submit(string $requrl, array $params): array
    {
        $public_params = [
            'ver' => $this->version,
            'mchnt_cd' => $this->mchnt_cd,
        ];
        $params = array_merge($public_params, $params);
        $data = json_encode($params);
        $data = $this->rsaPublicEncrypt($data);
        $body = json_encode(['mchnt_cd' => $this->mchnt_cd, 'message' => $data]);

        $response = get_curl($requrl, $body, 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$response) throw new \Exception('接口请求失败');
        $result = json_decode($response, true);

        if (isset($result['resp_code']) && $result['resp_code'] == '0000') {
            $data = $this->rsaPrivateDecrypt($result['message']);
            if (!$data) {
                throw new \Exception('返回数据私钥解密失败');
            }
            return json_decode($data, true) ?? [];
        } elseif (isset($result['resp_desc'])) {
            throw new \Exception($result['resp_desc']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    //回调数据解密
    public function decryptNotify(string $message): ?array
    {
        $data = $this->rsaPrivateDecrypt($message);
        if (!$data) return null;
        return json_decode($data, true);
    }

    //平台公钥加密
    private function rsaPublicEncrypt(string $data): string|false
    {
        $pubKey = str_replace(["\r\n", "\r", "\n"], "", $this->platform_public_key);
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new \Exception('加密失败，平台公钥不正确');
        }

        $encrypted = '';
        $partLen = openssl_pkey_get_details($pubkeyid)['bits'] / 8 - 11;
        $plainData = str_split($data, (int) $partLen);
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
    private function rsaPrivateDecrypt(string $data): string|false
    {
        $priKey = str_replace(["\r\n", "\r", "\n"], "", $this->merchant_private_key);
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $pkeyid = openssl_pkey_get_private($res);
        if (!$pkeyid) {
            throw new \Exception('解密失败，商户私钥不正确');
        }

        $decrypted = '';
        $partLen = openssl_pkey_get_details($pkeyid)['bits'] / 8;
        $chunks = str_split(base64_decode($data), (int) $partLen);
        foreach ($chunks as $chunk) {
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
