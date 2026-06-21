<?php

declare(strict_types=1);

namespace plugins\payment\haoshengyi;

use Exception;

/**
 * @see https://www.yuque.com/jaythan/dfbgdx 密码：un2o
 */
class HsyClient
{
    private string $gateway_url = 'https://open.haoshengyi365.com';
    private string $orgCode;
    /** @var \OpenSSLAsymmetricKey */
    private $mch_public_key;
    /** @var \OpenSSLAsymmetricKey */
    private $mch_private_key;
    private string $version = '1.0';

    public function __construct(string $orgCode, string $mch_public_key, string $mch_private_key)
    {
        $this->orgCode = $orgCode;
        $this->mch_public_key = $this->loadPublicKey($mch_public_key);
        $this->mch_private_key = $this->loadPrivateKey($mch_private_key);
    }

    //发起API请求
    public function execute(string $path, string $bizType, array $data): array
    {
        $requrl = $this->gateway_url . $path;
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $encryptedData = $this->rsaPublicEncrypt($json);
        $params = [
            'bizType' => $bizType,
            'orgCode' => $this->orgCode,
            'version' => $this->version,
            'timestamp' => date('YmdHis'),
            'nonceStr' => random(16),
            'body' => $encryptedData,
        ];
        $plainForSign = sprintf('bizType=%s&body=%s&nonceStr=%s&orgCode=%s&version=%s&timestamp=%s', $params['bizType'], $params['body'], $params['nonceStr'], $params['orgCode'], $params['version'], $params['timestamp']);
        $params['sign'] = $this->rsaPrivateSign($plainForSign);

        $body = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = get_curl($requrl, $body, 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == '00') {
            $body = $this->rsaPrivateDecrypt($result['body']);
            if (!$body) {
                throw new Exception('响应报文解密失败');
            }
            return json_decode($body, true) ?? [];
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        openssl_sign($data, $signature, $this->mch_private_key);
        $signature = base64_encode($signature);
        return $signature;
    }

    //商户公钥验签
    private function rsaPubilcVerify(string $data, string $signature): bool
    {
        $result = openssl_verify($data, base64_decode($signature), $this->mch_public_key);
        return $result === 1;
    }

    private function getSignContent(array $params): string
    {
        uksort($params, function ($a, $b) {
            return strcasecmp($a, $b);
        });
        $signstr = '';

        foreach ($params as $k => $v) {
            if ($k != 'sign' && $k != 'file' && $v !== null && $v !== '') {
                if (is_array($v)) {
                    $v = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                $signstr .= ($signstr ? '&' : '') . $k . '=' . $v;
            }
        }
        return $signstr;
    }

    public function verify(array $params): bool
    {
        if (empty($params['sign'])) return false;
        return $this->rsaPubilcVerify($this->getSignContent($params), $params['sign']);
    }

    //商户公钥加密
    private function rsaPublicEncrypt(string $data): string
    {
        $encrypted = '';
        $partLen = openssl_pkey_get_details($this->mch_public_key)['bits'] / 8 - 11;
        $plainData = str_split($data, (int)$partLen);
        foreach ($plainData as $chunk) {
            $partialEncrypted = '';
            $encryptionOk = openssl_public_encrypt($chunk, $partialEncrypted, $this->mch_public_key);
            if ($encryptionOk === false) {
                return '';
            }
            $encrypted .= $partialEncrypted;
        }
        return base64_encode($encrypted);
    }

    //商户私钥解密
    private function rsaPrivateDecrypt(string $data): string|false
    {
        $decrypted = '';
        $partLen = openssl_pkey_get_details($this->mch_private_key)['bits'] / 8;
        $data = str_split(base64_decode($data), (int)$partLen);
        foreach ($data as $chunk) {
            $partial = '';
            $decryptionOK = openssl_private_decrypt($chunk, $partial, $this->mch_private_key);
            if ($decryptionOK === false) {
                return false;
            }
            $decrypted .= $partial;
        }
        return $decrypted;
    }

    //加载商户公钥
    private function loadPublicKey(string $public_key): \OpenSSLAsymmetricKey
    {
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($public_key, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new Exception('商户公钥不正确');
        }
        return $pubkeyid;
    }

    //加载商户私钥
    private function loadPrivateKey(string $private_key): \OpenSSLAsymmetricKey
    {
        $private_key = "-----BEGIN PRIVATE KEY-----\n" .
            wordwrap($private_key, 64, "\n", true) .
            "\n-----END PRIVATE KEY-----";
        $prikeyid = openssl_pkey_get_private($private_key);
        if (!$prikeyid) {
            throw new Exception('商户私钥不正确');
        }
        return $prikeyid;
    }
}
