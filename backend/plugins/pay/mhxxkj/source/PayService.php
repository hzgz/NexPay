<?php

declare(strict_types=1);

namespace plugins\payment\mhxxkj;

use Exception;

class PayService
{
    private string $mer_account;
    private string $public_key;
    private string $private_key;

    public function __construct(string $mer_account, string $public_key, string $private_key)
    {
        $this->mer_account = $mer_account;
        $this->public_key = $public_key;
        $this->private_key = $private_key;
    }

    //发起API请求
    public function submit(string $requrl, array $params): array
    {
        $params['sign'] = $this->getSign($params, $this->private_key);
        $encrypt_data = $this->encryptData($params);
        $post_data = [
            'merAccount' => $this->mer_account,
            'data' => $encrypt_data,
        ];
        $response = get_curl($requrl, http_build_query($post_data));
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == '000000') {
            if (isset($result['data']['sign']) && !$this->verifySign($result['data'])) {
                throw new Exception('返回数据验签失败');
            }
            return $result['data'];
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //异步回调处理
    public function notify(string $data): array|false
    {
        $decrypt_data = $this->decryptData($data);
        $arr = json_decode($decrypt_data, true);
        if (!$arr) return false;
        if (!$this->verifySign($arr)) return false;
        return $arr;
    }

    //验签方法
    private function verifySign(array $param): bool
    {
        if (empty($param['sign'])) return false;
        $sign = $this->getSign($param, $this->public_key);
        return $sign === $param['sign'];
    }

    //生成签名
    private function getSign(array $param, string $key): string
    {
        ksort($param);
        $data = '';

        foreach ($param as $k => $v) {
            if ($k != 'sign' && $v !== '' && $v !== null) {
                $data .= $v;
            }
        }
        $sign = strtoupper(md5($data . $key));
        return $sign;
    }

    //商户私钥加密
    private function encryptData(array $params): string
    {
        $priKey = $this->private_key;
        $res = "-----BEGIN PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END PRIVATE KEY-----";
        $pkeyid = openssl_pkey_get_private($res);
        if (!$pkeyid) {
            throw new Exception('加密失败，商户私钥不正确');
        }

        ksort($params);
        $data = json_encode($params);
        $encrypted = '';
        foreach (str_split($data, 117) as $chunk) {
            openssl_private_encrypt($chunk, $encryptData, $pkeyid, OPENSSL_PKCS1_PADDING);
            $encrypted .= $encryptData;
        }
        return base64_encode($encrypted);
    }

    //平台公钥解密
    private function decryptData(string $data): string
    {
        $pubKey = $this->public_key;
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new Exception('解密失败，平台公钥不正确');
        }

        $data = base64_decode($data);
        $crypto = '';
        foreach (str_split($data, 128) as $chunk) {
            openssl_public_decrypt($chunk, $decrypted, $pubkeyid, OPENSSL_PKCS1_PADDING);
            $crypto .= $decrypted;
        }
        return $crypto;
    }
}
